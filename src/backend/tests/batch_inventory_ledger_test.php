<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/BatchInventoryLedger.php';
require_once __DIR__ . '/../app/Services/StockIssueService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';

use App\Services\BatchInventoryLedger;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

$throws = static function (callable $operation, string $expectedMessagePart, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, "{$label} (expected exception, none thrown)");
    } catch (Throwable $e) {
        $matches = str_contains($e->getMessage(), $expectedMessagePart);
        $cls = $e::class;
        $assert($matches, "{$label} (threw {$cls}: {$e->getMessage()})");
    }
};

echo "Starting Batch Inventory Ledger contract & integration tests...\n";

// SQLite in-memory test database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo'] = $pdo;

// Schema setup
$pdo->exec("CREATE TABLE branches (
    branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name VARCHAR(100) NOT NULL UNIQUE,
    branch_code VARCHAR(30) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO branches (branch_name, branch_code, status) VALUES ('Main Store', 'MAIN-STORE', 'active')");

$pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name VARCHAR(50) NOT NULL UNIQUE)");
$pdo->exec("INSERT INTO roles (role_name) VALUES ('cashier'), ('inventory_manager'), ('admin')");

$pdo->exec("CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name VARCHAR(150) NOT NULL DEFAULT 'Test User',
    display_name VARCHAR(150) NOT NULL DEFAULT 'Test User',
    branch_id INTEGER NULL,
    role_id INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO users (full_name, display_name, branch_id, role_id, status) VALUES ('Mina Manager', 'Mina Manager', 1, 2, 'active')");
$managerId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (full_name, display_name, branch_id, role_id, status) VALUES ('Carl Cashier', 'Carl Cashier', 1, 1, 'active')");
$cashierId = (int)$pdo->lastInsertId();

$pdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NULL,
    sku VARCHAR(50) NOT NULL,
    barcode VARCHAR(50) NOT NULL DEFAULT '',
    product_name VARCHAR(150) NOT NULL,
    pack_size INTEGER NULL,
    pack_barcode VARCHAR(50) NULL,
    selling_mode VARCHAR(20) NOT NULL DEFAULT 'piece',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    reorder_level INTEGER NOT NULL DEFAULT 0,
    safety_stock INTEGER NOT NULL DEFAULT 0,
    warning_lead_time_days INTEGER NULL
)");

$pdo->exec("CREATE TABLE inventory (
    product_id INTEGER PRIMARY KEY,
    quantity_on_hand INTEGER NOT NULL DEFAULT 0
)");

$pdo->exec("CREATE TABLE product_batches (
    batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    receiving_id INTEGER NULL,
    batch_number VARCHAR(100) NOT NULL,
    quantity INTEGER NOT NULL,
    remaining_quantity INTEGER NOT NULL,
    sealed_quantity INTEGER NOT NULL DEFAULT 0,
    loose_quantity INTEGER NOT NULL DEFAULT 0,
    expiration_date DATE NULL,
    date_received DATE NULL,
    supplier VARCHAR(150) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products (product_id)
)");

$pdo->exec("CREATE TABLE inventory_adjustments (
    adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    adjustment_qty INTEGER NOT NULL,
    adjustment_type TEXT NOT NULL,
    reported_by INTEGER NOT NULL,
    shift_id INTEGER NULL,
    reported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by INTEGER NULL,
    approved_at TEXT NULL,
    reason TEXT NULL,
    review_notes TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    form TEXT NOT NULL DEFAULT 'piece',
    reported_qty INTEGER NULL,
    pack_size INTEGER NULL
)");

$pdo->exec("CREATE TABLE stock_issue_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    adjustment_id INTEGER NOT NULL,
    batch_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    form TEXT NULL,
    reported_qty INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (adjustment_id) REFERENCES inventory_adjustments (adjustment_id) ON DELETE CASCADE,
    FOREIGN KEY (batch_id) REFERENCES product_batches (batch_id)
)");

