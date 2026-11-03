<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\DemandForecastService;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);

$userRole = $_SESSION['role'] ?? (function_exists('current_role') ? current_role() : 'admin');
$message = '';
$messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    csrf_verify();
    if (has_capability(RoleCapabilityPolicy::GENERATE_FORECASTS)) {
        try {
            $jobService = new \App\Services\MlJobService($pdo);
            $jobService->enqueue('forecast_generation', (int)$_SESSION['user_id'], $_SESSION['role'], $_SERVER['REMOTE_ADDR'] ?? null);
            $message = 'Forecast generation job has been enqueued. The background worker will process it shortly.';
            $messageClass = 'tag-success';
        } catch (Throwable $e) {
            $message = 'Failed to enqueue job: ' . $e->getMessage();
            $messageClass = 'tag-warning';
        }
    } else {
        http_response_code(403);
        $message = 'You do not have permission to generate forecasts.';
        $messageClass = 'tag-warning';
    }
}

$service = new DemandForecastService($pdo);
$searchQuery = trim($_GET['search'] ?? '');
$coverage = $service->getCoverage(null, null, $searchQuery, null, $userRole);

$effectiveJobId = $coverage['effective_job_id'];
$isGenerating = $coverage['is_updating'];
$latestJobStatus = $coverage['latest_attempt_status'];
$latestJob = $coverage['latest_job'];

// Forecast origin
$forecastOrigin = null;
$displayedForecastModel = $coverage['approved_model_version'];
foreach ($coverage['products'] as $p) {
    if (!empty($p['forecast_origin'])) {
        $forecastOrigin = $p['forecast_origin'];
        if (!empty($p['model_version'])) {
            $displayedForecastModel = $p['model_version'];
        }
        break;
    }
}

$originTimestamp = $forecastOrigin ? strtotime($forecastOrigin) : null;
$now = time();
$originAgeDays = $originTimestamp ? (int)floor(($now - $originTimestamp) / 86400) : null;
$originAgeText = $originAgeDays !== null ? ($originAgeDays === 0 ? 'Today' : ($originAgeDays === 1 ? '1 day old' : "{$originAgeDays} days old")) : 'Unavailable';

// Guidance target range
$targetRangeText = 'Unavailable';
if ($forecastOrigin) {
    $t1 = date('M d, Y', strtotime("$forecastOrigin +1 day"));
    $t28 = date('M d, Y', strtotime("$forecastOrigin +28 days"));
    $targetRangeText = "{$t1} to {$t28}";
}

if ($isGenerating) {
    $generationStatus = 'updating';
    $statusBadgeClass = 'tag-info';
    $statusBadgeLabel = 'Updating';
} elseif ($coverage['store_status'] === 'empty' || !$coverage['is_guidance_usable']) {
    $generationStatus = 'unavailable';
    $statusBadgeClass = 'tag-danger';
    $statusBadgeLabel = 'Unavailable';
} elseif ($originAgeDays !== null && $originAgeDays > 7) {
    $generationStatus = 'old';
    $statusBadgeClass = 'tag-warning';
    $statusBadgeLabel = 'Old';
} else {
    $generationStatus = 'ready';
    $statusBadgeClass = 'tag-success';
    $statusBadgeLabel = 'Ready';
}

$displayProducts = $coverage['filtered']['is_filtered'] ? $coverage['filtered']['products'] : $coverage['products'];

// Operational issues & performance summary
$operationalIssues = $coverage['operational_issues'] ?? $service->getOperationalIssues($coverage, $userRole);
$performanceSummary = $coverage['performance_summary'] ?? $service->getPerformanceSummary($displayedForecastModel, $coverage);

