<?php
// Contract and integration test for Issue #248: Preserve stock forms through counts and Stock Issues
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/InventoryCountService.php';
require_once __DIR__ . '/../app/Services/StockIssueService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

$throws = static function (callable $operation, string $expectedMessagePart = ''): void {
    try {
        $operation();
        throw new RuntimeException("Expected operation to throw, but it succeeded.");
    } catch (Throwable $e) {
        if ($expectedMessagePart !== '' && !str_contains($e->getMessage(), $expectedMessagePart)) {
            throw new RuntimeException("Expected exception containing '{$expectedMessagePart}', got: '{$e->getMessage()}'");
        }
        echo "  ok - correctly rejected: {$e->getMessage()}\n";
    }
};

echo "Starting tests for Issue #248: Preserve stock forms through counts and Stock Issues...\n";

// --- Section 1: Static UI Contracts ---
echo "Checking frontend static contracts...\n";
$countsSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/inventory_management/inventory_counts.php');
$cashierIssueSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/cashier/stock_issues.php');
$managerIssueSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/inventory_management/stock_issues.php');

$assert(str_contains($countsSrc, 'batch-count-sealed') || str_contains($countsSrc, 'physical_sealed_quantity'), 'inventory_counts.php includes sealed pack count inputs');
$assert(str_contains($countsSrc, 'batch-count-loose') || str_contains($countsSrc, 'physical_loose_quantity'), 'inventory_counts.php includes loose piece count inputs');
$assert(str_contains($cashierIssueSrc, 'name="form"') || str_contains($cashierIssueSrc, 'id="form"'), 'cashier stock_issues.php has form selection for pack/piece');
$assert(str_contains($managerIssueSrc, 'Sealed') || str_contains($managerIssueSrc, 'Pack') || str_contains($managerIssueSrc, 'form'), 'manager stock_issues.php displays stock form information');

// --- Section 2: Database Setup with SQLite In-Memory ---
echo "Setting up SQLite test database...\n";
class SqliteStockFormsTestPdo extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query);
        $query = str_replace('DATE_ADD(CURDATE(), INTERVAL ? DAY)', "strftime('%Y-%m-%d', CURDATE(), '+' || ? || ' days')", $query);
        return parent::prepare($query, $options);
    }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $query = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query);
        $query = str_replace('DATE_ADD(CURDATE(), INTERVAL ? DAY)', "strftime('%Y-%m-%d', CURDATE(), '+' || ? || ' days')", $query);
        return parent::query($query, ...$fetchModeArgs);
    }
}

$pdo = new SqliteStockFormsTestPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->sqliteCreateFunction('GET_LOCK', static fn(): int => 1, 2);
$pdo->sqliteCreateFunction('RELEASE_LOCK', static fn(): int => 1, 1);
$pdo->sqliteCreateFunction('SHA2', static fn(string $val, int $len): string => hash('sha256', $val), 2);
$pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'test', 0);
$pdo->sqliteCreateFunction('CONCAT', static fn(...$args): string => implode('', $args));
$pdo->sqliteCreateFunction('LEFT', static fn(string $str, int $len): string => substr($str, 0, $len), 2);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
$pdo->sqliteCreateFunction('DATE', static fn(string $d): string => substr($d, 0, 10), 1);
$pdo->sqliteCreateFunction('DATEDIFF', static fn(string $d1, string $d2): int => (int)((strtotime($d1) - strtotime($d2)) / 86400), 2);

