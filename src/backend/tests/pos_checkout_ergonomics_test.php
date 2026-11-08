<?php
// src/backend/tests/pos_checkout_ergonomics_test.php
//
// Verification suite for Ticket #447:
// Cashier POS & Rapid Checkout Ergonomics.

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Services/HeldSaleService.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Receipts/ReceiptEngine.php';

use App\Services\CashierShiftService;
use App\Services\HeldSaleService;
use App\Receipts\ReceiptEngine;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  ok - {$message}\n";
};

echo "Starting contract tests for Ticket #447 (POS Ergonomics & Rapid Checkout)...\n";

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->sqliteCreateFunction('NOW', static fn() => '2026-11-08 10:00:00');
$pdo->sqliteCreateFunction('CURDATE', static fn() => '2026-11-08');

$pdo->exec("
    CREATE TABLE branches (branch_id INTEGER PRIMARY KEY, branch_name TEXT, branch_code TEXT);
    CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, role_id INTEGER, branch_id INTEGER, status TEXT DEFAULT 'active');
    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT, paper_width_mm TEXT DEFAULT '80');
    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        register_id INTEGER,
        opening_cash REAL,
        status TEXT DEFAULT 'open',
        opened_at TEXT DEFAULT (datetime('now')),
        locked_at TEXT NULL
    );
    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER DEFAULT 1,
        sku TEXT,
        barcode TEXT,
        case_barcode TEXT NULL,
        pack_size INTEGER NULL,
        pack_barcode TEXT NULL,
        pack_price REAL NULL,
        product_name TEXT,
        unit_price REAL,
        cost_price REAL NULL,
        reorder_level INTEGER DEFAULT 0,
        safety_stock INTEGER DEFAULT 0,
        category_id INTEGER DEFAULT 1,
        quantity_sold INTEGER DEFAULT 0,
        status TEXT DEFAULT 'active'
    );
    CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER);
    CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER,
        batch_number TEXT,
        quantity INTEGER,
        remaining_quantity INTEGER,
        sealed_quantity INTEGER DEFAULT 0,
        loose_quantity INTEGER DEFAULT 0,
        expiration_date TEXT NULL,
        date_received TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE held_sales (
        held_sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        shift_id INTEGER,
        reference_no TEXT,
        customer_label TEXT NULL,
        cart_json TEXT,
        item_count INTEGER DEFAULT 0,
        total_amount REAL DEFAULT 0.00,
        status TEXT DEFAULT 'held',
        created_at TEXT DEFAULT (datetime('now')),
        expires_at TEXT NULL,
        resolved_at TEXT NULL,
        discard_reason TEXT NULL,
        discard_note TEXT NULL,
        discarded_by INTEGER NULL
    );
    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT,
        category TEXT,
        module TEXT,
        record_id INTEGER,
        previous_value TEXT NULL,
        new_value TEXT NULL,
        ip_address TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE store_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        shift_id INTEGER,
        total_amount REAL,
        payment_method TEXT DEFAULT 'cash',
        sale_date TEXT DEFAULT (datetime('now')),
        cash_received REAL NULL,
        change_due REAL NULL,
        discount_amount REAL DEFAULT 0
    );
    CREATE TABLE sale_items (
        sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER,
        product_id INTEGER,
        quantity INTEGER,
        unit_price REAL,
        subtotal REAL
    );
    CREATE TABLE sale_receipt_details (
        sale_id INTEGER PRIMARY KEY,
        details_json TEXT NOT NULL
    );
");

$pdo->exec("INSERT INTO users VALUES (1, 'Jane Cashier', 'cashier1', 3, 1, 'active')");
$pdo->exec("INSERT INTO registers VALUES (1, 'Main Register', '80')");
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash, status) VALUES (1, 1, 1, 1000.00, 'open')");
$pdo->exec("INSERT INTO store_settings VALUES ('store_name', 'RetailMind Superstore'), ('currency_symbol', '₱'), ('store_tin', '123-456-789-000')");

// Product setup: Product 1 (Soap, ₱50.00), Product 2 (Shampoo, ₱120.00)
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price) VALUES
    (1, 'SOAP-100', '480000000001', 'Moisturizing Soap', 50.00),
    (2, 'SHAMP-200', '480000000002', 'Herbal Shampoo 200ml', 120.00)");
