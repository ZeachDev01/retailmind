<?php
// Pure fixture coverage for restore compatibility (no database required).
$private = sys_get_temp_dir() . '/rm-compat-test-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
require_once __DIR__ . '/../bootstrap/app.php';
$_ENV['BACKUP_STORAGE_PATH'] = $private;
require_once __DIR__ . '/../includes/backup.php';

use App\Backup\Compatibility\RestoreCompatibilityRegistry;
use App\Backup\Compatibility\UnsupportedPackageStructure;
use App\Backup\SqlBackupFormat;
use App\Backup\StorePackageFormat;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$wrap = static function (string $body): string {
    $body = SqlBackupFormat::HEADER . "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" . $body . "\nSET FOREIGN_KEY_CHECKS=1;";
    return $body . "\n-- SHA256: " . hash('sha256', $body) . "\n";
};
$sqlFor = static function (array $schema, array $rows) use ($wrap): string {
    $body = '';
    foreach ($schema as $table => $definition) {
        $quoted = '`' . $table . '`';
        $body .= 'DROP TABLE IF EXISTS ' . $quoted . ";\n" . $definition['ddl'] . ";\n";
        foreach ($rows[$table] ?? [] as $record) {
            $columns = implode('`, `', $definition['columns']);
            $values = implode(', ', array_map(
                static fn(?string $value): string => $value === null ? 'NULL' : "X'" . bin2hex($value) . "'",
                array_map(static fn(string $column): ?string => $record[$column] ?? null, $definition['columns'])
            ));
            $body .= 'INSERT INTO ' . $quoted . ' (`' . $columns . '`) VALUES (' . $values . ");\n";
        }
    }
    return $wrap($body);
};
$table = static fn(string $name, array $columns): array => ['ddl' => "CREATE TABLE `{$name}` (" . implode(",\n  ", array_map(
    static fn(string $column): string => "`{$column}` text DEFAULT NULL", $columns
)) . "\n) ENGINE=InnoDB", 'columns' => $columns];

$current = [
    'roles' => $table('roles', ['role_id', 'role_name']),
    'users' => $table('users', ['user_id', 'first_name', 'last_name', 'display_name']),
    'products' => $table('products', ['product_id', 'product_name']),
    'held_sales' => $table('held_sales', ['held_sale_id']),
    'held_sale_items' => $table('held_sale_items', ['held_sale_item_id', 'held_sale_id', 'cart_key', 'line_position', 'product_id', 'selling_form', 'pack_size', 'product_name', 'quantity', 'price', 'server_price', 'stock', 'reorder_level', 'safety_stock', 'sku', 'barcode', 'product_image']),
    'cashier_shifts' => $table('cashier_shifts', ['shift_id']),
    'cashier_shift_payment_totals' => $table('cashier_shift_payment_totals', ['shift_id', 'payment_type', 'amount']),
    'schema_migrations' => $table('schema_migrations', ['migration_key', 'description', 'applied_at']),
];
ksort($current, SORT_STRING);

$older = $current;
unset($older['held_sale_items'], $older['cashier_shift_payment_totals']);
$older['branches'] = $table('branches', ['branch_id', 'branch_name']);
$older['users']['columns'] = ['user_id', 'full_name', 'branch_id'];
$older['products']['columns'] = ['product_id', 'branch_id', 'product_name'];
$older['held_sales']['columns'] = ['held_sale_id', 'cart_json'];
$older['cashier_shifts']['columns'] = ['shift_id', 'payment_totals'];
ksort($older, SORT_STRING);

$currentRows = [
    'roles' => [['role_id' => '1', 'role_name' => 'super_admin']],
    'users' => [['user_id' => '1', 'first_name' => 'Old', 'last_name' => 'Name', 'display_name' => 'Old Name']],
    'products' => [['product_id' => '10', 'product_name' => 'Widget']],
    'held_sales' => [['held_sale_id' => '1']],
    'held_sale_items' => [],
    'cashier_shifts' => [['shift_id' => '1']],
    'cashier_shift_payment_totals' => [],
    'schema_migrations' => [],
];
$olderRows = [
    'roles' => [['role_id' => '1', 'role_name' => 'super_admin']],
    'users' => [['user_id' => '1', 'full_name' => 'Old Name', 'branch_id' => '7']],
    'products' => [['product_id' => '10', 'branch_id' => '7', 'product_name' => 'Widget']],
    'held_sales' => [
        ['held_sale_id' => '1', 'cart_json' => '[{"product_id":10,"selling_form":"piece","name":"Widget","qty":2,"price":"5.00"}]'],
        ['held_sale_id' => '2', 'cart_json' => null],
    ],
    'cashier_shifts' => [['shift_id' => '1', 'payment_totals' => '{"cash":"10.00","card":"5.00"}']],
    'branches' => [['branch_id' => '7', 'branch_name' => 'Main']],
    'schema_migrations' => [],
];