$pdo->exec("
    CREATE TABLE branches (
        branch_id INTEGER PRIMARY KEY,
        branch_name TEXT,
        branch_code TEXT,
        status TEXT DEFAULT 'active'
    );
    INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Branch', 'MAIN', 'active');

    CREATE TABLE roles (
        role_id INTEGER PRIMARY KEY AUTOINCREMENT,
        role_name TEXT NOT NULL UNIQUE
    );
    INSERT INTO roles (role_name) VALUES ('cashier'), ('admin'), ('super_admin'), ('inventory_manager');

    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL DEFAULT 'User',
        username TEXT NULL,
        password_hash TEXT NULL,
        role_id INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'active',
        branch_id INTEGER NOT NULL DEFAULT 1
    );
    INSERT INTO users (user_id, full_name, username, role_id, branch_id) VALUES
        (1, 'Inventory Mgr', 'mgr', 4, 1),
        (2, 'Cashier User', 'cashier1', 1, 1),
        (3, 'Admin User', 'admin1', 2, 1);

    CREATE TABLE user_roles (
        user_id INTEGER,
        role_id INTEGER
    );
    INSERT INTO user_roles (user_id, role_id) VALUES (1, 4), (2, 1), (3, 2);

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL DEFAULT 1,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        closed_at TEXT NULL,
        status TEXT DEFAULT 'open',
        opening_cash REAL DEFAULT 1000.0,
        closing_cash REAL NULL
    );
    INSERT INTO cashier_shifts (shift_id, cashier_id, status) VALUES (1, 2, 'open');

    CREATE TABLE categories (
        category_id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_name TEXT NOT NULL
    );
    INSERT INTO categories (category_id, category_name) VALUES (1, 'Biscuits');

    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT,
        barcode TEXT,
        case_barcode TEXT,
        pack_size INTEGER NULL,
        pack_barcode TEXT NULL,
        pack_price REAL NULL,
        parent_product_id INTEGER DEFAULT NULL,
        variant_label TEXT DEFAULT NULL,
        product_name TEXT NOT NULL,
        brand TEXT DEFAULT NULL,
        category_id INTEGER DEFAULT NULL,
        unit_price REAL NOT NULL DEFAULT 0.0,
        cost_price REAL NOT NULL DEFAULT 0.0,
        quantity_purchased INTEGER NOT NULL DEFAULT 0,
        quantity_sold INTEGER NOT NULL DEFAULT 0,
        reorder_level INTEGER NOT NULL DEFAULT 10,
        supplier TEXT DEFAULT NULL,
        preferred_supplier TEXT DEFAULT NULL,
        supplier_lead_time_days INTEGER NOT NULL DEFAULT 7,
        safety_stock INTEGER NOT NULL DEFAULT 0,
        minimum_order_quantity INTEGER NOT NULL DEFAULT 1,
        units_per_package INTEGER NOT NULL DEFAULT 1,
        base_unit TEXT NOT NULL DEFAULT 'piece',
        receiving_unit TEXT NOT NULL DEFAULT 'package',
        expiration_date TEXT DEFAULT NULL,
        warning_lead_time_days INTEGER DEFAULT NULL,
        product_image TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        branch_id INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE inventory (
        product_id INTEGER PRIMARY KEY,
        quantity_on_hand INTEGER NOT NULL DEFAULT 0,
        branch_id INTEGER NOT NULL DEFAULT 1
    );

    CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        receiving_id INTEGER,
        batch_number TEXT,
        quantity INTEGER NOT NULL,
        remaining_quantity INTEGER NOT NULL,
        sealed_quantity INTEGER NOT NULL DEFAULT 0,
        loose_quantity INTEGER NOT NULL DEFAULT 0,
        expiration_date TEXT,
        date_received TEXT DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT,
        cost_price REAL DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE inventory_counts (
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
        approved_by INTEGER DEFAULT NULL,
        counted_at TEXT DEFAULT CURRENT_TIMESTAMP,
        approved_at TEXT DEFAULT NULL,
        status TEXT DEFAULT 'pending',
        related_adjustment_id INTEGER DEFAULT NULL
    );

    CREATE TABLE inventory_count_batches (
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
        expiration_date TEXT NULL,
        batch_number TEXT NULL,
        notes TEXT NULL
    );

    CREATE TABLE stock_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        change_qty INTEGER NOT NULL,
        reason TEXT NOT NULL,
        moved_by INTEGER NOT NULL,
        adjustment_id INTEGER NULL,
        moved_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE inventory_adjustments (
        adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        adjustment_qty INTEGER NOT NULL,
        adjustment_type TEXT NOT NULL,
        reported_by INTEGER NOT NULL,
        shift_id INTEGER DEFAULT NULL,
        reason TEXT,
        status TEXT DEFAULT 'pending',
        review_notes TEXT,
        approved_by INTEGER DEFAULT NULL,
        approved_at TEXT DEFAULT NULL,
        form TEXT NOT NULL DEFAULT 'piece',
        reported_qty INTEGER NOT NULL DEFAULT 0,
        pack_size INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        reported_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE stock_issue_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        adjustment_id INTEGER NOT NULL,
        batch_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        form TEXT NOT NULL DEFAULT 'piece',
        reported_qty INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE inventory_adjustment_revisions (
        revision_id INTEGER PRIMARY KEY AUTOINCREMENT,
        adjustment_id INTEGER NOT NULL,
        actor_id INTEGER NULL,
        action TEXT NOT NULL,
        old_status TEXT NULL,
        new_status TEXT NULL,
        old_values TEXT NULL,
        new_values TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT,
        category TEXT,
        module TEXT,
        record_id INTEGER,
        previous_value TEXT,
        new_value TEXT,
        ip_address TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE store_write_gate (
        gate_key TEXT PRIMARY KEY,
        is_paused INTEGER NOT NULL DEFAULT 0,
        paused_by INTEGER DEFAULT NULL,
        paused_at TEXT DEFAULT NULL
    );

    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_name TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open'
    );
    INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2026-01-01', '2026-12-31', 'open');

    CREATE TABLE fiscal_period_locks (
        lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_id INTEGER NOT NULL,
        table_name TEXT NULL
    );

    CREATE TABLE store_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );
    INSERT INTO store_settings (setting_key, setting_value) VALUES ('expiry_alert_days', '90,60,30,14,7');

    CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        type TEXT,
        title TEXT,
        message TEXT,
        reference_id INTEGER,
        reference_type TEXT,
        is_read INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE notification_preferences (
        user_id INTEGER PRIMARY KEY,
        notify_low_stock INTEGER DEFAULT 1,
        notify_expiring INTEGER DEFAULT 1,
        notify_counts INTEGER DEFAULT 1,
        notify_inapp INTEGER DEFAULT 1,
        low_stock_threshold INTEGER DEFAULT NULL
    );
    INSERT INTO notification_preferences (user_id) VALUES (1), (2), (3);
");

