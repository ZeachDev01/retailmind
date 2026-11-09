<?php
// Backup Keyring: additive imports, promotion with archive, blocked primary
// deletion, export by fingerprint, and a metadata-only private manifest.
// Issue #460.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/backup.php';

use App\Backup\BackupKeyStore;
use App\Backup\SqlBackupFormat;
use App\Backup\StorePackageFormat;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function (callable $fn, string $message) use ($assert): void {
    try { $fn(); } catch (Throwable) { return; }
    $assert(false, $message);
};

$root = sys_get_temp_dir() . '/rm-backup-keyring-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
try {
    $_ENV['BACKUP_STORAGE_PATH'] = $root . '/store';
    $store = $root . '/store';

    $primary = BackupKeyStore::current();
    $primaryFingerprint = BackupKeyStore::currentFingerprint();
    $assert($primaryFingerprint === hash('sha256', $primary), 'The current fingerprint identifies the primary key');
    $entries = BackupKeyStore::list();
    $assert(count($entries) === 1 && $entries[0]['fingerprint'] === $primaryFingerprint && $entries[0]['kind'] === 'primary',
        'A fresh keyring lists exactly the primary key');

    // A package made before promotion must stay readable afterwards.
    $ddl = 'CREATE TABLE `items` (`id` int, `note` text) ENGINE=InnoDB';
    $schema = ['items' => ['ddl' => $ddl, 'columns' => ['id', 'note']]];
    $body = SqlBackupFormat::HEADER . "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
        . "DROP TABLE IF EXISTS `items`;\n{$ddl};\nINSERT INTO `items` (`id`, `note`) VALUES (X'31', X'c3b1ff00');\nSET FOREIGN_KEY_CHECKS=1;";
    $sql = $body . "\n-- SHA256: " . hash('sha256', $body) . "\n";
    $oldPackage = StorePackageFormat::encode($sql, [], $schema);

    $imported = bin2hex(random_bytes(32));
    $importedFingerprint = hash('sha256', $imported);
    $first = BackupKeyStore::import($imported . "\n");
    $assert($first === ['fingerprint' => $importedFingerprint, 'status' => 'added'], 'Import adds a new key');
    $second = BackupKeyStore::import($imported . "\n");
    $assert($second === ['fingerprint' => $importedFingerprint, 'status' => 'present'], 'Import is idempotent');
    $assert(count(glob($store . '/backup-key-*.key') ?: []) === 1, 'Import keeps one material file per key');
    $assert(BackupKeyStore::import($primary . "\n")['status'] === 'present', 'Importing the primary key is a no-op');
    $assert(BackupKeyStore::find($importedFingerprint) === $imported, 'An imported key resolves by fingerprint');

    $promotion = BackupKeyStore::promote($importedFingerprint);
    $assert(BackupKeyStore::primaryFingerprint() === $importedFingerprint, 'Promotion switches the primary key');
    $again = BackupKeyStore::promote($importedFingerprint);
    $assert($again['archived'] === null, 'Promoting the current primary key is a no-op');
    $assert(BackupKeyStore::current() === $imported, 'New backups use the promoted key');
    $assert(BackupKeyStore::currentFingerprint() === $importedFingerprint, 'The current fingerprint follows promotion');
    $assert(($promotion['archived'] ?? null) === $primaryFingerprint, 'Promotion reports the archived primary');
    $assert(is_file($store . '/backup-key-' . $primaryFingerprint . '.key'), 'The previous primary material is archived, not deleted');
    $assert(StorePackageFormat::decode($oldPackage, $schema)['sql'] === $sql, 'Packages made with the archived key still decode');

    $entries = array_column(BackupKeyStore::list(), null, 'fingerprint');
    $assert(($entries[$importedFingerprint]['kind'] ?? null) === 'primary', 'The promoted key is listed as primary');
    $assert(($entries[$primaryFingerprint]['kind'] ?? null) === 'archived', 'The replaced primary is listed as archived');
    $assert(BackupKeyStore::find($primaryFingerprint) === $primary, 'An archived key resolves by fingerprint');

    $reject(fn() => BackupKeyStore::delete($importedFingerprint), 'The primary key cannot be deleted');
    $reject(fn() => BackupKeyStore::delete(str_repeat('0', 64)), 'An unknown key cannot be deleted');

    BackupKeyStore::delete($primaryFingerprint);
    $assert(!is_file($store . '/backup-key-' . $primaryFingerprint . '.key'), 'Deleting a key removes its material');
    $reject(fn() => BackupKeyStore::find($primaryFingerprint), 'A deleted key no longer resolves');
    $assert(count(BackupKeyStore::list()) === 1, 'A deleted key leaves the keyring');

    $extra = bin2hex(random_bytes(32));
    $extraFingerprint = hash('sha256', $extra);
    BackupKeyStore::import($extra . "\n");
    BackupKeyStore::delete($extraFingerprint);
    $assert(!is_file($store . '/backup-key-' . $extraFingerprint . '.key'), 'An imported key can be deleted');

    $manifest = $store . '/backup-keyring.json';
    $assert(is_file($manifest), 'Mutations keep a metadata manifest');
    $json = (string)file_get_contents($manifest);
    $assert(str_contains($json, $importedFingerprint), 'The manifest records fingerprints');
    foreach ([$primary, $imported, $extra] as $material) {
        $assert(!str_contains($json, $material), 'The manifest never stores key material');
    }

    unlink($manifest);
    $entries = BackupKeyStore::list();
    $assert(count($entries) === 1 && $entries[0]['fingerprint'] === $importedFingerprint && $entries[0]['kind'] === 'primary',
        'Deleting the manifest rebuilds the keyring from key files');
} finally {
    foreach (glob($root . '/store/*') ?: [] as $folder) {
        foreach (glob($folder . '/*') ?: [] as $file) unlink($file);
        @rmdir($folder);
    }
    @rmdir($root . '/store');
    @rmdir($root);
}
echo "Backup keyring: passed\n";
