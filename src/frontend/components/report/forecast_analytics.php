<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\DemandForecastService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\ReplenishmentQueueService;

require_role(['admin', 'super_admin']);
require_capability(RoleCapabilityPolicy::VIEW_FORECAST_ANALYTICS);

function analytics_empty_state(string $icon, string $title, string $description, string $path, string $action): void
{
    ?>
    <div class="analytics-empty-state">
        <span class="analytics-empty-art" aria-hidden="true">
            <span class="analytics-empty-shape analytics-empty-shape-back"></span>
            <span class="analytics-empty-shape analytics-empty-shape-front"></span>
            <span class="analytics-empty-dot"></span>
            <span class="analytics-empty-icon"><i class="bi bi-<?= htmlspecialchars($icon) ?>" aria-hidden="true"></i></span>
        </span>
        <h3><?= htmlspecialchars($title) ?></h3>
        <p><?= htmlspecialchars($description) ?></p>
        <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url($path)) ?>"><?= htmlspecialchars($action) ?><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
    <?php
}

$storeId = store_scope_id($pdo);
$saleUnitsSql = sales_units_sql($pdo, 'si');

// Explicit run selection never falls back to another model's evidence.
$demandService = new DemandForecastService($pdo, new CandidateEvaluationService($pdo), new ReplenishmentQueueService($pdo));
$completedRuns = $pdo->query("SELECT training_run_id, model_version, started_at, metrics_json FROM model_training_runs WHERE status='completed' ORDER BY training_run_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$selectedRun = null;
$requestedRun = $_GET['training_run_id'] ?? null;
$requestedVersion = $_GET['model_version'] ?? null;
foreach ($completedRuns as $run) {
    if (($requestedRun !== null && is_scalar($requestedRun) && (string)$run['training_run_id'] === (string)$requestedRun)
        || ($requestedRun === null && $requestedVersion !== null && is_string($requestedVersion) && $run['model_version'] === $requestedVersion)
        || ($requestedRun === null && $requestedVersion === null)) {
        $selectedRun = $run;
        break;
    }
}
if (($requestedRun !== null || $requestedVersion !== null) && !$selectedRun) {
    http_response_code(404);
    exit('Completed training candidate not found.');
}
$displayedModelVersion = $selectedRun['model_version'] ?? null;
$runEvidence = json_decode((string)($selectedRun['metrics_json'] ?? ''), true) ?: [];
$runMetadata = $runEvidence['metadata'] ?? [];
$historicalDailyAll = $runMetadata['held_out_daily'] ?? [];
$historicalDaily = $historicalDailyAll;
$historicalProducts = array_values(array_unique(array_column($historicalDaily, 'product_id')));
$historicalProduct = is_string($_GET['product_id'] ?? null) ? $_GET['product_id'] : (string)($historicalProducts[0] ?? '');
$historicalDaily = array_values(array_filter($historicalDaily, static fn(array $row): bool => (string)$row['product_id'] === $historicalProduct));
$chartGroup = in_array($_GET['group_by'] ?? '', ['day', 'week', 'month'], true) ? $_GET['group_by'] : 'day';
$chartOrder = ($_GET['date_order'] ?? '') === 'desc' ? 'desc' : 'asc';
$evidenceSource = in_array($_GET['evidence'] ?? '', ['historical', 'store'], true)
    ? $_GET['evidence'] : 'historical';
$isHistoricalView = $evidenceSource === 'historical';
$forecastOrigin = null;

// Model governance settings
$govStmt = $pdo->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ('ml_approved_model_version', 'ml_observation_mode', 'ml_recommendations_suspended', 'demo_import_metadata', 'demo_replay_result')");
$govSettings = $govStmt ? $govStmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];

$approvedModel = $govSettings['ml_approved_model_version'] ?? null;

$observationMode = in_array($govSettings['ml_observation_mode'] ?? '0', ['1', 'true'], true);
$recommendationsSuspended = in_array($govSettings['ml_recommendations_suspended'] ?? '0', ['1', 'true'], true);
$hasDemoReplay = !empty($govSettings['demo_replay_result']);

// 2. Fetch candidate evaluations for this specific model version
$evalService = new CandidateEvaluationService($pdo);
$evaluations = $displayedModelVersion ? $evalService->getEvaluationsByModelVersion($displayedModelVersion) : [];
if (isset($runEvidence['evaluations']) && is_array($runEvidence['evaluations'])) {
    $evaluations = [];
    foreach ($runEvidence['evaluations'] as $horizon => $metrics) {
        $evaluations[] = ['horizon_days' => (int)$horizon] + $metrics;
    }
}
$evalByHorizon = [];
foreach ($evaluations as $e) {
    $evalByHorizon[(int)$e['horizon_days']] = $e;
}

// 2b. Prediction Performance Evidence (honest evidence matched with subsequent Store sales outcomes)
$perfEvidence = $demandService->getPredictionPerformanceEvidence(null, $displayedModelVersion ?? 'none');

// 3. Demo status indicator
$isDemoMode = false;
$demoSource = 'S001';
if (!empty($govSettings['demo_import_metadata'])) {
    $isDemoMode = true;
    $meta = json_decode((string)$govSettings['demo_import_metadata'], true);
    $demoSource = $meta['source_label'] ?? 'S001';
}

