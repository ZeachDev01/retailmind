<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\DemandForecastService;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);

$service = new DemandForecastService($pdo);
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

$canConfigureModels = role_capability_policy()->allows((string)current_role(), RoleCapabilityPolicy::CONFIGURE_MODELS);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Forecast Exceptions</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Forecast Exceptions</h1>
                <p class="page-subtitle">Excluded products using stock rules and required operational next steps.</p>
            </div>
            <div class="page-heading-actions">
                <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">Demand Forecast</a>
                <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/inventory_management/replenishment_queue.php')) ?>">Review recommendations</a>
            </div>
        </header>

        <div class="card-grid" aria-label="Triage summary cards">
            <div class="stat-card">
                <div class="value"><?= count($exceptions) ?></div>
                <div class="label">Products using stock rules</div>
                <div class="hint"><?= $coverage['filtered']['is_filtered'] ? 'Showing ' . count($filteredExceptions) . ' matching' : 'Excluded from forecasts' ?></div>
            </div>
            <div class="stat-card">
                <div class="value"><?= (int)$coverage['scope_count'] ?></div>
                <div class="label">Active catalog products</div>
                <div class="hint">Replenishment scope denominator</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= (int)$coverage['forecast_backed_count'] ?></div>
                <div class="label">Forecast-backed products</div>
                <div class="hint"><?= $coverage['scope_count'] > 0 ? round(($coverage['forecast_backed_count'] / $coverage['scope_count']) * 100) . '% coverage' : 'No active products' ?></div>
            </div>
            <div class="stat-card">
                <div class="value"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $coverage['store_status']))) ?></div>
                <div class="label">Store status</div>
                <div class="hint"><?= $coverage['is_guidance_usable'] ? 'Guidance usable today' : 'Stock rules only' ?></div>
            </div>
        </div>

        <div class="alert <?= $coverage['forecast_backed_count'] === $coverage['scope_count'] && $coverage['scope_count'] > 0 ? 'tag-success' : 'tag-warning' ?>" role="status" style="margin-bottom:1.5rem;">
            <strong>Coverage:</strong> <?= htmlspecialchars($coverage['coverage_summary_text']) ?>
        </div>

        <section class="dashboard-section">
            <div class="section-header">
                <h2>Exception queue (<?= count($filteredExceptions) ?><?= $coverage['filtered']['is_filtered'] ? ' of ' . count($exceptions) : '' ?>)</h2>
            </div>

            <form method="get" action="" class="search-form no-print" role="search" aria-label="Filter exception queue" style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:flex-end; margin-bottom:1.25rem;">
                <div>
                    <label for="filter-search" class="u-text-muted" style="display:block; font-size:0.85rem; margin-bottom:0.25rem;">Search</label>
                    <input type="search" id="filter-search" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Product name or SKU" class="input-text" style="min-width:200px;">
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
                            <th scope="col">Exclusion reason</th>
                            <th scope="col">Category</th>
                            <th scope="col">Action needed</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredExceptions as $row): ?>
                            <tr>
                                <td>
                                    <strong><a href="../products/product_detail.php?id=<?= (int)$row['product_id'] ?>"><?= htmlspecialchars($row['product_name']) ?></a></strong><br>
                                    <span class="u-text-muted"><?= htmlspecialchars($row['sku']) ?></span>
                                </td>
                                <td><strong><?= (int)$row['current_stock'] ?></strong> units</td>
                                <td><strong><?= (int)$row['stock_rule_quantity'] ?></strong> units</td>
                                <td>
                                    <span class="reason"><?= htmlspecialchars($row['exclusion_reason'] ?? 'Forecast unavailable') ?></span>
                                </td>
                                <td>
                                    <span class="badge tag-light"><?= htmlspecialchars(ucfirst($row['exclusion_category'] ?? 'evidence')) ?></span>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($row['exclusion_action'] ?? 'Replenishment continues on stock rules') ?></small>
                                </td>
                                <td>
                                    <a class="btn btn-secondary btn-small" href="<?= htmlspecialchars(app_url('components/report/predictions.php?search=' . urlencode($row['sku']))) ?>" aria-label="Inspect forecast for <?= htmlspecialchars($row['product_name']) ?>">Inspect</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$filteredExceptions): ?>
                            <tr><td colspan="7" class="u-text-muted u-text-center"><?= ($searchQuery !== '' || $reasonFilter !== '') ? 'No matching forecast exceptions found.' : 'No excluded products. All active products have usable forecasts.' ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="dashboard-section">
            <div class="section-header">
                <h2>Recent forecast decisions (<?= count($decisions) ?>)</h2>
            </div>
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
                                <td><?= htmlspecialchars($row['override_reason'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($row['decided_by_name'] ?? 'Unknown') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$decisions): ?>
                            <tr><td colspan="7" class="u-text-muted u-text-center">No forecast decisions recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
