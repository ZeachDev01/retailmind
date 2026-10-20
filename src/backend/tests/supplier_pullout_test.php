<?php
// Contract and integration test for Ticket #224: Record batch-specific Supplier Pullouts under Inventory Control.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SupplierPulloutService.php';
require_once __DIR__ . '/../app/Services/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Store/StoreWriteGate.php';

use App\Authorization\RoleCapabilityPolicy;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

$throws = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message . ' (expected exception, none thrown)');
    } catch (Throwable $e) {
        $assert(
            $e instanceof RuntimeException || $e instanceof InvalidArgumentException,
            $message . ' (threw ' . $e::class . ': ' . $e->getMessage() . ')'
        );
    }
};

echo "Starting Ticket #224 supplier pullout tests...\n";

// --- Database Setup (SQLite in-memory) ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo'] = $pdo;

// Create schema
$pdo->exec("CREATE TABLE branches (
    branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name TEXT NOT NULL UNIQUE,
    branch_code TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Store', 'MAIN', 'active')");

$pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)");
$pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin')");

$pdo->exec("CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NULL,
    role_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'active'
)");

$insertUser = static function (string $name, string $username, string $role) use ($pdo): int {
    $roleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name = '{$role}'")->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO users (full_name, username, role_id, branch_id, status) VALUES (?, ?, ?, 1, ?)');
    $stmt->execute([$name, $username, $roleId, 'active']);
    return (int)$pdo->lastInsertId();
};

$cashierId = $insertUser('Cashier User', 'cashier1', 'cashier');
$managerId = $insertUser('Manager User', 'manager1', 'inventory_manager');
$adminId = $insertUser('Admin User', 'admin1', 'admin');

$pdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    sku TEXT NOT NULL,
    barcode TEXT NOT NULL,
    product_name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
)");

$pdo->exec("CREATE TABLE inventory (
    inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL UNIQUE,
    quantity_on_hand INTEGER NOT NULL DEFAULT 0,
    reorder_level INTEGER NOT NULL DEFAULT 5,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE product_batches (
    batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    receiving_id INTEGER NULL,
    batch_number TEXT NULL,
    quantity INTEGER NOT NULL,
    remaining_quantity INTEGER NOT NULL,
    expiration_date TEXT NULL,
    date_received TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    supplier TEXT NULL
)");

$pdo->exec("CREATE TABLE stock_receiving (
    receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NULL,
    product_id INTEGER NOT NULL,
    received_qty INTEGER NOT NULL,
    accepted_qty INTEGER NOT NULL,
    received_by INTEGER NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    supplier TEXT NULL,
    pullout_id INTEGER NULL
)");

$pdo->exec("CREATE TABLE stock_movements (
    movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    change_qty INTEGER NOT NULL,
    reason TEXT NOT NULL,
    moved_by INTEGER NULL,
    adjustment_id INTEGER NULL,
    pullout_id INTEGER NULL,
    moved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE supplier_pullouts (
    pullout_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    batch_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    reason TEXT NOT NULL,
    supplier TEXT NULL,
    pulled_by INTEGER NOT NULL,
    pulled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL
)");

$pdo->exec("CREATE TABLE store_write_gate (
    gate_key TEXT PRIMARY KEY,
    is_paused INTEGER NOT NULL DEFAULT 0
)");
$pdo->exec("INSERT OR IGNORE INTO store_write_gate (gate_key, is_paused) VALUES ('store_writes', 0)");

$pdo->exec("CREATE TABLE fiscal_periods (
    period_id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_name TEXT NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open'
)");
$pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2026-01-01', '2026-12-31', 'open')");

$pdo->exec("CREATE TABLE fiscal_period_locks (
    lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_id INTEGER NOT NULL,
    table_name TEXT NULL
)");

$pdo->exec("CREATE TABLE notifications (
    notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    type TEXT NOT NULL,
    reference_id INTEGER NULL,
    reference_type TEXT NULL,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Create test products and batches
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name) VALUES (1, 'BREAD-001', '10001', 'Sliced Bread')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 50)");

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name) VALUES (2, 'MILK-001', '10002', 'Fresh Milk')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 20)");

// Batch 1: Sliced Bread, active, 30 units, supplier BakerCo, exp Nov 1
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date, supplier)
            VALUES (1, 1, 'BATCH-BR-01', 30, 30, '2026-11-01', 'BakerCo')");

// Batch 2: Sliced Bread, expired, 20 units, supplier BakerCo, exp Oct 01 (past)
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date, supplier)
            VALUES (2, 1, 'BATCH-BR-02', 20, 20, '2026-10-01', 'BakerCo')");