// 4. Actual vs Forecast (for past completed target dates)
$actualVsForecast = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            df.product_id,
            p.product_name,
            p.sku,
            df.target_date,
            df.forecast_origin,
            df.horizon_day,
            df.predicted_units,
            (
                SELECT SUM({$saleUnitsSql})
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.sale_id
                WHERE si.product_id = df.product_id
                  AND DATE(s.sale_date) = df.target_date
            ) as actual_units
        FROM demand_forecasts df
        JOIN ml_jobs j ON j.job_id = df.job_id
        JOIN products p ON df.product_id = p.product_id
        WHERE df.target_date <= CURDATE()
          AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded'
          AND df.model_version = ?
        ORDER BY df.target_date DESC, df.product_id ASC
        LIMIT 25
    ");
    $stmt->execute([$displayedModelVersion ?? 'none']);
    $actualVsForecast = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// 5. Training trend data
$trainingTrend = [];
try {
    $runs = $pdo->query("SELECT started_at, metrics_json FROM model_training_runs WHERE status = 'completed' ORDER BY started_at ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($runs as $run) {
        $runMetrics = json_decode((string)$run['metrics_json'], true) ?: [];
        $metadata = $runMetrics['metadata'] ?? [];
        if (($metadata['dataset_kind'] ?? 'store') !== ($runMetadata['dataset_kind'] ?? 'store')
            || ($metadata['dataset_database'] ?? '') !== ($runMetadata['dataset_database'] ?? '')) continue;
        $trainingTrend[] = [
            'date' => $run['started_at'],
            'wape' => $runMetrics['evaluations'][28]['wape'] ?? $runMetrics['wape'] ?? null,
            'mae' => $runMetrics['mae'] ?? $runMetrics['mean_absolute_error'] ?? null
        ];
    }
} catch (PDOException $e) {}

// No recorded model evidence means an empty chart, not sample results.
$trainingTrend = array_values(array_filter($trainingTrend, static fn(array $run): bool => is_numeric($run['wape'])));
$featureImportance = array_filter($runMetadata['feature_importance'] ?? $runEvidence['feature_importance'] ?? [],
    static fn($value): bool => is_numeric($value) && is_finite((float)$value) && $value >= 0);

$comparisonRows = $actualVsForecast;
if ($isHistoricalView) {
    $productNames = $pdo->query('SELECT product_id,product_name,sku FROM products')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $comparisonRows = [];
    foreach ($historicalDailyAll as $row) {
        $product = $productNames[$row['product_id']] ?? [];
        $comparisonRows[] = $row + ['product_name' => $product['product_name'] ?? 'Historical Product ' . $row['product_id'], 'sku' => $product['sku'] ?? ''];
    }
} else {
    // Store trends use matched Store outcomes, not training backtests.
    $dailyErrors = [];
    foreach ($actualVsForecast as $row) {
        if ($row['actual_units'] === null) continue;
        $date = $row['target_date'];
        $dailyErrors[$date]['actual'] = ($dailyErrors[$date]['actual'] ?? 0) + (float)$row['actual_units'];
        $dailyErrors[$date]['error'] = ($dailyErrors[$date]['error'] ?? 0) + abs((float)$row['actual_units'] - (float)$row['predicted_units']);
    }
    ksort($dailyErrors);
    $trainingTrend = [];
    foreach ($dailyErrors as $date => $values) {
        if ($values['actual'] > 0) $trainingTrend[] = ['date' => $date, 'wape' => $values['error'] / $values['actual']];
    }
}

// Product performance calculations for best/worst tabs
$productPerf = [];
foreach ($comparisonRows as $row) {
    if ($row['actual_units'] === null) continue;
    $pName = (string)$row['product_id'];
    if (!isset($productPerf[$pName])) {
        $productPerf[$pName] = [
            'product_name' => $row['product_name'],
            'sku' => $row['sku'],
            'predicted' => 0,
            'actual' => 0,
            'abs_err' => 0,
            'count' => 0
        ];
    }
    $p = (float)($row['predicted_units'] ?? 0);
    $a = (float)($row['actual_units'] ?? 0);
    $productPerf[$pName]['predicted'] += $p;
    $productPerf[$pName]['actual'] += $a;
    $productPerf[$pName]['abs_err'] += abs($a - $p);
    $productPerf[$pName]['count']++;
}
foreach ($productPerf as &$perf) {
    $perf['mean_err'] = $perf['count'] > 0 ? $perf['abs_err'] / $perf['count'] : 0;
    $perf['wape'] = $perf['actual'] > 0 ? ($perf['abs_err'] / $perf['actual']) * 100 : null;
}
unset($perf);

$bestPerformers = array_values($productPerf);
usort($bestPerformers, static fn($a, $b) => $a['mean_err'] <=> $b['mean_err']);
$best = $bestPerformers;

$worstPerformers = array_values($productPerf);
usort($worstPerformers, static fn($a, $b) => $b['mean_err'] <=> $a['mean_err']);
$worst = $worstPerformers;

// Actual chart data series
$actualChartData = [];
foreach (array_slice(array_values(array_filter($actualVsForecast, static fn(array $row): bool => $row['actual_units'] !== null)), 0, 10) as $row) {
    $actualChartData[] = [
        'product_name' => $row['product_name'] . ' ' . $row['target_date'],
        'forecast_value' => (float)$row['predicted_units'],
        'actual_demand' => (float)($row['actual_units'] ?? 0)
    ];
}
if ($isHistoricalView) {
    $actualChartData = array_map(static fn(array $row): array => [
        'product_name' => $row['target_date'], 'forecast_value' => (float)$row['predicted_units'],
        'actual_demand' => (float)$row['actual_units'],
    ], array_values(array_filter($historicalDaily, static fn(array $row): bool => $row['actual_units'] !== null)));
}
// Format helpers
function format_unit_metric(?float $val): string {
    return $val !== null ? number_format($val, 2) . ' units' : 'N/A';
}

function format_wape_metric(?float $val, ?string $reason): string {
    if ($val !== null) {
        return number_format($val * 100, 1) . '%';
    }
    return $reason ? htmlspecialchars($reason) : 'N/A';
}

