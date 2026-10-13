<?php
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SystemHealthService.php';

use App\Attention\ApplicationLogReviewService;
use App\Attention\Clock;

ini_set('error_log', sys_get_temp_dir() . '/retailmind_log_review_contract.log');
final class LogReviewClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-12 12:00:00'); }
}
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$rejects = static function (callable $operation) use ($assert): void {
    try {
        $operation();
        $assert(false, 'Invalid or unauthorized acknowledgment was accepted');
    } catch (DomainException | InvalidArgumentException) {
    }
};
// Exercise the page's actual CSV boundary without loading its authenticated route.
$auditPage = file_get_contents(__DIR__ . '/../../frontend/components/system_administrator/audit_logs.php');
if (!preg_match('/function audit_log_csv_value\(.*?\n\}/s', $auditPage, $csvFunction)) {
    throw new RuntimeException('Audit CSV helper could not be loaded');
}
eval($csvFunction[0]);
$assert(audit_log_csv_value(" \t=1+1") === "' \t=1+1", 'Exported log text must not execute spreadsheet formulas after whitespace');
$assert(audit_log_csv_value('Ordinary diagnostic') === 'Ordinary diagnostic', 'Ordinary log text should export unchanged');
$healthPage = file_get_contents(__DIR__ . '/../../frontend/components/system_administrator/system_health.php');
$assert(
    (bool)preg_match('/<\?php if \(\$logSnapshot\[\'acknowledged\'\]\): \?>\s*<p[^>]*\bdata-rm-alert-processed="1"[^>]*>This log version has been acknowledged\. New entries will require another review\.<\/p>/', (string)$healthPage),
    'Acknowledged log status on System Health must stay inline without triggering a modal popup on every visit'
);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE activity_log (log_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT,
    category TEXT, module TEXT, new_value TEXT, metadata TEXT, ip_address TEXT, created_at TEXT)');
$clock = new LogReviewClock();
$backend = sys_get_temp_dir() . '/retailmind_log_review_' . bin2hex(random_bytes(6));
mkdir($backend . '/storage/logs', 0700, true);
$path = $backend . '/storage/logs/app.log';
$reviews = new ApplicationLogReviewService($pdo, $clock, $path);
$writeLog = static function (string $text) use ($path, $clock): void {
    file_put_contents($path, $text);
    touch($path, $clock->now()->getTimestamp());
};
$logCheck = static function (array $checks): array {
    return array_values(array_filter($checks, static fn(array $check): bool => $check['name'] === 'Application log'))[0];
};