// Product 1: Pack-enabled product (Pack of 12, barcode 480111111111, pack_barcode 480222222222)
$pdo->exec("
    INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price, status)
    VALUES (1, 'SKU-PACK-01', '480111111111', 12, '480222222222', 100.0, 'Chocolate Biscuit', 10.0, 7.0, 'active');

    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 91);

    -- Batch 1: 5 sealed packs, 3 loose pieces = 63 base units
    INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
    VALUES (101, 1, 'BATCH-001', 63, 63, 5, 3, '2026-11-30');

    -- Batch 2: 2 sealed packs, 4 loose pieces = 28 base units
    INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
    VALUES (102, 1, 'BATCH-002', 28, 28, 2, 4, '2026-12-31');
");

// Product 2: Piece-only product (no pack configuration)
$pdo->exec("
    INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, status)
    VALUES (2, 'SKU-PIECE-01', '480333333333', 'Single Candy', 2.0, 1.0, 'active');

    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 20);

    INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
    VALUES (201, 2, 'BATCH-PIECE-01', 20, 20, 0, 20, '2027-01-15');
");

$countService = new InventoryCountService($pdo);
$issueService = new StockIssueService($pdo, null, 'inventory_manager');

// --- Section 3: Cycle Counts with Sales Packs and Batches ---
echo "Testing cycle count recording and reconciliation for sales pack product...\n";

// 3.1: getProductBatchCoverage returns sealed and loose sums
$cov = $countService->getProductBatchCoverage(1);
$assert($cov['on_hand'] === 91, 'Product 1 on hand is 91 Base Units');
$assert($cov['batch_sum'] === 91, 'Batch sum is 91 Base Units');
$assert($cov['has_sales_pack'] === true, 'Product 1 identified as sales pack product');
$assert($cov['pack_size'] === 12, 'Product 1 pack size is 12');
$assert($cov['batch_sealed_sum'] === 7, 'Batch sealed sum is 7 packs (5 + 2)');
$assert($cov['batch_loose_sum'] === 7, 'Batch loose sum is 7 loose pieces (3 + 4)');

// 3.2: Count physical sealed and loose at batch level
// Suppose physical count finds:
// Batch 101: 4 sealed packs, 5 loose pieces = (4*12) + 5 = 53 base units (diff: -1 pack, +2 loose, total -10 base units)
// Batch 102: 2 sealed packs, 4 loose pieces = 28 base units (diff: 0)
// Extra batch (found): 1 sealed pack, 2 loose pieces = 14 base units
// Total physical = 53 + 28 + 14 = 95 Base Units (system 91, diff +4)
$countId = $countService->recordCount([
    'product_id' => 1,
    'discrepancy_reason' => 'Annual full shelf count with form breakdown',
    'batches' => [
        [
            'batch_id' => 101,
            'physical_sealed_quantity' => 4,
            'physical_loose_quantity' => 5,
        ],
        [
            'batch_id' => 102,
            'physical_sealed_quantity' => 2,
            'physical_loose_quantity' => 4,
        ],
        [
            'batch_id' => null,
            'batch_number' => 'FOUND-001',
            'expiration_date' => '2027-06-30',
            'physical_sealed_quantity' => 1,
            'physical_loose_quantity' => 2,
            'notes' => 'Found in back store unopened pack plus loose',
        ],
    ],
], 1);

$assert($countId > 0, "Inventory count #{$countId} recorded successfully");