// Batch 3: Fresh Milk, active, 20 units, supplier DairyInc
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date, supplier)
            VALUES (3, 2, 'BATCH-MK-01', 20, 20, '2026-11-15', 'DairyInc')");

$pulloutService = new SupplierPulloutService($pdo, null, 'inventory_manager');

// ==========================================
// Test 1: Atomic batch and inventory deduction
// ==========================================
echo "\n--- Test 1: Atomic batch and inventory deduction ---\n";
$res1 = $pulloutService->recordPullout([
    'product_id' => 1,
    'batch_id' => 1,
    'quantity' => 8,
    'reason' => 'Damaged in transit',
    'notes' => 'Driver collection #D-102',
], $managerId);

$assert(isset($res1['pullout_id']) && $res1['pullout_id'] > 0, 'Pullout record created with ID ' . ($res1['pullout_id'] ?? 0));
$assert($res1['remaining_batch_qty'] === 22, 'Returned remaining batch quantity is 22');
$assert($res1['new_on_hand'] === 42, 'Returned new on hand is 42');

// Verify database state
$b1Remaining = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 1")->fetchColumn();
$assert($b1Remaining === 22, "Batch 1 remaining quantity in DB deducted to 22 (was 30)");

$inv1OnHand = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert($inv1OnHand === 42, "Product 1 inventory in DB deducted to 42 (was 50)");

$pulloutRow = $pdo->query("SELECT * FROM supplier_pullouts WHERE pullout_id = " . (int)$res1['pullout_id'])->fetch();
$assert($pulloutRow !== false, "Supplier pullouts row exists in DB");
$assert((int)$pulloutRow['product_id'] === 1, "Pullout product_id is 1");
$assert((int)$pulloutRow['batch_id'] === 1, "Pullout batch_id is 1");
$assert((int)$pulloutRow['quantity'] === 8, "Pullout quantity is 8");
$assert($pulloutRow['reason'] === 'Damaged in transit', "Pullout reason recorded correctly");
$assert($pulloutRow['supplier'] === 'BakerCo', "Pullout supplier defaulted from batch to BakerCo");
$assert((int)$pulloutRow['pulled_by'] === $managerId, "Pullout staff recorded as managerId");

// ==========================================
// Test 2: Movement history recording
// ==========================================
echo "\n--- Test 2: Movement history recording ---\n";
$movement = $pdo->query("SELECT * FROM stock_movements WHERE pullout_id = " . (int)$res1['pullout_id'])->fetch();
$assert($movement !== false, "Stock movement row exists for pullout");
$assert((int)$movement['product_id'] === 1, "Movement product_id is 1");
$assert((int)$movement['change_qty'] === -8, "Movement change_qty is -8 (negative deduction)");
$assert($movement['reason'] === 'supplier_pullout', "Movement reason is strictly 'supplier_pullout'");
$assert((int)$movement['moved_by'] === $managerId, "Movement moved_by is managerId");

// ==========================================
// Test 3: Validation: rejects invalid allocations
// ==========================================
echo "\n--- Test 3: Validation: rejects invalid allocations ---\n";

// Wrong product
$throws(function () use ($pulloutService, $managerId) {
    // Batch 3 belongs to Product 2 (Milk), not Product 1 (Bread)
    $pulloutService->recordPullout([
        'product_id' => 1,
        'batch_id' => 3,
        'quantity' => 5,
        'reason' => 'Defective',
    ], $managerId);
}, 'Rejects batch belonging to different product');

// Zero quantity
$throws(function () use ($pulloutService, $managerId) {
    $pulloutService->recordPullout([
        'product_id' => 1,
        'batch_id' => 1,
        'quantity' => 0,
        'reason' => 'Zero check',
    ], $managerId);
}, 'Rejects zero quantity');

// Negative quantity
$throws(function () use ($pulloutService, $managerId) {
    $pulloutService->recordPullout([
        'product_id' => 1,
        'batch_id' => 1,
        'quantity' => -5,
        'reason' => 'Negative check',
    ], $managerId);
}, 'Rejects negative quantity');

// Fractional quantity
$throws(function () use ($pulloutService, $managerId) {
    $pulloutService->recordPullout([
        'product_id' => 1,
        'batch_id' => 1,
        'quantity' => 2.5,
        'reason' => 'Fractional check',
    ], $managerId);
}, 'Rejects fractional quantity (float)');

$throws(function () use ($pulloutService, $managerId) {
    $pulloutService->recordPullout([
        'product_id' => 1,
        'batch_id' => 1,
        'quantity' => '3.14',
        'reason' => 'Fractional check string',
    ], $managerId);
}, 'Rejects fractional quantity (string)');

