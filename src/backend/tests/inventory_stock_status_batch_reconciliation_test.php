<?php
// Contract and integration test for Ticket #448:
// Inventory Stock Status & Batch Reconciliation.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/BatchInventoryLedger.php';
require_once __DIR__ . '/../app/Services/InventoryCountService.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
require_once __DIR__ . '/../app/Services/StockIssueService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';

use App\Services\BatchInventoryLedger;
// InventoryCountService is in global namespace

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

echo "Starting Ticket #448: Inventory Stock Status & Batch Reconciliation tests...\n";

// =========================================================================
// 1. Static Contract & CSS Badge Differentiation Checks
// =========================================================================
echo "\n--- Step 1: Static Contract & Badge Checks ---\n";
$overviewFile = dirname(__DIR__, 2) . '/frontend/components/inventory_management/inventory_overview.php';
$countsFile = dirname(__DIR__, 2) . '/frontend/components/inventory_management/inventory_counts.php';
$cssFile = dirname(__DIR__, 2) . '/frontend/assets/css/inventory.css';

$overviewSrc = (string)file_get_contents($overviewFile);
$countsSrc = (string)file_get_contents($countsFile);
$cssSrc = (string)file_get_contents($cssFile);

// Check distinct color classes in CSS
$assert(str_contains($cssSrc, '.overview-stock-status--available'), 'inventory.css defines .overview-stock-status--available');
$assert(str_contains($cssSrc, '.overview-stock-status--low'), 'inventory.css defines .overview-stock-status--low');
$assert(str_contains($cssSrc, '.overview-stock-status--expiring'), 'inventory.css defines .overview-stock-status--expiring');
$assert(str_contains($cssSrc, '.overview-stock-status--out'), 'inventory.css defines .overview-stock-status--out');
$assert(str_contains($cssSrc, '.overview-stock-status--expired'), 'inventory.css defines .overview-stock-status--expired');

// Verify distinct separation (not lumped together)
$assert(!str_contains($cssSrc, '.overview-stock-status--low,.overview-stock-status--expiring'), 'Low and Expiring badges have distinct CSS rules');
$assert(!str_contains($cssSrc, '.overview-stock-status--out,.overview-stock-status--expired'), 'Out and Expired badges have distinct CSS rules');

// Check fast filtering options in overview table
$assert(str_contains($overviewSrc, 'value="in-stock"'), 'Overview has in-stock filter');
$assert(str_contains($overviewSrc, 'value="low"'), 'Overview has low stock filter');
$assert(str_contains($overviewSrc, 'value="expiring"'), 'Overview has expiring filter');
$assert(str_contains($overviewSrc, 'value="out"'), 'Overview has out of stock filter');
$assert(str_contains($overviewSrc, 'value="expired"'), 'Overview has expired filter');
$assert(str_contains($overviewSrc, 'data-stock-state'), 'Rows have data-stock-state attribute');
$assert(str_contains($overviewSrc, 'data-low'), 'Rows have data-low attribute');
$assert(str_contains($overviewSrc, 'data-expiring'), 'Rows have data-expiring attribute');
$assert(str_contains($overviewSrc, 'data-expired'), 'Rows have data-expired attribute');

// Check reconciliation worksheet has variance indicators
$assert(str_contains($countsSrc, 'batch_counts_table'), 'inventory_counts.php has batch_counts_table');
$assert(str_contains($countsSrc, 'Variance') || str_contains($countsSrc, 'variance'), 'Worksheet table has Variance column or calculation');
$assert(str_contains($countsSrc, 'Surplus') || str_contains($countsSrc, 'surplus'), 'Worksheet supports Surplus calculation/label');
$assert(str_contains($countsSrc, 'Deficit') || str_contains($countsSrc, 'deficit'), 'Worksheet supports Deficit calculation/label');

// =========================================================================
// 2. Database Integration & FIFO Order / Batch Provenance Tests
// =========================================================================
echo "\n--- Step 2: FIFO Ordering & Expiration Date Provenance ---\n";

class SqliteTestPdo448 extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query);
        $query = str_replace('DATE_ADD(CURDATE(), INTERVAL ? DAY)', "DATE(CURDATE(), '+' || ? || ' day')", $query);
        return parent::prepare($query, $options);
    }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $query = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query);
        $query = str_replace('DATE_ADD(CURDATE(), INTERVAL ? DAY)', "DATE(CURDATE(), '+' || ? || ' day')", $query);
        return parent::query($query, ...$fetchModeArgs);
    }
}

