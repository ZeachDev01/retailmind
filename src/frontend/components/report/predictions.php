<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\DemandForecastService;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);

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
        $message = 'You do not have permission to generate forecasts.';
        $messageClass = 'tag-warning';
    }
}

$service = new DemandForecastService($pdo);
$latestJobId = $service->getLatestSuccessfulJobId();
$forecasts = $latestJobId ? $service->getForecastAggregates($latestJobId) : [];

// Check if there is currently a running or pending job
$stmt = $pdo->query("SELECT status, operator_message, queued_at, completed_at FROM ml_jobs WHERE trigger_type = 'forecast_generation' ORDER BY job_id DESC LIMIT 1");
$latestJob = $stmt->fetch(PDO::FETCH_ASSOC);
$latestJobStatus = $latestJob['status'] ?? 'none';
$isGenerating = in_array($latestJobStatus, ['queued', 'running', 'processing'], true);

// Enrich forecasts with product details & stock
$productMap = [];
if (!empty($forecasts)) {
    $pIds = array_column($forecasts, 'product_id');
    $inClause = implode(',', array_map('intval', $pIds));
    $pStmt = $pdo->query("
        SELECT p.product_id, p.product_name, p.sku, COALESCE(i.quantity, 0) as current_stock
        FROM products p
        LEFT JOIN inventory i ON p.product_id = i.product_id
        WHERE p.product_id IN ($inClause)
    ");
    while ($row = $pStmt->fetch(PDO::FETCH_ASSOC)) {
        $productMap[(int)$row['product_id']] = $row;
    }
}

// Search filter
$searchQuery = trim($_GET['search'] ?? '');
if ($searchQuery !== '') {
    $filtered = [];
    foreach ($forecasts as $f) {
        $meta = $productMap[$f['product_id']] ?? [];
        $name = $meta['product_name'] ?? '';
        $sku = $meta['sku'] ?? '';
        $idStr = (string)$f['product_id'];

        if (stripos($name, $searchQuery) !== false || stripos($sku, $searchQuery) !== false || $idStr === $searchQuery) {
            $filtered[] = $f;
        }
    }
    $forecasts = $filtered;
}

// Check if demo metadata exists
$isDemoMode = false;
$metaStmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'demo_import_metadata'");
if ($metaStmt && $metaStmt->fetchColumn()) {
    $isDemoMode = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Demand Forecast</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
    <style>
        .forecast-header-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1.25rem;
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 8px;
        }
        .demo-badge {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            margin-left: 0.5rem;
            text-transform: uppercase;
        }
        .readiness-tag-High { background: #dcfce7; color: #15803d; }
        .readiness-tag-Medium { background: #fef3c7; color: #b45309; }
        .readiness-tag-Low { background: #fee2e2; color: #b91c1c; }
        .readiness-tag-Insufficient { background: #f1f5f9; color: #64748b; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1>Demand Forecast <?php if ($isDemoMode): ?><span class="demo-badge">Demo Mode (S001)</span><?php endif; ?></h1>
                <p class="page-subtitle">Expected demand, inventory exposure, and model readiness across the Store.</p>
            </div>
            
            <?php if (has_capability(RoleCapabilityPolicy::GENERATE_FORECASTS)): ?>
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <button type="submit" name="action" value="generate" class="btn btn-primary" <?= $isGenerating ? 'disabled' : '' ?>>
                        <?= $isGenerating ? 'Generating...' : 'Generate Now' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($latestJobStatus === 'failed'): ?>
            <div class="alert tag-warning">
                <strong>Notice:</strong> The latest forecast generation attempt did not complete. Showing the last verified forecast results below.
            </div>
        <?php endif; ?>

        <div class="forecast-header-card">
            <div>
                <strong>Active Run:</strong> Job #<?= htmlspecialchars((string)($latestJobId ?? 'None')) ?> 
                <?php if (!empty($forecasts[0]['forecast_origin'])): ?>
                    &middot; Origin: <?= htmlspecialchars($forecasts[0]['forecast_origin']) ?>
                <?php endif; ?>
                <?php if (!empty($forecasts[0]['model_version'])): ?>
                    &middot; Model: <code><?= htmlspecialchars($forecasts[0]['model_version']) ?></code>
                <?php endif; ?>
            </div>
            <form method="get" action="" class="search-form" style="display:flex; gap:0.5rem;">
                <input type="text" name="search" placeholder="Search by Name, SKU, or ID" value="<?= htmlspecialchars($searchQuery) ?>" class="input-text">
                <button type="submit" class="btn btn-secondary">Search</button>
                <?php if ($searchQuery !== ''): ?>
                    <a href="predictions.php" class="btn btn-quiet">Clear</a>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if (empty($forecasts)): ?>
            <div class="empty-state">
                <i class="bi bi-graph-up-arrow empty-icon"></i>
                <h2>No Forecasts Available</h2>
                <p><?= $searchQuery !== '' ? 'No matching products found in current forecast run.' : 'Demand forecasts have not been generated yet or the last run failed.' ?></p>
            </div>
        <?php else: ?>
            <div class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Current Stock</th>
                            <th>7-Day Demand</th>
                            <th>14-Day Demand</th>
                            <th>28-Day Demand</th>
                            <th>Readiness</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($forecasts as $f): 
                            $meta = $productMap[$f['product_id']] ?? [];
                            $name = $meta['product_name'] ?? "Product #{$f['product_id']}";
                            $sku = $meta['sku'] ?? "PRD-{$f['product_id']}";
                            $stock = (int)($meta['current_stock'] ?? 0);
                            $readiness = $f['readiness_level'] ?? 'Insufficient';
                        ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($name) ?></strong>
                                </td>
                                <td><code><?= htmlspecialchars($sku) ?></code></td>
                                <td><?= htmlspecialchars((string)$stock) ?></td>
                                <td><?= htmlspecialchars((string)$f['forecast_7_day']) ?></td>
                                <td><?= htmlspecialchars((string)$f['forecast_14_day']) ?></td>
                                <td><?= htmlspecialchars((string)$f['forecast_28_day']) ?></td>
                                <td>
                                    <span class="status-badge readiness-tag-<?= htmlspecialchars($readiness) ?>">
                                        <?= htmlspecialchars($readiness) ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars(app_url('components/inventory_management/product_investigation.php?product_id=' . $f['product_id'])) ?>">
                                        Investigate
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