// Excessive quantity (exceeds batch remaining: Batch 1 has 22 left)
$throws(function () use ($pulloutService, $managerId) {
    $pulloutService->recordPullout([
        'product_id' => 1,
        'batch_id' => 1,
        'quantity' => 25,
        'reason' => 'Excessive check',
    ], $managerId);
}, 'Rejects quantity exceeding batch remaining balance');

// Missing reason
$throws(function () use ($pulloutService, $managerId) {
    $pulloutService->recordPullout([
        'product_id' => 1,
        'batch_id' => 1,
        'quantity' => 5,
        'reason' => '   ',
    ], $managerId);
}, 'Rejects blank reason');

// ==========================================
// Test 4: Expired batch pullout allowed
// ==========================================
echo "\n--- Test 4: Expired batch pullout allowed ---\n";
// Batch 2 is expired (expiration_date = '2026-10-01', past date), remaining = 20
$resExpired = $pulloutService->recordPullout([
    'product_id' => 1,
    'batch_id' => 2,
    'quantity' => 15,
    'reason' => 'Expired goods supplier return',
], $managerId);

$assert(isset($resExpired['pullout_id']) && $resExpired['pullout_id'] > 0, 'Expired batch pullout succeeds');
$b2Remaining = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 2")->fetchColumn();
$assert($b2Remaining === 5, "Expired batch remaining quantity deducted from 20 to 5");
$invAfterExpired = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert($invAfterExpired === 27, "Product inventory deducted to 27 (was 42)");

// ==========================================
// Test 5: Workspace authorization
// ==========================================
echo "\n--- Test 5: Workspace authorization ---\n";

// Cashier user rejected
$cashierService = new SupplierPulloutService($pdo, null, 'cashier');
$throws(function () use ($cashierService, $cashierId) {
    $cashierService->recordPullout([
        'product_id' => 1,
        'batch_id' => 1,
        'quantity' => 1,
        'reason' => 'Cashier attempt',
    ], $cashierId);
}, 'Cashier workspace rejected from recording supplier pullout');

// User with cashier role without explicit workspace rejected
$noWorkspaceService = new SupplierPulloutService($pdo, null, null);
$throws(function () use ($noWorkspaceService, $cashierId) {
    $noWorkspaceService->recordPullout([
        'product_id' => 1,
        'batch_id' => 1,
        'quantity' => 1,
        'reason' => 'Cashier attempt via DB role',
    ], $cashierId);
}, 'Cashier role derived from DB rejected from recording supplier pullout');

// Administrator workspace rejected on mutation attempt (read-only oversight)
$adminService = new SupplierPulloutService($pdo, null, 'admin');
$throws(function () use ($adminService, $adminId) {
    $adminService->recordPullout([
        'product_id' => 1,
        'batch_id' => 1,
        'quantity' => 1,
        'reason' => 'Admin attempt',
    ], $adminId);
}, 'Admin workspace rejected from recording supplier pullout (read-only oversight)');

// ==========================================
// Test 6: Concurrency locking and atomic rollback on failure
// ==========================================
echo "\n--- Test 6: Concurrency locking and atomic rollback on failure ---\n";
// Record before values
$batch1Before = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 1")->fetchColumn();
$inv1Before = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$pulloutsCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM supplier_pullouts")->fetchColumn();
$movementsCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

// Create a scenario where an unexpected exception is thrown inside the transaction
// By locking fiscal period for stock_movements
$pdo->exec("UPDATE fiscal_periods SET status = 'locked' WHERE period_id = 1");
$throws(function () use ($pulloutService, $managerId) {
    $pulloutService->recordPullout([
        'product_id' => 1,
        'batch_id' => 1,
        'quantity' => 5,
        'reason' => 'Should fail due to locked period',
    ], $managerId);
}, 'Locked fiscal period throws RuntimeException before mutation');

// Restore fiscal period
$pdo->exec("UPDATE fiscal_periods SET status = 'open' WHERE period_id = 1");

// Verify that all quantities remained unchanged
$batch1After = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 1")->fetchColumn();
$inv1After = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$pulloutsCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM supplier_pullouts")->fetchColumn();
$movementsCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

$assert($batch1Before === $batch1After, "Batch quantity rolled back / unchanged on error");
$assert($inv1Before === $inv1After, "Inventory on hand rolled back / unchanged on error");
$assert($pulloutsCountBefore === $pulloutsCountAfter, "No orphaned supplier_pullouts row created");
$assert($movementsCountBefore === $movementsCountAfter, "No orphaned stock_movements row created");