$pdo = new SqliteTestPdo448('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
$GLOBALS['pdo'] = $pdo;

// DDL Setup
$pdo->exec("CREATE TABLE branches (
    branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name TEXT NOT NULL,
    branch_code TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code) VALUES (1, 'Main Store', 'MAIN')");

$pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT NOT NULL)");
$pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin')");

$pdo->exec("CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    display_name TEXT NOT NULL,
    branch_id INTEGER NULL,
    role_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO users (user_id, full_name, display_name, branch_id, role_id) VALUES
    (1, 'Ivan Inventory', 'Ivan Inventory', 1, 2),
    (2, 'Carl Cashier', 'Carl Cashier', 1, 1)");
$managerId = 1;

$pdo->exec("CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER)");
$pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (1, 2)");

$pdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NULL,
    sku TEXT NOT NULL,
    barcode TEXT NOT NULL DEFAULT '',
    product_name TEXT NOT NULL,
    brand TEXT NOT NULL DEFAULT '',
    pack_size INTEGER NULL,
    selling_mode TEXT NOT NULL DEFAULT 'piece',
    status TEXT NOT NULL DEFAULT 'active',
    reorder_level INTEGER NOT NULL DEFAULT 5,
    safety_stock INTEGER NOT NULL DEFAULT 2,
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
    batch_number TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    remaining_quantity INTEGER NOT NULL,
    sealed_quantity INTEGER NOT NULL DEFAULT 0,
    loose_quantity INTEGER NOT NULL DEFAULT 0,
    expiration_date DATE NULL,
    date_received DATE NULL,
    supplier TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE inventory_adjustments (
    adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    adjustment_qty INTEGER NOT NULL,
    adjustment_type TEXT NOT NULL,
    reported_by INTEGER NOT NULL,
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE inventory_counts (
    count_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    system_quantity INTEGER NOT NULL,
    physical_quantity INTEGER NOT NULL,
    difference_qty INTEGER NOT NULL,
    system_sealed_quantity INTEGER NOT NULL DEFAULT 0,
    system_loose_quantity INTEGER NOT NULL DEFAULT 0,
    physical_sealed_quantity INTEGER NOT NULL DEFAULT 0,
    physical_loose_quantity INTEGER NOT NULL DEFAULT 0,
    difference_sealed_qty INTEGER NOT NULL DEFAULT 0,
    difference_loose_qty INTEGER NOT NULL DEFAULT 0,
    discrepancy_reason TEXT NOT NULL,
    counted_by INTEGER NOT NULL,
    counted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_by INTEGER NULL,
    approved_at TIMESTAMP NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    related_adjustment_id INTEGER NULL
)");

$pdo->exec("CREATE TABLE inventory_count_batches (
    count_batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    count_id INTEGER NOT NULL,
    batch_id INTEGER NULL,
    system_quantity INTEGER NOT NULL DEFAULT 0,
    physical_quantity INTEGER NOT NULL DEFAULT 0,
    difference_qty INTEGER NOT NULL DEFAULT 0,
    system_sealed_quantity INTEGER NOT NULL DEFAULT 0,
    system_loose_quantity INTEGER NOT NULL DEFAULT 0,
    physical_sealed_quantity INTEGER NOT NULL DEFAULT 0,
    physical_loose_quantity INTEGER NOT NULL DEFAULT 0,
    difference_sealed_qty INTEGER NOT NULL DEFAULT 0,
    difference_loose_qty INTEGER NOT NULL DEFAULT 0,
    expiration_date DATE NULL,
    batch_number TEXT NULL,
    notes TEXT NULL
)");

$pdo->exec("CREATE TABLE stock_movements (
    movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    change_qty INTEGER NOT NULL,
    reason TEXT NOT NULL,
    moved_by INTEGER NULL,
    adjustment_id INTEGER NULL,
    moved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE fiscal_periods (
    period_id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_name TEXT NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open'
)");
$pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2026-01-01', '2026-12-31', 'open')");

$pdo->exec("CREATE TABLE fiscal_period_locks (period_id INTEGER NOT NULL, table_name TEXT NULL)");

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
    email_pending INTEGER DEFAULT 0,
    resolved_at TIMESTAMP NULL
)");

$pdo->exec("CREATE TABLE attention_states (
    user_id INTEGER NOT NULL,
    attention_key TEXT NOT NULL,
    fingerprint TEXT NOT NULL,
    notification_level INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    first_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    PRIMARY KEY (user_id, attention_key)
)");

$pdo->exec("CREATE TABLE notification_preferences (
    user_id INTEGER PRIMARY KEY,
    notify_low_stock INTEGER DEFAULT 1,
    notify_expiring_stock INTEGER DEFAULT 1,
    low_stock_threshold INTEGER DEFAULT NULL,
    notify_inapp INTEGER DEFAULT 1
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE inventory_submissions (
    user_id INTEGER,
    operation_kind TEXT,
    operation_token TEXT,
    payload_hash TEXT,
    result_json TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, operation_kind, operation_token)
)");

// Product 1: FIFO Ordering Test Product
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name) VALUES (1, 'SKU-FIFO', 'BC-FIFO', 'FIFO Milk Tea')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 60)");

