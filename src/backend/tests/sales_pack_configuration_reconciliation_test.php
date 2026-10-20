<?php
// Contract and integration test for Issue #246: Configure Sales Packs and reconcile starting stock.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/ProductCodeLookupService.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
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

echo "Starting tests for Issue #246: Configure Sales Packs and reconcile starting stock...\n";

// 1. Static contract checks on frontend products.php
echo "Checking frontend/components/inventory_management/products.php static contract...\n";
$productsSource = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/inventory_management/products.php');

$assert(str_contains($productsSource, 'name="pack_size"'), 'products.php includes pack_size input');
$assert(str_contains($productsSource, 'name="pack_barcode"'), 'products.php includes pack_barcode input');
$assert(str_contains($productsSource, 'name="pack_price"'), 'products.php includes pack_price input');
$assert(str_contains($productsSource, 'name="starting_packs"'), 'products.php includes starting_packs input');
$assert(str_contains($productsSource, 'name="starting_loose"'), 'products.php includes starting_loose input');

// 2. Setup SQLite test database
echo "Setting up SQLite test database...\n";
class SqliteSalesPackTestPdo extends PDO {
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

$pdo = new SqliteSalesPackTestPdo('sqlite::memory:');
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
        cost_price REAL DEFAULT NULL,
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

    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_name TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open'
    );
    INSERT INTO fiscal_periods (period_name, start_date, end_date, status)
    VALUES ('Current Period', '2026-01-01', '2026-12-31', 'open');

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

    CREATE TABLE activity_log (
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
    );
");

// Mock active inventory manager session
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'inventory_manager';
$_SESSION['username'] = 'mgr';
$_SESSION['full_name'] = 'Inventory Mgr';

$productService = new ProductService($pdo);
$lookupService = new ProductCodeLookupService($pdo);
$inventoryService = new InventoryService($pdo);

// 3. Test: Ordinary product creation requires no pack fields
echo "Testing ordinary product creation without pack fields...\n";
$ordinaryId = $productService->createProduct([
    'barcode' => 'BARCODE-ORD-001',
    'product_name' => 'Ordinary Soda Can',
    'unit_price' => 25.00,
    'cost_price' => 18.00,
], 1);
$assert($ordinaryId > 0, 'Ordinary product created successfully');
$ordRow = $pdo->query("SELECT * FROM products WHERE product_id = {$ordinaryId}")->fetch(PDO::FETCH_ASSOC);
$assert($ordRow['pack_size'] === null, 'Ordinary product pack_size is null');
$assert($ordRow['pack_barcode'] === null, 'Ordinary product pack_barcode is null');
$assert($ordRow['pack_price'] === null, 'Ordinary product pack_price is null');

// 4. Test: Complete pack configuration validation on creation
echo "Testing incomplete pack configuration rejection on creation...\n";

// Only pack_size provided
try {
    $productService->createProduct([
        'barcode' => 'BARCODE-TEST-INC1',
        'product_name' => 'Incomplete 1',
        'unit_price' => 10.00,
        'pack_size' => 12,
    ], 1);
    $assert(false, 'Expected incomplete pack configuration (missing barcode & price) to throw');
} catch (Throwable $e) {
    $assert(str_contains($e->getMessage(), 'pack'), 'Incomplete pack configuration (missing barcode/price) throws: ' . $e->getMessage());
}

// Only pack_barcode provided
try {
    $productService->createProduct([
        'barcode' => 'BARCODE-TEST-INC2',
        'product_name' => 'Incomplete 2',
        'unit_price' => 10.00,
        'pack_barcode' => 'PACK-INC-2',
    ], 1);
    $assert(false, 'Expected incomplete pack configuration (missing size & price) to throw');
} catch (Throwable $e) {
    $assert(str_contains($e->getMessage(), 'pack'), 'Incomplete pack configuration (missing size/price) throws: ' . $e->getMessage());
}

// Only pack_price provided
try {
    $productService->createProduct([
        'barcode' => 'BARCODE-TEST-INC3',
        'product_name' => 'Incomplete 3',
        'unit_price' => 10.00,
        'pack_price' => 90.00,
    ], 1);
    $assert(false, 'Expected incomplete pack configuration (missing size & barcode) to throw');
} catch (Throwable $e) {
    $assert(str_contains($e->getMessage(), 'pack'), 'Incomplete pack configuration (missing size/barcode) throws: ' . $e->getMessage());
}

