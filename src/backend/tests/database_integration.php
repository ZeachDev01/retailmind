<?php
// Requires a disposable MySQL database loaded with src/backend/database/sql/schema.sql.
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
// Sale Cashier Shift attribution (ticket #89). A fresh install and an upgraded
// install must both record which Cashier Shift authorized a sale, and must both
// protect that shift from being deleted out from under the sales ledger.
$assert(
    Schema::columnExists($pdo, 'sales', 'shift_id'),
    'sales.shift_id is missing'
);
$assert(
    Schema::indexExists($pdo, 'sales', 'idx_sales_shift'),
    'sales has no Cashier Shift lookup index'
);
$assert(
    Schema::foreignKeyRelationExists($pdo, 'sales', 'shift_id', 'cashier_shifts', 'shift_id'),
    'sales.shift_id is not protected by a foreign key'
);
// Held sales inside their Cashier Shift (ticket #91). A fresh install and an
// upgraded install must both record the explained discard and the completed
// sale, both index the pair the closure invariant reads, and both refuse to let
// a shift holding an unresolved cart be deleted out from under it.
foreach (['sale_id', 'discard_reason', 'discard_note', 'discarded_by'] as $heldSaleColumn) {
    $assert(
        Schema::columnExists($pdo, 'held_sales', $heldSaleColumn),
        "held_sales.{$heldSaleColumn} is missing"
    );
}
$assert(
    Schema::indexExists($pdo, 'held_sales', 'idx_held_sales_shift_status'),
    'held sales have no Cashier Shift and status index for the closure invariant to read'
);
$assert(
    Schema::foreignKeyRelationExists($pdo, 'held_sales', 'shift_id', 'cashier_shifts', 'shift_id'),
    'held_sales.shift_id is not protected by a foreign key'
);
$heldShiftDeleteRule = $pdo->query(
    "SELECT rc.DELETE_RULE
     FROM information_schema.referential_constraints rc
     WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
       AND rc.TABLE_NAME = 'held_sales'
       AND rc.REFERENCED_TABLE_NAME = 'cashier_shifts'"
)->fetchColumn();
$assert(
    strtoupper((string)$heldShiftDeleteRule) === 'RESTRICT',
    'a Cashier Shift holding an unresolved held sale must not be deletable out from under it'
);
// The two ways a held sale leaves the unresolved set have to exist as statuses,
// or the closure invariant and the discard reason have nothing to read.
$heldSaleStatusEnum = (string)$pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'held_sales' AND column_name = 'status'"
)->fetchColumn();
foreach (['held', 'resumed', 'discarded', 'completed', 'expired'] as $heldSaleStatus) {
    $assert(
        str_contains($heldSaleStatusEnum, "'{$heldSaleStatus}'"),
        "held_sales.status is missing the '{$heldSaleStatus}' resolution"
    );
}
// Register lock and resume (ticket #90). A fresh install and an upgraded install
// must both record a break on the shift itself, so the lock survives the session
// that set it, and both must leave it nullable so an unlocked shift — which is
// every shift that has not been locked — needs no extra write.
$assert(
    Schema::columnExists($pdo, 'cashier_shifts', 'locked_at'),
    'cashier_shifts.locked_at is missing'
);
$lockedAtColumn = $pdo->query(
    "SELECT is_nullable FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'cashier_shifts' AND column_name = 'locked_at'"
)->fetchColumn();
$assert(
    $lockedAtColumn === 'YES',
    'cashier_shifts.locked_at must stay nullable so an unlocked shift is the default'
);
// The deployment cutoff: sales recorded before the upgrade were never attributed
// to a shift, so the column must still accept them unlinked.
$salesShiftColumn = $pdo->query(
    "SELECT is_nullable FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'sales' AND column_name = 'shift_id'"
)->fetchColumn();
$assert(
    $salesShiftColumn === 'YES',
    'sales.shift_id must stay nullable so pre-cutoff sales remain unlinked'
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