// Insert batches:
// Batch A: Exp 2026-11-20, Received 2026-10-05 (10 units)
// Batch B: Exp 2026-11-20, Received 2026-10-01 (15 units) -> earlier received date on tie!
// Batch C: Exp 2026-11-10, Received 2026-10-10 (20 units) -> earliest expiration!
// Batch D: Undated (NULL), Received 2026-09-15 (15 units) -> non-perishable/undated
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received) VALUES
    (1, 1, 'BATCH-A', 10, 10, '2026-11-20', '2026-10-05'),
    (2, 1, 'BATCH-B', 15, 15, '2026-11-20', '2026-10-01'),
    (3, 1, 'BATCH-C', 20, 20, '2026-11-10', '2026-10-10'),
    (4, 1, 'BATCH-D', 15, 15, NULL, '2026-09-15')");

$ledger = new BatchInventoryLedger($pdo);

// Deduct 25 units: should take Batch C (all 20, earliest expiration), then Batch B (5 units, earlier received date)
$mutation1 = $ledger->recordStockIssueDeduction(1, 25, 'piece', null, null, $managerId, 101);

$remC = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 3")->fetchColumn();
$remB = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 2")->fetchColumn();
$remA = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 1")->fetchColumn();
$remD = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 4")->fetchColumn();
$qoh1 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();

$assert($remC === 0, 'Batch C (earliest expiration) depleted first');
$assert($remB === 10, 'Batch B (earlier received date on expiration tie) deducted 5 units (15 -> 10)');
$assert($remA === 10, 'Batch A (later received date on expiration tie) untouched at 10');
$assert($remD === 15, 'Undated Batch D untouched at 15');
$assert($qoh1 === 35, 'Quantity on hand correctly deducted from 60 to 35');
$assert($qoh1 === ($remA + $remB + $remC + $remD), 'Invariant verified: QOH matches sum of batch balances (35)');

// Verify allocation records preserved batch provenance
$allocs = $mutation1['allocations'];
$assert(isset($allocs[3]) && $allocs[3]['reported_units'] === 20, 'Allocation accurately references Batch C with 20 units');
$assert(isset($allocs[2]) && $allocs[2]['reported_units'] === 5, 'Allocation accurately references Batch B with 5 units');

// Next deduction of 20 units: should deplete Batch B (10) and Batch A (10)
$mutation2 = $ledger->recordStockIssueDeduction(1, 20, 'piece', null, null, $managerId, 102);
$remB2 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 2")->fetchColumn();
$remA2 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 1")->fetchColumn();
$remD2 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 4")->fetchColumn();
$qoh2 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();

$assert($remB2 === 0, 'Batch B fully depleted');
$assert($remA2 === 0, 'Batch A fully depleted');
$assert($remD2 === 15, 'Undated Batch D remains untouched until all dated batches are consumed');
$assert($qoh2 === 15, 'Quantity on hand is now 15');
$assert($qoh2 === $remD2, 'Core invariant preserved: QOH equals sum of batch balances');

