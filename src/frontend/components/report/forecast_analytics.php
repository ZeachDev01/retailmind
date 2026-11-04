<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\DemandForecastService;
use App\Forecasting\CandidateEvaluationService;

require_role(['super_admin']);
require_capability(RoleCapabilityPolicy::CONFIGURE_MODELS);
$storeId = store_scope_id($pdo);
$saleUnitsSql = sales_units_sql($pdo, 'si');

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

// Model governance settings
$govStmt = $pdo->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ('ml_approved_model_version', 'ml_observation_mode', 'ml_recommendations_suspended', 'demo_import_metadata', 'demo_replay_result')");
$govSettings = $govStmt ? $govStmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];

$approvedModel = $govSettings['ml_approved_model_version'] ?? null;
if (!$displayedModelVersion) {
    $displayedModelVersion = $approvedModel ?: null;
}

$observationMode = in_array($govSettings['ml_observation_mode'] ?? '0', ['1', 'true'], true);
$recommendationsSuspended = in_array($govSettings['ml_recommendations_suspended'] ?? '0', ['1', 'true'], true);
$hasDemoReplay = !empty($govSettings['demo_replay_result']);

// 2. Fetch candidate evaluations for this specific model version
$evalService = new CandidateEvaluationService($pdo);
$evaluations = $displayedModelVersion ? $evalService->getEvaluationsByModelVersion($displayedModelVersion) : [];
$evalByHorizon = [];
foreach ($evaluations as $e) {
    $evalByHorizon[(int)$e['horizon_days']] = $e;
}

// 2b. Prediction Performance Evidence (honest evidence matched with subsequent Store sales outcomes)
$perfEvidence = $demandService->getPredictionPerformanceEvidence(null, $displayedModelVersion);

