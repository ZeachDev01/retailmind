<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\DemandForecastService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\ReplenishmentQueueService;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);

$service = new DemandForecastService($pdo, new CandidateEvaluationService($pdo), new ReplenishmentQueueService($pdo));
$searchQuery = trim($_GET['search'] ?? '');
$reasonFilter = trim($_GET['reason'] ?? '');

$coverage = $service->getCoverage(null, null, $searchQuery, $reasonFilter);
$exceptions = $coverage['excluded_products'];
$availableReasons = array_keys($coverage['exclusion_reasons']);
sort($availableReasons);

$filteredExceptions = $coverage['filtered']['is_filtered']
    ? array_values(array_filter($coverage['filtered']['products'], static fn($p) => empty($p['is_forecast_backed'])))
    : $exceptions;

$decisionStatement = $pdo->prepare("SELECT fd.*,p.sku,p.product_name,u.display_name decided_by_name
    FROM forecast_decisions fd JOIN products p ON p.product_id=fd.product_id LEFT JOIN users u ON u.user_id=fd.decided_by
    ORDER BY fd.decided_at DESC LIMIT 30");
$decisionStatement->execute();
$decisions = $decisionStatement->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Forecast Exceptions</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
    <?php require_once __DIR__ . '/../report/forecast_ui.php'; ?>
</head>
<body class="forecast-workspace">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Forecast Exceptions</h1>
                <p class="page-subtitle">Products using stock rules instead of forecasts.</p>
            </div>
        </header>
        <?php forecast_workflow('forecast'); ?>

        <section class="forecast-overview" aria-label="Forecast exception summary">
            <div><small>Using stock rules</small><strong><?= count($exceptions) ?> <?= count($exceptions) === 1 ? 'product' : 'products' ?></strong></div>
            <div class="forecast-coverage"><small>Forecast coverage</small><strong><?= (int)$coverage['forecast_backed_count'] ?> / <?= (int)$coverage['scope_count'] ?></strong> <?php forecast_help('exception-coverage-help', $coverage['coverage_summary_text'], 'About forecast coverage'); ?><meter min="0" max="<?= max(1, (int)$coverage['scope_count']) ?>" value="<?= (int)$coverage['forecast_backed_count'] ?>" aria-label="Products with usable forecasts"><?= (int)$coverage['forecast_backed_count'] ?> / <?= (int)$coverage['scope_count'] ?></meter></div>
            <div><small>Store status</small><strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $coverage['store_status']))) ?></strong></div>
        </section>

        <section class="dashboard-section">
            <div class="section-header">
                <h2>Products to review (<?= count($filteredExceptions) ?><?= $coverage['filtered']['is_filtered'] ? ' of ' . count($exceptions) : '' ?>)</h2>
            </div>

            <form method="get" action="" class="search-form exception-filters no-print" role="search" aria-label="Filter exception queue" style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:flex-end; margin-bottom:1.25rem;">
                <div>
                    <label for="filter-search" class="u-text-muted" style="display:block; font-size:0.85rem; margin-bottom:0.25rem;">Search</label>
                    <input type="search" id="filter-search" name="search" autocomplete="off" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Product name or SKU…" class="input-text" style="min-width:200px;">
                </div>
                <div>
                    <label for="filter-reason" class="u-text-muted" style="display:block; font-size:0.85rem; margin-bottom:0.25rem;">Reason</label>
                    <select id="filter-reason" name="reason" class="input-select" style="min-width:180px;">
                        <option value="">All reasons</option>
                        <?php foreach ($availableReasons as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>" <?= $reasonFilter === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; gap:0.5rem;">
                    <button type="submit" class="btn btn-secondary">Filter</button>
                    <?php if ($searchQuery !== '' || $reasonFilter !== ''): ?>
                        <a href="forecast_exceptions.php" class="btn btn-quiet">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="table-wrap" role="region" aria-label="Forecast exceptions queue" tabindex="0">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">On hand</th>
                            <th scope="col">Stock-rule suggestion</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredExceptions as $row):
                            $reason = $row['exclusion_reason'] ?? 'Forecast unavailable';
                            $nextStep = $row['exclusion_action'] ?? 'Replenishment continues on stock rules';
                        ?>
                            <tr>
                                <td>
                                    <strong><a href="<?= htmlspecialchars(app_url('components/inventory_management/product_investigation.php?product_id=' . (int)$row['product_id'])) ?>"><?= htmlspecialchars($row['product_name']) ?></a></strong><br>
                                    <span class="u-text-muted"><?= htmlspecialchars($row['sku']) ?></span>
                                </td>
                                <td><strong><?= (int)$row['current_stock'] ?></strong> units</td>
                                <td><strong><?= (int)$row['stock_rule_quantity'] ?></strong> units</td>
                                <td>
                                    <div class="readiness-status-line">
                                        <span><?= htmlspecialchars(explode(':', $reason, 2)[0]) ?></span>
                                        <?php forecast_help('exception-' . (int)$row['product_id'], $reason . '. Next step: ' . $nextStep, 'Exception details for ' . $row['product_name']); ?>
                                    </div>
                                </td>
                                <td>
                                    <a class="btn btn-secondary btn-small" href="<?= htmlspecialchars(app_url('components/report/predictions.php?search=' . urlencode($row['sku']))) ?>" aria-label="View forecast for <?= htmlspecialchars($row['product_name']) ?>">View Forecast</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$filteredExceptions): ?>
                            <tr><td colspan="5" class="u-text-muted u-text-center"><?= ($searchQuery !== '' || $reasonFilter !== '') ? 'No matches. Clear the filters.' : ($coverage['scope_count'] === 0 ? 'No active products to check.' : 'All active products have usable forecasts.') ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <details class="forecast-details"><summary>Recent decisions (<?= count($decisions) ?>)</summary>
            <div class="table-wrap" role="region" aria-label="Recent forecast decisions" tabindex="0">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Product</th>
                            <th scope="col">Decision</th>
                            <th scope="col">Suggested</th>
                            <th scope="col">Final</th>
                            <th scope="col">Reason</th>
                            <th scope="col">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($decisions as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['decided_at']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($row['product_name']) ?></strong><br>
                                    <span class="u-text-muted"><?= htmlspecialchars($row['sku']) ?></span>
                                </td>
                                <td><?= htmlspecialchars(ucfirst($row['decision'])) ?></td>
                                <td><?= (int)$row['original_suggested_qty'] ?></td>
                                <td><?= (int)$row['final_quantity'] ?></td>
                                <td><?= htmlspecialchars($row['override_reason'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($row['decided_by_name'] ?? 'Unknown') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$decisions): ?>
                            <tr><td colspan="7" class="u-text-muted u-text-center">No decisions recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </main>
</div>
</body>
</html>
