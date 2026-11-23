<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';
$database = $argv[2] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Disposable database required.');
$config = require __DIR__ . '/../../config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($argv[1] === 'fail_audit') {
    $pdo->exec("CREATE TRIGGER fail_training_settings_audit BEFORE INSERT ON activity_log FOR EACH ROW
        BEGIN IF NEW.action IN ('ML settings update', 'ML schedule update') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test audit unavailable'; END IF; END");
    exit;
}
if ($argv[1] === 'allow_audit') { $pdo->exec('DROP TRIGGER fail_training_settings_audit'); exit; }
$state = [];
foreach (['platform_settings', 'ml_settings', 'ml_jobs', 'model_training_runs'] as $table) {
    $state[$table] = $pdo->query("SELECT * FROM `{$table}` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
}
$state['audit'] = $pdo->query("SELECT user_id, action, new_value FROM activity_log WHERE action IN ('ML settings update', 'ML schedule update') ORDER BY log_id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($state, JSON_THROW_ON_ERROR);
