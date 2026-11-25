<?php
// Contract and integration test for Ticket #219: Deduct affected batches when approving Stock Issues.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/StockIssueService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';

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
            $e instanceof RuntimeException || $e instanceof DomainException,
            $message . ' (threw ' . $e::class . ': ' . $e->getMessage() . ')'
        );
    }
};

echo "Starting Ticket #219 stock issue batch deduction tests...\n";

// SQLite test database setup
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo'] = $pdo;

// Create required tables
$pdo->exec("CREATE TABLE branches (
    branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name VARCHAR(100) NOT NULL UNIQUE,
    branch_code VARCHAR(30) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO branches (branch_name, branch_code, status) VALUES ('Main Store', 'MAIN-STORE', 'active')");
$storeId = (int)$pdo->lastInsertId();

$pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name VARCHAR(50) NOT NULL UNIQUE)");
$pdo->exec("INSERT INTO roles (role_name) VALUES ('cashier'), ('inventory_manager'), ('admin')");

$roleId = static function (string $name) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn();
};

$pdo->exec("CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name VARCHAR(150) NOT NULL DEFAULT 'Test User',
    display_name VARCHAR(150) NOT NULL DEFAULT 'Test User',
    branch_id INTEGER NULL,
    role_id INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
)");
$insertUser = static function (string $name, string $role) use ($pdo, $roleId, $storeId): int {
    $stmt = $pdo->prepare('INSERT INTO users (full_name, display_name, branch_id, role_id, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$name, $name, $storeId, $roleId($role), 'active']);
    return (int)$pdo->lastInsertId();
};
$cashierId = $insertUser('Carl Cashier', 'cashier');
$managerId = $insertUser('Mina Manager', 'inventory_manager');
$adminId = $insertUser('Adam Admin', 'admin');

$pdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NULL,
    sku VARCHAR(50) NOT NULL,
    barcode VARCHAR(50) NOT NULL DEFAULT '',
    product_name VARCHAR(150) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name, status)
            VALUES ({$storeId}, 'SKU-BREAD', 'CODE-BREAD', 'Bread Loaves', 'active')");
$breadId = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name, status)
            VALUES ({$storeId}, 'SKU-MILK', 'CODE-MILK', 'Fresh Milk', 'active')");
$milkId = (int)$pdo->lastInsertId();

$pdo->exec("CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER NOT NULL DEFAULT 0)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$breadId}, 20)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$milkId}, 10)");

$pdo->exec("CREATE TABLE product_batches (
    batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    receiving_id INTEGER NULL,
    batch_number VARCHAR(100) NOT NULL,
    quantity INTEGER NOT NULL,
    remaining_quantity INTEGER NOT NULL,
    expiration_date DATE NULL,
    date_received DATE NULL,
    supplier VARCHAR(150) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products (product_id)
)");
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date)
            VALUES ({$breadId}, 'BATCH-BREAD-01', 20, 20, '2026-10-25')");
$breadBatchId = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date)
            VALUES ({$milkId}, 'BATCH-MILK-01', 10, 10, '2026-10-30')");
$milkBatchId = (int)$pdo->lastInsertId();

$pdo->exec("CREATE TABLE cashier_shifts (
    shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cashier_id INTEGER NOT NULL,
    opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'open'
)");