// 5. Test: Invalid pack_size validation (must be whole number > 1)
echo "Testing invalid pack_size rejection...\n";
foreach ([1, 0, -5, '1.5', 'abc'] as $invalidSize) {
    try {
        $productService->createProduct([
            'barcode' => 'BARCODE-INV-SZ',
            'product_name' => 'Invalid Size Product',
            'unit_price' => 10.00,
            'pack_size' => $invalidSize,
            'pack_barcode' => 'PACK-INV-SZ',
            'pack_price' => 50.00,
        ], 1);
        $assert(false, "Expected invalid pack_size {$invalidSize} to throw");
    } catch (Throwable $e) {
        $assert(true, "Invalid pack_size {$invalidSize} rejected: " . $e->getMessage());
    }
}

// 6. Test: Invalid pack_price validation (must be positive and strictly below contents * unit_price)
echo "Testing invalid pack_price rejection...\n";
// pack_price = 0
try {
    $productService->createProduct([
        'barcode' => 'BARCODE-PR-0',
        'product_name' => 'Zero Pack Price',
        'unit_price' => 10.00,
        'pack_size' => 12,
        'pack_barcode' => 'PACK-PR-0',
        'pack_price' => 0.00,
    ], 1);
    $assert(false, 'Expected zero pack price to throw');
} catch (Throwable $e) {
    $assert(true, 'Zero pack price rejected: ' . $e->getMessage());
}

// pack_price >= pack_size * unit_price (12 * 10 = 120; pack_price = 120.00)
try {
    $productService->createProduct([
        'barcode' => 'BARCODE-PR-EQUAL',
        'product_name' => 'Equal Pack Price',
        'unit_price' => 10.00,
        'pack_size' => 12,
        'pack_barcode' => 'PACK-PR-EQUAL',
        'pack_price' => 120.00,
    ], 1);
    $assert(false, 'Expected equal pack price to throw');
} catch (Throwable $e) {
    $assert(true, 'Equal pack price rejected: ' . $e->getMessage());
}

// pack_price > pack_size * unit_price (12 * 10 = 120; pack_price = 130.00)
try {
    $productService->createProduct([
        'barcode' => 'BARCODE-PR-MORE',
        'product_name' => 'Expensive Pack Price',
        'unit_price' => 10.00,
        'pack_size' => 12,
        'pack_barcode' => 'PACK-PR-MORE',
        'pack_price' => 130.00,
    ], 1);
    $assert(false, 'Expected expensive pack price to throw');
} catch (Throwable $e) {
    $assert(true, 'Expensive pack price rejected: ' . $e->getMessage());
}

// 7. Test: Barcode collision detection across products and code kinds
echo "Testing barcode identity conflicts across products and code kinds...\n";
// pack_barcode matching piece barcode on same product
try {
    $productService->createProduct([
        'barcode' => 'SHARED-CODE-1',
        'product_name' => 'Same Code Self',
        'unit_price' => 10.00,
        'pack_size' => 12,
        'pack_barcode' => 'SHARED-CODE-1',
        'pack_price' => 100.00,
    ], 1);
    $assert(false, 'Expected pack_barcode matching piece barcode to throw');
} catch (Throwable $e) {
    $assert(true, 'pack_barcode matching piece barcode rejected: ' . $e->getMessage());
}

// pack_barcode matching existing product barcode
try {
    $productService->createProduct([
        'barcode' => 'UNIQUE-PIECE-2',
        'product_name' => 'Colliding Pack Code',
        'unit_price' => 10.00,
        'pack_size' => 12,
        'pack_barcode' => 'BARCODE-ORD-001', // already used by ordinary product
        'pack_price' => 100.00,
    ], 1);
    $assert(false, 'Expected pack_barcode colliding with other product barcode to throw');
} catch (Throwable $e) {
    $assert(true, 'pack_barcode colliding with existing product barcode rejected: ' . $e->getMessage());
}

// 8. Test: Valid pack-enabled product creation & ProductCodeLookupService
echo "Testing valid pack-enabled product creation and code lookup...\n";
$biscuitId = $productService->createProduct([
    'sku' => 'BISCUIT-SKU-001',
    'barcode' => 'BISCUIT-PC-001',
    'product_name' => 'Butter Biscuits',
    'unit_price' => 10.00,
    'cost_price' => 6.00,
    'pack_size' => 12,
    'pack_barcode' => 'BISCUIT-PK-001',
    'pack_price' => 100.00, // 100 < 12 * 10
], 1);
$assert($biscuitId > 0, 'Biscuit pack product created');