$activeTab = in_array($_GET['tab'] ?? '', ['actual', 'errors', 'model', 'logs'], true) ? $_GET['tab'] : 'actual';
$errorOrder = ($_GET['errors'] ?? '') === 'lowest' ? 'lowest' : 'highest';
$modelView = in_array($_GET['model_view'] ?? '', ['trend', 'features', 'checks'], true) ? $_GET['model_view'] : 'trend';
if (!$isHistoricalView && $modelView === 'features') $modelView = 'trend';
$tableSearch = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$tablePage = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$analyticsParams = [
    'training_run_id' => $selectedRun['training_run_id'] ?? null, 'evidence' => $evidenceSource,
    'tab' => $activeTab, 'product_id' => $historicalProduct, 'group_by' => $chartGroup,
    'date_order' => $chartOrder, 'errors' => $errorOrder, 'model_view' => $modelView,
    'q' => $tableSearch, 'page' => $tablePage,
];

function analytics_url(array $changes = []): string {
    global $analyticsParams;
    return '?' . http_build_query(array_replace($analyticsParams, $changes));
}

function analytics_inputs(array $exclude): void {
    global $analyticsParams;
    foreach ($analyticsParams as $key => $value) {
        if ($value === null || in_array($key, $exclude, true)) continue;
        echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string)$value) . '">';
    }
}

