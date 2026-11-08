<?php
// src/backend/tests/cashier_gcash_and_reversals_test.php
//
// Verification suite for Ticket #449:
// Cashier GCash Validation, Receipt Linkage, and Transaction Reversals.

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Services/CashRefundService.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/StockIssueService.php';
require_once __DIR__ . '/../app/Receipts/ReceiptEngine.php';
require_once __DIR__ . '/../app/Services/GCashTransactionService.php';

use App\Services\CashRefundService;
use App\Services\CashierShiftService;
use App\Services\GCashTransactionService;
use App\Receipts\ReceiptEngine;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  ok - {$message}\n";
};

echo "Starting contract tests for Ticket #449 (GCash Validation, Receipt Linkage & Loss Reports)...\n";

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->sqliteCreateFunction('NOW', static fn() => '2026-11-08 10:00:00');
$pdo->sqliteCreateFunction('CURDATE', static fn() => '2026-11-08');
$pdo->sqliteCreateFunction('GREATEST', static fn($a, $b) => max((float)$a, (float)$b), 2);
$pdo->sqliteCreateFunction('DATE_ADD', static fn(string $date, string $interval, int $days) => date('Y-m-d', strtotime($date . ' +' . $days . ' days')), 3);

// Setup schema
$pdo->exec("
    CREATE TABLE branches (branch_id INTEGER PRIMARY KEY, branch_name TEXT, branch_code TEXT);
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT UNIQUE);
    INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'admin'), (3, 'inventory_manager'), (4, 'super_admin');

    CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, display_name TEXT, username TEXT, role_id INTEGER, branch_id INTEGER, status TEXT DEFAULT 'active');
    INSERT INTO users (user_id, full_name, display_name, username, role_id, branch_id) VALUES (1, 'Maria Cashier', 'Maria C.', 'cashier1', 1, 1);

    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT, paper_width_mm TEXT DEFAULT '80', status TEXT DEFAULT 'active');
    INSERT INTO registers (register_id, name) VALUES (1, 'Terminal 1');

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        register_id INTEGER,
        opening_cash REAL DEFAULT 1000.00,
        status TEXT DEFAULT 'open',
        opened_at TEXT DEFAULT '2026-11-08 08:00:00',
        locked_at TEXT NULL
    );
    INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash) VALUES (1, 1, 1, 1000.00);

    CREATE TABLE cash_drawer_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER NOT NULL,
        cashier_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL,
        amount REAL NOT NULL,
        reason TEXT NULL,
        note TEXT NULL,
        recorded_by INTEGER NOT NULL,
        recorded_at TEXT DEFAULT '2026-11-08 10:00:00'
    );

    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER DEFAULT 1,
        sku TEXT,
        barcode TEXT,
        product_name TEXT,
        unit_price REAL,
        cost_price REAL DEFAULT 10.00,
        reorder_level INTEGER DEFAULT 5,
        safety_stock INTEGER DEFAULT 2,
        category_id INTEGER DEFAULT 1,
        quantity_sold INTEGER DEFAULT 0,
        status TEXT DEFAULT 'active'
    );
    INSERT INTO products (product_id, sku, barcode, product_name, unit_price) VALUES (1, 'SKU-001', '4800001001', 'Pandesal 10s', 50.00);

    CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER DEFAULT 100);
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 100);

    CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER,
        batch_number TEXT,
        quantity INTEGER,
        remaining_quantity INTEGER,
        sealed_quantity INTEGER DEFAULT 0,
        loose_quantity INTEGER DEFAULT 0,
        expiration_date TEXT NULL,
        date_received TEXT DEFAULT '2026-11-08 08:00:00'
    );
    INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, loose_quantity) VALUES (1, 'BATCH-001', 100, 100, 100);

    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_name TEXT,
        start_date TEXT,
        end_date TEXT,
        status TEXT DEFAULT 'open'
    );
    INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2026-01-01', '2026-12-31', 'open');

    CREATE TABLE fiscal_period_locks (period_id INTEGER, table_name TEXT);

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
        created_at TEXT DEFAULT '2026-11-08 10:00:00'
    );

    CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        shift_id INTEGER,
        total_amount REAL,
        payment_method TEXT DEFAULT 'cash',
        payment_reference TEXT NULL,
        sale_date TEXT DEFAULT '2026-11-08 10:00:00',
        cash_received REAL NULL,
        change_due REAL NULL,
        discount_amount REAL DEFAULT 0,
        status TEXT DEFAULT 'completed'
    );

    CREATE TABLE sale_items (
        sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER,
        product_id INTEGER,
        quantity REAL,
        unit_price REAL,
        subtotal REAL,
        selling_form TEXT DEFAULT 'piece',
        pack_size INTEGER NULL
    );

    CREATE TABLE sale_item_batches (
        sale_item_batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_item_id INTEGER NOT NULL,
        batch_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        unit_cost REAL DEFAULT 10.00,
        cost_status TEXT DEFAULT 'exact'
    );

    CREATE TABLE sale_receipt_details (
        sale_id INTEGER PRIMARY KEY,
        details_json TEXT,
        created_at TEXT DEFAULT '2026-11-08 10:00:00'
    );

    CREATE TABLE sale_reversals (
        reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER,
        reversal_type TEXT DEFAULT 'return',
        status TEXT DEFAULT 'pending',
        reason TEXT NULL,
        settlement_method TEXT DEFAULT 'cash',
        refund_amount REAL DEFAULT 0.00,
        created_at TEXT DEFAULT '2026-11-08 10:00:00'
    );

    CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER,
        shift_id INTEGER,
        cashier_id INTEGER,
        refund_amount REAL,
        payment_method TEXT,
        reason TEXT,
        note TEXT,
        original_cashier_id INTEGER,
        approved_by INTEGER NULL,
        exception_reason TEXT NULL,
        payment_reference TEXT NULL,
        created_at TEXT DEFAULT '2026-11-08 10:30:00'
    );

    CREATE TABLE cash_refund_items (
        refund_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        refund_id INTEGER,
        sale_item_id INTEGER,
        product_id INTEGER,
        quantity REAL,
        unit_price REAL,
        subtotal REAL,
        disposition TEXT
    );

    CREATE TABLE refund_receipt_details (
        refund_id INTEGER PRIMARY KEY,
        details_json TEXT,
        created_at TEXT DEFAULT '2026-11-08 10:30:00'
    );

    CREATE TABLE inventory_adjustments (
        adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        adjustment_qty INTEGER NOT NULL,
        adjustment_type TEXT NOT NULL,
        reported_by INTEGER NOT NULL,
        shift_id INTEGER NULL,
        reported_at TEXT NOT NULL DEFAULT '2026-11-08 10:45:00',
        approved_by INTEGER NULL,
        approved_at TEXT NULL,
        reason TEXT NULL,
        review_notes TEXT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        selling_form TEXT DEFAULT 'piece'
    );

    CREATE TABLE stock_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        change_qty INTEGER NOT NULL,
        reason TEXT NOT NULL,
        moved_by INTEGER NOT NULL,
        adjustment_id INTEGER NULL,
        batch_id INTEGER NULL,
        moved_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE gcash_service_sessions (
        session_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER,
        cashier_id INTEGER,
        opening_service_cash REAL,
        opening_wallet_balance REAL,
        status TEXT DEFAULT 'open',
        opened_at TEXT DEFAULT '2026-11-08 08:00:00'
    );
    INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, opening_service_cash, opening_wallet_balance)
    VALUES (1, 1, 1, 5000.00, 10000.00);

    CREATE TABLE gcash_transactions (
        transaction_id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER,
        shift_id INTEGER,
        transaction_type TEXT,
        reference_number TEXT UNIQUE,
        amount REAL,
        principal_amount REAL,
        fee_amount REAL,
        fee_method TEXT,
        service_cash_delta REAL DEFAULT 0,
        wallet_delta REAL DEFAULT 0,
        gross_fee REAL DEFAULT 0,
        cashier_id INTEGER,
        responsible_user_id INTEGER,
        responsible_role TEXT,
        responsibility_assigned_at TEXT,
        confirmed_wallet INTEGER DEFAULT 0,
        confirmed_cash INTEGER DEFAULT 0,
        notes TEXT,
        verification_status TEXT DEFAULT 'Pending',
        verified_by INTEGER NULL,
        completed_at TEXT NULL,
        receipt_details_json TEXT NULL
    );

    CREATE TABLE store_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
    INSERT INTO store_settings (setting_key, setting_value) VALUES
        ('store_name', 'Shalom Store'),
        ('store_address', 'Tangub City'),
        ('store_phone', '09171234567'),
        ('currency_symbol', '₱');
