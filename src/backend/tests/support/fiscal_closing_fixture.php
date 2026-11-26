<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . PHP_EOL); exit(1); });
$database = $argv[2] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Disposable database required.');
$config = require __DIR__ . '/../../config/database.php';
$pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET time_zone = '+08:00'");
switch ($argv[1]) {
    case 'setup':
        $pdo->exec("INSERT INTO fiscal_periods (period_id,period_name,start_date,end_date,status,created_by)
            VALUES (1,'Closing reminder fixture',DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 1 DAY),'open',1);
            INSERT INTO inventory_counts (product_id,system_quantity,physical_quantity,difference_qty,discrepancy_reason,counted_by,status)
            VALUES (1,10,8,-2,'Pending review fixture',3,'pending');
            INSERT INTO gcash_service_sessions (cashier_id,shift_id,wallet_number,opening_service_cash,opening_wallet_balance,status)
            VALUES (4,1,'09171234567',100,100,'open');
            UPDATE held_sales SET status='cancelled';
            INSERT INTO store_settings (setting_key,setting_value) VALUES ('shift_reminder_enabled','0')
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);");
        break;
    case 'expired':
        $pdo->exec("UPDATE cashier_shifts SET opened_at=DATE_SUB(NOW(),INTERVAL 2 DAY);
            UPDATE gcash_service_sessions SET opened_at=DATE_SUB(NOW(),INTERVAL 2 DAY);
            UPDATE inventory_counts SET counted_at=DATE_SUB(NOW(),INTERVAL 2 DAY);
            UPDATE fiscal_periods SET end_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE period_id=1;
            INSERT INTO fiscal_periods (period_name,start_date,end_date,status,created_by)
            VALUES ('Next scheduled period',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),'scheduled',1);");
        break;
    case 'resolved':
        $pdo->exec("UPDATE cashier_shifts SET status='closed',closed_at=NOW();
            UPDATE gcash_service_sessions SET status='closed',closed_at=NOW();
            UPDATE inventory_counts SET status='rejected';");
        break;
    case 'lock': $pdo->exec('UPDATE cashier_shifts SET locked_at=NOW()'); break;
    case 'unlock': $pdo->exec('UPDATE cashier_shifts SET locked_at=NULL'); break;
    default: throw new RuntimeException('Unknown fiscal fixture action.');
}