function analytics_records(array $rows, string $name, bool $errors = false): void {
    global $tableSearch, $tablePage;
    $total = count($rows);
    if ($tableSearch !== '') {
        $rows = array_values(array_filter($rows, static function (array $row) use ($tableSearch): bool {
            return stripos(implode(' ', array_filter($row, 'is_scalar')), $tableSearch) !== false;
        }));
    }
    $count = count($rows);
    $pages = max(1, (int)ceil($count / 10));
    $page = min($tablePage, $pages);
    $offset = ($page - 1) * 10;
    ?>
    <div class="analytics-records">
        <div class="analytics-table-toolbar">
            <h3><?= htmlspecialchars($name) ?> <span class="muted">(<?= $count ?>)</span></h3>
            <form method="get" class="analytics-search" role="search" aria-label="Search this table">
                <?php analytics_inputs(['q', 'page']); ?>
                <label for="analytics-search">Search table</label>
                <div><input id="analytics-search" type="search" name="q" value="<?= htmlspecialchars($tableSearch) ?>" placeholder="Product, date or value"><button type="submit" class="btn btn-secondary">Search</button></div>
                <?php if ($tableSearch !== ''): ?><a href="<?= htmlspecialchars(analytics_url(['q' => '', 'page' => null])) ?>">Clear search</a><?php endif; ?>
            </form>
        </div>
        <div class="table-wrap" tabindex="0" aria-label="<?= htmlspecialchars($name) ?>">
            <table class="data-table analytics-record-table" data-no-smart-table data-no-ghost-rows aria-label="<?= htmlspecialchars($name) ?>">
                <thead><tr><th scope="col">Product</th><th scope="col"><?= $errors ? 'Average error' : 'Date' ?></th><th scope="col">Forecast</th><th scope="col">Actual sales</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($rows, $offset, 10) as $row): ?>
                    <tr>
                        <td data-label="Product"><strong><?= htmlspecialchars($row['product_name']) ?></strong>
                            <details class="analytics-row-details"><summary>Details<span class="sr-only"> for <?= htmlspecialchars($row['product_name']) ?><?= isset($row['target_date']) ? ' on ' . htmlspecialchars($row['target_date']) : '' ?></span></summary><dl>
                                <?php foreach (['product_id' => 'Product ID', 'sku' => 'SKU', 'forecast_origin' => 'Forecast origin', 'baseline_units' => 'Weekly baseline units', 'horizon_day' => 'Days ahead'] as $key => $label): ?>
                                    <?php if (isset($row[$key]) && $row[$key] !== ''): ?><div><dt><?= $label ?></dt><dd><?= htmlspecialchars((string)$row[$key]) ?></dd></div><?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ($errors): ?><div><dt>WAPE</dt><dd><?= $row['wape'] === null ? 'Unavailable' : number_format($row['wape'], 1) . '%' ?></dd></div>
                                <?php else: ?><div><dt>Difference (actual minus forecast)</dt><dd><?= $row['actual_units'] === null ? 'Unknown' : htmlspecialchars((string)((float)$row['actual_units'] - (float)$row['predicted_units'])) ?></dd></div><?php endif; ?>
                            </dl></details>
                        </td>
                        <td data-label="<?= $errors ? 'Average error' : 'Date' ?>"><?= $errors ? number_format($row['mean_err'], 2) . ' units' : htmlspecialchars($row['target_date']) ?></td>
                        <td data-label="Forecast"><?= $errors ? number_format($row['predicted'], 1) : htmlspecialchars((string)$row['predicted_units']) ?></td>
                        <td data-label="Actual sales"><?= $errors ? number_format($row['actual'], 1) : ($row['actual_units'] === null ? 'Unknown' : htmlspecialchars((string)$row['actual_units'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$count): ?><tr><td colspan="4" class="analytics-no-results">No matching records. <a href="<?= htmlspecialchars(analytics_url(['q' => '', 'page' => null])) ?>">Clear search</a></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <nav class="analytics-pagination" aria-label="Table pages">
            <span><?= $count ? ($offset + 1) . '–' . min($offset + 10, $count) : '0' ?> of <?= $count ?><?= $count !== $total ? ' matching records' : ' records' ?></span>
            <div>
                <?php if ($page > 1): ?><a class="btn btn-secondary" href="<?= htmlspecialchars(analytics_url(['page' => $page - 1])) ?>" rel="prev">Previous</a><?php endif; ?>
                <span>Page <?= $page ?> of <?= $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn btn-secondary" href="<?= htmlspecialchars(analytics_url(['page' => $page + 1])) ?>" rel="next">Next</a><?php endif; ?>
            </div>
        </nav>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <title>Forecast Analytics</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/forecast-analytics.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/forecast-analytics.css')) ?>">
    <?php require_once __DIR__ . '/../report/forecast_ui.php'; ?>
</head>
<body class="forecast-workspace forecast-analytics-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar"><h1>Forecast Analytics <?php if ($isDemoMode): ?><span class="analytics-status">Demo Evidence (<?= htmlspecialchars($demoSource) ?>)</span><?php endif; ?></h1></div>
        <?php forecast_workflow(''); ?>

        <section class="analytics-context" aria-labelledby="candidate-evidence-title">
            <h2 id="candidate-evidence-title" class="sr-only">Select analytics evidence</h2>
            <form method="get" class="analytics-source-controls">
                <?php analytics_inputs(['training_run_id', 'evidence', 'q', 'page', 'product_id']); ?>
                <label for="analytics-evidence">Evidence source<select id="analytics-evidence" name="evidence" class="input-select"><option value="historical" <?= $isHistoricalView ? 'selected' : '' ?>>Historical test</option><option value="store" <?= !$isHistoricalView ? 'selected' : '' ?>>Store sales</option></select></label>
                <label for="analytics-run">Completed training run<select id="analytics-run" name="training_run_id" class="input-select" <?= !$completedRuns ? 'disabled' : '' ?>>
                    <?php if (!$completedRuns): ?><option value="">No completed runs</option><?php endif; ?>
                    <?php foreach ($completedRuns as $run): ?><option value="<?= (int)$run['training_run_id'] ?>" <?= $selectedRun && $selectedRun['training_run_id'] === $run['training_run_id'] ? 'selected' : '' ?>>Run #<?= (int)$run['training_run_id'] ?> · <?= htmlspecialchars($run['model_version']) ?> · <?= htmlspecialchars($run['started_at']) ?></option><?php endforeach; ?>
                </select></label>
                <button type="submit" class="btn btn-primary">View evidence</button>
            </form>
            <div class="analytics-source-summary">
                <span><i class="bi bi-<?= $isHistoricalView ? 'clock-history' : 'shop' ?>" aria-hidden="true"></i> <strong><?= $isHistoricalView ? 'Historical test' : 'Store sales' ?></strong> · <?= $isHistoricalView ? 'Recorded backtest results' : 'Saved forecasts matched with later sales' ?></span>
                <?php forecast_help('evidence-source-help', $isHistoricalView ? 'Each historical fold was fitted on earlier records. These evaluation outputs are separate from the final candidate fitted on all training history and from prospective Store observation. Original source dates are unchanged. Viewing evidence does not approve a model or enable buying guidance.' : 'Subsequent Store forecasts matched with recorded sales for the selected model. Imported history does not create Store sales. Missing observations remain unknown.', 'About this evidence source'); ?>
            </div>
            <?php if ($isHistoricalView && $selectedRun): ?><p class="analytics-provenance"><?= htmlspecialchars($runMetadata['evidence_label'] ?? 'Source not recorded') ?></p><?php endif; ?>
            <details class="analytics-metadata"><summary>Model &amp; data details</summary><dl>
                <div><dt>Displayed model</dt><dd><?= htmlspecialchars($displayedModelVersion ?? 'No model yet') ?></dd></div>
                <div><dt>Approved model</dt><dd><?= htmlspecialchars($approvedModel ?: 'None') ?></dd></div>
                <div><dt>Qualification</dt><dd><?= htmlspecialchars($runEvidence['outcome'] ?? $runMetadata['outcome'] ?? 'Not recorded') ?></dd></div>
                <div><dt>Training history</dt><dd><?= htmlspecialchars($runMetadata['data_window_start'] ?? 'Not recorded') ?> to <?= htmlspecialchars($runMetadata['data_window_end'] ?? 'Not recorded') ?></dd></div>
                <?php if (isset($runMetadata['job_id'])): ?><div><dt>Training job</dt><dd>#<?= (int)$runMetadata['job_id'] ?></dd></div><?php endif; ?>
                <div><dt>Buying guidance</dt><dd><?= $recommendationsSuspended ? 'Paused' : ($observationMode ? 'Observation only' : 'Not paused') ?></dd></div>
                <div><dt>Sales source</dt><dd><?= $isDemoMode ? 'Isolated Demo (' . htmlspecialchars($demoSource) . ')' : 'Operational Sales' ?></dd></div>
            </dl><?php if ($hasDemoReplay): ?><a href="forecast_replay.php">Separately fitted historical replay</a><?php endif; ?></details>
        </section>
        <?php if ($recommendationsSuspended): ?><p class="forecast-notice" role="status">Buying guidance is paused.</p>
        <?php elseif ($observationMode): ?><p class="forecast-notice" role="status">Observation only. Forecasts are not approved for routine buying.</p><?php endif; ?>

        <section class="forecast-analytics-tabs" aria-label="Forecast analytics views">
            <nav class="analytics-tab-list" aria-label="Analytics views">
                <?php foreach (['actual' => ['bar-chart', 'Forecasts vs Sales'], 'errors' => ['exclamation-triangle', 'Product Errors'], 'model' => ['sliders', 'Model Details'], 'logs' => ['clock-history', 'Prediction Logs']] as $key => [$icon, $label]): ?>
                    <a id="analytics-tab-<?= $key ?>" class="analytics-tab <?= $activeTab === $key ? 'is-active' : '' ?>" href="<?= htmlspecialchars(analytics_url(['tab' => $key, 'q' => '', 'page' => null])) ?>" <?= $activeTab === $key ? 'aria-current="page"' : '' ?>><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i><?= $label ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="analytics-tab-panel" id="analytics-panel-<?= $activeTab ?>">
            <?php if ($activeTab === 'actual'): ?>
                <div class="analytics-view-heading"><h2>Forecasts vs Sales</h2><span class="muted">Units sold <?php forecast_help('sales-comparison-help', 'Forecast units are blue; actual sales are green. The chart shows all available comparisons for this view. Table search and pagination only change the table. Unknown sales are excluded from the chart.', 'About the sales comparison'); ?></span></div>
                <?php if ($isHistoricalView && $historicalProducts): ?>
                <form method="get" class="analytics-chart-controls">
                    <?php analytics_inputs(['product_id', 'group_by', 'date_order', 'q', 'page']); ?>
                    <label for="analytics-product">Product<select id="analytics-product" name="product_id" class="input-select"><?php foreach ($historicalProducts as $id): ?><option value="<?= htmlspecialchars((string)$id) ?>" <?= (string)$id === $historicalProduct ? 'selected' : '' ?>>Product <?= htmlspecialchars((string)$id) ?></option><?php endforeach; ?></select></label>
                    <label for="analytics-group">Group chart by<select id="analytics-group" name="group_by" class="input-select"><?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $value => $label): ?><option value="<?= $value ?>" <?= $chartGroup === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                    <label for="analytics-order">Date order<select id="analytics-order" name="date_order" class="input-select"><option value="asc" <?= $chartOrder === 'asc' ? 'selected' : '' ?>>Oldest first</option><option value="desc" <?= $chartOrder === 'desc' ? 'selected' : '' ?>>Newest first</option></select></label>
                    <button type="submit" class="btn btn-secondary">Apply view</button>
                </form>
                <?php endif; ?>
                <?php if (empty($actualChartData)): ?>
                    <?php analytics_empty_state('bar-chart', $isHistoricalView ? 'No historical comparison recorded' : 'Ready for your first comparison', $isHistoricalView ? 'No held-out daily predictions recorded for this candidate.' : 'Compare saved forecasts once their target dates have recorded sales.', 'components/report/data_readiness.php', 'Check data readiness'); ?>
                <?php else: ?>
                    <div class="analytics-chart"><canvas id="actualChart" role="img" aria-label="Forecast and actual sales; exact daily values in the following table"></canvas></div>
                    <?php if ($isHistoricalView): ?><p id="historical-chart-summary" class="analytics-chart-summary" aria-live="polite"></p><?php else: ?><p class="analytics-chart-summary">Latest <?= count($actualChartData) ?> matched comparisons · Exact records below</p><?php endif; ?>
                <?php endif; ?>
                <?php
                    $dailyRows = $isHistoricalView ? array_values(array_filter($comparisonRows, static fn(array $row): bool => (string)$row['product_id'] === $historicalProduct)) : $actualVsForecast;
                    if ($isHistoricalView) usort($dailyRows, static fn(array $a, array $b): int => $chartOrder === 'desc' ? strcmp($b['target_date'], $a['target_date']) : strcmp($a['target_date'], $b['target_date']));
                    if ($dailyRows) analytics_records($dailyRows, $isHistoricalView ? 'Candidate held-out daily evidence' : 'Forecast and recorded sales comparison');
                ?>
            <?php elseif ($activeTab === 'errors'): ?>
                <div class="analytics-view-heading"><h2>Product Errors</h2><?php forecast_help('product-errors-help', 'Rankings use all recorded Products in the selected evidence source. Average error is the mean absolute difference per comparison, in units. Forecast and actual sales are totals over those records. WAPE is available in Details; zero actual sales makes WAPE unavailable.', 'About Product errors'); ?></div>
                <nav class="analytics-subnav" aria-label="Product error order">
                    <?php foreach (['highest' => 'Highest errors', 'lowest' => 'Lowest errors'] as $key => $label): ?><a id="analytics-tab-<?= $key === 'highest' ? 'attention' : 'best' ?>" href="<?= htmlspecialchars(analytics_url(['errors' => $key, 'q' => '', 'page' => null])) ?>" <?= $errorOrder === $key ? 'aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?>
                </nav>
                <?php if (!$productPerf): ?><?php analytics_empty_state('exclamation-triangle', 'No product rankings yet', 'Rankings appear when forecasts can be matched with sales.', 'components/report/predictions.php', 'View demand forecasts'); ?>
                <?php else: ?><?php analytics_records($errorOrder === 'lowest' ? $best : $worst, $errorOrder === 'lowest' ? 'Lowest recorded forecast errors' : 'Highest recorded forecast errors', true); ?><?php endif; ?>
            <?php elseif ($activeTab === 'model'): ?>
                <div class="analytics-view-heading"><h2>Model Details</h2><span class="muted"><?= $isHistoricalView ? 'Historical training evidence' : 'Subsequent Store results' ?></span></div>
                <nav class="analytics-subnav" aria-label="Model detail views">
                    <?php foreach (['trend' => 'Error trend', 'features' => 'Input influence', 'checks' => $isHistoricalView ? 'Historical checks' : 'Store performance'] as $key => $label): ?>
                        <?php if ($key === 'features' && !$isHistoricalView) continue; ?>
                        <a id="analytics-tab-<?= $key ?>" href="<?= htmlspecialchars(analytics_url(['model_view' => $key])) ?>" <?= $modelView === $key ? 'aria-current="page"' : '' ?>><?= $label ?></a>
                    <?php endforeach; ?>
                </nav>
                <?php if ($modelView === 'trend'): ?>
                    <h3><?= $isHistoricalView ? '28-day candidate error over training runs' : 'Store forecast error by target date' ?> <?php forecast_help('error-trend-help', $isHistoricalView ? 'Comparable completed training runs with recorded 28-day WAPE. WAPE measures error, not accuracy; lower is better.' : 'WAPE from matched Store forecasts and sales, by target date. Only dates with nonzero recorded sales have WAPE.', 'About the error trend'); ?></h3>
                    <?php if (!$trainingTrend): ?><?php analytics_empty_state('graph-up', 'No error trend yet', 'Comparable results with recorded forecast errors will appear here.', 'components/system_administrator/ml_settings.php', 'View training results'); ?>
                    <?php else: ?><div class="analytics-chart"><canvas id="trendChart" role="img" aria-label="Recorded WAPE trend; exact values in View values"></canvas></div><details class="forecast-details"><summary>View values</summary><dl class="analytics-values"><?php foreach ($trainingTrend as $point): ?><div><dt><?= htmlspecialchars($point['date']) ?></dt><dd><?= number_format($point['wape'] * 100, 2) ?>% WAPE</dd></div><?php endforeach; ?></dl></details><?php endif; ?>
                <?php elseif ($modelView === 'features'): ?>
                    <h3>Recorded input influence <?php forecast_help('input-influence-help', 'Training measurements saved by the selected candidate. These describe relative input influence, not a cause of Store sales.', 'About input influence'); ?></h3>
                    <?php if (!$featureImportance): ?><?php analytics_empty_state('sliders', 'No input insights recorded', 'This candidate did not save input influence measurements.', 'components/system_administrator/ml_settings.php', 'Review model evidence'); ?>
                    <?php else: ?><div class="analytics-chart"><canvas id="featureChart" role="img" aria-label="Recorded input influence; exact values in View values"></canvas></div><details class="forecast-details"><summary>View values</summary><dl class="analytics-values"><?php foreach ($featureImportance as $label => $value): ?><div><dt><?= htmlspecialchars($label) ?></dt><dd><?= htmlspecialchars((string)$value) ?></dd></div><?php endforeach; ?></dl></details><?php endif; ?>
                <?php elseif ($isHistoricalView): ?>
                    <h3>Historical model checks · 7, 14 &amp; 28 days <?php forecast_help('historical-checks-help', 'Chronological backtests compare summed 7-, 14-, and 28-day Product totals. These historical checks are separate from later Store outcomes. MAE and RMSE measure error in units; WAPE measures proportional error. Lower is better.', 'About historical model checks'); ?></h3>
                    <?php if (!$evaluations): ?><?php analytics_empty_state('clipboard-data', 'No model checks yet', 'Review training results before approving a model.', 'components/system_administrator/ml_settings.php', 'Review model training'); ?>
                    <?php else: ?>
                    <div class="analytics-checks"><?php foreach ([7, 14, 28] as $h): $data = $evalByHorizon[$h] ?? null; ?>
                        <details><summary><strong><?= $h ?>-day horizon</strong><span class="analytics-status"><?= $data ? 'Gate ' . htmlspecialchars(ucfirst($data['candidate_gate_outcome'])) : 'Unavailable' ?></span></summary>
                            <?php if ($data): ?><dl class="analytics-values"><?php foreach (['mae' => 'Random Forest MAE', 'rmse' => 'Random Forest RMSE', 'wape' => 'Random Forest WAPE', 'baseline_mae' => 'Weekly Baseline MAE', 'baseline_rmse' => 'Weekly Baseline RMSE', 'baseline_wape' => 'Weekly Baseline WAPE'] as $key => $label): ?><div><dt><?= $label ?></dt><dd><?= str_contains($key, 'wape') ? format_wape_metric(isset($data[$key]) ? (float)$data[$key] : null, $data['missing_metric_reason'] ?? null) : format_unit_metric(isset($data[$key]) ? (float)$data[$key] : null) ?></dd></div><?php endforeach; ?></dl><?php else: ?><p>No measurements recorded for this forecast window.</p><?php endif; ?>
                        </details>
                    <?php endforeach; ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <h3>Performance on Store sales <?php forecast_help('performance-help', 'Daily errors compare one Product/day at lead times 1–7, 1–14 and 1–28 days. They are not errors of summed horizon totals. Stockout periods leave unmet demand unknown and are kept separate from forecast errors.', 'About Store performance'); ?></h3>
                    <p class="analytics-performance-status"><strong><?= htmlspecialchars($perfEvidence['status_label']) ?></strong> · <?= (int)$perfEvidence['completed_target_dates'] ?> / <?= (int)$perfEvidence['required_target_dates'] ?> dates · <?= (int)$perfEvidence['usable_comparison_count'] ?> comparisons</p>
                    <details class="forecast-details"><summary>Observation details</summary><p><?= htmlspecialchars($perfEvidence['explanation']) ?></p><dl class="analytics-values">
                        <div><dt>Evaluated model</dt><dd><?= htmlspecialchars($perfEvidence['model_version']) ?> (<?= htmlspecialchars($perfEvidence['model_association']) ?>)</dd></div>
                        <div><dt>Verified stockouts</dt><dd><?= htmlspecialchars($perfEvidence['verified_stockouts_display']) ?></dd></div><div><dt>Verified expiry losses</dt><dd><?= htmlspecialchars($perfEvidence['verified_expiry_losses_display']) ?></dd></div>
                    </dl><p><?= htmlspecialchars($perfEvidence['model_inputs_description']) ?></p><p><?= htmlspecialchars($perfEvidence['causal_claim_note']) ?></p></details>
                    <div class="analytics-checks"><?php foreach ([7, 14, 28] as $h): $m = $perfEvidence['metrics_by_horizon'][$h] ?? []; ?>
                        <details><summary><strong>1–<?= $h ?> days ahead</strong><span class="muted">Daily errors</span></summary><dl class="analytics-values">
                            <?php foreach (['overestimated_units' => 'Overestimated units', 'underestimated_units' => 'Underestimated units', 'mae' => 'Model Error (MAE)', 'wape' => 'Model Error (WAPE)', 'baseline_mae' => 'Weekly Baseline Error (MAE)', 'baseline_wape' => 'Weekly Baseline Error (WAPE)'] as $key => $label): ?><div><dt><?= $label ?></dt><dd><?= str_contains($key, 'wape') ? format_wape_metric(isset($m[$key]) ? (float)$m[$key] : null, null) : format_unit_metric(isset($m[$key]) ? (float)$m[$key] : null) ?></dd></div><?php endforeach; ?>
                        </dl></details>
                    <?php endforeach; ?></div>
                <?php endif; ?>
            <?php else: ?>
                <div class="analytics-view-heading"><h2>Prediction Logs</h2><?php forecast_help('prediction-logs-help', $isHistoricalView ? 'All saved held-out daily predictions from the selected training run. Original target dates, forecast origins and weekly baseline values remain available in Details.' : 'Latest 25 saved predictions for the selected model whose target dates have been reached. Unknown sales remain unknown; they are not zero.', 'About prediction logs'); ?></div>
                <?php if (!$comparisonRows): ?><?php analytics_empty_state('clock-history', 'No prediction history yet', 'Saved predictions for this evidence source will appear here.', 'components/report/predictions.php', 'View demand forecasts'); ?>
                <?php else: ?><?php analytics_records($comparisonRows, $isHistoricalView ? 'Historical prediction records' : 'Recent Store prediction records'); ?><?php endif; ?>
            <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<script src="<?= htmlspecialchars(app_url('assets/js/forecast-analytics-chart.js?v=' . filemtime(__DIR__ . '/../../assets/js/forecast-analytics-chart.js'))) ?>"></script>
<script>
const historicalData = <?= json_encode($historicalDaily, JSON_HEX_TAG) ?>;
const historicalGroup = <?= json_encode($chartGroup) ?>;
const historicalOrder = <?= json_encode($chartOrder) ?>;
const historicalView = <?= $isHistoricalView ? 'true' : 'false' ?>;
const historicalChartRows = ForecastAnalyticsChart.groupDaily(historicalData, historicalGroup, historicalOrder);
const historicalLabels = historicalChartRows.map(x => (historicalGroup === 'week' ? 'Week of ' : '') + ForecastAnalyticsChart.dateLabel(x.date, historicalGroup));
const featureData = <?= json_encode(['labels' => array_keys($featureImportance), 'values' => array_values($featureImportance)], JSON_HEX_TAG) ?>;
const trendData = <?= json_encode($trainingTrend, JSON_HEX_TAG) ?>;
const actualData = <?= json_encode($actualChartData, JSON_HEX_TAG) ?>;
let analyticsPrinting = false;

function analyticsTextColor() {
    const isPrint = analyticsPrinting || (window.matchMedia && window.matchMedia('print').matches);
    return !isPrint && document.documentElement.dataset.theme === 'dark' ? '#b1bfd3' : '#475569';
}

function drawBars(canvasId, labels, series, legends) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const dpr = window.devicePixelRatio || 1;
    const width = canvas.clientWidth || 600;
    const height = 280;
    canvas.width = width * dpr;
    canvas.height = height * dpr;
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, width, height);

    const pad = {l: 48, r: 12, t: 18, b: 55};
    const cw = width - pad.l - pad.r;
    const ch = height - pad.t - pad.b;
    const all = series.flatMap(s => s.values).filter(v => Number.isFinite(Number(v))).map(Number);
    const max = Math.max(1, ...all) * 1.1;

    ctx.strokeStyle = '#94a3b8';
    ctx.beginPath();
    ctx.moveTo(pad.l, pad.t);
    ctx.lineTo(pad.l, height - pad.b);
    ctx.lineTo(width - pad.r, height - pad.b);
    ctx.stroke();

    const groups = Math.max(1, labels.length);
    const groupW = cw / groups;
    const barW = Math.min(24, groupW / (series.length + 1));

    series.forEach((s, si) => {
        ctx.fillStyle = s.fill || (si === 0 ? '#2563eb' : '#16a34a');
        s.values.forEach((value, i) => {
            value = Number(value) || 0;
            const h = (value / max) * ch;
            const x = pad.l + i * groupW + (groupW - series.length * barW) / 2 + si * barW;
            ctx.fillRect(x, height - pad.b - h, barW * 0.8, h);
        });
    });

    ctx.fillStyle = analyticsTextColor();
    ctx.font = '12px sans-serif';
    const tickLabels = labels.map(label => String(label).slice(0, 22));
    const labelWidth = Math.max(1, ...tickLabels.map(label => ctx.measureText(label).width));
    const ticks = ForecastAnalyticsChart.tickIndices(labels.length, cw, labelWidth);
    ctx.textAlign = 'center';
    ticks.forEach(i => {
        const half = ctx.measureText(tickLabels[i]).width / 2;
        const x = Math.max(half + 4, Math.min(width - half - 4, pad.l + i * groupW + groupW / 2));
        ctx.fillText(tickLabels[i], x, height - pad.b + 24);
    });
    ctx.textAlign = 'left';
    ctx.fillText(max.toFixed(1), 4, pad.t + 5);
    if (legends) {
        legends.forEach((x, i) => {
            ctx.fillStyle = series[i].fill;
            ctx.fillRect(pad.l + i * 110, 3, 9, 9);
            ctx.fillStyle = analyticsTextColor();
            ctx.fillText(x, pad.l + i * 110 + 15, 12);
        });
    }
}