// Final deduction of 5 units: should deduct from undated Batch D
$ledger->recordStockIssueDeduction(1, 5, 'piece', null, null, $managerId, 103);
$remD3 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 4")->fetchColumn();
$qoh3 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert($remD3 === 10, 'Undated Batch D deducted from 15 to 10');
$assert($qoh3 === 10, 'QOH deducted to 10');

// =========================================================================
// 3. Physical Count Worksheet: Surplus & Deficit Variance Calculations
// =========================================================================
echo "\n--- Step 3: Physical Count Worksheet & Variance Calculations ---\n";

$countService = new InventoryCountService($pdo, 'inventory_manager');

// Product 2 for reconciliation
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name) VALUES (2, 'SKU-RECON', 'BC-RECON', 'Reconciliation Coffee')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 30)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date) VALUES
    (10, 2, 'BATCH-COF-1', 20, 20, '2026-11-30'),
    (11, 2, 'BATCH-COF-2', 10, 10, '2026-12-15')");

// Test Count A: Batch 10 has deficit of -3 (counted 17 vs 20 system),
// Batch 11 has surplus of +5 (counted 15 vs 10 system),
// Found extra batch of +4 (counted 4 vs 0 system).
// Total physical = 17 + 15 + 4 = 36. System = 30. Net variance = +6 (Surplus).
$countPayload = [
    'product_id' => 2,
    'discrepancy_reason' => 'Annual physical count audit',
    'batches' => [
        [
            'batch_id' => 10,
            'physical_quantity' => 17, // deficit -3
        ],
        [
            'batch_id' => 11,
            'physical_quantity' => 15, // surplus +5
        ],
        [
            'batch_id' => null,
            'batch_number' => 'FOUND-SHELF',
            'expiration_date' => '2027-02-01',
            'physical_quantity' => 4, // surplus +4
            'notes' => 'Found behind shelf',
        ],
    ],
    'physical_quantity' => 36,
];

$countIdA = $countService->recordCount($countPayload, $managerId);
$countRow = $pdo->query("SELECT * FROM inventory_counts WHERE count_id = {$countIdA}")->fetch(PDO::FETCH_ASSOC);

$assert((int)$countRow['system_quantity'] === 30, 'System quantity recorded as 30');
$assert((int)$countRow['physical_quantity'] === 36, 'Physical quantity recorded as 36');
$assert((int)$countRow['difference_qty'] === 6, 'Net difference automatically computed as +6 (Surplus)');
$assert($countRow['status'] === 'pending', 'Count is pending approval');

$countBatches = $countService->getCountBatches($countIdA);
$assert(count($countBatches) === 3, 'Recorded exactly 3 batch variance lines');

$cb10 = array_values(array_filter($countBatches, fn($b) => (int)$b['batch_id'] === 10))[0];
$assert((int)$cb10['system_quantity'] === 20, 'Batch 10 system quantity is 20');
$assert((int)$cb10['physical_quantity'] === 17, 'Batch 10 physical quantity is 17');
$assert((int)$cb10['difference_qty'] === -3, 'Batch 10 deficit variance automatically computed as -3');

$cb11 = array_values(array_filter($countBatches, fn($b) => (int)$b['batch_id'] === 11))[0];
$assert((int)$cb11['system_quantity'] === 10, 'Batch 11 system quantity is 10');
$assert((int)$cb11['physical_quantity'] === 15, 'Batch 11 physical quantity is 15');
$assert((int)$cb11['difference_qty'] === 5, 'Batch 11 surplus variance automatically computed as +5');

$cbExtra = array_values(array_filter($countBatches, fn($b) => $b['batch_id'] === null))[0];
$assert((int)$cbExtra['system_quantity'] === 0, 'Found batch system quantity is 0');
$assert((int)$cbExtra['physical_quantity'] === 4, 'Found batch physical quantity is 4');
$assert((int)$cbExtra['difference_qty'] === 4, 'Found batch surplus variance automatically computed as +4');

// =========================================================================
// 4. Submitting Approved Physical Count Adjustments: Transparent Ledger Posting
// =========================================================================
echo "\n--- Step 4: Ledger Posting on Approved Count Adjustment ---\n";

$countService->approveCount($countIdA, $managerId, str_repeat('a', 48));