try {
    $assert($reviews->check()['status'] === 'healthy', 'Missing log should preserve existing healthy behavior');
    $writeLog('');
    $assert($reviews->reviewSnapshot('super_admin')['excerpt'] === '', 'Empty log must load without read errors');
    $assert($reviews->check()['status'] === 'healthy', 'Empty log must be healthy');
    $rejects(fn() => $reviews->reviewSnapshot('admin'));
    $rejects(fn() => $reviews->acknowledge('super_admin', 1, '', 'fixed', 'Checked.', '192.0.2.1'));

    $writeLog("[12-Oct-2026 11:59:00] Test diagnostic: storage unavailable.\n");
    $snapshot = $reviews->reviewSnapshot('super_admin');
    $assert($reviews->check()['status'] === 'warning', 'Recent unreviewed log must warn');
    $health = new SystemHealthService($pdo, $backend);
    $before = $health->checks();
    $rejects(fn() => $reviews->acknowledge('admin', 1, $snapshot['fingerprint'], 'fixed', 'Checked.', '192.0.2.1'));
    $rejects(fn() => $reviews->acknowledge('super_admin', 1, $snapshot['fingerprint'], 'invalid', 'Checked.', '192.0.2.1'));
    $rejects(fn() => $reviews->acknowledge('super_admin', 1, $snapshot['fingerprint'], 'fixed', ' ', '192.0.2.1'));
    $rejects(fn() => $reviews->acknowledge('super_admin', 1, $snapshot['fingerprint'], 'fixed', str_repeat('x', 1001), '192.0.2.1'));
    $rejects(fn() => $reviews->acknowledge('super_admin', 1, 'forged', 'fixed', 'Checked.', '192.0.2.1'));
    $original = file_get_contents($path);
    $reviews->acknowledge('super_admin', 1, $snapshot['fingerprint'], 'expected', 'Isolated test diagnostic; verified normal access separately.', '192.0.2.1');
    $assert($reviews->check()['status'] === 'healthy', 'Acknowledgment must clear only the current log warning');
    $assert(file_get_contents($path) === $original, 'Acknowledgment must not truncate or change the log');
    $record = $pdo->query('SELECT * FROM activity_log')->fetch(PDO::FETCH_ASSOC);
    $evidence = json_decode($record['metadata'], true, flags: JSON_THROW_ON_ERROR);
    $result = json_decode($record['new_value'], true, flags: JSON_THROW_ON_ERROR);
    $assert($record['user_id'] === 1 && $record['created_at'] === '2026-10-12 12:00:00', 'Reviewer and time must be saved');
    $assert($evidence['excerpt'] === $original && $result['result'] === 'Known diagnostic or test entry', 'Evidence and review result must be preserved');
    $after = $health->checks();
    $assert($logCheck($after)['status'] === 'healthy', 'System Health must use acknowledgment status');
    foreach ($before as $index => $check) {
        if ($check['name'] !== 'Application log') {
            $assert($check['status'] === $after[$index]['status'], 'Acknowledgment must not change other health checks');
        }
    }
    $assert($after[0]['status'] === 'critical' && $after[2]['status'] === 'critical', 'Database and storage failures must stay visible');
    $rejects(fn() => $reviews->acknowledge('super_admin', 1, $snapshot['fingerprint'], 'fixed', 'Duplicate.', '192.0.2.1'));

    file_put_contents($path, "Another error in the same second.\n", FILE_APPEND);
    touch($path, $clock->now()->getTimestamp());
    $assert($reviews->check()['status'] === 'warning', 'Appending in the same second must raise the warning again');
    $rejects(fn() => $reviews->acknowledge('super_admin', 1, $snapshot['fingerprint'], 'fixed', 'Stale form.', '192.0.2.1'));
    $latest = $reviews->reviewSnapshot('super_admin');
    $reviews->acknowledge('super_admin', 1, $latest['fingerprint'], 'fixed', 'Cause corrected; repeated affected action successfully.', '192.0.2.1');
    $assert($reviews->check()['status'] === 'healthy', 'A later review should clear the later version');

    $writeLog(str_repeat('A', 20000) . "\nLarge-log final entry.\n");
    $large = $reviews->reviewSnapshot('super_admin');
    $assert(strlen($large['excerpt']) === 16384 && str_ends_with($large['excerpt'], "Large-log final entry.\n"), 'Large logs must use a bounded recent excerpt');
    $reviews->acknowledge('super_admin', 1, $large['fingerprint'], 'expected', 'Known test output.', '192.0.2.1');
    // Replace a log without changing its length or modification timestamp.
    rename($path, $path . '.old');
    $writeLog(str_repeat('B', 20000) . "\nLarge-log final entry.\n");
    $assert($reviews->check()['status'] === 'warning', 'Rotation or same-size replacement must not reuse an acknowledgment');
    $rejects(fn() => $reviews->acknowledge('super_admin', 1, $large['fingerprint'], 'fixed', 'Stale rotated log.', '192.0.2.1'));
    touch($path, $clock->now()->getTimestamp() - 86401);
    $assert($reviews->check()['status'] === 'healthy', 'Old logs should keep the existing 24-hour behavior');

    $writeLog("Invalid UTF-8 diagnostic: \xff\xfe\n");
    $invalidText = $reviews->reviewSnapshot('super_admin');
    $reviews->acknowledge('super_admin', 1, $invalidText['fingerprint'], 'expected', 'Encoding test entry.', '192.0.2.1');
    $assert($reviews->check()['status'] === 'healthy', 'Invalid log bytes must not prevent recording a review');
    $writeLog('New log data');
    $pdo->exec("CREATE TRIGGER reject_review BEFORE INSERT ON activity_log BEGIN SELECT RAISE(ABORT, 'write rejected'); END");
    $failedSave = $reviews->reviewSnapshot('super_admin');
    try {
        $reviews->acknowledge('super_admin', 1, $failedSave['fingerprint'], 'fixed', 'Cannot save.', '192.0.2.1');
        $assert(false, 'Audit failure must not be ignored');
    } catch (PDOException) {
    }
    $assert($reviews->check()['status'] === 'warning', 'Failed audit save must leave the warning active');
    $pdo->exec('DROP TABLE activity_log');
    $assert($reviews->check()['status'] === 'warning', 'Unavailable review history must not report healthy');
    echo "Application log review contract passed.\n";
} finally {
    // These files and directories are created solely by this test under its random temporary directory.
    foreach ([$path, $path . '.old'] as $testFile) {
        if (is_file($testFile)) {
            unlink($testFile);
        }
    }
    rmdir($backend . '/storage/logs');
    rmdir($backend . '/storage');
    rmdir($backend);
}