function drawLine(canvasId, labels, values) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.clientWidth || 600;
    const h = 280;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, w, h);

    const p = {l: 45, r: 15, t: 15, b: 45};
    const cw = w - p.l - p.r;
    const ch = h - p.t - p.b;
    const nums = values.map(Number).filter(Number.isFinite);
    const max = Math.max(1, ...nums) * 1.1;

    ctx.strokeStyle = '#94a3b8';
    ctx.beginPath();
    ctx.moveTo(p.l, p.t);
    ctx.lineTo(p.l, h - p.b);
    ctx.lineTo(w - p.r, h - p.b);
    ctx.stroke();

    ctx.strokeStyle = '#2563eb';
    ctx.lineWidth = 2;
    ctx.beginPath();
    values.forEach((v, i) => {
        const x = p.l + (labels.length <= 1 ? cw / 2 : (i * cw) / (labels.length - 1));
        const y = h - p.b - ((Number(v) || 0) / max) * ch;
        i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
    });
    ctx.stroke();

    ctx.fillStyle = '#2563eb';
    values.forEach((value, i) => {
        const x = p.l + (labels.length <= 1 ? cw / 2 : (i * cw) / (labels.length - 1));
        const y = h - p.b - ((Number(value) || 0) / max) * ch;
        ctx.beginPath();
        ctx.arc(x, y, 3, 0, Math.PI * 2);
        ctx.fill();
    });

    ctx.fillStyle = analyticsTextColor();
    ctx.font = '11px sans-serif';
    ForecastAnalyticsChart.tickIndices(labels.length, cw, 60).forEach(i => {
        const label = ForecastAnalyticsChart.dateLabel(String(labels[i]).slice(0, 10));
        const x = p.l + (labels.length <= 1 ? cw / 2 : (i * cw) / (labels.length - 1));
        ctx.fillText(label, Math.min(w - ctx.measureText(label).width - 4, x - 12), h - 18);
    });
    ctx.fillText(max.toFixed(1) + '%', 3, p.t + 5);
}

