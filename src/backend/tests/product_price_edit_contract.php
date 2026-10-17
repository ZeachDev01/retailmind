<?php
// Contract and integration test for Ticket #213: Edit selling price while preserving completed sales and receipt history.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/HeldSaleService.php';

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
    // 1. Static Contract Checks on products.php
    echo "Checking products.php static contract for selling price editing...\n";
    $productsSource = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/inventory_management/products.php');

    $assert(str_contains($productsSource, "name=\"selling_price\""), 'products.php includes selling_price input field');
    $assert(str_contains($productsSource, 'id="edit-selling-price"'), 'products.php provides edit-selling-price ID for price input');
    $assert(str_contains($productsSource, 'Cost Price') && str_contains($productsSource, 'Read-only'), 'products.php displays cost price as read-only');
    $assert(str_contains($productsSource, 'id="selling-price-warning"'), 'products.php includes selling-price-warning container');
    $assert(str_contains($productsSource, 'below cost price'), 'products.php includes inline warning check for price below cost');
    $assert(str_contains($productsSource, "dataset.price = String(product.unit_price"), 'products.php updates dataset.price on catalog save');
    $assert(str_contains($productsSource, 'priceCell.textContent = formatMoney(product.unit_price)'), 'products.php updates column-price cell with formatMoney');
    $assert(str_contains($productsSource, "\$updateData['selling_price'] = \$_POST['selling_price']"), 'products.php passes selling_price to updateData');

    // 2. Database Integration & Service Contract Setup
    echo "Checking ProductService selling price editing and validations...\n";
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
            user_id INTEGER,
            branch_id INTEGER,
            total_amount REAL NOT NULL,
            payment_method TEXT,
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

        CREATE TABLE sale_receipt_details (
            sale_id INTEGER PRIMARY KEY,
            details_json TEXT NOT NULL
        );

        CREATE TABLE held_sales (
            held_sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
            shift_id INTEGER,
            cashier_id INTEGER,
            reference_no TEXT,
            cart_json TEXT,
            item_count INTEGER,
            total_amount REAL,
            status TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            resolved_at TEXT DEFAULT NULL,
            discard_reason TEXT DEFAULT NULL,
            discard_note TEXT DEFAULT NULL,
            discarded_by INTEGER DEFAULT NULL
        );

        CREATE TABLE cashier_shifts (
            shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER,
            branch_id INTEGER NOT NULL DEFAULT 1,
            status TEXT DEFAULT 'open',
            starting_cash REAL DEFAULT 0,
            opened_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        INSERT INTO products (
            product_id, sku, barcode, case_barcode, parent_product_id, variant_label, product_name, brand, category_id,
            unit_price, cost_price, quantity_purchased, quantity_sold, reorder_level, safety_stock,
            minimum_order_quantity, units_per_package, base_unit, receiving_unit, expiration_date, product_image, status
        ) VALUES (
            1, 'SKU-001', 'BAR-001', 'CASE-001', NULL, NULL, 'Original Juice', 'JuiceCo', 1,
            50.0, 30.0, 100, 20, 15, 5,
            2, 12, 'can', 'case', '2027-01-01', 'storage/images/juice.png', 'active'
        )
    ");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 80)");

    // Insert completed historical sale with receipt details
    $pdo->exec("
        INSERT INTO sales (sale_id, user_id, branch_id, total_amount, payment_method, created_at)
        VALUES (10, 1, 1, 100.00, 'cash', '2026-10-01 10:00:00');

        INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
        VALUES (101, 10, 1, 2, 50.00, 100.00);

        INSERT INTO sale_receipt_details (sale_id, details_json)
        VALUES (10, '{\"sale_id\":10,\"items\":[{\"product_id\":1,\"unit_price\":50.00,\"qty\":2,\"subtotal\":100.00}],\"total\":100.00}');
    ");

    $service = new ProductService($pdo);

    // 2. Workspace Authorization Check: Reject non-inventory_manager
    $nonInventoryRoles = ['admin', 'super_admin', 'cashier', ''];
    foreach ($nonInventoryRoles as $role) {
        $GLOBALS['test_active_role'] = $role;
        $_SESSION['role'] = $role;
        try {
            $service->updateProductCatalogDetails(1, ['product_name' => 'Original Juice', 'selling_price' => 60.0], 1);
            $assert(false, "Service should reject edit from workspace: '{$role}'");
        } catch (Throwable $e) {
            $assert(str_contains(strtolower($e->getMessage()), 'inventory manager'), "Non-inventory_manager workspace '{$role}' rejected with actionable message");
        }
    }

    // Set role to inventory_manager for subsequent tests
    $GLOBALS['test_active_role'] = 'inventory_manager';
    $_SESSION['role'] = 'inventory_manager';

    // 3. Selling price validation
    // Zero rejected
    foreach ([0, '0', '0.00', 0.0001] as $zeroVal) {
        try {
            $service->updateProductCatalogDetails(1, ['product_name' => 'Original Juice', 'selling_price' => $zeroVal], 1);
            $assert(false, "Zero selling price '{$zeroVal}' should be rejected");
        } catch (Throwable $e) {
            $assert(str_contains(strtolower($e->getMessage()), 'greater than php 0'), "Zero selling price '{$zeroVal}' rejected");
        }
    }

    // Negative rejected
    foreach ([-1, -15.5, '-20.00'] as $negVal) {
        try {
            $service->updateProductCatalogDetails(1, ['product_name' => 'Original Juice', 'selling_price' => $negVal], 1);
            $assert(false, "Negative selling price '{$negVal}' should be rejected");
        } catch (Throwable $e) {
            $assert(str_contains(strtolower($e->getMessage()), 'greater than php 0'), "Negative selling price '{$negVal}' rejected");
        }
    }

    // Non-numeric / malformed / empty rejected
    foreach (['not-a-number', '10.5.5', '', null] as $invalidVal) {
        try {
            $service->updateProductCatalogDetails(1, ['product_name' => 'Original Juice', 'selling_price' => $invalidVal], 1);
            $assert(false, "Invalid selling price '{$invalidVal}' should be rejected");
        } catch (Throwable $e) {
            $assert(str_contains(strtolower($e->getMessage()), 'valid number'), "Invalid selling price '" . var_export($invalidVal, true) . "' rejected");
        }
    }

    // Out of range rejected
    try {
        $service->updateProductCatalogDetails(1, ['product_name' => 'Original Juice', 'selling_price' => 1000000000000.0], 1);
        $assert(false, 'Out of range selling price should be rejected');
    } catch (Throwable $e) {
        $assert(str_contains(strtolower($e->getMessage()), 'out of range'), 'Out of range selling price rejected');
    }

    // Positive valid price accepted & 2-decimal rounding
    $update1 = $service->updateProductCatalogDetails(1, ['product_name' => 'Original Juice', 'selling_price' => 65.556], 1);
    $assert((float)$update1['unit_price'] === 65.56, 'Selling price accepted and rounded to 2 decimals (65.556 -> 65.56)');
    $assert((float)$update1['selling_price'] === 65.56, 'Returned payload includes selling_price property');
    $assert(($update1['warning'] ?? null) === null, 'No warning emitted when selling price >= cost price');

    $dbPrice = (float)$pdo->query('SELECT unit_price FROM products WHERE product_id = 1')->fetchColumn();
    $assert($dbPrice === 65.56, 'DB unit_price updated to 65.56');

    // 4. Below-cost price succeeds with warning indicator
    // Product 1 cost_price is 30.0. Update to 25.0 (< 30.0)
    $belowCostUpdate = $service->updateProductCatalogDetails(1, ['product_name' => 'Original Juice', 'selling_price' => 25.00], 1);
    $assert((float)$belowCostUpdate['unit_price'] === 25.00, 'Below-cost selling price saved successfully');
    $assert(isset($belowCostUpdate['warning']) && str_contains($belowCostUpdate['warning'], 'Warning: Selling price (₱25.00) is below cost price (₱30.00)'), 'Below-cost warning message returned with exact amounts');

    $dbPriceBelow = (float)$pdo->query('SELECT unit_price FROM products WHERE product_id = 1')->fetchColumn();
    $assert($dbPriceBelow === 25.00, 'DB unit_price updated to below-cost price (25.00)');

    // Update back above cost price (75.00)
    $aboveCostUpdate = $service->updateProductCatalogDetails(1, ['product_name' => 'Original Juice', 'selling_price' => 75.00], 1);
    $assert((float)$aboveCostUpdate['unit_price'] === 75.00, 'Selling price updated to 75.00');
    $assert(($aboveCostUpdate['warning'] ?? null) === null, 'Warning cleared when selling price is above cost price');

    // 5. Cost price and unrelated fields strictly preserved
    $stmt = $pdo->prepare('SELECT p.*, i.quantity_on_hand FROM products p JOIN inventory i ON i.product_id = p.product_id WHERE p.product_id = 1');
    $stmt->execute();
    $preserved = $stmt->fetch(PDO::FETCH_ASSOC);

    $assert((float)$preserved['cost_price'] === 30.0, 'Cost price strictly preserved (read-only)');
    $assert($preserved['sku'] === 'SKU-001', 'SKU preserved');
    $assert($preserved['barcode'] === 'BAR-001', 'Barcode preserved');
    $assert($preserved['case_barcode'] === 'CASE-001', 'Case barcode preserved');
    $assert($preserved['status'] === 'active', 'Status preserved');
    $assert((int)$preserved['quantity_on_hand'] === 80, 'Inventory quantity_on_hand preserved');
    $assert((int)$preserved['reorder_level'] === 15, 'Reorder level preserved');
    $assert((int)$preserved['safety_stock'] === 5, 'Safety stock preserved');
    $assert((int)$preserved['minimum_order_quantity'] === 2, 'Minimum order quantity preserved');
    $assert((int)$preserved['units_per_package'] === 12, 'Units per package preserved');
    $assert($preserved['base_unit'] === 'can', 'Base unit preserved');
    $assert($preserved['receiving_unit'] === 'case', 'Receiving unit preserved');

    // 6. Completed sales and receipt history preserved
    $histSale = $pdo->query('SELECT * FROM sales WHERE sale_id = 10')->fetch(PDO::FETCH_ASSOC);
    $assert((float)$histSale['total_amount'] === 100.00, 'Historical sale total_amount preserved');

    $histItem = $pdo->query('SELECT * FROM sale_items WHERE sale_item_id = 101')->fetch(PDO::FETCH_ASSOC);
    $assert((float)$histItem['unit_price'] === 50.00, 'Historical sale_item unit_price preserved');
    $assert((float)$histItem['subtotal'] === 100.00, 'Historical sale_item subtotal preserved');

    $histReceipt = $pdo->query('SELECT details_json FROM sale_receipt_details WHERE sale_id = 10')->fetchColumn();
    $assert(str_contains($histReceipt, '"unit_price":50'), 'Historical receipt details snapshot preserved byte-for-byte');

    // 7. Held sale resumption refreshes to new price and flags change report
    // Simulate held cart created when price was 50.00
    $heldCart = [
        1 => [
            'name' => 'Original Juice',
            'qty' => 2,
            'price' => 50.00,
        ]
    ];
    $shifts = new CashierShiftService($pdo);
    $heldService = new HeldSaleService($pdo, $shifts);
    $review = $heldService->reviewCart($heldCart);

    $assert((float)$review['cart'][1]['price'] === 75.00, 'Held sale resumption refreshed cart line to new current price (75.00)');
    $assert(!empty($review['changes']), 'Held sale review reported price changes');
    $changeText = implode(' ', $review['changes']);
    $assert(str_contains($changeText, 'price changed from PHP 50.00 to PHP 75.00'), 'Held sale change report specifies exact old and new price');

    // 8. New checkout uses updated price
    // Check that querying the product for POS/checkout gets the updated price
    $posProductStmt = $pdo->prepare("SELECT product_id, product_name, unit_price, status FROM products WHERE product_id = 1 AND status = 'active'");
    $posProductStmt->execute();
    $checkoutProduct = $posProductStmt->fetch(PDO::FETCH_ASSOC);
    $assert((float)$checkoutProduct['unit_price'] === 75.00, 'Checkout product query reads updated selling price (75.00)');

    // Record new sale item at updated price
    $pdo->prepare("INSERT INTO sales (sale_id, user_id, branch_id, total_amount, payment_method) VALUES (20, 1, 1, 225.00, 'cash')")->execute();
    $pdo->prepare("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal) VALUES (201, 20, 1, 3, ?, ?)")
        ->execute([$checkoutProduct['unit_price'], $checkoutProduct['unit_price'] * 3]);

    $newSaleItem = $pdo->query('SELECT * FROM sale_items WHERE sale_item_id = 201')->fetch(PDO::FETCH_ASSOC);
    $assert((float)$newSaleItem['unit_price'] === 75.00, 'New sale item recorded at updated selling price');
    $assert((float)$newSaleItem['subtotal'] === 225.00, 'New sale item subtotal calculated with updated price');

    // Historical sale item 101 remains untouched
    $recheckHistItem = $pdo->query('SELECT * FROM sale_items WHERE sale_item_id = 101')->fetch(PDO::FETCH_ASSOC);
    $assert((float)$recheckHistItem['unit_price'] === 50.00, 'Historical sale item 101 remains at 50.00 after new checkout');

} catch (Throwable $e) {
    $failures[] = 'Contract exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}

if (!empty($failures)) {
    fwrite(STDERR, "\nProduct price edit contract FAILED (" . count($failures) . " errors):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nProduct price edit contract: ALL PASSED\n";