");

// -----------------------------------------------------------------------------
// Test 1: GCash reference validation for correct Philippine format and length
// -----------------------------------------------------------------------------
echo "\n--- Test 1: GCash Reference Validation ---\n";
$salesService = new \SalesWorkflowService($pdo);

// A valid 13-digit Philippine GCash reference
$validRef = '0012345678901';
$cart = [
    'SKU-001:piece' => [
        'product_id' => 1,
        'sku' => 'SKU-001',
        'name' => 'Pandesal 10s',
        'qty' => 2,
        'unit_price' => 50.00,
        'selling_form' => 'piece',
        'pack_size' => null,
    ],
];

// Valid reference passes e-wallet checkout
$checkoutResult = $salesService->checkout($cart, 1, 'cashier', 'ewallet', [
    'payment_reference' => $validRef,
    'payment_verified' => true,
]);
$assert(is_array($checkoutResult) && !empty($checkoutResult['sale_id']), 'Valid 13-digit Philippine GCash reference completes e-wallet checkout');

// Formatted 13-digit reference with spaces and dashes is normalized and accepted
$formattedRef = '0012 345 678902';
$checkoutResult2 = $salesService->checkout($cart, 1, 'cashier', 'ewallet', [
    'payment_reference' => $formattedRef,
    'payment_verified' => true,
]);
$savedRef = $pdo->query('SELECT payment_reference FROM sales WHERE sale_id = ' . (int)$checkoutResult2['sale_id'])->fetchColumn();
$assert($savedRef === '0012345678902', 'Spaces in Philippine GCash reference are normalized cleanly on checkout');

