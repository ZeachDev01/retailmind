<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';
$database = $argv[2] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/', $database)) throw new RuntimeException('Disposable database required.');
$config = require __DIR__ . '/../../config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET time_zone = '+08:00'");
switch ($argv[1]) {
    case 'overdue':
        $pdo->exec("UPDATE cashier_shifts SET opened_at = DATE_SUB(NOW(), INTERVAL 2 DAY), locked_at = NULL WHERE shift_id = 1");
        break;
    case 'lock':
        $pdo->exec('UPDATE cashier_shifts SET locked_at = NOW() WHERE shift_id = 1');
        break;
    case 'unlock':
        $pdo->exec('UPDATE cashier_shifts SET locked_at = NULL WHERE shift_id = 1');
        break;
    case 'close':
        $pdo->exec("UPDATE cashier_shifts SET status = 'closed', closed_at = NOW(), closed_by = 4 WHERE shift_id = 1");
        break;
    default: throw new RuntimeException('Unknown fixture action.');
}
