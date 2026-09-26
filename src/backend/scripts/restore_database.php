<?php
// Only this explicit CLI entry point may enter an incomplete restore.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/backup.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\RecoveryStore;
use App\Services\DatabaseBackupService;
use App\Services\DatabaseRestoreService;

try {
    $password = (string)getenv('RESTORE_PASSWORD');
    putenv('RESTORE_PASSWORD');
    if ($password === '') {
        throw new RuntimeException('Set RESTORE_PASSWORD temporarily to the initiating Super Administrator password. Do not save it in .env or pass it as a command-line argument.');
    }
    $options = getopt('', ['file:']);
    $path = (string)($options['file'] ?? RecoveryStore::path('latest-safety.sql'));
    $policy = new RoleCapabilityPolicy();
    $service = new DatabaseRestoreService($pdo, $policy, new DatabaseBackupService($pdo, $policy));
    $outcome = $service->recover($path, $password);
    unset($password);
    echo $outcome['message'] . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Recovery was not completed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
