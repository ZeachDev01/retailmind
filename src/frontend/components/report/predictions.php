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

$isGenerating = $coverage['is_updating'];

// Forecast origin
$forecastOrigin = null;
foreach ($coverage['products'] as $p) {
    if (!empty($p['forecast_origin'])) {
        $forecastOrigin = $p['forecast_origin'];
        break;
    }
}

// Guidance target range
$targetRangeText = 'Unavailable';
if ($forecastOrigin) {
    $t1 = date('M d, Y', strtotime("$forecastOrigin +1 day"));
    $t28 = date('M d, Y', strtotime("$forecastOrigin +28 days"));
    $targetRangeText = "{$t1} to {$t28}";
}

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

$operationalIssues = $coverage['operational_issues'] ?? $service->getOperationalIssues($coverage, $userRole);
$primaryIssue = $operationalIssues[0] ?? null;
$reviewCount = count(array_filter($operationalIssues, static fn(array $issue): bool => !in_array($issue['severity'] ?? '', ['healthy', 'success'], true)));
$scopeCount = (int)$coverage['scope_count'];
$forecastCount = (int)$coverage['forecast_backed_count'];
$coverageText = $scopeCount > 0 ? "$forecastCount of $scopeCount products have a forecast." : '';

if (in_array($primaryIssue['category'] ?? '', ['no_approved_model', 'blocked_setup_ready'], true)) {
    foreach ($operationalIssues as $issue) {
        if ($issue['category'] === 'candidate_review') {
            $primaryIssue = $issue;
            break;
        }
    }
}

