<?php
// Uses a disposable database, never the configured Store's tables.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_CHECKOUT_DB_TESTS') !== '1') { echo "Philippine TIMESTAMP projection: skipped (RUN_CHECKOUT_DB_TESTS=1)\n"; exit; }
require_once __DIR__ . '/../app/Core/Environment.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Services/PhilippineTime.php';
require_once __DIR__ . '/../app/Services/CashierOperationalHistoryService.php';

use App\Core\Database;
use App\Core\Environment;
use App\Services\CashierOperationalHistoryService;
use App\Services\PhilippineTime;

Environment::load(dirname(__DIR__, 3) . '/.env');
$config = require __DIR__ . '/../config/database.php';
set_exception_handler(static function (Throwable $exception): void { fwrite(STDERR, $exception->getMessage()."\n"); exit(1); });
$admin = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database = 'retailmind_time_test_' . bin2hex(random_bytes(6));
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
try {
    $admin->exec("CREATE DATABASE `{$database}`");
    $admin->exec("USE `{$database}`");
    $admin->exec("SET time_zone='+00:00'");
    $admin->exec("CREATE TABLE sales (sale_id INT PRIMARY KEY, cashier_id INT, shift_id INT, sale_date TIMESTAMP(6), total_amount DECIMAL(10,2), payment_method VARCHAR(20), cash_received DECIMAL(10,2), change_due DECIMAL(10,2))");
    $insert = $admin->prepare('INSERT INTO sales VALUES (?, ?, 1, ?, 10, "cash", 10, 0)');
    foreach ([[1,1,'2026-09-30 15:59:59'],[2,1,'2026-09-30 16:00:00'],[3,1,'2026-10-01 15:59:59.999999'],[4,1,'2026-10-01 16:00:00'],[5,2,'2026-10-01 00:39:18'],[6,1,'2026-10-01 00:39:18']] as $row) $insert->execute($row);
    $epoch = (float)$admin->query('SELECT UNIX_TIMESTAMP(sale_date) FROM sales WHERE sale_id=6')->fetchColumn();
    $config['database'] = $database;
    $read = Database::connection($config);
    $assert($read->query('SELECT @@session.time_zone')->fetchColumn() === '+08:00', 'Application read session pins TIMESTAMP projection');
    $sale = $read->query('SELECT sale_date,UNIX_TIMESTAMP(sale_date) AS epoch FROM sales WHERE sale_id=6')->fetch(PDO::FETCH_ASSOC);
    $assert((float)$sale['epoch'] === $epoch && PhilippineTime::format($sale['sale_date']) === 'Oct 1, 2026 · 8:39 AM', 'Display keeps the stored instant and historical transaction time');
    $history = new CashierOperationalHistoryService($read);
    $assert(array_column($history->page('sales',1,1,25,'2026-10-01','2026-10-01'),'sale_id') === [3,6,2], 'Manila day includes UTC previous-date midnight, excludes next midnight, scopes owner and sorts underlying instants');
    $assert((float)$admin->query('SELECT UNIX_TIMESTAMP(sale_date) FROM sales WHERE sale_id=6')->fetchColumn() === $epoch, 'Formatting did not rewrite timestamp history');
    echo "Philippine TIMESTAMP projection and day boundaries: passed\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