$pdo->exec("CREATE TABLE stock_movements (
    movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    change_qty INTEGER NOT NULL,
    reason TEXT NOT NULL,
    moved_by INTEGER NULL,
    adjustment_id INTEGER NULL,
    moved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE fiscal_periods (
    period_id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_name VARCHAR(100) NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open'
)");
$pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2026-01-01', '2026-12-31', 'open')");

$pdo->exec("CREATE TABLE fiscal_period_locks (
    period_id INTEGER NOT NULL,
    table_name VARCHAR(100) NULL
)");

$pdo->exec("CREATE TABLE inventory_adjustment_revisions (
    revision_id INTEGER PRIMARY KEY AUTOINCREMENT,
    adjustment_id INTEGER NOT NULL,
    actor_id INTEGER NULL,
    action TEXT NOT NULL,
    old_status TEXT NULL,
    new_status TEXT NULL,
    old_values TEXT NULL,
    new_values TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE activity_log (
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
    created_at TEXT NULL
)");

$pdo->exec("CREATE TABLE cashier_shifts (
    shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cashier_id INTEGER NOT NULL,
    opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'open'
)");
$pdo->exec("INSERT INTO cashier_shifts (cashier_id, status) VALUES ({$cashierId}, 'open')");
$shiftId = (int)$pdo->lastInsertId();

$pdo->exec("CREATE TABLE notification_preferences (
    user_id INTEGER PRIMARY KEY,
    notify_low_stock INTEGER DEFAULT 1,
    notify_expiring_stock INTEGER DEFAULT 1,
    low_stock_threshold INTEGER DEFAULT NULL,
    notify_inapp INTEGER DEFAULT 1
)");

$pdo->exec("CREATE TABLE notifications (
    notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    reference_id INTEGER NULL,
    reference_type TEXT NULL,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    workspace TEXT NULL,
    attention_key TEXT NULL,
    attention_severity TEXT NULL,
    attention_count INTEGER NULL,
    attention_destination TEXT NULL,
    inapp_enabled INTEGER DEFAULT 1,
    email_pending INTEGER DEFAULT 0
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS attention_states (
    user_id INTEGER NOT NULL,
    attention_key TEXT NOT NULL,
    fingerprint TEXT NOT NULL,
    notification_level INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    first_detected_at TEXT NOT NULL,
    last_detected_at TEXT NOT NULL,
    resolved_at TEXT NULL,
    PRIMARY KEY (user_id, attention_key)
)");

$pdo->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, is_primary INTEGER);
    INSERT INTO user_roles SELECT user_id, role_id, 1 FROM users');

// Instantiate Ledger
$ledger = new BatchInventoryLedger($pdo);

// -------------------------------------------------------------------------
// Scenario 1: Strict FEFO Ordering with Equal & Absent Expirations
// -------------------------------------------------------------------------
echo "\n--- Scenario 1: Strict FEFO Ordering with Equal & Absent Expirations ---\n";
// Product 1: Multi-batch product
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name) VALUES (1, 'SKU-FEFO', 'BC-FEFO', 'FEFO Test Loaf')");
$p1Id = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$p1Id}, 40)");

// Insert batches in non-FEFO order to ensure SQL ordering is authoritative:
// Batch A: expires 2026-11-20, received 2026-11-01 (middle date) -> 10 units
// Batch B: expires 2026-11-10, received 2026-11-02 (earliest date) -> 10 units
// Batch C: expires 2026-11-10, received 2026-11-01 (equal date to B, but received earlier!) -> 10 units
// Batch D: expires NULL (undated), received 2026-10-30 -> 10 units
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received)
    VALUES ({$p1Id}, 'BATCH-A', 10, 10, '2026-11-20', '2026-11-01')");
$bA = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received)
    VALUES ({$p1Id}, 'BATCH-B', 10, 10, '2026-11-10', '2026-11-02')");
$bB = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received)
    VALUES ({$p1Id}, 'BATCH-C', 10, 10, '2026-11-10', '2026-11-01')");
$bC = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received)
    VALUES ({$p1Id}, 'BATCH-D', 10, 10, NULL, '2026-10-30')");
$bD = (int)$pdo->lastInsertId();

// Verify invariant before deduction: 40 on hand === 40 in batches
$assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$p1Id}")->fetchColumn() === 40, 'Initial p1 stock is 40');

// Deduct 15 units via automatic FEFO for dummy adjustment #101
$pdo->exec("INSERT INTO inventory_adjustments (adjustment_id, product_id, adjustment_qty, adjustment_type, reported_by, shift_id, status)
    VALUES (101, {$p1Id}, -15, 'damaged', {$cashierId}, {$shiftId}, 'pending')");

$res1 = $ledger->recordStockIssueDeduction($p1Id, 15, 'piece', null, null, $managerId, 101);
// Expected allocation order:
// 1st: Batch C (expires 2026-11-10, date_received 2026-11-01) -> 10 units (depleted)
// 2nd: Batch B (expires 2026-11-10, date_received 2026-11-02) -> 5 units (5 remaining)
// Batch A (2026-11-20) and Batch D (NULL) untouched!
$assert(isset($res1['allocations'][$bC]) && $res1['allocations'][$bC]['base_units'] === 10, 'FEFO picked Batch C first for 10 units (earlier date_received on tie)');
$assert(isset($res1['allocations'][$bB]) && $res1['allocations'][$bB]['base_units'] === 5, 'FEFO picked Batch B second for 5 units');
$assert(!isset($res1['allocations'][$bA]), 'Batch A untouched');
$assert(!isset($res1['allocations'][$bD]), 'Batch D (undated) untouched');

$remC = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$bC}")->fetchColumn();
$remB = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$bB}")->fetchColumn();
$assert($remC === 0, 'Batch C remaining is 0');
$assert($remB === 5, 'Batch B remaining is 5');
$qoh1 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$p1Id}")->fetchColumn();
$assert($qoh1 === 25, 'p1 QOH deducted from 40 to 25');

