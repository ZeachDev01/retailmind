<?php
// Contract and integration test for Issue #217: Preserve batch dates through product creation and receiving.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';
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

try {
    // 1. Static Contract & UI Checks on products.php
    echo "Checking products.php static contract for Starting Stock Expiration...\n";
    $productsSource = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/inventory_management/products.php');

    $assert(str_contains($productsSource, 'id="starting-stock-expiration-group" hidden'), 'products.php hides starting-stock-expiration-group by default');
    $assert(str_contains($productsSource, 'Starting Stock Expiration'), 'products.php labels field as Starting Stock Expiration');
    $assert(str_contains($productsSource, 'id="starting-stock-expiration" name="expiration_date"'), 'products.php provides starting-stock-expiration input named expiration_date');
    $assert(str_contains($productsSource, 'updateStartingStockExpirationVisibility'), 'products.php provides dynamic visibility helper for starting stock expiration');

    // 2. Database Integration Setup with in-memory SQLite
    echo "Setting up SQLite test database...\n";
    class SqliteTestPdo extends PDO {
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

    $pdo = new SqliteTestPdo('sqlite::memory:');
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
        INSERT INTO categories (category_id, category_name) VALUES (1, 'Bakery');

        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT,
            barcode TEXT,
            case_barcode TEXT,
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

        CREATE TABLE stock_receiving_groups (
            group_id INTEGER PRIMARY KEY AUTOINCREMENT,
            received_by INTEGER NOT NULL,
            supplier TEXT,
            invoice_number TEXT,
            notes TEXT,
            received_at TEXT DEFAULT CURRENT_TIMESTAMP,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE stock_receiving (
            receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER,
            product_id INTEGER NOT NULL,
            received_qty INTEGER NOT NULL,
            received_packages INTEGER NOT NULL DEFAULT 0,
            units_per_package_used INTEGER NOT NULL DEFAULT 1,
            accepted_qty INTEGER NOT NULL,
            damaged_qty INTEGER NOT NULL DEFAULT 0,
            cost_price REAL,
            total_cost REAL,
            received_by INTEGER NOT NULL,
            supplier TEXT,
            invoice_number TEXT,
            batch_number TEXT,
            expiration_date TEXT,
            discrepancy_type TEXT DEFAULT 'none',
            discrepancy_qty INTEGER DEFAULT 0,
            discrepancy_notes TEXT,
            notes TEXT,
            received_at TEXT DEFAULT CURRENT_TIMESTAMP,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE product_batches (
            batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            receiving_id INTEGER,
            batch_number TEXT,
            quantity INTEGER NOT NULL,
            remaining_quantity INTEGER NOT NULL,
            expiration_date TEXT,
            date_received TEXT DEFAULT CURRENT_TIMESTAMP,
            supplier TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE purchase_history (
            history_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            supplier TEXT,
            quantity_purchased INTEGER NOT NULL,
            cost_price REAL NOT NULL,
            purchase_date TEXT DEFAULT CURRENT_TIMESTAMP,
            total_cost REAL NOT NULL,
            recorded_by INTEGER NOT NULL
        );

        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            change_qty INTEGER NOT NULL,
            reason TEXT NOT NULL,
            moved_by INTEGER NOT NULL,
            moved_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE inventory_adjustments (
            adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            adjustment_qty INTEGER NOT NULL,
            adjustment_type TEXT NOT NULL,
            reported_by INTEGER NOT NULL,
            reason TEXT,
            status TEXT DEFAULT 'pending',
            review_notes TEXT,
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
            notify_expiring_stock INTEGER DEFAULT 1,
            low_stock_threshold INTEGER DEFAULT NULL
        );
    ");

    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'inventory_manager';
    $_SESSION['user'] = ['role_name' => 'inventory_manager', 'user_id' => 1];

    $productService = new ProductService($pdo);
    $receivingService = new ReceivingService($pdo);

    // 3. Opening stock with expiration creates Opening Batch with supplied date
    echo "Testing opening stock with expiration creates Opening Batch...\n";
    $breadId = $productService->createProduct([
        'product_name' => 'Artisan Bread',
        'initial_stock_quantity' => 20,
        'cost_price' => 15.00,
        'selling_price' => 30.00,
        'expiration_date' => '2026-11-01',
        'preferred_supplier' => 'Bakery Supply',
    ], 1);

    $breadProduct = $pdo->query("SELECT * FROM products WHERE product_id = {$breadId}")->fetch(PDO::FETCH_ASSOC);
    $assert($breadProduct['expiration_date'] === '2026-11-01', 'Product table retains starting stock expiration date as legacy evidence');

    $batches = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$breadId}")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($batches) === 1, 'Opening stock creates exactly one Opening Batch');
    $assert((int)$batches[0]['quantity'] === 20 && (int)$batches[0]['remaining_quantity'] === 20, 'Opening Batch records full starting quantity');
    $assert($batches[0]['expiration_date'] === '2026-11-01', 'Opening Batch records supplied expiration date');
    $assert($batches[0]['receiving_id'] === null, 'Opening Batch has null receiving_id identifying it as opening stock');

    $invRow = $pdo->query("SELECT * FROM inventory WHERE product_id = {$breadId}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$invRow['quantity_on_hand'] === 20, 'Inventory quantity on hand is 20');

    $movement = $pdo->query("SELECT * FROM stock_movements WHERE product_id = {$breadId}")->fetch(PDO::FETCH_ASSOC);
    $assert(!empty($movement) && (int)$movement['change_qty'] === 20 && $movement['reason'] === 'purchase', 'Stock movement recorded for opening stock');

    // 4. Zero starting stock creates no quantity-bearing batch
    echo "Testing zero starting stock creates no quantity-bearing batch...\n";
    $zeroStockId = $productService->createProduct([
        'product_name' => 'Zero Stock Loaf',
        'initial_stock_quantity' => 0,
        'cost_price' => 10.00,
        'selling_price' => 20.00,
        'expiration_date' => '2026-11-01', // Should not create batch
    ], 1);

    $zeroBatches = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$zeroStockId}")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($zeroBatches) === 0, 'Zero starting stock creates no batch records in product_batches');

    $zeroProduct = $pdo->query("SELECT * FROM products WHERE product_id = {$zeroStockId}")->fetch(PDO::FETCH_ASSOC);
    $assert($zeroProduct['expiration_date'] === null, 'Zero starting stock leaves product expiration_date null');

    $zeroInv = $pdo->query("SELECT * FROM inventory WHERE product_id = {$zeroStockId}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$zeroInv['quantity_on_hand'] === 0, 'Zero starting stock has 0 quantity on hand');

    $zeroMovements = $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = {$zeroStockId}")->fetchColumn();
    $assert((int)$zeroMovements === 0, 'Zero starting stock records no stock movements');

    // 5. Optional / omitted expiration dates remain null
    echo "Testing optional/omitted expiration dates remain null...\n";
    $undatedId = $productService->createProduct([
        'product_name' => 'General Merchandise Box',
        'initial_stock_quantity' => 10,
        'cost_price' => 50.00,
        'selling_price' => 100.00,
        'expiration_date' => '', // Omitted date
    ], 1);

    $undatedBatch = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$undatedId}")->fetch(PDO::FETCH_ASSOC);
    $assert(!empty($undatedBatch) && $undatedBatch['expiration_date'] === null, 'Omitted expiration date in product creation remains null in Opening Batch');

    $undatedProduct = $pdo->query("SELECT * FROM products WHERE product_id = {$undatedId}")->fetch(PDO::FETCH_ASSOC);
    $assert($undatedProduct['expiration_date'] === null, 'Omitted expiration date remains null on product record');

    // Receiving with omitted expiration date
    $receivingService->receiveShoppingTrip([
        [
            'product_id' => $undatedId,
            'quantity_mode' => 'units',
            'received_qty' => 5,
            'expiration_date' => '',
        ]
    ], ['supplier' => 'Wholesaler'], 1);

    $undatedBatches = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$undatedId} ORDER BY batch_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($undatedBatches) === 2, 'Two batches exist for merchandise');
    $assert($undatedBatches[1]['expiration_date'] === null, 'Received batch with omitted date records null expiration_date');
    $assert($undatedBatches[1]['receiving_id'] !== null, 'Received batch has non-null receiving_id');

    // 6. Strict invalid calendar date rejection in ProductService & ReceivingService
    echo "Testing strict invalid calendar date rejection...\n";
    $invalidDates = [
        '2026-02-31' => 'Day out of range for February',
        '2026-02-29' => 'Non-leap year February 29',
        '2026-04-31' => 'April 31 does not exist',
        '2026-13-01' => 'Month 13 does not exist',
        '2026-00-10' => 'Month 0 does not exist',
        '2026-10-00' => 'Day 0 does not exist',
        'not-a-date' => 'Non-date string',
        '2026-1-1' => 'Malformed month and day',
        '2026/10/25' => 'Slash-delimited date',
        '0999-12-31' => 'Year before 1000',
    ];

    foreach ($invalidDates as $invalidDate => $reason) {
        $productThrew = false;
        try {
            $productService->createProduct([
                'product_name' => 'Invalid Test Product',
                'initial_stock_quantity' => 1,
                'expiration_date' => $invalidDate,
            ], 1);
        } catch (RuntimeException $e) {
            $productThrew = true;
            $assert($e->getMessage() === 'Enter a valid expiration date.', "ProductService strictly rejects {$invalidDate} ({$reason}) with exact message");
        }
        $assert($productThrew, "ProductService throws for {$invalidDate} ({$reason})");

        $receivingThrew = false;
        try {
            $receivingService->receiveShoppingTrip([
                [
                    'product_id' => $breadId,
                    'quantity_mode' => 'units',
                    'received_qty' => 1,
                    'expiration_date' => $invalidDate,
                ]
            ], ['supplier' => 'Test Supplier'], 1);
        } catch (RuntimeException $e) {
            $receivingThrew = true;
            $assert(str_contains($e->getMessage(), 'Enter a valid expiration date.'), "ReceivingService strictly rejects {$invalidDate} ({$reason})");
        }
        $assert($receivingThrew, "ReceivingService throws for {$invalidDate} ({$reason})");
    }

    // Valid leap year February 29 succeeds
    $leapYearId = $productService->createProduct([
        'product_name' => 'Leap Year Product',
        'initial_stock_quantity' => 2,
        'expiration_date' => '2024-02-29',
    ], 1);
    $leapBatch = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$leapYearId}")->fetch(PDO::FETCH_ASSOC);
    $assert(!empty($leapBatch) && $leapBatch['expiration_date'] === '2024-02-29', 'Valid leap year 2024-02-29 accepted');

    // 7. Multiple receiving entries with separate dates preserve all batch dates without overwriting prior batches or older dates
    echo "Testing multiple receiving entries preserve all batch dates...\n";
    // Artisan bread initially had:
    // Opening Batch: 20 units, expiration '2026-11-01'
    // Total on hand: 20
    // Product expiration: '2026-11-01'

    // Delivery 1: 30 loaves expiring '2026-12-01' (later date)
    $res1 = $receivingService->receiveShoppingTrip([
        [
            'product_id' => $breadId,
            'quantity_mode' => 'units',
            'received_qty' => 30,
            'expiration_date' => '2026-12-01',
            'batch_number' => 'DEC-01',
        ]
    ], ['supplier' => 'Bakery Express', 'invoice_number' => 'INV-101'], 1);

    $breadInv1 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$breadId}")->fetchColumn();
    $assert($breadInv1 === 50, 'Total on hand increased to 50 (20 opening + 30 received)');

    $breadProd1 = $pdo->query("SELECT expiration_date FROM products WHERE product_id = {$breadId}")->fetch(PDO::FETCH_ASSOC);
    $assert($breadProd1['expiration_date'] === '2026-11-01', 'Receiving does NOT overwrite products.expiration_date with new delivery date');

    $batches1 = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$breadId} ORDER BY batch_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($batches1) === 2, 'Two batches exist after delivery 1');
    $assert($batches1[0]['expiration_date'] === '2026-11-01' && (int)$batches1[0]['remaining_quantity'] === 20, 'Opening Batch preserved intact');
    $assert($batches1[1]['expiration_date'] === '2026-12-01' && (int)$batches1[1]['remaining_quantity'] === 30, 'New delivery batch recorded with 2026-12-01');

    // Delivery 2: 15 loaves expiring '2026-10-15' (earlier / older date!)
    $res2 = $receivingService->receiveShoppingTrip([
        [
            'product_id' => $breadId,
            'quantity_mode' => 'units',
            'received_qty' => 15,
            'expiration_date' => '2026-10-15',
            'batch_number' => 'OCT-15',
        ]
    ], ['supplier' => 'Bakery Express', 'invoice_number' => 'INV-102'], 1);

    $breadInv2 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$breadId}")->fetchColumn();
    $assert($breadInv2 === 65, 'Total on hand increased to 65 (20 + 30 + 15)');

    $batches2 = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$breadId} ORDER BY batch_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($batches2) === 3, 'Three batches exist after delivery 2');
    $assert($batches2[0]['expiration_date'] === '2026-11-01', 'Opening batch expiration 2026-11-01 preserved');
    $assert($batches2[1]['expiration_date'] === '2026-12-01', 'Delivery 1 batch expiration 2026-12-01 preserved');
    $assert($batches2[2]['expiration_date'] === '2026-10-15', 'Delivery 2 batch expiration 2026-10-15 preserved');

    // Delivery 3: 10 loaves sharing identical date '2026-12-01'
    $res3 = $receivingService->receiveShoppingTrip([
        [
            'product_id' => $breadId,
            'quantity_mode' => 'units',
            'received_qty' => 10,
            'expiration_date' => '2026-12-01',
            'batch_number' => 'DEC-01-B',
        ]
    ], ['supplier' => 'Bakery Express', 'invoice_number' => 'INV-103'], 1);

    $batches3 = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$breadId} ORDER BY batch_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($batches3) === 4, 'Four distinct batches exist, allowing duplicate expiration dates across separate batches');
    $assert($batches3[3]['expiration_date'] === '2026-12-01' && (int)$batches3[3]['remaining_quantity'] === 10, 'Fourth batch recorded with shared date 2026-12-01');

    // 8. Product editing does not rewrite existing batch dates
    echo "Testing product catalog editing does not rewrite existing batch dates...\n";
    $productService->updateProductCatalogDetails($breadId, [
        'product_name' => 'Artisan Sourdough Bread',
        'selling_price' => 35.00,
        'reorder_level' => 15,
        'expiration_date' => '2029-01-01', // Catalog edits cannot alter batch dates or legacy evidence.
    ], 1);

    $breadProdAfterEdit = $pdo->query("SELECT * FROM products WHERE product_id = {$breadId}")->fetch(PDO::FETCH_ASSOC);
    $assert($breadProdAfterEdit['product_name'] === 'Artisan Sourdough Bread', 'Product name updated in catalog');
    $assert($breadProdAfterEdit['expiration_date'] === '2026-11-01', 'Product expiration_date unchanged by catalog edit');

    $batchesAfterEdit = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$breadId} ORDER BY batch_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($batchesAfterEdit) === 4, 'All four batches still exist');
    $assert($batchesAfterEdit[0]['expiration_date'] === '2026-11-01', 'Batch 1 date untouched by catalog edit');
    $assert($batchesAfterEdit[1]['expiration_date'] === '2026-12-01', 'Batch 2 date untouched by catalog edit');
    $assert($batchesAfterEdit[2]['expiration_date'] === '2026-10-15', 'Batch 3 date untouched by catalog edit');
    $assert($batchesAfterEdit[3]['expiration_date'] === '2026-12-01', 'Batch 4 date untouched by catalog edit');

    // 8b. Package conversion and duplicate line protection
    echo "Testing package conversion into product_batches...\n";
    $packProdId = $productService->createProduct([
        'product_name' => 'Packaged Soda Can',
        'barcode' => 'PKG-SODA-001',
        'units_per_package' => 12,
        'initial_stock_quantity' => 0,
        'cost_price' => 24.00,
        'selling_price' => 3.00,
    ], 1);
    $receivingService->receiveShoppingTrip([
        [
            'product_id' => $packProdId,
            'quantity_mode' => 'packages',
            'received_packages' => 3, // 3 * 12 = 36 units
            'expiration_date' => '2027-01-15',
        ]
    ], ['supplier' => 'Wholesale Soda', 'invoice_number' => 'INV-SODA-1'], 1);
    $packBatch = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$packProdId}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$packBatch['quantity'] === 36 && (int)$packBatch['remaining_quantity'] === 36, 'Package receiving converted 3 packages into 36 units in product_batches');
    $assert($packBatch['expiration_date'] === '2027-01-15', 'Packaged batch has correct expiration date');

    echo "Testing duplicate product in receiving submission is rejected...\n";
    $dupRejected = false;
    try {
        $receivingService->receiveShoppingTrip([
            ['product_id' => $packProdId, 'quantity_mode' => 'units', 'received_qty' => 5],
            ['product_id' => $packProdId, 'quantity_mode' => 'units', 'received_qty' => 10],
        ], ['supplier' => 'Dup Store', 'invoice_number' => 'INV-DUP'], 1);
    } catch (RuntimeException $e) {
        $dupRejected = true;
        $assert(str_contains($e->getMessage(), 'Duplicate product on line 2'), 'Duplicate product line rejected with helpful message');
    }
    $assert($dupRejected, 'Duplicate product rejected');

    // 9. Atomic rollback after failed receiving submission
    echo "Testing atomic rollback after failed receiving submission...\n";
    $productCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $groupCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving_groups")->fetchColumn();
    $receivingCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving")->fetchColumn();
    $batchCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM product_batches")->fetchColumn();
    $movementCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
    $invBeforeBread = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$breadId}")->fetchColumn();
    $invBeforeUndated = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$undatedId}")->fetchColumn();

    // Multi-product submission where line 1 is valid, but line 2 has an invalid expiration date
    $multiFailed = false;
    try {
        $receivingService->receiveShoppingTrip([
            [
                'product_id' => $breadId,
                'quantity_mode' => 'units',
                'received_qty' => 5,
                'expiration_date' => '2026-12-30',
            ],
            [
                'product_id' => $undatedId,
                'quantity_mode' => 'units',
                'received_qty' => 5,
                'expiration_date' => '2026-02-31', // INVALID DATE!
            ]
        ], ['supplier' => 'Failed Trip', 'invoice_number' => 'INV-FAIL-01'], 1);
    } catch (RuntimeException $e) {
        $multiFailed = true;
        $assert(str_contains($e->getMessage(), 'Line 2: Enter a valid expiration date.'), 'Line 2 error thrown with line context');
    }
    $assert($multiFailed, 'Multi-product submission with invalid line was rejected');

    $groupCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving_groups")->fetchColumn();
    $receivingCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving")->fetchColumn();
    $batchCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM product_batches")->fetchColumn();
    $movementCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
    $invAfterBread = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$breadId}")->fetchColumn();
    $invAfterUndated = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$undatedId}")->fetchColumn();

    $assert($groupCountAfter === $groupCountBefore, 'No stock_receiving_groups record created on rollback');
    $assert($receivingCountAfter === $receivingCountBefore, 'No stock_receiving lines created on rollback');
    $assert($batchCountAfter === $batchCountBefore, 'No product_batches created on rollback (line 1 was rolled back)');
    $assert($movementCountAfter === $movementCountBefore, 'No stock_movements recorded on rollback');
    $assert($invAfterBread === $invBeforeBread, 'Line 1 product inventory on hand unchanged on rollback');
    $assert($invAfterUndated === $invBeforeUndated, 'Line 2 product inventory on hand unchanged on rollback');

    echo "Testing starting quantity validation at the public creation workflow...\n";
    foreach ([-1, '1.5', '2147483648', [], true] as $invalidQuantity) {
        $rejected = false;
        try {
            $productService->createProduct([
                'product_name' => 'Invalid starting quantity',
                'initial_stock_quantity' => $invalidQuantity,
            ], 1);
        } catch (RuntimeException $e) {
            $rejected = true;
        }
        $assert($rejected, 'Invalid starting quantity rejected: ' . json_encode($invalidQuantity));
    }

    // The retained legacy HTTP purchase action uses this public service workflow too.
    echo "Testing legacy stock purchase preserves batch dates and product evidence...\n";
    $productService->purchaseStock([
        'search_term' => 'Artisan Sourdough Bread',
        'purchase_quantity' => 4,
        'purchase_cost_price' => 15,
        'expiration_date' => '2027-02-28',
    ], 1);
    $legacyBatches = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$breadId} ORDER BY batch_id")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($legacyBatches) === 5, 'Legacy purchase creates a distinct batch');
    $assert($legacyBatches[4]['expiration_date'] === '2027-02-28' && (int)$legacyBatches[4]['remaining_quantity'] === 4, 'Legacy purchase retains supplied date and quantity');
    $assert($legacyBatches[0]['expiration_date'] === '2026-11-01', 'Legacy purchase preserves opening batch date');
    $assert($pdo->query("SELECT expiration_date FROM products WHERE product_id = {$breadId}")->fetchColumn() === '2026-11-01', 'Legacy purchase preserves original Product-date evidence');
    $assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$breadId}")->fetchColumn() === 79, 'Legacy purchase increases current inventory by four');

    echo "Testing legacy purchase rejects a missing current balance...\n";
    $pdo->exec("DELETE FROM inventory WHERE product_id = {$zeroStockId}");
    $missingBalanceRejected = false;
    try {
        $productService->purchaseStock(['search_term' => 'Zero Stock Loaf', 'product_name' => 'Zero Stock Loaf',
            'purchase_quantity' => 3, 'expiration_date' => '2027-01-01'], 1);
    } catch (RuntimeException $e) {
        $missingBalanceRejected = true;
    }
    $assert($missingBalanceRejected, 'Legacy purchase refuses missing inventory rather than recording unbalanced stock or a duplicate Product');

    // Fail at the last write, not input validation: preceding batches and totals must roll back too.
    echo "Testing storage-failure rollback for multi-product receiving and opening stock...\n";
    $savedState = static function () use ($pdo): array {
        $state = [];
        foreach (['products', 'inventory', 'product_batches', 'stock_receiving_groups', 'stock_receiving', 'stock_movements', 'purchase_history', 'activity_log'] as $table) {
            $state[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $state;
    };
    $stateBefore = $savedState();
    $pdo->exec("CREATE TRIGGER fail_receiving_movement BEFORE INSERT ON stock_movements
        WHEN NEW.product_id = {$undatedId} BEGIN SELECT RAISE(ABORT, 'Injected movement failure'); END");
    $storageFailed = false;
    try {
        $receivingService->receiveShoppingTrip([
            ['product_id' => $breadId, 'received_qty' => 2, 'cost_price' => '15.00', 'expiration_date' => '2027-03-01'],
            ['product_id' => $undatedId, 'received_qty' => 3, 'cost_price' => '50.00'],
        ], ['supplier' => 'Storage rollback fixture'], 1);
    } catch (RuntimeException $e) {
        $storageFailed = true;
    } finally {
        $pdo->exec('DROP TRIGGER fail_receiving_movement');
    }
    $assert($storageFailed && !$pdo->inTransaction(), 'Receiving storage failure closes the failed transaction');
    $assert($savedState() === $stateBefore, 'Storage failure rolls back both Products, totals, batches, receiving, movements, and purchase history');

    $pdo->exec("CREATE TRIGGER fail_opening_movement BEFORE INSERT ON stock_movements
        BEGIN SELECT RAISE(ABORT, 'Injected opening movement failure'); END");
    $openingFailed = false;
    try {
        $productService->createProduct(['product_name' => 'Failed Opening Batch', 'initial_stock_quantity' => 7,
            'expiration_date' => '2027-03-01'], 1);
    } catch (RuntimeException $e) {
        $openingFailed = true;
    } finally {
        $pdo->exec('DROP TRIGGER fail_opening_movement');
    }
    $assert($openingFailed && !$pdo->inTransaction(), 'Opening movement failure closes the failed transaction');
    $assert($savedState() === $stateBefore, 'Opening failure leaves no Product, inventory, batch, purchase history, or movement');

    echo "Testing Fiscal Period protection for starting stock and receiving...\n";
    $pdo->exec("UPDATE fiscal_periods SET status = 'closed'");
    $closedState = $savedState();
    foreach (['opening', 'receiving'] as $workflow) {
        $blocked = false;
        try {
            if ($workflow === 'opening') {
                $productService->createProduct(['product_name' => 'Closed period opening', 'initial_stock_quantity' => 1], 1);
            } else {
                $receivingService->receiveStock(['product_id' => $breadId, 'received_qty' => 1], 1);
            }
        } catch (RuntimeException $e) {
            $blocked = true;
        }
        $assert($blocked && $savedState() === $closedState, "Closed Fiscal Period blocks {$workflow} without any saved stock changes");
    }
    $pdo->exec("UPDATE fiscal_periods SET status = 'open'");

    echo "Testing successful atomic multi-product receiving...\n";
    $received = $receivingService->receiveShoppingTrip([
        ['product_id' => $breadId, 'received_qty' => 2, 'expiration_date' => '2027-03-01'],
        ['product_id' => $undatedId, 'received_qty' => 3],
    ], ['supplier' => 'Successful trip'], 1);
    $assert(count($received) === 2 && $received[0]['group_id'] === $received[1]['group_id'], 'Both Products saved under one receiving entry');
    $assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$breadId}")->fetchColumn() === 81, 'Successful trip increases bread total from 79 to 81');
    $assert((int)$pdo->query("SELECT SUM(remaining_quantity) FROM product_batches WHERE product_id = {$breadId}")->fetchColumn() === 81, 'Bread batch quantities equal the committed total');
    $assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$undatedId}")->fetchColumn() === 18, 'Successful trip increases merchandise total from 15 to 18');
    $assert((int)$pdo->query("SELECT SUM(remaining_quantity) FROM product_batches WHERE product_id = {$undatedId}")->fetchColumn() === 18, 'Undated merchandise batches equal the committed total');

    $stateBefore = $savedState();
    foreach ([['purchase_quantity' => '1.5'], ['expiration_date' => '2026-02-31']] as $invalidPurchase) {
        $invalidRejected = false;
        try {
            $productService->purchaseStock(array_replace(['search_term' => 'Artisan Sourdough Bread', 'purchase_quantity' => 1], $invalidPurchase), 1);
        } catch (RuntimeException $e) {
            $invalidRejected = true;
        }
        $assert($invalidRejected && $savedState() === $stateBefore, 'Legacy purchase rejects invalid quantities/dates without saving changes');
    }

} catch (Throwable $e) {
    $failures[] = "Unexpected exception: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    fwrite(STDERR, "  fail - Unexpected exception: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
}

if (!empty($failures)) {
    echo "\nBatch Product Creation & Receiving test FAILED with " . count($failures) . " failure(s).\n";
    exit(1);
}

echo "\nBatch Product Creation & Receiving test: ALL PASSED\n";
exit(0);