function drawAnalyticsPanel(panel) {
    if (panel === 'features') drawBars('featureChart', featureData.labels, [{values: featureData.values, fill: '#2563eb'}]);
    if (panel === 'trend') drawLine('trendChart', trendData.map(x => x.date), trendData.map(x => x.wape * 100));
    if (panel === 'actual') {
        if (historicalView) drawBars('actualChart', historicalLabels, [{values: historicalChartRows.map(x => x.predicted), fill: '#2563eb'}, {values: historicalChartRows.map(x => x.actual), fill: '#16a34a'}], ['Predicted', 'Actual']);
        else drawBars('actualChart', actualData.map(x => x.product_name), [{values: actualData.map(x => x.forecast_value), fill: '#2563eb'}, {values: actualData.map(x => x.actual_demand), fill: '#16a34a'}], ['Forecast', 'Actual']);
    }
}

const activePanel = <?= json_encode($activeTab === 'model' ? $modelView : $activeTab) ?>;
const redrawAnalytics = () => drawAnalyticsPanel(activePanel);
const centerAnalyticsTab = () => {
    const list = document.querySelector('.forecast-analytics-tabs .analytics-tab-list');
    const active = list?.querySelector('[aria-current="page"]');
    if (!active) return;
    const bounds = list.getBoundingClientRect();
    const tab = active.getBoundingClientRect();
    list.scrollLeft += tab.left - bounds.left - (list.clientWidth - tab.width) / 2;
};
centerAnalyticsTab();
const drawHistorical = () => {
    const summary = document.getElementById('historical-chart-summary');
    if (summary && historicalData.length) {
        const dates = historicalData.map(x => x.target_date).sort();
        summary.textContent = `${historicalChartRows.length} ${historicalGroup === 'day' ? 'daily' : historicalGroup === 'week' ? 'weekly' : 'monthly'} totals · Original daily values below · ${dates[0]} to ${dates[dates.length - 1]}`;
        document.getElementById('actualChart').setAttribute('aria-label', `Historical actual and predicted units grouped by ${historicalGroup}. Original dates ${dates[0]} to ${dates[dates.length - 1]}. Exact daily values are in the following table.`);
    }
};
drawHistorical();
redrawAnalytics();
const redrawAll = () => {
    drawHistorical();
    drawAnalyticsPanel('features');
    drawAnalyticsPanel('trend');
    drawAnalyticsPanel('actual');
};

window.addEventListener('retailmind:themechange', () => { redrawAnalytics(); drawHistorical(); });
window.addEventListener('resize', () => { redrawAnalytics(); drawHistorical(); centerAnalyticsTab(); });

if (window.MutationObserver) {
    const themeObserver = new MutationObserver(mutations => {
        for (const mutation of mutations) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'data-theme') {
                redrawAnalytics();
                break;
            }
        }
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
}

window.addEventListener('beforeprint', () => {
    analyticsPrinting = true;
    redrawAll();
});

window.addEventListener('afterprint', () => {
    analyticsPrinting = false;
    redrawAnalytics();
});

if (window.matchMedia) {
    window.matchMedia('print').addEventListener('change', e => {
        analyticsPrinting = e.matches;
        if (e.matches) {
            redrawAll();
        } else {
            redrawAnalytics();
        }
    });
}
</script>
</body>
</html>
