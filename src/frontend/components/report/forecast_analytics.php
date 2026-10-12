<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\DemandForecastService;
use App\Forecasting\CandidateEvaluationService;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);
$storeId = store_scope_id($pdo);

// 1. Determine currently displayed forecast and model version
$demandService = new DemandForecastService($pdo);
$latestJobId = $demandService->getLatestSuccessfulJobId();

$displayedModelVersion = null;
$forecastOrigin = null;
if ($latestJobId) {
    $stmt = $pdo->prepare("SELECT model_version, forecast_origin FROM demand_forecasts WHERE job_id = ? LIMIT 1");
    $stmt->execute([$latestJobId]);
    $jobMeta = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($jobMeta) {
        $displayedModelVersion = $jobMeta['model_version'];
        $forecastOrigin = $jobMeta['forecast_origin'];
    }
}

if (!$displayedModelVersion) {
    // Fallback to platform settings approved model
    $stmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'");
    $displayedModelVersion = $stmt->fetchColumn() ?: null;
}

// 2. Fetch candidate evaluations for this specific model version
$evalService = new CandidateEvaluationService($pdo);
$evaluations = $displayedModelVersion ? $evalService->getEvaluationsByModelVersion($displayedModelVersion) : [];
$evalByHorizon = [];
foreach ($evaluations as $e) {
    $evalByHorizon[(int)$e['horizon_days']] = $e;
}

// 3. Demo status indicator
$isDemoMode = false;
$metaStmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'demo_import_metadata'");
if ($metaStmt && $metaStmt->fetchColumn()) {
    $isDemoMode = true;
}

// 4. Actual vs Forecast (for past completed target dates)
$actualVsForecast = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.product_name,
            p.sku,
            df.target_date,
            df.horizon_day,
            df.predicted_units,
            (
                SELECT SUM(si.quantity)
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.sale_id
                WHERE si.product_id = df.product_id
                  AND DATE(s.sale_date) = df.target_date
            ) as actual_units
        FROM demand_forecasts df
        JOIN ml_jobs j ON j.job_id = df.job_id
        JOIN products p ON df.product_id = p.product_id
        WHERE df.target_date <= CURDATE()
          AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded'
        ORDER BY df.target_date DESC, df.product_id ASC
        LIMIT 25
    ");
    $stmt->execute();
    $actualVsForecast = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Format helpers
function format_unit_metric(?float $val): string {
    return $val !== null ? number_format($val, 2) . ' units' : 'N/A';
}

