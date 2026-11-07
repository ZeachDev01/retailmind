<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\DemandForecastService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\ReplenishmentQueueService;

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
            $message = 'Forecast generation queued. Results will update after the background worker finishes.';
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

$service = new DemandForecastService($pdo, new CandidateEvaluationService($pdo), new ReplenishmentQueueService($pdo));
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

$statusTitle = $isGenerating
    ? 'Forecast update in progress'
    : ($coverage['is_guidance_usable'] ? 'Expected sales are available' : 'Current sales forecasts are unavailable');

$displayProducts = $coverage['filtered']['is_filtered'] ? $coverage['filtered']['products'] : $coverage['products'];

$basisFilter = $_GET['basis'] ?? 'all';
if ($basisFilter === 'forecast_backed') {
    $displayProducts = array_values(array_filter($displayProducts, static fn($p) => !empty($p['is_forecast_backed'])));
} elseif ($basisFilter === 'stock_rules') {
    $displayProducts = array_values(array_filter($displayProducts, static fn($p) => empty($p['is_forecast_backed'])));
}

$totalFilteredProducts = count($displayProducts);
$showAllProducts = (isset($_GET['show']) && $_GET['show'] === 'all') || (isset($_GET['per_page']) && $_GET['per_page'] === 'all');
$productsPerPage = 15;
$currentProductPage = max(1, (int)($_GET['p'] ?? 1));
$totalProductPages = max(1, (int)ceil($totalFilteredProducts / $productsPerPage));
if ($currentProductPage > $totalProductPages) {
    $currentProductPage = $totalProductPages;
}
$productOffset = ($currentProductPage - 1) * $productsPerPage;
$paginatedProducts = $showAllProducts ? $displayProducts : array_slice($displayProducts, $productOffset, $productsPerPage);

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
<body class="forecast-workspace forecast-predictions-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Demand Forecast</h1>
                <p class="page-subtitle">Expected sales. Stock on hand. What needs attention.</p>
            </div>
        </header>
        <?php forecast_workflow('forecast'); ?>
        <?php
        $primaryIssue = $operationalIssues[0] ?? null;
        $nextUrl = $primaryIssue['action_url'] ?? null;
        $nextLabel = $primaryIssue['action_label'] ?? 'Check sales data';
        $nextText = $primaryIssue ? $primaryIssue['responsible_role'] . ': ' . $nextLabel . '.' : 'Check sales data and model approval.';
        if (in_array($primaryIssue['severity'] ?? '', ['healthy', 'success'], true)) {
            $nextText = has_capability(RoleCapabilityPolicy::MUTATE_INVENTORY)
                ? 'Review expected sales and buying suggestions.'
                : 'Review product forecasts below. No forecast issues need action.';
            $nextUrl = null;
        }
        if (empty($coverage['approved_model_version'])) {
            $nextText = has_capability(RoleCapabilityPolicy::CONFIGURE_MODELS)
                ? 'Check the training results before sending a model for Administrator review.'
                : (has_capability(RoleCapabilityPolicy::APPROVE_MODELS)
                    ? 'Review the candidate model and its approval requirements.'
                    : 'Use stock rules for buying while the Administrator reviews the model.');
            $nextUrl = has_capability(RoleCapabilityPolicy::CONFIGURE_MODELS)
                ? app_url('components/system_administrator/ml_settings.php')
                : (has_capability(RoleCapabilityPolicy::APPROVE_MODELS) ? app_url('components/administrator/model_review.php') : null);
            $nextLabel = has_capability(RoleCapabilityPolicy::CONFIGURE_MODELS) ? 'Check training result' : 'Check model review';
        }
        ?>
        <?php if ($isDemoMode): ?>
            <div class="forecast-notice" role="status"><strong>Historical demo · <?= htmlspecialchars($demoSource) ?></strong> — not current Store forecasts.</div>
        <?php endif; ?>
        <?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>" role="alert"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <section class="forecast-overview forecast-status-card" aria-labelledby="heading-usability">
            <div class="forecast-status-content">
                <div class="forecast-status-heading">
                    <h2 id="heading-usability">Forecast status</h2>
                    <span class="status-badge <?= $statusBadgeClass ?>"><?= $statusBadgeLabel ?></span>
                    <?php forecast_help('coverage-help', $coverage['coverage_summary_text'], 'About forecast availability'); ?>
                </div>
                <p class="forecast-status-message">
                    <strong><?= htmlspecialchars($statusTitle) ?></strong>
                    <?= htmlspecialchars($isGenerating ? 'Wait for the background job. Buying suggestions keep their current basis until it finishes.' : $nextText) ?>
                </p>
            </div>
            <dl class="forecast-facts">
                <div class="forecast-fact">
                    <dt>Products with forecasts</dt>
                    <dd>
                        <strong><?= (int)$coverage['forecast_backed_count'] ?> of <?= (int)$coverage['scope_count'] ?></strong>
                        <meter min="0" max="<?= max(1, (int)$coverage['scope_count']) ?>" value="<?= (int)$coverage['forecast_backed_count'] ?>" aria-label="Forecast product coverage"></meter>
                        <small><?= (int)$coverage['stock_rule_count'] ?> using stock rules</small>
                    </dd>
                </div>
                <div class="forecast-fact">
                    <dt>Sales history through</dt>
                    <dd>
                        <strong><?= htmlspecialchars($forecastOrigin ?? 'Unavailable') ?></strong>
                        <small><?= htmlspecialchars($originAgeText) ?></small>
                    </dd>
                </div>
            </dl>
            <?php if ($nextUrl && !$isGenerating): ?>
                <a class="btn btn-primary forecast-status-action" href="<?= htmlspecialchars($nextUrl) ?>"><?= htmlspecialchars($nextLabel) ?></a>
            <?php endif; ?>
        </section>
        <?php if ($coverage['is_observation_mode'] || $coverage['is_recommendations_suspended']): ?>
            <div class="forecast-notice" role="status"><?= $coverage['is_recommendations_suspended'] ? 'Forecast buying suggestions are paused. Use stock rules.' : 'Observation only. Forecasts are not approved for routine buying.' ?></div>
        <?php endif; ?>
        <details class="forecast-details forecast-actions">
            <summary id="heading-actions"><span>Forecast checks <span class="forecast-check-count"><?= count(array_filter($operationalIssues, static fn(array $issue): bool => !in_array($issue['severity'] ?? '', ['healthy', 'success'], true))) ?> to review</span></span></summary>
            <div role="list">
            <?php foreach ($operationalIssues as $issueIndex => $issue):
                $issueIcon = match($issue['severity'] ?? 'info') {
                    'critical' => 'exclamation-octagon', 'warning' => 'exclamation-triangle',
                    'healthy', 'success' => 'check-circle', default => 'info-circle'
                };
                $issueTitle = match($issue['category'] ?? '') {
                    'paused' => 'Buying suggestions paused',
                    'blocked_recovery' => 'Forecast update blocked',
                    'exhausted_recovery' => 'Forecast update needs support',
                    'retry_scheduled' => 'Forecast update will retry',
                    'empty_store' => 'No active products',
                    'stale_guidance' => 'Forecasts need updating',
                    'no_approved_model' => 'No approved model',
                    'partial_coverage' => (int)$coverage['stock_rule_count'] . ' products use stock rules',
                    'stock_rules_only' => 'All products use stock rules',
                    'candidate_review' => 'Candidate ready for review',
                    'observation_mode' => 'Model under observation',
                    'updating' => 'Forecast update in progress',
                    'healthy' => 'All checks passed',
                    default => $issue['condition']
                };
            ?>
                <div class="action-issue-card" role="listitem">
                    <i class="bi bi-<?= $issueIcon ?> forecast-check-icon is-<?= htmlspecialchars($issue['severity'] ?? 'info') ?>" role="img" aria-label="<?= htmlspecialchars(ucfirst($issue['severity'] ?? 'info')) ?>"></i>
                    <div class="forecast-check-copy">
                        <div class="forecast-check-title">
                            <strong><?= htmlspecialchars($issueTitle) ?></strong>
                            <?php forecast_help('issue-' . $issueIndex, $issue['condition'] . ' ' . $issue['effect'], 'Details: ' . $issueTitle); ?>
                        </div>
                        <?php if (!in_array($issue['severity'] ?? '', ['healthy', 'success'], true)): ?><small><?= htmlspecialchars($issue['responsible_role']) ?></small><?php endif; ?>
                    </div>
                    <?php if (!empty($issue['action_url'])): ?>
                        <a href="<?= htmlspecialchars($issue['action_url']) ?>"><?= htmlspecialchars($issue['action_label']) ?> &rarr;</a>
                    <?php elseif (!empty($issue['action_label']) && !in_array($issue['severity'] ?? '', ['healthy', 'success'], true)): ?>
                        <small><?= htmlspecialchars($issue['action_label']) ?></small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </details>
        <div class="forecast-panel forecast-products" id="product-forecasts">
            <div class="forecast-panel-heading">
                <div class="forecast-panel-title">
                    <h2>Product Forecasts <span class="forecast-count">(<?= $totalFilteredProducts ?><?= $coverage['filtered']['is_filtered'] || $basisFilter !== 'all' ? ' of ' . (int)$coverage['scope_count'] : '' ?>)</span></h2>
                    <p class="forecast-panel-subtitle">Expected sales in Base Units · <?= htmlspecialchars($targetRangeText) ?> <?php forecast_help('quantity-help', '7-, 14-, and 28-day totals each start the day after the forecast origin. These are expected sales, not quantities to buy. Review Replenishment Recommendations before purchasing.', 'About forecast quantities'); ?></p>
                </div>
                <div class="forecast-panel-toolbar">
                    <nav class="forecast-basis-tabs" aria-label="Forecast filter basis">
                        <a href="?<?= htmlspecialchars(http_build_query(array_filter(array_merge($_GET, ['basis' => 'all', 'p' => 1])))) ?>" class="forecast-basis-chip <?= $basisFilter === 'all' ? 'is-active' : '' ?>">All (<?= (int)$coverage['scope_count'] ?>)</a>
                        <a href="?<?= htmlspecialchars(http_build_query(array_filter(array_merge($_GET, ['basis' => 'forecast_backed', 'p' => 1])))) ?>" class="forecast-basis-chip <?= $basisFilter === 'forecast_backed' ? 'is-active' : '' ?>">Forecast-backed (<?= (int)$coverage['forecast_backed_count'] ?>)</a>
                        <a href="?<?= htmlspecialchars(http_build_query(array_filter(array_merge($_GET, ['basis' => 'stock_rules', 'p' => 1])))) ?>" class="forecast-basis-chip <?= $basisFilter === 'stock_rules' ? 'is-active' : '' ?>">Stock rules (<?= (int)$coverage['stock_rule_count'] ?>)</a>
                    </nav>
                    <form method="get" action="" class="search-form" role="search">
                        <?php if ($basisFilter !== 'all'): ?><input type="hidden" name="basis" value="<?= htmlspecialchars($basisFilter) ?>"><?php endif; ?>
                        <input type="search" name="search" aria-label="Search products by name, SKU, or ID" placeholder="Name, SKU, or ID" value="<?= htmlspecialchars($searchQuery) ?>" class="input-text">
                        <button type="submit" class="btn btn-secondary">Search</button>
                        <?php if ($searchQuery !== ''): ?>
                            <a href="predictions.php<?= $basisFilter !== 'all' ? '?basis=' . urlencode($basisFilter) : '' ?>" class="btn btn-quiet">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php if (empty($displayProducts) || $coverage['scope_count'] === 0): ?>
                <div class="empty-state">
                    <i class="bi bi-graph-up-arrow empty-icon"></i>
                    <h2><?= $searchQuery !== '' ? 'No matching products' : 'No forecasts available' ?></h2>
                    <p><?= $searchQuery !== '' ? 'No matching products found. Clear the search to view all products.' : 'Demand forecasts have not been generated yet or the last run failed.' ?></p>
                </div>
            <?php else: ?>
                <div class="table-wrap" role="region" aria-label="Demand forecasts table" tabindex="0">
                    <table class="data-table forecast-table" data-no-smart-table>
                        <thead>
                            <tr>
                                <th scope="col">Product</th>
                                <th scope="col" class="num">On hand</th>
                                <th scope="col" class="num">7 days</th>
                                <th scope="col" class="num">14 days</th>
                                <th scope="col" class="num">28 days</th>
                                <th scope="col">Forecast basis <?php forecast_help('basis-help', 'Forecast-backed products use estimated sales. Other products use stock rules. Readiness describes available evidence, not an accuracy guarantee.', 'About forecast basis'); ?></th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paginatedProducts as $f):
                                $name = $f['product_name'] ?? "Product #{$f['product_id']}";
                                $sku = $f['sku'] ?? "PRD-{$f['product_id']}";
                                $stock = (int)($f['current_stock'] ?? 0);
                                $isForecastBacked = !empty($f['is_forecast_backed']);
                                $readiness = $f['readiness_level'] ?? 'Insufficient';
                                $missingForecast = '<span class="muted" aria-label="No forecast">&mdash;</span>';
                            ?>
                                <tr>
                                    <td class="forecast-product-cell" data-label="Product">
                                        <strong><a href="<?= htmlspecialchars(app_url('components/inventory_management/product_investigation.php?product_id=' . $f['product_id'])) ?>"><?= htmlspecialchars($name) ?></a></strong>
                                        <small class="forecast-sku"><?= htmlspecialchars($sku) ?></small>
                                    </td>
                                    <td class="num" data-label="On hand"><?= htmlspecialchars((string)$stock) ?></td>
                                    <td class="num" data-label="7 days"><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_7_day'] ?? 0)) : $missingForecast ?></td>
                                    <td class="num" data-label="14 days"><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_14_day'] ?? 0)) : $missingForecast ?></td>
                                    <td class="num forecast-strong" data-label="28 days"><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_28_day'] ?? 0)) : $missingForecast ?></td>
                                    <td data-label="Forecast basis">
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
                                    <td class="forecast-action-cell" data-label="Action">
                                        <a class="btn btn-sm btn-secondary forecast-row-link" href="<?= htmlspecialchars(app_url('components/inventory_management/product_investigation.php?product_id=' . $f['product_id'])) ?>">
                                            View forecast
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalFilteredProducts > $productsPerPage): ?>
                    <nav class="forecast-pagination" aria-label="Products pagination">
                        <span class="pagination-info">Showing <?= $showAllProducts ? '1–' . $totalFilteredProducts : ($productOffset + 1) . '–' . min($totalFilteredProducts, $productOffset + $productsPerPage) ?> of <?= $totalFilteredProducts ?> products</span>
                        <div class="pagination-links">
                            <?php if (!$showAllProducts && $totalProductPages > 1): ?>
                                <?php if ($currentProductPage > 1): ?>
                                    <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['p' => $currentProductPage - 1]))) ?>#product-forecasts" class="btn btn-sm btn-quiet">&larr; Previous</a>
                                <?php endif; ?>
                                <span class="pagination-current">Page <?= $currentProductPage ?> of <?= $totalProductPages ?></span>
                                <?php if ($currentProductPage < $totalProductPages): ?>
                                    <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['p' => $currentProductPage + 1]))) ?>#product-forecasts" class="btn btn-sm btn-quiet">Next &rarr;</a>
                                <?php endif; ?>
                                <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['show' => 'all']))) ?>#product-forecasts" class="pagination-toggle">View all</a>
                            <?php elseif ($showAllProducts): ?>
                                <a href="?<?= htmlspecialchars(http_build_query(array_diff_key($_GET, ['show' => '']))) ?>#product-forecasts" class="pagination-toggle">Show paginated</a>
                            <?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php require __DIR__ . '/forecast_stock_chart.php'; ?>
        <details class="forecast-related forecast-tools"><summary>More forecasting tools</summary>
        <nav class="forecasting-nav-chips" aria-label="Forecasting Destinations">
            <a href="<?= htmlspecialchars(app_url('components/report/forecast_exceptions.php')) ?>">Forecast Exceptions</a>
            <a href="<?= htmlspecialchars(app_url('components/report/data_readiness.php')) ?>">Data Readiness</a>
            <?php if (has_capability(RoleCapabilityPolicy::APPROVE_MODELS)): ?>
                <a href="<?= htmlspecialchars(app_url('components/administrator/model_review.php')) ?>">Model Review &amp; Approval</a>
            <?php elseif ($userRole === 'super_admin'): ?>
                <a href="<?= htmlspecialchars(app_url('components/report/forecast_analytics.php')) ?>">Forecast Analytics</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(app_url('components/administrator/store_calendar.php')) ?>">Store Calendar</a>
            <a href="<?= htmlspecialchars(app_url('components/inventory_management/promotions.php')) ?>">Promotions</a>
        </nav>
        </details>
        <details class="forecast-details forecast-performance">
            <summary>Forecast performance &amp; model details</summary>
            <p class="muted forecast-model-line">Model: <?= htmlspecialchars($displayedForecastModel ?? 'None approved') ?> · <?= $coverage['is_observation_mode'] ? 'Observation only' : ($coverage['is_recommendations_suspended'] ? 'Buying suggestions paused' : 'Check product coverage above') ?></p>
            <p class="muted forecast-model-line">Usable results: <?= $effectiveJobId ? 'Job #' . (int)$effectiveJobId : 'None' ?><?= $latestJob ? ' · Latest attempt: #' . (int)$latestJob['job_id'] . ' ' . htmlspecialchars($latestJobStatus) : '' ?></p>
        <section aria-labelledby="heading-performance">
            <div class="forecast-section-header">
                <h2 id="heading-performance">Forecast performance</h2>
                <span class="status-badge <?= $performanceSummary['status'] === 'available' ? 'tag-success' : 'tag-info' ?>">
                    <?= $performanceSummary['status'] === 'available' ? 'Evaluation Evidence' : 'Still Collecting Evidence' ?>
                </span>
            </div>

            <p class="muted forecast-performance-note">Daily errors compare one Product/day at lead times 1–7, 1–14, and 1–28 days; they are not errors of summed horizon totals. Technical qualification separately evaluates summed horizons using chronological backtests.</p>
            <?php if ($performanceSummary['status'] === 'still_collecting_evidence'): ?>
                <div class="forecast-summary tag-info forecast-collecting" role="status">
                    <strong>Still collecting evidence:</strong> <?= htmlspecialchars($performanceSummary['reason']) ?>
                    <p class="muted forecast-collecting-note">
                        Predictions are evaluated against genuine subsequent Store sales. Stockout periods leave unmet demand unknown and are excluded from error calculations. Demo and historical backtests are never presented as live Store accuracy.
                    </p>
                </div>
            <?php else: ?>
                <div class="card forecast-performance-card">
                    <p class="forecast-evaluated-model"><strong>Evaluated model:</strong> <code><?= htmlspecialchars($performanceSummary['model_version'] ?? 'Approved') ?></code></p>
                    <p class="muted"><?= htmlspecialchars(ucfirst($performanceSummary['model_association'])) ?> model · <?= (int)$performanceSummary['product_count'] ?> Products · <?= (int)$performanceSummary['usable_comparison_count'] ?> usable Product/date comparisons · <?= htmlspecialchars($performanceSummary['date_range']['start'] ?? 'Unavailable') ?> to <?= htmlspecialchars($performanceSummary['date_range']['end'] ?? 'Unavailable') ?>.</p>
                    <div class="table-wrap" role="region" aria-label="Performance metrics table" tabindex="0">
                        <table class="data-table" data-no-smart-table>
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
                    <p class="muted forecast-performance-disclaimer">
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