// Check ProductCodeLookupService resolves piece barcode and pack barcode accurately
$lookupPiece = $lookupService->lookup('BISCUIT-PC-001');
$assert($lookupPiece['outcome'] === ProductCodeLookupService::OUTCOME_MATCH, 'Lookup piece barcode outcome is match');
$assert($lookupPiece['matched_code_kind'] === ProductCodeLookupService::KIND_UNIT_BARCODE, 'Lookup piece barcode kind is unit_barcode');
$assert((int)$lookupPiece['product']['product_id'] === $biscuitId, 'Lookup piece barcode matched biscuit ID');

$lookupPack = $lookupService->lookup('BISCUIT-PK-001');
$assert($lookupPack['outcome'] === ProductCodeLookupService::OUTCOME_MATCH, 'Lookup pack barcode outcome is match');
$assert($lookupPack['matched_code_kind'] === 'pack_barcode', 'Lookup pack barcode kind is pack_barcode');
$assert((int)$lookupPack['product']['product_id'] === $biscuitId, 'Lookup pack barcode matched biscuit ID');

// Ensure new product with piece barcode colliding with existing pack_barcode is rejected
try {
    $productService->createProduct([
        'barcode' => 'BISCUIT-PK-001',
        'product_name' => 'Colliding Unit Barcode',
        'unit_price' => 15.00,
    ], 1);
    $assert(false, 'Expected unit barcode colliding with existing pack_barcode to throw');
} catch (Throwable $e) {
    $assert(true, 'Unit barcode colliding with existing pack_barcode rejected: ' . $e->getMessage());
}

// 9. Test: Starting stock: 5 packs of 12 + 3 loose pieces yields 63 Base Units
echo "Testing starting stock of 5 packs of 12 plus 3 loose pieces...\n";
$startingProductId = $productService->createProduct([
    'barcode' => 'SNACK-PC-63',
    'product_name' => 'Choco Crackers 12s',
    'unit_price' => 15.00,
    'cost_price' => 10.00,
    'pack_size' => 12,
    'pack_barcode' => 'SNACK-PK-63',
    'pack_price' => 150.00, // 150 < 12 * 15 = 180
    'starting_packs' => 5,
    'starting_loose' => 3,
    'expiration_date' => '2026-12-31',
    'preferred_supplier' => 'Snack Co',
    'batch_number' => 'BATCH-OPEN-63',
], 1);

// Verify inventory balance is 63 Base Units
$invRow = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$startingProductId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$invRow['quantity_on_hand'] === 63, 'Inventory on hand is 63 Base Units');

// Verify products quantity_purchased is 63
$prodRow = $pdo->query("SELECT quantity_purchased FROM products WHERE product_id = {$startingProductId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$prodRow['quantity_purchased'] === 63, 'Product quantity_purchased is 63 Base Units');

// Verify product_batches Opening Batch
$batchRow = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$startingProductId}")->fetch(PDO::FETCH_ASSOC);
$assert($batchRow !== false, 'Opening Batch exists in product_batches');
$assert((int)$batchRow['quantity'] === 63, 'Opening Batch quantity is 63 Base Units');
$assert((int)$batchRow['remaining_quantity'] === 63, 'Opening Batch remaining_quantity is 63 Base Units');
$assert((int)$batchRow['sealed_quantity'] === 5, 'Opening Batch sealed_quantity is 5 packs');
$assert((int)$batchRow['loose_quantity'] === 3, 'Opening Batch loose_quantity is 3 pieces');
$assert($batchRow['expiration_date'] === '2026-12-31', 'Opening Batch expiration_date is 2026-12-31');
$assert((float)$batchRow['cost_price'] === 10.00, 'Opening Batch cost_price is 10.00');
$assert($batchRow['receiving_id'] === null, 'Opening Batch receiving_id is null');

// Verify purchase_history
$phRow = $pdo->query("SELECT * FROM purchase_history WHERE product_id = {$startingProductId}")->fetch(PDO::FETCH_ASSOC);
$assert($phRow !== false, 'purchase_history record created');
$assert((int)$phRow['quantity_purchased'] === 63, 'purchase_history quantity_purchased is 63 Base Units');
$assert((float)$phRow['total_cost'] === 630.00, 'purchase_history total_cost is 63 * 10 = 630.00');
$assert((int)$phRow['recorded_by'] === 1, 'purchase_history recorded_by is user 1');

// Verify stock_movements
$smRow = $pdo->query("SELECT * FROM stock_movements WHERE product_id = {$startingProductId}")->fetch(PDO::FETCH_ASSOC);
$assert($smRow !== false, 'stock_movements record created');
$assert((int)$smRow['change_qty'] === 63, 'stock_movements change_qty is 63');
$assert((int)$smRow['moved_by'] === 1, 'stock_movements moved_by is user 1');

