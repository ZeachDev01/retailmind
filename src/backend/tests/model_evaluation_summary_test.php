<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';

use App\Authorization\RoleCapabilityPolicy;

$reviewRole = 'admin';
function has_capability(string $capability): bool
{
    global $reviewRole;
    return (new RoleCapabilityPolicy())->allows($reviewRole, $capability);
}
function app_url(string $path): string { return '/' . $path; }
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function render_review(PDO $pdo, ?string $displayedForecastModel = null): string
{
    ob_start();
    include __DIR__ . '/../../frontend/components/report/model_evaluation_summary.php';
    return ob_get_clean();
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE platform_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE model_training_runs (training_run_id INTEGER PRIMARY KEY, model_version TEXT, status TEXT);
    CREATE TABLE ml_model_evaluations (evaluation_id INTEGER PRIMARY KEY, model_version TEXT,
        horizon_days INTEGER, mae REAL, rmse REAL, wape REAL, baseline_mae REAL, baseline_rmse REAL,
        baseline_wape REAL, candidate_gate_outcome TEXT, missing_metric_reason TEXT,
        data_window_start TEXT, data_window_end TEXT);");

$empty = render_review($pdo);
check(str_contains($empty, 'None yet') && str_contains($empty, 'No completed model training'), 'Empty state must not imply readiness');
$pdo->exec("INSERT INTO model_training_runs VALUES (1, 'older', 'completed'), (2, 'candidate<script>', 'completed'), (3, 'unfinished', 'running');
    INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'older'),
        ('ml_observation_mode', '1'), ('ml_recommendations_suspended', '0'),
        ('ml_max_wape_7d', '0.35'), ('ml_max_wape_14d', '0.35'), ('ml_max_wape_28d', '0.35');");
$insert = $pdo->prepare("INSERT INTO ml_model_evaluations
    (model_version, horizon_days, wape, baseline_wape, candidate_gate_outcome) VALUES (?, ?, ?, ?, 'passed')");
foreach ([7, 14, 28] as $horizon) $insert->execute(['candidate<script>', $horizon, 0.2, 0.3]);
$before = $pdo->query('SELECT total_changes()')->fetchColumn();
$passed = render_review($pdo, 'old-forecast');
check(substr_count($passed, '>Passed<') === 3, 'All three horizons should pass with good evidence');
check(str_contains($passed, '20.00%') && str_contains($passed, '30.00%'), 'Errors must display as percentages');
check(str_contains($passed, 'Observation only') && str_contains($passed, 'Not the approved model'), 'Passing checks must not imply approval');
check(str_contains($passed, 'differs from the approved model'), 'Old displayed forecasts must be distinguished');
check(str_contains($passed, 'candidate&lt;script&gt;') && !str_contains($passed, '<script>'), 'Model identifiers must be escaped');
check(!str_contains($passed, 'href='), 'Administrator must not receive technical controls or links');
check($before === $pdo->query('SELECT total_changes()')->fetchColumn(), 'Summary must not write to the database');

$pdo->exec("UPDATE ml_model_evaluations SET wape = 0.4 WHERE horizon_days = 7;
    UPDATE platform_settings SET setting_value = '1' WHERE setting_key = 'ml_recommendations_suspended';");
$failed = render_review($pdo);
check(str_contains($failed, 'Needs improvement'), 'Failed checks must remain visible');
check(str_contains($failed, 'Purchase suggestions are paused'), 'Suspension must remain visible');
$pdo->exec('UPDATE ml_model_evaluations SET baseline_wape = NULL WHERE horizon_days = 14');
check(str_contains(render_review($pdo), 'Not enough test results'), 'Missing baseline cannot pass');
$pdo->exec('DELETE FROM ml_model_evaluations WHERE horizon_days = 28');
$incomplete = render_review($pdo);
check(substr_count($incomplete, 'Not enough test results') === 3 && !str_contains($incomplete, '>Passed<'), 'Incomplete evaluation set cannot imply passing checks');

$reviewRole = 'super_admin';
$technical = render_review($pdo);
check(str_contains($technical, 'ml_settings.php') && str_contains($technical, 'forecast_analytics.php'), 'Super Administrator must get existing review links');
check(!str_contains($technical, '<form'), 'Summary must remain read-only for every role');
$reviewRole = 'inventory_manager';
check(render_review($pdo) === '', 'Inventory Manager does not receive the administrator review');
$reviewRole = 'cashier';
check(render_review($pdo) === '', 'Cashier cannot read the summary');
$reviewRole = 'admin';
$pdo->exec('DROP TABLE model_training_runs');
check(str_contains(render_review($pdo), 'Model checks could not be loaded'), 'Read errors must show a safe unavailable state');

if (in_array('--preview', $argv, true)) {
    $directory = __DIR__ . '/../../../.scratch/model-evaluation-summary';
    if (!is_dir($directory)) mkdir($directory, 0777, true);
    foreach (['empty' => $empty, 'passed' => $passed, 'failed' => $failed, 'technical' => $technical] as $name => $html) {
        file_put_contents($directory . '/' . $name . '.html', $html);
    }
}
echo "Model evaluation summary: passed (roles, missing evidence, approval, suspension, escaping, and read-only rendering).\n";
