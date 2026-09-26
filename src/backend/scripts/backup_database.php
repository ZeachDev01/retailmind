<?php
// Scheduled Database Backup. It shares the encrypted capture, coordination, and
// history used by the manual Administrator/Super Administrator workflow
// (#69), so this script no longer writes a readable SQL copy to the server.
require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/backup.php';
require_once dirname(__DIR__) . '/includes/functions.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\DatabaseBackupService;

$service = new DatabaseBackupService($pdo, new RoleCapabilityPolicy());
$filename = 'scheduled-' . date('Ymd-His') . '.rmbak';
try {
    $result = $service->createForSystem();
    if (($result['status'] ?? '') !== DatabaseBackupService::OUTCOME_READY) {
        fwrite(STDERR, ($result['message'] ?? 'A backup is already in progress.') . PHP_EOL);
        exit(2);
    }
    echo $result['path'] . PHP_EOL;
} catch (Throwable $e) {
    $service->recordHistory($filename, 'scheduled', 0, 'failed', null, $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