// 10. Test: Atomic rollback on invalid starting stock input (no partial product or stock)
echo "Testing rollback on invalid starting stock...\n";
$prodCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$batchCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM product_batches")->fetchColumn();
$invCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();

// Invalid fractional pack count
try {
    $productService->createProduct([
        'barcode' => 'ROLLBACK-1',
        'product_name' => 'Rollback Product',
        'unit_price' => 20.00,
        'pack_size' => 6,
        'pack_barcode' => 'ROLLBACK-PK-1',
        'pack_price' => 100.00,
        'starting_packs' => '2.5',
        'starting_loose' => 0,
    ], 1);
    $assert(false, 'Expected fractional starting_packs to throw');
} catch (Throwable $e) {
    $assert(true, 'Fractional starting_packs rejected: ' . $e->getMessage());
}

// Overflow starting stock
try {
    $productService->createProduct([
        'barcode' => 'ROLLBACK-2',
        'product_name' => 'Rollback Product 2',
        'unit_price' => 20.00,
        'pack_size' => 1000,
        'pack_barcode' => 'ROLLBACK-PK-2',
        'pack_price' => 15000.00,
        'starting_packs' => 3000000, // 3,000,000 * 1000 = 3,000,000,000 > 2,147,483,647
        'starting_loose' => 0,
    ], 1);
    $assert(false, 'Expected overflow starting stock to throw');
} catch (Throwable $e) {
    $assert(true, 'Overflow starting stock rejected: ' . $e->getMessage());
}

$assert((int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() === $prodCountBefore, 'No partial product created on rollback');
$assert((int)$pdo->query("SELECT COUNT(*) FROM product_batches")->fetchColumn() === $batchCountBefore, 'No partial batch created on rollback');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn() === $invCountBefore, 'No partial inventory created on rollback');

// 11. Test: Editing pack configuration: prevent silent reinterpretation of sealed stock
echo "Testing pack configuration editing guard when sealed stock exists...\n";

// Attempt to change pack_size when startingProductId has 5 sealed packs
try {
    $productService->updateProductCatalogDetails($startingProductId, [
        'product_name' => 'Choco Crackers 10s',
        'pack_size' => 10, // changed from 12!
        'pack_barcode' => 'SNACK-PK-63',
        'pack_price' => 130.00,
    ], 1);
    $assert(false, 'Expected changing pack_size with sealed stock > 0 to throw');
} catch (Throwable $e) {
    $assert(str_contains($e->getMessage(), 'sealed') || str_contains($e->getMessage(), 'pack'), 'Changing pack_size blocked while sealed stock exists: ' . $e->getMessage());
}

// Attempt to remove pack configuration when sealed stock > 0
try {
    $productService->updateProductCatalogDetails($startingProductId, [
        'product_name' => 'Choco Crackers 12s',
        'pack_size' => null,
        'pack_barcode' => null,
        'pack_price' => null,
    ], 1);
    $assert(false, 'Expected removing pack configuration with sealed stock > 0 to throw');
} catch (Throwable $e) {
    $assert(true, 'Removing pack configuration blocked while sealed stock exists: ' . $e->getMessage());
}

// Updating pack_price or pack_barcode on product with sealed stock is permitted if valid
$updated = $productService->updateProductCatalogDetails($startingProductId, [
    'product_name' => 'Choco Crackers 12s Updated',
    'pack_size' => 12,
    'pack_barcode' => 'SNACK-PK-63-NEW',
    'pack_price' => 140.00, // 140 < 12 * 15
], 1);
$assert($updated['pack_barcode'] === 'SNACK-PK-63-NEW', 'Updating pack_barcode with sealed stock succeeds');
$assert((float)$updated['pack_price'] === 140.00, 'Updating pack_price with sealed stock succeeds');

// 12. Test: Product with zero sealed stock allows changing pack_size or removing configuration
echo "Testing pack configuration modification when sealed stock is zero...\n";
$zeroSealedProdId = $productService->createProduct([
    'barcode' => 'ZERO-SEAL-01',
    'product_name' => 'Zero Sealed Product',
    'unit_price' => 20.00,
    'pack_size' => 10,
    'pack_barcode' => 'ZERO-SEAL-PK',
    'pack_price' => 180.00,
    'starting_packs' => 0,
    'starting_loose' => 5, // only loose stock
], 1);

// Changing pack_size on product with 0 sealed packs succeeds
$upZero = $productService->updateProductCatalogDetails($zeroSealedProdId, [
    'product_name' => 'Zero Sealed Product',
    'pack_size' => 8,
    'pack_barcode' => 'ZERO-SEAL-PK',
    'pack_price' => 140.00,
], 1);
$assert((int)$upZero['pack_size'] === 8, 'Changing pack_size with 0 sealed packs succeeds');

