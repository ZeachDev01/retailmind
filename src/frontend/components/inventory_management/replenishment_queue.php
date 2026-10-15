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
        .replenishment-page .queue-list table { table-layout: fixed; min-width: 760px; }
        .replenishment-page .queue-list th:first-child { width: 46%; }
        .replenishment-page .queue-list th:last-child { width: 24%; }
        .replenishment-page .queue-list th { white-space: normal; overflow-wrap: anywhere; }
        .replenishment-page .queue-list td { white-space: normal; overflow-wrap: anywhere; vertical-align: top; font-size: 1rem; }
        .replenishment-page .queue-list small { font-size: .875rem; }
        .replenishment-page .queue-list a:not(.btn) { display: inline-flex; align-items: center; min-height: 44px; color: var(--primary); text-decoration: underline; text-underline-offset: .2em; }
        .replenishment-page .queue-guidance { max-width: 75ch; }
        .replenishment-page .queue-guidance summary { min-height: 44px; padding-block: .6rem; cursor: pointer; }
        .replenishment-page .queue-meta { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
        .replenishment-page .topbar .queue-meta { flex-direction: row; }
        .replenishment-page .topbar h1 { overflow-wrap: anywhere; }
        .replenishment-page .topbar .queue-meta > span { width: auto; max-width: 100%; overflow-wrap: anywhere; }
        .replenishment-page .alert { flex-wrap: wrap; }
        .replenishment-page .auto-table-toolbar input,
        .replenishment-page .table-pagination select { min-height: 44px; font-size: 1rem; }
        @media print {
            .replenishment-page .queue-list { overflow: visible; }
            .replenishment-page .queue-list table { display: table; table-layout: auto; min-width: 0; }
            .replenishment-page .queue-list .queue-item[hidden] { display: table-row !important; }
            .replenishment-page .queue-list tr { break-inside: avoid; }
            .replenishment-page .queue-list th { position: static; }
            .replenishment-page .queue-list a { text-decoration: none; min-height: 0; }
            .replenishment-page .queue-guidance summary { min-height: 0; padding: 0; outline: none; list-style: none; }
            .replenishment-page .queue-list th:last-child,
            .replenishment-page .queue-list td:last-child { display: none; }
        }
    </style>
</head>
<body class="replenishment-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1><?= htmlspecialchars($pageTitle) ?></h1>
                <p class="page-subtitle">Review current stock and suggested buying quantities.</p>
            </div>
            <div class="queue-meta">
                <?php if ($activeModelVersion): ?>
                    <span class="decision-pill">Model: <?= htmlspecialchars($activeModelVersion) ?></span>
                <?php endif; ?>
                <?php if ($isDemo): ?>
                    <span class="decision-pill info">Demo Mode</span>
                <?php elseif ($isProduction): ?>
                    <span class="decision-pill">Production forecast</span>
                <?php endif; ?>
                <?php if ($queue): ?>
                    <button class="btn btn-secondary no-print" type="button" onclick="window.print()">Print all recommendations</button>
                <?php endif; ?>
            </div>
        </div>

            <?php if ($error): ?>
                <div class="alert tag-warning" role="status">
                    <span><?= htmlspecialchars($error) ?></span>
                    <a class="btn btn-secondary no-print" href="replenishment_queue.php">Reload</a>
                </div>
            <?php endif; ?>

            <section class="dashboard-section" aria-label="Buying guidance">
                    <details class="queue-guidance">
                        <summary>Non-binding buying guidance</summary>
                        <p>No supplier configuration is required. Reviewing these suggestions does not create purchase requests, place orders, or change stock. The owner buys outside RetailMind and may choose different products, stores, or quantities.</p>
                    </details>
                    
                    <?php if ($queueUnavailable): ?>
                        <p>Recommendations could not be loaded. Reload to try again.</p>
                    <?php elseif (empty($queue)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox empty-state-icon" aria-hidden="true"></i>
                            <p>No active products available for recommendations.</p>
                        </div>
                    <?php else: ?>
                        <p class="muted u-mt-1" id="queue-help">Unknown means forecast urgency is unavailable. <span class="no-print">On smaller screens, scroll sideways for quantities and actions.</span></p>
                        <div class="queue-list table-wrap u-mt-1" tabindex="0" role="region" aria-label="Replenishment recommendations" aria-describedby="queue-help">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th scope="col">Product / basis</th>
                                        <th scope="col" class="u-num-col">On hand</th>
                                        <th scope="col" class="u-num-col">Suggested units</th>
                                        <th scope="col"><?= $canReceive ? 'Action' : 'Access' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($queue as $item):
                                        $rowClass = strtolower($item['urgency_label']);
                                        $urgencyClass = ['Urgent' => 'tag-danger', 'Watch' => 'tag-warning', 'Unknown' => 'decision-pill info', 'Covered' => 'tag-success'][$item['urgency_label']];
                                        $stockClass = $item['current_stock'] <= 0 ? 'tag-danger' : ($item['stock_status'] === 'Low stock' ? 'tag-warning' : 'tag-success');
                                    ?>
                                        <tr class="queue-item queue-<?= $rowClass ?>">
                                            <td>
                                                <div class="queue-meta">
                                                    <strong><a href="../products/product_detail.php?id=<?= (int)$item['product_id'] ?>"><?= htmlspecialchars($item['product_name']) ?></a></strong>
                                                    <span class="<?= $stockClass ?>"><?= htmlspecialchars($item['stock_status']) ?></span>
                                                    <span class="<?= $urgencyClass ?>">Forecast urgency: <?= htmlspecialchars($item['urgency_label']) ?></span>
                                                </div>
                                                <small class="muted"><?= htmlspecialchars($item['sku']) ?></small>
                                                <div class="u-mt-05">
                                                    <strong><?= htmlspecialchars($item['basis']) ?></strong>
                                                    <div class="muted"><small><?= htmlspecialchars($item['explanation']) ?></small></div>
                                                    <div><small><?= htmlspecialchars($item['forecast_status']) ?></small></div>
                                                    <?php if ($item['forecast_origin'] && strtotime((string)$item['forecast_origin']) < strtotime('-3 days')): ?>
                                                        <div><span class="tag-warning">Stale forecast: <?= htmlspecialchars((string)$item['forecast_origin']) ?></span></div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="u-num-col">
                                                <strong><?= (int)$item['current_stock'] ?> units</strong>
                                            </td>
                                            <td class="u-num-col">
                                                <?php if ($item['recommended_quantity'] > 0): ?>
                                                    <strong><?= (int)$item['recommended_quantity'] ?> units</strong>
                                                <?php else: ?>
                                                    <span class="muted">0 units suggested</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($canReceive): ?>
                                                    <a class="btn btn-secondary" aria-label="Add bought goods for <?= htmlspecialchars($item['product_name']) ?>" href="../report/stock_receiving.php?product_id=<?= (int)$item['product_id'] ?>">Add bought goods</a>
                                                <?php else: ?>
                                                    <span class="muted">View only</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
