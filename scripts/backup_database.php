<?php
require_once dirname(__DIR__) . '/assets/bootstrap/app.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/backup.php';

$directory = dirname(__DIR__) . '/storage/backups';
$filename = 'scheduled-' . date('Ymd-His') . '.sql';
try {
    $result = create_database_backup($pdo, $directory . '/' . $filename);
    record_backup_history($pdo, $filename, 'scheduled', (int)$result['size'], 'completed', null);
    echo $result['path'] . PHP_EOL;
} catch (Throwable $e) {
    record_backup_history($pdo, $filename, 'scheduled', 0, 'failed', null, $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
