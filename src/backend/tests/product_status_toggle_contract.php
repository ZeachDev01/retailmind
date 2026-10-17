<?php
// Contract and integration test for Ticket #214: Deactivate and reactivate products from the Products drawer.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/HeldSaleService.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';

use App\Services\CashierShiftService;
use App\Services\HeldSaleService;

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
    echo "Checking products.php static contract and accessibility for status toggling...\n";
    $productsSource = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/inventory_management/products.php');

    $assert(str_contains($productsSource, 'name="status"'), 'products.php includes status field');
    $assert(str_contains($productsSource, 'id="edit-status"'), 'products.php provides edit-status ID');
    $assert(str_contains($productsSource, 'for="edit-status"'), 'products.php provides accessible label association with for="edit-status"');
    $assert(str_contains($productsSource, 'value="active"') && str_contains($productsSource, 'value="inactive"'), 'products.php provides Active and Inactive options');
    $assert(str_contains($productsSource, '$updateData[\'status\'] = $_POST[\'status\']'), 'products.php passes status to updateData');
    $assert(str_contains($productsSource, 'row.dataset.status = product.status'), 'products.php updates row.dataset.status on catalog save');
    $assert(str_contains($productsSource, "badgeClass = 'inactive'") || str_contains($productsSource, 'badgeClass = "inactive"'), 'products.php updates .status-badge with inactive class');
    $assert(str_contains($productsSource, 'badgeText = \'Inactive\'') || str_contains($productsSource, 'badgeText = "Inactive"'), 'products.php updates badge text to Inactive');
    $assert(str_contains($productsSource, "Status must be strictly 'active' or 'inactive'"), 'products.php includes client-side status validation');
    $assert(str_contains($productsSource, 'isDrawerDirty = true'), 'products.php tracks unsaved changes on input/change');
    $assert(str_contains($productsSource, 'cancelProductEdit'), 'products.php provides cancelProductEdit flow');
    $assert(str_contains($productsSource, 'product-edit-error'), 'products.php displays actionable error on failed save recovery');

    // Accessibility & responsiveness checks
    $assert(str_contains($productsSource, 'width:100%'), 'Status select styled for narrow-screen editing');
    $assert(!str_contains($productsSource, 'tabindex="-1" id="edit-status"'), 'Status select remains in standard keyboard navigation tab order');

    // 2. Database Integration & Service Contract Setup
    echo "Setting up SQLite test database...\n";
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction(
        'DATE_ADD',
        static function (string $date, string $interval, int $days): string {
            return date('Y-m-d', strtotime($date . ' +' . $days . ' days'));
        },
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
        INSERT INTO users (user_id, full_name, username, role_id, branch_id) VALUES (2, 'Cashier One', 'cashier1', 1, 1);

        CREATE TABLE registers (
            register_id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active'
        );
        INSERT INTO registers (register_id, name) VALUES (1, 'Register 1');

        CREATE TABLE cashier_shifts (
            shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER NOT NULL,
            register_id INTEGER NULL,
            opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
            opening_cash REAL NOT NULL DEFAULT 0.00,
            status TEXT NOT NULL DEFAULT 'open',
            closed_at TEXT NULL,
            expected_cash REAL NULL,
            actual_cash REAL NULL,
            cash_variance REAL NULL,
            variance_threshold REAL NULL,
            variance_review_required INTEGER NOT NULL DEFAULT 0,
            payment_totals TEXT NULL,
            closing_notes TEXT NULL,
            closed_by INTEGER NULL,
            intervention_reason TEXT NULL,
            reviewed_by INTEGER NULL,
            reviewed_at TEXT NULL,
            locked_at TEXT NULL
        );
        INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash, status) VALUES (1, 2, 1, 100.00, 'open');

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

        CREATE TABLE product_batches (
            batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            batch_number TEXT NULL,
            quantity INTEGER NOT NULL DEFAULT 0,
            remaining_quantity INTEGER NOT NULL DEFAULT 0,
            expiration_date TEXT NULL,
            date_received TEXT NULL,
            supplier TEXT NULL
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

        CREATE TABLE sales (
            sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER NOT NULL,
            shift_id INTEGER NULL,
            total_amount REAL NOT NULL,
            payment_method TEXT DEFAULT 'cash',
            sale_date TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE sale_items (
            sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price REAL NOT NULL,
            subtotal REAL NOT NULL
        );

        CREATE TABLE sale_item_batches (
            sale_item_id INTEGER NOT NULL,
            batch_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL
        );

        CREATE TABLE sale_receipt_details (
            sale_id INTEGER PRIMARY KEY,
            details_json TEXT NOT NULL
        );

        CREATE TABLE held_sales (
            held_sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
            shift_id INTEGER,
            cashier_id INTEGER,
            reference_no TEXT,
            customer_label TEXT NULL,
            cart_json TEXT,
            item_count INTEGER,
            total_amount REAL,
            status TEXT DEFAULT 'held',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NULL,
            resolved_at TEXT NULL,
            sale_id INTEGER NULL,
            discard_reason TEXT NULL,
            discard_note TEXT NULL,
            discarded_by INTEGER NULL
        );

        CREATE TABLE promotions (
            promotion_id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            status TEXT DEFAULT 'active',
            starts_at TEXT,
            ends_at TEXT
        );

        CREATE TABLE cash_drawer_movements (
            drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            shift_id INTEGER NOT NULL,
            movement_type TEXT NOT NULL,
            amount REAL NOT NULL,
            reason TEXT NULL,
            recorded_by INTEGER NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            change_qty INTEGER NOT NULL,
            reason TEXT NOT NULL,
            moved_by INTEGER NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE fiscal_periods (
            period_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL
        );

        CREATE TABLE fiscal_period_locks (
            lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_id INTEGER NOT NULL,
            table_name TEXT NULL
        );
    ");

    // Insert active test product
    $pdo->exec("
        INSERT INTO products (
            product_id, sku, barcode, case_barcode, parent_product_id, variant_label, product_name, brand, category_id,
            unit_price, cost_price, quantity_purchased, quantity_sold, reorder_level, safety_stock,
            minimum_order_quantity, units_per_package, base_unit, receiving_unit, expiration_date, product_image, status
        ) VALUES (
            1, 'SKU-WATER-01', 'BAR-WATER-01', 'CASE-WATER-01', NULL, NULL, 'Spring Water 500ml', 'AquaPure', 1,
            25.00, 15.00, 100, 10, 20, 5,
            1, 24, 'bottle', 'case', '2027-12-31', 'storage/images/water.png', 'active'
        );
        INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 90);
        INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, date_received)
        VALUES (1, 1, 'BATCH-001', 90, 90, '2026-10-01 08:00:00');
    ");

    // Insert historical completed sale and receipt details for product 1
    $pdo->exec("
        INSERT INTO sales (sale_id, cashier_id, shift_id, total_amount, payment_method, created_at)
        VALUES (10, 2, 1, 50.00, 'cash', '2026-10-10 14:00:00');
        INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
        VALUES (101, 10, 1, 2, 25.00, 50.00);
        INSERT INTO sale_receipt_details (sale_id, details_json)
        VALUES (10, '{\"sale_id\":10,\"items\":[{\"product_id\":1,\"unit_price\":25.00,\"qty\":2,\"subtotal\":50.00}],\"total\":50.00}');
    ");

    $productService = new ProductService($pdo);
    $shiftsService = new CashierShiftService($pdo);
    $heldService = new HeldSaleService($pdo, $shiftsService);
    $salesService = new SalesWorkflowService($pdo);

    // 3. Active Inventory Manager Workspace Enforcement
    echo "Testing workspace enforcement...\n";
    $unauthorizedRoles = ['admin', 'super_admin', 'cashier', ''];
    foreach ($unauthorizedRoles as $role) {
        $GLOBALS['test_active_role'] = $role;
        $_SESSION['role'] = $role;
        try {
            $productService->updateProductCatalogDetails(1, ['product_name' => 'Spring Water', 'status' => 'inactive'], 1);
            $assert(false, "Service should reject status update from unauthorized workspace: '{$role}'");
        } catch (Throwable $e) {
            $assert(str_contains(strtolower($e->getMessage()), 'inventory manager'), "Unauthorized workspace '{$role}' rejected with actionable error");
        }
    }

    // Set active workspace to inventory_manager
    $GLOBALS['test_active_role'] = 'inventory_manager';
    $_SESSION['role'] = 'inventory_manager';

    // 4. Server Validation: Strictly 'active' or 'inactive'
    echo "Testing status validation (strictly 'active' or 'inactive')...\n";
    $invalidStatuses = ['archived', 'deleted', 'foobar', 'ACTIVE', 'INACTIVE', '1', '0', '', null, 'disabled'];
    foreach ($invalidStatuses as $invalid) {
        try {
            $productService->updateProductCatalogDetails(1, ['product_name' => 'Spring Water', 'status' => $invalid], 1);
            $assert(false, "Invalid status '" . var_export($invalid, true) . "' should be rejected");
        } catch (Throwable $e) {
            $assert(str_contains(strtolower($e->getMessage()), "status must be strictly 'active' or 'inactive'"), "Invalid status '" . var_export($invalid, true) . "' rejected with actionable message");
        }
    }

    // 5. Deactivation Persistence & Attribute Preservation
    echo "Testing deactivation persistence and attribute preservation...\n";
    $deactivateResult = $productService->updateProductCatalogDetails(1, [
        'product_name' => 'Spring Water 500ml',
        'status' => 'inactive'
    ], 1);

    $assert($deactivateResult['status'] === 'inactive', 'Returned product status is inactive');
    $dbStatus = $pdo->query('SELECT status FROM products WHERE product_id = 1')->fetchColumn();
    $assert($dbStatus === 'inactive', 'DB products.status persisted as inactive');

    // Verify all other attributes and inventory are preserved
    $productRow = $pdo->query('SELECT p.*, i.quantity_on_hand FROM products p JOIN inventory i ON i.product_id = p.product_id WHERE p.product_id = 1')->fetch(PDO::FETCH_ASSOC);
    $assert($productRow['sku'] === 'SKU-WATER-01', 'SKU preserved');
    $assert($productRow['barcode'] === 'BAR-WATER-01', 'Barcode preserved');
    $assert($productRow['case_barcode'] === 'CASE-WATER-01', 'Case barcode preserved');
    $assert((float)$productRow['unit_price'] === 25.00, 'Selling price preserved');
    $assert((float)$productRow['cost_price'] === 15.00, 'Cost price preserved');
    $assert((int)$productRow['quantity_on_hand'] === 90, 'Stock on hand preserved (90 units)');
    $assert((int)$productRow['reorder_level'] === 20, 'Reorder level preserved');
    $assert((int)$productRow['safety_stock'] === 5, 'Safety stock preserved');
    $assert((int)$productRow['minimum_order_quantity'] === 1, 'MOQ preserved');
    $assert((int)$productRow['units_per_package'] === 24, 'Units per package preserved');
    $assert($productRow['base_unit'] === 'bottle', 'Base unit preserved');
    $assert($productRow['receiving_unit'] === 'case', 'Receiving unit preserved');

    // Completed sales and receipt history strictly preserved
    $histSale = $pdo->query('SELECT * FROM sales WHERE sale_id = 10')->fetch(PDO::FETCH_ASSOC);
    $assert((float)$histSale['total_amount'] === 50.00, 'Historical sale total preserved');
    $histReceipt = $pdo->query('SELECT details_json FROM sale_receipt_details WHERE sale_id = 10')->fetchColumn();
    $assert(str_contains($histReceipt, '"unit_price":25'), 'Historical receipt details preserved');

    // 6. Inactive Product Rejected During Checkout Attempt
    echo "Testing inactive product checkout refusal...\n";
    $checkoutCart = [['product_id' => 1, 'qty' => 2]];
    $checkoutDetails = ['cash_received' => 100.00];

    try {
        $salesService->checkout($checkoutCart, 2, 'cashier', 'cash', $checkoutDetails);
        $assert(false, 'Checkout of inactive product should be rejected');
    } catch (Throwable $e) {
        $assert(get_class($e) === 'StockConflictException', 'StockConflictException thrown for deactivated product');
        if ($e instanceof StockConflictException) {
            $conflicts = $e->getConflicts();
            $assert(!empty($conflicts) && (int)$conflicts[0]['product_id'] === 1 && (int)$conflicts[0]['available_stock'] === 0, 'Conflict correctly reports inactive product unavailable (0 stock available)');
        }
    }

    // 7. Inactive Product in Held Sale Resumption Review
    echo "Testing held sale resumption review for inactive product...\n";
    $heldCart = [
        1 => [
            'name' => 'Spring Water 500ml',
            'qty' => 3,
            'price' => 25.00,
        ]
    ];
    $review = $heldService->reviewCart($heldCart);
    $assert(!isset($review['cart'][1]), 'Inactive product excluded from proposed resumed cart');
    $assert(!empty($review['changes']), 'Held sale review reported change for inactive product');
    $changeStr = implode(' ', $review['changes']);
    $assert(str_contains($changeStr, 'Spring Water 500ml: unavailable; proposed removal of 3 unit(s).'), 'Review explicitly flags product as unavailable and proposes removal');

    // 8. Reactivation Restores Sales Eligibility
    echo "Testing reactivation and normal checkout restoration...\n";
    $reactivateResult = $productService->updateProductCatalogDetails(1, [
        'product_name' => 'Spring Water 500ml',
        'status' => 'active'
    ], 1);

    $assert($reactivateResult['status'] === 'active', 'Returned product status is active');
    $dbStatusActive = $pdo->query('SELECT status FROM products WHERE product_id = 1')->fetchColumn();
    $assert($dbStatusActive === 'active', 'DB products.status persisted as active');

    // Held sale review now allows the reactivated product
    $reviewActive = $heldService->reviewCart($heldCart);
    $assert(isset($reviewActive['cart'][1]), 'Reactivated product included in proposed resumed cart');
    $assert((int)$reviewActive['cart'][1]['qty'] === 3, 'Proposed cart retains full requested quantity');

    // Checkout succeeds now that product is active
    $saleResult = $salesService->checkout($checkoutCart, 2, 'cashier', 'cash', $checkoutDetails);
    $assert(isset($saleResult['sale_id']) && (int)$saleResult['sale_id'] > 0, 'Checkout succeeds for reactivated product');
    $assert((float)$saleResult['total'] === 50.00, 'Sale total matches price * qty (25.00 * 2 = 50.00)');

    // Stock decremented after sale
    $newStock = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn();
    $assert($newStock === 88, 'Inventory decremented from 90 to 88 units upon successful checkout');

    // 9. Activity Log Tracking
    echo "Testing activity log entries for deactivation and reactivation...\n";
    $logs = $pdo->query("SELECT * FROM activity_log WHERE module = 'Products' AND record_id = 1 ORDER BY log_id DESC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($logs) >= 2, 'Activity log records product modifications');
    $latestLog = $logs[0];
    $prevLog = $logs[1];
    $assert(str_contains($latestLog['action'], 'Product catalog details updated'), 'Log action describes catalog details update');

} catch (Throwable $e) {
    $failures[] = 'Contract exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}

if (!empty($failures)) {
    fwrite(STDERR, "\nProduct status toggle contract FAILED (" . count($failures) . " errors):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nProduct status toggle contract: ALL PASSED\n";