// Invariant verified: 0 + 5 + 10 + 10 = 25
$sum1 = (int)$pdo->query("SELECT SUM(remaining_quantity) FROM product_batches WHERE product_id = {$p1Id}")->fetchColumn();
$assert($qoh1 === $sum1, "Core invariant verified: QOH ({$qoh1}) === sum(remaining) ({$sum1})");

// Now deduct 20 units: should deplete B (5), deplete A (10), and take 5 from undated D (5 remaining)
$pdo->exec("INSERT INTO inventory_adjustments (adjustment_id, product_id, adjustment_qty, adjustment_type, reported_by, shift_id, status)
    VALUES (102, {$p1Id}, -20, 'expired', {$cashierId}, {$shiftId}, 'pending')");
$res2 = $ledger->recordStockIssueDeduction($p1Id, 20, 'piece', null, null, $managerId, 102);
$assert(isset($res2['allocations'][$bB]) && $res2['allocations'][$bB]['base_units'] === 5, 'Depleted Batch B (5)');
$assert(isset($res2['allocations'][$bA]) && $res2['allocations'][$bA]['base_units'] === 10, 'Depleted Batch A (10)');
$assert(isset($res2['allocations'][$bD]) && $res2['allocations'][$bD]['base_units'] === 5, 'Allocated remaining 5 from undated Batch D');

$qoh2 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$p1Id}")->fetchColumn();
$sum2 = (int)$pdo->query("SELECT SUM(remaining_quantity) FROM product_batches WHERE product_id = {$p1Id}")->fetchColumn();
$assert($qoh2 === 5 && $sum2 === 5, "Core invariant verified after undated deduction: QOH ({$qoh2}) === sum(remaining) ({$sum2})");

// -------------------------------------------------------------------------
// Scenario 2: Sealed Sales Packs vs Loose Base Units Handling
// -------------------------------------------------------------------------
echo "\n--- Scenario 2: Sealed Sales Packs vs Loose Base Units Handling ---\n";
// Product 2: Sales pack product (pack_size = 6)
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name, pack_size, selling_mode)
    VALUES (1, 'SKU-PACK', 'BC-PACK', 'Bottled Juice 6-Pack', 6, 'both')");
$p2Id = (int)$pdo->lastInsertId();
// 3 packs (18 base units) + 4 loose pieces = 22 base units total
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$p2Id}, 22)");
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
    VALUES ({$p2Id}, 'PACK-BATCH-1', 22, 22, 3, 4, '2026-12-01')");
$bPack1 = (int)$pdo->lastInsertId();

