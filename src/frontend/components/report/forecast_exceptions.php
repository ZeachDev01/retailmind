<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);
$storeId = store_scope_id($pdo);
$saleUnitsSql = sales_units_sql($pdo, 'si');

$metrics = get_ml_model_metrics();
$settings = get_ml_settings($pdo);
$wapeThreshold = (float)($settings['accuracy_threshold_wape'] ?? 35);
$staleDays = max(1, (int)($settings['retrain_frequency_days'] ?? 7));
$productMetricMap = [];
foreach (($metrics['product_metrics'] ?? []) as $metric) {
    $productMetricMap[(int)$metric['product_id']] = $metric;
}

$predictionStatement = $pdo->prepare("SELECT df.forecast_id, SUM(df.predicted_units) OVER(PARTITION BY df.job_id, df.product_id) AS predicted_demand_next_30_days, df.forecast_origin AS generated_at, df.readiness_level,
           p.product_id, p.sku, p.product_name, i.quantity_on_hand,
           COALESCE(s.units_30,0) units_30, COALESCE(s.nonzero_days,0) nonzero_days, s.first_sale_date
    FROM demand_forecasts df
    JOIN products p ON p.product_id=df.product_id
    JOIN inventory i ON i.product_id=df.product_id
    LEFT JOIN (
      SELECT si.product_id,
        SUM(CASE WHEN sa.sale_date >= DATE_SUB(NOW(),INTERVAL 30 DAY) THEN {$saleUnitsSql} ELSE 0 END) units_30,
        COUNT(DISTINCT CASE WHEN sa.sale_date >= DATE_SUB(NOW(),INTERVAL 90 DAY) THEN DATE(sa.sale_date) END) nonzero_days,
        MIN(sa.sale_date) first_sale_date
      FROM sale_items si JOIN sales sa ON sa.sale_id=si.sale_id GROUP BY si.product_id
    ) s ON s.product_id=df.product_id
    WHERE p.branch_id = ?
      AND df.job_id=(
          SELECT df2.job_id FROM demand_forecasts df2 
          JOIN ml_jobs j ON j.job_id = df2.job_id
          WHERE df2.product_id=df.product_id
            AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded'
          ORDER BY df2.forecast_origin DESC, df2.forecast_id DESC LIMIT 1
      )
    GROUP BY df.job_id, df.product_id, df.forecast_origin, p.product_name, p.product_id, p.sku, i.quantity_on_hand, s.units_30, s.nonzero_days, s.first_sale_date, df.forecast_id, df.predicted_units, df.readiness_level
    ORDER BY df.forecast_origin DESC,p.product_name");
$predictionStatement->execute([$storeId]);
$predictions = $predictionStatement->fetchAll(PDO::FETCH_ASSOC);

$exceptions = [];
foreach ($predictions as $row) {
    $reasons = [];
    $confidence = isset($row['confidence_score']) && $row['confidence_score'] !== null ? (float)$row['confidence_score'] : null;
    $pm = $productMetricMap[(int)$row['product_id']] ?? [];
    $wape = isset($pm['wape']) ? (float)$pm['wape'] : null;
    if ($confidence !== null && $confidence < .50) {
        $reasons[] = 'Low confidence';
    }
    if ($wape !== null && $wape > $wapeThreshold) {
        $reasons[] = 'WAPE above threshold';
    }
    if ((int)$row['nonzero_days'] < (int)($settings['minimum_nonzero_sales_days'] ?? 5)) {
        $reasons[] = 'Insufficient nonzero sales days';
    }
    if ((int)$row['predicted_demand_next_30_days'] > max(10, (int)$row['units_30'] * 2)) {
        $reasons[] = 'Forecast spike versus recent demand';
    }
    if ((int)$row['units_30'] === 0 && (int)$row['predicted_demand_next_30_days'] > 0) {
        $reasons[] = 'Forecast despite zero recent sales';
    }
    if (strtotime((string)$row['generated_at']) < strtotime('-' . $staleDays . ' days')) {
        $reasons[] = 'Prediction is stale';
    }
    if (!$reasons) {
        continue;
    }
    $row['exception_reasons'] = $reasons;
    $row['product_wape'] = $wape;
    $exceptions[] = $row;
}

$searchQuery = trim($_GET['search'] ?? '');
$reasonFilter = trim($_GET['reason'] ?? '');

$availableReasons = [];
foreach ($exceptions as $ex) {
    foreach ($ex['exception_reasons'] as $r) {
        $availableReasons[$r] = true;
    }
}
$availableReasons = array_keys($availableReasons);
sort($availableReasons);

$filteredExceptions = $exceptions;
if ($searchQuery !== '' || $reasonFilter !== '') {
    $filteredExceptions = array_values(array_filter($exceptions, static function (array $row) use ($searchQuery, $reasonFilter): bool {
        if ($searchQuery !== '') {
            $term = mb_strtolower($searchQuery);
            $sku = mb_strtolower((string)($row['sku'] ?? ''));
            $name = mb_strtolower((string)($row['product_name'] ?? ''));
            if (!str_contains($sku, $term) && !str_contains($name, $term)) {
                return false;
            }
        }
        if ($reasonFilter !== '' && !in_array($reasonFilter, $row['exception_reasons'] ?? [], true)) {
            return false;
        }
        return true;
    }));
}

$decisionStatement = $pdo->prepare("SELECT fd.*,p.sku,p.product_name,u.full_name decided_by_name
    FROM forecast_decisions fd JOIN products p ON p.product_id=fd.product_id LEFT JOIN users u ON u.user_id=fd.decided_by
    WHERE p.branch_id = ? ORDER BY fd.decided_at DESC LIMIT 30");
$decisionStatement->execute([$storeId]);
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
            <h1>Forecast Exceptions</h1>
            <div class="page-heading-actions">
                <?php if ($canConfigureModels): ?>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/report/forecast_analytics.php')) ?>">Analytics</a>
                <?php endif; ?>
                <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/inventory_management/replenishment_queue.php')) ?>">Review recommendations</a>
            </div>
        </header>

        <div class="card-grid" aria-label="Triage summary cards">
            <div class="stat-card">
                <div class="value"><?= count($exceptions) ?></div>
                <div class="label">Products needing review</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= htmlspecialchars(number_format($wapeThreshold, 0)) ?>%</div>
                <div class="label">WAPE alert threshold</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'wape')) ?><?= isset($metrics['wape']) ? '%' : '' ?></div>
                <div class="label">Current model WAPE</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= !empty($metrics['model_beats_baseline']) ? 'Yes' : (array_key_exists('model_beats_baseline', $metrics) ? 'No' : '-') ?></div>
                <div class="label">Beats 7-day baseline</div>
            </div>
        </div>

        <section class="dashboard-section">
            <div class="section-header">
                <h2>Exception queue (<?= count($filteredExceptions) ?>)</h2>
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
                            <th scope="col">Forecast 30d</th>
                            <th scope="col">Recent sales 30d</th>
                            <th scope="col">Confidence</th>
                            <th scope="col">WAPE</th>
                            <th scope="col">Generated</th>
                            <th scope="col">Reasons</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredExceptions as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($row['product_name']) ?></strong><br>
                                    <span class="u-text-muted"><?= htmlspecialchars($row['sku']) ?></span>
                                </td>
                                <td><?= (int)$row['predicted_demand_next_30_days'] ?></td>
                                <td><?= (int)$row['units_30'] ?></td>
                                <td><?= isset($row['confidence_score']) && $row['confidence_score'] !== null ? number_format((float)$row['confidence_score'] * 100, 0) . '%' : 'Unavailable' ?></td>
                                <td><?= $row['product_wape'] === null ? '-' : number_format((float)$row['product_wape'], 2) . '%' ?></td>
                                <td><?= htmlspecialchars($row['generated_at']) ?></td>
                                <td>
                                    <?php foreach ($row['exception_reasons'] as $reason): ?>
                                        <span class="reason"><?= htmlspecialchars($reason) ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <a class="btn btn-secondary btn-small" href="<?= htmlspecialchars(app_url('components/report/predictions.php?search=' . urlencode($row['sku']))) ?>" aria-label="Inspect forecast for <?= htmlspecialchars($row['product_name']) ?>">Inspect</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$filteredExceptions): ?>
                            <tr><td colspan="8" class="u-text-muted u-text-center"><?= ($searchQuery !== '' || $reasonFilter !== '') ? 'No matching forecast exceptions found.' : 'No current forecast exceptions.' ?></td></tr>
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
