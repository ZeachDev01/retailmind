<?php
declare(strict_types=1);

// Never run operational checks against the configured Store database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_DB_TESTS') !== '1') { echo "Daily expiry MySQL: skipped (RUN_DB_TESTS=1 required)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/NotificationService.php';
set_exception_handler(static function (Throwable $exception): void { fwrite(STDERR, (string)$exception . "\n"); exit(1); });
$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$command = static function (array $arguments): string {
    $output = [];
    exec(implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY], $arguments))) . ' 2>&1', $output, $code);
    if ($code !== 0) throw new RuntimeException(implode("\n", $output));
    return implode("\n", $output);
};
$fixture = static fn(string $action): string => $command([__DIR__ . '/support/theme_fixture.php', $action, $database]);
$daily = static function (string $instant) use ($command, $database, $assert): void {
    $output = $command([__DIR__ . '/support/daily_notifications_fixture.php', $database, $instant]);
    // The global exception handler can return exit 0 after failure; require completion.
    $assert(str_contains($output, 'Notification checks completed.'), 'Daily entrypoint did not complete: ' . $output);
};
try {
    $fixture('create');
    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4",
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '+08:00'");
    $pdo->exec("ALTER TABLE notifications MODIFY type ENUM('low_stock','replenishment','adjustment','system','fiscal_period','expiring_stock','expired_stock') NOT NULL");
    $pdo->exec("INSERT INTO notifications (user_id,type,title,message) VALUES (3,'system','Preserved history','Before upgrade')");
    $migration = require __DIR__ . '/../database/migrations/202610190001_attention_notification_type.php';
    $migration['up']($pdo);
    $migration['up']($pdo);
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE title='Preserved history'")->fetchColumn() === 1, 'Enum upgrade preserves history and can be repeated');
    $pdo->exec("UPDATE users SET created_at='2026-10-19', last_login_at='2026-10-19'");
    $daily('2026-10-19T12:00:00+08:00');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='attention'")->fetchColumn() > 0,
        'Real Administrator attention must complete before the expiry check');
    $pdo->exec("INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,branch_id,warning_lead_time_days)
        VALUES (100,'DAILY-TWO','DAILY-TWO','Two-day Bread',25,3,1,2),(101,'DAILY-FIVE','DAILY-FIVE','Five-day Bread',25,3,1,5)");
    $pdo->exec("INSERT INTO inventory (product_id,quantity_on_hand) VALUES (100,20),(101,10)");
    $pdo->exec("INSERT INTO product_batches (batch_id,product_id,batch_number,quantity,remaining_quantity,expiration_date)
        VALUES (1001,100,'TWO-A',10,10,'2026-10-25'),(1002,100,'TWO-B',10,10,'2026-10-25'),
        (1003,101,'FIVE-A',10,10,'2026-10-25')");
    $reminders = new NotificationService($pdo);
    $active = static fn(string $day, int $user = 3): array => array_keys(array_filter($reminders->getBatchReminders($user, $day), static fn(array $batch): bool => (bool)$batch['active']));
    $daily('2026-10-19T12:00:00+08:00');
    $assert($active('2026-10-19') === [], 'Neither Product qualifies before its warning boundary');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE reference_id IN (1001,1002,1003)")->fetchColumn() === 0,
        'Before the Product boundary the scheduled path must not create fixed-window history');
    $daily('2026-10-20T12:00:00+08:00');
    $assert($active('2026-10-20') === [1003], 'Five-day Product qualifies October 20 without a transaction');
    $pdo->exec("INSERT INTO users (user_id,full_name,username,password_hash,role_id,branch_id,email,status,created_at,last_login_at) VALUES
        (5,'Email only','daily_email','unused',3,1,'email-only@example.test','active','2026-10-19','2026-10-19'),
        (6,'Opted out','daily_optout','unused',3,1,'optout@example.test','active','2026-10-19','2026-10-19'),
        (7,'Inactive','daily_inactive','unused',3,1,'inactive@example.test','disabled','2026-10-19','2026-10-19'),
        (8,'Cashier','daily_cashier','unused',4,1,'cashier@example.test','active','2026-10-19','2026-10-19')");
    $pdo->exec("UPDATE users SET email='admin@example.test' WHERE user_id=1");
    $pdo->exec("INSERT INTO notification_preferences (user_id,notify_inapp,notify_email,notify_low_stock) VALUES
        (1,1,1,0),(5,0,1,0),(6,0,0,0),(7,1,1,0),(8,1,1,0)");
    $daily('2026-10-21T12:00:00+08:00');
    $assert($active('2026-10-21', 5) === [] && $active('2026-10-21', 6) === []
        && $active('2026-10-21', 7) === [] && $active('2026-10-21', 8) === [],
        'Email-only, opted-out, inactive and Cashier accounts must not gain in-app expiry reminders');
    $assert($active('2026-10-21', 1) === [], 'Routine expiry stays out of Administrator workspace');
    $emails = $pdo->query("SELECT recipient FROM email_delivery_log WHERE subject='Expiry 5-Day Alert: Five-day Bread' ORDER BY recipient")->fetchAll(PDO::FETCH_COLUMN);
    $assert($emails === ['email-only@example.test'], 'Only opted-in eligible accounts receive the scheduled expiry email attempt');
    $pdo->exec("INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,branch_id)
        VALUES (102,'DAILY-DEFAULT','DAILY-DEFAULT','Default Bread',25,3,1)");
    $pdo->exec("INSERT INTO inventory (product_id,quantity_on_hand) VALUES (102,10)");
    $pdo->exec("INSERT INTO product_batches (batch_id,product_id,batch_number,quantity,remaining_quantity,expiration_date)
        VALUES (1004,102,'DEFAULT-A',10,10,'2026-12-15'),(1005,100,'UNDATED',0,0,NULL),
        (1006,100,'DEPLETED',0,0,'2026-10-25'),(1007,100,'UNDATED-POSITIVE',10,10,NULL)");
    $pdo->exec('UPDATE inventory SET quantity_on_hand=30 WHERE product_id=100');
    $daily('2026-10-21T12:00:00+08:00');
    $assert($active('2026-10-21') === [1003,1004], 'Unset Product override retains default 60-day milestone beyond the removed fixed 30-day window');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM email_delivery_log WHERE recipient='email-only@example.test' AND subject='Expiry 5-Day Alert: Five-day Bread'")->fetchColumn() === 1,
        'A repeated Manila-day check does not repeat email for a recipient using both channels');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM email_delivery_log WHERE recipient='email-only@example.test' AND subject='Expiry 5-Day Alert: Five-day Bread'")->fetchColumn() === 1,
        'Email-only recipients deduplicate unchanged reruns');
    $pdo->exec("INSERT INTO store_settings (setting_key,setting_value) VALUES ('expiry_alert_days','7,3') ON DUPLICATE KEY UPDATE setting_value='7,3'");
    $daily('2026-10-22T12:00:00+08:00');
    $assert($active('2026-10-22') === [1003], 'Global governance applies only to unset overrides; two-day Product is still before its boundary');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM email_delivery_log WHERE recipient='email-only@example.test' AND subject='Expiry 5-Day Alert: Five-day Bread'")->fetchColumn() === 1,
        'An unchanged condition does not create another daily email');
    $daily('2026-10-23T12:00:00+08:00');
    $assert($active('2026-10-23') === [1001,1002,1003], 'Two-day Product qualifies October 23 with independent batches');
    $historyCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=3 AND type='expiring_stock' AND reference_id IN (1001,1002,1003)")->fetchColumn();
    $daily('2026-10-23T12:00:00+08:00');
    $clock = new class implements App\Attention\Clock {
        public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-24T12:00:00+08:00'); }
    };
    (new NotificationService($pdo, $clock))->checkAndNotifyExpiringStock();
    $daily('2026-10-24T12:00:00+08:00');
    $assert($active('2026-10-24') === [1001,1002,1003], 'Both Product overrides remain eligible after their boundaries');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=3 AND type='expiring_stock' AND reference_id IN (1001,1002,1003)")->fetchColumn() === $historyCount,
        'Repeated daily and transaction-triggered checks share batch milestone identity');
    $pdo->exec('UPDATE products SET warning_lead_time_days=1 WHERE product_id=100');
    $daily('2026-10-24T12:00:00+08:00');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=3 AND reference_id IN (1001,1002) AND reference_type='product_batch_expiry_1'")->fetchColumn() === 2,
        'Later Product setting changes affect the next daily milestone');
    $snapshot = $pdo->query('SELECT batch_id,remaining_quantity,expiration_date FROM product_batches ORDER BY batch_id')->fetchAll();
    $daily('2026-10-25T15:59:59+00:00');
    $onExpiration = $reminders->getBatchReminders(3, '2026-10-25');
    $assert($active('2026-10-25') === [1001,1002,1003] && str_starts_with($onExpiration[1001]['title'], 'Prepare for supplier collection:'),
        'Expiration day remains unexpired until Manila midnight');
    $daily('2026-10-25T16:00:00+00:00');
    $expired = $reminders->getBatchReminders(3, '2026-10-26');
    $assert($active('2026-10-26') === [1001,1002,1003] && $expired[1001]['title'] === 'Expired stock—waiting for supplier collection: Two-day Bread',
        'The next Manila day keeps remaining bread actionable with expired wording');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM email_delivery_log WHERE recipient='email-only@example.test' AND subject='Expired stock—waiting for supplier collection: Two-day Bread'")->fetchColumn() === 2,
        'Email-only recipient receives expired waiting wording for both affected batches');
    $daily('2026-10-27T12:00:00+08:00');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=3 AND type='expired_stock' AND reference_id IN (1001,1002,1003)")->fetchColumn() === 3,
        'Overdue checks retain one expired notification per batch');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE reference_id IN (1005,1006,1007)')->fetchColumn() === 0,
        'Undated and depleted batches create no date reminders');
    $assert($pdo->query('SELECT batch_id,remaining_quantity,expiration_date FROM product_batches ORDER BY batch_id')->fetchAll() === $snapshot,
        'Daily evaluation and date transitions never change batch stock or dates');
    $assert($pdo->query('SELECT product_id,quantity_on_hand FROM inventory WHERE product_id>=100 ORDER BY product_id')->fetchAll()
        === [['product_id'=>100,'quantity_on_hand'=>30],['product_id'=>101,'quantity_on_hand'=>10],['product_id'=>102,'quantity_on_hand'=>10]],
        'Daily evaluation never changes total stock');
    $pdo->exec('UPDATE product_batches SET remaining_quantity=0 WHERE batch_id IN (1001,1002,1003)');
    $daily('2026-10-27T12:00:00+08:00');
    $pdo->exec('UPDATE product_batches SET remaining_quantity=10 WHERE batch_id IN (1001,1002,1003)');
    $pdo->exec("CREATE TRIGGER reject_daily_notification BEFORE INSERT ON notifications FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected daily notification failure'");
    $failed = false;
    try {
        $daily('2026-10-27T12:00:00+08:00');
    } catch (RuntimeException $exception) {
        $failed = str_contains($exception->getMessage(), 'Daily notification checks failed:')
            && !str_contains($exception->getMessage(), 'Notification checks completed.');
    } finally {
        $pdo->exec('DROP TRIGGER reject_daily_notification');
    }
    $assert($failed, 'An operational failure returns nonzero and never reports successful completion');

    // Ticket #267: missing/invalid addresses, resolution and recurrence, technical details, unchanged reruns.
    $pdo->exec("INSERT INTO users (user_id,full_name,username,password_hash,role_id,branch_id,email,status,created_at,last_login_at) VALUES
        (20,'Missing address','t267_missing','unused',3,1,NULL,'active','2026-10-19','2026-10-19'),
        (21,'Valid address','t267_valid','unused',3,1,'ticket267-valid@example.test','active','2026-10-19','2026-10-19'),
        (22,'Invalid address','t267_invalid','unused',3,1,'not-an-address','active','2026-10-19','2026-10-19'),
        (23,'Super address','t267_super','unused',2,1,'ticket267-super@example.test','active','2026-10-19','2026-10-19')");
    $pdo->exec("INSERT INTO notification_preferences (user_id,notify_inapp,notify_email,notify_low_stock) VALUES
        (20,1,1,1),(21,1,1,1),(22,1,1,1),(23,1,1,1)");
    $pdo->exec("INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,branch_id) VALUES
        (200,'T267-BLOCKED','T267-BLOCKED','Ticket 267 Blocked Bread',25,3,1),
        (201,'T267-RECUR','T267-RECUR','Ticket 267 Recurrence Bread',25,3,1)");
    $pdo->exec('INSERT INTO inventory (product_id,quantity_on_hand) VALUES (200,1),(201,5)');
    $pdo->exec("INSERT INTO product_batches (batch_id,product_id,batch_number,quantity,remaining_quantity,expiration_date)
        VALUES (2001,201,'T267-RECUR',5,5,'2026-11-05')");
    $pdo->exec('UPDATE products SET warning_lead_time_days=10 WHERE product_id=201');
    $daily('2026-10-28T12:00:00+08:00');
    $assert($pdo->query("SELECT recipient FROM email_delivery_log WHERE subject='Low Stock Alert: Ticket 267 Blocked Bread' ORDER BY recipient")->fetchAll(PDO::FETCH_COLUMN)
        === ['ticket267-valid@example.test'],
        'A missing or invalid opted-in address is never emailed and does not block an eligible recipient');

    $daily('2026-10-29T12:00:00+08:00');
    $recurring = 'Expiry 10-Day Alert: Ticket 267 Recurrence Bread';
    $expiringEmails = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM email_delivery_log WHERE recipient='email-only@example.test' AND subject='{$recurring}'")->fetchColumn();
    $assert($expiringEmails() === 1, 'A newly triggered expiring condition is emailed once');
    $pdo->exec('UPDATE product_batches SET remaining_quantity=0 WHERE batch_id=2001');
    $daily('2026-10-30T12:00:00+08:00');
    $assert($expiringEmails() === 1, 'A resolved condition is not emailed while it remains resolved');
    $pdo->exec('UPDATE product_batches SET remaining_quantity=5 WHERE batch_id=2001');
    $daily('2026-10-31T12:00:00+08:00');
    $assert($expiringEmails() === 2, 'A recurrence after actual resolution is emailed again');

    $pdo->exec("INSERT INTO model_training_runs (model_name,model_version,status,started_at,error_message)
        VALUES ('Random Forest','ticket267','failed','2026-11-01 08:00:00','ticket267 internal stack trace detail')");
    $daily('2026-11-01T12:00:00+08:00');
    $assert($pdo->query("SELECT recipient FROM email_delivery_log WHERE subject='Random Forest training failure' ORDER BY recipient")->fetchAll(PDO::FETCH_COLUMN)
        === ['ticket267-super@example.test'],
        'Technical model-training failure details are emailed only to the Super Administrator');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE reference_type='model_training' AND message LIKE '%ticket267 internal%' AND workspace='super_admin'")->fetchColumn() > 0,
        'The Super Administrator receives the raw training failure detail');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE reference_type='model_training' AND message LIKE '%ticket267 internal%' AND workspace IN ('admin','inventory_manager','cashier')")->fetchColumn() === 0,
        'Operational Workspaces never receive raw training failure detail');

    $emailRows = (int)$pdo->query('SELECT COUNT(*) FROM email_delivery_log')->fetchColumn();
    $daily('2026-11-01T12:00:00+08:00');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM email_delivery_log')->fetchColumn() === $emailRows,
        'An unchanged rerun adds no new email delivery log rows');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM email_delivery_log WHERE subject='Random Forest training failure'")->fetchColumn() === 1,
        'An already-alerted condition is not emailed again on an unchanged rerun');
    echo "Daily expiry MySQL: passed\n";
} finally {
    $pdo = null;
    $fixture('cleanup');
}