// Invalid reference lengths and formats must be refused before checkout finalization
$invalidReferences = [
    '12345',              // Too short
    '00123456789012345',  // Too long
    '001234567890A',      // Non-numeric character
    'ABCDEFGHIJKLM',      // Letters only
    '',                   // Empty
];

foreach ($invalidReferences as $badRef) {
    $refused = false;
    try {
        $salesService->checkout($cart, 1, 'cashier', 'ewallet', [
            'payment_reference' => $badRef,
            'payment_verified' => true,
        ]);
    } catch (\Throwable $e) {
        $refused = true;
    }
    $assert($refused, "Invalid GCash reference '{$badRef}' is refused before checkout finalization");
}

// -----------------------------------------------------------------------------
// Test 2: GCash receipt dialog displays verified reference details, mobile customer identifier, and timestamp
// -----------------------------------------------------------------------------
echo "\n--- Test 2: GCash Receipt Dialog Details ---\n";

$receiptEngine = new ReceiptEngine($pdo);
$gcashReceiptData = [
    'transaction_id' => 101,
    'transaction_type' => 'Cash-In',
    'completed_at' => '2026-11-08 10:15:30',
    'cashier_name' => 'Maria Cashier',
    'register_name' => 'Terminal 1',
    'reference_number' => '0012345678901',
    'principal_amount' => '500.00',
    'fee_amount' => '10.00',
    'fee_method' => 'Fee in cash',
    'customer_identifier' => '0917-123-4567',
    'cash_collected' => '510.00',
    'cash_paid_out' => '0.00',
    'wallet_sent' => '500.00',
    'wallet_received' => '0.00',
    'store' => [
        'name' => 'Shalom Store',
        'address' => 'Tangub City',
        'currency_symbol' => '₱',
    ],
];

$viewData = ReceiptEngine::prepareGCashViewData($gcashReceiptData, 80);
$assert($viewData['reference_number'] === '0012345678901', 'GCash receipt view data preserves verified reference number');
$assert($viewData['customer_identifier'] === '0917-123-4567', 'GCash receipt view data preserves mobile customer identifier');
$assert(!empty($viewData['completed_at_formatted']), 'GCash receipt view data formats completion timestamp');

// Render HTML view
ob_start();
ReceiptEngine::renderGCash($gcashReceiptData, 80);
$html = ob_get_clean();

$assert(str_contains($html, '0012345678901'), 'GCash receipt HTML contains verified reference digits');
$assert(str_contains($html, '0917-123-4567'), 'GCash receipt HTML displays customer mobile identifier');
$assert(str_contains($html, $viewData['completed_at_formatted']), 'GCash receipt HTML displays formatted timestamp');