[$statusTitle, $statusIcon, $statusTone, $nextText] = match (true) {
    $isGenerating => ['Updating forecasts', 'arrow-repeat', 'info', 'The update runs automatically.'],
    $scopeCount === 0 => ['No active products yet', 'info-circle', 'warning', 'Forecasts need active products and sales history.'],
    ($primaryIssue['category'] ?? '') === 'exhausted_recovery' => ['Forecast update needs support', 'exclamation-octagon', 'danger', 'The Super Administrator can check the update.'],
    ($primaryIssue['category'] ?? '') === 'stale_guidance' => ['Forecasts need an update', 'clock-history', 'warning', 'The saved forecasts are out of date.'],
    ($primaryIssue['category'] ?? '') === 'retry_scheduled' => ['Forecast update will retry', 'arrow-repeat', 'warning', 'The next attempt runs automatically.'],
    ($primaryIssue['category'] ?? '') === 'candidate_review' => ['Model ready for review', 'clipboard-check', 'warning', 'A trained model is ready for the Administrator to review.'],
    ($primaryIssue['category'] ?? '') === 'blocked_setup_ready' => ['Waiting for model training', 'info-circle', 'warning', 'Sales history is ready. A model needs to be trained.'],
    in_array($primaryIssue['category'] ?? '', ['blocked_setup_collecting', 'no_approved_model'], true)
        => ['No product forecasts yet', 'info-circle', 'warning', 'Check if sales history is ready.'],
    $forecastCount === 0 => ['No product forecasts yet', 'info-circle', 'warning', $primaryIssue['condition'] ?? ''],
    $forecastCount < $scopeCount => ['Some products have forecasts', 'info-circle', 'warning', ''],
    default => ['Forecasts are ready', 'check2-circle', 'success', ''],
};
$nextUrl = ($primaryIssue['category'] ?? '') === 'blocked_setup_ready' && $userRole !== 'super_admin' ? null : ($primaryIssue['action_url'] ?? null);
$nextLabel = match ($primaryIssue['category'] ?? '') {
    'blocked_setup_collecting', 'empty_store' => 'Check data readiness',
    'no_approved_model' => $userRole === 'admin' ? 'Check data readiness' : ($primaryIssue['action_label'] ?? ''),
    'candidate_review' => $userRole === 'admin' ? 'Review model' : ($primaryIssue['action_label'] ?? ''),
    default => $primaryIssue['action_label'] ?? '',
};
if (!$nextUrl && $coverage['is_guidance_usable'] && !$coverage['is_observation_mode'] && !$coverage['is_recommendations_suspended'] && has_capability(RoleCapabilityPolicy::MUTATE_INVENTORY)) {
    $nextUrl = app_url('components/inventory_management/replenishment_queue.php');
    $nextLabel = 'See what to reorder';
}

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
            </div>
        </header>
        <?php forecast_workflow('forecast'); ?>
        <?php if ($isDemoMode): ?>
            <div class="forecast-notice" role="status"><strong>Historical demo · <?= htmlspecialchars($demoSource) ?></strong> — not current Store forecasts.</div>
        <?php endif; ?>
        <?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>" role="alert"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <section class="forecast-status-card is-<?= $statusTone ?>" aria-labelledby="heading-usability">
            <i class="bi bi-<?= $statusIcon ?> forecast-status-icon" aria-hidden="true"></i>
            <div class="forecast-status-copy">
                <h2 id="heading-usability"><?= htmlspecialchars($statusTitle) ?></h2>
                <p><?= htmlspecialchars(trim($coverageText . ' ' . $nextText)) ?></p>
            </div>
            <?php if ($nextUrl && !$isGenerating): ?>
                <a class="btn btn-primary forecast-status-action" href="<?= htmlspecialchars($nextUrl) ?>"><?= htmlspecialchars($nextLabel) ?></a>
            <?php endif; ?>
        </section>
        <?php if ($coverage['is_observation_mode'] || $coverage['is_recommendations_suspended']): ?>
            <div class="forecast-notice" role="status"><?= $coverage['is_recommendations_suspended'] ? 'Forecast buying suggestions are paused. Use stock rules.' : 'Observation only. Forecasts are not approved for routine buying.' ?></div>
        <?php endif; ?>
        <details class="forecast-details forecast-actions">
            <summary id="heading-actions"><span>Forecast checks <span class="forecast-check-count"><?= $reviewCount > 0 ? $reviewCount . ' to review' : 'All clear' ?></span></span></summary>
            <div role="list">
            <?php foreach ($operationalIssues as $issueIndex => $issue):
                $issueIcon = match($issue['severity'] ?? 'info') {
                    'critical' => 'exclamation-octagon', 'warning' => 'exclamation-triangle',
                    'healthy', 'success' => 'check-circle', default => 'info-circle'
                };
                $issueTitle = match($issue['category'] ?? '') {
                    'paused' => 'Buying suggestions paused',
                    'blocked_recovery' => 'Forecast update blocked',
                    'blocked_setup_collecting' => 'Forecasting is collecting sales history',
                    'blocked_setup_ready' => 'Sales history ready; no model approved',
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
                    <h2>Product forecasts <span class="forecast-count">(<?= $totalFilteredProducts ?><?= $coverage['filtered']['is_filtered'] || $basisFilter !== 'all' ? ' of ' . (int)$coverage['scope_count'] : '' ?>)</span></h2>
                    <p class="forecast-panel-subtitle"><?= htmlspecialchars($targetRangeText) ?> <?php forecast_help('quantity-help', 'Expected units customers may buy in the next 7, 14, or 28 days. These are sales estimates, not a buying order. Use Replenishment Recommendations for purchase quantities.', 'About these numbers'); ?></p>
                </div>
                <div class="forecast-panel-toolbar">
                    <nav class="forecast-basis-tabs" aria-label="Filter products by forecast basis">
                        <a href="?<?= htmlspecialchars(http_build_query(array_filter(array_merge($_GET, ['basis' => 'all', 'p' => 1])))) ?>" class="forecast-basis-chip <?= $basisFilter === 'all' ? 'is-active' : '' ?>"<?= $basisFilter === 'all' ? ' aria-current="page"' : '' ?>>All products (<?= (int)$coverage['scope_count'] ?>)</a>
                        <a href="?<?= htmlspecialchars(http_build_query(array_filter(array_merge($_GET, ['basis' => 'forecast_backed', 'p' => 1])))) ?>" class="forecast-basis-chip <?= $basisFilter === 'forecast_backed' ? 'is-active' : '' ?>"<?= $basisFilter === 'forecast_backed' ? ' aria-current="page"' : '' ?>>Has forecast (<?= (int)$coverage['forecast_backed_count'] ?>)</a>
                        <a href="?<?= htmlspecialchars(http_build_query(array_filter(array_merge($_GET, ['basis' => 'stock_rules', 'p' => 1])))) ?>" class="forecast-basis-chip <?= $basisFilter === 'stock_rules' ? 'is-active' : '' ?>"<?= $basisFilter === 'stock_rules' ? ' aria-current="page"' : '' ?>>No forecast (<?= (int)$coverage['stock_rule_count'] ?>)</a>
                    </nav>
                    <form method="get" action="" class="search-form" role="search">
                        <?php if ($basisFilter !== 'all'): ?><input type="hidden" name="basis" value="<?= htmlspecialchars($basisFilter) ?>"><?php endif; ?>
                        <input type="search" name="search" autocomplete="off" aria-label="Search products by name, SKU, or ID" placeholder="Product name or SKU" value="<?= htmlspecialchars($searchQuery) ?>" class="input-text">
                        <button type="submit" class="btn btn-secondary">Search</button>
                        <?php if ($searchQuery !== ''): ?>
                            <a href="predictions.php<?= $basisFilter !== 'all' ? '?basis=' . urlencode($basisFilter) : '' ?>" class="btn btn-quiet">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php if (empty($displayProducts) || $coverage['scope_count'] === 0): ?>
                <div class="empty-state">
                    <i class="bi bi-graph-up-arrow empty-icon" aria-hidden="true"></i>
                    <h2><?= $searchQuery !== '' ? 'No matching products' : 'No forecasts available' ?></h2>
                    <p><?= $searchQuery !== '' ? 'No matching products found. Clear the search to view all products.' : 'Generate a forecast or add products first. Expected sales will appear here once they are ready.' ?></p>
                </div>
            <?php else: ?>
                <div class="table-wrap" role="region" aria-label="Demand forecasts table" tabindex="0">
                    <table class="data-table forecast-table" data-no-smart-table data-no-ghost-rows>
                        <thead>
                            <tr>
                                <th scope="col">Product</th>
                                <th scope="col" class="num">In stock</th>
                                <th scope="col" class="num">7 days</th>
                                <th scope="col" class="num">14 days</th>
                                <th scope="col" class="num">28 days</th>
                                <th scope="col">
                                    <span class="forecast-quality-line">
                                        <span>Forecast quality</span>
                                        <?php forecast_help('basis-help', 'Quality shows how much recent sales history supports the estimate. Products without a forecast use stock rules instead.', 'About forecast quality'); ?>
                                    </span>
                                </th>
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
                                $qualityLabel = ['High' => 'Good', 'Medium' => 'Fair', 'Low' => 'Limited', 'Insufficient' => 'Limited'][$readiness] ?? $readiness;
                                $missingForecast = '<span class="muted" aria-label="No forecast">&mdash;</span>';
                            ?>
                                <tr>
                                    <td class="forecast-product-cell" data-label="Product">
                                        <strong><a href="<?= htmlspecialchars(app_url('components/inventory_management/product_investigation.php?product_id=' . $f['product_id'])) ?>"><?= htmlspecialchars($name) ?></a></strong>
                                        <small class="forecast-sku"><?= htmlspecialchars($sku) ?></small>
                                    </td>
                                    <td class="num" data-label="In stock"><?= htmlspecialchars((string)$stock) ?></td>
                                    <td class="num" data-label="7 days"><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_7_day'] ?? 0)) : $missingForecast ?></td>
                                    <td class="num" data-label="14 days"><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_14_day'] ?? 0)) : $missingForecast ?></td>
                                    <td class="num forecast-strong" data-label="28 days"><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_28_day'] ?? 0)) : $missingForecast ?></td>
                                    <td data-label="Forecast quality">
                                        <span class="forecast-quality-line">
                                            <?php if ($isForecastBacked): ?>
                                                <span class="status-badge readiness-tag-<?= htmlspecialchars($readiness) ?>">
                                                     <?= htmlspecialchars($qualityLabel) ?>
                                                </span>
                                                <?php forecast_help('basis-' . (int)$f['product_id'], (string)$f['basis'], 'Forecast evidence for ' . $name); ?>
                                            <?php else: ?>
                                                <span class="status-badge readiness-tag-Insufficient">No forecast</span>
                                                <?php forecast_help('excluded-' . (int)$f['product_id'], (string)($f['exclusion_reason'] ?? 'Stock rules'), 'Why ' . $name . ' has no forecast'); ?>
                                            <?php endif; ?>
                                        </span>
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
                                <a href="?<?= htmlspecialchars(http_build_query(array_diff_key($_GET, ['show' => '']))) ?>#product-forecasts" class="pagination-toggle">Show pages</a>
                            <?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php require __DIR__ . '/forecast_stock_chart.php'; ?>
    </main>
</div>
<?php if ($isGenerating): ?><script>setTimeout(() => window.location.replace(window.location.href), 5000);</script><?php endif; ?>
</body>
</html>