$olderKeys = ['202610250001_retire_unused_storage', '202611010001_retire_branches', '202611010002_remove_branch_id', '202611010004_normalize_held_sale_items', '202611010005_users_atomic_names', '202611010006_normalize_shift_payment_totals'];
$currentKeys = array_merge($olderKeys, ['202611100001_backup_keyring_and_history']);
$registry = static fn(): RestoreCompatibilityRegistry => new RestoreCompatibilityRegistry(null, $currentKeys);

try {
    // Current packages keep today's behaviour exactly.
    $currentSql = $sqlFor($current, $currentRows);
    $currentPackage = StorePackageFormat::encode($currentSql, [], $current, str_repeat('a', 32), 'mariadb',
        ['migrations' => $currentKeys, 'key_fingerprint' => hash('sha256', \App\Backup\BackupKeyStore::current())]);
    $decoded = StorePackageFormat::decode($currentPackage, $current, $registry(), $currentKeys);
    $assert($decoded['compatibility']['status'] === 'current', 'A package from the current structure needs no adapter');
    $assert($decoded['compatibility']['adapter'] === null && $decoded['compatibility']['missing_migrations'] === [], 'Current packages expose no adapter or missing migrations');
    $assert($decoded['rows']['users'] === $currentRows['users']
        && $decoded['rows']['products'] === $currentRows['products']
        && $decoded['rows']['held_sales'] === $currentRows['held_sales'], 'Current package rows are returned unchanged');
    $assert($decoded['source_schema'] === $current, 'The producer schema is extracted from the package');

    // A backup taken before this installation gained a column is restorable
    // without an adapter: the destination structure wins and the new column is blank.
    $behind = $current;
    $behind['users'] = $table('users', ['user_id', 'first_name', 'last_name']);
    $behindRows = $currentRows;
    $behindRows['users'] = [['user_id' => '1', 'first_name' => 'Old', 'last_name' => 'Name']];
    $behindSql = $sqlFor($behind, $behindRows);
    $behindPackage = StorePackageFormat::encode($behindSql, [], $behind, str_repeat('8', 32), 'mariadb',
        ['migrations' => $currentKeys]);
    $additive = StorePackageFormat::decode($behindPackage, $current, $registry(), $currentKeys);
    $additiveReport = $additive['compatibility'];
    $assert($additiveReport['status'] === 'upgradable', 'Destination-only additions are upgradable: ' . $additiveReport['message']);
    $assert($additiveReport['adapter'] === null, 'Safe additions need no structure adapter');
    $assert($additiveReport['changes']['columns_added'] === ['users.display_name'], 'The report names the newer destination column');
    $assert($additiveReport['missing_migrations'] === [], 'A behind package reports no missing migration');
    $assert($additive['rows']['users'] === $behindRows['users'], 'A behind package keeps its rows unchanged');

    // An older package is classified and adapted before it reaches replacement.
    $olderSql = $sqlFor($older, $olderRows);
    $olderPackage = StorePackageFormat::encode($olderSql, [], $older, str_repeat('b', 32), 'mariadb',
        ['migrations' => $olderKeys, 'key_fingerprint' => hash('sha256', \App\Backup\BackupKeyStore::current())]);
    $upgraded = StorePackageFormat::decode($olderPackage, $current, $registry(), $currentKeys);
    $report = $upgraded['compatibility'];
    $assert($report['status'] === 'upgradable', 'The pre-branch-retirement structure is upgradable: ' . $report['message']);
    $assert($report['adapter'] === 'pre-202611010001', 'The registered older-structure adapter is selected');
    $assert($report['changes']['tables_removed'] === ['branches'], 'The adapter covers the retired branches table');
    $assert(in_array('users.full_name', $report['changes']['columns_removed'], true), 'The adapter reports the renamed full_name column');
    $assert(in_array('held_sales.cart_json', $report['changes']['columns_removed'], true), 'The adapter reports the normalized cart column');
    $assert($upgraded['rows']['users'][0]['display_name'] === 'Old Name', 'full_name becomes display_name');
    $assert(!isset($upgraded['rows']['users'][0]['full_name']) && !isset($upgraded['rows']['users'][0]['branch_id']), 'Dropped user columns are stripped');
    $assert(!isset($upgraded['rows']['products'][0]['branch_id']), 'Dropped product columns are stripped');
    $assert(!isset($upgraded['rows']['branches']), 'Retired tables are removed from the replacement');
    $assert(count($upgraded['rows']['held_sale_items']) === 1, 'Held sale carts become atomic rows');
    $assert($upgraded['rows']['held_sale_items'][0]['held_sale_id'] === '1'
        && $upgraded['rows']['held_sale_items'][0]['line_position'] === '0'
        && $upgraded['rows']['held_sale_items'][0]['product_name'] === 'Widget'
        && $upgraded['rows']['held_sale_items'][0]['quantity'] === '2', 'Held sale line values follow the normalizing migration');
    $assert(count($upgraded['rows']['cashier_shift_payment_totals']) === 2, 'Shift payment totals become atomic rows');
    $assert(array_column($upgraded['rows']['cashier_shift_payment_totals'], 'payment_type') === ['cash', 'card'], 'Payment totals keep the migration ordering');
    $assert(!isset($upgraded['rows']['cashier_shifts'][0]['payment_totals']), 'The JSON payment totals column is stripped');
    $assert($upgraded['rows']['schema_migrations'] === [], 'Captured packages carry no migration ledger rows');
    $assert($report['migrations'] === $olderKeys, 'The compatibility report exposes the source migration keys');

    // Unknown older structure is never guessed at.
    $unknown = $older;
    $unknown['users']['columns'][] = 'mystery_column';
    $unknownRows = $olderRows;
    $unknownRows['users'][0]['mystery_column'] = 'surprise';
    $unknownSql = $sqlFor($unknown, $unknownRows);
    $unknownPackage = StorePackageFormat::encode($unknownSql, [], $unknown, str_repeat('c', 32), 'mariadb',
        ['migrations' => $olderKeys]);
    try {
        StorePackageFormat::decode($unknownPackage, $current, $registry(), $currentKeys);
        $assert(false, 'Unknown older columns must be rejected');
    } catch (UnsupportedPackageStructure $exception) {
        $unknownReport = $exception->report();
        $assert($unknownReport['status'] === 'unsupported', 'Unknown older structures are unsupported');
        $assert(str_contains($unknownReport['message'], 'users.mystery_column'), 'The rejection names the unknown table.column');
    }

    // A package that expects migrations this installation does not know is newer.
    $newerKeys = array_merge($currentKeys, ['209901010001_future_format']);
    $newerPackage = StorePackageFormat::encode($currentSql, [], $current, str_repeat('d', 32), 'mariadb',
        ['migrations' => $newerKeys]);
    try {
        StorePackageFormat::decode($newerPackage, $current, $registry(), $currentKeys);
        $assert(false, 'Newer packages must be rejected');
    } catch (UnsupportedPackageStructure $exception) {
        $newerReport = $exception->report();
        $assert($newerReport['status'] === 'unsupported' && $newerReport['newer_format'] === true, 'Newer manifests are flagged');
        $assert($newerReport['missing_migrations'] === ['209901010001_future_format'], 'The rejection names the newer migration');
        $assert(str_contains($newerReport['message'], '209901010001_future_format'), 'The newer-format message names the migration');
    }

    // Two adapters claiming one structure fail closed instead of guessing.
    $ambiguous = new RestoreCompatibilityRegistry([
        new \App\Backup\Compatibility\Adapters\PreBranchRetirementAdapter(),
        new \App\Backup\Compatibility\Adapters\PreBranchRetirementAdapter(),
    ], $currentKeys);
    try {
        StorePackageFormat::decode($olderPackage, $current, $ambiguous, $currentKeys);
        $assert(false, 'Ambiguous adapters must fail closed');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'ambiguous'), 'Ambiguous adapter selection is reported');
    }

    // Re-signed JSON cannot smuggle a different producer map past the hash check.
    $key = \App\Backup\BackupKeyStore::current();
    $seal = static function (array $manifest) use ($key): string {
        $salt = str_repeat('s', 16); $iv = str_repeat('i', 12);
        $prefix = "RMSTORE2\n" . hash('sha256', $key) . $salt . $iv;
        $cipher = openssl_encrypt(json_encode($manifest, JSON_THROW_ON_ERROR), 'aes-256-gcm', hex2bin($key),
            OPENSSL_RAW_DATA, $iv, $tag, $prefix, 16);
        return $prefix . $tag . $cipher;
    };
    $manifest = [
        'format' => 'RMSTORE1', 'sql_format' => 'RMSQL2', 'schema' => hash('sha256', json_encode($older, JSON_THROW_ON_ERROR)),
        'package_id' => str_repeat('e', 32), 'origin_id' => str_repeat('f', 32), 'snapshot_at' => '2026-01-01 00:00:00',
        'php' => PHP_VERSION, 'openssl' => OPENSSL_VERSION_TEXT, 'database' => 'mariadb',
        'migrations' => $currentKeys,
        'members' => [['path' => 'database.sql', 'size' => strlen($currentSql), 'content' => base64_encode($currentSql)]],
    ];
    try {
        StorePackageFormat::decode($seal($manifest), $current, $registry(), $currentKeys);
        $assert(false, 'A mismatched producer hash must be rejected');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'fingerprint'), 'The hash mismatch is reported as a schema fingerprint failure');
    }

    // Read-only preflight must surface an unsupported review instead of a
    // generic error, so the unsupported chip branch stays reachable.
    if (getenv('RUN_DB_TESTS') === '1') {
        $config = require __DIR__ . '/../config/database.php';
        $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
        $server = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database = 'rm_preflight_' . bin2hex(random_bytes(6));
        $futureKey = '209901010001_future_format';
        try {
            $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $destination = new PDO($dsn . ';dbname=' . $database, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $destination->exec('CREATE TABLE roles (role_id INT PRIMARY KEY, role_name VARCHAR(30)) ENGINE=InnoDB');
            $destination->exec("INSERT INTO roles VALUES (1, 'super_admin')");
            $destination->exec('CREATE TABLE users (user_id INT PRIMARY KEY, username VARCHAR(100), password_hash VARCHAR(255), status VARCHAR(20), role_id INT) ENGINE=InnoDB');
            $password = 'Preflight@2026';
            $destination->prepare("INSERT INTO users VALUES (1, 'preflight', ?, 'active', 1)")
                ->execute([password_hash($password, PASSWORD_DEFAULT)]);
            $destination->exec('CREATE TABLE schema_migrations (migration_key VARCHAR(100) PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');

            $futureSchema = ['users' => $table('users', ['user_id', 'username', 'password_hash', 'status', 'role_id'])];
            $futurePackage = StorePackageFormat::encode(
                $sqlFor($futureSchema, ['users' => []]),
                [],
                $futureSchema,
                str_repeat('1', 32),
                'mariadb',
                ['migrations' => [$futureKey]]
            );
            $futurePath = $private . '/future-preflight.rmpkg';
            file_put_contents($futurePath, $futurePackage);
            $policy = new \App\Authorization\RoleCapabilityPolicy();
            $restorer = new \App\Backup\DatabaseRestoreService(
                $destination,
                $policy,
                new \App\Backup\DatabaseBackupService($destination, $policy)
            );
            $preflight = $restorer->preflight(1, 'super_admin', $futurePath, 'future-preflight.rmpkg', $password);
            $assert($preflight['valid'] === false, 'Unsupported preflight must be invalid');
            $assert(($preflight['compatibility']['status'] ?? '') === 'unsupported', 'Unsupported preflight keeps the compatibility report');
            $assert(in_array($futureKey, $preflight['compatibility']['missing_migrations'] ?? [], true), 'Unsupported preflight names the newer migration');
            $assert($preflight['conflicts'] === [] && $preflight['files'] === 0 && $preflight['bytes'] === 0 && $preflight['snapshot_at'] === '',
                'Unsupported preflight returns empty conflicts, files, bytes, and snapshot');
        } finally {
            $server->exec("DROP DATABASE IF EXISTS `{$database}`");
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    exit(1);
}
echo "Restore compatibility: passed\n";