// 3. Demo status indicator
$isDemoMode = false;
$demoSource = 'S001';
if (!empty($govSettings['demo_import_metadata'])) {
    $isDemoMode = true;
    $meta = json_decode((string)$govSettings['demo_import_metadata'], true);
    $demoSource = $meta['source_label'] ?? 'S001';
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
                SELECT SUM({$saleUnitsSql})
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

// 5. Training trend data
$trainingTrend = [];
try {
    $runs = $pdo->query("SELECT started_at, metrics_json FROM model_training_runs WHERE status = 'completed' ORDER BY started_at ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($runs as $run) {
        $runMetrics = json_decode((string)$run['metrics_json'], true) ?: [];
        $trainingTrend[] = [
            'date' => $run['started_at'],
            'wape' => $runMetrics['wape'] ?? null,
            'mae' => $runMetrics['mae'] ?? $runMetrics['mean_absolute_error'] ?? null
        ];
    }
} catch (PDOException $e) {}

// No recorded model evidence means an empty chart, not sample results.
$trainingTrend = array_values(array_filter($trainingTrend, static fn(array $run): bool => is_numeric($run['wape'])));
$featureImportance = [];

// Product performance calculations for best/worst tabs
$productPerf = [];
foreach ($actualVsForecast as $row) {
    if ($row['actual_units'] === null) continue;
    $pName = $row['product_name'];
    if (!isset($productPerf[$pName])) {
        $productPerf[$pName] = [
            'product_name' => $pName,
            'sku' => $row['sku'],
            'predicted' => 0,
            'actual' => 0,
            'abs_err' => 0,
            'count' => 0
        ];
    }
    $p = (float)($row['predicted_units'] ?? 0);
    $a = (float)($row['actual_units'] ?? 0);
    $productPerf[$pName]['predicted'] += $p;
    $productPerf[$pName]['actual'] += $a;
    $productPerf[$pName]['abs_err'] += abs($a - $p);
    $productPerf[$pName]['count']++;
}
foreach ($productPerf as &$perf) {
    $perf['mean_err'] = $perf['count'] > 0 ? $perf['abs_err'] / $perf['count'] : 0;
    $perf['wape'] = $perf['actual'] > 0 ? ($perf['abs_err'] / $perf['actual']) * 100 : 0;
}
unset($perf);

$bestPerformers = array_values($productPerf);
usort($bestPerformers, static fn($a, $b) => $a['mean_err'] <=> $b['mean_err']);
$best = array_slice($bestPerformers, 0, 5);

$worstPerformers = array_values($productPerf);
usort($worstPerformers, static fn($a, $b) => $b['mean_err'] <=> $a['mean_err']);
$worst = array_slice($worstPerformers, 0, 5);

// Actual chart data series
$actualChartData = [];
foreach (array_slice(array_values(array_filter($actualVsForecast, static fn(array $row): bool => $row['actual_units'] !== null)), 0, 10) as $row) {
    $actualChartData[] = [
        'product_name' => $row['product_name'] . ' ' . $row['target_date'],
        'forecast_value' => (float)$row['predicted_units'],
        'actual_demand' => (float)($row['actual_units'] ?? 0)
    ];
}
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
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <title>Forecast Analytics</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
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
            text-transform: uppercase;
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
        .metric-label { color: var(--muted); }
        .metric-val { font-weight: 600; }
        .comparison-tag {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }
        .gate-passed { background: #dcfce7; color: #15803d; }
        .gate-failed { background: #fee2e2; color: #b91c1c; }
        .gov-pill-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: center;
        }
        .gov-pill {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }
        .gov-pill-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            font-weight: 600;
        }
        .gov-pill-val {
            font-size: 0.95rem;
            font-weight: 600;
        }
    </style>
    <?php require_once __DIR__ . '/../report/forecast_ui.php'; ?>
</head>
<body class="forecast-workspace">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1>Forecast Analytics <?php if ($isDemoMode): ?><span class="demo-badge">Demo Evidence (<?= htmlspecialchars($demoSource) ?>)</span><?php endif; ?></h1>
                <p class="page-subtitle">Compare forecasts with recorded sales. Review model evidence when needed.</p>
            </div>
        </div>

        <?php forecast_workflow(''); ?>
        <?php if ($recommendationsSuspended || $observationMode): ?>
            <p class="forecast-notice" role="status"><?= $recommendationsSuspended ? 'Forecast buying suggestions are paused. Use stock rules.' : 'Observation only. Forecasts are not approved for routine buying.' ?></p>
        <?php endif; ?>
        <details class="forecast-details"><summary>Model &amp; data details</summary>
        <section class="model-context-card" aria-label="Model Governance">
            <?= csrf_field() ?>
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <div class="gov-pill-grid">
                    <div class="gov-pill">
                        <span class="gov-pill-label">Displayed model</span>
                        <span class="gov-pill-val">
                            <?php if ($displayedModelVersion): ?>
                                <code><?= htmlspecialchars($displayedModelVersion) ?></code>
                            <?php else: ?>
                                <span style="color:var(--muted);">No model yet</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="gov-pill">
                        <span class="gov-pill-label">Approved model</span>
                        <span class="gov-pill-val">
                            <?php if ($approvedModel): ?>
                                <code><?= htmlspecialchars($approvedModel) ?></code>
                            <?php else: ?>
                                <span style="color:var(--muted);">None</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="gov-pill">
                        <span class="gov-pill-label">Buying guidance</span>
                        <span class="gov-pill-val">
                            <?php if ($recommendationsSuspended): ?>
                                <span class="comparison-tag gate-failed">Paused</span>
                            <?php elseif ($observationMode): ?>
                                <span class="comparison-tag" style="background:#fef3c7; color:#92400e;">Observation only</span>
                            <?php else: ?>
                                <span class="comparison-tag gate-passed">Not paused</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="gov-pill">
                        <span class="gov-pill-label">Sales source</span>
                        <span class="gov-pill-val">
                            <?= $isDemoMode ? 'Isolated Demo (' . htmlspecialchars($demoSource) . ')' : 'Operational Sales' ?>
                        </span>
                    </div>
                </div>
                <div>
                    <?php if ($hasDemoReplay): ?>
                        <a class="btn btn-secondary" href="forecast_replay.php">Historical replay</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        </details>
        <section class="forecast-analytics-tabs" aria-label="Forecast charts">
            <div class="analytics-tab-list" role="tablist" aria-label="Forecast analytics views">
                <button class="analytics-tab is-active" id="analytics-tab-actual" type="button" role="tab" aria-selected="true" aria-controls="analytics-panel-actual" data-analytics-tab="actual">
                    <i class="bi bi-bar-chart" aria-hidden="true"></i> Forecasts vs sales
                </button>
                <button class="analytics-tab" id="analytics-tab-features" type="button" role="tab" aria-selected="false" tabindex="-1" aria-controls="analytics-panel-features" data-analytics-tab="features">
                    <i class="bi bi-sliders" aria-hidden="true"></i> Input influence
                </button>
                <button class="analytics-tab" id="analytics-tab-trend" type="button" role="tab" aria-selected="false" aria-controls="analytics-panel-trend" data-analytics-tab="trend" tabindex="-1">
                    <i class="bi bi-graph-up" aria-hidden="true"></i> Error trend
                </button>
                <button class="analytics-tab" id="analytics-tab-best" type="button" role="tab" aria-selected="false" aria-controls="analytics-panel-best" data-analytics-tab="best" tabindex="-1">
                    <i class="bi bi-trophy" aria-hidden="true"></i> Lowest errors
                </button>
                <button class="analytics-tab" id="analytics-tab-attention" type="button" role="tab" aria-selected="false" aria-controls="analytics-panel-attention" data-analytics-tab="attention" tabindex="-1">
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Highest errors
                </button>
            </div>

            <div class="analytics-tab-panel chart-card" id="analytics-panel-features" role="tabpanel" aria-labelledby="analytics-tab-features" data-analytics-panel="features" hidden>
                <h2 id="analytics-view-title">Input influence</h2>
                <?php if (empty($featureImportance)): ?><p class="muted">Input influence has not been recorded for this model.</p><?php else: ?><canvas id="featureChart" role="img" aria-label="Recorded model measurements"></canvas><?php endif; ?>
            </div>

            <div class="analytics-tab-panel chart-card" id="analytics-panel-trend" role="tabpanel" aria-labelledby="analytics-tab-trend" data-analytics-panel="trend" hidden>
                <h2>Prediction error over training runs</h2>
                <?php if (empty($trainingTrend)): ?><p class="muted">No recorded training error yet.</p><?php else: ?><canvas id="trendChart" role="img" aria-label="Recorded model measurements"></canvas><?php endif; ?>
            </div>

            <div class="analytics-tab-panel chart-card" id="analytics-panel-actual" role="tabpanel" aria-labelledby="analytics-tab-actual" data-analytics-panel="actual">
                <h2>Forecasts vs recorded sales <?php forecast_help('sales-comparison-help', 'Recorded units by product and date. Forecasts are blue; recorded sales are green.', 'About the sales comparison'); ?></h2>
                <?php if (empty($actualChartData)): ?><p class="muted">No sales comparisons yet. Comparisons appear when forecast dates have recorded sales.</p><?php else: ?><canvas id="actualChart" role="img" aria-label="Recorded forecast and sales comparison"></canvas>
                <details class="forecast-details"><summary>Comparison values</summary><div class="table-wrap"><table class="data-table"><thead><tr><th>Product &amp; date</th><th>Forecast units</th><th>Recorded sales</th></tr></thead><tbody>
                <?php foreach ($actualChartData as $point): ?><tr><td><?= htmlspecialchars($point['product_name']) ?></td><td><?= htmlspecialchars((string)$point['forecast_value']) ?></td><td><?= htmlspecialchars((string)$point['actual_demand']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div></details><?php endif; ?>
            </div>

            <div class="analytics-tab-panel" id="analytics-panel-best" role="tabpanel" aria-labelledby="analytics-tab-best" data-analytics-panel="best" hidden>
                <h2>Lowest recorded errors</h2>
                <?php if (empty($best)): ?>
                    <p style="color:var(--muted);">No recorded sales comparisons to rank yet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th scope="col">Product</th>
                                    <th scope="col">SKU</th>
                                    <th scope="col">Predicted</th>
                                    <th scope="col">Actual</th>
                                    <th scope="col">Average error (units)</th>
                                    <th scope="col">WAPE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($best as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                                        <td><code><?= htmlspecialchars($item['sku']) ?></code></td>
                                        <td><?= number_format($item['predicted'], 1) ?></td>
                                        <td><?= number_format($item['actual'], 1) ?></td>
                                        <td><?= number_format($item['mean_err'], 2) ?></td>
                                        <td><?= number_format($item['wape'], 1) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="analytics-tab-panel" id="analytics-panel-attention" role="tabpanel" aria-labelledby="analytics-tab-attention" data-analytics-panel="attention" hidden>
                <h2>Forecasts Needing Attention</h2>
                <?php if (empty($worst)): ?>
                    <p style="color:var(--muted);">No variance flags recorded.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th scope="col">Product</th>
                                    <th scope="col">SKU</th>
                                    <th scope="col">Predicted</th>
                                    <th scope="col">Actual</th>
                                    <th scope="col">Average error (units)</th>
                                    <th scope="col">WAPE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($worst as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                                        <td><code><?= htmlspecialchars($item['sku']) ?></code></td>
                                        <td><?= number_format($item['predicted'], 1) ?></td>
                                        <td><?= number_format($item['actual'], 1) ?></td>
                                        <td><?= number_format($item['mean_err'], 2) ?></td>
                                        <td><?= number_format($item['wape'], 1) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="model-context-card" aria-label="Prediction Performance Evidence" style="margin-bottom: 1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem;">
                <div>
                    <h2 style="font-size:1.15rem; margin:0 0 0.25rem 0;">Performance on Store sales</h2>
                    <p class="muted" style="margin:0; font-size:0.875rem;">
                        Compares saved forecasts with later Store sales.
                    </p>
                </div>
                <div>
                    <?php if ($perfEvidence['status'] === 'genuine_evidence'): ?>
                        <span class="comparison-tag gate-passed" style="font-size:0.875rem; padding:0.35rem 0.75rem;">Genuine Store Evidence</span>
                    <?php elseif ($perfEvidence['status'] === 'collecting_evidence'): ?>
                        <span class="comparison-tag" style="background:var(--theme-warning-surface, #fef3c7); color:var(--theme-warning-text, #92400e); font-size:0.875rem; padding:0.35rem 0.75rem;">Still Collecting Evidence</span>
                    <?php else: ?>
                        <span class="comparison-tag gate-failed" style="font-size:0.875rem; padding:0.35rem 0.75rem;">Unavailable</span>
                    <?php endif; ?>
                </div>
            </div>

            <details class="forecast-details"><summary>Observation evidence &amp; daily errors</summary>
            <p style="margin-bottom:0.75rem;"><?= htmlspecialchars($perfEvidence['explanation']) ?></p>
            <p class="muted">Daily errors compare one Product/day at lead times 1–7, 1–14, and 1–28 days; they are not errors of summed horizon totals. Technical qualification separately evaluates summed horizons using chronological backtests.</p>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1rem; padding:0.75rem; background:var(--theme-surface-soft, #f8fafc); border-radius:6px;">
                <div>
                    <span class="muted" style="font-size:0.75rem; display:block; text-transform:uppercase;">Evaluated Model</span>
                    <strong><code><?= htmlspecialchars($perfEvidence['model_version']) ?></code></strong>
                    <span class="muted" style="font-size:0.8rem;">(<?= ucfirst($perfEvidence['model_association']) ?> Model)</span>
                </div>
                <div>
                    <span class="muted" style="font-size:0.75rem; display:block; text-transform:uppercase;">Observation Progress</span>
                    <strong><?= (int)$perfEvidence['completed_target_dates'] ?> / <?= (int)$perfEvidence['required_target_dates'] ?> dates</strong>
                    <span class="muted" style="font-size:0.8rem;">(<?= (int)$perfEvidence['usable_comparison_count'] ?> comparisons)</span>
                </div>
                <div>
                    <span class="muted" style="font-size:0.75rem; display:block; text-transform:uppercase;">Verified Stockouts</span>
                    <strong><?= htmlspecialchars($perfEvidence['verified_stockouts_display']) ?></strong>
                    <span class="muted" style="font-size:0.8rem;">(Incomplete demand excluded)</span>
                </div>
                <div>
                    <span class="muted" style="font-size:0.75rem; display:block; text-transform:uppercase;">Verified Expiry Losses</span>
                    <strong><?= htmlspecialchars($perfEvidence['verified_expiry_losses_display']) ?><?= $perfEvidence['verified_expiry_losses'] !== null ? ' units' : '' ?></strong>
                    <span class="muted" style="font-size:0.8rem;">(Tracked losses)</span>
                </div>
            </div>

            <p class="muted" style="font-size:0.85rem; margin-bottom:1rem;">
                <strong>Inputs:</strong> <?= htmlspecialchars($perfEvidence['model_inputs_description']) ?>
            </p>

            <div class="table-wrap">
                <table class="data-table">
                    <caption>Daily Errors by Forecast Lead Time</caption>
                    <thead>
                        <tr>
                            <th scope="col">Forecast Lead Time</th>
                            <th scope="col">Overestimated Units</th>
                            <th scope="col">Underestimated Units</th>
                            <th scope="col">Model Error (MAE)</th>
                            <th scope="col">Model Error (WAPE)</th>
                            <th scope="col">Weekly Baseline Error (MAE)</th>
                            <th scope="col">Weekly Baseline Error (WAPE)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ([7, 14, 28] as $h):
                            $m = $perfEvidence['metrics_by_horizon'][$h] ?? [];
                        ?>
                            <tr>
                                <th scope="row">1–<?= $h ?> Days Ahead</th>
                                <td><?= $m['overestimated_units'] !== null ? number_format($m['overestimated_units'], 2) . ' units' : '<span class="muted">Unavailable</span>' ?></td>
                                <td><?= $m['underestimated_units'] !== null ? number_format($m['underestimated_units'], 2) . ' units' : '<span class="muted">Unavailable</span>' ?></td>
                                <td><?= $m['mae'] !== null ? number_format($m['mae'], 2) . ' units' : '<span class="muted">Unavailable</span>' ?></td>
                                <td><?= $m['wape'] !== null ? number_format($m['wape'] * 100, 2) . '%' : '<span class="muted">Unavailable</span>' ?></td>
                                <td><?= $m['baseline_mae'] !== null ? number_format($m['baseline_mae'], 2) . ' units' : '<span class="muted">Unavailable</span>' ?></td>
                                <td><?= $m['baseline_wape'] !== null ? number_format($m['baseline_wape'] * 100, 2) . '%' : '<span class="muted">Unavailable</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="muted" style="font-size:0.8rem; margin-top:0.75rem; margin-bottom:0;">
                <small><?= htmlspecialchars($perfEvidence['causal_claim_note']) ?> Stockout periods leave unmet demand unknown and are kept separate from forecast errors.</small>
            </p>
            </details>
        </section>

        <details class="forecast-details"><summary>Historical model checks · 7, 14 &amp; 28 days</summary>
        <p class="muted">Technical qualification: chronological backtests compare summed 7-, 14-, and 28-day Product totals. These historical tests are separate from subsequent Store outcome evidence above.</p>
        <?php if (empty($evaluations)): ?>
            <div class="empty-state">
                <i class="bi bi-bar-chart empty-icon"></i>
                <h2>No Evaluation Records Available</h2>
                <p>Retrain a candidate model to record evaluation benchmarks.</p>
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
                            <p style="color:var(--muted);">No evaluation data recorded for <?= $h ?> days.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        </details>

        <details class="forecast-details"><summary>Recent prediction logs</summary>
        <section aria-labelledby="prediction-logs-title">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem;">
                <h2 id="prediction-logs-title" style="margin:0;">Recent Prediction Logs</h2>
                <span class="metric-label" style="font-size:0.85rem;">Observed sales for past target dates</span>
            </div>
            <?php if (empty($actualVsForecast)): ?>
                <p style="color:var(--muted); margin:0;">No completed forecast target dates recorded.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Product</th>
                                <th scope="col">SKU</th>
                                <th scope="col">Target Date</th>
                                <th scope="col">Days ahead</th>
                                <th scope="col">Predicted Units</th>
                                <th scope="col">Recorded sales</th>
                                <th scope="col">Difference</th>
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
                                    <td style="color: <?= $diff === null ? 'var(--muted)' : ($diff >= 0 ? 'var(--theme-success-text, #15803d)' : 'var(--theme-danger-text, #b91c1c)') ?>;">
                                        <?= $diff === null ? '—' : ($diff > 0 ? "+{$diff}" : (string)$diff) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        </details>
    </main>
</div>

<script>
const featureData = <?= json_encode(['labels' => array_keys($featureImportance), 'values' => array_values($featureImportance)], JSON_HEX_TAG) ?>;
const trendData = <?= json_encode($trainingTrend, JSON_HEX_TAG) ?>;
const actualData = <?= json_encode($actualChartData, JSON_HEX_TAG) ?>;
let analyticsPrinting = false;

function analyticsTextColor() {
    const isPrint = analyticsPrinting || (window.matchMedia && window.matchMedia('print').matches);
    return !isPrint && document.documentElement.dataset.theme === 'dark' ? '#b1bfd3' : '#475569';
}

function drawBars(canvasId, labels, series, legends) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const dpr = window.devicePixelRatio || 1;
    const width = canvas.clientWidth || 600;
    const height = 240;
    canvas.width = width * dpr;
    canvas.height = height * dpr;
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, width, height);

    const pad = {l: 48, r: 12, t: 18, b: 55};
    const cw = width - pad.l - pad.r;
    const ch = height - pad.t - pad.b;
    const all = series.flatMap(s => s.values).filter(v => Number.isFinite(Number(v))).map(Number);
    const max = Math.max(1, ...all) * 1.1;

    ctx.strokeStyle = '#94a3b8';
    ctx.beginPath();
    ctx.moveTo(pad.l, pad.t);
    ctx.lineTo(pad.l, height - pad.b);
    ctx.lineTo(width - pad.r, height - pad.b);
    ctx.stroke();

    const groups = Math.max(1, labels.length);
    const groupW = cw / groups;
    const barW = Math.max(3, groupW / (series.length + 1));

    series.forEach((s, si) => {
        ctx.fillStyle = s.fill || (si === 0 ? '#2563eb' : '#16a34a');
        s.values.forEach((value, i) => {
            value = Number(value) || 0;
            const h = (value / max) * ch;
            const x = pad.l + i * groupW + (si + 0.4) * barW;
            ctx.fillRect(x, height - pad.b - h, barW * 0.8, h);
        });
    });

    ctx.fillStyle = analyticsTextColor();
    ctx.font = '11px sans-serif';
    labels.forEach((label, i) => {
        ctx.save();
        ctx.translate(pad.l + i * groupW + groupW / 2, height - pad.b + 8);
        ctx.rotate(-0.45);
        ctx.textAlign = 'right';
        ctx.textBaseline = 'top';
        ctx.fillText(String(label).slice(0, 18), 0, 0);
        ctx.restore();
    });
    ctx.fillText(max.toFixed(1), 4, pad.t + 5);
    if (legends) {
        legends.forEach((x, i) => ctx.fillText(x, pad.l + i * 110, 12));
    }
}

function drawLine(canvasId, labels, values) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.clientWidth || 600;
    const h = 240;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, w, h);

    const p = {l: 45, r: 15, t: 15, b: 45};
    const cw = w - p.l - p.r;
    const ch = h - p.t - p.b;
    const nums = values.map(Number).filter(Number.isFinite);
    const max = Math.max(1, ...nums) * 1.1;

    ctx.strokeStyle = '#94a3b8';
    ctx.beginPath();
    ctx.moveTo(p.l, p.t);
    ctx.lineTo(p.l, h - p.b);
    ctx.lineTo(w - p.r, h - p.b);
    ctx.stroke();

    ctx.strokeStyle = '#2563eb';
    ctx.lineWidth = 2;
    ctx.beginPath();
    values.forEach((v, i) => {
        const x = p.l + (labels.length <= 1 ? cw / 2 : (i * cw) / (labels.length - 1));
        const y = h - p.b - ((Number(v) || 0) / max) * ch;
        i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
    });
    ctx.stroke();

    ctx.fillStyle = analyticsTextColor();
    ctx.font = '11px sans-serif';
    labels.forEach((l, i) => {
        if (i % Math.max(1, Math.ceil(labels.length / 6)) === 0) {
            ctx.fillText(String(l).slice(5, 10), p.l + (labels.length <= 1 ? 0 : (i * cw) / (labels.length - 1)) - 12, h - 18);
        }
    });
    ctx.fillText(max.toFixed(1), 3, p.t + 5);
}

function drawAnalyticsPanel(panel) {
    if (panel === 'features') drawBars('featureChart', featureData.labels, [{values: featureData.values, fill: '#2563eb'}]);
    if (panel === 'trend') drawLine('trendChart', trendData.map(x => x.date), trendData.map(x => x.wape));
    if (panel === 'actual') drawBars('actualChart', actualData.map(x => x.product_name), [{values: actualData.map(x => x.forecast_value), fill: '#2563eb'}, {values: actualData.map(x => x.actual_demand), fill: '#16a34a'}], ['Forecast', 'Actual']);
}

const analyticsTabs = Array.from(document.querySelectorAll('[data-analytics-tab]'));
const analyticsPanels = Array.from(document.querySelectorAll('[data-analytics-panel]'));

function activateAnalyticsTab(tab) {
    const target = tab.dataset.analyticsTab;
    analyticsTabs.forEach(item => {
        const active = item === tab;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', String(active));
        item.tabIndex = active ? 0 : -1;
    });
    analyticsPanels.forEach(panel => {
        panel.hidden = panel.dataset.analyticsPanel !== target;
    });
    drawAnalyticsPanel(target);
}

analyticsTabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activateAnalyticsTab(tab));
    tab.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        let next = index;
        if (event.key === 'ArrowRight') next = (index + 1) % analyticsTabs.length;
        if (event.key === 'ArrowLeft') next = (index - 1 + analyticsTabs.length) % analyticsTabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = analyticsTabs.length - 1;
        analyticsTabs[next].focus();
        activateAnalyticsTab(analyticsTabs[next]);
    });
});

drawAnalyticsPanel('actual');

const redrawAnalytics = () => {
    const activeTab = document.querySelector('[data-analytics-tab].is-active');
    if (activeTab) drawAnalyticsPanel(activeTab.dataset.analyticsTab);
};

const redrawAll = () => {
    drawAnalyticsPanel('features');
    drawAnalyticsPanel('trend');
    drawAnalyticsPanel('actual');
};

window.addEventListener('retailmind:themechange', redrawAnalytics);
window.addEventListener('resize', redrawAnalytics);

if (window.MutationObserver) {
    const themeObserver = new MutationObserver(mutations => {
        for (const mutation of mutations) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'data-theme') {
                redrawAnalytics();
                break;
            }
        }
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
}

window.addEventListener('beforeprint', () => {
    analyticsPrinting = true;
    redrawAll();
});

window.addEventListener('afterprint', () => {
    analyticsPrinting = false;
    redrawAnalytics();
});

if (window.matchMedia) {
    window.matchMedia('print').addEventListener('change', e => {
        analyticsPrinting = e.matches;
        if (e.matches) {
            redrawAll();
        } else {
            redrawAnalytics();
        }
    });
}
</script>
</body>
</html>
