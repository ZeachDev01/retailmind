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
    ? $_GET['evidence'] : ($historicalDailyAll ? 'historical' : 'store');
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
$best = array_slice($bestPerformers, 0, 5);

$worstPerformers = array_values($productPerf);
usort($worstPerformers, static fn($a, $b) => $b['mean_err'] <=> $a['mean_err']);
$worst = array_slice($worstPerformers, 0, 5);

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
    <style>
        .analytics-chart-controls { display:flex; flex-wrap:wrap; align-items:end; gap:.75rem; margin:1rem 0; }
        .analytics-chart-controls label { display:grid; gap:.35rem; min-width:0; }
        .analytics-chart-controls select { max-width:100%; }
        .forecast-analytics-tabs > .analytics-chart-controls { padding:0 1rem; }
        .forecast-analytics-tabs > .muted { margin:1rem; }
        #historicalChart { display:block; width:100%; height:280px; }
        #historical-chart-summary { color:var(--muted); margin:.5rem 0 1rem; }
        .model-context-card {
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .candidate-evidence-filter { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-block: .75rem; }
        .candidate-evidence-filter select { min-width: 0; }
        .candidate-evidence-filter label { flex-basis: 100%; }
        .demo-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-left: 0.5rem;
            text-transform: uppercase;
        }
        .horizon-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .horizon-card {
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 8px;
            padding: 1.25rem;
        }
        .horizon-card h3 {
            margin-top: 0;
            font-size: 1.15rem;
            border-bottom: 1px solid var(--border-color, #e2e8f0);
            padding-bottom: 0.5rem;
        }
        .metric-row {
            display: flex;
            justify-content: space-between;
            padding: 0.35rem 0;
            font-size: 0.95rem;
        }
        .metric-label { color: var(--muted); }
        .metric-val { font-weight: 600; }
        .comparison-tag {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }
        .gate-passed { background: #dcfce7; color: #15803d; }
        .gate-failed { background: #fee2e2; color: #b91c1c; }
        .gov-pill-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: center;
        }
        .gov-pill {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }
        .gov-pill-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            font-weight: 600;
        }
        .gov-pill-val {
            font-size: 0.95rem;
            font-weight: 600;
        }
    </style>
    <?php require_once __DIR__ . '/../report/forecast_ui.php'; ?>
</head>
<body class="forecast-workspace">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1>Forecast Analytics <?php if ($isDemoMode): ?><span class="demo-badge">Demo Evidence (<?= htmlspecialchars($demoSource) ?>)</span><?php endif; ?></h1>
            </div>
        </div>

        <?php forecast_workflow(''); ?>
        <section class="model-context-card" aria-labelledby="candidate-evidence-title">
            <h2 id="candidate-evidence-title">Historical candidate evaluation</h2>
            <form method="get" class="candidate-evidence-filter">
                <input type="hidden" name="evidence" value="<?= htmlspecialchars($evidenceSource) ?>">
                <label for="analytics-run">Completed training run</label>
                <select id="analytics-run" name="training_run_id" class="input-select">
                    <?php foreach ($completedRuns as $run): ?>
                    <option value="<?= (int)$run['training_run_id'] ?>" <?= $selectedRun && $selectedRun['training_run_id'] === $run['training_run_id'] ? 'selected' : '' ?>>Run #<?= (int)$run['training_run_id'] ?> &middot; <?= htmlspecialchars($run['model_version']) ?> &middot; <?= htmlspecialchars($run['started_at']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary">View candidate</button>
            </form>
            <?php if ($selectedRun): ?>
            <p>Training run #<?= (int)$selectedRun['training_run_id'] ?> &middot; Candidate <code><?= htmlspecialchars($displayedModelVersion) ?></code> &middot; Qualification: <?= htmlspecialchars($runEvidence['outcome'] ?? $runMetadata['outcome'] ?? 'Not recorded') ?><?= isset($runMetadata['job_id']) ? ' &middot; Job #' . (int)$runMetadata['job_id'] : '' ?></p>
            <p>Source: <?= htmlspecialchars($runMetadata['evidence_label'] ?? 'Not recorded') ?>. History: <?= htmlspecialchars($runMetadata['data_window_start'] ?? 'Not recorded') ?> to <?= htmlspecialchars($runMetadata['data_window_end'] ?? 'Not recorded') ?>.</p>
            <?php else: ?><p>No completed training candidates yet.</p><?php endif; ?>
            <p>Viewing evidence does not approve a model, advance Store observation or enable buying guidance.</p>
            <h3>Held-out daily predictions</h3>
            <p>Each historical fold was fitted on earlier records. These are evaluation outputs, separate from the final candidate fitted on all training history and from prospective Store observation. Original source dates are unchanged.</p>
            <?php if (!$historicalDaily): ?>
                <p>No held-out daily predictions recorded for this candidate. Aggregate quality results may still be available below.</p>
            <?php else: ?>
                <form method="get" class="analytics-chart-controls">
                    <input type="hidden" name="training_run_id" value="<?= (int)$selectedRun['training_run_id'] ?>">
                    <input type="hidden" name="evidence" value="<?= htmlspecialchars($evidenceSource) ?>">
                    <label for="analytics-product">Historical Product ID
                    <select id="analytics-product" name="product_id" class="input-select"><?php foreach ($historicalProducts as $id): ?><option value="<?= htmlspecialchars((string)$id) ?>" <?= (string)$id === $historicalProduct ? 'selected' : '' ?>><?= htmlspecialchars((string)$id) ?></option><?php endforeach; ?></select></label>
                    <label for="analytics-group">Group by
                        <select id="analytics-group" name="group_by" class="input-select"><?php foreach (['day'=>'Day','week'=>'Week','month'=>'Month'] as $value=>$label): ?><option value="<?= $value ?>" <?= $chartGroup === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
                    </label>
                    <label for="analytics-order">Date order
                        <select id="analytics-order" name="date_order" class="input-select"><option value="asc" <?= $chartOrder === 'asc' ? 'selected' : '' ?>>Oldest first</option><option value="desc" <?= $chartOrder === 'desc' ? 'selected' : '' ?>>Newest first</option></select>
                    </label>
                    <button type="submit" class="btn btn-secondary">Apply view</button>
                </form>
                <canvas id="historicalChart" role="img" aria-label="Held-out actual and predicted daily units; exact values in the following table"></canvas>
                <p id="historical-chart-summary" aria-live="polite"></p>
                <p class="muted">Grouped bars sum recorded units. Weeks start Monday; partial periods contain only available dates. The table retains original daily values.</p>
                <div class="table-wrap data-table-scroll" tabindex="0" aria-label="Scroll historical daily evidence"><table class="data-table" data-no-ghost-rows aria-label="Candidate held-out daily evidence">
                    <thead><tr><th scope="col">Product ID</th><th scope="col">Forecast origin</th><th scope="col">Original target date</th><th scope="col">Actual units</th><th scope="col">Predicted units</th><th scope="col">Weekly baseline units</th></tr></thead>
                    <tbody><?php foreach ($historicalDaily as $row): ?><tr>
                        <?php foreach (['product_id', 'forecast_origin', 'target_date', 'actual_units', 'predicted_units', 'baseline_units'] as $key): ?><td><?= htmlspecialchars((string)$row[$key]) ?></td><?php endforeach; ?>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
            <?php if ($hasDemoReplay): ?><p><a href="forecast_replay.php">Separately fitted historical replay</a> uses its own identified experiment and metrics; it is not evidence produced by the selected final candidate.</p><?php endif; ?>
        </section>

        <?php if ($observationMode && !$recommendationsSuspended): ?>
            <p class="forecast-notice" role="status">Observation only. Forecasts are not approved for routine buying.</p>
        <?php endif; ?>
        <details class="forecast-details"><summary>Model &amp; data details</summary>
        <section class="model-context-card" aria-label="Model Governance">
            <?= csrf_field() ?>
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <div class="gov-pill-grid">
                    <div class="gov-pill">
                        <span class="gov-pill-label">Displayed model</span>
                        <span class="gov-pill-val">
                            <?php if ($displayedModelVersion): ?>
                                <code><?= htmlspecialchars($displayedModelVersion) ?></code>
                            <?php else: ?>
                                <span style="color:var(--muted);">No model yet</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="gov-pill">
                        <span class="gov-pill-label">Approved model</span>
                        <span class="gov-pill-val">
                            <?php if ($approvedModel): ?>
                                <code><?= htmlspecialchars($approvedModel) ?></code>
                            <?php else: ?>
                                <span style="color:var(--muted);">None</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="gov-pill">
                        <span class="gov-pill-label">Buying guidance</span>
                        <span class="gov-pill-val">
                            <?php if ($recommendationsSuspended): ?>
                                <span class="comparison-tag gate-failed">Paused</span>
                            <?php elseif ($observationMode): ?>
                                <span class="comparison-tag" style="background:#fef3c7; color:#92400e;">Observation only</span>
                            <?php else: ?>
                                <span class="comparison-tag gate-passed">Not paused</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="gov-pill">
                        <span class="gov-pill-label">Sales source</span>
                        <span class="gov-pill-val">
                            <?= $isDemoMode ? 'Isolated Demo (' . htmlspecialchars($demoSource) . ')' : 'Operational Sales' ?>
                        </span>
                    </div>
                </div>
                <div>
                    <?php if ($hasDemoReplay): ?>
                        <a class="btn btn-secondary" href="forecast_replay.php">Historical replay</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        </details>
        <section class="forecast-analytics-tabs" aria-label="Forecast charts">
            <form method="get" class="analytics-chart-controls">
                <?php if ($selectedRun): ?><input type="hidden" name="training_run_id" value="<?= (int)$selectedRun['training_run_id'] ?>"><?php endif; ?>
                <input type="hidden" name="product_id" value="<?= htmlspecialchars($historicalProduct) ?>">
                <input type="hidden" name="group_by" value="<?= htmlspecialchars($chartGroup) ?>">
                <input type="hidden" name="date_order" value="<?= htmlspecialchars($chartOrder) ?>">
                <label for="analytics-evidence">Evidence source
                    <select id="analytics-evidence" name="evidence" class="input-select"><option value="historical" <?= $isHistoricalView ? 'selected' : '' ?>>Historical candidate results</option><option value="store" <?= !$isHistoricalView ? 'selected' : '' ?>>Subsequent Store results</option></select>
                </label>
                <button type="submit" class="btn btn-secondary">View evidence</button>
            </form>
            <p class="muted"><?= $isHistoricalView ? 'Historical candidate backtests. Comparisons use the selected Product; rankings cover all recorded Products. These are not subsequent Store outcomes.' : 'Subsequent Store forecasts matched with recorded sales. Imported M5 history does not create Store sales.' ?></p>
            <div class="analytics-tab-list" role="tablist" aria-label="Forecast analytics views">
                <button class="analytics-tab is-active" id="analytics-tab-actual" type="button" role="tab" aria-selected="true" aria-controls="analytics-panel-actual" data-analytics-tab="actual">
                    <i class="bi bi-bar-chart" aria-hidden="true"></i> Forecasts vs Sales
                </button>
                <button class="analytics-tab" id="analytics-tab-features" type="button" role="tab" aria-selected="false" tabindex="-1" aria-controls="analytics-panel-features" data-analytics-tab="features">
                    <i class="bi bi-sliders" aria-hidden="true"></i> Input Influence
                </button>
                <button class="analytics-tab" id="analytics-tab-trend" type="button" role="tab" aria-selected="false" aria-controls="analytics-panel-trend" data-analytics-tab="trend" tabindex="-1">
                    <i class="bi bi-graph-up" aria-hidden="true"></i> Error Trend
                </button>
                <button class="analytics-tab" id="analytics-tab-best" type="button" role="tab" aria-selected="false" aria-controls="analytics-panel-best" data-analytics-tab="best" tabindex="-1">
                    <i class="bi bi-trophy" aria-hidden="true"></i> Lowest Errors
                </button>
                <button class="analytics-tab" id="analytics-tab-attention" type="button" role="tab" aria-selected="false" aria-controls="analytics-panel-attention" data-analytics-tab="attention" tabindex="-1">
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Highest Errors
                </button>
            </div>

            <div class="analytics-tab-panel chart-card" id="analytics-panel-features" role="tabpanel" aria-labelledby="analytics-tab-features" data-analytics-panel="features" hidden>
                <h2 id="analytics-view-title">Input Influence</h2>
                <?php if (empty($featureImportance)): ?>
                    <?php analytics_empty_state('sliders', 'No input insights recorded', 'This candidate did not save input influence measurements.', 'components/system_administrator/ml_settings.php', 'Review model evidence'); ?>
                <?php else: ?><canvas id="featureChart" role="img" aria-label="Recorded model measurements"></canvas><?php endif; ?>
            </div>

            <div class="analytics-tab-panel chart-card" id="analytics-panel-trend" role="tabpanel" aria-labelledby="analytics-tab-trend" data-analytics-panel="trend" hidden>
                <h2><?= $isHistoricalView ? '28-day candidate error over training runs' : 'Store forecast error by target date' ?></h2>
                <?php if (empty($trainingTrend)): ?>
                    <?php analytics_empty_state('graph-up', 'No error trend yet', $isHistoricalView ? 'Comparable training runs with recorded 28-day WAPE will appear here.' : 'Store comparisons with nonzero recorded sales will appear here.', 'components/system_administrator/ml_settings.php', 'View training results'); ?>
                <?php else: ?><canvas id="trendChart" role="img" aria-label="Recorded model measurements"></canvas><?php endif; ?>
            </div>

            <div class="analytics-tab-panel chart-card" id="analytics-panel-actual" role="tabpanel" aria-labelledby="analytics-tab-actual" data-analytics-panel="actual">
                <h2><?= $isHistoricalView ? 'Historical predicted vs actual sales' : 'Subsequent Store forecasts vs recorded sales' ?> <?php forecast_help('sales-comparison-help', 'Predicted units are blue; recorded units are green. Historical evidence is separate from subsequent Store outcomes.', 'About the sales comparison'); ?></h2>
                <?php if (empty($actualChartData)): ?>
                    <?php analytics_empty_state('bar-chart', $isHistoricalView ? 'No historical comparison recorded' : 'Ready for your first comparison', $isHistoricalView ? 'This candidate did not save held-out daily predictions.' : 'Compare saved forecasts once their target dates have recorded sales.', 'components/report/data_readiness.php', 'Check data readiness'); ?>
                <?php else: ?><canvas id="actualChart" role="img" aria-label="Recorded forecast and sales comparison"></canvas>
                <details class="forecast-details"><summary>Comparison values</summary><div class="table-wrap"><table class="data-table" aria-label="Forecast and recorded sales comparison"><thead><tr><th scope="col">Product &amp; date</th><th scope="col">Forecast units</th><th scope="col">Recorded sales</th></tr></thead><tbody>
                <?php foreach ($actualChartData as $point): ?><tr><td><?= htmlspecialchars($point['product_name']) ?></td><td><?= htmlspecialchars((string)$point['forecast_value']) ?></td><td><?= htmlspecialchars((string)$point['actual_demand']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div></details><?php endif; ?>
            </div>

            <div class="analytics-tab-panel" id="analytics-panel-best" role="tabpanel" aria-labelledby="analytics-tab-best" data-analytics-panel="best" hidden>
                <h2>Lowest Recorded Errors</h2>
                <?php if (empty($best)): ?>
                    <?php analytics_empty_state('trophy', 'No product rankings yet', 'Rankings appear when forecasts can be matched with sales.', 'components/report/predictions.php', 'View demand forecasts'); ?>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table" aria-label="Lowest recorded forecast errors">
                            <thead>
                                <tr>
                                    <th scope="col">Product</th>
                                    <th scope="col">SKU</th>
                                    <th scope="col">Predicted</th>
                                    <th scope="col">Actual</th>
                                    <th scope="col">Average error (units)</th>
                                    <th scope="col">WAPE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($best as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                                        <td><code><?= htmlspecialchars($item['sku']) ?></code></td>
                                        <td><?= number_format($item['predicted'], 1) ?></td>
                                        <td><?= number_format($item['actual'], 1) ?></td>
                                        <td><?= number_format($item['mean_err'], 2) ?></td>
                                        <td><?= $item['wape'] === null ? 'Unavailable' : number_format($item['wape'], 1) . '%' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="analytics-tab-panel" id="analytics-panel-attention" role="tabpanel" aria-labelledby="analytics-tab-attention" data-analytics-panel="attention" hidden>
                <h2>Forecasts Needing Attention</h2>
                <?php if (empty($worst)): ?>
                    <?php analytics_empty_state('exclamation-triangle', 'No error comparisons yet', 'Sales comparisons are needed to identify the highest errors.', 'components/report/predictions.php', 'View demand forecasts'); ?>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table" aria-label="Forecasts needing attention">
                            <thead>
                                <tr>
                                    <th scope="col">Product</th>
                                    <th scope="col">SKU</th>
                                    <th scope="col">Predicted</th>
                                    <th scope="col">Actual</th>
                                    <th scope="col">Average error (units)</th>
                                    <th scope="col">WAPE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($worst as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                                        <td><code><?= htmlspecialchars($item['sku']) ?></code></td>
                                        <td><?= number_format($item['predicted'], 1) ?></td>
                                        <td><?= number_format($item['actual'], 1) ?></td>
                                        <td><?= number_format($item['mean_err'], 2) ?></td>
                                        <td><?= $item['wape'] === null ? 'Unavailable' : number_format($item['wape'], 1) . '%' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="model-context-card" aria-label="Prediction Performance Evidence" style="margin-bottom: 1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem;">
                <div>
                    <h2 style="font-size:1.15rem; margin:0;">Performance on Store sales <?php forecast_help('performance-help', 'Compares saved forecasts with later Store sales. Results appear after target dates have usable observations.', 'About Store performance'); ?></h2>
                </div>
                <div>
                    <?php if ($perfEvidence['status'] === 'genuine_evidence'): ?>
                        <span class="comparison-tag gate-passed" style="font-size:0.875rem; padding:0.35rem 0.75rem;">Genuine Store Evidence</span>
                    <?php elseif ($perfEvidence['status'] === 'collecting_evidence'): ?>
                        <span class="comparison-tag" style="background:var(--theme-warning-surface, #fef3c7); color:var(--theme-warning-text, #92400e); font-size:0.875rem; padding:0.35rem 0.75rem;">Still Collecting Evidence</span>
                    <?php else: ?>
                        <span class="comparison-tag gate-failed" style="font-size:0.875rem; padding:0.35rem 0.75rem;">Unavailable</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ((int)$perfEvidence['usable_comparison_count'] === 0): ?>
                <?php analytics_empty_state('calendar-check', 'Waiting for Store sales', 'Results appear after forecast dates have usable Store observations.', 'components/report/predictions.php', 'View demand forecasts'); ?>
            <?php endif; ?>
            <details class="forecast-details"><summary>Observation evidence &amp; daily errors</summary>
            <p style="margin-bottom:0.75rem;"><?= htmlspecialchars($perfEvidence['explanation']) ?></p>
            <p class="muted">Daily errors compare one Product/day at lead times 1–7, 1–14, and 1–28 days; they are not errors of summed horizon totals. Technical qualification separately evaluates summed horizons using chronological backtests.</p>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1rem; padding:0.75rem; background:var(--theme-surface-soft, #f8fafc); border-radius:6px;">
                <div>
                    <span class="muted" style="font-size:0.75rem; display:block; text-transform:uppercase;">Evaluated Model</span>
                    <strong><code><?= htmlspecialchars($perfEvidence['model_version']) ?></code></strong>
                    <span class="muted" style="font-size:0.8rem;">(<?= ucfirst($perfEvidence['model_association']) ?> Model)</span>
                </div>
                <div>
                    <span class="muted" style="font-size:0.75rem; display:block; text-transform:uppercase;">Observation Progress</span>
                    <strong><?= (int)$perfEvidence['completed_target_dates'] ?> / <?= (int)$perfEvidence['required_target_dates'] ?> dates</strong>
                    <span class="muted" style="font-size:0.8rem;">(<?= (int)$perfEvidence['usable_comparison_count'] ?> comparisons)</span>
                </div>
                <div>
                    <span class="muted" style="font-size:0.75rem; display:block; text-transform:uppercase;">Verified Stockouts</span>
                    <strong><?= htmlspecialchars($perfEvidence['verified_stockouts_display']) ?></strong>
                    <span class="muted" style="font-size:0.8rem;">(Incomplete demand excluded)</span>
                </div>
                <div>
                    <span class="muted" style="font-size:0.75rem; display:block; text-transform:uppercase;">Verified Expiry Losses</span>
                    <strong><?= htmlspecialchars($perfEvidence['verified_expiry_losses_display']) ?><?= $perfEvidence['verified_expiry_losses'] !== null ? ' units' : '' ?></strong>
                    <span class="muted" style="font-size:0.8rem;">(Tracked losses)</span>
                </div>
            </div>

            <p class="muted" style="font-size:0.85rem; margin-bottom:1rem;">
                <strong>Inputs:</strong> <?= htmlspecialchars($perfEvidence['model_inputs_description']) ?>
            </p>

            <div class="table-wrap">
                <table class="data-table" aria-label="Forecast accuracy by lead time">
                    <caption>Daily Errors by Forecast Lead Time</caption>
                    <thead>
                        <tr>
                            <th scope="col">Forecast Lead Time</th>
                            <th scope="col">Overestimated Units</th>
                            <th scope="col">Underestimated Units</th>
                            <th scope="col">Model Error (MAE)</th>
                            <th scope="col">Model Error (WAPE)</th>
                            <th scope="col">Weekly Baseline Error (MAE)</th>
                            <th scope="col">Weekly Baseline Error (WAPE)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ([7, 14, 28] as $h):
                            $m = $perfEvidence['metrics_by_horizon'][$h] ?? [];
                        ?>
                            <tr>
                                <th scope="row">1–<?= $h ?> Days Ahead</th>
                                <td><?= $m['overestimated_units'] !== null ? number_format($m['overestimated_units'], 2) . ' units' : '<span class="muted">Unavailable</span>' ?></td>
                                <td><?= $m['underestimated_units'] !== null ? number_format($m['underestimated_units'], 2) . ' units' : '<span class="muted">Unavailable</span>' ?></td>
                                <td><?= $m['mae'] !== null ? number_format($m['mae'], 2) . ' units' : '<span class="muted">Unavailable</span>' ?></td>
                                <td><?= $m['wape'] !== null ? number_format($m['wape'] * 100, 2) . '%' : '<span class="muted">Unavailable</span>' ?></td>
                                <td><?= $m['baseline_mae'] !== null ? number_format($m['baseline_mae'], 2) . ' units' : '<span class="muted">Unavailable</span>' ?></td>
                                <td><?= $m['baseline_wape'] !== null ? number_format($m['baseline_wape'] * 100, 2) . '%' : '<span class="muted">Unavailable</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="muted" style="font-size:0.8rem; margin-top:0.75rem; margin-bottom:0;">
                <small><?= htmlspecialchars($perfEvidence['causal_claim_note']) ?> Stockout periods leave unmet demand unknown and are kept separate from forecast errors.</small>
            </p>
            </details>
        </section>

        <details class="forecast-details"><summary>Historical model checks · 7, 14 &amp; 28 days</summary>
        <p class="muted">Technical qualification: chronological backtests compare summed 7-, 14-, and 28-day Product totals. These historical tests are separate from subsequent Store outcome evidence above.</p>
        <?php if (empty($evaluations)): ?>
            <?php analytics_empty_state('clipboard-data', 'No model checks yet', 'Review training results before approving a model.', 'components/system_administrator/ml_settings.php', 'Review model training'); ?>
        <?php else: ?>
            <div class="horizon-grid">
                <?php foreach ([7, 14, 28] as $h):
                    $data = $evalByHorizon[$h] ?? null;
                ?>
                    <div class="horizon-card">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                            <h3 style="margin:0;"><?= $h ?>-Day Horizon</h3>
                            <?php if ($data): ?>
                                <span class="comparison-tag gate-<?= htmlspecialchars($data['candidate_gate_outcome']) ?>">
                                    Gate <?= ucfirst($data['candidate_gate_outcome']) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($data): ?>
                            <div class="metric-row">
                                <span class="metric-label">Random Forest MAE</span>
                                <span class="metric-val"><?= format_unit_metric($data['mae'] !== null ? (float)$data['mae'] : null) ?></span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Random Forest RMSE</span>
                                <span class="metric-val"><?= format_unit_metric($data['rmse'] !== null ? (float)$data['rmse'] : null) ?></span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Random Forest WAPE</span>
                                <span class="metric-val"><?= format_wape_metric($data['wape'] !== null ? (float)$data['wape'] : null, $data['missing_metric_reason']) ?></span>
                            </div>

                            <hr style="border:0; border-top:1px dashed var(--border-color, #e2e8f0); margin:0.75rem 0;">

                            <div class="metric-row">
                                <span class="metric-label">Weekly Baseline MAE</span>
                                <span class="metric-val"><?= format_unit_metric($data['baseline_mae'] !== null ? (float)$data['baseline_mae'] : null) ?></span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Weekly Baseline RMSE</span>
                                <span class="metric-val"><?= format_unit_metric($data['baseline_rmse'] !== null ? (float)$data['baseline_rmse'] : null) ?></span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Weekly Baseline WAPE</span>
                                <span class="metric-val"><?= format_wape_metric($data['baseline_wape'] !== null ? (float)$data['baseline_wape'] : null, null) ?></span>
                            </div>
                        <?php else: ?>
                            <?php analytics_empty_state('clipboard-data', "No {$h}-day evaluation yet", 'No measurements recorded for this forecast window.', 'components/system_administrator/ml_settings.php', 'Review model training'); ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        </details>

        <details class="forecast-details"><summary>Recent prediction logs</summary>
        <section aria-labelledby="prediction-logs-title">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem;">
                <h2 id="prediction-logs-title" style="margin:0;">Recent Prediction Logs</h2>
                <span class="metric-label" style="font-size:0.85rem;">Observed sales for past target dates</span>
            </div>
            <?php if (empty($actualVsForecast)): ?>
                <?php analytics_empty_state('clock-history', 'No prediction history yet', 'Saved predictions appear once their target dates are reached.', 'components/report/predictions.php', 'View demand forecasts'); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table" aria-label="Predictions compared with actual sales">
                        <thead>
                            <tr>
                                <th scope="col">Product</th>
                                <th scope="col">SKU</th>
                                <th scope="col">Target Date</th>
                                <th scope="col">Days ahead</th>
                                <th scope="col">Predicted Units</th>
                                <th scope="col">Recorded sales</th>
                                <th scope="col">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($actualVsForecast as $row):
                                $diff = $row['actual_units'] !== null ? (int)$row['actual_units'] - (int)$row['predicted_units'] : null;
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                                    <td><code><?= htmlspecialchars($row['sku']) ?></code></td>
                                    <td><?= htmlspecialchars($row['target_date']) ?></td>
                                    <td>Day <?= htmlspecialchars((string)$row['horizon_day']) ?></td>
                                    <td><strong><?= htmlspecialchars((string)$row['predicted_units']) ?></strong></td>
                                    <td><?= $row['actual_units'] !== null ? htmlspecialchars((string)$row['actual_units']) : 'Unknown' ?></td>
                                    <td style="color: <?= $diff === null ? 'var(--muted)' : ($diff >= 0 ? 'var(--theme-success-text, #15803d)' : 'var(--theme-danger-text, #b91c1c)') ?>;">
                                        <?= $diff === null ? '—' : ($diff > 0 ? "+{$diff}" : (string)$diff) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        </details>
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
    const height = canvasId === 'historicalChart' ? 280 : 240;
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
    const h = 240;
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

const analyticsTabs = Array.from(document.querySelectorAll('[data-analytics-tab]'));
const analyticsPanels = Array.from(document.querySelectorAll('[data-analytics-panel]'));

function activateAnalyticsTab(tab, updateUrl = true) {
    const target = tab.dataset.analyticsTab;
    analyticsTabs.forEach(item => {
        const active = item === tab;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', String(active));
        item.tabIndex = active ? 0 : -1;
    });
    analyticsPanels.forEach(panel => {
        panel.hidden = panel.dataset.analyticsPanel !== target;
    });
    if (updateUrl && window.history && window.history.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', target);
        window.history.replaceState(null, '', url);
    }
    drawAnalyticsPanel(target);
}

analyticsTabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activateAnalyticsTab(tab));
    tab.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        let next = index;
        if (event.key === 'ArrowRight') next = (index + 1) % analyticsTabs.length;
        if (event.key === 'ArrowLeft') next = (index - 1 + analyticsTabs.length) % analyticsTabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = analyticsTabs.length - 1;
        analyticsTabs[next].focus();
        activateAnalyticsTab(analyticsTabs[next]);
    });
});

