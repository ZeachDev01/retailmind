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
        $error = OperatorAlert::message($stockError, 'Could not load replenishment recommendations. Reload the page or tell your Administrator.');
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - RetailMind</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <style>
        .queue-urgent { border: 1px solid var(--border); }
        .queue-watch { border: 1px solid var(--border); }
        .queue-unknown { border: 1px solid var(--border); }
        .queue-covered { border: 1px solid var(--border); }
        
        .urgency-badge.urgent { background: var(--theme-danger-surface, #fee2e2); color: var(--theme-danger-text, #991b1b); padding: 2px 6px; border-radius: 4px; font-weight: bold;}
        .urgency-badge.watch { background: var(--theme-warning-surface, #fef3c7); color: var(--theme-warning-text, #92400e); padding: 2px 6px; border-radius: 4px; font-weight: bold;}
        .urgency-badge.unknown { background: var(--theme-info-surface, #dbeafe); color: var(--theme-info-text, #1d4ed8); padding: 2px 6px; border-radius: 4px; font-weight: bold;}
        .urgency-badge.covered { background: var(--theme-success-surface, #dcfce7); color: var(--theme-success-text, #166534); padding: 2px 6px; border-radius: 4px; font-weight: bold;}
        
        .queue-grid {
            display: grid;
            grid-template-columns: minmax(200px, 1.5fr) 1fr 1fr 1fr 2fr;
            gap: 1rem;
            align-items: center;
        }
        .queue-mobile-label { display: none; }
        @media (max-width: 1100px) {
            .queue-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            .queue-grid-header { display: none; }
            .queue-mobile-label { display: block; }
            .queue-item { padding-bottom: 1rem; border-bottom: 1px solid var(--border); }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1><?= htmlspecialchars($pageTitle) ?></h1>
                <p class="page-subtitle">Review current stock and suggested buying quantities.</p>
            </div>
            <div class="u-flex gap-2 align-items-center">
                <?php if ($activeModelVersion): ?>
                    <span class="badge tag-light">Model: <?= htmlspecialchars($activeModelVersion) ?></span>
                <?php endif; ?>
                <?php if ($isDemo): ?>
                    <span class="badge tag-info">Demo Mode</span>
                <?php elseif ($isProduction): ?>
                    <span class="badge tag-light">Production forecast</span>
                <?php endif; ?>
                <button class="btn btn-secondary" type="button" onclick="window.print()">Print Queue</button>
            </div>
        </div>

            <?php if ($error): ?>
                <div class="alert tag-warning"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <section class="panel">
                <div class="panel-body">
                    <nav class="u-flex-wrap mb-3" aria-label="Stock replenishment">
                        <a class="btn btn-secondary" href="inventory_overview.php">Check stock</a>
                        <?php if (has_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY)): ?>
                            <a class="btn btn-primary" href="../report/stock_receiving.php">Record bought stock</a>
                        <?php endif; ?>
                    </nav>
                    <p>Non-binding buying guidance. No supplier configuration is required. Reviewing these suggestions does not create purchase requests, place orders, or change stock. The owner buys outside RetailMind and may choose different products, stores, or quantities.</p>
                    <details><summary>Purchasing history and legacy workflows</summary>
                        <a href="replenishment_requests.php">Replenishment requests</a> ·
                        <a href="../invoice/purchase_orders.php">Purchase orders</a> ·
                        <a href="legacy_reorder_planner.php">Supplier-based reorder planning</a>
                    </details>
                    
                    <?php if (empty($queue)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox empty-state-icon"></i>
                            <p>No active products available for recommendations.</p>
                        </div>
                    <?php else: ?>
                        <div class="queue-list mt-3">
                            <div class="queue-grid queue-grid-header fw-bold pb-2 border-bottom mb-2">
                                <div>Product</div>
                                <div>Current stock</div>
                                <div>Forecast exposure</div>
                                <div>Suggested buying quantity</div>
                                <div>Explanation</div>
                            </div>
                            
                            <?php foreach ($queue as $item): 
                                $rowClass = strtolower($item['urgency_label']);
                            ?>
                                <div class="queue-grid queue-item mb-3 p-2 queue-<?= $rowClass ?> bg-white shadow-sm rounded">
                                    <div>
                                        <strong><a href="../products/product_detail.php?id=<?= $item['product_id'] ?>"><?= htmlspecialchars($item['product_name']) ?></a></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($item['sku']) ?></small>
                                    </div>
                                    <div>
                                        <small class="queue-mobile-label">Current stock</small>
                                        <strong><?= (int)$item['current_stock'] ?> units</strong><br>
                                        <span><?= htmlspecialchars($item['stock_status']) ?></span>
                                    </div>
                                    <div>
                                        <small class="queue-mobile-label">Forecast exposure</small>
                                        <?php if ($item['stock_exposure'] !== null): ?>
                                            <span class="<?= $item['stock_exposure'] < 0 ? 'text-danger fw-bold' : '' ?>">
                                                <?= $item['stock_exposure'] ?> units
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Unknown</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <small class="queue-mobile-label">Suggested buying quantity</small>
                                        <?php if ($item['recommended_quantity'] > 0): ?>
                                            <strong><?= $item['recommended_quantity'] ?> units</strong><br>
                                        <?php else: ?>
                                            <span class="text-muted">0 units suggested</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <strong><?= htmlspecialchars($item['basis']) ?></strong><br>
                                        <small><?= htmlspecialchars($item['explanation']) ?></small>
                                        <div><small><?= htmlspecialchars($item['forecast_status']) ?></small></div>
                                        <?php if ($item['forecast_origin'] && strtotime((string)$item['forecast_origin']) < strtotime('-3 days')): ?>
                                            <div class="text-warning"><small><i class="bi bi-exclamation-triangle"></i> Stale forecast.</small></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
