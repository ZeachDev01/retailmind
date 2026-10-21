<?php
require_once __DIR__ . '/../bootstrap/app.php';

use App\Attention\AttentionCenter;
use App\Attention\AttentionNotificationService;
use App\Attention\AttentionRuleService;
use App\Attention\AttentionSettingsService;
use App\Attention\Clock;
use App\Attention\FailedSignInReviewService;

final class SignInReviewClock implements Clock
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
        $assert(false, 'Invalid or unauthorized review was accepted');
    } catch (DomainException | InvalidArgumentException) {
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, username TEXT, is_recovery_account INTEGER);
    CREATE TABLE login_attempts (attempt_id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, ip_address TEXT,
        was_successful INTEGER, attempted_at TEXT);
    CREATE TABLE activity_log (log_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT,
        category TEXT, module TEXT, record_id INTEGER, new_value TEXT, metadata TEXT, ip_address TEXT, created_at TEXT);
    CREATE TABLE attention_settings (setting_scope TEXT, setting_key TEXT, setting_value TEXT);
    CREATE TABLE attention_states (user_id INTEGER, attention_key TEXT, fingerprint TEXT, is_active INTEGER,
        first_detected_at TEXT, last_detected_at TEXT, resolved_at TEXT, PRIMARY KEY (user_id, attention_key));
    CREATE TABLE notifications (notification_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT,
        title TEXT, message TEXT, reference_id INTEGER, reference_type TEXT, attention_key TEXT,
        attention_severity TEXT, attention_count INTEGER, attention_destination TEXT, is_read INTEGER,
        created_at TEXT);
    INSERT INTO users VALUES (1, 'owner', 0), (2, 'cashier', 0), (3, 'sealed_identity', 1)");
require_once __DIR__ . '/support/notification_schema.php';
notification_test_schema($pdo);
$clock = new SignInReviewClock();
$reviews = new FailedSignInReviewService($pdo, $clock);
$addAttempt = static function (string $identity = 'user:2', string $time = '2026-10-12 11:00:00', int $success = 0) use ($pdo): int {
    $statement = $pdo->prepare('INSERT INTO login_attempts (username, ip_address, was_successful, attempted_at) VALUES (?, ?, ?, ?)');
    $statement->execute([$identity, '192.0.2.10', $success, $time]);
    return (int)$pdo->lastInsertId();
};
$addAttempt('user:2', '2026-10-11 11:59:59');
$addAttempt('user:2', '2026-10-12 11:00:00', 1);
for ($index = 0; $index < 5; $index++) {
    $lastShown = $addAttempt();
}
$assert($reviews->pendingCount() === 5, 'Only failures in the last 24 hours should count');
$shown = $reviews->pendingAttempts('super_admin');
$assert(count($shown) === 5 && $shown[0]['username'] === 'cashier', 'Review should identify the affected account');
$assert($shown[0]['ip_address'] === '192.0.2.10', 'Review must show IP addresses');
$rejects(fn() => $reviews->pendingAttempts('admin'));
$rejects(fn() => $reviews->clearReviewed('admin', 1, $lastShown, 'user_mistake', 'Confirmed with cashier.', '192.0.2.1'));
$rejects(fn() => $reviews->clearReviewed('super_admin', 1, $lastShown, 'invalid', 'Checked.', '192.0.2.1'));
$rejects(fn() => $reviews->clearReviewed('super_admin', 1, $lastShown, 'unconfirmed', ' ', '192.0.2.1'));
$rejects(fn() => $reviews->clearReviewed('super_admin', 1, $lastShown, 'unconfirmed', str_repeat('x', 1001), '192.0.2.1'));
$rejects(fn() => $reviews->clearReviewed('super_admin', 1, $lastShown + 100, 'unconfirmed', 'Checked.', '192.0.2.1'));

