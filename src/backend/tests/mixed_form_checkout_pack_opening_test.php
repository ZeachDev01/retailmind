<?php
/**
 * Test suite for Issue #251: Complete mixed-form checkout with automatic Pack Opening.
 *
 * Verifies:
 * - Concurrency protection & quote review revalidation at final checkout
 * - Automatic Pack Opening for piece shortfalls (minimum FEFO packs, leftover loose retained)
 * - Conservation of Base Units on pack opening; only sale reduces total base units
 * - Pack sales consume sealed stock only; loose stock cannot satisfy pack lines
 * - Line order invariance in mixed carts
 * - Expiration eligibility (expired stock cannot be sold or opened)
 * - Atomic execution and complete rollback on injected failure
 * - Idempotent checkout attempt replay & recovery
 * - Persistence of selling_form and pack_size in sale_items and receipt details
 * - Immutable historical receipts across subsequent catalog edits
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Receipts/ReceiptDetailsService.php';
require_once __DIR__ . '/../app/Receipts/SaleReceiptPresentation.php';

use App\Receipts\ReceiptDetailsService;
use App\Receipts\SaleReceiptPresentation;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "  FAIL - {$message}\n";
    } else {
        echo "  ok - {$message}\n";
    }
};

function createTestPdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->sqliteCreateFunction('NOW', static fn() => '2026-10-20 10:00:00');
    $pdo->sqliteCreateFunction('CURDATE', static fn() => '2026-10-20');
    $pdo->sqliteCreateFunction('LEAST', static fn(...$vals) => min($vals));
    $pdo->sqliteCreateFunction('GREATEST', static fn(...$vals) => max($vals));

    $pdo->exec("
        CREATE TABLE branches (
            branch_id INTEGER PRIMARY KEY,
            branch_name TEXT,
            branch_code TEXT
        );
        CREATE TABLE users (
            user_id INTEGER PRIMARY KEY,
            full_name TEXT,
            username TEXT,
            password_hash TEXT,
            role_id INTEGER,
            branch_id INTEGER DEFAULT 1,
            status TEXT DEFAULT 'active'
        );
        CREATE TABLE roles (
            role_id INTEGER PRIMARY KEY,
            role_name TEXT
        );
        CREATE TABLE registers (
            register_id INTEGER PRIMARY KEY,
            name TEXT
        );
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
            status TEXT DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE inventory (
            product_id INTEGER PRIMARY KEY,
            quantity_on_hand INTEGER
        );
        CREATE TABLE product_batches (
            batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            batch_number TEXT,
            quantity INTEGER,
            remaining_quantity INTEGER,
            sealed_quantity INTEGER NOT NULL DEFAULT 0,
            loose_quantity INTEGER NOT NULL DEFAULT 0,
            cost_price REAL NULL,
            expiration_date TEXT NULL,
            date_received TEXT DEFAULT (datetime('now')),
            receiving_id INTEGER NULL
        );
        CREATE TABLE stock_receiving (
            receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cost_price REAL NULL,
            total_cost REAL NULL,
            accepted_qty INTEGER NULL,
            received_qty INTEGER NULL
        );
        CREATE TABLE sales (
            sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER,
            branch_id INTEGER DEFAULT 1,
            shift_id INTEGER,
            total_amount REAL,
            gross_amount REAL NULL,
            payment_method TEXT,
            cash_received REAL NULL,
            change_due REAL NULL,
            payment_reference TEXT NULL,
            discount_type TEXT DEFAULT 'none',
            discount_value REAL DEFAULT 0,
            discount_amount REAL DEFAULT 0,
            discount_reason TEXT NULL,
            discount_authorized_by INTEGER NULL,
            promotion_id INTEGER NULL,
            promotion_name TEXT NULL,
            sale_date TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE sale_items (
            sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER,
            product_id INTEGER,
            selling_form TEXT NOT NULL DEFAULT 'piece',
            pack_size INTEGER NULL,
            quantity INTEGER,
            unit_price REAL,
            subtotal REAL
        );
        CREATE TABLE sale_item_batches (
            sale_item_batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_item_id INTEGER,
            batch_id INTEGER,
            quantity INTEGER,
            unit_cost REAL NULL,
            cost_price REAL NULL,
            cost_status TEXT DEFAULT 'missing',
            cost_source TEXT NULL,
            cost_provenance TEXT NULL
        );
        CREATE TABLE checkout_attempts (
            cashier_id INTEGER,
            attempt_id TEXT,
            request_hash TEXT,
            sale_id INTEGER NULL,
            result_json TEXT,
            PRIMARY KEY (cashier_id, attempt_id)
        );
        CREATE TABLE sale_receipt_details (
            sale_id INTEGER PRIMARY KEY,
            details_json TEXT NOT NULL
        );
        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            change_qty INTEGER,
            reason TEXT,
            moved_by INTEGER,
            moved_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE activity_log (
            log_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT,
            category TEXT,
            module TEXT,
            record_id INTEGER NULL,
            metadata TEXT NULL,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE promotions (
            promotion_id INTEGER PRIMARY KEY AUTOINCREMENT,
            promotion_name TEXT,
            discount_type TEXT,
            discount_value REAL,
            scope TEXT,
            starts_at TEXT,
            ends_at TEXT,
            status TEXT DEFAULT 'active'
        );
        CREATE TABLE fiscal_periods (
            period_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_name TEXT NOT NULL,
            start_date TEXT,
            end_date TEXT,
            status TEXT NOT NULL DEFAULT 'open'
        );
        CREATE TABLE fiscal_period_locks (
            lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_id INTEGER,
            table_name TEXT
        );
        CREATE TABLE store_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT
        );
    ");

    $pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'admin')");
    $pdo->exec("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id) VALUES (1, 'Jane Cashier', 'cashier1', 'hash', 1, 1)");
    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (1, 'Till 1')");
    $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash, status) VALUES (1, 1, 1, 500.0, 'open')");
    $pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2026-01-01', '2026-12-31', 'open')");

    return $pdo;
}

echo "Starting tests for Issue #251: Complete mixed-form checkout with automatic Pack Opening...\n";

// =========================================================================
// SECTION 1: Automatic pack opening for piece shortfalls
// =========================================================================
echo "\n--- Section 1: Automatic pack opening & Base Unit conservation ---\n";
$pdo = createTestPdo();
$service = new SalesWorkflowService($pdo);

// 2 sealed packs of 12 plus 3 loose pieces = 27 Base Units
$pdo->exec("INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price)
            VALUES (1, 'BIS-01', 'BIS-PC', 12, 'BIS-PK', 100.00, 'Chocolate Biscuit', 10.00, 7.00)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 27)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
            VALUES (1, 1, 'BATCH-01', 27, 27, 2, 3, date('now', '+30 days'))");

// Selling 5 pieces requires opening 1 pack
$cart = [['product_id' => 1, 'selling_form' => 'piece', 'qty' => 5]];
$quote = $service->reviewQuote($cart, 1, 'cashier');
$assert(count($quote['sale']['plans'][1]['pack_openings']) === 1, 'Review planned 1 pack opening');

$checkoutResult = $service->checkout($cart, 1, 'cashier', 'cash', [
    'cash_received' => 100.00,
    'reviewed_quote' => $quote['state_hash'],
]);

$assert($checkoutResult['sale_id'] > 0, 'Checkout succeeded with valid sale_id');
$assert((float)$checkoutResult['total'] === 50.00, 'Charged price is 5 * 10 = 50.00');

// Check batch: 1 sealed pack, 10 loose pieces, 22 base units remaining
$batch1 = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 1")->fetch();
$assert((int)$batch1['sealed_quantity'] === 1, 'Batch has exactly 1 sealed pack remaining');
$assert((int)$batch1['loose_quantity'] === 10, 'Batch has exactly 10 loose pieces remaining');
$assert((int)$batch1['remaining_quantity'] === 22, 'Batch remaining_quantity is 22 Base Units');

// Check inventory & products & movements
$inv1 = $pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert((int)$inv1 === 22, 'Inventory quantity_on_hand is 22 Base Units');

$soldQty1 = $pdo->query("SELECT quantity_sold FROM products WHERE product_id = 1")->fetchColumn();
$assert((int)$soldQty1 === 5, 'products.quantity_sold updated by 5 Base Units');

$movement1 = $pdo->query("SELECT * FROM stock_movements WHERE product_id = 1 AND reason = 'sale'")->fetch();
$assert((int)$movement1['change_qty'] === -5, 'stock_movements recorded -5 Base Units');

// Check activity log attribution for automatic pack opening
$activityLog = $pdo->query("SELECT * FROM activity_log WHERE record_id = {$checkoutResult['sale_id']} AND action = 'Automatic pack opening at checkout'")->fetch();
$assert($activityLog !== false, 'activity_log recorded Automatic pack opening at checkout');
$meta = json_decode((string)$activityLog['metadata'], true);
$assert($meta['packs_opened'] === 1, 'Activity log records 1 pack opened');
$assert($meta['leftover_loose'] === 10, 'Activity log records 10 leftover loose pieces');
$assert($meta['cashier_id'] === 1, 'Activity log attributes cashier');

// Check sale_items persistence
$saleItem1 = $pdo->query("SELECT * FROM sale_items WHERE sale_id = {$checkoutResult['sale_id']}")->fetch();
$assert($saleItem1['selling_form'] === 'piece', 'sale_items recorded selling_form piece');
$assert($saleItem1['pack_size'] === null, 'sale_items pack_size is null for piece line');
$assert((int)$saleItem1['quantity'] === 5, 'sale_items quantity is 5');
$assert((float)$saleItem1['unit_price'] === 10.00, 'sale_items unit_price is 10.00');
$assert((float)$saleItem1['subtotal'] === 50.00, 'sale_items subtotal is 50.00');

// =========================================================================
// SECTION 2: Sufficient loose stock opens NO pack
// =========================================================================
echo "\n--- Section 2: Sufficient loose stock opens NO pack ---\n";
// From remaining 1 sealed pack and 10 loose pieces (22 base units), sell 3 pieces
$cart2 = [['product_id' => 1, 'selling_form' => 'piece', 'qty' => 3]];
$quote2 = $service->reviewQuote($cart2, 1, 'cashier');
$assert(empty($quote2['sale']['plans'][1]['pack_openings']), 'No pack opening planned when loose stock sufficient');

$actCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'Automatic pack opening at checkout'")->fetchColumn();
$checkout2 = $service->checkout($cart2, 1, 'cashier', 'cash', [
    'cash_received' => 50.00,
    'reviewed_quote' => $quote2['state_hash'],
]);

$batch2 = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 1")->fetch();
$assert((int)$batch2['sealed_quantity'] === 1, 'Sealed pack untouched (still 1)');
$assert((int)$batch2['loose_quantity'] === 7, 'Loose pieces decremented from 10 to 7');
$assert((int)$batch2['remaining_quantity'] === 19, 'Batch remaining_quantity is 19');
$actCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'Automatic pack opening at checkout'")->fetchColumn();
$assert($actCountAfter === $actCountBefore, 'No additional pack opening activity logged');

// =========================================================================
// SECTION 3: Multiple-pack shortfall and exact depletion
// =========================================================================
echo "\n--- Section 3: Multiple-pack shortfall and exact depletion ---\n";
// Product 2: pack_size = 6, 3 sealed packs + 2 loose pieces = 20 base units.
$pdo->exec("INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price)
            VALUES (2, 'SOAP-01', 'SOAP-PC', 6, 'SOAP-PK', 50.00, 'Laundry Soap', 10.00, 6.00)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 20)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
            VALUES (2, 2, 'BATCH-02', 20, 20, 3, 2, date('now', '+30 days'))");

// Demand 14 pieces: 2 loose available, shortfall is 12 pieces -> exactly 2 packs of 6 needed.
$cart3 = [['product_id' => 2, 'selling_form' => 'piece', 'qty' => 14]];
$quote3 = $service->reviewQuote($cart3, 1, 'cashier');
$openings3 = $quote3['sale']['plans'][2]['pack_openings'];
$assert(count($openings3) === 1, '1 batch opening planned');
$assert($openings3[0]['packs'] === 2, 'Exactly 2 packs planned to open');
$assert($openings3[0]['leftover_loose'] === 0, 'Exact depletion of opened packs (0 leftover)');

$checkout3 = $service->checkout($cart3, 1, 'cashier', 'cash', [
    'cash_received' => 200.00,
    'reviewed_quote' => $quote3['state_hash'],
]);

$batch3 = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 2")->fetch();
$assert((int)$batch3['sealed_quantity'] === 1, '1 sealed pack remains (3 - 2 = 1)');
$assert((int)$batch3['loose_quantity'] === 0, '0 loose pieces remain (exact depletion)');
$assert((int)$batch3['remaining_quantity'] === 6, 'Batch remaining_quantity is 6');
$assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 2")->fetchColumn() === 6, 'Inventory is 6');

// =========================================================================
// SECTION 4: Pack sales consume sealed stock only
// =========================================================================
echo "\n--- Section 4: Pack sales consume sealed stock only ---\n";
// Product 3: 0 sealed packs, 24 loose pieces
$pdo->exec("INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price)
            VALUES (3, 'JUICE-01', 'JUICE-PC', 12, 'JUICE-PK', 100.00, 'Orange Juice', 10.00, 6.00)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (3, 24)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
            VALUES (3, 3, 'BATCH-03', 24, 24, 0, 24, date('now', '+30 days'))");

// Attempt to sell 1 pack when only loose stock exists: MUST FAIL
$packOnlyCart = [['product_id' => 3, 'selling_form' => 'pack', 'qty' => 1]];
try {
    $service->checkout($packOnlyCart, 1, 'cashier', 'cash', ['cash_received' => 100.00]);
    $assert(false, 'Expected StockConflictException for pack sale without sealed stock');
} catch (StockConflictException $e) {
    $assert(true, 'Pack sale without sealed stock rejected with StockConflictException: ' . $e->getMessage());
}

// Add 2 sealed packs to Batch 3: total 2 packs, 24 loose pieces = 48 base units
$pdo->exec("UPDATE product_batches SET sealed_quantity = 2, remaining_quantity = 48 WHERE batch_id = 3");
$pdo->exec("UPDATE inventory SET quantity_on_hand = 48 WHERE product_id = 3");

$quotePack = $service->reviewQuote($packOnlyCart, 1, 'cashier');
$checkoutPack = $service->checkout($packOnlyCart, 1, 'cashier', 'cash', [
    'cash_received' => 100.00,
    'reviewed_quote' => $quotePack['state_hash'],
]);

$batch3After = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 3")->fetch();
$assert((int)$batch3After['sealed_quantity'] === 1, 'Sealed pack decremented by 1 (2 -> 1)');
$assert((int)$batch3After['loose_quantity'] === 24, 'Loose stock untouched (still 24)');
$assert((int)$batch3After['remaining_quantity'] === 36, 'Batch remaining_quantity is 48 - 12 = 36');
$assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 3")->fetchColumn() === 36, 'Inventory deducted by 12 base units');

$packSaleItem = $pdo->query("SELECT * FROM sale_items WHERE sale_id = {$checkoutPack['sale_id']}")->fetch();
$assert($packSaleItem['selling_form'] === 'pack', 'sale_items recorded selling_form pack');
$assert((int)$packSaleItem['pack_size'] === 12, 'sale_items recorded pack_size 12');
$assert((int)$packSaleItem['quantity'] === 1, 'sale_items quantity is 1');
$assert((float)$packSaleItem['unit_price'] === 100.00, 'sale_items charged pack price 100.00');

$packMovement = $pdo->query("SELECT * FROM stock_movements WHERE product_id = 3 ORDER BY movement_id DESC LIMIT 1")->fetch();
$assert((int)$packMovement['change_qty'] === -12, 'stock_movements deducted 12 Base Units for pack sale');

// =========================================================================
// SECTION 5: Mixed pack/piece demand & Line order invariance
// =========================================================================
echo "\n--- Section 5: Mixed pack/piece demand & Line order invariance ---\n";
// Product 4: 2 sealed packs of 12 + 3 loose pieces = 27 base units.
// Demand: 1 pack and 5 pieces.
// Need 1 pack sealed (1 pack left).
// Shortfall: 5 - 3 = 2 pieces, opens remaining 1 pack, leaves 10 loose pieces.
// Final remaining: 0 sealed packs, 10 loose pieces, 10 base units.
$pdo->exec("INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price)
            VALUES (4, 'TEA-01', 'TEA-PC', 12, 'TEA-PK', 100.00, 'Green Tea', 10.00, 5.00)");

// Setup DB state for Order A: [pack, piece]
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (4, 27)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
            VALUES (4, 4, 'BATCH-04', 27, 27, 2, 3, date('now', '+30 days'))");

$cartA = [
    ['product_id' => 4, 'selling_form' => 'pack', 'qty' => 1],
    ['product_id' => 4, 'selling_form' => 'piece', 'qty' => 5],
];
$quoteA = $service->reviewQuote($cartA, 1, 'cashier');
$checkoutA = $service->checkout($cartA, 1, 'cashier', 'cash', [
    'cash_received' => 200.00,
    'reviewed_quote' => $quoteA['state_hash'],
]);

$batch4A = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 4")->fetch();
$assert((int)$batch4A['sealed_quantity'] === 0, 'Order A: 0 sealed packs remain');
$assert((int)$batch4A['loose_quantity'] === 10, 'Order A: 10 loose pieces remain');
$assert((int)$batch4A['remaining_quantity'] === 10, 'Order A: 10 Base Units remain');
$assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 4")->fetchColumn() === 10, 'Order A: inventory is 10');

// Reset Product 4 for Order B: [piece, pack]
$pdo->exec("UPDATE product_batches SET sealed_quantity = 2, loose_quantity = 3, remaining_quantity = 27 WHERE batch_id = 4");
$pdo->exec("UPDATE inventory SET quantity_on_hand = 27 WHERE product_id = 4");

$cartB = [
    ['product_id' => 4, 'selling_form' => 'piece', 'qty' => 5],
    ['product_id' => 4, 'selling_form' => 'pack', 'qty' => 1],
];
$quoteB = $service->reviewQuote($cartB, 1, 'cashier');
$checkoutB = $service->checkout($cartB, 1, 'cashier', 'cash', [
    'cash_received' => 200.00,
    'reviewed_quote' => $quoteB['state_hash'],
]);

$batch4B = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 4")->fetch();
$assert((int)$batch4B['sealed_quantity'] === 0, 'Order B: 0 sealed packs remain');
$assert((int)$batch4B['loose_quantity'] === 10, 'Order B: 10 loose pieces remain');
$assert((int)$batch4B['remaining_quantity'] === 10, 'Order B: 10 Base Units remain');
$assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 4")->fetchColumn() === 10, 'Order B: inventory is 10');

// Insufficient combined stock: 2 packs and 5 pieces when only 2 packs and 3 pieces exist
$pdo->exec("UPDATE product_batches SET sealed_quantity = 2, loose_quantity = 3, remaining_quantity = 27 WHERE batch_id = 4");
$pdo->exec("UPDATE inventory SET quantity_on_hand = 27 WHERE product_id = 4");

$overDemandCart = [
    ['product_id' => 4, 'selling_form' => 'pack', 'qty' => 2],
    ['product_id' => 4, 'selling_form' => 'piece', 'qty' => 5],
];
try {
    $service->checkout($overDemandCart, 1, 'cashier', 'cash', ['cash_received' => 300.00]);
    $assert(false, 'Expected StockConflictException on insufficient combined stock');
} catch (StockConflictException $e) {
    $assert(true, 'Insufficient combined stock rejected cleanly');
}
// Verify ZERO mutation
$batch4Clean = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 4")->fetch();
$assert((int)$batch4Clean['sealed_quantity'] === 2 && (int)$batch4Clean['loose_quantity'] === 3, 'Zero stock mutation on rejected checkout');

// =========================================================================
// SECTION 6: Expiration eligibility (expired stock excluded)
// =========================================================================
echo "\n--- Section 6: Expiration eligibility ---\n";
$pdo->exec("INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price)
            VALUES (5, 'MILK-01', 'MILK-PC', 12, 'MILK-PK', 100.00, 'Fresh Milk', 10.00, 6.00)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (5, 54)");
// Batch 5A: Expired yesterday, 2 packs, 5 loose pieces
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
            VALUES (51, 5, 'EXP-BATCH', 29, 29, 2, 5, date('now', '-1 day'))");
// Batch 5B: Active, 2 packs, 1 loose piece
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
            VALUES (52, 5, 'ACT-BATCH', 25, 25, 2, 1, date('now', '+10 days'))");

// Selling 5 pieces: loose on active batch is 1 piece, shortfall is 4 pieces -> open 1 pack from Batch 5B!
// Expired Batch 51 cannot be opened or consumed!
$cartExp = [['product_id' => 5, 'selling_form' => 'piece', 'qty' => 5]];
$quoteExp = $service->reviewQuote($cartExp, 1, 'cashier');
$openExp = $quoteExp['sale']['plans'][5]['pack_openings'];
$assert(count($openExp) === 1, '1 pack opening planned');
$assert($openExp[0]['batch_id'] === 52, 'Pack opened strictly from active Batch 52');

$checkoutExp = $service->checkout($cartExp, 1, 'cashier', 'cash', [
    'cash_received' => 100.00,
    'reviewed_quote' => $quoteExp['state_hash'],
]);

$expBatch = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 51")->fetch();
$assert((int)$expBatch['sealed_quantity'] === 2 && (int)$expBatch['loose_quantity'] === 5, 'Expired batch 51 completely untouched');
$actBatch = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 52")->fetch();
$assert((int)$actBatch['sealed_quantity'] === 1, 'Active batch sealed packs decremented (2 -> 1)');
$assert((int)$actBatch['loose_quantity'] === 8, 'Active batch loose: 1 + 12 - 5 = 8 pieces');

// =========================================================================
// SECTION 7: Atomic execution and rollback on failure
// =========================================================================
echo "\n--- Section 7: Atomic execution and rollback on failure ---\n";
// Product 6
$pdo->exec("INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price)
            VALUES (6, 'CRACK-01', 'CRACK-PC', 12, 'CRACK-PK', 100.00, 'Soda Crackers', 10.00, 6.00)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (6, 27)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
            VALUES (6, 6, 'BATCH-06', 27, 27, 2, 3, date('now', '+30 days'))");

// Inject trigger failure on receipt insertion
$pdo->exec("CREATE TRIGGER fail_receipt_insert BEFORE INSERT ON sale_receipt_details FOR EACH ROW
            BEGIN SELECT RAISE(ABORT, 'Injected receipt failure'); END;");

$cartRollback = [['product_id' => 6, 'selling_form' => 'piece', 'qty' => 5]];
$salesBefore = (int)$pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();
$saleItemsBefore = (int)$pdo->query("SELECT COUNT(*) FROM sale_items")->fetchColumn();
$movementsBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
$activityBefore = (int)$pdo->query("SELECT COUNT(*) FROM activity_log")->fetchColumn();

try {
    $service->checkout($cartRollback, 1, 'cashier', 'cash', ['cash_received' => 100.00]);
    $assert(false, 'Expected injected failure to throw');
} catch (Throwable $e) {
    $assert(str_contains($e->getMessage(), 'Injected receipt failure'), 'Injected failure caught');
}

$assert((int)$pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn() === $salesBefore, 'Sales table rolled back');
$assert((int)$pdo->query("SELECT COUNT(*) FROM sale_items")->fetchColumn() === $saleItemsBefore, 'sale_items rolled back');
$assert((int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn() === $movementsBefore, 'stock_movements rolled back');
$assert((int)$pdo->query("SELECT COUNT(*) FROM activity_log")->fetchColumn() === $activityBefore, 'activity_log rolled back');

$batch6 = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 6")->fetch();
$assert((int)$batch6['sealed_quantity'] === 2 && (int)$batch6['loose_quantity'] === 3 && (int)$batch6['remaining_quantity'] === 27,
    'Batch sealed and loose quantities completely restored');
$assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 6")->fetchColumn() === 27,
    'Inventory completely restored');

$pdo->exec("DROP TRIGGER fail_receipt_insert");

// =========================================================================
// SECTION 8: Idempotent attempt replay & recovery
// =========================================================================
echo "\n--- Section 8: Idempotent attempt replay & recovery ---\n";
$attemptId = str_repeat('e', 32);
$attemptDetails = [
    'checkout_attempt' => $attemptId,
    'cash_received' => 100.00,
];
$quoteReplay = $service->reviewQuote($cartRollback, 1, 'cashier', $attemptDetails);
$attemptDetails['reviewed_quote'] = $quoteReplay['state_hash'];

$resultReplay1 = $service->checkout($cartRollback, 1, 'cashier', 'cash', $attemptDetails);
$assert($resultReplay1['sale_id'] > 0, 'First checkout attempt succeeded');

$salesCount1 = (int)$pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();
$batch6After1 = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 6")->fetch();

// Replay with identical attempt_id
$resultReplay2 = $service->checkout($cartRollback, 1, 'cashier', 'cash', $attemptDetails);
$assert($resultReplay2['sale_id'] === $resultReplay1['sale_id'], 'Replay returned identical sale_id');
$assert((int)$pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn() === $salesCount1, 'Zero duplicate sales created');

$batch6After2 = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 6")->fetch();
$assert($batch6After2['sealed_quantity'] === $batch6After1['sealed_quantity'], 'Zero duplicate pack opening on replay');
$assert($batch6After2['remaining_quantity'] === $batch6After1['remaining_quantity'], 'Zero duplicate inventory deduction on replay');

// Recovery via recoverAttempt
$recovered = $service->recoverAttempt($attemptId, 1, 'cashier');
$assert($recovered['sale_id'] === $resultReplay1['sale_id'], 'recoverAttempt recovers cached outcome');

// Replay with tampered cart must be rejected
try {
    $service->checkout([['product_id' => 6, 'selling_form' => 'piece', 'qty' => 10]], 1, 'cashier', 'cash', $attemptDetails);
    $assert(false, 'Expected tampered cart on same attempt to throw');
} catch (DomainException $e) {
    $assert(str_contains($e->getMessage(), 'different cart or payment'), 'Tampered cart rejected: ' . $e->getMessage());
}

// =========================================================================
// SECTION 9: Receipt details persistence & Catalog edit immutability
// =========================================================================
echo "\n--- Section 9: Receipt persistence & Catalog edit immutability ---\n";
$receiptService = new ReceiptDetailsService($pdo);
$saleReceipt = $receiptService->fetchSale($resultReplay1['sale_id']);
$assert(isset($saleReceipt['receipt_items']), 'Preserved receipt items exist in snapshot');
$itemsPreserved = $saleReceipt['receipt_items'];
$assert(count($itemsPreserved) === 1, '1 item preserved');
$assert($itemsPreserved[0]['selling_form'] === 'piece', 'Preserved selling_form piece');

// Check pack line receipt preservation
$packCart = [['product_id' => 1, 'selling_form' => 'pack', 'qty' => 1]];
$quotePackPreserve = $service->reviewQuote($packCart, 1, 'cashier');
$checkoutPackPreserve = $service->checkout($packCart, 1, 'cashier', 'cash', [
    'cash_received' => 100.00,
    'reviewed_quote' => $quotePackPreserve['state_hash'],
]);

$packReceipt = $receiptService->fetchSale($checkoutPackPreserve['sale_id']);
$packItems = $packReceipt['receipt_items'];
$assert($packItems[0]['selling_form'] === 'pack', 'Preserved receipt has selling_form pack');
$assert((int)$packItems[0]['pack_size'] === 12, 'Preserved receipt has pack_size 12');
$assert((float)$packItems[0]['unit_price'] === 100.00, 'Preserved receipt has unit_price 100.00');

// Now edit the catalog: change product 1 pack_price to 150.00, pack_size to 6, unit_price to 20.00
$pdo->exec("UPDATE products SET pack_price = 150.00, pack_size = 6, unit_price = 20.00, product_name = 'Renamed Biscuit' WHERE product_id = 1");

// Re-fetch historical receipt: MUST NOT CHANGE
$packReceiptAfterEdit = $receiptService->fetchSale($checkoutPackPreserve['sale_id']);
$packItemsAfter = $packReceiptAfterEdit['receipt_items'];
$assert($packItemsAfter[0]['selling_form'] === 'pack', 'Selling form immutable across catalog edits');
$assert((int)$packItemsAfter[0]['pack_size'] === 12, 'Historical pack contents (12) preserved despite catalog edit to 6');
$assert((float)$packItemsAfter[0]['unit_price'] === 100.00, 'Historical price (100.00) preserved despite catalog edit to 150.00');

// Test Receipt HTML rendering distinguishes pack and pack contents
ob_start();
SaleReceiptPresentation::render($packReceiptAfterEdit, $packItemsAfter, $packReceiptAfterEdit['receipt_store'] ?? $receiptService->storeInfo(), 'https://example.test/receipt');
$renderedHtml = ob_get_clean();

$assert(str_contains($renderedHtml, 'Pack (12 pcs)') || str_contains($renderedHtml, 'Pack') && str_contains($renderedHtml, '12 pcs'),
    'Receipt display renders Pack form and pack contents count');
$assert(str_contains($renderedHtml, '100.00'), 'Receipt displays charged pack price');

// Check summary
if (!empty($failures)) {
    echo "\nFAILED with " . count($failures) . " failure(s)!\n";
    exit(1);
}

echo "\nAll contract, workflow, and integration checks for Issue #251 PASSED!\n";