// ==========================================
// Test 7: Replacement aggregation & history deduplication
// ==========================================
echo "\n--- Test 7: Replacement aggregation & history deduplication ---\n";
// Insert two partial replacement receiving rows linked to pullout 1
$pdo->exec("INSERT INTO stock_receiving (product_id, received_qty, accepted_qty, received_by, pullout_id)
            VALUES (1, 3, 3, {$managerId}, 1)");
$pdo->exec("INSERT INTO stock_receiving (product_id, received_qty, accepted_qty, received_by, pullout_id)
            VALUES (1, 5, 5, {$managerId}, 1)");

$pullouts = $pulloutService->getPullouts(['product_id' => 1]);
$pullout1Entries = array_values(array_filter($pullouts, fn($p) => (int)$p['pullout_id'] === 1));
$assert(count($pullout1Entries) === 1, 'Multiple replacement receipts do not duplicate pullout history row');
$assert((int)$pullout1Entries[0]['total_replaced_qty'] === 8, 'Aggregated total_replaced_qty is 8');
$assert((int)$pullout1Entries[0]['replacement_count'] === 2, 'Replacement count is 2');

$singlePullout = $pulloutService->getPullout(1);
$assert($singlePullout !== null, 'getPullout(1) finds pullout');
$assert((int)$singlePullout['total_replaced_qty'] === 8, 'getPullout includes total_replaced_qty');

// Clean up test receiving rows
$pdo->exec("DELETE FROM stock_receiving WHERE pullout_id = 1");

// ==========================================
// Test 8: UI static contracts
// ==========================================
echo "\n--- Test 8: UI static contracts ---\n";

$sidebarCode = file_get_contents(__DIR__ . '/../../frontend/components/sidebar.php');
$assert($sidebarCode !== false, 'sidebar.php exists and readable');
$assert(str_contains($sidebarCode, 'components/inventory_management/supplier_pullouts.php'), 'sidebar.php links to supplier_pullouts.php');
$assert(str_contains($sidebarCode, "'label' => 'Supplier Pullouts'"), 'sidebar.php contains label Supplier Pullouts');

$uiCode = file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/supplier_pullouts.php');
$assert($uiCode !== false, 'supplier_pullouts.php exists and readable');
$assert(str_contains($uiCode, 'require_role'), 'supplier_pullouts.php enforces role restrictions');
$assert(str_contains($uiCode, 'verify_csrf_token'), 'supplier_pullouts.php verifies CSRF token');
$assert(str_contains($uiCode, 'name="csrf_token"'), 'supplier_pullouts.php includes csrf_token field');
$assert(str_contains($uiCode, 'name="product_id"'), 'supplier_pullouts.php includes product_id field');
$assert(str_contains($uiCode, 'name="batch_id"'), 'supplier_pullouts.php includes batch_id field');
$assert(str_contains($uiCode, 'name="quantity"'), 'supplier_pullouts.php includes quantity field');
$assert(str_contains($uiCode, 'name="reason"'), 'supplier_pullouts.php includes reason field');
$assert(str_contains($uiCode, 'name="supplier"'), 'supplier_pullouts.php includes supplier field');
$assert(str_contains($uiCode, 'btn-submit-pullout'), 'supplier_pullouts.php includes submit button');
$assert(str_contains($uiCode, '$_GET[\'product_id\']'), 'supplier_pullouts.php supports product_id prefill');
$assert(str_contains($uiCode, '$_GET[\'batch_id\']'), 'supplier_pullouts.php supports batch_id prefill');
$assert(str_contains($uiCode, 'Supplier Pullout History'), 'supplier_pullouts.php contains history table');

// Migration contract check
$migrationFile = __DIR__ . '/../database/migrations/202610180004_supplier_pullouts.php';
$assert(file_exists($migrationFile), '202610180004_supplier_pullouts.php migration exists');
$migration = require $migrationFile;
$assert($migration['key'] === '202610180004_supplier_pullouts', 'Migration key matches 202610180004_supplier_pullouts');

// Schema parity contract check
$schemaSql = file_get_contents(__DIR__ . '/../database/sql/schema.sql');
$assert(str_contains($schemaSql, 'CREATE TABLE `supplier_pullouts`'), 'schema.sql defines supplier_pullouts table');
$assert(str_contains($schemaSql, "'supplier_pullout'"), 'schema.sql includes supplier_pullout in reason enum');

$updateSql = file_get_contents(__DIR__ . '/../database/sql/update.sql');
$assert(str_contains($updateSql, '202610180004_supplier_pullouts'), 'update.sql includes 202610180004_supplier_pullouts');

// A read alert follows the physical batch through partial and final collection.
require_once __DIR__ . '/../app/Services/NotificationService.php';
$pdo->exec('CREATE TABLE notification_preferences (user_id INTEGER PRIMARY KEY, notify_inapp INTEGER DEFAULT 1)');
$pdo->exec('ALTER TABLE products ADD COLUMN warning_lead_time_days INTEGER NULL');
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, warning_lead_time_days) VALUES (3, 'BREAD-REMINDER', '10003', 'Reminder Bread', 2)");
$pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (3, 10)');
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date) VALUES (4, 3, 'COLLECT-10', 10, 10, '2026-10-25')");
$clock = new class implements \App\Attention\Clock {
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-23T00:00:00+08:00'); }
};
$notifications = new NotificationService($pdo, $clock);
$notifications->checkAndNotifyExpiringStock(30);
$reminders = $notifications->getBatchReminders($managerId, '2026-10-25');
$assert(isset($reminders[4]) && $reminders[4]['active'] && (int)$reminders[4]['remaining_quantity'] === 10, 'Expiration day keeps ten units actionable');
$assert($reminders[4]['title'] === 'Prepare for supplier collection: Reminder Bread', 'Expiration day is not expired');
$pdo->exec('UPDATE notifications SET is_read = 1 WHERE reference_id = 4');
$reminders = $notifications->getBatchReminders($managerId, '2026-10-26');
$assert($reminders[4]['active'] && $reminders[4]['title'] === 'Expired stock—waiting for supplier collection: Reminder Bread', 'Read reminder remains active after Manila expiration day');
$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 3')->fetchColumn() === 10, 'Checks, reading and date transition leave physical stock unchanged');
$partial = $pulloutService->recordPullout(['product_id' => 3, 'batch_id' => 4, 'quantity' => 8, 'reason' => 'Supplier collection'], $managerId);
$reminders = $notifications->getBatchReminders($managerId, '2026-10-26');
$assert($reminders[4]['active'] && str_contains($reminders[4]['message'], 'Remaining: 2.'), 'Partial collection immediately presents two remaining units');
$assert($partial['remaining_batch_qty'] === 2 && $partial['new_on_hand'] === 2, 'Eight-unit collection deducts batch and inventory together');
$final = $pulloutService->recordPullout(['product_id' => 3, 'batch_id' => 4, 'quantity' => 2, 'reason' => 'Final supplier collection'], $managerId);
$reminders = $notifications->getBatchReminders($managerId, '2026-10-26');
$assert(!$reminders[4]['active'] && $final['remaining_batch_qty'] === 0 && $final['new_on_hand'] === 0, 'Final collection immediately clears active work');
$assert(count($pulloutService->getPullouts(['product_id' => 3])) === 2 && isset($reminders[4]), 'Completed collection retains pullout and notification history');