// 13. Test: Never infer packs when adding pack configuration to an existing piece product with stock
echo "Testing adding pack configuration to legacy piece product preserves loose stock...\n";
$legacyProdId = $productService->createProduct([
    'barcode' => 'LEGACY-PIECE-24',
    'product_name' => 'Legacy Biscuits 24 Pieces',
    'unit_price' => 10.00,
    'initial_stock_quantity' => 24, // 24 loose pieces
], 1);

// Add pack configuration to legacy product
$upLegacy = $productService->updateProductCatalogDetails($legacyProdId, [
    'product_name' => 'Legacy Biscuits 24 Pieces',
    'pack_size' => 12,
    'pack_barcode' => 'LEGACY-PK-12',
    'pack_price' => 100.00,
], 1);

// Check batch: sealed_quantity MUST be 0, loose_quantity MUST be 24 (never infer 2 packs!)
$legBatch = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$legacyProdId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$legBatch['sealed_quantity'] === 0, 'Legacy stock was NOT inferred as sealed packs (sealed_quantity is 0)');
$assert((int)$legBatch['loose_quantity'] === 24, 'Legacy stock remains loose (loose_quantity is 24)');

// 14. Test: Inventory views expose sealed, loose, equivalent total, and expired physical quantities
echo "Testing inventory summaries and batch views with sealed, loose, and expired stock...\n";
// Create product with 1 active batch (2 packs, 1 loose, expires in future) and 1 expired batch (1 pack, 2 loose, expired)
$expProdId = $productService->createProduct([
    'barcode' => 'EXP-PROD-PC',
    'product_name' => 'Perishable Snack 6s',
    'unit_price' => 10.00,
    'pack_size' => 6,
    'pack_barcode' => 'EXP-PROD-PK',
    'pack_price' => 50.00,
    'starting_packs' => 2,
    'starting_loose' => 1,
    'expiration_date' => '2027-01-01', // Active: 2 * 6 + 1 = 13 Base Units
], 1);

// Insert an expired batch directly into product_batches for testing: 1 pack of 6 + 2 loose = 8 Base Units, expired 2025-01-01
$pdo->prepare("
    INSERT INTO product_batches (product_id, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
    VALUES (?, 8, 8, 1, 2, '2025-01-01')
")->execute([$expProdId]);
// Update inventory total: 13 + 8 = 21 Base Units
$pdo->exec("UPDATE inventory SET quantity_on_hand = 21 WHERE product_id = {$expProdId}");

$summaries = $inventoryService->getProductsWithBatchSummaries('2026-10-20');
$expSummary = null;
foreach ($summaries as $s) {
    if ((int)$s['product_id'] === $expProdId) {
        $expSummary = $s;
        break;
    }
}

$assert($expSummary !== null, 'Product found in getProductsWithBatchSummaries');
$assert((int)$expSummary['quantity_on_hand'] === 21, 'Inventory summary reports 21 equivalent Base Units total');
$assert((int)$expSummary['expired_quantity'] === 8, 'Inventory summary reports 8 expired Base Units');
$assert((int)$expSummary['physical_sealed_packs'] === 3, 'Physical sealed packs is 3 (2 active + 1 expired)');
$assert((int)$expSummary['physical_loose_pieces'] === 3, 'Physical loose pieces is 3 (1 active + 2 expired)');
$assert((int)$expSummary['saleable_sealed_packs'] === 2, 'Saleable sealed packs is 2 (excluding expired pack)');
$assert((int)$expSummary['saleable_loose_pieces'] === 1, 'Saleable loose pieces is 1 (excluding expired pieces)');

$batches = $inventoryService->getProductBatches($expProdId, '2026-10-20');
$assert(count($batches) === 2, 'getProductBatches returns 2 batches');
$hasExpiredBatch = false;
$hasActiveBatch = false;
foreach ($batches as $b) {
    if ($b['status'] === 'Expired') {
        $hasExpiredBatch = true;
        $assert((int)$b['sealed_quantity'] === 1, 'Expired batch has 1 sealed pack');
        $assert((int)$b['loose_quantity'] === 2, 'Expired batch has 2 loose pieces');
    } elseif ($b['status'] === 'Active') {
        $hasActiveBatch = true;
        $assert((int)$b['sealed_quantity'] === 2, 'Active batch has 2 sealed packs');
        $assert((int)$b['loose_quantity'] === 1, 'Active batch has 1 loose piece');
    }
}
$assert($hasExpiredBatch, 'Expired batch identified with Expired status');
$assert($hasActiveBatch, 'Active batch identified with Active status');

// 15. Test: Selling price update rejects price that violates pack_price < pack_size * selling_price
echo "Testing selling price reduction guard against existing pack price...\n";
$sellingGuardProdId = $productService->createProduct([
    'barcode' => 'SELL-GUARD-PC',
    'product_name' => 'Selling Guard Product',
    'unit_price' => 20.00,
    'pack_size' => 6,
    'pack_barcode' => 'SELL-GUARD-PK',
    'pack_price' => 100.00, // 6 * 20 = 120 > 100
], 1);

try {
    // Lower unit_price to 15: 6 * 15 = 90 <= 100
    $productService->updateProductCatalogDetails($sellingGuardProdId, [
        'product_name' => 'Selling Guard Product',
        'unit_price' => 15.00,
    ], 1);
    $failures[] = 'Lowering unit_price below pack price parity should have failed';
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'must be lower than contents count times piece price'), 'Selling price reduction violating pack price parity rejected: ' . $e->getMessage());
}