// Verify recorded count record
$countRow = $pdo->query("SELECT * FROM inventory_counts WHERE count_id = {$countId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$countRow['system_quantity'] === 91, 'Count system_quantity is 91 Base Units');
$assert((int)$countRow['physical_quantity'] === 95, 'Count physical_quantity is 95 Base Units');
$assert((int)$countRow['difference_qty'] === 4, 'Count difference_qty is +4 Base Units');
$assert((int)$countRow['system_sealed_quantity'] === 7, 'Count system_sealed_quantity is 7 packs');
$assert((int)$countRow['system_loose_quantity'] === 7, 'Count system_loose_quantity is 7 loose pieces');
$assert((int)$countRow['physical_sealed_quantity'] === 7, 'Count physical_sealed_quantity is 7 packs (4 + 2 + 1)');
$assert((int)$countRow['physical_loose_quantity'] === 11, 'Count physical_loose_quantity is 11 loose pieces (5 + 4 + 2)');

// Verify count batches
$cbRows = $pdo->query("SELECT * FROM inventory_count_batches WHERE count_id = {$countId} ORDER BY count_batch_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$assert(count($cbRows) === 3, 'Recorded 3 batch lines');
$assert((int)$cbRows[0]['batch_id'] === 101, 'Line 1 is batch 101');
$assert((int)$cbRows[0]['physical_sealed_quantity'] === 4, 'Line 1 physical sealed is 4');
$assert((int)$cbRows[0]['physical_loose_quantity'] === 5, 'Line 1 physical loose is 5');
$assert((int)$cbRows[0]['physical_quantity'] === 53, 'Line 1 physical base units is 53');
$assert((int)$cbRows[0]['difference_qty'] === -10, 'Line 1 difference is -10 Base Units');

$assert((int)$cbRows[2]['batch_id'] === 0 || $cbRows[2]['batch_id'] === null, 'Line 3 is new batch');
$assert((int)$cbRows[2]['physical_sealed_quantity'] === 1, 'Line 3 physical sealed is 1');
$assert((int)$cbRows[2]['physical_loose_quantity'] === 2, 'Line 3 physical loose is 2');
$assert((int)$cbRows[2]['physical_quantity'] === 14, 'Line 3 physical base units is 14');

// 3.3: Approve count and verify reconciliation
$countService->approveCount($countId, 1);

// Check product_batches
$b101 = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 101")->fetch(PDO::FETCH_ASSOC);
$assert((int)$b101['sealed_quantity'] === 4, 'Batch 101 sealed_quantity reconciled to 4');
$assert((int)$b101['loose_quantity'] === 5, 'Batch 101 loose_quantity reconciled to 5');
$assert((int)$b101['remaining_quantity'] === 53, 'Batch 101 remaining_quantity reconciled to 53');

$b102 = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 102")->fetch(PDO::FETCH_ASSOC);
$assert((int)$b102['sealed_quantity'] === 2, 'Batch 102 sealed_quantity reconciled to 2');
$assert((int)$b102['loose_quantity'] === 4, 'Batch 102 loose_quantity reconciled to 4');
$assert((int)$b102['remaining_quantity'] === 28, 'Batch 102 remaining_quantity reconciled to 28');

$newBatch = $pdo->query("SELECT * FROM product_batches WHERE batch_number = 'FOUND-001'")->fetch(PDO::FETCH_ASSOC);
$assert($newBatch !== false, 'Found batch created');
$assert((int)$newBatch['sealed_quantity'] === 1, 'Found batch sealed_quantity is 1');
$assert((int)$newBatch['loose_quantity'] === 2, 'Found batch loose_quantity is 2');
$assert((int)$newBatch['remaining_quantity'] === 14, 'Found batch remaining_quantity is 14');

// Check inventory
$inv1 = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert((int)$inv1 === 95, 'Inventory quantity_on_hand updated to 95 Base Units');

// Check stock_movements
$sm = $pdo->query("SELECT change_qty, reason, moved_by FROM stock_movements WHERE product_id = 1 ORDER BY movement_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert((int)$sm['change_qty'] === 4, 'Stock movement recorded +4 Base Units change');
$assert((int)$sm['moved_by'] === 1, 'Stock movement attributed to manager 1');

// 3.4: Strict validations on counts
$throws(static function () use ($countService) {
    $countService->recordCount([
        'product_id' => 1,
        'discrepancy_reason' => 'Invalid negative sealed count',
        'batches' => [
            ['batch_id' => 101, 'physical_sealed_quantity' => -1, 'physical_loose_quantity' => 5],
        ],
    ], 1);
}, 'must be a non-negative whole number');

$throws(static function () use ($countService) {
    $countService->recordCount([
        'product_id' => 1,
        'discrepancy_reason' => 'Invalid fractional sealed count',
        'batches' => [
            ['batch_id' => 101, 'physical_sealed_quantity' => '2.5', 'physical_loose_quantity' => 0],
        ],
    ], 1);
}, 'must be a non-negative whole number');

// --- Section 4: Piece-Only Product Counts Compatibility ---
echo "Testing cycle count backward compatibility with piece-only products...\n";
$pieceCountId = $countService->recordCount([
    'product_id' => 2,
    'physical_quantity' => 18,
    'discrepancy_reason' => '2 candies damaged during storage',
    'batches' => [
        ['batch_id' => 201, 'physical_quantity' => 18],
    ],
], 1);

$countService->approveCount($pieceCountId, 1);
$b201 = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 201")->fetch(PDO::FETCH_ASSOC);
$assert((int)$b201['remaining_quantity'] === 18, 'Piece-only batch remaining_quantity updated to 18');
$assert((int)$b201['loose_quantity'] === 18, 'Piece-only batch loose_quantity updated to 18');
$assert((int)$b201['sealed_quantity'] === 0, 'Piece-only batch sealed_quantity remains 0');

$inv2 = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 2")->fetchColumn();
$assert((int)$inv2 === 18, 'Piece-only inventory updated to 18');

// --- Section 5: Cashier Stock Issue Submissions with Forms ---
echo "Testing cashier stock issue submission by form ('piece' vs 'pack')...\n";
$cashierIssueService = new StockIssueService($pdo, null, 'cashier');

// 5.1: Cashier reports 1 sealed pack damaged
$packIssueId = $cashierIssueService->submitReport([
    'product_id' => 1,
    'category' => 'damaged',
    'quantity' => 1,
    'form' => 'pack',
    'explanation' => 'One sealed pack crushed by customer cart',
], 2);

$packReport = $pdo->query("SELECT * FROM inventory_adjustments WHERE adjustment_id = {$packIssueId}")->fetch(PDO::FETCH_ASSOC);
$assert($packReport['form'] === 'pack', 'Stock issue form recorded as pack');
$assert((int)$packReport['reported_qty'] === 1, 'Reported quantity is 1 pack');
$assert((int)$packReport['pack_size'] === 12, 'Pack size recorded as 12');
$assert((int)$packReport['adjustment_qty'] === -12, 'adjustment_qty stored as -12 Base Units');
$assert($packReport['status'] === 'pending', 'Report status is pending');

// Stock must NOT be deducted yet!
$invBeforeApprove = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert((int)$invBeforeApprove === 95, 'Inventory remains 95 Base Units while report is pending');

// 5.2: Cashier reports 3 loose pieces expired
$pieceIssueId = $cashierIssueService->submitReport([
    'product_id' => 1,
    'category' => 'expired',
    'quantity' => 3,
    'form' => 'piece',
    'explanation' => 'Three loose biscuits reached expiry date',
], 2);

$pieceReport = $pdo->query("SELECT * FROM inventory_adjustments WHERE adjustment_id = {$pieceIssueId}")->fetch(PDO::FETCH_ASSOC);
$assert($pieceReport['form'] === 'piece', 'Stock issue form recorded as piece');
$assert((int)$pieceReport['reported_qty'] === 3, 'Reported quantity is 3 pieces');
$assert((int)$pieceReport['adjustment_qty'] === -3, 'adjustment_qty stored as -3 Base Units');

// 5.3: Cashier attempts to report 'pack' on piece-only product -> must be rejected!
$throws(static function () use ($cashierIssueService) {
    $cashierIssueService->submitReport([
        'product_id' => 2,
        'category' => 'damaged',
        'quantity' => 1,
        'form' => 'pack',
        'explanation' => 'Attempting to report pack on piece-only item',
    ], 2);
}, 'without Sales Pack configured');

// 5.4: Cashier attempts to report invalid form -> must be rejected!
$throws(static function () use ($cashierIssueService) {
    $cashierIssueService->submitReport([
        'product_id' => 1,
        'category' => 'damaged',
        'quantity' => 1,
        'form' => 'barrel',
        'explanation' => 'Invalid selling form',
    ], 2);
}, 'Invalid stock form');

// --- Section 6: Inventory Manager Review/Approval Deducting Correct Form ---
echo "Testing Inventory Manager approval and batch form deduction...\n";

// 6.1: Approve the pack issue ($packIssueId = 1 pack = 12 base units)
// Recommend batch allocation for pack:
$recPacks = $issueService->recommendBatchAllocation(1, 1, 'pack');
$assert(count($recPacks) === 1, 'Recommended allocation for 1 pack returns 1 batch');
$recBatchId = array_key_first($recPacks);
$assert($recPacks[$recBatchId] === 1, 'Allocated 1 pack to batch #101 (FEFO)');

// Approve with allocated batch
$issueService->approveReport($packIssueId, 1, 'Pack damage verified', $recPacks);

$bAfterPack = $pdo->query("SELECT * FROM product_batches WHERE batch_id = {$recBatchId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$bAfterPack['sealed_quantity'] === 3, 'Batch sealed_quantity decreased by 1 pack (4 -> 3)');
$assert((int)$bAfterPack['loose_quantity'] === 5, 'Batch loose_quantity UNCHANGED (remains 5)');
$assert((int)$bAfterPack['remaining_quantity'] === 41, 'Batch remaining_quantity decreased by 12 (53 -> 41)');

$invAfterPack = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert((int)$invAfterPack === 83, 'Inventory on hand decreased from 95 to 83 Base Units');

// Check stock_issue_batches
$sibPack = $pdo->query("SELECT * FROM stock_issue_batches WHERE adjustment_id = {$packIssueId}")->fetch(PDO::FETCH_ASSOC);
$assert($sibPack !== false, 'Batch deduction recorded in stock_issue_batches');
$assert((int)$sibPack['batch_id'] === $recBatchId, 'Deducted from correct batch');
$assert($sibPack['form'] === 'pack', 'stock_issue_batches records form as pack');
$assert((int)$sibPack['reported_qty'] === 1, 'stock_issue_batches records reported_qty as 1 pack');
$assert((int)$sibPack['quantity'] === 12, 'stock_issue_batches records quantity as 12 Base Units');

// 6.2: Approve the piece issue ($pieceIssueId = 3 pieces)
$recPieces = $issueService->recommendBatchAllocation(1, 3, 'piece');
$assert(count($recPieces) === 1, 'Recommended allocation for 3 pieces');
$recPieceBatchId = array_key_first($recPieces);

$issueService->approveReport($pieceIssueId, 1, 'Piece expiry verified', $recPieces);

$bAfterPiece = $pdo->query("SELECT * FROM product_batches WHERE batch_id = {$recPieceBatchId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$bAfterPiece['sealed_quantity'] === 3, 'Batch sealed_quantity UNCHANGED (remains 3)');
$assert((int)$bAfterPiece['loose_quantity'] === 2, 'Batch loose_quantity decreased by 3 (5 -> 2)');
$assert((int)$bAfterPiece['remaining_quantity'] === 38, 'Batch remaining_quantity decreased by 3 (41 -> 38)');

$invAfterPiece = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert((int)$invAfterPiece === 80, 'Inventory on hand decreased from 83 to 80 Base Units');

// --- Section 7: Guard Against Insufficient Form Balances & Loose-Pack Cross-Contamination ---
echo "Testing form balance guards (never satisfy pack from loose, never open pack for issue)...\n";

// Current batch form totals:
// Batch 101: 3 sealed, 2 loose (remaining 38)
// Batch 102: 2 sealed, 4 loose (remaining 28)
// Found-001: 1 sealed, 2 loose (remaining 14)
// Total sealed: 6 packs. Total loose: 8 pieces. Total base units: 80.

// 7.1: Cashier reports 10 loose pieces damaged (only 8 loose exist, although 80 base units exist!)
$overPieceIssueId = $cashierIssueService->submitReport([
    'product_id' => 1,
    'category' => 'damaged',
    'quantity' => 10,
    'form' => 'piece',
    'explanation' => '10 pieces water damaged',
], 2);

// Manager attempts to approve: MUST fail because only 8 loose pieces exist across all batches!
// It must NEVER invent loose pieces by auto-opening sealed packs for a stock issue!
$throws(static function () use ($issueService, $overPieceIssueId) {
    $issueService->approveReport($overPieceIssueId, 1, 'Approval attempt for excess loose pieces');
}, 'Insufficient loose stock');

$invUnchanged = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert((int)$invUnchanged === 80, 'Inventory completely unchanged when loose stock insufficient');

// 7.2: Cashier reports 7 sealed packs damaged (only 6 sealed exist, although 100 base units exist with loose pieces!)
// Ensure total base units on hand is 100 (6 sealed * 12 = 72 + 28 loose = 100)
$pdo->exec("UPDATE product_batches SET remaining_quantity = remaining_quantity + 20, loose_quantity = loose_quantity + 20 WHERE batch_id = 102");
$pdo->exec("UPDATE inventory SET quantity_on_hand = quantity_on_hand + 20 WHERE product_id = 1");

$overPackIssueId = $cashierIssueService->submitReport([
    'product_id' => 1,
    'category' => 'damaged',
    'quantity' => 7,
    'form' => 'pack',
    'explanation' => '7 packs damaged in fire',
], 2);

// Manager attempts to approve: MUST fail because only 6 sealed packs exist!
// It must NEVER satisfy sealed packs from loose pieces!
$throws(static function () use ($issueService, $overPackIssueId) {
    $issueService->approveReport($overPackIssueId, 1, 'Approval attempt for excess sealed packs');
}, 'Insufficient sealed pack stock');

$invUnchanged2 = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert((int)$invUnchanged2 === 100, 'Inventory completely unchanged when sealed stock insufficient');

// --- Section 8: Replay / Double Approval / Rollback ---
echo "Testing replay protection and transactional rollback...\n";

// 8.1: Replaying approval on already approved report must throw
$throws(static function () use ($issueService, $packIssueId) {
    $issueService->approveReport($packIssueId, 1, 'Replay approval attempt');
}, 'Only pending reports can be approved');

// 8.2: Rejected report must not deduct stock
$rejectIssueId = $cashierIssueService->submitReport([
    'product_id' => 1,
    'category' => 'damaged',
    'quantity' => 1,
    'form' => 'pack',
    'explanation' => 'Pack dented',
], 2);

$issueService->rejectReport($rejectIssueId, 1, 'Pack is cosmetically fine to sell');
$rejectReport = $pdo->query("SELECT status FROM inventory_adjustments WHERE adjustment_id = {$rejectIssueId}")->fetch(PDO::FETCH_ASSOC);
$assert($rejectReport['status'] === 'rejected', 'Report marked as rejected');

$invAfterReject = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert((int)$invAfterReject === 100, 'Stock unchanged after rejection');

// 8.3: Canceled report by cashier
$cancelIssueId = $cashierIssueService->submitReport([
    'product_id' => 1,
    'category' => 'damaged',
    'quantity' => 1,
    'form' => 'piece',
    'explanation' => 'Piece dropped',
], 2);
$cashierIssueService->cancelReport($cancelIssueId, 2);
$cancelReport = $pdo->query("SELECT status FROM inventory_adjustments WHERE adjustment_id = {$cancelIssueId}")->fetch(PDO::FETCH_ASSOC);
$assert($cancelReport['status'] === 'cancelled', 'Report marked as cancelled');
$invAfterCancel = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert((int)$invAfterCancel === 100, 'Stock unchanged after cancellation');

// 8.4: Overflow and non-integer validation on Stock Issue submission & Counts
$throws(static function () use ($cashierIssueService) {
    $cashierIssueService->submitReport([
        'product_id' => 1,
        'category' => 'damaged',
        'quantity' => '1.5',
        'form' => 'piece',
        'explanation' => 'Fractional piece report',
    ], 2);
}, 'whole number');

$throws(static function () use ($cashierIssueService) {
    $cashierIssueService->submitReport([
        'product_id' => 1,
        'category' => 'damaged',
        'quantity' => 200000000,
        'form' => 'pack',
        'explanation' => 'Overflow pack report (200M * 12 > 2^31-1)',
    ], 2);
}, 'too large');

$throws(static function () use ($countService) {
    $countService->recordCount([
        'product_id' => 1,
        'discrepancy_reason' => 'Overflow count',
        'batches' => [
            ['batch_id' => 101, 'physical_sealed_quantity' => 200000000, 'physical_loose_quantity' => 0],
        ],
    ], 1);
}, 'too large');

$throws(static function () use ($countService) {
    $countService->recordCount([
        'product_id' => 2,
        'discrepancy_reason' => 'Sealed packs on piece-only product',
        'batches' => [
            ['batch_id' => 201, 'physical_sealed_quantity' => 1, 'physical_loose_quantity' => 5],
        ],
    ], 1);
}, 'without a Sales Pack configured');

// 8.5: Count form staleness guard (if batch sealed/loose composition changes while total stays 100)
$staleCountId = $countService->recordCount([
    'product_id' => 1,
    'discrepancy_reason' => 'Count before concurrent pack opening',
    'batches' => [
        ['batch_id' => 101, 'physical_sealed_quantity' => 3, 'physical_loose_quantity' => 2],
        ['batch_id' => 102, 'physical_sealed_quantity' => 2, 'physical_loose_quantity' => 24],
    ],
], 1);

// Simulate a concurrent pack opening on Batch 101 (1 pack opened -> 2 sealed, 14 loose; remaining_quantity still 38!)
$pdo->exec("UPDATE product_batches SET sealed_quantity = 2, loose_quantity = 14 WHERE batch_id = 101");

$throws(static function () use ($countService, $staleCountId) {
    $countService->approveCount($staleCountId, 1);
}, 'Stock form balances have changed');

// Restore Batch 101 to 3 sealed, 2 loose
$pdo->exec("UPDATE product_batches SET sealed_quantity = 3, loose_quantity = 2 WHERE batch_id = 101");

// 8.6: Atomic rollback on injected database failure during Stock Issue approval
$rollbackIssueId = $cashierIssueService->submitReport([
    'product_id' => 1,
    'category' => 'damaged',
    'quantity' => 1,
    'form' => 'pack',
    'explanation' => 'Pack for rollback test',
], 2);

$pdo->exec("
    CREATE TRIGGER fail_stock_movement_on_rollback_issue
    BEFORE INSERT ON stock_movements
    WHEN NEW.adjustment_id = {$rollbackIssueId}
    BEGIN
        SELECT RAISE(ABORT, 'Injected movement write failure');
    END;
");

$throws(static function () use ($issueService, $rollbackIssueId) {
    $issueService->approveReport($rollbackIssueId, 1, 'Should roll back');
}, 'Injected movement write failure');

$pdo->exec("DROP TRIGGER fail_stock_movement_on_rollback_issue");

$b101AfterRollback = $pdo->query("SELECT sealed_quantity, loose_quantity, remaining_quantity FROM product_batches WHERE batch_id = 101")->fetch(PDO::FETCH_ASSOC);
$assert((int)$b101AfterRollback['sealed_quantity'] === 3, 'Batch 101 sealed_quantity rolled back to 3');
$assert((int)$b101AfterRollback['loose_quantity'] === 2, 'Batch 101 loose_quantity rolled back to 2');
$assert((int)$b101AfterRollback['remaining_quantity'] === 38, 'Batch 101 remaining_quantity rolled back to 38');
$invAfterRollback = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert($invAfterRollback === 100, 'Inventory rolled back to 100');
$repAfterRollback = $pdo->query("SELECT status FROM inventory_adjustments WHERE adjustment_id = {$rollbackIssueId}")->fetchColumn();
$assert($repAfterRollback === 'pending', 'Stock issue report status rolled back to pending');

// 8.7: Administrator read-only oversight & report details include form and batch breakdown
$adminOversight = $issueService->getOversightReports([], 3);
$assert(count($adminOversight) >= 4, 'Administrator can list all stock issue reports');
$packDetail = $issueService->getReportDetail($packIssueId, 3);
$assert($packDetail !== null && $packDetail['report']['form'] === 'pack', 'Oversight detail retains form = pack');
$assert((int)$packDetail['report']['reported_qty'] === 1, 'Oversight detail retains reported_qty = 1');
$assert(!empty($packDetail['batches']) && $packDetail['batches'][0]['form'] === 'pack' && (int)$packDetail['batches'][0]['reported_qty'] === 1, 'Oversight batch detail retains form = pack and reported_qty = 1');

// 8.7b: Top-level / unbatched count with sealed/loose reconciles product_batches parity with inventory.quantity_on_hand
$pdo->exec("
    INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price, status)
    VALUES (3, 'SKU-PACK-UNBATCHED', '480444444444', 6, '480555555555', 50.0, 'Unbatched Juice 6pk', 10.0, 6.0, 'active');
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (3, 18);
");
$topCountId = $countService->recordCount([
    'product_id' => 3,
    'physical_sealed_quantity' => 2,
    'physical_loose_quantity' => 3,
    'discrepancy_reason' => 'Unbatched shelf count found 2 sealed packs + 3 loose',
], 1);
$countService->approveCount($topCountId, 1);
$cov3 = $countService->getProductBatchCoverage(3);
$assert($cov3['on_hand'] === 15, 'Top-level pack count updated inventory.quantity_on_hand to 15 (2*6 + 3)');
$assert($cov3['batch_sum'] === 15, 'Top-level pack count reconciled product_batches sum to 15');
$assert($cov3['batch_sealed_sum'] === 2, 'Top-level pack count reconciled batch_sealed_sum to 2');
$assert($cov3['batch_loose_sum'] === 3, 'Top-level pack count reconciled batch_loose_sum to 3');
$assert($cov3['has_mismatch'] === false, 'Top-level pack count eliminated batch vs inventory mismatch');

// 8.7c: StockIssueService::formatIssueQuantity shared helper
$assert(StockIssueService::formatIssueQuantity(['form' => 'pack', 'reported_qty' => 2, 'adjustment_qty' => -24]) === '-2 packs (24 pcs)', 'formatIssueQuantity formats plural packs with base units');
$assert(StockIssueService::formatIssueQuantity(['form' => 'pack', 'reported_qty' => 1, 'adjustment_qty' => -12]) === '-1 pack (12 pcs)', 'formatIssueQuantity formats singular pack with base units');
$assert(StockIssueService::formatIssueQuantity(['form' => 'piece', 'reported_qty' => 3, 'adjustment_qty' => -3]) === '-3 pieces', 'formatIssueQuantity formats plural pieces');
$assert(StockIssueService::formatIssueQuantity(['form' => 'piece', 'reported_qty' => 1, 'adjustment_qty' => -1]) === '-1 piece', 'formatIssueQuantity formats singular piece');

// 8.7d: A pack product cannot be counted from a total alone, because that would assert "all loose".
$pdo->exec("
    INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price, status)
    VALUES (4, 'SKU-PACK-FORMLESS', '480444444445', 6, '480555555556', 50.0, 'Formless Juice 6pk', 10.0, 6.0, 'active');
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (4, 12);
    INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
    VALUES (401, 4, 'B-FORMLESS', 12, 12, 2, 0, '2027-01-01');
");
$formlessRejected = false;
try {
    $countService->recordCount([
        'product_id' => 4,
        'physical_quantity' => 12,
        'discrepancy_reason' => 'Total only shelf count',
    ], 1);
} catch (RuntimeException $e) {
    $formlessRejected = str_contains($e->getMessage(), 'sealed packs and loose pieces');
}
$assert($formlessRejected, 'A total-only count for a pack product is refused with a sealed/loose instruction');
$assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_counts WHERE product_id = 4')->fetchColumn() === 0, 'Refused form-less pack count records nothing');

// 8.7e: A top-level count that would merge several dated batches is refused instead of losing provenance.
$pdo->exec("
    INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price, status)
    VALUES (5, 'SKU-PACK-MULTI', '480444444446', 6, '480555555557', 50.0, 'Multi Batch Juice 6pk', 10.0, 6.0, 'active');
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (5, 12);
    INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
    VALUES (501, 5, 'B-MULTI-A', 6, 6, 1, 0, '2027-01-01'),
           (502, 5, 'B-MULTI-B', 6, 6, 1, 0, '2027-06-01');
");
$multiCountId = $countService->recordCount([
    'product_id' => 5,
    'physical_sealed_quantity' => 2,
    'physical_loose_quantity' => 0,
    'discrepancy_reason' => 'Top-level count across two dated batches',
], 1);
$multiRejected = false;
try {
    $countService->approveCount($multiCountId, 1);
} catch (RuntimeException $e) {
    $multiRejected = str_contains($e->getMessage(), 'multiple active batches');
}
$assert($multiRejected, 'Approving a top-level count with multiple active batches is refused');
$multiBatches = $pdo->query("SELECT * FROM product_batches WHERE product_id = 5 ORDER BY batch_id")->fetchAll(PDO::FETCH_ASSOC);
$assert(
    count($multiBatches) === 2
        && (int)$multiBatches[0]['remaining_quantity'] === 6 && (int)$multiBatches[0]['sealed_quantity'] === 1
        && (int)$multiBatches[1]['remaining_quantity'] === 6 && (int)$multiBatches[1]['sealed_quantity'] === 1
        && (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 5')->fetchColumn() === 12
        && $pdo->query("SELECT status FROM inventory_counts WHERE count_id = {$multiCountId}")->fetchColumn() === 'pending',
    'Refused multi-batch count preserves batches, expirations, inventory, and pending status'
);

// 8.7f: A same-total form correction writes an attributable audit movement with zero change.
$pdo->exec("
    INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price, status)
    VALUES (6, 'SKU-PACK-RESHAPE', '480444444447', 6, '480555555558', 50.0, 'Reshaped Juice 6pk', 10.0, 6.0, 'active');
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (6, 12);
    INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
    VALUES (601, 6, 'B-RESHAPE', 12, 12, 2, 0, '2027-01-01');
");
$reshapeCountId = $countService->recordCount([
    'product_id' => 6,
    'physical_sealed_quantity' => 1,
    'physical_loose_quantity' => 6,
    'discrepancy_reason' => 'Shelf has one sealed pack and six loose pieces',
], 1);
$countService->approveCount($reshapeCountId, 1);
$reshaped = $pdo->query('SELECT remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE batch_id = 601')->fetch(PDO::FETCH_ASSOC);
$formMove = $pdo->query("SELECT change_qty, reason, moved_by FROM stock_movements WHERE product_id = 6 ORDER BY movement_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert(
    (int)$reshaped['remaining_quantity'] === 12 && (int)$reshaped['sealed_quantity'] === 1 && (int)$reshaped['loose_quantity'] === 6,
    'Same-total form correction updates sealed and loose counts'
);
$assert(
    $formMove !== false && (int)$formMove['change_qty'] === 0 && $formMove['reason'] === 'adjustment' && (int)$formMove['moved_by'] === 1,
    'Same-total form correction writes a zero-change audit movement attributed to the approver'
);

// 8.8: Run migration 202610200004_stock_counts_and_issues_forms idempotently
$mig = require __DIR__ . '/../database/migrations/202610200004_stock_counts_and_issues_forms.php';
$assert($mig['key'] === '202610200004_stock_counts_and_issues_forms', 'Migration key is 202610200004_stock_counts_and_issues_forms');
($mig['up'])($pdo);
$assert(true, 'Migration 202610200004_stock_counts_and_issues_forms executed idempotently on SQLite');

if (!empty($failures)) {
    echo "\nTest FAILED with " . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\nAll contract and integration checks for Issue #248 PASSED!\n";