$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date) VALUES (5, 3, 'COLLECT-SECOND', 3, 3, '2026-10-25'), (6, 3, 'UNDATED', 2, 2, NULL)");
$pdo->exec('UPDATE inventory SET quantity_on_hand = 5 WHERE product_id = 3');
$notifications->checkAndNotifyExpiringStock();
$notifications->checkAndNotifyExpiringStock();
$reminders = $notifications->getBatchReminders($managerId, '2026-10-25');
$assert(!$reminders[4]['active'] && $reminders[5]['active'] && !isset($reminders[6]), 'Another batch stays independent; undated stock has no date reminder');
$secondBatchAlerts = array_filter(get_unread_notifications($pdo, $managerId), static fn(array $alert): bool => (int)$alert['reference_id'] === 5);
$assert(count($secondBatchAlerts) === 1, 'Repeated checks do not duplicate the same batch milestone');
$laterClock = new class implements \App\Attention\Clock {
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-26T16:01:00Z'); }
};
$laterNotifications = new NotificationService($pdo, $laterClock);
$laterNotifications->checkAndNotifyExpiringStock();
$laterNotifications->checkAndNotifyExpiringStock();
$expiredBatchAlerts = array_filter(get_unread_notifications($pdo, $managerId), static fn(array $alert): bool => (int)$alert['reference_id'] === 5 && $alert['type'] === 'expired_stock');
$assert(count($expiredBatchAlerts) === 1 && $laterNotifications->getBatchReminders($managerId)[5]['active'], 'Repeated expired checks retain one active condition and one expired alert');

// Summary
if (!empty($failures)) {
    echo "\nFailed assertions (" . count($failures) . "):\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "\nAll Ticket #224 supplier pullout tests passed!\n";
exit(0);