// 16. Test: pack_barcode cannot collide with an existing product SKU
echo "Testing pack_barcode collision with existing SKU...\n";
$skuProdId = $productService->createProduct([
    'sku' => 'CUSTOM-SKU-99',
    'barcode' => 'REG-BARCODE-99',
    'product_name' => 'Custom SKU Product',
    'unit_price' => 10.00,
], 1);

try {
    $productService->createProduct([
        'barcode' => 'OTHER-BARCODE-99',
        'product_name' => 'Colliding Pack Barcode Product',
        'unit_price' => 10.00,
        'pack_size' => 5,
        'pack_barcode' => 'CUSTOM-SKU-99', // Collides with existing product SKU
        'pack_price' => 45.00,
    ], 1);
    $failures[] = 'Pack barcode matching existing SKU should have been rejected';
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'already assigned'), 'Pack barcode colliding with SKU rejected: ' . $e->getMessage());
}

// 17. Test: reconcilePhysicalStock updates inventory, distributes batches, records delta movement and activity log
echo "Testing physical stock reconciliation...\n";
$reconProdId = $productService->createProduct([
    'barcode' => 'RECON-TEST-PC',
    'product_name' => 'Recon Test Biscuit',
    'unit_price' => 10.00,
    'pack_size' => 10,
    'pack_barcode' => 'RECON-TEST-PK',
    'pack_price' => 90.00,
    'starting_packs' => 3,
    'starting_loose' => 5, // 3 * 10 + 5 = 35 Base Units
], 1);

// Physical shelf count: 4 packs and 2 loose pieces = 42 Base Units (delta = +7)
$reconRes = $productService->reconcilePhysicalStock($reconProdId, 4, 2, 'Verified annual physical inventory count', 1);
$assert((int)$reconRes['quantity_on_hand'] === 42, 'Reconciliation updated quantity_on_hand to 42');
$assert((int)$reconRes['physical_sealed_packs'] === 4, 'Reconciliation reports 4 sealed packs');
$assert((int)$reconRes['physical_loose_pieces'] === 2, 'Reconciliation reports 2 loose pieces');

