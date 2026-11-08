<?php
// Contract test for Ticket #455: Super Administrator Encrypted Backup & Store Recovery.
$private = sys_get_temp_dir() . '/rm-superadmin-recovery-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
$_ENV['BACKUP_STORAGE_PATH'] = $private;

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/backup.php';

use App\Audit\ProtectedAuditRecordService;
use App\Authorization\RoleCapabilityPolicy;
use App\Backup\BackupKeyStore;
use App\Backup\DatabaseBackupService;
use App\Backup\DatabaseRestoreService;
use App\Backup\DatabaseSnapshotWriter;
use App\Backup\IdentityReconciliationService;
use App\Backup\RecoveryAuthService;
use App\Backup\RecoveryStore;
use App\Backup\SafetyCopyService;
use App\Backup\SqlBackupFormat;
use App\Backup\StorePackageFormat;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$reject = static function (callable $work, string $message) use ($assert): void {
    $rejected = false;
    try {
        $work();
    } catch (Throwable) {
        $rejected = true;
    }
    $assert($rejected, $message);
};

// -----------------------------------------------------------------------------
// Part 1: Fast Portable AES-256-GCM Backup, Table Checksums, Pre-Validation & Reopening Audit
// -----------------------------------------------------------------------------
try {
    mkdir($private, 0700, true);
    $key = BackupKeyStore::current();
    $assert(strlen($key) === 64 && ctype_xdigit($key), 'AES-256 recovery key is 256-bit hex');

    $ddlRoles = 'CREATE TABLE `roles` (`role_id` int, `role_name` text) ENGINE=InnoDB';
    $ddlUsers = 'CREATE TABLE `users` (`user_id` int, `username` text, `role_id` int) ENGINE=InnoDB';
    $schema = [
        'roles' => ['ddl' => $ddlRoles, 'columns' => ['role_id', 'role_name']],
        'users' => ['ddl' => $ddlUsers, 'columns' => ['user_id', 'username', 'role_id']],
    ];
    $body = SqlBackupFormat::HEADER
        . "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
        . "DROP TABLE IF EXISTS `roles`;\n{$ddlRoles};\n"
        . "INSERT INTO `roles` (`role_id`, `role_name`) VALUES (X'31', X'" . bin2hex('super_admin') . "');\n"
        . "DROP TABLE IF EXISTS `users`;\n{$ddlUsers};\n"
        . "INSERT INTO `users` (`user_id`, `username`, `role_id`) VALUES (X'31', X'" . bin2hex('super_root') . "', X'31');\n"
        . "SET FOREIGN_KEY_CHECKS=1;";
    $sql = $body . "\n-- SHA256: " . hash('sha256', $body) . "\n";

    // AC 1: Portable encrypted backup with AES-256-GCM and table checksums
    $package = StorePackageFormat::encode($sql, [], $schema, bin2hex(random_bytes(16)), '8.0.36');
    $assert(str_starts_with($package, "RMSTORE2\n"), 'Encrypted package carries RMSTORE2 envelope header');
    $assert(!str_contains($package, 'super_root') && !str_contains($package, $key), 'AES-256-GCM ciphertext conceals data and key');

    $decoded = StorePackageFormat::decode($package, $schema);
    $assert(isset($decoded['table_checksums']['roles'], $decoded['table_checksums']['users']), 'Manifest computes and verifies SHA-256 table checksums');
    $expectedChecksums = StorePackageFormat::computeTableChecksums($decoded['rows']);
    $assert($decoded['table_checksums'] === $expectedChecksums, 'Decoded table checksums match row-level SHA-256 checksums');

    // AC 2: Pre-restoration validator rejects tampered table checksums or incompatible schema before write ops
    $tamperedManifest = $decoded['manifest'];
    $tamperedManifest['table_checksums']['users'] = str_repeat('f', 64);
    $tamperedManifest['members'] = [['path' => 'database.sql', 'size' => strlen($sql), 'content' => base64_encode($sql)]];
    $salt = random_bytes(16);
    $iv = random_bytes(12);
    $prefix = "RMSTORE2\n" . hash('sha256', $key) . $salt . $iv;
    $cipher = openssl_encrypt(json_encode($tamperedManifest, JSON_THROW_ON_ERROR), StorePackageFormat::CIPHER, hex2bin($key), OPENSSL_RAW_DATA, $iv, $tag, $prefix, 16);
    $tamperedPackage = $prefix . $tag . $cipher;
    $reject(fn() => StorePackageFormat::decode($tamperedPackage, $schema), 'Pre-restoration validator rejects corrupted table checksum');

    $incompatibleSchema = ['roles' => $schema['roles']];
    $reject(fn() => StorePackageFormat::decode($package, $incompatibleSchema), 'Pre-restoration validator rejects incompatible schema');

    // AC 4 (SQLite unit check): Store reopening requires Super Admin/authorized auth and logs critical audit entry
    $sqlite = new PDO('sqlite::memory:');
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT)");
    $sqlite->exec("INSERT INTO roles VALUES (1, 'super_admin'), (2, 'admin'), (3, 'cashier')");
    $sqlite->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, display_name TEXT, username TEXT, role_id INTEGER, status TEXT, is_recovery_account INTEGER DEFAULT 0)");
    $sqlite->exec("INSERT INTO users VALUES (1, 'Super Admin', 'super_root', 1, 'active', 0)");
    $sqlite->exec("CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        action TEXT NOT NULL,
        category TEXT NOT NULL,
        module TEXT NULL,
        record_id INTEGER NULL,
        previous_value TEXT NULL,
        new_value TEXT NULL,
        metadata TEXT NULL,
        ip_address TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $sqlite->exec("CREATE TABLE store_write_gate (gate_key TEXT PRIMARY KEY, paused_at TEXT NULL, paused_by INTEGER NULL)");
    $sqlite->exec("INSERT INTO store_write_gate VALUES ('store_writes', '2026-11-08 10:00:00', 1)");

    $policy = new RoleCapabilityPolicy();
    $sqliteBackups = new DatabaseBackupService($sqlite, $policy, $private . '/sqlite-backups');
    $sqliteRestore = new DatabaseRestoreService($sqlite, $policy, $sqliteBackups);

    $opId = 'op-reopen-' . bin2hex(random_bytes(8));
    $superPass = 'SuperAdminReopen@2026';
    $nowIso = gmdate('c');
    $reviewState = [
        'operation_id' => $opId,
        'stage' => RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING,
        'actor_id' => 1,
        'safety_operation_id' => 'safety-123',
        'authorized_operators' => [
            [
                'user_id' => 1,
                'username' => 'super_root',
                'role' => 'super_admin',
                'password_hash' => password_hash($superPass, PASSWORD_DEFAULT),
                'is_initiator' => true,
            ],
        ],
        'stages' => [['mode' => 'incoming_restart', 'stage' => RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING]],
        'review_checks' => [
            'automated' => [
                'stock' => ['status' => 'passed', 'checked_at' => $nowIso, 'details' => 'Zero negative stock'],
                'receipt' => ['status' => 'passed', 'checked_at' => $nowIso, 'details' => 'Receipts match'],
                'upload' => ['status' => 'passed', 'checked_at' => $nowIso, 'details' => 'Uploads present'],
                'attribution' => ['status' => 'passed', 'checked_at' => $nowIso, 'details' => 'Attribution valid'],
            ],
            'human_confirmations' => [
                'stock' => ['confirmed' => true],
                'receipt' => ['confirmed' => true],
                'upload' => ['confirmed' => true],
                'attribution' => ['confirmed' => true],
            ],
        ],
    ];
    RecoveryStore::recordOperation($reviewState);
    $assert(RecoveryStore::isPaused(), 'Store is paused during operator review pending');

    // Unauthorized role/actor or bad password must be rejected
    $reject(fn() => $sqliteRestore->reopenStore(99, 'cashier', $superPass, $opId), 'Cashier cannot reopen store');
    $reject(fn() => $sqliteRestore->reopenStore(1, 'cashier', $superPass, $opId), 'Mismatched non-admin role cannot reopen store');
    $reject(fn() => $sqliteRestore->reopenStore(1, 'super_admin', 'WrongPassword!', $opId), 'Wrong Super Admin password rejected');

    // Authorized Super Administrator reopens the store
    $reopened = $sqliteRestore->reopenStore(1, 'super_admin', $superPass, $opId, 'Verified full store recovery');
    $assert($reopened['success'] === true, 'Super Administrator successfully reopens store');
    $assert(!RecoveryStore::isPaused(), 'Store pause released upon Super Administrator reopening');

    // Verify critical audit trail entry is logged and surfaced by ProtectedAuditRecordService
    $auditService = new ProtectedAuditRecordService($sqlite, $policy);
    $criticalAudits = $auditService->records('super_admin', ['critical_only' => true]);
    $assert(count($criticalAudits) === 1, 'Store reopening is captured in critical audit trail filter');
    $assert($criticalAudits[0]['action'] === 'Recovery store reopened', 'Critical audit action is Recovery store reopened');
    $assert($criticalAudits[0]['category'] === 'security', 'Audit entry category is security');
    $assert($criticalAudits[0]['module'] === 'Database Restore', 'Audit entry module is Database Restore');
    $meta = json_decode((string)$criticalAudits[0]['metadata'], true);
    $assert(($meta['reopened_by']['role'] ?? '') === 'super_admin' && ($meta['operation_id'] ?? '') === $opId, 'Audit metadata records Super Administrator identity and operation ID');
} catch (Throwable $e) {
    $failures[] = 'Portable contract failure: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Part 2: Full MySQL End-to-End Backup, Pre-Validation, Zero-Loss Restore & Reopening
// -----------------------------------------------------------------------------
if (getenv('RUN_DB_TESTS') === '1' && $failures === []) {
    $config = require __DIR__ . '/../config/database.php';
    $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $database = 'rm_t455_' . bin2hex(random_bytes(5));
    $server = null;
    try {
        $server = new PDO($dsn, $config['username'], $config['password'], $options);
        $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO($dsn . ';dbname=' . $database, $config['username'], $config['password'], $options);

        require_once __DIR__ . '/../includes/functions.php';
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (split_sql_statements((string)file_get_contents(__DIR__ . '/../database/sql/schema.sql')) as $stmt) {
            $pdo->exec($stmt);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        (new \App\Database\MigrationRunner($pdo, __DIR__ . '/../database/migrations'))->runPending();

        $superPassword = 'ThemeTest@2026';
        $hash = password_hash($superPassword, PASSWORD_DEFAULT);
        $superRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name='super_admin'")->fetchColumn();
        $adminRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name='admin'")->fetchColumn();

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DELETE FROM user_roles');
        $pdo->exec('DELETE FROM recovery_accounts');
        $pdo->exec('DELETE FROM users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $pdo->prepare("INSERT INTO users (user_id, first_name, last_name, display_name, username, email, password_hash, role_id, status, session_version, must_change_password, is_recovery_account)
            VALUES (1, 'Store', 'Admin', 'Store Admin', 'store_admin', 'admin@retailmind.local', ?, ?, 'active', 1, 0, 0),
                   (2, 'Super', 'Admin', 'Super Admin', 'super_admin', 'super@retailmind.local', ?, ?, 'active', 1, 0, 0)")
            ->execute([$hash, $adminRoleId, $hash, $superRoleId]);
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (1, ?, 1), (2, ?, 1)")
            ->execute([$adminRoleId, $superRoleId]);

        $catId = (int)$pdo->query("SELECT category_id FROM categories LIMIT 1")->fetchColumn();
        if ($catId === 0) {
            $pdo->exec("INSERT INTO categories (category_id, category_name) VALUES (1, 'General')");
            $catId = 1;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DELETE FROM sale_items');
        $pdo->exec('DELETE FROM sales');
        $pdo->exec('DELETE FROM inventory');
        $pdo->exec('DELETE FROM product_batches');
        $pdo->exec('DELETE FROM products');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $pdo->prepare("INSERT INTO products (product_id, sku, barcode, product_name, category_id, cost_price, unit_price, status) VALUES (100, 'SKU-100', 'BC-100', 'Artisan Coffee Beans', ?, 200.00, 350.00, 'active')")->execute([$catId]);
        $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (100, 30)");
        $pdo->exec("INSERT INTO sales (sale_id, cashier_id, total_amount, payment_method) VALUES (501, 1, 700.00, 'cash')");
        $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal) VALUES (901, 501, 100, 2, 350.00, 700.00)");

        $uploadDir = $private . '/uploads';
        mkdir($uploadDir . '/images', 0700, true);
        mkdir($uploadDir . '/profile-images', 0700, true);

        $policy = new RoleCapabilityPolicy();
        $backupService = new DatabaseBackupService($pdo, $policy, $private . '/artifacts', $pdo, $uploadDir);
        $restoreService = new DatabaseRestoreService($pdo, $policy, $backupService, $uploadDir);

        // 1. One-click portable database backup generation with encryption and checksums
        $created = $backupService->create(2, 'super_admin');
        $assert($created['status'] === DatabaseBackupService::OUTCOME_READY, 'MySQL one-click backup succeeds');
        $assert($created['cipher'] === 'aes-256-gcm', 'Backup uses AES-256-GCM encryption');
        $assert(strlen($created['checksum']) === 64 && $created['checksum'] === hash_file('sha256', $created['path']), 'Backup generates verified SHA-256 package checksum');
        $assert(!empty($created['table_checksums']['products']) && !empty($created['table_checksums']['sales']), 'Backup generates per-table SHA-256 checksums');

        // 2. Pre-restoration validator verifies backup integrity, schema compatibility, and table checksums
        $validation = $restoreService->validatePreRestoration(2, 'super_admin', $created['path'], $created['filename'], $superPassword);
        $assert($validation['integrity_verified'] === true, 'Pre-restoration validator confirms package integrity');
        $assert($validation['schema_compatible'] === true, 'Pre-restoration validator confirms schema compatibility');
        $assert($validation['table_checksums_verified'] === true, 'Pre-restoration validator confirms table checksums');
        $assert($validation['replacement_authorized'] === false, 'Pre-restoration validator never executes write operations');

        $expectedProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $expectedSales = (int)$pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();

        // Mutate operational state to verify restore recovers everything with zero records lost or orphaned
        $pdo->exec("UPDATE products SET product_name = 'MUTATED_NAME' WHERE product_id = 100");
        $pdo->exec("UPDATE inventory SET quantity_on_hand = 4 WHERE product_id = 100");

        // 3. Safe store restore procedure recovers operational state with zero records lost or orphaned
        $decisions = [
            1 => ['action' => 'link', 'destination_id' => 1],
            2 => ['action' => 'historical'],
        ];
        $restoreOutcome = $restoreService->startIncoming(2, 'super_admin', $created['path'], $created['filename'], $superPassword, $decisions);
        $assert($restoreOutcome['success'] === true, 'Safe incoming restore completed');
        $assert($restoreOutcome['stage'] === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING, 'Restore transitions to operator_review_pending');

        $restoredProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $restoredSales = (int)$pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();
        $restoredName = (string)$pdo->query("SELECT product_name FROM products WHERE product_id = 100")->fetchColumn();
        $restoredQty = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 100")->fetchColumn();
        $assert($restoredProducts === $expectedProducts && $restoredSales === $expectedSales && $restoredName === 'Artisan Coffee Beans' && $restoredQty === 30, 'Zero records lost: all products, inventory, and sales restored accurately');

        $postCheck = $restoreService->verifyPostRestoreIntegrity(SqlBackupFormat::schema($pdo), []);
        $assert($postCheck['valid'] === true, 'Zero orphaned records: post-restore foreign key and business checks pass');

        // 4. Store reopening requires Super Administrator authentication and logs critical audit entry
        foreach (['stock', 'receipt', 'upload', 'attribution'] as $checkKey) {
            $restoreService->confirmChecklistItem(2, 'super_admin', $checkKey, true);
        }
        $activeState = RecoveryStore::state();
        $activeOpId = (string)$activeState['operation_id'];

        $reopenResult = $restoreService->reopenStore(2, 'super_admin', $superPassword, $activeOpId, 'Super Admin verified recovery');
        $assert($reopenResult['success'] === true, 'Super Admin reopens store after MySQL recovery');
        $assert(!RecoveryStore::isPaused(), 'Store operations resumed');

        $auditService = new ProtectedAuditRecordService($pdo, $policy);
        $criticalLogs = $auditService->records('super_admin', ['critical_only' => true]);
        $reopenLogs = array_values(array_filter($criticalLogs, static fn(array $r): bool => str_contains($r['action'], 'Recovery store reopened')));
        $assert(count($reopenLogs) === 1, 'Critical audit trail entry logged in MySQL upon store reopening');
    } catch (Throwable $e) {
        $failures[] = 'MySQL integration contract failure: ' . $e->getMessage();
    } finally {
        if (RecoveryStore::isPaused()) {
            try { RecoveryStore::resume(); } catch (Throwable) {}
        }
        if ($server instanceof PDO) {
            $server->exec("DROP DATABASE IF EXISTS `{$database}`");
        }
    }
}

$removeDir = static function (string $dir) use (&$removeDir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $dir . '/' . $item;
        is_dir($target) ? $removeDir($target) : @unlink($target);
    }
    @rmdir($dir);
};
$removeDir($private);

if ($failures !== []) {
    fwrite(STDERR, "Superadmin encrypted backup recovery contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Superadmin encrypted backup recovery contract: passed\n";