$pdo->exec("INSERT INTO inventory VALUES (1, 50), (2, 30)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity) VALUES
    (1, 1, 'BATCH-S1', 50, 50, 0, 50),
    (2, 2, 'BATCH-H1', 30, 30, 0, 30)");

$shiftService = new CashierShiftService($pdo);
$heldService = new HeldSaleService($pdo, $shiftService);

// =========================================================================
// SECTION 1: Held Cart Restoration with Custom Pricing & Item Discounts
// =========================================================================
echo "\n--- Section 1: Held cart with custom pricing & item discounts ---\n";

$customCart = [
    '1:piece' => [
        'product_id' => 1,
        'selling_form' => 'piece',
        'qty' => 2,
        'price' => 45.00, // Custom price: ₱45 instead of catalog ₱50
        'custom_price' => 45.00,
        'discount' => 5.00, // Item discount: ₱5 off
        'name' => 'Moisturizing Soap',
    ],
    '2:piece' => [
        'product_id' => 2,
        'selling_form' => 'piece',
        'qty' => 1,
        'price' => 120.00,
        'name' => 'Herbal Shampoo 200ml',
    ],
];

$held = $heldService->hold(1, 'cashier', $customCart, 'VIP Customer');
$assert($held['held_sale_id'] > 0, 'Cart with custom pricing and item discount held successfully');

$resumed = $heldService->resume(1, 'cashier', $held['held_sale_id']);
$assert(count($resumed['cart']) === 2, 'Resumed cart has both scanned line items');
$assert(isset($resumed['cart']['1:piece']['custom_price']), 'Custom price key preserved in held cart');
$assert((float)$resumed['cart']['1:piece']['custom_price'] === 45.00, 'Custom price value 45.00 preserved intact');
$assert(isset($resumed['cart']['1:piece']['discount']), 'Item discount key preserved in held cart');
$assert((float)$resumed['cart']['1:piece']['discount'] === 5.00, 'Item discount value 5.00 preserved intact');
$assert(isset($resumed['review']['cart']['1:piece']['custom_price']), 'Review proposal preserves custom price');
$assert(isset($resumed['review']['cart']['1:piece']['discount']), 'Review proposal preserves item discount');

// =========================================================================
// SECTION 2: Receipt Itemization, Tax Breakdown & Change Due
// =========================================================================
echo "\n--- Section 2: Receipt tax breakdown & change due ---\n";

$saleRecord = [
    'sale_id' => 901,
    'cashier_id' => 1,
    'shift_id' => 1,
    'total_amount' => 112.00, // VAT inclusive: ₱100 VATable + ₱12 VAT
    'payment_method' => 'cash',
    'cash_received' => 150.00,
    'change_due' => 38.00,
    'discount_amount' => 0.00,
    'sale_date' => '2026-11-08 10:30:00',
    'receipt_store' => [
        'name' => 'RetailMind Superstore',
        'currency_symbol' => '₱',
        'address' => '123 Market St, Manila',
        'contact' => '+63 2 8123 4567',
        'tin' => '123-456-789-000',
        'footer' => 'Thank you for shopping with us!',
        'logo' => '',
        'tagline' => 'Everyday low prices',
    ],
];

$saleItems = [
    [
        'sale_item_id' => 1,
        'sale_id' => 901,
        'product_id' => 1,
        'product_name' => 'Moisturizing Soap',
        'sku' => 'SOAP-100',
        'quantity' => 2,
        'unit_price' => 56.00,
        'subtotal' => 112.00,
        'selling_form' => 'piece',
    ],
];

$viewData = ReceiptEngine::prepareSaleViewData(
    $saleRecord,
    $saleItems,
    $saleRecord['receipt_store'],
    80
);

$assert(isset($viewData['totals']['vatable_sales_formatted']), 'Totals include VATable sales formatted');
$assert(isset($viewData['totals']['vat_amount_formatted']), 'Totals include VAT amount formatted');
$assert($viewData['totals']['vatable_sales_formatted'] === '₱100.00', 'VATable sales calculated correctly (112 / 1.12 = 100.00)');
$assert($viewData['totals']['vat_amount_formatted'] === '₱12.00', '12% VAT amount calculated correctly (112 - 100 = 12.00)');
$assert($viewData['totals']['change_due_formatted'] === '₱38.00', 'Change due formatted correctly (150 - 112 = 38.00)');
$assert(count($viewData['items']) === 1, 'Completed itemization contains scanned item');
$assert($viewData['items'][0]['name'] === 'Moisturizing Soap', 'Itemized item product name rendered');

// Test HTML view output contains the tax breakdown and change due
ob_start();
$view = $viewData;
require __DIR__ . '/../app/Views/sale_receipt.php';
$receiptHtml = ob_get_clean();

$assert(str_contains($receiptHtml, 'VATable Sales'), 'Receipt HTML contains VATable Sales row');
$assert(str_contains($receiptHtml, '₱100.00'), 'Receipt HTML contains VATable Sales amount ₱100.00');
$assert(str_contains($receiptHtml, 'VAT (12%)'), 'Receipt HTML contains VAT (12%) row');
$assert(str_contains($receiptHtml, '₱12.00'), 'Receipt HTML contains VAT amount ₱12.00');
$assert(str_contains($receiptHtml, 'Change'), 'Receipt HTML contains Change row');
$assert(str_contains($receiptHtml, '₱38.00'), 'Receipt HTML contains Change amount ₱38.00');

echo "\nAll Ticket #447 contract assertions PASSED!\n";