// Check movement recorded
$mvt = $pdo->query("SELECT * FROM stock_movements WHERE product_id = {$reconProdId} AND reason LIKE '%reconciliation%' ORDER BY movement_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert($mvt !== false, 'Stock movement recorded for reconciliation');
$assert((int)$mvt['change_qty'] === 7, 'Stock movement change_qty is +7');
$assert((int)$mvt['moved_by'] === 1, 'Stock movement moved_by is user 1');

// Check activity log
$act = $pdo->query("SELECT * FROM activity_log WHERE user_id = 1 AND action = 'Physical stock reconciliation' ORDER BY log_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert($act !== false, 'Activity log recorded for physical stock reconciliation');

// 18. Blank starting_loose on a pack product means zero, never the auto-computed total.
echo "Testing blank starting loose with starting packs...\n";
$blankLooseProdId = $productService->createProduct([
    'barcode' => 'BLANK-LOOSE-PC',
    'product_name' => 'Blank Loose Biscuit',
    'unit_price' => 10.00,
    'pack_size' => 6,
    'pack_barcode' => 'BLANK-LOOSE-PK',
    'pack_price' => 50.00,
    'starting_packs' => 2,
    'initial_stock_quantity' => 12,
], 1);
$blankLooseInv = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$blankLooseProdId}")->fetchColumn();
$blankLooseBatch = $pdo->query("SELECT sealed_quantity, loose_quantity, remaining_quantity FROM product_batches WHERE product_id = {$blankLooseProdId}")->fetch(PDO::FETCH_ASSOC);
$assert($blankLooseInv === 12, 'Blank starting loose with 2 packs of 6 gives exactly 12 Base Units, not 24');
$assert((int)$blankLooseBatch['sealed_quantity'] === 2 && (int)$blankLooseBatch['loose_quantity'] === 0 && (int)$blankLooseBatch['remaining_quantity'] === 12, 'Blank starting loose records 2 sealed packs and 0 loose');

// 19. Reconciliation must allocate the whole asserted sealed count across batches or refuse atomically.
echo "Testing multi-batch sealed reconciliation...\n";
$multiReconProdId = $productService->createProduct([
    'barcode' => 'MULTI-RECON-PC',
    'product_name' => 'Multi Batch Biscuit',
    'unit_price' => 10.00,
    'pack_size' => 12,
    'pack_barcode' => 'MULTI-RECON-PK',
    'pack_price' => 100.00,
    'starting_loose' => 6,
    'expiration_date' => '2027-01-01',
], 1);
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
    VALUES ({$multiReconProdId}, 'MULTI-B2', 6, 6, 0, 6, '2027-06-01')");
$pdo->exec("UPDATE inventory SET quantity_on_hand = 12 WHERE product_id = {$multiReconProdId}");

try {
    $productService->reconcilePhysicalStock($multiReconProdId, 1, 0, 'Verified annual physical inventory count', 1);
    $assert(false, 'Reconciliation should reject a sealed pack that cannot be allocated without fragmenting batches');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'cannot be allocated'), 'Reconciliation rejects an unallocatable sealed count: ' . $e->getMessage());
}
$multiBatches = $pdo->query("SELECT remaining_quantity, sealed_quantity, loose_quantity, expiration_date FROM product_batches WHERE product_id = {$multiReconProdId} ORDER BY batch_id")->fetchAll(PDO::FETCH_ASSOC);
$assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$multiReconProdId}")->fetchColumn() === 12, 'Rejected reconciliation leaves inventory unchanged');
$assert(
    count($multiBatches) === 2
        && (int)$multiBatches[0]['remaining_quantity'] === 6 && (int)$multiBatches[0]['sealed_quantity'] === 0 && (int)$multiBatches[0]['loose_quantity'] === 6
        && (int)$multiBatches[1]['remaining_quantity'] === 6 && (int)$multiBatches[1]['sealed_quantity'] === 0 && (int)$multiBatches[1]['loose_quantity'] === 6,
    'Rejected reconciliation leaves both batches and their forms unchanged'
);

// A valid count preserves existing sealed placement and each batch's provenance.
$multiBatchIds = $pdo->query("SELECT batch_id FROM product_batches WHERE product_id = {$multiReconProdId} ORDER BY batch_id")->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec("UPDATE product_batches SET remaining_quantity = 12, sealed_quantity = 1, loose_quantity = 0, batch_number = 'MULTI-B1' WHERE batch_id = " . (int)$multiBatchIds[0]);
$pdo->exec("UPDATE product_batches SET remaining_quantity = 12, sealed_quantity = 0, loose_quantity = 12, batch_number = 'MULTI-B2' WHERE batch_id = " . (int)$multiBatchIds[1]);
$pdo->exec("UPDATE inventory SET quantity_on_hand = 24 WHERE product_id = {$multiReconProdId}");
$productService->reconcilePhysicalStock($multiReconProdId, 2, 0, 'Verified annual physical inventory count', 1);
$allocated = $pdo->query("SELECT batch_number, remaining_quantity, sealed_quantity, loose_quantity, expiration_date FROM product_batches WHERE product_id = {$multiReconProdId} ORDER BY batch_number")->fetchAll(PDO::FETCH_ASSOC);
$assert(
    count($allocated) === 2
        && $allocated[0]['batch_number'] === 'MULTI-B1' && (int)$allocated[0]['sealed_quantity'] === 1 && (int)$allocated[0]['loose_quantity'] === 0
        && $allocated[1]['batch_number'] === 'MULTI-B2' && (int)$allocated[1]['sealed_quantity'] === 1 && (int)$allocated[1]['loose_quantity'] === 0
        && (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$multiReconProdId}")->fetchColumn() === 24,
    'Reconciliation allocates both sealed packs across batches and preserves base-unit totals and expirations'
);
$assert($allocated[0]['expiration_date'] === '2027-01-01' && $allocated[1]['expiration_date'] === '2027-06-01', 'Reconciliation keeps each batch expiration date');

