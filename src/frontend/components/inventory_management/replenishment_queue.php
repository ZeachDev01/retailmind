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
$demoSource = '';
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
        $demoSource = $jobMeta['source_label'] ?? '';
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
    <script src="<?= htmlspecialchars(app_url('assets/js/jspdf.umd.min.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars(app_url('assets/js/replenishment-pdf.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/replenishment-pdf.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars(app_url('assets/js/replenishment.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/replenishment.js')) ?>" defer></script>
</head>
<body class="replenishment-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
        </div>

            <?php if ($error): ?>
                <div class="alert tag-warning" role="status">
                    <span><?= htmlspecialchars($error) ?></span>
                    <a class="btn btn-secondary no-print" href="replenishment_queue.php">Reload</a>
                </div>
            <?php endif; ?>

            <script>
                window.RetailMindUI?.toast(
                    'Review quantities before buying. Recommendations do not place orders or change stock.',
                    'info',
                    'Buying guidance, not a purchase order',
                    8000
                );
            </script>
            <?php if ($isDemo): ?>
                <p class="muted">Demo: <?= htmlspecialchars($demoSource) ?> historical sales. Current stock and planning settings are demonstration inputs, not source observations. No Store accuracy is established.</p>
            <?php endif; ?>
            <?php if ($pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='demo_replay_result'")->fetchColumn()): ?>
                <p class="tag-warning">The train-only replay model is not approved. This page retains normal stock-rule guidance. <a href="../report/forecast_replay.php">Compare test predictions and view experimental buying quantities</a>.</p>
            <?php endif; ?>
            <section class="queue-panel" id="queue-products" tabindex="-1">
                    <?php if ($queueUnavailable): ?>
                        <p>Recommendations could not be loaded. Reload to try again.</p>
                    <?php elseif (empty($queue)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox empty-state-icon" aria-hidden="true"></i>
                            <p>No active products available for recommendations.</p>
                        </div>
                    <?php else: ?>
                        <div class="queue-filters no-print" role="search" aria-label="Filter recommendations">
                            <label class="queue-search">Search products<div><i class="bi bi-search" aria-hidden="true"></i><input id="queue-search" type="search" placeholder="Product name or SKU"></div></label>
                            <label>Stock status<select id="queue-stock"><option value="" selected>All statuses</option><option value="needs_replenishment">Needs replenishment</option><option>Out of stock</option><option>Low stock</option><option>In stock</option></select></label>
                            <label>Sort by<select id="queue-sort"><option value="priority">Stock priority</option><option value="name">Product name</option><option value="stock">Lowest stock</option><option value="quantity">Suggested quantity</option></select></label>
                            <div class="queue-actions" id="queue-actions">
                                <button class="btn btn-secondary" id="queue-actions-trigger" type="button" aria-expanded="false" aria-controls="queue-actions-menu" aria-haspopup="menu">Actions</button>
                                <div class="queue-actions-menu" id="queue-actions-menu" role="menu" hidden>
                                    <a class="queue-actions-item queue-recalculate" id="queue-recalculate" role="menuitem" href="replenishment_queue.php?recalculate=1"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Recalculate</a>
                                    <?php if ($queue): ?>
                                        <button class="queue-actions-item queue-print" id="queue-download-pdf" type="button" role="menuitem"><i class="bi bi-download" aria-hidden="true"></i> Download PDF</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="queue-list table-wrap" tabindex="0" role="region" aria-label="Replenishment recommendations">
                            <table class="table" data-no-smart-table>
                                <thead><tr>
                                    <th scope="col">Priority</th><th scope="col">Product</th><th scope="col">On hand</th><th scope="col">Suggested</th>
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
                                            </td>
                                            <td><strong class="queue-number <?= $item['current_stock'] <= 0 ? 'queue-out' : '' ?>"><?= (int)$item['current_stock'] ?></strong> <small class="muted">units</small></td>
                                            <td><strong class="queue-number queue-suggested"><?= (int)$item['recommended_quantity'] ?></strong> <small class="muted">units</small></td>
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
                        </footer>
                    <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