// Deduct 2 packs: base units = 12, sealed_quantity decrements by 2, loose remains 4
$pdo->exec("INSERT INTO inventory_adjustments (adjustment_id, product_id, adjustment_qty, adjustment_type, reported_by, shift_id, status, form, reported_qty, pack_size)
    VALUES (103, {$p2Id}, -12, 'damaged', {$cashierId}, {$shiftId}, 'pending', 'pack', 2, 6)");
$resPack = $ledger->recordStockIssueDeduction($p2Id, 2, 'pack', 6, null, $managerId, 103);

$assert($resPack['base_units'] === 12, 'Pack deduction base units is 12');
$batchRow = $pdo->query("SELECT sealed_quantity, loose_quantity, remaining_quantity FROM product_batches WHERE batch_id = {$bPack1}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$batchRow['sealed_quantity'] === 1, 'Sealed quantity is now 1 pack (was 3)');
$assert((int)$batchRow['loose_quantity'] === 4, 'Loose quantity untouched at 4 pieces');
$assert((int)$batchRow['remaining_quantity'] === 10, 'Remaining base units is 10 (1 pack * 6 + 4 loose)');
$qohPack = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$p2Id}")->fetchColumn();
$assert($qohPack === 10, 'Inventory quantity on hand is 10');

// Deduct 3 loose pieces: base units = 3, sealed_quantity remains 1, loose decrements by 3
$pdo->exec("INSERT INTO inventory_adjustments (adjustment_id, product_id, adjustment_qty, adjustment_type, reported_by, shift_id, status, form, reported_qty, pack_size)
    VALUES (104, {$p2Id}, -3, 'damaged', {$cashierId}, {$shiftId}, 'pending', 'piece', 3, 6)");
$resLoose = $ledger->recordStockIssueDeduction($p2Id, 3, 'piece', 6, null, $managerId, 104);
$batchRow2 = $pdo->query("SELECT sealed_quantity, loose_quantity, remaining_quantity FROM product_batches WHERE batch_id = {$bPack1}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$batchRow2['sealed_quantity'] === 1, 'Sealed quantity still 1 pack');
$assert((int)$batchRow2['loose_quantity'] === 1, 'Loose quantity is now 1 piece (was 4)');
$assert((int)$batchRow2['remaining_quantity'] === 7, 'Remaining base units is 7 (1 pack * 6 + 1 loose)');

// Update stock to 1 pack + 11 loose = 17 base units to test pack shortage with sufficient total stock
$pdo->exec("UPDATE product_batches SET loose_quantity = 11, remaining_quantity = 17 WHERE batch_id = {$bPack1}");
$pdo->exec("UPDATE inventory SET quantity_on_hand = 17 WHERE product_id = {$p2Id}");

// Insufficient sealed packs: try to deduct 2 packs when only 1 is available (total 17 >= 12, but packs 1 < 2)
$pdo->exec("INSERT INTO inventory_adjustments (adjustment_id, product_id, adjustment_qty, adjustment_type, reported_by, shift_id, status, form, reported_qty, pack_size)
    VALUES (105, {$p2Id}, -12, 'damaged', {$cashierId}, {$shiftId}, 'pending', 'pack', 2, 6)");
$throws(
    static fn() => $ledger->recordStockIssueDeduction($p2Id, 2, 'pack', 6, null, $managerId, 105),
    'Insufficient sealed pack stock',
    'Refuse deduction when requested sealed packs exceed available'
);

// Insufficient loose pieces: try to deduct 15 loose pieces when only 11 are loose (total 17 >= 15, but loose 11 < 15)
$pdo->exec("INSERT INTO inventory_adjustments (adjustment_id, product_id, adjustment_qty, adjustment_type, reported_by, shift_id, status, form, reported_qty, pack_size)
    VALUES (106, {$p2Id}, -15, 'damaged', {$cashierId}, {$shiftId}, 'pending', 'piece', 15, 6)");
$throws(
    static fn() => $ledger->recordStockIssueDeduction($p2Id, 15, 'piece', 6, null, $managerId, 106),
    'Insufficient loose stock',
    'Refuse deduction when requested loose pieces exceed available'
);



// -------------------------------------------------------------------------
// Scenario 3: Explicit Batch Allocation Validation
// -------------------------------------------------------------------------
echo "\n--- Scenario 3: Explicit Batch Allocation Validation ---\n";
// Rejection of invalid batch IDs, wrong products, negative quantities, float formats, and sum mismatches
$throws(
    static fn() => $ledger->recordStockIssueDeduction($p2Id, 1, 'piece', 6, [-1 => 1], $managerId, 107),
    'Invalid batch ID',
    'Reject negative batch ID'
);
$throws(
    static fn() => $ledger->recordStockIssueDeduction($p2Id, 1, 'piece', 6, [$bPack1 => -1], $managerId, 107),
    'Allocated quantity cannot be negative',
    'Reject negative allocated quantity'
);
$throws(
    static fn() => $ledger->recordStockIssueDeduction($p2Id, 1, 'piece', 6, [$bPack1 => '1.5'], $managerId, 107),
    'Allocated quantity must be a whole number',
    'Reject non-integer float string allocation'
);
$throws(
    static fn() => $ledger->recordStockIssueDeduction($p2Id, 1, 'piece', 6, [$bA => 1], $managerId, 107),
    'does not belong to product',
    'Reject batch belonging to another product'
);
$throws(
    static fn() => $ledger->recordStockIssueDeduction($p2Id, 2, 'piece', 6, [$bPack1 => 1], $managerId, 107),
    'does not match reported quantity',
    'Reject allocation sum mismatch (allocated 1, reported 2)'
);

// -------------------------------------------------------------------------
// Scenario 4: Fiscal Period & Lock Protection
// -------------------------------------------------------------------------
echo "\n--- Scenario 4: Fiscal Period & Lock Protection ---\n";
// Closed fiscal period blocks deduction
$pdo->exec("UPDATE fiscal_periods SET status = 'closed'");
$throws(
    static fn() => $ledger->recordStockIssueDeduction($p1Id, 1, 'piece', null, null, $managerId, 108),
    'fiscal period "FY2026" is closed',
    'Closed fiscal period blocks ledger mutation'
);

$pdo->exec("UPDATE fiscal_periods SET status = 'open'");
$pdo->exec("INSERT INTO fiscal_period_locks (period_id, table_name) VALUES (1, 'inventory_adjustments')");
$throws(
    static fn() => $ledger->recordStockIssueDeduction($p1Id, 1, 'piece', null, null, $managerId, 108),
    'is locked for inventory_adjustments',
    'Locked table in fiscal period blocks ledger mutation'
);
$pdo->exec("DELETE FROM fiscal_period_locks");

// -------------------------------------------------------------------------
// Scenario 5: Pre-mutation Batch/QOH Mismatch Rejection
// -------------------------------------------------------------------------
echo "\n--- Scenario 5: Pre-mutation Batch/QOH Mismatch Rejection ---\n";
// Create product with corrupt/mismatched stock before deduction: QOH = 20, but batches sum to 15
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name) VALUES (1, 'SKU-CORRUPT', 'BC-CORRUPT', 'Corrupt Stock Product')");
$pCorrupt = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$pCorrupt}, 20)");
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date)
    VALUES ({$pCorrupt}, 'BATCH-MISMATCH', 15, 15, '2026-12-01')");

$throws(
    static fn() => $ledger->recordStockIssueDeduction($pCorrupt, 2, 'piece', null, null, $managerId, 109),
    'inventory quantity on hand (20) does not match batch total (15)',
    'Reject deduction when pre-existing stock on hand does not match batch sum'
);

// -------------------------------------------------------------------------
// Scenario 6: Legacy Unbatched Product Fallback
// -------------------------------------------------------------------------
echo "\n--- Scenario 6: Legacy Unbatched Product Fallback ---\n";
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name) VALUES (1, 'SKU-LEGACY', 'BC-LEGACY', 'Legacy Non-Batched Product')");
$pLegacy = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$pLegacy}, 50)");

