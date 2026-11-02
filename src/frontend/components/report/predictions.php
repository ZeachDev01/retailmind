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
        http_response_code(403);
        $message = 'You do not have permission to generate forecasts.';
        $messageClass = 'tag-warning';
    }
}

$service = new DemandForecastService($pdo);
$searchQuery = trim($_GET['search'] ?? '');
$coverage = $service->getCoverage(null, null, $searchQuery);

$effectiveJobId = $coverage['effective_job_id'];
$isGenerating = $coverage['is_updating'];
$latestJobStatus = $coverage['latest_attempt_status'];

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
    <link rel="stylesheet" href="<?= htmlspecialchars(function_exists('theme_url') ? theme_url('assets/css/style.css') : app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
    <style>
        .forecast-header-card {
            margin-bottom: 1.5rem;
            padding: 1.25rem;
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 8px;
        }
        .demo-badge {
            display: inline-block;
            background: var(--theme-warning-surface, #fef3c7);
            color: var(--theme-warning-text, #92400e);
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            margin-left: 0.5rem;
            text-transform: uppercase;
        }
        .readiness-tag-High { background: var(--theme-success-surface, #dcfce7); color: var(--theme-success-text, #15803d); }
        .readiness-tag-Medium { background: var(--theme-warning-surface, #fef3c7); color: var(--theme-warning-text, #b45309); }
        .readiness-tag-Low { background: var(--theme-danger-surface, #fee2e2); color: var(--theme-danger-text, #b91c1c); }
        .readiness-tag-Insufficient { background: var(--theme-surface-soft, #f1f5f9); color: var(--muted, #64748b); }
        .model-review { display: block; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Demand Forecast <?php if ($isDemoMode): ?><span class="demo-badge">Demo Mode (<?= htmlspecialchars($demoSource) ?>)</span><?php endif; ?></h1>
                <p class="page-subtitle">Expected demand, inventory exposure, and model readiness.</p>
            </div>
            
            <div class="page-heading-actions">
                <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/inventory_management/replenishment_queue.php')) ?>">Replenishment Queue</a>
                <?php if (has_capability(RoleCapabilityPolicy::GENERATE_FORECASTS)): ?>
                    <form method="post" action="" style="display:inline-block; margin:0;">
                        <?= csrf_field() ?>
                        <button type="submit" name="action" value="generate" class="btn btn-primary" <?= $isGenerating ? 'disabled' : '' ?>>
                            <?= $isGenerating ? 'Generating...' : 'Generate Now' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($isDemoMode): ?>
            <div class="alert tag-info" role="status" style="margin-bottom: 1.25rem;">
                <strong>Demo dataset active (<?= htmlspecialchars($demoSource) ?>):</strong> Forecasts reflect historical benchmark data.
            </div>
        <?php endif; ?>

        <?php if (has_capability(RoleCapabilityPolicy::GENERATE_FORECASTS)): ?>
            <?php include __DIR__ . '/model_evaluation_summary.php'; ?>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>" role="alert">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="alert <?= $coverage['store_status'] === 'full' ? 'tag-success' : ($coverage['forecast_backed_count'] > 0 ? 'tag-info' : 'tag-warning') ?>" role="status" style="margin-bottom: 1.25rem;">
            <strong>Coverage:</strong> <?= htmlspecialchars($coverage['coverage_summary_text']) ?>
            <?php if ($coverage['stock_rule_count'] > 0): ?>
                <a href="<?= htmlspecialchars(app_url('components/report/forecast_exceptions.php')) ?>" class="alert-link" style="text-decoration:underline; font-weight:600; margin-left:0.5rem;">Inspect exclusions &amp; next actions</a>
            <?php endif; ?>
        </div>

        <div class="card-grid">
            <div class="stat-card">
                <div class="label">Forecast Coverage</div>
                <div class="value"><?= (int)$coverage['forecast_backed_count'] ?> of <?= (int)$coverage['scope_count'] ?></div>
                <div class="hint"><?= (int)$coverage['stock_rule_count'] ?> use stock rules<?= $coverage['filtered']['is_filtered'] ? ' (filtered: ' . (int)$coverage['filtered']['forecast_backed_count'] . ' of ' . (int)$coverage['filtered']['count'] . ')' : '' ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Generation Status</div>
                <div class="value"><span class="status-badge <?= $statusBadgeClass ?>"><?= $statusBadgeLabel ?></span></div>
                <div class="hint">Job #<?= htmlspecialchars((string)($effectiveJobId ?? 'None')) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Forecast Origin</div>
                <div class="value" style="font-size: 1.25rem;"><?= htmlspecialchars($forecastOrigin ?? 'Unavailable') ?></div>
                <div class="hint"><?= htmlspecialchars($originAgeText) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Model Version</div>
                <div class="value" style="font-size: 1.1rem; font-family: ui-monospace, monospace;"><?= htmlspecialchars($displayedForecastModel ?? 'Unavailable') ?></div>
                <div class="hint"><?= $coverage['is_observation_mode'] ? 'Observation mode' : ($coverage['is_recommendations_suspended'] ? 'Recommendations suspended' : 'Approved algorithm') ?></div>
            </div>
        </div>

        <div class="card">
            <div class="table-toolbar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1rem;">
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
                                <th scope="col">SKU</th>
                                <th scope="col">Current Stock</th>
                                <th scope="col">7-Day Demand</th>
                                <th scope="col">14-Day Demand</th>
                                <th scope="col">28-Day Demand</th>
                                <th scope="col">Buying Guidance</th>
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
                                    </td>
                                    <td><code><?= htmlspecialchars($sku) ?></code></td>
                                    <td><?= htmlspecialchars((string)$stock) ?></td>
                                    <td><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_7_day'] ?? 0)) : '<span class="muted">Unavailable</span>' ?></td>
                                    <td><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_14_day'] ?? 0)) : '<span class="muted">Unavailable</span>' ?></td>
                                    <td><?= $isForecastBacked ? htmlspecialchars((string)($f['forecast_28_day'] ?? 0)) : '<span class="muted">Unavailable</span>' ?></td>
                                    <td>
                                        <?php if ($isForecastBacked): ?>
                                            <span class="status-badge readiness-tag-<?= htmlspecialchars($readiness) ?>">
                                                <?= htmlspecialchars($readiness) ?>
                                            </span>
                                            <small class="muted" style="display:block;"><?= htmlspecialchars($f['basis']) ?></small>
                                        <?php else: ?>
                                            <span class="badge tag-secondary">Stock-rule</span>
                                            <small class="muted" style="display:block;"><?= htmlspecialchars($f['exclusion_reason'] ?? 'Stock rules') ?></small>
                                        <?php endif; ?>
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
        </div>
    </main>
</div>
<?php if ($isGenerating): ?><script>setTimeout(() => window.location.replace(window.location.href), 5000);</script><?php endif; ?>
</body>
</html>
