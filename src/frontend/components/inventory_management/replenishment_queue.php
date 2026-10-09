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
use App\Authorization\RoleCapabilityPolicy;

Session::start();
require_role(['admin', 'super_admin', 'inventory_manager']);

$pageTitle = 'Replenishment Action Queue';
$error = '';
$success = '';

try {
    $forecastService = new DemandForecastService($pdo);
    $jobId = $forecastService->getLatestSuccessfulJobId();
    
    $queue = [];
    if ($jobId) {
        $queueService = new ReplenishmentQueueService($pdo);
        $queue = $queueService->getQueue($jobId);
    }
} catch (Throwable $e) {
    $error = OperatorAlert::message($e, 'Could not load the replenishment queue.');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - RetailMind</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/print.css" media="print">
    <style>
        .queue-urgent { border-left: 4px solid var(--danger); }
        .queue-watch { border-left: 4px solid var(--warning); }
        .queue-covered { border-left: 4px solid var(--success); }
        
        .urgency-badge.urgent { background: var(--danger); color: white; padding: 2px 6px; border-radius: 4px; font-weight: bold;}
        .urgency-badge.watch { background: var(--warning); color: white; padding: 2px 6px; border-radius: 4px; font-weight: bold;}
        .urgency-badge.covered { background: var(--success); color: white; padding: 2px 6px; border-radius: 4px; font-weight: bold;}
        
        .queue-grid {
            display: grid;
            grid-template-columns: minmax(200px, 1.5fr) 1fr 1fr 1fr 2fr;
            gap: 1rem;
            align-items: center;
        }
        @media (max-width: 768px) {
            .queue-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            .queue-grid-header { display: none; }
            .queue-item { padding-bottom: 1rem; border-bottom: 1px solid var(--line); }
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
                <p class="page-subtitle">Review products ordered by stock exposure.</p>
            </div>
            <button class="btn btn-secondary" type="button" onclick="window.print()">Print Queue</button>
        </div>

            <?php if ($error): ?>
                <div class="alert tag-warning"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <section class="panel">
                <div class="panel-body">
                    <p>Review products ordered by stock exposure. Recommendations account for scheduled inbound quantities and available minimum-order constraints. Note: Reviewing the queue does not create a purchase order or change stock.</p>
                    
                    <?php if (empty($queue)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox empty-state-icon"></i>
                            <p>No replenishment actions required or no forecast available.</p>
                        </div>
                    <?php else: ?>
                        <div class="queue-list mt-3">
                            <div class="queue-grid queue-grid-header fw-bold pb-2 border-bottom mb-2">
                                <div>Product</div>
                                <div>Status</div>
                                <div>Exposure</div>
                                <div>Recommendation</div>
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
                                        <span class="urgency-badge <?= $rowClass ?>"><?= htmlspecialchars($item['urgency_label']) ?></span>
                                    </div>
                                    <div>
                                        <span class="<?= $item['stock_exposure'] < 0 ? 'text-danger fw-bold' : '' ?>">
                                            <?= $item['stock_exposure'] ?> units
                                        </span>
                                    </div>
                                    <div>
                                        <?php if ($item['recommended_quantity'] > 0): ?>
                                            <strong><?= $item['recommended_quantity'] ?> units</strong>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <small><?= htmlspecialchars($item['explanation']) ?></small>
                                        <?php if ($item['readiness_level'] !== 'High'): ?>
                                            <div class="text-warning"><small><i class="bi bi-exclamation-triangle"></i> Low readiness forecast.</small></div>
                                        <?php endif; ?>
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
    <script src="../../assets/js/ui.js"></script>
</body>
</html>