$pdo->exec("CREATE TABLE inventory_adjustments (
    adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    adjustment_qty INTEGER NOT NULL,
    form TEXT NOT NULL DEFAULT 'piece',
    reported_qty INTEGER NULL,
    pack_size INTEGER NULL,
    adjustment_type TEXT NOT NULL,
    reported_by INTEGER NOT NULL,
    shift_id INTEGER NULL,
    reported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by INTEGER NULL,
    approved_at TEXT NULL,
    reason TEXT NULL,
    review_notes TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
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

$pdo->exec("CREATE TABLE stock_movements (
    movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    change_qty INTEGER NOT NULL,
    reason TEXT NOT NULL,
    moved_by INTEGER NULL,
    adjustment_id INTEGER NULL,
    moved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE stock_issue_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    adjustment_id INTEGER NOT NULL,
    batch_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (adjustment_id) REFERENCES inventory_adjustments (adjustment_id) ON DELETE CASCADE,
    FOREIGN KEY (batch_id) REFERENCES product_batches (batch_id)
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

$pdo->exec("CREATE TABLE notifications (
    notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    reference_id INTEGER NULL,
    reference_type TEXT NULL,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

require_once __DIR__ . '/support/notification_schema.php';
notification_test_schema($pdo);

$quietNotifications = new class($pdo) extends NotificationService {
    public function checkAndNotifyLowStock(): void {}
    public function checkAndNotifyExpiringStock(int $days = 30): void {}
};

$service = new StockIssueService($pdo, $quietNotifications);

// Helper lambdas
$openShift = static function () use ($pdo, $cashierId): int {
    $pdo->prepare("INSERT INTO cashier_shifts (cashier_id, status) VALUES ({$cashierId}, 'open')")->execute();
    return (int)$pdo->lastInsertId();
};
$shiftId = $openShift();

$getInventory = static function (int $productId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id = ?');
    $stmt->execute([$productId]);
    return (int)$stmt->fetchColumn();
};

$getBatchRemaining = static function (int $batchId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT remaining_quantity FROM product_batches WHERE batch_id = ?');
    $stmt->execute([$batchId]);
    return (int)$stmt->fetchColumn();
};

// -------------------------------------------------------------------------
// Test 1: Starting with 20 loaves, approving 3 damaged leaves total and batch at 17, 1 movement, batch attribution saved.
// -------------------------------------------------------------------------
echo "\n--- Test 1: 20 loaves -> approve 3 damaged -> total 17, batch 17, 1 movement, attribution saved ---\n";
$assert($getInventory($breadId) === 20, 'Initial bread inventory is 20');
$assert($getBatchRemaining($breadBatchId) === 20, 'Initial bread batch remaining is 20');

$adjId1 = $service->submitReport([
    'product_id' => $breadId,
    'category' => 'damaged',
    'quantity' => 3,
    'explanation' => 'Three loaves crushed in crate.',
], $cashierId);

// Approve with explicit batch allocation
$service->approveReport($adjId1, $managerId, 'Confirmed 3 damaged loaves', [
    ['batch_id' => $breadBatchId, 'quantity' => 3],
]);

$assert($getInventory($breadId) === 17, 'Bread inventory after approval is 17');
$assert($getBatchRemaining($breadBatchId) === 17, 'Bread batch remaining after approval is 17');

$movementStmt = $pdo->prepare('SELECT * FROM stock_movements WHERE adjustment_id = ?');
$movementStmt->execute([$adjId1]);
$movements = $movementStmt->fetchAll(PDO::FETCH_ASSOC);
$assert(count($movements) === 1, 'Exactly one stock movement recorded');
$assert((int)$movements[0]['change_qty'] === -3, 'Movement change_qty is -3');
$assert($movements[0]['reason'] === 'adjustment', 'Movement reason is adjustment');
$assert((int)$movements[0]['moved_by'] === $managerId, 'Movement attributed to manager');

$reportBatches = $service->getReportBatches($adjId1);
$assert(count($reportBatches) === 1, 'One batch attribution recorded');
$assert((int)$reportBatches[0]['batch_id'] === $breadBatchId, 'Attribution references the correct batch');
$assert((int)$reportBatches[0]['quantity'] === 3, 'Attributed quantity is 3');

// -------------------------------------------------------------------------
// Test 2: Rejection of invalid allocations (negative, fractional, wrong product, excessive quantity, sum mismatch).
// -------------------------------------------------------------------------
echo "\n--- Test 2: Rejection of invalid allocations ---\n";

$adjId2 = $service->submitReport([
    'product_id' => $breadId,
    'category' => 'damaged',
    'quantity' => 2,
    'explanation' => 'Two loaves stale.',
], $cashierId);

// Negative allocation
$throws(
    static fn() => $service->approveReport($adjId2, $managerId, 'note', [
        ['batch_id' => $breadBatchId, 'quantity' => -2],
    ]),
    'Negative allocation must be rejected'
);

// Fractional allocation (float)
$throws(
    static fn() => $service->approveReport($adjId2, $managerId, 'note', [
        ['batch_id' => $breadBatchId, 'quantity' => 1.5],
    ]),
    'Fractional float allocation must be rejected'
);

// Fractional allocation (string)
$throws(
    static fn() => $service->approveReport($adjId2, $managerId, 'note', [
        ['batch_id' => $breadBatchId, 'quantity' => '1.5'],
    ]),
    'Fractional string allocation must be rejected'
);

// Wrong product batch
$throws(
    static fn() => $service->approveReport($adjId2, $managerId, 'note', [
        ['batch_id' => $milkBatchId, 'quantity' => 2],
    ]),
    'Allocation of batch belonging to different product must be rejected'
);

// Excessive quantity (more than batch remaining)
$adjIdExcessive = $service->submitReport([
    'product_id' => $breadId,
    'category' => 'damaged',
    'quantity' => 20,
    'explanation' => 'Twenty loaves damaged.',
], $cashierId);
$throws(
    static fn() => $service->approveReport($adjIdExcessive, $managerId, 'note', [
        ['batch_id' => $breadBatchId, 'quantity' => 20],
    ]),
    'Allocation exceeding single batch remaining quantity must be rejected'
);

// Sum mismatch (sum < reported)
$throws(
    static fn() => $service->approveReport($adjId2, $managerId, 'note', [
        ['batch_id' => $breadBatchId, 'quantity' => 1],
    ]),
    'Allocation sum less than reported quantity must be rejected'
);

// Sum mismatch (sum > reported)
$throws(
    static fn() => $service->approveReport($adjId2, $managerId, 'note', [
        ['batch_id' => $breadBatchId, 'quantity' => 3],
    ]),
    'Allocation sum greater than reported quantity must be rejected'
);

// State must be completely untouched after rejected validation attempts
$assert($getInventory($breadId) === 17, 'Bread inventory remained 17 after rejected allocations');
$assert($getBatchRemaining($breadBatchId) === 17, 'Bread batch remained 17 after rejected allocations');

// -------------------------------------------------------------------------
// Test 3: Auto-allocation via FEFO when batchAllocations is omitted/null.
// -------------------------------------------------------------------------
echo "\n--- Test 3: Auto-allocation via FEFO ---\n";
// Add second batch for bread with later expiration
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date)
            VALUES ({$breadId}, 'BATCH-BREAD-02', 10, 10, '2026-11-01')");
$breadBatch2Id = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE inventory SET quantity_on_hand = quantity_on_hand + 10 WHERE product_id = ?')->execute([$breadId]);
$assert($getInventory($breadId) === 27, 'Bread inventory updated to 27');

// BreadBatch 1 has 17 (exp 2026-10-25), BreadBatch 2 has 10 (exp 2026-11-01).
// Approve report #adjId2 (qty 2) with null allocations: FEFO should allocate 2 from BreadBatch 1.
$service->approveReport($adjId2, $managerId, 'FEFO auto-allocated', null);
$assert($getInventory($breadId) === 25, 'Bread inventory is 25 after FEFO approval');
$assert($getBatchRemaining($breadBatchId) === 15, 'BreadBatch 1 remaining is 15 (earliest batch deducted)');
$assert($getBatchRemaining($breadBatch2Id) === 10, 'BreadBatch 2 remaining is 10 (later batch untouched)');

$feFoBatches = $service->getReportBatches($adjId2);
$assert(count($feFoBatches) === 1, 'One batch attributed under FEFO');
$assert((int)$feFoBatches[0]['batch_id'] === $breadBatchId, 'FEFO picked earliest batch');
$assert((int)$feFoBatches[0]['quantity'] === 2, 'FEFO deducted 2');

// Multi-batch span FEFO test: report 16 units -> 15 from batch 1, 1 from batch 2
$adjId3 = $service->submitReport([
    'product_id' => $breadId,
    'category' => 'expired',
    'quantity' => 16,
    'explanation' => 'Sixteen loaves past pull date.',
], $cashierId);

$service->approveReport($adjId3, $managerId, 'Approve multi-batch expiration');
$assert($getInventory($breadId) === 9, 'Bread inventory is 9 (25 - 16)');
$assert($getBatchRemaining($breadBatchId) === 0, 'BreadBatch 1 is depleted (15 - 15 = 0)');
$assert($getBatchRemaining($breadBatch2Id) === 9, 'BreadBatch 2 remaining is 9 (10 - 1 = 9)');

$spanBatches = $service->getReportBatches($adjId3);
$assert(count($spanBatches) === 2, 'Two batches attributed across span');
$assert((int)$spanBatches[0]['batch_id'] === $breadBatchId && (int)$spanBatches[0]['quantity'] === 15, 'Batch 1 depleted by 15');
$assert((int)$spanBatches[1]['batch_id'] === $breadBatch2Id && (int)$spanBatches[1]['quantity'] === 1, 'Batch 2 deducted 1');

// -------------------------------------------------------------------------
// Test 4: Duplicate approval prevention.
// -------------------------------------------------------------------------
echo "\n--- Test 4: Duplicate approval prevention ---\n";
$throws(
    static fn() => $service->approveReport($adjId3, $managerId),
    'Approving an already approved report must be refused'
);

// -------------------------------------------------------------------------
// Test 5: Atomic rollback on failure during approval.
// -------------------------------------------------------------------------
echo "\n--- Test 5: Atomic rollback on injected failure ---\n";
$adjIdRollback = $service->submitReport([
    'product_id' => $breadId,
    'category' => 'damaged',
    'quantity' => 4,
    'explanation' => 'Four loaves damaged in storage.',
], $cashierId);

$invBefore = $getInventory($breadId);
$batchBefore = $getBatchRemaining($breadBatch2Id);

// Temporarily add trigger to simulate database failure during batch write
$pdo->exec("CREATE TRIGGER fail_batch_insert BEFORE INSERT ON stock_issue_batches BEGIN SELECT RAISE(ABORT, 'Injected batch write failure'); END;");

$throws(
    static fn() => $service->approveReport($adjIdRollback, $managerId, 'Should fail and rollback'),
    'Approval must throw when batch recording fails'
);

// Verify atomic rollback: inventory and batch remaining were rolled back
$assert($getInventory($breadId) === $invBefore, 'Inventory rolled back completely on failure');
$assert($getBatchRemaining($breadBatch2Id) === $batchBefore, 'Batch remaining rolled back completely on failure');
$stmt = $pdo->prepare('SELECT status FROM inventory_adjustments WHERE adjustment_id = ?');
$stmt->execute([$adjIdRollback]);
$assert($stmt->fetchColumn() === 'pending', 'Report remains pending after rolled-back approval');

// Drop trigger
$pdo->exec("DROP TRIGGER fail_batch_insert;");

// -------------------------------------------------------------------------
// Test 6: Concurrent overlapping deductions rejection.
// -------------------------------------------------------------------------
echo "\n--- Test 6: Concurrent overlapping deductions ---\n";
// BreadBatch 2 now has 9 remaining.
// Cashier submits report A for 6, report B for 5. Total = 11 > 9.
$adjIdA = $service->submitReport([
    'product_id' => $breadId,
    'category' => 'damaged',
    'quantity' => 6,
    'explanation' => 'Six units damaged.',
], $cashierId);

$adjIdB = $service->submitReport([
    'product_id' => $breadId,
    'category' => 'damaged',
    'quantity' => 5,
    'explanation' => 'Five units damaged.',
], $cashierId);

// Approve A: 9 - 6 = 3 left
$service->approveReport($adjIdA, $managerId, 'Approve A');
$assert($getInventory($breadId) === 3, 'Inventory is 3');
$assert($getBatchRemaining($breadBatch2Id) === 3, 'Batch remaining is 3');

// Approving B now fails because only 3 remain for 5 requested
$throws(
    static fn() => $service->approveReport($adjIdB, $managerId, 'Approve B'),
    'Concurrent overlapping deduction must be refused when remaining batch/total is insufficient'
);
$assert($getInventory($breadId) === 3, 'Inventory preserved at 3');
$assert($getBatchRemaining($breadBatch2Id) === 3, 'Batch remaining preserved at 3');

// -------------------------------------------------------------------------
// Test 7: Preserve returned-for-correction, edit, resubmit, reject, cancel.
// -------------------------------------------------------------------------
echo "\n--- Test 7: Lifecycle preservation (return, edit, resubmit, reject, cancel) ---\n";

// Legacy waiting reports can be decided without another reply.
$pdo->prepare("UPDATE inventory_adjustments SET status='returned', review_notes='Please check physical count; only 3 units present.', approved_by=? WHERE adjustment_id=?")->execute([$managerId, $adjIdB]);
$throws(static fn() => $service->sendMessage($adjIdB, $cashierId, 'cashier', 'Reply'), 'Legacy chat is read-only');
$throws(static fn() => $service->editReport($adjIdB, $cashierId, ['quantity' => 3]), 'Reported details remain locked');
$service->approveReport($adjIdB, $managerId, 'Verified 3 units after recount', [$breadBatch2Id => 3], '', 3);
$assert($getInventory($breadId) === 0, 'Inventory is 0');
$assert($getBatchRemaining($breadBatch2Id) === 0, 'Batch 2 is depleted (0)');

// Test cancel lifecycle on another report
$adjIdCancel = $service->submitReport([
    'product_id' => $milkId,
    'category' => 'other',
    'quantity' => 1,
    'explanation' => 'Accidentally dropped, testing cancellation.',
], $cashierId);
$service->cancelReport($adjIdCancel, $cashierId);
$stmt = $pdo->prepare('SELECT status FROM inventory_adjustments WHERE adjustment_id = ?');
$stmt->execute([$adjIdCancel]);
$assert($stmt->fetchColumn() === 'cancelled', 'Report cancelled');
$assert($getInventory($milkId) === 10, 'Milk inventory unchanged after cancel');
$assert($getBatchRemaining($milkBatchId) === 10, 'Milk batch unchanged after cancel');

// Test reject lifecycle
$adjIdReject = $service->submitReport([
    'product_id' => $milkId,
    'category' => 'missing',
    'quantity' => 2,
    'explanation' => 'Two cartons missing from cooler.',
], $cashierId);
$service->rejectReport($adjIdReject, $managerId, 'Cartons found in receiving area.');
$stmt = $pdo->prepare('SELECT status, review_notes FROM inventory_adjustments WHERE adjustment_id = ?');
$stmt->execute([$adjIdReject]);
$rejRow = $stmt->fetch(PDO::FETCH_ASSOC);
$assert($rejRow['status'] === 'rejected', 'Report rejected');
$assert($getInventory($milkId) === 10, 'Milk inventory unchanged after reject');
$assert($getBatchRemaining($milkBatchId) === 10, 'Milk batch unchanged after reject');

// -------------------------------------------------------------------------
// Test 8: Role, Fiscal Period, and Oversight Checks.
// -------------------------------------------------------------------------
echo "\n--- Test 8: Workspace permissions, Fiscal Period, and Administrator oversight ---\n";

$adjIdPerms = $service->submitReport([
    'product_id' => $milkId,
    'category' => 'expired',
    'quantity' => 1,
    'explanation' => 'One carton expired.',
], $cashierId);

// Cashier cannot approve
$throws(
    static fn() => $service->approveReport($adjIdPerms, $cashierId),
    'Cashier cannot approve report'
);

// Administrator cannot approve (oversight only)
$throws(
    static fn() => $service->approveReport($adjIdPerms, $adminId),
    'Administrator cannot approve report'
);

// Administrator oversight can read report details including batches
$detail = $service->getReportDetail($adjId1, $adminId);
$assert($detail !== null, 'Administrator can view report detail');
$assert(!empty($detail['batches']), 'Report detail includes deducted batches attribution');
$assert((int)$detail['batches'][0]['quantity'] === 3, 'Batch attribution details match');

$pdo->exec('CREATE TABLE inventory_submissions (user_id INTEGER, operation_kind TEXT, operation_token TEXT, payload_hash TEXT, result_json TEXT, PRIMARY KEY(user_id, operation_kind, operation_token))');

// Closed fiscal period blocks approval
$pdo->exec("UPDATE fiscal_periods SET status = 'closed'");
$periodId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO fiscal_period_locks (period_id, table_name) VALUES ({$periodId}, 'inventory_adjustments')");

$throws(
    static fn() => $service->approveReport($adjIdPerms, $managerId),
    'Approval blocked when fiscal period is closed'
);

$throws(static fn() => $service->recordConfirmedIssue(['product_id'=>$milkId,'category'=>'damaged','quantity'=>1,'explanation'=>'One leaking carton.','operation_token'=>str_repeat('e',48)],$managerId), 'Direct recording is blocked by a closed Fiscal Period');

// -------------------------------------------------------------------------
// Test 9: Legacy unbatched product fallback.
// -------------------------------------------------------------------------
echo "\n--- Test 9: Legacy unbatched product fallback ---\n";
// Clear fiscal period lock
$pdo->exec("DELETE FROM fiscal_period_locks");
$pdo->exec("UPDATE fiscal_periods SET status = 'open'");

// Product with 0 batches in product_batches
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name, status)
            VALUES ({$storeId}, 'SKU-UNBATCHED', 'CODE-UNBATCHED', 'General Merchandise', 'active')");
$unbatchedId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$unbatchedId}, 50)");

