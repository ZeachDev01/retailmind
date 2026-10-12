<?php
declare(strict_types=1);

// Local Demo orchestration only; fitting and predictions stay in the shared worker.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../bootstrap/app.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
});
$config = require __DIR__ . '/../config/database.php';
$database = (string)$config['database'];
if (!preg_match('/^retailmind_demo_[a-z0-9_]+$/D', $database)
    || !in_array(strtolower((string)env('APP_ENV')), ['development', 'test'], true)
    || !in_array($config['host'], ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Use APP_ENV=development/test and a local retailmind_demo_* database.');
}
$action = $argv[1] ?? '';
if (!in_array($action, ['setup', 'train', 'review', 'approve', 'forecast'], true)) {
    throw new RuntimeException('Usage: demo_forecasting.php setup|train|review|approve VERSION|forecast');
}
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($action === 'setup') {
    $password = (string)getenv('DEMO_PASSWORD');
    if (strlen($password) < 12) throw new RuntimeException('Set DEMO_PASSWORD to at least 12 characters.');
    // CREATE without IF NOT EXISTS prevents replacing an existing database.
    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `{$database}`; SET FOREIGN_KEY_CHECKS=0");
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__ . '/../database/sql/schema.sql'), $tables);
    foreach ($tables[0] as $ddl) $pdo->exec($ddl);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO branches (branch_id,branch_name,branch_code) VALUES (1,'Isolated Demo','DEMO')");
    foreach (['super_admin', 'admin', 'inventory_manager', 'cashier'] as $index => $role) {
        $id = $index + 1;
        $pdo->prepare('INSERT INTO roles (role_id,role_name) VALUES (?,?)')->execute([$id,$role]);
        $pdo->prepare('INSERT INTO users (user_id,full_name,username,password_hash,role_id,branch_id,must_change_password) VALUES (?,?,?,?,?,1,0)')
            ->execute([$id,'Demo ' . $role,'demo_' . $role,password_hash($password,PASSWORD_DEFAULT),$id]);
    }
    (new App\Database\MigrationRunner($pdo, __DIR__ . '/../database/migrations'))->runPending();
    // MySQL 8's charset default differs from legacy model_training_runs metadata.
    $pdo->exec('ALTER TABLE demand_forecasts MODIFY model_version VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    $pdo->exec("INSERT INTO platform_settings (setting_key,setting_value) VALUES
        ('ml_max_wape_7d','0.35'),('ml_max_wape_14d','0.35'),('ml_max_wape_28d','0.35')
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    echo "Created empty isolated Demo: {$database}. Import the CSV next.\n";
    exit;
}
$pdo->exec("USE `{$database}`");
$metadata = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='demo_import_metadata'")->fetchColumn();
if (!$metadata) throw new RuntimeException('Import Demo records before operating the pipeline.');
if ($action === 'review') {
    $candidate = $pdo->query("SELECT model_version, metrics_json FROM model_training_runs
        WHERE status='completed' ORDER BY training_run_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$candidate) throw new RuntimeException('No completed Demo candidate to review.');
    $evaluation = new App\Forecasting\CandidateEvaluationService($pdo);
    echo json_encode(['model_version' => $candidate['model_version'],
        'evidence' => json_decode($candidate['metrics_json'], true, 512, JSON_THROW_ON_ERROR),
        'quality' => $evaluation->evaluateCandidateQuality($candidate['model_version'])],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}
if ($action === 'approve') {
    require_once __DIR__ . '/../includes/functions.php';
    $version = $argv[2] ?? '';
    if (!str_starts_with($version, 'demo_')) throw new RuntimeException('Explicit Demo model version required.');
    $evaluation = new App\Forecasting\CandidateEvaluationService($pdo);
    (new App\Forecasting\ModelPromotionService($pdo, $evaluation))->promote($version, 1);
    $evaluation->setObservationMode(false, 1);
    $evaluation->setRecommendationsSuspended(false, 1);
    echo "Explicitly approved Demo candidate {$version}; Store activation is separate.\n";
} else {
    $id = (new App\Services\MlJobService($pdo))->enqueue($action === 'train' ? 'model_retraining' : 'forecast_generation', 1, 'super_admin');
    echo "Queued {$action} job {$id}; run ml_worker.py --once.\n";
}