// -----------------------------------------------------------------------------
// Test 3: Cash refund requests link to valid historical sale receipt and cannot exceed original paid amounts
// -----------------------------------------------------------------------------
echo "\n--- Test 3: Cash Refund Receipt Linkage & Paid Amount Cap ---\n";

$shiftService = new CashierShiftService($pdo);
$refundService = new CashRefundService($pdo, $shiftService);

// 1. Refund against non-existent sale ID is refused
$fakeSaleRefused = false;
try {
    $refundService->refund(1, 'cashier', 99999, [
        1 => ['quantity' => 1, 'disposition' => 'restockable'],
    ], 'damaged', 'Item spoiled');
} catch (\DomainException $e) {
    $fakeSaleRefused = true;
}
$assert($fakeSaleRefused, 'Refund request for non-existent sale receipt is rejected');

// Let's create an authentic historical cash sale of ₱100 (2 Pandesal @ ₱50)
$historicalSale = $salesService->checkout($cart, 1, 'cashier', 'cash', [
    'cash_received' => 100.00,
]);
$historicalSaleId = (int)$historicalSale['sale_id'];
$saleItemStmt = $pdo->prepare('SELECT sale_item_id FROM sale_items WHERE sale_id = ?');
$saleItemStmt->execute([$historicalSaleId]);
$historicalSaleItemId = (int)$saleItemStmt->fetchColumn();

// Partial refund of 1 item (₱50) against historical sale
$refundId1 = $refundService->refund(1, 'cashier', $historicalSaleId, [
    $historicalSaleItemId => ['quantity' => 1, 'disposition' => 'restockable'],
], 'customer_return', 'Returned one piece');
$assert($refundId1 > 0, 'Partial refund links cleanly to valid historical sale receipt');

// Check remaining refundable balance is now ₱50
$remainingRefundable = $refundService->refundableAmount($historicalSaleId);
$assert(abs($remainingRefundable - 50.00) < 0.01, 'Remaining refundable balance correctly reflects remaining ₱50 of ₱100 paid');

// Attempting to refund 2 more items (₱100 requested, but only ₱50 left)
// CashRefundService caps or rejects excess over original paid amount
$refundId2 = $refundService->refund(1, 'cashier', $historicalSaleId, [
    $historicalSaleItemId => ['quantity' => 1, 'disposition' => 'restockable'],
], 'customer_return', 'Returned second piece');
$assert($refundId2 > 0, 'Second refund of remaining item succeeds');

// Now fully refunded: remaining is 0. Attempting any further refund MUST be rejected
$overRefundRefused = false;
try {
    $refundService->refund(1, 'cashier', $historicalSaleId, [
        $historicalSaleItemId => ['quantity' => 1, 'disposition' => 'restockable'],
    ], 'customer_return', 'Attempt extra refund');
} catch (\DomainException $e) {
    $overRefundRefused = true;
}
$assert($overRefundRefused, 'Refund request exceeding original paid receipt amount is refused');

// -----------------------------------------------------------------------------
// Test 4: Cashier stock issue submission directly from terminal
// -----------------------------------------------------------------------------
echo "\n--- Test 4: Terminal Stock Issue Loss Reporting ---\n";

$stockIssueService = new StockIssueService($pdo, null, 'cashier');
$reportId = $stockIssueService->submitReport([
    'product_id' => 1,
    'category' => 'damaged',
    'quantity' => 3,
    'form' => 'piece',
    'explanation' => 'Dented packaging discovered during counter transaction',
], 1);

$assert($reportId > 0, 'Cashier submits damaged item report directly from active terminal shift');

// Verify report was recorded as pending with correct attributes
$reportStmt = $pdo->prepare('SELECT * FROM inventory_adjustments WHERE adjustment_id = ?');
$reportStmt->execute([$reportId]);
$report = $reportStmt->fetch();

$assert($report['status'] === 'pending', 'Submitted stock loss report is recorded as pending review');
$assert((int)$report['product_id'] === 1, 'Report records affected product ID');
$assert((int)$report['adjustment_qty'] === -3, 'Report records affected quantity as negative loss');
$assert($report['adjustment_type'] === 'damaged', 'Report category is recorded as damaged');

echo "\nAll Ticket #449 contract tests PASSED!\n";
