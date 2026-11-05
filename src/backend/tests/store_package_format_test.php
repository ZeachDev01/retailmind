<?php
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/backup.php';
use App\Backup\StorePackageFormat;
use App\Backup\SqlBackupFormat;
$assert = static function(bool $yes, string $why): void { if (!$yes) throw new RuntimeException($why); };
$reject = static function(callable $fn, string $why) use ($assert): void { try { $fn(); } catch (Throwable) { return; } $assert(false, $why); };
ini_set('memory_limit', '128M');
StorePackageFormat::assertRuntime();
$assert((int)ini_get('memory_limit') >= 512 || ini_get('memory_limit') === '-1', 'Package operations provision their required memory even when per-directory settings are ignored');
$ddl = 'CREATE TABLE `items` (`id` int, `note` text) ENGINE=InnoDB';
$schema = ['items' => ['ddl' => $ddl, 'columns' => ['id', 'note']]];
$body = SqlBackupFormat::HEADER . "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nDROP TABLE IF EXISTS `items`;\n{$ddl};\nINSERT INTO `items` (`id`, `note`) VALUES (X'31', X'c3b1ff00');\nSET FOREIGN_KEY_CHECKS=1;";
$sql = $body . "\n-- SHA256: " . hash('sha256', $body) . "\n";
$directory = sys_get_temp_dir() . '/rm-backup-key-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $_ENV['BACKUP_STORAGE_PATH'] = $directory . '/source';
    $key = \App\Backup\BackupKeyStore::current();
    $package = StorePackageFormat::encode($sql, [], $schema);
    $assert(!str_contains($package, 'CREATE TABLE') && !str_contains($package, $key), 'Stolen package exposes neither SQL nor key');
    $result = StorePackageFormat::decode($package, $schema);
    $assert($result['sql'] === $sql, 'Unicode and binary SQL round trip unchanged');
    $assert($result['rows']['items'][0]['note'] === "\xc3\xb1\xff\x00", 'Rows expose decoded binary values without executing SQL');
    $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAAAgCAIAAAAt/+nTAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAU0lEQVRYhe3YsQkAIAwFUb+4/8pxArEQPAL3WpscFoGkqkZnkx7glQE0A2gG0NbpIcnPOa5O+6r9DxhAM4BmAM0AmgE0A2gG0OJdCGYAzQCaAbQNoAsJO3lArLgAAAAASUVORK5CYII=';
    $logoRows = ['store_settings' => ['setting_key' => 'receipt_logo', 'setting_value' => $logo]];
    foreach (['sale_receipt_details', 'refund_receipt_details', 'gcash_transactions'] as $table) {
        $column = $table === 'gcash_transactions' ? 'receipt_details_json' : 'details_json';
        $logoRows[$table] = [$column => json_encode(['store' => ['logo' => $logo]], JSON_THROW_ON_ERROR)];
    }
    $logoSchema = [];
    $logoBody = SqlBackupFormat::HEADER . "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    foreach ($logoRows as $table => $row) {
        $ddl = 'CREATE TABLE `' . $table . '` (' . implode(', ', array_map(static fn($column) => '`' . $column . '` text', array_keys($row))) . ') ENGINE=InnoDB';
        $logoSchema[$table] = ['ddl' => $ddl, 'columns' => array_keys($row)];
        $logoBody .= 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $ddl . ";\nINSERT INTO `" . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES ('
            . implode(', ', array_map(static fn($value) => "X'" . bin2hex($value) . "'", array_values($row))) . ");\n";
    }
    $logoBody .= 'SET FOREIGN_KEY_CHECKS=1;';
    $logoSql = $logoBody . "\n-- SHA256: " . hash('sha256', $logoBody) . "\n";
    $restored = StorePackageFormat::decode(StorePackageFormat::encode($logoSql, [], $logoSchema), $logoSchema);
    foreach ($logoRows as $table => $row) {
        $assert($restored['rows'][$table][0] === $row, 'Backup restores current and historical receipt logos without external uploads: ' . $table);
    }
    $reject(fn() => StorePackageFormat::decode(substr($package, 0, -1), $schema), 'Truncated package rejected');
    $modified = $package; $modified[strlen($modified)-1] = chr(ord($modified[strlen($modified)-1]) ^ 1);
    $reject(fn() => StorePackageFormat::decode($modified, $schema), 'Authentication rejects altered ciphertext');
    $reject(fn() => StorePackageFormat::decode($sql, $schema), 'Plain SQL rejected');
    $reject(fn() => StorePackageFormat::encode($sql, ['images/../evil.php' => '<?php'], $schema), 'Traversal rejected');
    $reject(fn() => StorePackageFormat::encode($sql, ['images/evil.php' => '<?php'], $schema), 'Executable member rejected');
    // Authenticated hostile input represents an untrusted holder of the recovery key.
    $manifest = $result['manifest'];
    $member = ['path' => 'database.sql', 'size' => strlen($sql), 'content' => base64_encode($sql)];
    $manifest['members'] = [$member];
    $seal = static function(array $manifest) use ($key): string {
        $salt = str_repeat('s', 16); $iv = str_repeat('i', 12); $prefix = "RMSTORE2\n" . hash('sha256', $key) . $salt . $iv;
        $key = hex2bin($key);
        $cipher = openssl_encrypt(json_encode($manifest, JSON_THROW_ON_ERROR), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $prefix, 16);
        return $prefix . $tag . $cipher;
    };
    $bad = [];
    $case = $manifest; $case['members'][] = $member; $bad['duplicate database member'] = $case;
    $case = $manifest; $case['members'] = []; $bad['missing database'] = $case;
    $case = $manifest; $case['members'][0]['link'] = '../outside'; $bad['linked member'] = $case;
    $case = $manifest; $case['schema'] = str_repeat('0', 64); $bad['incompatible schema'] = $case;
    $case = $manifest; $case['format'] = 'RMSTORE999'; $bad['incompatible version'] = $case;
    $case = $manifest; $case['members'][0]['size'] = 33554433; $bad['content resource limit'] = $case;
    $case = $manifest; $case['members'][0]['size']--; $bad['false declared size'] = $case;
    $case = $manifest; $case['members'][0]['content'] = base64_encode('DROP DATABASE store;'); $case['members'][0]['size'] = 20; $bad['arbitrary SQL'] = $case;
    foreach (['../outside.png', '/images/x.png', 'C:/images/x.png', 'images/linked.png/else', 'images/code.php', 'secrets/.env'] as $path) {
        $case = $manifest; $case['members'][] = ['path' => $path, 'size' => 4, 'content' => base64_encode('evil')]; $bad[$path] = $case;
    }
    foreach ($bad as $why => $case) $reject(fn() => StorePackageFormat::decode($seal($case), $schema), $why . ' must reject before any replacement');
    $reject(fn() => StorePackageFormat::decode(str_repeat('x', StorePackageFormat::MAX_BYTES + 1), $schema), 'Oversize input rejects before derivation');
    $key = \App\Backup\BackupKeyStore::current();
    $assert($key === \App\Backup\BackupKeyStore::current(), 'Backup key is stable across requests');
    $automatic = StorePackageFormat::encode($sql, [], $schema);
    $assert(str_starts_with($automatic, "RMSTORE2\n"), 'Automatic encryption identifies its recovery key');
    $assert(!str_contains($automatic, $sql) && !str_contains($automatic, $key), 'Encrypted backup exposes neither SQL nor key');
    $assert(StorePackageFormat::decode($automatic, $schema)['sql'] === $sql, 'Automatic restore needs no login-derived encryption secret');
    $tampered = $automatic;
    $tampered[strlen($tampered)-1] = chr(ord($tampered[strlen($tampered)-1]) ^ 1);
    $reject(fn() => StorePackageFormat::decode($tampered, $schema), 'Automatic encryption rejects damaged packages');
    $legacyPrefix = "RMSTORE1\n" . str_repeat('s', 16) . str_repeat('i', 12);
    $legacyKey = hash_pbkdf2('sha256', 'legacy-backup-passphrase', str_repeat('s', 16), 600000, 32, true);
    $legacyCipher = openssl_encrypt(json_encode($manifest, JSON_THROW_ON_ERROR), 'aes-256-gcm', $legacyKey, OPENSSL_RAW_DATA, str_repeat('i', 12), $legacyTag, $legacyPrefix, 16);
    $reject(fn() => StorePackageFormat::decode($legacyPrefix . $legacyTag . $legacyCipher, $schema), 'Legacy passphrase packages are no longer supported');
    $_ENV['BACKUP_STORAGE_PATH'] = $directory . '/destination';
    $destinationKey = \App\Backup\BackupKeyStore::current();
    $reject(fn() => StorePackageFormat::decode($automatic, $schema), 'Another server cannot decrypt without the recovery key');
    $reject(fn() => \App\Backup\BackupKeyStore::import('../invalid'), 'Invalid recovery keys are rejected');
    \App\Backup\BackupKeyStore::import($key . "\n");
    $assert(StorePackageFormat::decode($automatic, $schema)['sql'] === $sql, 'Imported recovery key enables cross-server restoration');
    $assert(\App\Backup\BackupKeyStore::current() === $destinationKey, 'Import preserves keys for existing destination backups');
} finally {
    foreach (glob($directory . '/*') as $folder) {
        foreach (glob($folder . '/*') as $file) unlink($file);
        rmdir($folder);
    }
    rmdir($directory);
}
echo "Encrypted Store package format: passed\n";
