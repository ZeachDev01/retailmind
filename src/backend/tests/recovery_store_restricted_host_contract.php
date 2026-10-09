<?php

$autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (str_starts_with($class, $prefix)) {
            $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });
}

use App\Backup\RecoveryStore;

$project = dirname(__DIR__, 3);
putenv('BACKUP_STORAGE_PATH=');
ini_set('open_basedir', $project);

try {
    if (!RecoveryStore::admitRequest()) {
        throw new RuntimeException('The request was blocked without a recovery operation.');
    }
    if (RecoveryStore::isPaused() || RecoveryStore::epoch() !== 'initial') {
        throw new RuntimeException('Web-only recovery state is incorrect.');
    }
    try {
        RecoveryStore::directory();
        throw new RuntimeException('Database Backup storage unexpectedly became available.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'unavailable')) {
            throw $exception;
        }
    }
    echo "Restricted-host web startup: passed\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Restricted-host web startup: failed: {$exception->getMessage()}\n");
    exit(1);
}