$center = new AttentionCenter(new AttentionRuleService(new AttentionSettingsService($pdo, $clock), $clock), new AttentionNotificationService($pdo, $clock));
$refresh = static fn() => $center->refresh(1, 'super_admin', ['failed_login_count' => ['value' => $reviews->pendingCount(), 'count' => $reviews->pendingCount()]]);
$assert(count($refresh()) === 1, 'Five failures should trigger the warning');
$beforeCount = (int)$pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
$newArrival = $addAttempt('unknown:stranger');
$reviews->clearReviewed('super_admin', 1, $lastShown, 'user_mistake', 'Cashier confirmed a typing mistake by phone.', '192.0.2.1');
$assert($reviews->pendingCount() === 1, 'Failure arriving after the displayed batch must remain unreviewed');
$assert((int)$pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() === $beforeCount + 1, 'Manual clearing must not delete attempts');
$assert((int)$pdo->query('SELECT is_active FROM attention_states')->fetchColumn() === 0, 'Manual clearing must resolve the old warning state');
$assert((int)$pdo->query('SELECT is_read FROM notifications')->fetchColumn() === 1, 'Old warning notification should be marked read for its reviewer');
$history = $pdo->query('SELECT * FROM activity_log')->fetch(PDO::FETCH_ASSOC);
$snapshot = json_decode($history['new_value'], true, flags: JSON_THROW_ON_ERROR);
$evidence = json_decode($history['metadata'], true, flags: JSON_THROW_ON_ERROR);
$assert($snapshot['reviewed_count'] === 5 && $snapshot['result'] === 'Confirmed user mistake', 'Review outcome and attempts must be saved');
$assert($history['user_id'] === 1 && $history['created_at'] === '2026-10-12 12:00:00', 'Reviewer and time must be recorded');
$assert($evidence['attempts'][0]['ip_address'] === '192.0.2.10', 'Review snapshot must preserve attempt evidence');
$assert($reviews->pendingAttempts('super_admin')[0]['username'] === 'stranger', 'Unknown identifiers should display without internal prefixes');
$assert($refresh() === [], 'Reviewed failures must no longer trigger the dashboard warning');
$rejects(fn() => $reviews->clearReviewed('super_admin', 1, $lastShown, 'unconfirmed', 'Duplicate.', '192.0.2.1'));

for ($index = 0; $index < 4; $index++) {
    $addAttempt();
}
$assert(count($refresh()) === 1, 'Five new failures must trigger another warning');
$assert((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2, 'New failures must generate a fresh notification');

// Failure while resolving state must roll back the checkpoint and notification update.
$pdo->exec("CREATE TRIGGER reject_resolution BEFORE UPDATE ON attention_states BEGIN SELECT RAISE(ABORT, 'resolution rejected'); END");
$lastPending = $reviews->pendingAttempts('super_admin');
$lastId = (int)$lastPending[array_key_last($lastPending)]['attempt_id'];
try {
    $reviews->clearReviewed('super_admin', 1, $lastId, 'suspected_unauthorized', 'Unfamiliar attempts; account secured.', '192.0.2.1');
    $assert(false, 'Failed transaction should throw');
} catch (PDOException) {
}
$assert($reviews->pendingCount() === 5 && (int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() === 1, 'Failed clear must not leave a review checkpoint');
$assert((int)$pdo->query('SELECT is_read FROM notifications ORDER BY notification_id DESC LIMIT 1')->fetchColumn() === 0, 'Failed clear must not mark the new notification read');
$pdo->exec('DROP TRIGGER reject_resolution');

for ($index = 0; $index < 100; $index++) {
    $addAttempt();
}
$batch = $reviews->pendingAttempts('super_admin');
$assert(count($batch) === 100, 'Review batches should be bounded');
$rejects(fn() => $reviews->clearReviewed('super_admin', 1, $lastId + 100, 'unconfirmed', 'Cannot skip unlisted attempts.', '192.0.2.1'));
$reviews->clearReviewed('super_admin', 1, (int)$batch[99]['attempt_id'], 'unconfirmed', 'Unable to confirm; investigated accounts.', '192.0.2.1');
$assert($reviews->pendingCount() === 5, 'Attempts beyond the shown batch must remain counted');
$addAttempt('user:3');
$assert($reviews->pendingAttempts('super_admin')[5]['username'] === 'Recovery Account', 'Recovery account identity must stay sealed');
$pdo->exec('DELETE FROM login_attempts WHERE attempt_id <= ' . (int)$batch[99]['attempt_id']);
$assert((int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() === 2, 'Review history must survive attempt cleanup');
echo "Failed sign-in review contract passed.\n";
