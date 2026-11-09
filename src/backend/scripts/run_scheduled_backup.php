<?php
// RetailMind Weekly Scheduled Backup (#460).
//
// Runs from the hourly Windows task. Exit codes: 0 success or not due,
// 1 failure, 2 busy/skipped so the task log distinguishes a retry from a fault.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $exception): void {
    error_log((string)$exception);
    fwrite(STDERR, 'Scheduled backup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
});
require_once dirname(__DIR__) . '/includes/functions.php';
$pdo = App\Core\Database::connection();
$clock ??= new App\Attention\SystemClock();

try {
    $result = (new App\Backup\ScheduledBackupService($pdo, $clock))->runDue();
    echo ($result['outcome'] ?? 'unknown') . ': ' . ($result['message'] ?? '') . PHP_EOL;
    if (($result['outcome'] ?? '') === App\Backup\ScheduledBackupService::OUTCOME_FAILED) {
        exit(1);
    }
    if (($result['outcome'] ?? '') === App\Backup\ScheduledBackupService::OUTCOME_SKIPPED) {
        exit(2);
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Scheduled backup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
