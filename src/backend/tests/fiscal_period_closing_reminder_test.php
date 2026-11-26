<?php
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/notification_schema.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . PHP_EOL); exit(1); });

use App\Services\FiscalPeriodClosingReminder;
use App\Attention\Clock;

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    INSERT INTO roles VALUES (1,'cashier'),(2,'inventory_manager'),(3,'admin'),(4,'super_admin');
    CREATE TABLE users (user_id INTEGER PRIMARY KEY, role_id INTEGER, status TEXT);
    INSERT INTO users VALUES (1,1,'active'),(2,1,'active'),(3,2,'active'),(4,3,'active'),(5,4,'active'),(6,1,'disabled');
    CREATE TABLE notifications (notification_id INTEGER PRIMARY KEY, user_id INTEGER, type TEXT, title TEXT,
        message TEXT, reference_id INTEGER, reference_type TEXT, created_at TEXT);
    CREATE TABLE fiscal_periods (period_id INTEGER PRIMARY KEY, period_name TEXT, start_date TEXT, end_date TEXT, status TEXT);
    INSERT INTO fiscal_periods VALUES (1,'Old period','2026-11-01','2026-11-27','open'),
        (2,'Next period','2026-11-28','2026-12-31','scheduled');
    CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, cashier_id INTEGER, status TEXT, opened_at TEXT, locked_at TEXT);
    INSERT INTO cashier_shifts VALUES (1,1,'open','2026-11-25 10:00:00',NULL),(2,2,'open','2026-11-25 11:00:00',NULL);
    CREATE TABLE gcash_service_sessions (session_id INTEGER PRIMARY KEY, cashier_id INTEGER, status TEXT, opened_at TEXT);
    INSERT INTO gcash_service_sessions VALUES (1,1,'open','2026-11-25 10:00:00');
    CREATE TABLE inventory_counts (count_id INTEGER PRIMARY KEY, status TEXT, counted_at TEXT);
    INSERT INTO inventory_counts VALUES (1,'pending','2026-11-25 10:00:00');");
notification_test_schema($pdo);
$pdo->exec('INSERT INTO user_roles (user_id,role_id) VALUES (5,4),(5,2)');
$clock = new class implements Clock {
    public string $time = '2026-11-25 15:59:59 UTC';
    public function now(): DateTimeImmutable { return new DateTimeImmutable($this->time); }
};
$reminders = new FiscalPeriodClosingReminder($pdo, $clock);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$assert($reminders->synchronize(1, 'cashier') === [], 'No reminder before the Philippine day before period end');
$clock->time = '2026-11-25 16:00:00 UTC';
$own = $reminders->synchronize(1, 'cashier');
$assert(count($own) === 2, 'At Philippine midnight, own open shift and GCash session need closing');
$assert(count($reminders->synchronize(2, 'cashier')) === 1, 'Cashier sees only own work');
$assert(count($reminders->synchronize(3, 'inventory_manager')) === 1, 'Inventory reviewer sees pending count');
$assert(count($reminders->synchronize(4, 'admin')) === 1, 'Administrator gets period oversight');
$assert(count($reminders->synchronize(5, 'inventory_manager')) === 1, 'Secondary assigned inventory role is eligible');
$assert($reminders->synchronize(5, 'admin') === [], 'Unassigned workspace cannot access reminders');
$assert($reminders->synchronize(6, 'cashier') === [], 'Disabled user cannot receive reminders');
$assert($reminders->synchronize(1, 'super_admin') === [], 'No operational reminder in technical workspace');
$before = (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$reminders->synchronize(1, 'cashier');
$assert((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $before, 'Repeated polls do not duplicate bell notices');
$assert((int)$pdo->query('SELECT SUM(email_pending) FROM notifications')->fetchColumn() === 0, 'Bell reminders never queue email');
$pdo->exec('UPDATE notifications SET is_read = 1 WHERE user_id = 1');
$assert(count($reminders->synchronize(1, 'cashier')) === 2, 'Reading does not resolve work or suppress daily popup');
$clock->time = '2026-11-28 04:00:00 UTC';
$overdue = $reminders->synchronize(1, 'cashier');
$assert(count($overdue) === 2 && $overdue[0]->severity === 'critical', 'First return after end still warns about unfinished work');
$assert(str_contains($overdue[0]->explanation, '2026-11-27'), 'Reminder identifies period end date');
$pdo->exec("UPDATE cashier_shifts SET status = 'closed' WHERE cashier_id = 1;
    UPDATE gcash_service_sessions SET status = 'closed';");
$assert($reminders->synchronize(1, 'cashier') === [], 'Completed work clears popup');
$assert((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 1 AND resolved_at IS NULL')->fetchColumn() === 0, 'Completed work resolves bell notices');
$assert(count($reminders->synchronize(2, 'cashier')) === 1, 'Another cashier remains responsible');
$pdo->exec("UPDATE fiscal_periods SET status = 'closed' WHERE period_id = 1");
$assert(count($reminders->synchronize(2, 'cashier')) === 1, 'Legacy unfinished work is still visible after period finalization');
$pdo->exec("UPDATE inventory_counts SET status = 'rejected'; UPDATE cashier_shifts SET status = 'closed';");
$assert($reminders->synchronize(3, 'inventory_manager') === [], 'Resolving counts clears inventory reminder');
$assert($reminders->synchronize(4, 'admin') === [], 'Administrator reminder clears after all work completes');
echo "Fiscal period closing reminders: passed\n";
