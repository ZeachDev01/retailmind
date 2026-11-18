<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\DemandForecastService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\ReplenishmentQueueService;
use App\Forecasting\StoreModelGovernanceService;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);

$service = new DemandForecastService($pdo, new CandidateEvaluationService($pdo), new ReplenishmentQueueService($pdo));
$role = current_role();
$isAdministrator = $role === 'admin';
$searchQuery = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$reasonFilter = is_string($_GET['reason'] ?? null) ? trim($_GET['reason']) : '';

$coverage = $service->getCoverage(null, null, $searchQuery, $reasonFilter, $role);
$exceptions = $coverage['excluded_products'];
$scopeCount = (int)$coverage['scope_count'];
$forecastCount = (int)$coverage['forecast_backed_count'];
$noApprovedModel = $scopeCount > 0 && empty($coverage['approved_model_version']);
$showAffectedProducts = $coverage['filtered']['is_filtered'] || ($_GET['products'] ?? null) === '1';
$issues = array_column($coverage['operational_issues'], null, 'category');
$canReviewModel = $isAdministrator && has_capability(RoleCapabilityPolicy::APPROVE_MODELS)
    && (new StoreModelGovernanceService($pdo))->getApprovalSummary()['can_approve'];
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
    <main class="main-content forecast-exceptions-page">
        <header class="page-heading">
            <div>
                <h1 class="exception-heading"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Forecast Exceptions</h1>
            </div>
        </header>
        <?php forecast_workflow('forecast'); ?>

        <section class="forecast-summary-strip exception-overview" aria-label="Forecast exception summary">
            <div>
                <span class="forecast-summary-label"><i class="bi bi-graph-up" aria-hidden="true"></i> Forecast coverage <?php forecast_help('exception-coverage-help', $coverage['coverage_summary_text'], 'About forecast coverage'); ?></span>
                <strong><?= $forecastCount ?> of <?= $scopeCount ?></strong>
                <?php if ($scopeCount > 0): ?>
                    <meter min="0" max="<?= $scopeCount ?>" value="<?= $forecastCount ?>" aria-label="Products with usable forecasts"><?= $forecastCount ?> / <?= $scopeCount ?></meter>
                <?php else: ?>
                    <small>No active products to check.</small>
                <?php endif; ?>
            </div>
            <div>
                <span class="forecast-summary-label"><i class="bi bi-box-seam" aria-hidden="true"></i> Using stock rules</span>
                <strong><?= count($exceptions) ?> <?= count($exceptions) === 1 ? 'product' : 'products' ?></strong>
            </div>
            <?php if (!$isAdministrator): ?>
                <div>
                    <span class="forecast-summary-label"><i class="bi bi-info-circle" aria-hidden="true"></i> Store status</span>
                    <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $coverage['store_status']))) ?></strong>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($isAdministrator): ?>
            <section class="dashboard-section forecast-exceptions-action" aria-labelledby="forecast-exceptions-action-title">
                <div>
                    <h2 id="forecast-exceptions-action-title" class="exception-heading"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Needs your action</h2>
                    <?php if ($canReviewModel): ?>
                        <p><strong>Model ready for review.</strong> Review the test results before approving.</p>
                    <?php else: ?>
                        <p>No model review is available right now.</p>
                    <?php endif; ?>
                    <?php if ($noApprovedModel): ?>
                        <p>No approved model yet. Buying suggestions use stock rules.</p>
                    <?php endif; ?>
                </div>
                <?php if ($canReviewModel): ?>
                    <a class="btn btn-primary" href="<?= htmlspecialchars(app_url('components/administrator/model_review.php')) ?>">Review model <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                <?php endif; ?>
            </section>
            <?php if (isset($issues['paused'])): ?>
                <p class="forecast-exceptions-coverage"><i class="bi bi-pause-circle" aria-hidden="true"></i> <span><strong>Forecast buying suggestions are paused.</strong> Buying suggestions use stock rules.</span></p>
            <?php endif; ?>
            <?php if (isset($issues['retry_scheduled'])): ?>
                <p class="forecast-exceptions-coverage"><i class="bi bi-clock" aria-hidden="true"></i> <span><strong>Forecast update will retry.</strong> Runs automatically. No action needed.</span></p>
            <?php elseif (isset($issues['exhausted_recovery'])): ?>
                <p class="forecast-exceptions-coverage"><i class="bi bi-life-preserver" aria-hidden="true"></i> <span><strong>Forecast update needs support.</strong> Contact the Super Administrator.</span></p>
            <?php endif; ?>
            <details class="forecast-details forecast-affected-products"<?= $showAffectedProducts ? ' open' : '' ?>>
                <summary>
                    <span class="exception-heading">
                        <i class="bi bi-list-check" aria-hidden="true"></i>
                        <span>
                            All affected products <span class="exception-count">(<?= count($exceptions) ?>)</span>
                            <?php if ($exceptions && !$noApprovedModel && !isset($issues['paused'])): ?>
                                <small>Using stock rules for buying suggestions</small>
                            <?php endif; ?>
                        </span>
                    </span>
                </summary>
        <?php else: ?>
        <section class="dashboard-section">
            <div class="section-header">
                <h2 class="exception-heading"><i class="bi bi-list-check" aria-hidden="true"></i> <span>Products to review <span class="exception-count">(<?= count($filteredExceptions) ?><?= $coverage['filtered']['is_filtered'] ? ' of ' . count($exceptions) : '' ?>)</span></span></h2>
            </div>
        <?php endif; ?>

            <form method="get" action="" class="exception-filters no-print" role="search" aria-label="Filter exception queue">
                <div>
                    <label for="filter-search">Search</label>
                    <input type="search" id="filter-search" name="search" autocomplete="off" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Product name or SKU…" class="input-text">
                </div>
                <div>
                    <label for="filter-reason">Reason</label>
                    <select id="filter-reason" name="reason" class="input-select">
                        <option value="">All reasons</option>
                        <?php foreach ($availableReasons as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>" <?= $reasonFilter === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="exception-filter-actions">
                    <button type="submit" class="btn btn-secondary"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
                    <?php if ($searchQuery !== '' || $reasonFilter !== ''): ?>
                        <a href="forecast_exceptions.php<?= $isAdministrator ? '?products=1' : '' ?>" class="btn btn-quiet">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($isAdministrator && $coverage['filtered']['is_filtered'] && $filteredExceptions): ?>
                <p class="u-text-muted"><?= count($filteredExceptions) ?> of <?= count($exceptions) ?> affected products shown</p>
            <?php endif; ?>
            <div class="table-wrap" role="region" aria-label="Forecast exceptions queue" tabindex="0">
                <table class="data-table forecast-stack-table" data-no-smart-table data-no-ghost-rows>
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
                            $productUrl = app_url('components/inventory_management/product_investigation.php?product_id=' . (int)$row['product_id']);
                            $storeWideReason = $isAdministrator && $noApprovedModel && $reason === 'Unapproved model: no approved Store model';
                        ?>
                            <tr>
                                <td data-label="Product">
                                    <strong><?php if ($isAdministrator): ?><?= htmlspecialchars($row['product_name']) ?><?php else: ?><a href="<?= htmlspecialchars($productUrl) ?>"><?= htmlspecialchars($row['product_name']) ?></a><?php endif; ?></strong>
                                    <small class="forecast-sku"><?= htmlspecialchars($row['sku']) ?></small>
                                </td>
                                <td data-label="On hand"><span><strong><?= (int)$row['current_stock'] ?></strong> units</span></td>
                                <td data-label="Stock-rule suggestion"><span><strong><?= (int)$row['stock_rule_quantity'] ?></strong> units</span></td>
                                <td data-label="Reason">
                                    <div class="readiness-status-line">
                                        <span><?= $storeWideReason ? 'Store-wide' : htmlspecialchars(explode(':', $reason, 2)[0]) ?></span>
                                        <?php if (!$storeWideReason) {
                                            forecast_help('exception-' . (int)$row['product_id'], $reason . '. Next step: ' . $nextStep, 'Exception details for ' . $row['product_name']);
                                        } ?>
                                    </div>
                                </td>
                                <td data-label="Action">
                                    <?php if ($isAdministrator): ?>
                                        <a class="btn btn-secondary btn-small" href="<?= htmlspecialchars($productUrl) ?>" aria-label="View product details for <?= htmlspecialchars($row['product_name']) ?>">View product details <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                                    <?php else: ?>
                                        <a class="btn btn-secondary btn-small" href="<?= htmlspecialchars(app_url('components/report/predictions.php?search=' . urlencode($row['sku']))) ?>" aria-label="View forecast for <?= htmlspecialchars($row['product_name']) ?>">View Forecast <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$filteredExceptions): ?>
                            <tr><td colspan="5" class="u-text-muted u-text-center"><?= ($searchQuery !== '' || $reasonFilter !== '') ? 'No matches. Clear the filters.' : ($coverage['scope_count'] === 0 ? 'No active products to check.' : 'All active products have usable forecasts.') ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php if ($isAdministrator): ?>
            </details>
        <?php else: ?>
            </section>
        <?php endif; ?>

        <details class="forecast-details exception-history">
            <summary>
                <span class="exception-heading"><i class="bi bi-clock-history" aria-hidden="true"></i> <span>Recent decisions <span class="exception-count">(<?= count($decisions) ?>)</span></span></span>
            </summary>
            <?php if ($decisions): ?>
            <div class="table-wrap" role="region" aria-label="Recent forecast decisions" tabindex="0">
                <table class="data-table" data-no-smart-table data-no-ghost-rows>
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
                                    <strong><?= htmlspecialchars($row['product_name']) ?></strong>
                                    <small class="forecast-sku"><?= htmlspecialchars($row['sku']) ?></small>
                                </td>
                                <td><?= htmlspecialchars(ucfirst($row['decision'])) ?></td>
                                <td><?= (int)$row['original_suggested_qty'] ?></td>
                                <td><?= (int)$row['final_quantity'] ?></td>
                                <td><?= htmlspecialchars($row['override_reason'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($row['decided_by_name'] ?? 'Unknown') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="exception-history-empty">No decisions recorded yet.</p>
            <?php endif; ?>
        </details>
    </main>
</div>
</body>
</html>