function format_wape_metric(?float $val, ?string $reason): string {
    if ($val !== null) {
        return number_format($val * 100, 1) . '%';
    }
    return $reason ? htmlspecialchars($reason) : 'N/A';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Forecast Analytics</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
    <style>
        .model-context-card {
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .demo-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-left: 0.5rem;
        }
        .horizon-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .horizon-card {
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 8px;
            padding: 1.25rem;
        }
        .horizon-card h3 {
            margin-top: 0;
            font-size: 1.15rem;
            border-bottom: 1px solid var(--border-color, #e2e8f0);
            padding-bottom: 0.5rem;
        }
        .metric-row {
            display: flex;
            justify-content: space-between;
            padding: 0.35rem 0;
            font-size: 0.95rem;
        }
        .metric-label { color: var(--text-muted, #64748b); }
        .metric-val { font-weight: 600; }
        .comparison-tag {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
        }
        .gate-passed { background: #dcfce7; color: #15803d; }
        .gate-failed { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1>Forecast Analytics <?php if ($isDemoMode): ?><span class="demo-badge">Demo Evidence (S001)</span><?php endif; ?></h1>
                <p class="page-subtitle">Evaluation metrics bound directly to the active displayed model and run context.</p>
            </div>
            <div>
                <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">View Forecasts</a>
            </div>
        </div>

        <div class="model-context-card">
            <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
                <div>
                    <strong>Bound Model Version:</strong> 
                    <?php if ($displayedModelVersion): ?>
                        <code><?= htmlspecialchars($displayedModelVersion) ?></code>
                    <?php else: ?>
                        <em>No approved model version resolved yet</em>
                    <?php endif; ?>
                </div>
                <div>
                    <strong>Evaluation Context:</strong>
                    <?= $isDemoMode ? 'Isolated Demo Dataset (S001, 20 Products)' : 'Store Operational Sales' ?>
                </div>
            </div>
        </div>

        <?php if (empty($evaluations)): ?>
            <div class="empty-state">
                <i class="bi bi-bar-chart empty-icon"></i>
                <h2>No Evaluation Records Available</h2>
                <p>Candidate evaluations have not been recorded for the current model version. Retrain a candidate model to review evaluation benchmarks.</p>
            </div>
        <?php else: ?>
            <div class="horizon-grid">
                <?php foreach ([7, 14, 28] as $h): 
                    $data = $evalByHorizon[$h] ?? null;
                ?>
                    <div class="horizon-card">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                            <h3 style="margin:0;"><?= $h ?>-Day Horizon</h3>
                            <?php if ($data): ?>
                                <span class="comparison-tag gate-<?= htmlspecialchars($data['candidate_gate_outcome']) ?>">
                                    Gate <?= ucfirst($data['candidate_gate_outcome']) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($data): ?>
                            <div class="metric-row">
                                <span class="metric-label">Random Forest MAE</span>
                                <span class="metric-val"><?= format_unit_metric($data['mae'] !== null ? (float)$data['mae'] : null) ?></span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Random Forest RMSE</span>
                                <span class="metric-val"><?= format_unit_metric($data['rmse'] !== null ? (float)$data['rmse'] : null) ?></span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Random Forest WAPE</span>
                                <span class="metric-val"><?= format_wape_metric($data['wape'] !== null ? (float)$data['wape'] : null, $data['missing_metric_reason']) ?></span>
                            </div>

                            <hr style="border:0; border-top:1px dashed var(--border-color, #e2e8f0); margin:0.75rem 0;">

                            <div class="metric-row">
                                <span class="metric-label">Weekly Baseline MAE</span>
                                <span class="metric-val"><?= format_unit_metric($data['baseline_mae'] !== null ? (float)$data['baseline_mae'] : null) ?></span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Weekly Baseline RMSE</span>
                                <span class="metric-val"><?= format_unit_metric($data['baseline_rmse'] !== null ? (float)$data['baseline_rmse'] : null) ?></span>
                            </div>
                            <div class="metric-row">
                                <span class="metric-label">Weekly Baseline WAPE</span>
                                <span class="metric-val"><?= format_wape_metric($data['baseline_wape'] !== null ? (float)$data['baseline_wape'] : null, null) ?></span>
                            </div>
                        <?php else: ?>
                            <p style="color:var(--text-muted, #64748b);">No evaluation data recorded for <?= $h ?> days.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Actual vs Predicted Comparison (Completed Target Dates)</h2>
            <p class="section-description" style="color:var(--text-muted, #64748b); margin-bottom:1rem;">
                Comparing forecast predictions against observed valid sales for completed dates.
            </p>
            <?php if (empty($actualVsForecast)): ?>
                <p style="color:var(--text-muted, #64748b);">No historical target dates have completed since the active forecast run.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Target Date</th>
                            <th>Horizon Day</th>
                            <th>Predicted Units</th>
                            <th>Actual Valid Sales</th>
                            <th>Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($actualVsForecast as $row): 
                            $diff = $row['actual_units'] !== null ? (int)$row['actual_units'] - (int)$row['predicted_units'] : null;
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($row['product_name']) ?></td>
                                <td><code><?= htmlspecialchars($row['sku']) ?></code></td>
                                <td><?= htmlspecialchars($row['target_date']) ?></td>
                                <td>Day <?= htmlspecialchars((string)$row['horizon_day']) ?></td>
                                <td><strong><?= htmlspecialchars((string)$row['predicted_units']) ?></strong></td>
                                <td><?= $row['actual_units'] !== null ? htmlspecialchars((string)$row['actual_units']) : 'Unknown' ?></td>
                                <td style="color: <?= $diff === null ? 'var(--text-muted)' : ($diff >= 0 ? '#15803d' : '#b91c1c') ?>;">
                                    <?= $diff === null ? '—' : ($diff > 0 ? "+{$diff}" : $diff) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