// 20. Emergency stock adjustments never reinterpret sealed packs as loose pieces.
echo "Testing form-aware stock adjustments...\n";
$adjustProdId = $productService->createProduct([
    'barcode' => 'ADJUST-FORM-PC',
    'product_name' => 'Adjustable Pack Biscuit',
    'unit_price' => 10.00,
    'pack_size' => 6,
    'pack_barcode' => 'ADJUST-FORM-PK',
    'pack_price' => 50.00,
    'starting_packs' => 2,
    'starting_loose' => 3,
], 1);
$formBefore = $pdo->query("SELECT sealed_quantity, loose_quantity, remaining_quantity FROM product_batches WHERE product_id = {$adjustProdId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$formBefore['sealed_quantity'] === 2 && (int)$formBefore['loose_quantity'] === 3 && (int)$formBefore['remaining_quantity'] === 15,
    'Adjustment fixture starts at 2 sealed packs + 3 loose pieces (15 Base Units)');

$productService->adjustStock([
    'product_id' => $adjustProdId,
    'qty_change' => 5,
    'adjustment_reason' => 'Found extra loose pieces during aisle inspection',
], 1);
$adjustInv = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$adjustProdId}")->fetchColumn();
$adjustNewBatch = $pdo->query("SELECT sealed_quantity, loose_quantity, remaining_quantity FROM product_batches WHERE product_id = {$adjustProdId} AND batch_number IS NULL ORDER BY batch_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$adjustSealed = (int)$pdo->query("SELECT SUM(sealed_quantity) FROM product_batches WHERE product_id = {$adjustProdId}")->fetchColumn();
$adjustLoose = (int)$pdo->query("SELECT SUM(loose_quantity) FROM product_batches WHERE product_id = {$adjustProdId}")->fetchColumn();
$assert($adjustInv === 20 && (int)$adjustNewBatch['sealed_quantity'] === 0 && (int)$adjustNewBatch['loose_quantity'] === 5,
    'Positive adjustment adds 5 Base Units as loose pieces only (sealed 0)');
$assert($adjustSealed === 2 && $adjustLoose === 8 && $adjustSealed * 6 + $adjustLoose === $adjustInv,
    'Positive adjustment preserves sealed packs and the sealed/loose invariant (2 x 6 + 8 = 20)');

$productService->adjustStock([
    'product_id' => $adjustProdId,
    'qty_change' => -4,
    'adjustment_reason' => 'Loose pieces damaged while restocking the shelf',
], 1);
$adjustInv = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$adjustProdId}")->fetchColumn();
$adjustSealed = (int)$pdo->query("SELECT SUM(sealed_quantity) FROM product_batches WHERE product_id = {$adjustProdId}")->fetchColumn();
$adjustLoose = (int)$pdo->query("SELECT SUM(loose_quantity) FROM product_batches WHERE product_id = {$adjustProdId}")->fetchColumn();
$assert($adjustInv === 16 && $adjustSealed === 2 && $adjustLoose === 4 && $adjustSealed * 6 + $adjustLoose === $adjustInv,
    'Negative adjustment removes 4 loose pieces, leaves 2 sealed packs untouched, and keeps the invariant');

$beforeRefusal = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$adjustProdId}")->fetchColumn();
$beforeRefusalBatches = $pdo->query("SELECT batch_id, remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE product_id = {$adjustProdId} ORDER BY batch_id")->fetchAll(PDO::FETCH_ASSOC);
try {
    $productService->adjustStock([
        'product_id' => $adjustProdId,
        'qty_change' => -5,
        'adjustment_reason' => 'Attempt to consume an unopened sealed pack',
    ], 1);
    $assert(false, 'Adjustment beyond available loose stock should be refused instead of opening sealed packs');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'loose') && (str_contains($e->getMessage(), 'Stock Issue') || str_contains($e->getMessage(), 'physical count')),
        'Adjustment beyond loose stock is refused with operator guidance: ' . $e->getMessage());
}
$afterRefusalBatches = $pdo->query("SELECT batch_id, remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE product_id = {$adjustProdId} ORDER BY batch_id")->fetchAll(PDO::FETCH_ASSOC);
$assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$adjustProdId}")->fetchColumn() === (int)$beforeRefusal
    && $afterRefusalBatches === $beforeRefusalBatches,
    'Refused adjustment writes nothing to inventory or batches');

if (!empty($failures)) {
    echo "\nFailed with " . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\nAll contract and integration checks for Issue #246 PASSED!\n";
exit(0);
