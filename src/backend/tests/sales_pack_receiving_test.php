<?php
// src/backend/tests/sales_pack_receiving_test.php
//
// Integration and contract tests for Issue #247:
// Receive sealed packs and loose pieces.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';
require_once __DIR__ . '/../app/Services/ProductCodeLookupService.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  ok - {$message}\n";
};

$rejects = static function (callable $operation, string $expectedMessagePart = ''): void {
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

echo "Starting tests for Issue #247: Receive sealed packs and loose pieces...\n";

// 1. Static contract checks on frontend stock_receiving.php and receiving_history.php
echo "Checking frontend static contracts...\n";
$stockReceivingSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/report/stock_receiving.php');
$receivingHistorySrc = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/report/receiving_history.php');

$assert(str_contains($stockReceivingSrc, 'data-field="received_packs"') || str_contains($stockReceivingSrc, 'name="received_packs"'), 'stock_receiving.php includes received_packs field');
$assert(str_contains($stockReceivingSrc, 'data-field="received_loose"') || str_contains($stockReceivingSrc, 'name="received_loose"'), 'stock_receiving.php includes received_loose field');
$assert(str_contains($stockReceivingSrc, 'data-field="rejected_packs"') || str_contains($stockReceivingSrc, 'name="rejected_packs"'), 'stock_receiving.php includes rejected_packs field');
$assert(str_contains($stockReceivingSrc, 'data-field="rejected_loose"') || str_contains($stockReceivingSrc, 'name="rejected_loose"'), 'stock_receiving.php includes rejected_loose field');
$assert(str_contains($receivingHistorySrc, 'received_packs'), 'receiving_history.php references received_packs');
$assert(str_contains($receivingHistorySrc, 'rejected_packs'), 'receiving_history.php references rejected_packs');

// 2. Database setup with SQLite in-memory
class SqliteSalesPackReceivingTestPdo extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace('DATE_ADD(CURDATE(), INTERVAL ? DAY)', "strftime('%Y-%m-%d', CURDATE(), '+' || ? || ' days')", $query);
        return parent::prepare(preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query), $options);
    }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return parent::query(preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query), ...$fetchModeArgs);
    }
}

$pdo = new SqliteSalesPackReceivingTestPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->sqliteCreateFunction('GET_LOCK', static fn(): int => 1, 2);
$pdo->sqliteCreateFunction('RELEASE_LOCK', static fn(): int => 1, 1);
$pdo->sqliteCreateFunction('SHA2', static fn(string $val, int $len): string => hash('sha256', $val), 2);
$pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'test', 0);
$pdo->sqliteCreateFunction('CONCAT', static fn(...$args): string => implode('', $args));
$pdo->sqliteCreateFunction('CONCAT_WS', static function ($separator, ...$args): string {
    $filtered = array_filter($args, static fn($v) => $v !== null && $v !== '');
    return implode((string)$separator, $filtered);
});
$pdo->sqliteCreateFunction('LEFT', static fn(string $str, int $len): string => substr($str, 0, $len), 2);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
$pdo->sqliteCreateFunction('DATE', static fn(string $d): string => substr($d, 0, 10), 1);
$pdo->sqliteCreateFunction('DATEDIFF', static fn(string $d1, string $d2): int => (int)((strtotime($d1) - strtotime($d2)) / 86400), 2);
$pdo->sqliteCreateFunction('LOCATE', static function (string $substr, string $str): int {
    $pos = mb_strpos($str, $substr);
    return $pos === false ? 0 : $pos + 1;
}, 2);

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
    INSERT INTO users (user_id, full_name, username, role_id, branch_id) VALUES (1, 'Inventory Mgr', 'mgr', 4, 1);

    CREATE TABLE user_roles (
        user_id INTEGER,
        role_id INTEGER
    );
    INSERT INTO user_roles (user_id, role_id) VALUES (1, 4);

    CREATE TABLE categories (
        category_id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_name TEXT NOT NULL
    );
    INSERT INTO categories (category_id, category_name) VALUES (1, 'Snacks');

    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT,
        barcode TEXT,
        case_barcode TEXT,
        pack_size INTEGER DEFAULT NULL,
        pack_barcode TEXT DEFAULT NULL,
        pack_price REAL DEFAULT NULL,
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
        last_updated TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        receiving_id INTEGER DEFAULT NULL,
        batch_number TEXT DEFAULT NULL,
        quantity INTEGER NOT NULL,
        remaining_quantity INTEGER NOT NULL,
        sealed_quantity INTEGER NOT NULL DEFAULT 0,
        loose_quantity INTEGER NOT NULL DEFAULT 0,
        expiration_date TEXT DEFAULT NULL,
        date_received TEXT DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT DEFAULT NULL,
        cost_price REAL DEFAULT NULL
    );

    CREATE TABLE stock_receiving_groups (
        group_id INTEGER PRIMARY KEY AUTOINCREMENT,
        received_by INTEGER NOT NULL,
        received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT DEFAULT NULL,
        invoice_number TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL
    );

    CREATE TABLE stock_receiving (
        receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER DEFAULT NULL,
        product_id INTEGER NOT NULL,
        received_qty INTEGER NOT NULL,
        received_packages INTEGER NOT NULL DEFAULT 0,
        units_per_package_used INTEGER NOT NULL DEFAULT 1,
        accepted_qty INTEGER NOT NULL DEFAULT 0,
        damaged_qty INTEGER NOT NULL DEFAULT 0,
        cost_price REAL DEFAULT NULL,
        total_cost REAL NOT NULL DEFAULT 0.00,
        received_by INTEGER NOT NULL,
        received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT DEFAULT NULL,
        invoice_number TEXT DEFAULT NULL,
        batch_number TEXT DEFAULT NULL,
        expiration_date TEXT DEFAULT NULL,
        discrepancy_type TEXT NOT NULL DEFAULT 'none',
        discrepancy_qty INTEGER NOT NULL DEFAULT 0,
        discrepancy_notes TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        pullout_id INTEGER DEFAULT NULL,
        received_packs INTEGER NOT NULL DEFAULT 0,
        accepted_packs INTEGER NOT NULL DEFAULT 0,
        damaged_packs INTEGER NOT NULL DEFAULT 0,
        rejected_packs INTEGER NOT NULL DEFAULT 0,
        received_loose INTEGER NOT NULL DEFAULT 0,
        accepted_loose INTEGER NOT NULL DEFAULT 0,
        damaged_loose INTEGER NOT NULL DEFAULT 0,
        rejected_loose INTEGER NOT NULL DEFAULT 0,
        pack_size_used INTEGER DEFAULT NULL
    );

    CREATE TABLE stock_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        change_qty INTEGER NOT NULL,
        reason TEXT NOT NULL,
        reference_id INTEGER DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        moved_by INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE purchase_history (
        purchase_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        supplier TEXT DEFAULT NULL,
        quantity_purchased INTEGER NOT NULL,
        cost_price REAL NOT NULL,
        total_cost REAL NOT NULL,
        purchase_date TEXT DEFAULT CURRENT_TIMESTAMP,
        recorded_by INTEGER NOT NULL
    );

    CREATE TABLE inventory_adjustments (
        adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        adjustment_qty INTEGER NOT NULL,
        adjustment_type TEXT NOT NULL,
        reported_by INTEGER NOT NULL,
        reason TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        review_notes TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT NOT NULL,
        category TEXT DEFAULT 'general',
        module TEXT DEFAULT NULL,
        record_id INTEGER DEFAULT NULL,
        previous_value TEXT DEFAULT NULL,
        new_value TEXT DEFAULT NULL,
        metadata TEXT DEFAULT NULL,
        ip_address TEXT DEFAULT NULL,
        timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
        table_name TEXT,
        record_id INTEGER,
        locked_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE notification_preferences (
        pref_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        notify_low_stock INTEGER DEFAULT 1,
        notify_adjustment INTEGER DEFAULT 0,
        notify_email INTEGER DEFAULT 0,
        notify_inapp INTEGER DEFAULT 1,
        low_stock_threshold INTEGER DEFAULT 10
    );

    CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        reference_id INTEGER NULL,
        reference_type TEXT NULL,
        is_read INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

require_once __DIR__ . '/support/notification_schema.php';
notification_test_schema($pdo);

$productService = new ProductService($pdo);
$inventoryService = new InventoryService($pdo);
$receivingService = new ReceivingService($pdo);
$lookupService = new ProductCodeLookupService($pdo);

// 1. Create a pack-enabled product: 12 pieces per pack, pack barcode, starting stock 5 packs and 3 loose
echo "Testing AC 1 & 2: Receive 2 packs of 12 and 4 loose pieces into 5 packs and 3 loose...\n";
$biscuit = $productService->createProduct([
    'product_name' => 'Choco Biscuits Pack of 12',
    'category_id' => 1,
    'sku' => 'SKU-CHOCO-12',
    'barcode' => 'CHOCO-PIECE-BARCODE',
    'pack_size' => 12,
    'pack_barcode' => 'CHOCO-PACK-BARCODE',
    'pack_price' => 100.00,
    'cost_price' => 8.00,
    'selling_price' => 10.00,
    'units_per_package' => 24, // Receiving Unit case has 24 pieces
    'starting_stock_mode' => 'packs',
    'starting_packs' => 5,
    'starting_loose' => 3,
    'expiration_date' => '2026-11-30',
], 1);

$productId = (int)$biscuit;

// Check starting state: 5 packs * 12 + 3 loose = 63 Base Units
$summary = $inventoryService->getProductsWithBatchSummaries('2026-10-20')[0];
$assert((int)$summary['physical_sealed_packs'] === 5, 'Starting physical sealed packs is 5');
$assert((int)$summary['physical_loose_pieces'] === 3, 'Starting physical loose pieces is 3');
$assert((int)$summary['quantity_on_hand'] === 63, 'Starting Base Units total is 63');

// Receive 2 packs of 12 and 4 loose pieces
$receipt = $receivingService->receiveStock([
    'product_id' => $productId,
    'quantity_mode' => 'packs',
    'received_packs' => 2,
    'received_loose' => 4,
    'damaged_packs' => 0,
    'damaged_loose' => 0,
    'cost_price' => '8.00',
    'supplier' => 'Universal Robina Store',
    'invoice_number' => 'INV-2026-001',
    'batch_number' => 'BATCH-RCV-001',
    'expiration_date' => '2026-12-31',
], 1);

// Result must be 7 sealed packs, 7 loose pieces, and 91 Base Units
$summaryAfter = $inventoryService->getProductsWithBatchSummaries('2026-10-20')[0];
$assert((int)$summaryAfter['physical_sealed_packs'] === 7, 'After receiving: physical sealed packs is 7 (5 + 2)');
$assert((int)$summaryAfter['physical_loose_pieces'] === 7, 'After receiving: physical loose pieces is 7 (3 + 4)');
$assert((int)$summaryAfter['quantity_on_hand'] === 91, 'After receiving: Base Units total is 91 (63 + 28)');
$assert(7 * 12 + 7 === 91, '7 packs of 12 + 7 loose pieces = 91 Base Units exactly');

// Check the new batch created for this receipt
$batches = $inventoryService->getProductBatches($productId);
$assert(count($batches) === 2, 'Two separate Stock Batches exist for the two deliveries');
$newBatch = $batches[1]; // newer batch or ordered by expiration
$openingBatch = $batches[0];
// Verify separate batches and non-overwritten expiration/costs (AC 5)
$assert($openingBatch['expiration_date'] === '2026-11-30', 'Earlier batch expiration 2026-11-30 is preserved');
$assert($newBatch['expiration_date'] === '2026-12-31', 'New delivery has separate expiration date 2026-12-31');
$assert((int)$newBatch['sealed_quantity'] === 2, 'New batch sealed_quantity is 2 packs');
$assert((int)$newBatch['loose_quantity'] === 4, 'New batch loose_quantity is 4 loose pieces');
$assert((int)$newBatch['remaining_quantity'] === 28, 'New batch remaining_quantity is 28 Base Units');
$assert((int)$newBatch['quantity'] === 28, 'New batch total quantity is 28 Base Units');

// Check receiving record (AC 2 & 4)
$assert($receipt['received_packs'] === 2, 'Receipt returned received_packs = 2');
$assert($receipt['accepted_packs'] === 2, 'Receipt returned accepted_packs = 2');
$assert($receipt['received_loose'] === 4, 'Receipt returned received_loose = 4');
$assert($receipt['accepted_loose'] === 4, 'Receipt returned accepted_loose = 4');
$assert($receipt['received_qty'] === 28, 'Receipt returned total received_qty = 28');
$assert($receipt['accepted_qty'] === 28, 'Receipt returned total accepted_qty = 28');
$assert($receipt['pack_size_used'] === 12, 'Receipt returned pack_size_used = 12');

echo "AC 1 passed.\n";

// Test AC 2: Damaged and rejected quantities explicitly by form
echo "Testing AC 2: Damaged and rejected quantities explicitly by form...\n";
// Deliver 3 packs and 5 loose: 1 pack damaged, 1 loose damaged, 2 packs accepted, 4 loose accepted
$damagedReceipt = $receivingService->receiveStock([
    'product_id' => $productId,
    'received_packs' => 3,
    'damaged_packs' => 1,
    'received_loose' => 5,
    'damaged_loose' => 1,
    'cost_price' => '', // blank = unknown cost
    'supplier' => 'Mega Mart',
    'invoice_number' => 'INV-2026-002',
    'batch_number' => 'BATCH-RCV-002',
    'expiration_date' => '2027-01-31',
], 1);

// Accepted is only 2 packs and 4 loose = 28 Base Units! Damaged is 1 pack (12) + 1 loose = 13 Base Units!
$assert($damagedReceipt['received_packs'] === 3, 'Damaged receipt received 3 packs');
$assert($damagedReceipt['accepted_packs'] === 2, 'Only 2 complete packs accepted');
$assert($damagedReceipt['damaged_packs'] === 1, '1 pack reported damaged');
$assert($damagedReceipt['received_loose'] === 5, 'Damaged receipt received 5 loose');
$assert($damagedReceipt['accepted_loose'] === 4, '4 loose accepted');
$assert($damagedReceipt['damaged_loose'] === 1, '1 loose reported damaged');
$assert($damagedReceipt['received_qty'] === 41, 'Total delivered base units is (3*12)+5 = 41');
$assert($damagedReceipt['accepted_qty'] === 28, 'Total accepted base units is (2*12)+4 = 28');
$assert($damagedReceipt['damaged_qty'] === 13, 'Total damaged base units is (1*12)+1 = 13');

// Only complete accepted packs increase sealed stock! Damaged pack cannot remain saleable sealed pack!
$summaryAfterDamaged = $inventoryService->getProductsWithBatchSummaries('2026-10-20')[0];
$assert((int)$summaryAfterDamaged['physical_sealed_packs'] === 9, 'Physical sealed packs is 7 + 2 = 9 (damaged pack not added)');
$assert((int)$summaryAfterDamaged['physical_loose_pieces'] === 11, 'Physical loose pieces is 7 + 4 = 11 (damaged piece not added)');
$assert((int)$summaryAfterDamaged['quantity_on_hand'] === 119, 'Base Units total is 91 + 28 = 119');

// Verify inventory_adjustments has 13 units damaged
$adjStmt = $pdo->query("SELECT adjustment_qty, reason, status FROM inventory_adjustments ORDER BY adjustment_id DESC LIMIT 1");
$adj = $adjStmt->fetch();
$assert((int)$adj['adjustment_qty'] === -13, 'Inventory adjustment excluded -13 damaged units');
$assert($adj['status'] === 'cancelled', 'Damaged goods on receiving are excluded before stock was added');

// Test rejected quantities explicitly by form
$rejectedReceipt = $receivingService->receiveStock([
    'product_id' => $productId,
    'received_packs' => 3,
    'damaged_packs' => 1,
    'rejected_packs' => 1,
    'received_loose' => 5,
    'damaged_loose' => 1,
    'rejected_loose' => 1,
    'batch_number' => 'BATCH-RCV-003',
    'expiration_date' => '2027-02-28',
], 1);

$assert($rejectedReceipt['received_packs'] === 3, 'Rejected receipt received 3 packs');
$assert($rejectedReceipt['accepted_packs'] === 1, 'Only 1 complete pack accepted (3 - 1 damaged - 1 rejected)');
$assert($rejectedReceipt['damaged_packs'] === 1, '1 pack reported damaged');
$assert($rejectedReceipt['rejected_packs'] === 1, '1 pack reported rejected');
$assert($rejectedReceipt['received_loose'] === 5, 'Rejected receipt received 5 loose');
$assert($rejectedReceipt['accepted_loose'] === 3, '3 loose accepted (5 - 1 damaged - 1 rejected)');
$assert($rejectedReceipt['damaged_loose'] === 1, '1 loose reported damaged');
$assert($rejectedReceipt['rejected_loose'] === 1, '1 loose reported rejected');
$assert($rejectedReceipt['received_qty'] === 41, 'Delivered base units is 41');
$assert($rejectedReceipt['accepted_qty'] === 15, 'Accepted base units is (1*12)+3 = 15');
$assert($rejectedReceipt['damaged_qty'] === 13, 'Damaged base units is (1*12)+1 = 13');
$assert($rejectedReceipt['rejected_qty'] === 13, 'Rejected base units is (1*12)+1 = 13');
$assert($rejectedReceipt['discrepancy_type'] === 'damaged', 'Discrepancy type is damaged when damaged > 0');
$assert($rejectedReceipt['discrepancy_qty'] === 26, 'Discrepancy qty accounts for damaged + rejected = 13 + 13 = 26');

// Pure rejected delivery (damaged = 0, rejected > 0)
$pureRejectedReceipt = $receivingService->receiveStock([
    'product_id' => $productId,
    'received_packs' => 2,
    'rejected_packs' => 1,
    'received_loose' => 2,
    'rejected_loose' => 1,
    'batch_number' => 'BATCH-RCV-004',
    'expiration_date' => '2027-03-31',
], 1);
$assert($pureRejectedReceipt['discrepancy_type'] === 'other', 'Discrepancy type is other when rejected > 0 and damaged = 0');
$assert($pureRejectedReceipt['discrepancy_qty'] === 13, 'Discrepancy qty equals rejected qty 13');

echo "AC 2 passed.\n";

// Test AC 3: Barcode lookup and unknown conversion protection
echo "Testing AC 3: Barcode scans route to correct forms; unknown conversion cannot manufacture packs...\n";
$pieceLookup = $lookupService->lookup('CHOCO-PIECE-BARCODE');
$assert($pieceLookup['outcome'] === 'match', 'Piece barcode matches');
$assert($pieceLookup['matched_code_kind'] === 'unit_barcode', 'Piece barcode kind is unit_barcode');

$packLookup = $lookupService->lookup('CHOCO-PACK-BARCODE');
$assert($packLookup['outcome'] === 'match', 'Pack barcode matches');
$assert($packLookup['matched_code_kind'] === 'pack_barcode', 'Pack barcode kind is pack_barcode');

// Create ordinary product without pack config
$plain = $productService->createProduct([
    'product_name' => 'Plain Cracker',
    'category_id' => 1,
    'sku' => 'SKU-PLAIN-01',
    'barcode' => 'PLAIN-UNIT-BARCODE',
    'case_barcode' => 'PLAIN-CASE-BARCODE',
    'cost_price' => 5.00,
    'selling_price' => 7.00,
    'units_per_package' => 20, // Receiving Unit case of 20
    'starting_stock_mode' => 'pieces',
    'initial_stock_quantity' => 10,
], 1);
$plainId = (int)$plain;

// Receive 2 packages / cases of 20 units_per_package via legacy quantity_mode='packages'
$pkgReceipt = $receivingService->receiveStock([
    'product_id' => $plainId,
    'quantity_mode' => 'packages',
    'received_packages' => 2,
    'damaged_qty' => 0,
    'cost_price' => '5.00',
    'batch_number' => 'BATCH-PLAIN-001',
], 1);
$assert($pkgReceipt['accepted_qty'] === 40, '2 packages of 20 = 40 accepted units');
$plainBatches = $inventoryService->getProductBatches($plainId);
$plainBatch = null;
foreach ($plainBatches as $b) {
    if ($b['batch_number'] === 'BATCH-PLAIN-001') {
        $plainBatch = $b;
        break;
    }
}
$assert($plainBatch !== null, 'Batch BATCH-PLAIN-001 found');
$assert((int)$plainBatch['sealed_quantity'] === 0, 'Receiving packages does NOT manufacture sealed Sales Packs');
$assert((int)$plainBatch['loose_quantity'] === 40, 'All units enter loose stock');

// Attempting to receive packs on product without pack_size must be rejected!
$rejects(function () use ($receivingService, $plainId) {
    $receivingService->receiveStock([
        'product_id' => $plainId,
        'received_packs' => 5,
        'received_loose' => 0,
    ], 1);
}, 'Sales Pack');

echo "AC 3 passed.\n";

// Test AC 4: Receiving history retention
echo "Testing AC 4: Receiving history retains forms, conversions, costs, details, attribution...\n";
$history = $receivingService->getGroupedReceivingHistory(1, 1);
$assert(!empty($history), 'Grouped receiving history is not empty');
$foundLine = null;
foreach ($history as $entry) {
    foreach ($entry['lines'] as $line) {
        if ($line['receiving_id'] === $receipt['receiving_id']) {
            $foundLine = $line;
            break 2;
        }
    }
}
$assert($foundLine !== null, 'Found line in receiving history');
$assert($foundLine['received_packs'] === 2, 'History has received_packs = 2');
$assert($foundLine['accepted_packs'] === 2, 'History has accepted_packs = 2');
$assert($foundLine['received_loose'] === 4, 'History has received_loose = 4');
$assert($foundLine['accepted_loose'] === 4, 'History has accepted_loose = 4');
$assert($foundLine['pack_size_used'] === 12, 'History has pack_size_used = 12');
$assert((float)$foundLine['cost_price'] === 8.00, 'History retains purchase cost 8.00');

// Check unknown cost entry in history
$unknownCostLine = null;
foreach ($history as $entry) {
    foreach ($entry['lines'] as $line) {
        if ($line['receiving_id'] === $damagedReceipt['receiving_id']) {
            $unknownCostLine = $line;
            break 2;
        }
    }
}
$assert($unknownCostLine !== null, 'Found unknown cost line in history');
$assert($unknownCostLine['cost_price'] === null, 'Omitted cost remains strictly null (unknown)');
$assert($unknownCostLine['damaged_packs'] === 1, 'History has damaged_packs = 1');
$assert($unknownCostLine['damaged_loose'] === 1, 'History has damaged_loose = 1');

echo "AC 4 passed.\n";

// Test AC 6: Concurrency and failure rollback
echo "Testing AC 6: Complete atomic rollback on failure...\n";
$stockBeforeRollback = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$batchesCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM product_batches WHERE product_id = {$productId}")->fetchColumn();
$receivingCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving WHERE product_id = {$productId}")->fetchColumn();

// Attempt multi-line shopping trip where second line fails
$rejects(function () use ($receivingService, $productId, $plainId) {
    $receivingService->receiveShoppingTrip([
        [
            'product_id' => $productId,
            'received_packs' => 3,
            'received_loose' => 0,
        ],
        [
            'product_id' => $plainId,
            'received_packs' => 10, // Invalid: plain product has no pack size!
        ]
    ], ['supplier' => 'Failing Supplier'], 1);
}, 'Sales Pack');

// Verify complete rollback
$stockAfterRollback = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$batchesCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM product_batches WHERE product_id = {$productId}")->fetchColumn();
$receivingCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving WHERE product_id = {$productId}")->fetchColumn();

$assert($stockAfterRollback === $stockBeforeRollback, 'Inventory rolled back completely');
$assert($batchesCountAfter === $batchesCountBefore, 'No partial batch created on rollback');
$assert($receivingCountAfter === $receivingCountBefore, 'No partial receiving record created on rollback');

echo "AC 6 passed.\n";

// Test AC 7: Reject invalid counts, fractional counts, inconsistent rejected quantities, overflow
echo "Testing AC 7: Reject invalid/fractional counts, inconsistent rejected quantities, overflow...\n";

// Fractional received_packs
$rejects(function () use ($receivingService, $productId) {
    $receivingService->receiveStock([
        'product_id' => $productId,
        'received_packs' => '2.5',
    ], 1);
}, 'must be a non-negative whole number');

// Fractional received_loose
$rejects(function () use ($receivingService, $productId) {
    $receivingService->receiveStock([
        'product_id' => $productId,
        'received_packs' => 2,
        'received_loose' => '4.2',
    ], 1);
}, 'must be a non-negative whole number');

// Negative counts
$rejects(function () use ($receivingService, $productId) {
    $receivingService->receiveStock([
        'product_id' => $productId,
        'received_packs' => -1,
    ], 1);
}, 'must be a non-negative whole number');

// Damaged packs > received packs
$rejects(function () use ($receivingService, $productId) {
    $receivingService->receiveStock([
        'product_id' => $productId,
        'received_packs' => 2,
        'damaged_packs' => 3,
    ], 1);
}, 'Damaged packs cannot exceed');

// Damaged loose > received loose
$rejects(function () use ($receivingService, $productId) {
    $receivingService->receiveStock([
        'product_id' => $productId,
        'received_loose' => 2,
        'damaged_loose' => 5,
    ], 1);
}, 'Damaged loose pieces cannot exceed');

// Inconsistent accepted + damaged + rejected
$rejects(function () use ($receivingService, $productId) {
    $receivingService->receiveStock([
        'product_id' => $productId,
        'received_packs' => 5,
        'accepted_packs' => 4,
        'damaged_packs' => 2, // 4 + 2 != 5
    ], 1);
}, 'Inconsistent');

// 0 accepted and 0 damaged/rejected
$rejects(function () use ($receivingService, $productId) {
    $receivingService->receiveStock([
        'product_id' => $productId,
        'received_packs' => 0,
        'received_loose' => 0,
    ], 1);
}, 'At least one delivered unit');

$rejects(function () use ($receivingService, $productId) {
    $receivingService->receiveStock([
        'product_id' => $productId,
        'received_packs' => 2,
        'damaged_packs' => 1,
        'rejected_packs' => 2,
    ], 1);
}, 'Damaged and rejected packs cannot exceed quantity received.');

$rejects(function () use ($receivingService, $productId) {
    $receivingService->receiveStock([
        'product_id' => $productId,
        'received_packs' => 2,
        'received_loose' => 2,
        'damaged_loose' => 1,
        'rejected_loose' => 2,
    ], 1);
}, 'Damaged and rejected loose pieces cannot exceed quantity received.');

// Overflow
$rejects(function () use ($receivingService, $productId) {
    $receivingService->receiveStock([
        'product_id' => $productId,
        'received_packs' => 200000000, // 200M * 12 > INT_MAX
    ], 1);
}, 'too large');

echo "AC 7 passed.\n";

echo "\nAll sales pack receiving integration and contract checks PASSED!\n";