// Check if demo metadata exists
$isDemoMode = false;
$demoSource = 'S001';
$metaStmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'demo_import_metadata'");
if ($metaStmt && ($demoJson = $metaStmt->fetchColumn())) {
    $isDemoMode = true;
    $demoSource = (json_decode($demoJson, true)['source_label'] ?? 'S001');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Demand Forecast</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
    <?php require_once __DIR__ . '/forecast_ui.php'; ?>
</head>
<body class="forecast-workspace">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Demand Forecast</h1>
                <p class="page-subtitle">Expected sales. Stock on hand. What needs attention.</p>
            </div>
            <div class="page-heading-actions">
                <a class="btn btn-primary" href="<?= htmlspecialchars(app_url('components/inventory_management/replenishment_queue.php')) ?>">Review replenishment</a>
                <?php if (has_capability(RoleCapabilityPolicy::GENERATE_FORECASTS)): ?>
                    <form method="post" action="" style="display:inline-block; margin:0;">
                        <?= csrf_field() ?>
                        <button type="submit" name="action" value="generate" class="btn btn-secondary" <?= $isGenerating ? 'disabled' : '' ?>><?= $isGenerating ? 'Generating...' : 'Generate forecasts' ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </header>
        <nav class="forecasting-nav-chips" aria-label="Forecasting Destinations">
            <a href="<?= htmlspecialchars(app_url('components/report/forecast_exceptions.php')) ?>">Forecast Exceptions</a>
            <a href="<?= htmlspecialchars(app_url('components/report/data_readiness.php')) ?>">Data Readiness</a>
            <?php if (has_capability(RoleCapabilityPolicy::APPROVE_MODELS)): ?>
                <a href="<?= htmlspecialchars(app_url('components/administrator/model_review.php')) ?>">Model Review &amp; Approval</a>
            <?php elseif ($userRole === 'super_admin'): ?>
                <a href="<?= htmlspecialchars(app_url('components/report/forecast_analytics.php')) ?>">Forecast Model Governance</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(app_url('components/administrator/store_calendar.php')) ?>">Store Calendar</a>
            <a href="<?= htmlspecialchars(app_url('components/inventory_management/promotions.php')) ?>">Promotions</a>
        </nav>
        <?php if ($isDemoMode): ?>
            <div class="forecast-notice" role="status"><strong>Historical demo · <?= htmlspecialchars($demoSource) ?></strong> — not current Store forecasts.</div>
        <?php endif; ?>
        <?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>" role="alert"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <section class="forecast-overview" aria-labelledby="heading-usability">
            <div>
                <h2 id="heading-usability">Forecast status</h2>
                <span class="status-badge <?= $statusBadgeClass ?>"><?= $statusBadgeLabel ?></span>
                <?php forecast_help('coverage-help', $coverage['coverage_summary_text'], 'About forecast availability'); ?>
            </div>
            <div class="forecast-coverage">
                <strong><?= (int)$coverage['forecast_backed_count'] ?> of <?= (int)$coverage['scope_count'] ?> products forecast-backed</strong>
                <meter min="0" max="<?= max(1, (int)$coverage['scope_count']) ?>" value="<?= (int)$coverage['forecast_backed_count'] ?>" aria-label="Forecast product coverage"></meter>
                <small><?= (int)$coverage['stock_rule_count'] ?> use stock rules</small>
            </div>
            <div><small>Forecast origin</small><strong><?= htmlspecialchars($forecastOrigin ?? 'Unavailable') ?></strong><small><?= htmlspecialchars($originAgeText) ?></small></div>
        </section>
        <?php if ($coverage['is_observation_mode'] || $coverage['is_recommendations_suspended']): ?>
            <div class="forecast-notice" role="status"><?= $coverage['is_recommendations_suspended'] ? 'Forecast buying suggestions are paused. Use stock rules.' : 'Observation only. Forecasts are not approved for routine buying.' ?></div>
        <?php endif; ?>
        <section class="forecast-actions" aria-labelledby="heading-actions">
            <h2 id="heading-actions">Needs attention</h2>
            <div role="list">
            <?php foreach ($operationalIssues as $issueIndex => $issue):
                $sevBadgeClass = match($issue['severity'] ?? 'info') {
                    'critical' => 'tag-danger', 'warning' => 'tag-warning',
                    'healthy', 'success' => 'tag-success', default => 'tag-info'
                };
            ?>
                <div class="action-issue-card" role="listitem">
                    <div>
                        <span class="status-badge <?= $sevBadgeClass ?>"><?= htmlspecialchars($issue['condition']) ?></span>
                        <?php if (!in_array($issue['severity'] ?? '', ['healthy', 'success'], true)): ?><small class="muted"><?= htmlspecialchars($issue['responsible_role']) ?></small><?php endif; ?>
                        <?php forecast_help('issue-' . $issueIndex, $issue['effect'], 'Effect of this forecast status'); ?>
                    </div>
                    <?php if (!empty($issue['action_url'])): ?>
                        <a href="<?= htmlspecialchars($issue['action_url']) ?>"><?= htmlspecialchars($issue['action_label']) ?> &rarr;</a>
                    <?php elseif (!empty($issue['action_label']) && !in_array($issue['severity'] ?? '', ['healthy', 'success'], true)): ?>
                        <small><?= htmlspecialchars($issue['action_label']) ?></small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </section>
        <?php require __DIR__ . '/forecast_stock_chart.php'; ?>
        <div class="forecast-panel" id="product-forecasts">
            <div class="forecast-panel-heading">
                <h2 style="font-size:1.15rem; margin:0;">
                    Product Forecasts (<?= count($displayProducts) ?><?= $coverage['filtered']['is_filtered'] ? ' matching search of ' . (int)$coverage['scope_count'] : '' ?>)
                </h2>
                <form method="get" action="" class="search-form" role="search" style="display:flex; gap:0.5rem; align-items:center; margin:0;">
                    <input type="search" name="search" aria-label="Search products by name, SKU, or ID" placeholder="Search by Name, SKU, or ID" value="<?= htmlspecialchars($searchQuery) ?>" class="input-text" style="min-width:240px;">
                    <button type="submit" class="btn btn-secondary">Search</button>
                    <?php if ($searchQuery !== ''): ?>
                        <a href="predictions.php" class="btn btn-quiet">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <p class="muted">Expected sales in Base Units · <?= htmlspecialchars($targetRangeText) ?> <?php forecast_help('quantity-help', '7-, 14-, and 28-day totals each start the day after the forecast origin. These are expected sales, not quantities to buy. Review Replenishment Recommendations before purchasing.', 'About forecast quantities'); ?></p>
            <?php if (empty($displayProducts) || $coverage['scope_count'] === 0): ?>
                <div class="empty-state">
                    <i class="bi bi-graph-up-arrow empty-icon"></i>
                    <h2>No Forecasts Available</h2>
                    <p><?= $searchQuery !== '' ? 'No matching products found in current catalog.' : 'Demand forecasts have not been generated yet or the last run failed.' ?></p>
                </div>
            <?php else: ?>
                <div class="table-wrap" role="region" aria-label="Demand forecasts table" tabindex="0">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Product</th>
                                <th scope="col">On hand</th>
                                <th scope="col">7 days</th>
                                <th scope="col">14 days</th>
                                <th scope="col">28 days</th>
                                <th scope="col">Forecast basis <?php forecast_help('basis-help', 'Forecast-backed products use estimated sales. Other products use stock rules. Readiness describes available evidence, not an accuracy guarantee.', 'About forecast basis'); ?></th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($displayProducts as $f):
                                $name = $f['product_name'] ?? "Product #{$f['product_id']}";
                                $sku = $f['sku'] ?? "PRD-{$f['product_id']}";
                                $stock = (int)($f['current_stock'] ?? 0);
                                $isForecastBacked = !empty($f['is_forecast_backed']);
                                $readiness = $f['readiness_level'] ?? 'Insufficient';
                            ?>
                                <tr>
                                    <td>
                                        <strong><a href="<?= htmlspecialchars(app_url('components/inventory_management/product_investigation.php?product_id=' . $f['product_id'])) ?>"><?= htmlspecialchars($name) ?></a></strong>
                                        <small class="forecast-sku"><?= htmlspecialchars($sku) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars((string)$stock) ?></td>
                                    <td><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_7_day'] ?? 0)) : '<span class="muted">Unavailable</span>' ?></td>
                                    <td><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_14_day'] ?? 0)) : '<span class="muted">Unavailable</span>' ?></td>
                                    <td><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_28_day'] ?? 0)) : '<span class="muted">Unavailable</span>' ?></td>
                                    <td>
                                        <?php if ($isForecastBacked): ?>
                                            <span class="status-badge readiness-tag-<?= htmlspecialchars($readiness) ?>">
                                                <?= htmlspecialchars($readiness) ?>
                                            </span>
                                            <?php forecast_help('basis-' . (int)$f['product_id'], (string)$f['basis'], 'Forecast evidence for ' . $name); ?>
                                        <?php else: ?>
                                            <span class="badge tag-secondary">Stock-rule</span>
                                            <?php forecast_help('excluded-' . (int)$f['product_id'], (string)($f['exclusion_reason'] ?? 'Stock rules'), 'Why ' . $name . ' uses stock rules'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars(app_url('components/inventory_management/product_investigation.php?product_id=' . $f['product_id'])) ?>">
                                            View forecast
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <details class="forecast-details">
            <summary>Forecast performance &amp; model details</summary>
            <p class="muted">Model: <?= htmlspecialchars($displayedForecastModel ?? 'None approved') ?> · <?= $coverage['is_observation_mode'] ? 'Observation only' : ($coverage['is_recommendations_suspended'] ? 'Buying suggestions paused' : 'Check product coverage above') ?></p>
            <p class="muted">Usable results: <?= $effectiveJobId ? 'Job #' . (int)$effectiveJobId : 'None' ?><?= $latestJob ? ' · Latest attempt: #' . (int)$latestJob['job_id'] . ' ' . htmlspecialchars($latestJobStatus) : '' ?></p>
        <section aria-labelledby="heading-performance">
            <div class="forecast-section-header">
                <h2 id="heading-performance">Forecast performance</h2>
                <span class="status-badge <?= $performanceSummary['status'] === 'available' ? 'tag-success' : 'tag-info' ?>">
                    <?= $performanceSummary['status'] === 'available' ? 'Evaluation Evidence' : 'Still Collecting Evidence' ?>
                </span>
            </div>

            <p class="muted">Daily errors compare one Product/day at lead times 1–7, 1–14, and 1–28 days; they are not errors of summed horizon totals. Technical qualification separately evaluates summed horizons using chronological backtests.</p>
            <?php if ($performanceSummary['status'] === 'still_collecting_evidence'): ?>
                <div class="forecast-summary tag-info" role="status" style="margin-bottom: 1rem;">
                    <strong>Still collecting evidence:</strong> <?= htmlspecialchars($performanceSummary['reason']) ?>
                    <p style="margin: 0.5rem 0 0 0; font-size: 0.9rem;" class="muted">
                        Predictions are evaluated against genuine subsequent Store sales. Stockout periods leave unmet demand unknown and are excluded from error calculations. Demo and historical backtests are never presented as live Store accuracy.
                    </p>
                </div>
            <?php else: ?>
                <div class="card" style="padding: 1.25rem; margin-bottom: 1rem;">
                    <p style="margin-top: 0;"><strong>Evaluated model:</strong> <code><?= htmlspecialchars($performanceSummary['model_version'] ?? 'Approved') ?></code></p>
                    <p class="muted"><?= htmlspecialchars(ucfirst($performanceSummary['model_association'])) ?> model · <?= (int)$performanceSummary['product_count'] ?> Products · <?= (int)$performanceSummary['usable_comparison_count'] ?> usable Product/date comparisons · <?= htmlspecialchars($performanceSummary['date_range']['start'] ?? 'Unavailable') ?> to <?= htmlspecialchars($performanceSummary['date_range']['end'] ?? 'Unavailable') ?>.</p>
                    <div class="table-wrap" role="region" aria-label="Performance metrics table" tabindex="0">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th scope="col">Forecast Lead Time</th>
                                    <th scope="col">Average Error (MAE, Base Units)</th>
                                    <th scope="col">Repeat Last Week (Baseline MAE)</th>
                                    <th scope="col">RMSE (Base Units)</th>
                                    <th scope="col">Repeat Last Week (Baseline RMSE)</th>
                                    <th scope="col">Model Error (WAPE)</th>
                                    <th scope="col">Repeat Last Week (Baseline WAPE)</th>
                                    <th scope="col">Overestimated Units</th>
                                    <th scope="col">Underestimated Units</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($performanceSummary['horizons'] as $horizon):
                                    $wapeText = isset($horizon['wape']) ? number_format($horizon['wape'] * 100, 1) . '%' : ($horizon['missing_metric_reason'] ?? 'Unavailable');
                                    $baseWapeText = isset($horizon['baseline_wape']) ? number_format($horizon['baseline_wape'] * 100, 1) . '%' : 'Unavailable';
                                    $overUnits = $horizon['overestimated_units'] !== null ? number_format($horizon['overestimated_units']) : 'Not recorded';
                                    $underUnits = $horizon['underestimated_units'] !== null ? number_format($horizon['underestimated_units']) : 'Not recorded';
                                ?>
                                    <tr>
                                        <td><strong>1–<?= (int)$horizon['horizon_days'] ?> Days Ahead</strong></td>
                                        <?php foreach (['mae', 'baseline_mae', 'rmse', 'baseline_rmse'] as $metric): ?>
                                            <td><?= isset($horizon[$metric]) ? number_format($horizon[$metric], 2) : 'Unavailable' ?></td>
                                        <?php endforeach; ?>
                                        <td><?= htmlspecialchars($wapeText) ?></td>
                                        <td><?= htmlspecialchars($baseWapeText) ?></td>
                                        <td><?= htmlspecialchars($overUnits) ?></td>
                                        <td><?= htmlspecialchars($underUnits) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="muted" style="font-size: 0.85rem; margin-top: 0.75rem; margin-bottom: 0;">
                        <?= htmlspecialchars($performanceSummary['notes']) ?>
                    </p>
                </div>
            <?php endif; ?>
        </section>

        </details>
    </main>
</div>
<?php if ($isGenerating): ?><script>setTimeout(() => window.location.replace(window.location.href), 5000);</script><?php endif; ?>
</body>
</html>
