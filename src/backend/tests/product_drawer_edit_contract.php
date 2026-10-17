<?php
// Contract and integration test for Ticket #212: Edit product catalog details in the Products drawer.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';

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
    // 1. Static Contract Checks on products.php
    echo "Checking products.php static contract...\n";
    $productsSource = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/inventory_management/products.php');

    $assert(str_contains($productsSource, "action === 'update_catalog_details'"), 'products.php handles update_catalog_details action');
    $assert(str_contains($productsSource, "current_role() === 'inventory_manager'") || str_contains($productsSource, "current_role() !== 'inventory_manager'"), 'products.php enforces inventory_manager workspace');
    $assert(str_contains($productsSource, 'csrf_verify()'), 'products.php verifies CSRF on catalog updates');
    $assert(str_contains($productsSource, 'id="product-edit-btn"') || str_contains($productsSource, 'data-product-edit'), 'products.php includes Edit product action');

    // 2. Database Integration & Service Contract
    echo "Checking ProductService catalog details editing...\n";
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Setup schema
    $pdo->exec("
        CREATE TABLE branches (
            branch_id INTEGER PRIMARY KEY,
            branch_name TEXT,
            branch_code TEXT,
            status TEXT DEFAULT 'active'
        );
        INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Branch', 'MAIN', 'active');
        CREATE TABLE users (
            user_id INTEGER PRIMARY KEY,
            branch_id INTEGER NOT NULL DEFAULT 1
        );
        INSERT INTO users (user_id, branch_id) VALUES (1, 1);
        CREATE TABLE categories (


            category_id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_name TEXT NOT NULL
        );
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
    ");

    $pdo->exec("INSERT INTO categories (category_id, category_name) VALUES (1, 'Beverages'), (2, 'Snacks')");
    $pdo->exec("
        INSERT INTO products (
            product_id, sku, barcode, case_barcode, parent_product_id, variant_label, product_name, brand, category_id,
            unit_price, cost_price, quantity_purchased, quantity_sold, reorder_level, safety_stock,
            minimum_order_quantity, units_per_package, base_unit, receiving_unit, expiration_date, product_image, status
        ) VALUES (
            1, 'SKU-001', 'BAR-001', 'CASE-001', NULL, NULL, 'Original Juice', 'JuiceCo', 1,
            50.0, 30.0, 100, 20, 15, 5,
            2, 12, 'can', 'case', '2027-01-01', 'storage/images/juice.png', 'active'
        ), (
            2, 'SKU-002', 'BAR-002', NULL, 1, '500ml Bottle', 'Original Juice 500ml', 'JuiceCo', 1,
            30.0, 18.0, 50, 10, 10, 2,
            1, 6, 'bottle', 'pack', '2027-01-01', NULL, 'active'
        )
    ");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 80), (2, 40)");

    $service = new ProductService($pdo);

    // Test workspace authorization: Reject when not inventory_manager
    $nonInventoryRoles = ['admin', 'super_admin', 'cashier', ''];
    foreach ($nonInventoryRoles as $role) {
        $GLOBALS['test_active_role'] = $role;
        $_SESSION['role'] = $role;
        try {
            $service->updateProductCatalogDetails(1, ['product_name' => 'Attempted By ' . $role], 1);
            $assert(false, "Service should reject edit from workspace: '{$role}'");
        } catch (Throwable $e) {
            $assert(str_contains(strtolower($e->getMessage()), 'inventory manager'), "Non-inventory_manager workspace '{$role}' rejected with actionable message");
        }
    }

    // Now set active role to inventory_manager
    $GLOBALS['test_active_role'] = 'inventory_manager';
    $_SESSION['role'] = 'inventory_manager';

    // Test successful update of editable fields on standalone product
    $result = $service->updateProductCatalogDetails(1, [
        'product_name' => 'Juice Delight 330ml',
        'brand' => 'FreshCo',
        'category_id' => 2,
        'product_image' => 'storage/images/juice_new.png',
        // Attempt to change read-only fields (MUST BE IGNORED)
        'unit_price' => 999.0,
        'cost_price' => 888.0,
        'sku' => 'HACKED-SKU',
        'barcode' => 'HACKED-BAR',
        'case_barcode' => 'HACKED-CASE',
        'quantity_on_hand' => 9999,
        'reorder_level' => 999,
        'safety_stock' => 999,
        'minimum_order_quantity' => 999,
        'units_per_package' => 999,
        'base_unit' => 'barrel',
        'receiving_unit' => 'pallet',
        'parent_product_id' => 99,
        'variant_label' => 'Should Not Change'
    ], 1);

    $assert($result['product_name'] === 'Juice Delight 330ml', 'Product name updated');
    $assert($result['brand'] === 'FreshCo', 'Brand updated');
    $assert((int)$result['category_id'] === 2, 'Category updated');
    $assert($result['product_image'] === 'storage/images/juice_new.png', 'Product image updated');

    // Verify in database that read-only fields were strictly preserved
    $stmt = $pdo->prepare('SELECT p.*, i.quantity_on_hand FROM products p JOIN inventory i ON i.product_id = p.product_id WHERE p.product_id = 1');
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $assert($row['product_name'] === 'Juice Delight 330ml', 'DB product_name updated');
    $assert($row['brand'] === 'FreshCo', 'DB brand updated');
    $assert((int)$row['category_id'] === 2, 'DB category_id updated');
    $assert($row['product_image'] === 'storage/images/juice_new.png', 'DB product_image updated');
    $assert((float)$row['unit_price'] === 50.0, 'DB unit_price preserved');
    $assert((float)$row['cost_price'] === 30.0, 'DB cost_price preserved');
    $assert($row['status'] === 'active', 'DB status preserved');
    $assert($row['sku'] === 'SKU-001', 'DB sku preserved');
    $assert($row['barcode'] === 'BAR-001', 'DB barcode preserved');
    $assert($row['case_barcode'] === 'CASE-001', 'DB case_barcode preserved');
    $assert((int)$row['quantity_on_hand'] === 80, 'DB stock quantity preserved');
    $assert((int)$row['reorder_level'] === 15, 'DB reorder_level preserved');
    $assert((int)$row['safety_stock'] === 5, 'DB safety_stock preserved');
    $assert((int)$row['minimum_order_quantity'] === 2, 'DB minimum_order_quantity preserved');
    $assert((int)$row['units_per_package'] === 12, 'DB units_per_package preserved');
    $assert($row['base_unit'] === 'can', 'DB base_unit preserved');
    $assert($row['receiving_unit'] === 'case', 'DB receiving_unit preserved');
    $assert($row['parent_product_id'] === null, 'DB parent_product_id preserved (not changed)');
    $assert($row['variant_label'] === null, 'DB variant_label preserved (non-variant remained null)');

    // Test variant product label editing (product_id = 2 has parent_product_id = 1)
    $variantResult = $service->updateProductCatalogDetails(2, [
        'product_name' => 'Original Juice 500ml Pet',
        'variant_label' => '500ml PET Bottle',
        'brand' => 'JuiceCo',
        'category_id' => 1
    ], 1);
    $assert($variantResult['variant_label'] === '500ml PET Bottle', 'Variant label updated on variant product');
    $assert($variantResult['product_name'] === 'Original Juice 500ml Pet', 'Variant product name updated');

    // Test variant label exceeding 100 characters rejected
    try {
        $longLabel = str_repeat('A', 101);
        $service->updateProductCatalogDetails(2, [
            'product_name' => 'Original Juice 500ml Pet',
            'variant_label' => $longLabel
        ], 1);
        $assert(false, 'Variant label over 100 chars should be rejected');
    } catch (Throwable $e) {
        $assert(str_contains(strtolower($e->getMessage()), '100'), 'Variant label over 100 chars rejected');
    }

    // Test empty product name rejected
    try {
        $service->updateProductCatalogDetails(1, [
            'product_name' => '   '
        ], 1);
        $assert(false, 'Empty product name should be rejected');
    } catch (Throwable $e) {
        $assert(str_contains(strtolower($e->getMessage()), 'required'), 'Empty product name rejected');
    }

    // Test invalid category_id rejected
    try {
        $service->updateProductCatalogDetails(1, [
            'product_name' => 'Juice Delight 330ml',
            'category_id' => 99999
        ], 1);
        $assert(false, 'Non-existent category_id should be rejected');
    } catch (Throwable $e) {
        $assert(str_contains(strtolower($e->getMessage()), 'category'), 'Invalid category rejected');
    }

    // Test activity log recorded
    $logCount = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Products' AND record_id = 1")->fetchColumn();
    $assert($logCount > 0, 'Activity log entry created for product catalog detail edit');

} catch (Throwable $e) {
    $failures[] = 'Contract exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}

if (!empty($failures)) {
    fwrite(STDERR, "\nProduct drawer edit contract FAILED (" . count($failures) . " errors):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nProduct drawer edit contract: ALL PASSED\n";
