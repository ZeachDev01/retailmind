<?php
// Story 40: backup history repairs through the normal migration path.
$root = dirname(__DIR__, 3);
$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return $contents === false ? '' : $contents;
};
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$shared = $read('src/backend/includes/backup.php');
$administratorPage = $read('src/frontend/components/administrator/database_backup.php');
$superAdministratorPage = $read('src/frontend/components/system_administrator/backup_restore.php');
$migration = $read('src/backend/database/migrations/202611100001_backup_keyring_and_history.php');
$schema = $read('src/backend/database/sql/schema.sql');

$assert(!str_contains($shared, 'ensure_backup_schema'), 'The ad-hoc backup_history bootstrap is removed');
$assert(str_contains($shared, 'function ensure_backup_storage(PDO $pdo): void'), 'The shared helper repairs backup history');
$assert(str_contains($shared, 'MigrationRunner') && str_contains($shared, 'runPending()'), 'Repair uses the normal migration runner');
$assert(str_contains($shared, 'columnExists') && str_contains($shared, 'snapshot_at') && str_contains($shared, 'key_fingerprint'),
    'Repair runs only when backup history metadata is missing');
$assert(str_contains($shared, 'static $'), 'Repair runs at most once per request');
$assert(substr_count($shared, 'function ensure_backup_storage') === 1, 'There is one shared repair helper');

foreach (['Administrator page' => $administratorPage, 'Super Administrator page' => $superAdministratorPage] as $name => $source) {
    $assert(str_contains($source, 'ensure_backup_storage($pdo)'), $name . ' repairs stale backup history before reading it');
    $assert(str_contains($source, 'database_updates.php') || str_contains($source, 'Apply Database Updates'),
        $name . ' offers an actionable database-updates message when repair fails');
}

$assert(str_contains($migration, "'202611100001_backup_keyring_and_history'"), 'The repair migration exists');
$assert(str_contains($migration, 'addColumnIfMissing'), 'The repair migration applies idempotent column updates');
foreach (['snapshot_at', 'envelope_version', 'cipher', 'requested_by_role', 'key_fingerprint', 'artifact_path', 'trigger_source', 'verified_at', 'removed_at'] as $column) {
    $assert(str_contains($migration, "'{$column}'"), 'The migration adds backup_history.' . $column);
    $assert(str_contains($schema, "`{$column}`"), 'schema.sql carries backup_history.' . $column);
}
$assert(str_contains($shared, 'function record_backup_history'), 'The history writer remains available to existing callers');
$assert(str_contains($shared, 'ensure_backup_storage($pdo)'), 'The history writer repairs the schema before writing');

if ($failures) {
    fwrite(STDERR, "Backup schema bootstrap contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Backup schema bootstrap contract: passed\n";
