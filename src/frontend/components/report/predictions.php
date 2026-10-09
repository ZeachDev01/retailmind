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
$stmt = $pdo->query("SELECT status FROM ml_jobs WHERE trigger_type = 'forecast_generation' ORDER BY job_id DESC LIMIT 1");
$latestJobStatus = $stmt->fetchColumn();
$isGenerating = in_array($latestJobStatus, ['queued', 'running', 'processing'], true);

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
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1>Demand Forecast</h1>
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
        
        <div class="card">
            <form method="get" action="<?= htmlspecialchars(app_url('components/inventory_management/product_investigation.php')) ?>" class="search-form">
                <input type="number" name="product_id" placeholder="Search Product ID" required class="input-text">
                <button type="submit" class="btn btn-secondary">Investigate</button>
            </form>
        </div>
        
        <?php if (empty($forecasts)): ?>
            <div class="empty-state">
                <i class="bi bi-graph-up-arrow empty-icon"></i>
                <h2>No Forecasts Available</h2>
                <p>Demand forecasts have not been generated yet or the last run failed.</p>
            </div>
        <?php else: ?>
            <div class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product ID</th>
                            <th>7 Days</th>
                            <th>14 Days</th>
                            <th>28 Days</th>
                            <th>Readiness</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($forecasts as $f): ?>
                            <tr>
                                <td><a href="<?= htmlspecialchars(app_url('components/inventory_management/product_investigation.php?product_id=' . $f['product_id'])) ?>"><?= htmlspecialchars((string)$f['product_id']) ?></a></td>
                                <td><?= htmlspecialchars((string)$f['forecast_7_day']) ?></td>
                                <td><?= htmlspecialchars((string)$f['forecast_14_day']) ?></td>
                                <td><?= htmlspecialchars((string)$f['forecast_28_day']) ?></td>
                                <td><?= htmlspecialchars((string)$f['readiness_level']) ?></td>
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