$adjIdUnbatched = $service->submitReport([
    'product_id' => $unbatchedId,
    'category' => 'damaged',
    'quantity' => 5,
    'explanation' => 'Five unbatched items damaged.',
], $cashierId);

$throws(static fn() => $service->approveReport($adjIdUnbatched, $managerId, 'Unbatched issue'), 'Missing batches refuse approval');
$assert($getInventory($unbatchedId) === 50, 'Unbatched product inventory remains unchanged');
$unbatchedReportBatches = $service->getReportBatches($adjIdUnbatched);
$assert(empty($unbatchedReportBatches), 'No batch attribution rows created for unbatched product');

// -------------------------------------------------------------------------
// Summary
// -------------------------------------------------------------------------
echo "\n--- Verified quantities: smaller, larger, invalid, and zero ---\n";
$pdo->exec("INSERT INTO products (branch_id, sku, barcode, product_name, status) VALUES ({$storeId}, 'VERIFIED', 'VERIFIED', 'Verified stock fixture', 'active')");
$verifiedProduct = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES ({$verifiedProduct}, 12)");
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity) VALUES ({$verifiedProduct}, 'VERIFIED', 12, 12)");
$verifiedBatch = (int)$pdo->lastInsertId();
$newReport = static fn() => $service->submitReport(['product_id' => $verifiedProduct, 'category' => 'damaged', 'quantity' => 5, 'explanation' => 'Five containers reported punctured.'], $cashierId);
$smaller = $newReport();
foreach ([0, -1, '2.5', 2.5, '', [], '2147483648'] as $invalid) {
    $throws(static fn() => $service->approveReport($smaller, $managerId, 'Inspection', null, '', $invalid), 'Invalid verified quantity refused: ' . json_encode($invalid));
}
$throws(static fn() => $service->approveReport($smaller, $managerId, ' ', null, '', 2), 'Changed quantity requires a reason');
$throws(static fn() => $service->approveReport($smaller, $managerId, 'Two damaged', [$verifiedBatch => 5], '', 2), 'Allocation must match verified quantity');
$throws(static fn() => $service->approveReport($smaller, $managerId, 'More damaged', null, '', 13), 'Verified quantity cannot exceed stock');
$assert($getInventory($verifiedProduct) === 12 && $getBatchRemaining($verifiedBatch) === 12, 'All refused approvals leave stock and batches unchanged');
$approvalToken = str_repeat('a', 48);
$service->approveReport($smaller, $managerId, 'Only two are punctured after inspection.', [$verifiedBatch => 2], $approvalToken, '2');
$service->approveReport($smaller, $managerId, 'Only two are punctured after inspection.', [$verifiedBatch => 2], $approvalToken, '2');
$assert((int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE adjustment_id={$smaller}")->fetchColumn() === 1, 'Retrying the same verified approval deducts only once');
$throws(static fn() => $service->approveReport($smaller, $managerId, 'Only two are punctured after inspection.', [$verifiedBatch => 2], $approvalToken, 3), 'Retry token cannot authorize a different verified quantity');
$saved = $pdo->query("SELECT * FROM inventory_adjustments WHERE adjustment_id = {$smaller}")->fetch(PDO::FETCH_ASSOC);
$assert($saved['status'] === 'approved' && (int)$saved['reported_qty'] === 5 && (int)$saved['adjustment_qty'] === -2, 'Approval closes report while retaining original 5 and actual deduction 2');
$assert($getInventory($verifiedProduct) === 10 && $getBatchRemaining($verifiedBatch) === 10, 'Exactly two deducted from inventory and batch');
$assert((int)$pdo->query("SELECT change_qty FROM stock_movements WHERE adjustment_id = {$smaller}")->fetchColumn() === -2, 'Linked movement uses verified quantity');
$revisions = $service->getRevisions($smaller);
$lastRevision = end($revisions);
$values = json_decode($lastRevision['new_values'], true);
$assert((int)$lastRevision['actor_id'] === $managerId && $values['reported_qty'] === 5 && $values['verified_qty'] === 2 && $values['review_notes'] !== '', 'History records manager, reported and verified quantities, and reason');
$throws(static fn() => $service->approveReport($smaller, $managerId, 'Retry', null, '', 2), 'Verified approval cannot deduct twice');
$larger = $newReport();
$pdo->exec("UPDATE inventory_adjustments SET reported_qty=NULL WHERE adjustment_id={$larger}");
$service->approveReport($larger, $managerId, 'Seven are punctured after recount.', null, '', 7);
$assert((int)$pdo->query("SELECT reported_qty FROM inventory_adjustments WHERE adjustment_id={$larger}")->fetchColumn() === 5, 'Legacy report without quantity snapshot retains original quantity on approval');
$assert($getInventory($verifiedProduct) === 3 && $getBatchRemaining($verifiedBatch) === 3, 'Verified quantity may exceed reported quantity with available stock');
$zero = $newReport();
$beforeMovements = (int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
$service->rejectReport($zero, $managerId, 'No damage found after inspection.');
$assert($getInventory($verifiedProduct) === 3 && $getBatchRemaining($verifiedBatch) === 3 && (int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn() === $beforeMovements, 'Rejecting zero affected units leaves stock, batches and movements unchanged');
$assert(StockIssueService::formatIssueQuantity(['status' => 'approved', 'form' => 'pack', 'reported_qty' => 5, 'adjustment_qty' => -12, 'pack_size' => 6]) === '-2 packs (12 pcs)', 'Approved pack display shows actual deduction rather than original report');

echo "\n--- Direct Manager recording ---\n";
$directData = ['product_id' => $milkId, 'category' => 'damaged', 'quantity' => 2, 'form' => 'piece', 'explanation' => 'Confirmed two leaking cartons.', 'operation_token' => str_repeat('b',48)];
$before = $getInventory($milkId);
$direct = $service->recordConfirmedIssue($directData, $managerId);
$retry = $service->recordConfirmedIssue($directData, $managerId);
$row = $service->getReport($direct);
$assert($retry === $direct && $getInventory($milkId) === $before - 2, 'Direct recording and retry deduct exactly once');
$assert($row['status'] === 'approved' && (int)$row['reported_by'] === $managerId && (int)$row['approved_by'] === $managerId && $row['shift_id'] === null, 'Direct record is final with Manager attribution and no Cashier Shift');
$assert((int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE adjustment_id={$direct}")->fetchColumn() === 1, 'Direct issue has one linked movement');
$throws(static fn() => $service->recordConfirmedIssue(array_replace($directData,['quantity'=>3]), $managerId), 'A completed token cannot record different quantity');
foreach ([$cashierId, $adminId] as $actor) $throws(static fn() => $service->recordConfirmedIssue($directData,$actor), 'Only Managers can directly record');
$throws(static fn() => (new StockIssueService($pdo,null,'cashier'))->recordConfirmedIssue($directData,$managerId), 'Cashier Workspace cannot inherit direct Manager authority');
foreach ([0,-1,'2.5',[]] as $invalid) $throws(static fn() => $service->recordConfirmedIssue(array_replace($directData,['quantity'=>$invalid,'operation_token'=>'']),$managerId), 'Invalid direct quantity refused');
$recordsBefore = (int)$pdo->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn();
$pdo->exec("CREATE TRIGGER fail_direct_batch BEFORE INSERT ON stock_issue_batches BEGIN SELECT RAISE(ABORT, 'Direct write failure'); END");
$throws(static fn() => $service->recordConfirmedIssue(array_replace($directData,['operation_token'=>str_repeat('c',48)]),$managerId), 'Direct deduction failure rolls back record');
$pdo->exec('DROP TRIGGER fail_direct_batch');
$assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn() === $recordsBefore && $getInventory($milkId) === $before-2, 'Failed direct issue leaves no report and no deduction');
$throws(static fn() => $service->recordConfirmedIssue(array_replace($directData,['quantity'=>999,'operation_token'=>str_repeat('d',48)]),$managerId), 'Direct recording refuses insufficient stock');
$assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn() === $recordsBefore, 'Insufficient stock leaves no unfinished direct issue');

if ($failures) {
    fwrite(STDERR, "\nStock issue batch deduction tests FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nAll Stock issue batch deduction tests passed!\n";
