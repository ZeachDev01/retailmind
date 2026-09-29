<?php
// Requires a disposable MySQL database loaded with src/backend/sql/schema.sql.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Database integration tests: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../config/db.php';

use App\Database\Schema;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(Schema::columnExists($pdo, 'users', 'must_change_password'), 'users.must_change_password is missing');
$assert(Schema::columnExists($pdo, 'users', 'profile_image'), 'users.profile_image is missing');
$assert(Schema::columnExists($pdo, 'users', 'is_recovery_account'), 'users.is_recovery_account is missing');
$assert(Schema::columnExists($pdo, 'users', 'disabled_at'), 'users.disabled_at is missing');
$assert(Schema::tableExists($pdo, 'login_attempts'), 'login_attempts table is missing');
$assert(Schema::tableExists($pdo, 'attention_settings'), 'attention_settings table is missing');
$assert(Schema::tableExists($pdo, 'attention_states'), 'attention_states table is missing');
$assert(Schema::columnExists($pdo, 'notifications', 'attention_key'), 'notifications.attention_key is missing');
$assert(Schema::tableExists($pdo, 'purchase_orders'), 'purchase_orders table is missing');
// Register administration (ticket #87). A fresh install and an upgraded install
// must expose the same Register structure to RegisterService.
foreach (['register_id', 'name', 'status', 'disabled_at', 'created_by'] as $registerColumn) {
    $assert(
        Schema::columnExists($pdo, 'registers', $registerColumn),
        "registers.{$registerColumn} is missing"
    );
}
$assert(Schema::indexExists($pdo, 'registers', 'register_name'), 'registers display names are not unique');
$assert(
    Schema::foreignKeyRelationExists($pdo, 'registers', 'created_by', 'users', 'user_id'),
    'registers.created_by is not protected by a foreign key'
);
// Exclusive Cashier Shift opening (ticket #88). A fresh install and an upgraded
// install must both enforce one open shift per Cashier and one per Register,
// and must both bind an open shift to a Register that cannot be deleted.
$assert(
    Schema::columnExists($pdo, 'cashier_shifts', 'register_id'),
    'cashier_shifts.register_id is missing'
);
foreach (['open_cashier_id', 'open_register_id'] as $exclusivityColumn) {
    $assert(
        Schema::columnExists($pdo, 'cashier_shifts', $exclusivityColumn),
        "cashier_shifts.{$exclusivityColumn} is missing"
    );
}
$assert(
    Schema::indexExists($pdo, 'cashier_shifts', 'uq_cashier_shifts_open_cashier'),
    'cashier_shifts does not enforce one open shift per Cashier'
);
$assert(
    Schema::indexExists($pdo, 'cashier_shifts', 'uq_cashier_shifts_open_register'),
    'cashier_shifts does not enforce one open shift per Register'
);
$assert(
    Schema::indexExists($pdo, 'cashier_shifts', 'idx_cashier_shifts_register'),
    'cashier_shifts has no Register lookup index'
);
$assert(
    Schema::foreignKeyRelationExists($pdo, 'cashier_shifts', 'register_id', 'registers', 'register_id'),
    'cashier_shifts.register_id is not protected by a foreign key'
);
// The uniqueness must be a real UNIQUE index, not a plain one, or a race would
// quietly create two open shifts on the same Register.
$exclusivityKeys = $pdo->query(
    "SELECT index_name, non_unique FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'cashier_shifts'
       AND index_name IN ('uq_cashier_shifts_open_cashier','uq_cashier_shifts_open_register')"
)->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['uq_cashier_shifts_open_cashier', 'uq_cashier_shifts_open_register'] as $exclusivityKey) {
    $assert(
        isset($exclusivityKeys[$exclusivityKey]) && (int)$exclusivityKeys[$exclusivityKey] === 0,
        "cashier_shifts.{$exclusivityKey} must be a UNIQUE index"
    );
}
$assert(
    Schema::indexExists($pdo, 'notifications', 'idx_notifications_attention'),
    'notifications attention index is missing'
);
$assert(
    Schema::foreignKeyRelationExists($pdo, 'supplier_products', 'supplier_id', 'suppliers', 'supplier_id'),
    'supplier_products.supplier_id is not protected by a foreign key'
);
$assert(
    Schema::foreignKeyRelationExists($pdo, 'purchase_order_items', 'purchase_order_id', 'purchase_orders', 'purchase_order_id'),
    'purchase_order_items.purchase_order_id is not protected by a foreign key'
);

try {
    $pdo->beginTransaction();
    $roleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name='cashier' LIMIT 1")->fetchColumn();
    $username = 'integration_' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        "INSERT INTO users (full_name, username, email, password_hash, role_id, status, must_change_password)
         VALUES ('Integration User', ?, ?, ?, ?, 'active', 1)"
    );
    $stmt->execute([$username, $username . '@example.test', password_hash('Integration@123', PASSWORD_DEFAULT), $roleId]);
    $userId = (int)$pdo->lastInsertId();
    $locked = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE');
    $locked->execute([$userId]);
    $assert((int)$locked->fetchColumn() === $userId, 'SELECT FOR UPDATE did not return the inserted user');
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $failures[] = 'Transactional database test failed: ' . $e->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Database integration tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Database integration tests: passed\n";
