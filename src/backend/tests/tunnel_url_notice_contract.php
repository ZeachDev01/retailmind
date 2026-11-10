<?php
// Remote access URL notice (2026-11-10): the tunnel was started before the
// Administrator account existed, so the change-time email had no recipient and
// was never retried. The notice must also run from the daily job, deliver at
// most once per Manila day, and only record success after a real send.

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
restore_exception_handler();

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$functions = (string)@file_get_contents(__DIR__ . '/../includes/functions.php');
$dailyJob = (string)@file_get_contents(__DIR__ . '/../scripts/run_notifications.php');

$assert(str_contains($functions, "tunnel_last_notified_date"), 'URL notice must store the Manila day marker');
$assert(str_contains($functions, "=== \$today"), 'URL notice must skip a second send on the same day');
$assert(str_contains($dailyJob, 'notify_tunnel_url_change'), 'Daily job must retry the remote access URL notice');

$noticeStart = strpos($functions, 'function notify_tunnel_url_change');
$notice = $noticeStart === false ? '' : substr($functions, $noticeStart);
$sendPosition = strpos($notice, 'send_email_notification(');
$recordPosition = strpos($notice, 'tunnel_last_notified_date\' => $today');
$assert($sendPosition !== false && $recordPosition !== false && $sendPosition < $recordPosition,
    'Day marker must be recorded only after the send attempt, so failures are retried');

// Per-recipient day check: a sent row today skips, a failed row does not, and
// yesterday's successful row does not suppress today's notice.
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$yesterday = (new DateTimeImmutable($today . ' 12:00:00', new DateTimeZone('Asia/Manila')))
    ->modify('-1 day')->format('Y-m-d H:i:s');
$log = new PDO('sqlite::memory:');
$log->exec('CREATE TABLE email_delivery_log (
    email_log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient TEXT NOT NULL,
    subject TEXT NOT NULL,
    status TEXT NOT NULL,
    provider TEXT NULL,
    error_message TEXT NULL,
    created_at TEXT NOT NULL
)');
$insert = $log->prepare('INSERT INTO email_delivery_log (recipient, subject, status, created_at) VALUES (?, ?, ?, ?)');
$insert->execute(['admin@example.com', 'Store remote access URL updated', 'sent', $today . ' 08:00:00']);
$insert->execute(['failed@example.com', 'Store remote access URL updated', 'failed', $today . ' 08:00:00']);
$insert->execute(['old@example.com', 'Store remote access URL updated', 'sent', $yesterday]);

$assert(tunnel_notice_already_sent($log, 'admin@example.com', 'Store remote access URL updated', $today),
    'A recipient already sent today must be skipped');
$assert(!tunnel_notice_already_sent($log, 'failed@example.com', 'Store remote access URL updated', $today),
    'A failed delivery must stay retryable the same day');
$assert(!tunnel_notice_already_sent($log, 'old@example.com', 'Store remote access URL updated', $today),
    'A previous day send must not suppress today\'s notice');

$missingLog = new PDO('sqlite::memory:');
$assert(!tunnel_notice_already_sent($missingLog, 'admin@example.com', 'Store remote access URL updated', $today),
    'A missing delivery log must not block the first notice');

if ($failures) {
    fwrite(STDERR, "Tunnel URL notice contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Tunnel URL notice contract: passed\n";
