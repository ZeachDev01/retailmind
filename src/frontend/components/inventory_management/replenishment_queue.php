<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/backend/bootstrap/app.php';
require_once dirname(__DIR__, 3) . '/backend/includes/csrf.php';
require_once dirname(__DIR__, 3) . '/backend/includes/theme.php';
require_once dirname(__DIR__, 3) . '/backend/includes/auth.php';
require_once dirname(__DIR__, 3) . '/backend/includes/functions.php';

use App\Core\Session;
use App\Support\OperatorAlert;
use App\Forecasting\DemandForecastService;
use App\Forecasting\ReplenishmentQueueService;

Session::start();
require_role(['admin', 'super_admin', 'inventory_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$pageTitle = 'Replenishment Recommendations';
$error = '';
$activeModelVersion = '';
$isDemo = false;
$isProduction = false;
$queueUnavailable = false;
$canReceive = has_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);

$jobId = null;
try {
    $forecastService = new DemandForecastService($pdo);
    $jobId = $forecastService->getLatestSuccessfulJobId();
    
    $queue = [];
    if ($jobId) {
        $jobMeta = $forecastService->getJobMetadata($jobId);
        $activeModelVersion = $jobMeta['model_version'] ?? '';
        $isDemo = $jobMeta['is_demo'] ?? false;
        $isProduction = ($jobMeta['dataset_kind'] ?? null) === 'store';

    }
} catch (Throwable $e) {
    $jobId = null;
    $error = OperatorAlert::message($e, 'Forecast unavailable. Showing stock-rule guidance.');
}

try {
    $queue = (new ReplenishmentQueueService($pdo))->getRecommendations($jobId);
} catch (Throwable $e) {
    $error = OperatorAlert::message($e, 'Forecast unavailable. Showing stock-rule guidance.');
    try {
        $queue = (new ReplenishmentQueueService($pdo))->getRecommendations();
    } catch (Throwable $stockError) {
        $queue = [];
        $queueUnavailable = true;
        $error = OperatorAlert::message($stockError, 'Could not load replenishment recommendations. Reload the page or tell your Administrator.');
    }
}

$stockPriority = ['Out of stock' => 0, 'Low stock' => 1, 'In stock' => 2];
usort($queue, static fn (array $a, array $b): int =>
    ($stockPriority[$a['stock_status']] <=> $stockPriority[$b['stock_status']])
    ?: ($a['current_stock'] <=> $b['current_stock'])
    ?: ($a['product_id'] <=> $b['product_id']));
$stockCounts = array_count_values(array_column($queue, 'stock_status'));
$replenishCount = count(array_filter($queue, static fn (array $item): bool => $item['recommended_quantity'] > 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - RetailMind</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/replenishment.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/replenishment.css')) ?>">
    <script src="<?= htmlspecialchars(app_url('assets/js/replenishment.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/replenishment.js')) ?>" defer></script>
</head>
<body class="replenishment-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1><?= htmlspecialchars($pageTitle) ?></h1>
                <p class="page-subtitle">Know what needs buying. Receive it when it arrives.</p>
            </div>
            <?php if ($queue): ?>
                <button class="btn btn-secondary queue-print no-print" type="button" onclick="window.print()" aria-label="Print all recommendations"><i class="bi bi-printer" aria-hidden="true"></i> Print</button>
            <?php endif; ?>
        </div>

            <?php if ($error): ?>
                <div class="alert tag-warning" role="status">
                    <span><?= htmlspecialchars($error) ?></span>
                    <a class="btn btn-secondary no-print" href="replenishment_queue.php">Reload</a>
                </div>
            <?php endif; ?>

            <section class="queue-summary" aria-label="Recommendation summary">
                <?php foreach (['Out of stock' => 'Needs restocking', 'Low stock' => 'At or below reorder level', 'In stock' => 'Above reorder level'] as $stockStatus => $description): ?>
                    <a class="queue-stat queue-<?= str_replace(' ', '-', strtolower($stockStatus)) ?>" href="#queue-products" data-queue-stock="<?= $stockStatus ?>" aria-label="Show <?= $stockStatus ?> products">
                        <strong class="queue-stat-number"><?= $queueUnavailable ? '-' : ($stockCounts[$stockStatus] ?? 0) ?></strong>
                        <div><strong class="queue-stat-label"><?= $stockStatus ?></strong><span><?= $description ?></span></div>
                    </a>
                <?php endforeach; ?>
                <div class="queue-stat queue-forecast">
                    <i class="bi bi-clock" aria-hidden="true"></i>
                    <div><strong><?= $isDemo ? 'Demo Mode' : ($isProduction ? 'Production forecast' : ($jobId ? 'Demand Forecast' : 'Stock-rule guidance')) ?></strong>
                        <span><?= $activeModelVersion ? 'Model: ' . htmlspecialchars($activeModelVersion) : 'Forecast unavailable' ?></span>
                    </div>
                </div>
            </section>
            <aside class="queue-guidance" aria-label="Buying guidance">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <details>
                    <summary><strong>Buying guidance, not a purchase order.</strong> <span>Recommendations are guidance only.</span></summary>
                    <p>No supplier configuration is required. Reviewing these suggestions does not create purchase requests, place orders, or change stock. The owner buys outside RetailMind and may choose different products, stores, or quantities.</p>
                </details>
            </aside>
            <section class="queue-panel" id="queue-products" tabindex="-1" aria-labelledby="queue-title">
                <header class="queue-panel-heading"><div><h2 id="queue-title">Purchase queue</h2><span class="queue-count"><?= $replenishCount ?> to replenish</span></div><span class="muted">Priority comes first</span></header>
                    <?php if ($queueUnavailable): ?>
                        <p>Recommendations could not be loaded. Reload to try again.</p>
                    <?php elseif (empty($queue)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox empty-state-icon" aria-hidden="true"></i>
                            <p>No active products available for recommendations.</p>
                        </div>
                    <?php else: ?>
                        <div class="queue-filters no-print" role="search" aria-label="Filter recommendations">
                            <label class="queue-search">Search products<div><i class="bi bi-search" aria-hidden="true"></i><input id="queue-search" type="search" placeholder="Product name or SKU" aria-label="Product name or SKU"></div></label>
                            <label>Stock status<select id="queue-stock"><option value="">All stock</option><option>Out of stock</option><option>Low stock</option><option>In stock</option></select></label>
                            <label>Sort by<select id="queue-sort"><option value="priority">Stock priority first</option><option value="name">Product name</option><option value="stock">Lowest stock</option><option value="quantity">Suggested quantity</option></select></label>
                        </div>
                        <p class="queue-help muted" id="queue-help">Priority follows stock level: out of stock, low stock, then in stock. <span class="no-print">Scroll sideways on smaller screens for quantities and actions.</span></p>
                        <div class="queue-list table-wrap" tabindex="0" role="region" aria-label="Replenishment recommendations" aria-describedby="queue-help">
                            <table class="table" data-no-smart-table>
                                <thead><tr>
                                    <th scope="col">Priority</th><th scope="col">Product</th><th scope="col">On hand</th><th scope="col">Suggested</th><th scope="col">Why now</th><th scope="col"><?= $canReceive ? 'Next action' : 'Access' ?></th>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($queue as $item):
                                        $rowClass = str_replace(' ', '-', strtolower($item['stock_status']));
                                        $stockClass = ['Out of stock' => 'tag-danger', 'Low stock' => 'tag-warning', 'In stock' => 'tag-success'][$item['stock_status']];
                                    ?>
                                        <tr class="queue-item queue-<?= $rowClass ?>" data-stock="<?= htmlspecialchars($item['stock_status']) ?>" data-name="<?= htmlspecialchars($item['product_name']) ?>" data-sku="<?= htmlspecialchars($item['sku']) ?>" data-on-hand="<?= (int)$item['current_stock'] ?>" data-quantity="<?= (int)$item['recommended_quantity'] ?>">
                                            <td><span class="queue-priority <?= $stockClass ?>"><?= htmlspecialchars($item['stock_status']) ?></span></td>
                                            <td>
                                                <a class="queue-product" href="../products/product_detail.php?id=<?= (int)$item['product_id'] ?>"><?= htmlspecialchars($item['product_name']) ?></a>
                                                <small class="muted queue-sku"><?= htmlspecialchars($item['sku']) ?></small>
                                                <details class="queue-details"><summary>Forecast details</summary>
                                                    <strong><?= htmlspecialchars($item['basis']) ?></strong>
                                                    <p><?= htmlspecialchars($item['explanation']) ?></p>
                                                    <p><?= htmlspecialchars($item['forecast_status']) ?></p>
                                                    <?php if ($item['forecast_origin'] && strtotime((string)$item['forecast_origin']) < strtotime('-3 days')): ?>
                                                        <span class="tag-warning">Stale forecast: <?= htmlspecialchars((string)$item['forecast_origin']) ?></span>
                                                    <?php endif; ?>
                                                </details>
                                            </td>
                                            <td><strong class="queue-number <?= $item['current_stock'] <= 0 ? 'queue-out' : '' ?>"><?= (int)$item['current_stock'] ?></strong><small class="muted">units</small></td>
                                            <td><strong class="queue-number queue-suggested"><?= (int)$item['recommended_quantity'] ?></strong><small class="muted">units</small></td>
                                            <td class="queue-reason"><?= htmlspecialchars($item['stock_status'] === 'In stock' ? ($item['recommended_quantity'] > 0 ? 'Below target stock' : ($item['urgency_label'] === 'Covered' ? 'Stock covers demand' : 'No stock-rule purchase needed')) : $item['stock_status']) ?></td>
                                            <td>
                                                <?php if ($canReceive): ?>
                                                    <a class="btn <?= $item['recommended_quantity'] > 0 ? 'btn-primary' : 'btn-secondary' ?> queue-receive" aria-label="Add bought goods for <?= htmlspecialchars($item['product_name']) ?>" href="../report/stock_receiving.php?product_id=<?= (int)$item['product_id'] ?>">Add bought goods <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                                                <?php else: ?>
                                                    <span class="muted">View only</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p id="queue-no-results" class="empty-state" hidden>No products match these filters.</p>
                        <footer class="queue-panel-footer">
                            <span id="queue-results" role="status">Showing <?= count($queue) ?> of <?= count($queue) ?> products</span>
                            <label>Rows <select id="queue-page-size" aria-label="Rows per page"><option>10</option><option>25</option><option>50</option><option>100</option></select></label>
                            <span id="queue-page-status" aria-live="polite"></span>
                            <nav class="queue-pagination" id="queue-pagination" aria-label="Recommendation pages"></nav>
                            <span>Review quantities before you buy</span>
                        </footer>
                    <?php endif; ?>
            </section>
            <footer class="queue-page-footer muted"><span>Suggestions use base units. Set cases during receiving.</span><span>Stock updates when bought goods are received.</span></footer>
        </main>
    </div>
</body>
</html>
