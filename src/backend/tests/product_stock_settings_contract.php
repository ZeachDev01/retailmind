<?php
// Contract and integration test for Ticket #215: Edit product packaging and stock planning settings safely.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';
require_once __DIR__ . '/../app/Forecasting/ReplenishmentQueueService.php';

use App\Forecasting\ReplenishmentQueueService;

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
    // 1. Static Contract & Accessibility Checks on products.php
    echo "Checking products.php static contract and accessibility for stock planning settings...\n";
    $productsSource = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/inventory_management/products.php');

    // Field names and IDs present
    $assert(str_contains($productsSource, 'name="units_per_package"'), 'products.php includes units_per_package input');
    $assert(str_contains($productsSource, 'id="edit-units-per-package"'), 'products.php provides edit-units-per-package ID');
    $assert(str_contains($productsSource, 'for="edit-units-per-package"'), 'products.php provides accessible label association with for="edit-units-per-package"');

    $assert(str_contains($productsSource, 'name="minimum_order_quantity"'), 'products.php includes minimum_order_quantity input');
    $assert(str_contains($productsSource, 'id="edit-moq"'), 'products.php provides edit-moq ID');
    $assert(str_contains($productsSource, 'for="edit-moq"'), 'products.php provides accessible label association with for="edit-moq"');

    $assert(str_contains($productsSource, 'name="reorder_level"'), 'products.php includes reorder_level input');
    $assert(str_contains($productsSource, 'id="edit-reorder-level"'), 'products.php provides edit-reorder-level ID');
    $assert(str_contains($productsSource, 'for="edit-reorder-level"'), 'products.php provides accessible label association with for="edit-reorder-level"');

    $assert(str_contains($productsSource, 'name="safety_stock"'), 'products.php includes safety_stock input');
    $assert(str_contains($productsSource, 'id="edit-safety-stock"'), 'products.php provides edit-safety-stock ID');
    $assert(str_contains($productsSource, 'for="edit-safety-stock"'), 'products.php provides accessible label association with for="edit-safety-stock"');

    // Clear Base Unit / Receiving Unit context labels
    $assert(str_contains($productsSource, 'product.base_unit') && str_contains($productsSource, 'product.receiving_unit'), 'products.php displays clear Base Unit / Receiving Unit labels in edit form');

    // POST handler passes the 4 fields
    $assert(str_contains($productsSource, '$updateData[\'units_per_package\'] = $_POST[\'units_per_package\']'), 'products.php passes units_per_package to updateData');
    $assert(str_contains($productsSource, '$updateData[\'minimum_order_quantity\'] = $_POST[\'minimum_order_quantity\']'), 'products.php passes minimum_order_quantity to updateData');
    $assert(str_contains($productsSource, '$updateData[\'reorder_level\'] = $_POST[\'reorder_level\']'), 'products.php passes reorder_level to updateData');
    $assert(str_contains($productsSource, '$updateData[\'safety_stock\'] = $_POST[\'safety_stock\']'), 'products.php passes safety_stock to updateData');

    // Client-side validations
    $assert(str_contains($productsSource, 'Units per package must be a whole number of at least 1.'), 'products.php includes client-side units_per_package validation');
    $assert(str_contains($productsSource, 'Minimum order quantity must be a whole number of at least 1.'), 'products.php includes client-side minimum_order_quantity validation');
    $assert(str_contains($productsSource, 'Reorder level must be a whole number of 0 or more.'), 'products.php includes client-side reorder_level validation');
    $assert(str_contains($productsSource, 'Safety stock must be a whole number of 0 or more.'), 'products.php includes client-side safety_stock validation');

    // DOM updates on save
    $assert(str_contains($productsSource, 'renderProductDrawerQuantity'), 'products.php provides renderProductDrawerQuantity helper');
    $assert(str_contains($productsSource, 'stockCell.innerHTML = `<span class="stock-value">${Number(product.quantity_on_hand || 0).toLocaleString()}</span><br><small>Reorder at ${Number(product.reorder_level || 0).toLocaleString()}</small>`'), 'products.php updates catalog table stockCell with reorder level on save');

    // Accessibility & responsiveness
    $assert(str_contains($productsSource, 'grid-template-columns:repeat(auto-fit, minmax(180px, 1fr))'), 'Stock setting inputs use responsive grid for narrow screens');
    $assert(!str_contains($productsSource, 'tabindex="-1" id="edit-units-per-package"'), 'Units per package input remains in natural keyboard tab order');
    $assert(!str_contains($productsSource, 'tabindex="-1" id="edit-moq"'), 'MOQ input remains in natural keyboard tab order');
    $assert(!str_contains($productsSource, 'tabindex="-1" id="edit-reorder-level"'), 'Reorder level input remains in natural keyboard tab order');
    $assert(!str_contains($productsSource, 'tabindex="-1" id="edit-safety-stock"'), 'Safety stock input remains in natural keyboard tab order');

    // 2. Database Integration & Service Contract Setup
    echo "Setting up SQLite test database...\n";
    class SqliteTestPdo extends PDO {
        #[\ReturnTypeWillChange]
        public function prepare(string $query, array $options = []): PDOStatement|false {
            return parent::prepare(preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query), $options);
        }
        #[\ReturnTypeWillChange]
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
            return parent::query(preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query), ...$fetchModeArgs);
        }
    }
    $pdo = new SqliteTestPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Register MySQL compatibility functions for SQLite
    $pdo->sqliteCreateFunction('GET_LOCK', static fn(): int => 1, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', static fn(): int => 1, 1);
    $pdo->sqliteCreateFunction('SHA2', static fn(string $val, int $len): string => hash('sha256', $val), 2);
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'test', 0);
    $pdo->sqliteCreateFunction('CONCAT', static fn(...$args): string => implode('', $args));
    $pdo->sqliteCreateFunction('LEFT', static fn(string $str, int $len): string => substr($str, 0, $len), 2);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
    $pdo->sqliteCreateFunction(
        'DATE_ADD',
        static fn(string $date, string $interval, int $days): string => date('Y-m-d', strtotime($date . ' +' . $days . ' days')),
        3
    );

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
        INSERT INTO users (user_id, full_name, username, role_id, branch_id) VALUES (2, 'Admin User', 'admin', 2, 1);

        CREATE TABLE categories (
            category_id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_name TEXT NOT NULL
        );
        INSERT INTO categories (category_id, category_name) VALUES (1, 'Beverages');

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
            date_received TEXT,
            supplier TEXT
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

        CREATE TABLE forecast_jobs (
            job_id INTEGER PRIMARY KEY AUTOINCREMENT,
            status TEXT DEFAULT 'pending',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE demand_forecasts (
            forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            recommended_quantity INTEGER NOT NULL DEFAULT 0
        );
    ");

    // Seed product: 120 cans on hand, 12 per case, MOQ 24, Reorder 30, Safety 10
    $pdo->exec("
        INSERT INTO products (
            product_id, sku, barcode, case_barcode, product_name, brand, category_id,
            unit_price, cost_price, quantity_purchased, quantity_sold,
            reorder_level, safety_stock, minimum_order_quantity, units_per_package,
            base_unit, receiving_unit, status
        ) VALUES (
            1, 'CAN-001', 'BAR-001', 'CASE-001', 'Sparkling Soda 330ml', 'SodaCo', 1,
            25.0, 15.0, 120, 0,
            30, 10, 24, 12,
            'can', 'case', 'active'
        )
    ");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 120)");

    // Seed historical receiving record with units_per_package_used = 12
    $pdo->exec("
        INSERT INTO stock_receiving (
            receiving_id, group_id, product_id, received_qty, received_packages,
            units_per_package_used, accepted_qty, damaged_qty, received_by
        ) VALUES (
            1, 1, 1, 12, 1, 12, 12, 0, 1
        )
    ");

    $productService = new ProductService($pdo);

    // 3. Test Workspace Enforcement
    echo "Testing workspace enforcement...\n";
    $nonInventoryRoles = ['admin', 'super_admin', 'cashier', ''];
    foreach ($nonInventoryRoles as $role) {
        $GLOBALS['test_active_role'] = $role;
        $_SESSION['role'] = $role;
        try {
            $productService->updateProductCatalogDetails(1, [
                'product_name' => 'Sparkling Soda 330ml',
                'units_per_package' => 24
            ], 1);
            $assert(false, "Should reject stock settings edit from workspace: '{$role}'");
        } catch (Throwable $e) {
            $assert(str_contains(strtolower($e->getMessage()), 'inventory manager'), "Unauthorized workspace '{$role}' rejected with actionable error");
        }
    }

    // Set active workspace to inventory_manager
    $GLOBALS['test_active_role'] = 'inventory_manager';
    $_SESSION['role'] = 'inventory_manager';

    // 4. Test Whole-Number Boundaries & Validation
    echo "Testing strict whole-number boundaries & validation...\n";

    // Test units_per_package validation (positive integer >= 1)
    $invalidUnitsPerPackage = [
        [0, 'Zero rejected'],
        ['0', 'Zero string rejected'],
        [-1, 'Negative rejected'],
        ['-5', 'Negative string rejected'],
        [12.5, 'Float fraction rejected'],
        ['12.5', 'Fraction string rejected'],
        ['0.5', 'Decimal string rejected'],
        ['abc', 'Non-numeric string rejected'],
        ['', 'Empty string rejected'],
        ['2147483648', 'Out of range (> 2147483647) rejected'],
        [2147483648, 'Out of range int rejected']
    ];
    foreach ($invalidUnitsPerPackage as [$val, $desc]) {
        try {
            $productService->updateProductCatalogDetails(1, [
                'product_name' => 'Sparkling Soda 330ml',
                'units_per_package' => $val
            ], 1);
            $assert(false, "units_per_package: {$desc} ({$val}) should be rejected");
        } catch (Throwable $e) {
            $assert(true, "units_per_package: {$desc} rejected with actionable error ({$e->getMessage()})");
        }
    }

    // Test minimum_order_quantity validation (positive integer >= 1)
    $invalidMoq = [
        [0, 'Zero rejected'],
        ['0', 'Zero string rejected'],
        [-2, 'Negative rejected'],
        ['-10', 'Negative string rejected'],
        [24.7, 'Float fraction rejected'],
        ['24.7', 'Fraction string rejected'],
        ['xyz', 'Non-numeric string rejected'],
        ['', 'Empty string rejected'],
        ['2147483648', 'Out of range rejected']
    ];
    foreach ($invalidMoq as [$val, $desc]) {
        try {
            $productService->updateProductCatalogDetails(1, [
                'product_name' => 'Sparkling Soda 330ml',
                'minimum_order_quantity' => $val
            ], 1);
            $assert(false, "minimum_order_quantity: {$desc} ({$val}) should be rejected");
        } catch (Throwable $e) {
            $assert(true, "minimum_order_quantity: {$desc} rejected with actionable error ({$e->getMessage()})");
        }
    }

    // Test reorder_level validation (integer >= 0)
    $invalidReorder = [
        [-1, 'Negative rejected'],
        ['-10', 'Negative string rejected'],
        [15.5, 'Float fraction rejected'],
        ['15.5', 'Fraction string rejected'],
        ['invalid', 'Non-numeric string rejected'],
        ['', 'Empty string rejected'],
        ['2147483648', 'Out of range rejected']
    ];
    foreach ($invalidReorder as [$val, $desc]) {
        try {
            $productService->updateProductCatalogDetails(1, [
                'product_name' => 'Sparkling Soda 330ml',
                'reorder_level' => $val
            ], 1);
            $assert(false, "reorder_level: {$desc} ({$val}) should be rejected");
        } catch (Throwable $e) {
            $assert(true, "reorder_level: {$desc} rejected with actionable error ({$e->getMessage()})");
        }
    }

    // Test safety_stock validation (integer >= 0)
    $invalidSafety = [
        [-1, 'Negative rejected'],
        ['-5', 'Negative string rejected'],
        [5.2, 'Float fraction rejected'],
        ['5.2', 'Fraction string rejected'],
        ['abc', 'Non-numeric string rejected'],
        ['', 'Empty string rejected'],
        ['2147483648', 'Out of range rejected']
    ];
    foreach ($invalidSafety as [$val, $desc]) {
        try {
            $productService->updateProductCatalogDetails(1, [
                'product_name' => 'Sparkling Soda 330ml',
                'safety_stock' => $val
            ], 1);
            $assert(false, "safety_stock: {$desc} ({$val}) should be rejected");
        } catch (Throwable $e) {
            $assert(true, "safety_stock: {$desc} rejected with actionable error ({$e->getMessage()})");
        }
    }

    // Test accepting 0 for reorder_level and safety_stock
    $zeroSettings = $productService->updateProductCatalogDetails(1, [
        'product_name' => 'Sparkling Soda 330ml',
        'reorder_level' => '0',
        'safety_stock' => 0
    ], 1);
    $assert((int)$zeroSettings['reorder_level'] === 0, 'Reorder level accepts 0');
    $assert((int)$zeroSettings['safety_stock'] === 0, 'Safety stock accepts 0');

    // 5. Successful Update of All 4 Stock Settings & Preservation Checks
    echo "Testing successful update and strict preservation...\n";
    $updatedResult = $productService->updateProductCatalogDetails(1, [
        'product_name' => 'Sparkling Soda 330ml',
        'units_per_package' => '24',
        'minimum_order_quantity' => 48,
        'reorder_level' => 50,
        'safety_stock' => 20,
        // Attempt to modify read-only / disallowed fields:
        'base_unit' => 'barrel',
        'receiving_unit' => 'pallet',
        'sku' => 'MODIFIED-SKU',
        'barcode' => 'MODIFIED-BAR',
        'case_barcode' => 'MODIFIED-CASE',
        'cost_price' => 999.00,
        'quantity_on_hand' => 9999
    ], 1);

    $assert((int)$updatedResult['units_per_package'] === 24, 'Returned payload units_per_package is 24');
    $assert((int)$updatedResult['minimum_order_quantity'] === 48, 'Returned payload minimum_order_quantity is 48');
    $assert((int)$updatedResult['reorder_level'] === 50, 'Returned payload reorder_level is 50');
    $assert((int)$updatedResult['safety_stock'] === 20, 'Returned payload safety_stock is 20');

    // Check database row
    $dbProduct = $pdo->query('SELECT p.*, i.quantity_on_hand FROM products p JOIN inventory i ON i.product_id = p.product_id WHERE p.product_id = 1')->fetch(PDO::FETCH_ASSOC);

    $assert((int)$dbProduct['units_per_package'] === 24, 'DB units_per_package updated to 24');
    $assert((int)$dbProduct['minimum_order_quantity'] === 48, 'DB minimum_order_quantity updated to 48');
    $assert((int)$dbProduct['reorder_level'] === 50, 'DB reorder_level updated to 50');
    $assert((int)$dbProduct['safety_stock'] === 20, 'DB safety_stock updated to 20');

    // Strict preservation assertions:
    $assert((int)$dbProduct['quantity_on_hand'] === 120, 'Current stock (quantity_on_hand) strictly preserved at 120 cans');
    $assert($dbProduct['base_unit'] === 'can', 'Base unit strictly preserved as "can"');
    $assert($dbProduct['receiving_unit'] === 'case', 'Receiving unit strictly preserved as "case"');
    $assert($dbProduct['sku'] === 'CAN-001', 'SKU strictly preserved');
    $assert($dbProduct['barcode'] === 'BAR-001', 'Barcode strictly preserved');
    $assert($dbProduct['case_barcode'] === 'CASE-001', 'Case barcode strictly preserved');
    $assert((float)$dbProduct['cost_price'] === 15.0, 'Cost price strictly preserved');
    $assert((float)$dbProduct['unit_price'] === 25.0, 'Selling price strictly preserved');

    // 6. Preservation of Past Receiving History Records
    echo "Testing preservation of past receiving history snapshots...\n";
    $historicalReceiving = $pdo->query('SELECT * FROM stock_receiving WHERE receiving_id = 1')->fetch(PDO::FETCH_ASSOC);
    $assert((int)$historicalReceiving['units_per_package_used'] === 12, 'Historical stock_receiving units_per_package_used snapshot preserved at 12');
    $assert((int)$historicalReceiving['received_qty'] === 12, 'Historical stock_receiving received_qty preserved at 12');
    $assert((int)$historicalReceiving['accepted_qty'] === 12, 'Historical stock_receiving accepted_qty preserved at 12');

    // 7. Future Receiving Conversion Uses Updated Pack Size
    echo "Testing future receiving conversion with updated units_per_package...\n";
    $receivingService = new ReceivingService($pdo);
    $receivingResult = $receivingService->receiveShoppingTrip([
        [
            'product_id' => 1,
            'quantity_mode' => 'packages',
            'received_packages' => 2,
            'damaged_qty' => 0,
            'cost_price' => '15.00'
        ]
    ], ['supplier' => 'Beverage Wholesaler'], 1);

    // 2 cases * 24 cans/case = 48 cans accepted
    $assert((int)$receivingResult[0]['accepted_qty'] === 48, 'Future receiving converted 2 packages * 24 units/package = 48 base units');
    $assert((int)$receivingResult[0]['units_per_package_used'] === 24, 'Future receiving recorded units_per_package_used snapshot as 24');

    // Verify inventory incremented in Base Units: 120 + 48 = 168
    $newStock = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn();
    $assert($newStock === 168, 'Current stock incremented by 48 base units to 168 cans');

    // 8. No Background Forecast Generation or ML Retraining Triggered
    echo "Testing that no background forecasting or ML retraining occurred...\n";
    $jobCount = (int)$pdo->query('SELECT COUNT(*) FROM forecast_jobs')->fetchColumn();
    $assert($jobCount === 0, 'No forecast jobs created by stock setting edits');

    $forecastCount = (int)$pdo->query('SELECT COUNT(*) FROM demand_forecasts')->fetchColumn();
    $assert($forecastCount === 0, 'No demand forecasts generated by stock setting edits');

    // 9. Replenishment Recommendations Use Authoritative Updated Settings
    echo "Testing replenishment queue calculation with updated settings...\n";
    // ReplenishmentQueueService::roundQuantity($quantity, $moq, $packageSize)
    $roundedQty = ReplenishmentQueueService::roundQuantity(10, 48, 24);
    // Quantity 10 is below MOQ 48, so max(10, 48) = 48. 48 / 24 = 2 packages * 24 = 48.
    $assert($roundedQty === 48, 'Replenishment rounding satisfies new MOQ of 48 and pack size of 24');

    $roundedQty2 = ReplenishmentQueueService::roundQuantity(50, 48, 24);
    // Quantity 50 > MOQ 48, ceil(50 / 24) = 3 packages * 24 = 72.
    $assert($roundedQty2 === 72, 'Replenishment rounding rounds 50 up to package multiple 72');

    // 10. Activity Log Record Created
    $logCount = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Products' AND record_id = 1")->fetchColumn();
    $assert($logCount > 0, 'Activity log entry created for product stock settings edit');

} catch (Throwable $e) {
    $prev = $e->getPrevious();
    $failures[] = 'Contract exception: ' . $e->getMessage() . ($prev ? ' (Previous: ' . $prev->getMessage() . ')' : '') . ' at ' . $e->getFile() . ':' . $e->getLine();
}

if (!empty($failures)) {
    fwrite(STDERR, "\nProduct stock settings contract FAILED (" . count($failures) . " errors):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nProduct stock settings contract: ALL PASSED\n";