$throws(static fn() => $ledger->recordStockIssueDeduction($pLegacy, 5, 'piece', null, null, $managerId, 110),
    'does not match batch total', 'Missing batches reject deductions without fabricating history');
$assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$pLegacy}")->fetchColumn() === 50, 'Unbatched stock remains unchanged');

// -------------------------------------------------------------------------
// Scenario 7: End-to-End Migration in StockIssueService Approval
// -------------------------------------------------------------------------
echo "\n--- Scenario 7: End-to-End StockIssueService Approval via Ledger ---\n";
$service = new StockIssueService($pdo);

// Create product and report
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name) VALUES (1, 'SKU-E2E', 'BC-E2E', 'E2E Test Product')");
$pE2E = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$pE2E}, 10)");
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date)
    VALUES ({$pE2E}, 'BATCH-E2E-1', 10, 10, '2026-11-15')");
$bE2E = (int)$pdo->lastInsertId();

$adjIdE2E = $service->submitReport([
    'product_id' => $pE2E,
    'category' => 'damaged',
    'quantity' => 3,
    'explanation' => 'Three broken packages during stocking.',
], $cashierId);

// Approve through StockIssueService
$service->approveReport($adjIdE2E, $managerId, 'Manager approved 3 damaged');

$finalQoh = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$pE2E}")->fetchColumn();
$finalRem = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$bE2E}")->fetchColumn();
$assert($finalQoh === 7, 'Final QOH is 7');
$assert($finalRem === 7, 'Final batch remaining is 7');
$assert($finalQoh === $finalRem, 'Core invariant preserved through StockIssueService approval');

$adjRow = $pdo->query("SELECT status, approved_by FROM inventory_adjustments WHERE adjustment_id = {$adjIdE2E}")->fetch(PDO::FETCH_ASSOC);
$assert($adjRow['status'] === 'approved' && (int)$adjRow['approved_by'] === $managerId, 'Adjustment marked approved with actor attribution');

$reportBatches = $service->getReportBatches($adjIdE2E);
$assert(count($reportBatches) === 1 && (int)$reportBatches[0]['quantity'] === 3, 'stock_issue_batches attribution recorded');

// -------------------------------------------------------------------------
// Summary
// -------------------------------------------------------------------------
if ($failures) {
    fwrite(STDERR, "\nBatch Inventory Ledger tests FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nAll Batch Inventory Ledger tests passed successfully!\n";