const initialTabParam = new URLSearchParams(window.location.search).get('tab');
const initialTab = analyticsTabs.find(t => t.dataset.analyticsTab === initialTabParam) || analyticsTabs[0];
if (initialTab) {
    activateAnalyticsTab(initialTab, false);
} else {
    drawAnalyticsPanel('actual');
}

const redrawAnalytics = () => {
    const activeTab = document.querySelector('[data-analytics-tab].is-active');
    if (activeTab) drawAnalyticsPanel(activeTab.dataset.analyticsTab);
};

const drawHistorical = () => {
    drawBars('historicalChart', historicalLabels, [{values: historicalChartRows.map(x => x.predicted), fill: '#2563eb'}, {values: historicalChartRows.map(x => x.actual), fill: '#16a34a'}], ['Predicted', 'Actual']);
    const summary = document.getElementById('historical-chart-summary');
    if (summary && historicalData.length) {
        const dates = historicalData.map(x => x.target_date).sort();
        summary.textContent = `${historicalChartRows.length} ${historicalGroup === 'day' ? 'daily' : historicalGroup === 'week' ? 'weekly' : 'monthly'} totals · Original dates ${dates[0]} to ${dates[dates.length - 1]}`;
        document.getElementById('historicalChart').setAttribute('aria-label', `Historical actual and predicted units grouped by ${historicalGroup}. Original dates ${dates[0]} to ${dates[dates.length - 1]}. Exact daily values are in the following table.`);
    }
};
drawHistorical();
const redrawAll = () => {
    drawHistorical();
    drawAnalyticsPanel('features');
    drawAnalyticsPanel('trend');
    drawAnalyticsPanel('actual');
};

window.addEventListener('retailmind:themechange', () => { redrawAnalytics(); drawHistorical(); });
window.addEventListener('resize', () => { redrawAnalytics(); drawHistorical(); });

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