// Check count status updated
$approvedCount = $pdo->query("SELECT status, approved_by, approved_at FROM inventory_counts WHERE count_id = {$countIdA}")->fetch(PDO::FETCH_ASSOC);
$assert($approvedCount['status'] === 'approved', 'Count marked approved');
$assert((int)$approvedCount['approved_by'] === $managerId, 'Attributed to approving manager');

// Check inventory table updated to physical quantity
$qohRecon = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 2")->fetchColumn();
$assert($qohRecon === 36, 'Inventory quantity on hand updated to physical count (36)');

// Check product_batches updated
$rem10 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 10")->fetchColumn();
$rem11 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 11")->fetchColumn();
$extraBatch = $pdo->query("SELECT * FROM product_batches WHERE product_id = 2 AND batch_number = 'FOUND-SHELF'")->fetch(PDO::FETCH_ASSOC);

$assert($rem10 === 17, 'Batch 10 balance reconciled to 17 (deficit applied)');
$assert($rem11 === 15, 'Batch 11 balance reconciled to 15 (surplus applied)');
$assert($extraBatch !== false && (int)$extraBatch['remaining_quantity'] === 4, 'Discovered surplus batch created with 4 units');
$assert($extraBatch['expiration_date'] === '2027-02-01', 'Discovered batch expiration date tracked accurately');

// Invariant verification
$batchSum2 = (int)$pdo->query("SELECT SUM(remaining_quantity) FROM product_batches WHERE product_id = 2")->fetchColumn();
$assert($qohRecon === $batchSum2, 'Invariant strictly maintained: QOH (36) === SUM(batches) (36)');

// Check transparent ledger posting in stock_movements
$movements = $pdo->query("SELECT * FROM stock_movements WHERE product_id = 2 ORDER BY movement_id DESC LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
$assert(count($movements) === 1, 'Stock movement recorded on physical count approval');
$lastMove = $movements[0];
$assert((int)$lastMove['change_qty'] === 6, 'Stock movement change_qty is +6 (matches net surplus variance)');
$assert($lastMove['reason'] === 'adjustment', 'Stock movement reason is "adjustment"');
$assert((int)$lastMove['moved_by'] === $managerId, 'Stock movement moved_by is the approving manager');

// =========================================================================
// 5. Deficit Count Approval Posting
// =========================================================================
echo "\n--- Step 5: Deficit Count Approval Posting ---\n";

// Count B: Deficit of -6 units (counted 30 vs 36 system)
$countPayloadDeficit = [
    'product_id' => 2,
    'discrepancy_reason' => 'Damaged cans written off during recount',
    'batches' => [
        ['batch_id' => 10, 'physical_quantity' => 14], // -3
        ['batch_id' => 11, 'physical_quantity' => 12], // -3
        ['batch_id' => (int)$extraBatch['batch_id'], 'physical_quantity' => 4], // 0
    ],
    'physical_quantity' => 30,
];

$countIdB = $countService->recordCount($countPayloadDeficit, $managerId);
$countRowB = $pdo->query("SELECT difference_qty FROM inventory_counts WHERE count_id = {$countIdB}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$countRowB['difference_qty'] === -6, 'Net deficit variance computed as -6');

$countService->approveCount($countIdB, $managerId, str_repeat('b', 48));

$qohAfterDeficit = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 2")->fetchColumn();
$assert($qohAfterDeficit === 30, 'Inventory quantity on hand adjusted down to 30');

$moveDeficit = $pdo->query("SELECT * FROM stock_movements WHERE product_id = 2 ORDER BY movement_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert((int)$moveDeficit['change_qty'] === -6, 'Ledger movement records -6 change_qty for deficit count');
$assert($moveDeficit['reason'] === 'adjustment', 'Ledger reason is "adjustment"');

// Core invariant check
$batchSumAfterDeficit = (int)$pdo->query("SELECT SUM(remaining_quantity) FROM product_batches WHERE product_id = 2")->fetchColumn();
$assert($qohAfterDeficit === $batchSumAfterDeficit, 'Invariant strictly maintained after deficit reconciliation: 30 === 30');

// =========================================================================
// Summary
// =========================================================================
if ($failures) {
    fwrite(STDERR, "\nTicket #448 tests FAILED with " . count($failures) . " failure(s):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nAll Ticket #448 Inventory Stock Status & Batch Reconciliation tests PASSED successfully!\n";
