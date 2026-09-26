<?php
// Scheduled Database Backup uses the same unencrypted SQL capture as both roles.
// Copy the resulting private temporary file to your own archive before cleanup.
require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/backup.php';
require_once dirname(__DIR__) . '/includes/functions.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\DatabaseBackupService;

$service = new DatabaseBackupService($pdo, new RoleCapabilityPolicy());
$filename = 'scheduled-' . date('m-d-Y-His') . '.sql';
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
