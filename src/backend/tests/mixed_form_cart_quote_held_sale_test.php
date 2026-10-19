<?php
// src/backend/tests/mixed_form_cart_quote_held_sale_test.php
//
// Verification suite for Issue #250:
// "Build mixed-form carts, quotes, and held sales"
//
// Seams tested:
// 1. SalesWorkflowService (sanitizing, fingerprinting, quote building, FEFO combined allocation, reviewQuote)
// 2. HeldSaleService (holding, reviewing, resuming, discarding mixed-form carts)
// 3. POS scan lookup & form selection contract

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Services/HeldSaleService.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/ProductCodeLookupService.php';

use App\Services\CashierShiftService;
use App\Services\HeldSaleService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  ok - {$message}\n";
};

echo "Starting tests for Issue #250: Mixed-form carts, quotes, and held sales...\n";

function createTestPdo(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

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
            branch_id INTEGER,
            status TEXT DEFAULT 'active'
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
            user_id INTEGER,
            branch_id INTEGER DEFAULT 1,
            shift_id INTEGER,
            total_amount REAL,
            net_total REAL,
            payment_method TEXT,
            cash_received REAL NULL,
            change_due REAL NULL,
            discount_type TEXT DEFAULT 'none',
            discount_value REAL DEFAULT 0,
            discount_amount REAL DEFAULT 0,
            discount_reason TEXT NULL,
            discount_authorized_by INTEGER NULL,
            sale_date TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE sale_items (
            sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER,
            product_id INTEGER,
            quantity INTEGER,
            unit_price REAL,
            subtotal REAL
        );
        CREATE TABLE sale_item_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_item_id INTEGER,
            batch_id INTEGER,
            quantity INTEGER,
            unit_cost REAL NULL,
            cost_price REAL NULL,
            cost_status TEXT DEFAULT 'missing',
            cost_source TEXT NULL,
            cost_provenance TEXT NULL
        );
        CREATE TABLE promotions (
            promotion_id INTEGER PRIMARY KEY AUTOINCREMENT,
            promotion_name TEXT,
            discount_type TEXT,
            discount_value REAL,
            scope TEXT,
            starts_at TEXT,
            ends_at TEXT,
            created_by INTEGER,
            status TEXT DEFAULT 'active'
        );
        CREATE TABLE checkout_attempts (
            cashier_id INTEGER,
            attempt_id TEXT,
            request_hash TEXT,
            sale_id INTEGER NULL,
            result_json TEXT,
            PRIMARY KEY (cashier_id, attempt_id)
        );
        CREATE TABLE held_sales (
            held_sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER,
            shift_id INTEGER,
            reference_no TEXT,
            customer_label TEXT NULL,
            cart_json TEXT,
            item_count INTEGER,
            total_amount REAL,
            status TEXT DEFAULT 'held',
            expires_at TEXT NULL,
            resolved_at TEXT NULL,
            discard_reason TEXT NULL,
            discard_note TEXT NULL,
            discarded_by INTEGER NULL,
            sale_id INTEGER NULL,
            created_at TEXT DEFAULT (datetime('now'))
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
        CREATE TABLE activity_log (
            log_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT,
            category TEXT,
            module TEXT,
            record_id INTEGER NULL,
            previous_value TEXT NULL,
            new_value TEXT NULL,
            ip_address TEXT NULL,
            metadata TEXT NULL,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            change_qty INTEGER,
            reason TEXT,
            moved_by INTEGER,
            created_at TEXT DEFAULT (datetime('now'))
        );
    ");

    $pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code) VALUES (1, 'Main Store', 'MAIN')");
    $pdo->exec("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id) VALUES (1, 'Cashier User', 'cashier', 'hash', 1, 1)");
    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (1, 'Till 1')");
    $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash) VALUES (1, 1, 1, 500)");
    $pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2026-01-01', '2026-12-31', 'open')");

    return $pdo;
}

// =========================================================================
// SECTION 1: Cart Identity & Distinct Line Items by Product and Selling Form
// =========================================================================
echo "\n--- Section 1: Distinct line items & selling form defaults ---\n";
$pdo = createTestPdo();
$service = new SalesWorkflowService($pdo);

// Create Biscuit: piece barcode 'BIS-PC', pack barcode 'BIS-PK', pack_size=12, unit_price=10.0, pack_price=100.0
$pdo->exec("INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price)
            VALUES (1, 'BIS-SKU', 'BIS-PC', 12, 'BIS-PK', 100.00, 'Chocolate Biscuit', 10.00, 7.00)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 63)"); // 5 packs of 12 + 3 loose = 63
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
            VALUES (1, 'BATCH-1', 63, 63, 5, 3, '2026-12-31')");

// Test 1.1: Cart with missing selling_form defaults to 'piece'
$cartMissingForm = [['product_id' => 1, 'qty' => 3]];
$quote = $service->reviewQuote($cartMissingForm, 1, 'cashier');
$assert(count($quote['sale']['items']) === 1, 'Single item in sale');
$assert($quote['sale']['items'][0]['selling_form'] === 'piece', 'Missing form defaults to piece');
$assert($quote['sale']['items'][0]['unit_price'] === 10.00, 'Piece price applied for default piece form');
$assert($quote['sale']['items'][0]['subtotal'] === 30.00, 'Subtotal is 3 * 10 = 30.00');
$assert($quote['sale']['items'][0]['base_unit_quantity'] === 3, 'Base unit quantity is 3');

// Test 1.2: Mixed cart: 1 pack AND 3 pieces of the SAME product
$mixedCart = [
    ['product_id' => 1, 'selling_form' => 'pack', 'qty' => 1],
    ['product_id' => 1, 'selling_form' => 'piece', 'qty' => 3],
];
$quote = $service->reviewQuote($mixedCart, 1, 'cashier');
$assert(count($quote['sale']['items']) === 2, 'Mixed cart maintains 2 distinct lines for same Product');
$assert($quote['total'] === 130.00, 'Total is 100.00 (pack) + 30.00 (pieces) = 130.00');

// Test 1.3: Quote fingerprint distinguishes between 1 pack vs 1 piece
$attemptA = $service->attemptFingerprintForTest([['product_id' => 1, 'selling_form' => 'pack', 'qty' => 1]], 'cash', []);
$attemptB = $service->attemptFingerprintForTest([['product_id' => 1, 'selling_form' => 'piece', 'qty' => 1]], 'cash', []);
$assert($attemptA !== $attemptB, 'Quote fingerprint differs between pack and piece of the same product');

// Test 1.4: 12 loose pieces retain piece pricing (120.00), not automatic pack pricing
$twelvePiecesCart = [['product_id' => 1, 'selling_form' => 'piece', 'qty' => 12]];
$quoteTwelve = $service->reviewQuote($twelvePiecesCart, 1, 'cashier');
$assert($quoteTwelve['sale']['items'][0]['unit_price'] === 10.00, '12 pieces retain 10.00 unit price');
$assert($quoteTwelve['sale']['items'][0]['subtotal'] === 120.00, '12 pieces total 120.00, not pack price of 100.00');

// =========================================================================
// SECTION 2: Server Validation (Form, Quantity, Pack Existence)
// =========================================================================
echo "\n--- Section 2: Server validation & configuration guards ---\n";

// Test 2.1: Invalid selling_form rejected
$invalidFormCart = [['product_id' => 1, 'selling_form' => 'case', 'qty' => 1]];
try {
    $service->reviewQuote($invalidFormCart, 1, 'cashier');
    $assert(false, 'Expected refusal for invalid selling form');
} catch (DomainException $e) {
    $assert(str_contains($e->getMessage(), 'form') || str_contains($e->getMessage(), 'piece or pack'), 'Invalid selling form rejected');
}

// Test 2.2: Non-integer / negative quantity rejected
try {
    $service->reviewQuote([['product_id' => 1, 'selling_form' => 'pack', 'qty' => 0]], 1, 'cashier');
    $assert(false, 'Expected refusal for zero quantity');
} catch (DomainException $e) {
    $assert(str_contains($e->getMessage(), 'positive whole number'), 'Zero quantity rejected');
}

try {
    $service->reviewQuote([['product_id' => 1, 'selling_form' => 'pack', 'qty' => -2]], 1, 'cashier');
    $assert(false, 'Expected refusal for negative quantity');
} catch (DomainException $e) {
    $assert(str_contains($e->getMessage(), 'positive whole number'), 'Negative quantity rejected');
}

// Test 2.3: Ordinary product sold without pack configuration cannot be quoted as 'pack'
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price)
            VALUES (2, 'WATER-SKU', 'WATER-PC', 'Mineral Water 500ml', 15.00)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 20)");
$pdo->exec("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity)
            VALUES (2, 'BATCH-W', 20, 20, 0, 20)");

try {
    $service->reviewQuote([['product_id' => 2, 'selling_form' => 'pack', 'qty' => 1]], 1, 'cashier');
    $assert(false, 'Expected refusal for pack request on product without sales pack');
} catch (StockConflictException | DomainException $e) {
    $assert(true, 'Pack request refused on product without sales pack configuration');
}

// =========================================================================
// SECTION 3: Combined Demand Planning (Sealed first, Loose first for piece, Automatic Pack Opening)
// =========================================================================
echo "\n--- Section 3: Combined demand planning & automatic pack opening ---\n";

// Setup Scenario: Biscuit starts with:
// Batch 1: expiration 2026-11-01, 2 sealed packs (12 ea), 3 loose pieces (total 27 pieces)
// Batch 2: expiration 2026-12-01, 3 sealed packs (12 ea), 0 loose pieces (total 36 pieces)
$pdo = createTestPdo();
$service = new SalesWorkflowService($pdo);
$pdo->exec("INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price)
            VALUES (1, 'BIS-SKU', 'BIS-PC', 12, 'BIS-PK', 100.00, 'Chocolate Biscuit', 10.00, 7.00)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 63)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date, date_received)
            VALUES (1, 1, 'BATCH-EARLY', 27, 27, 2, 3, '2026-11-01', '2026-10-01 10:00:00'),
                   (2, 1, 'BATCH-LATER', 36, 36, 3, 0, '2026-12-01', '2026-10-02 10:00:00')");

// Test 3.1: Piece sale of 5 pieces.
// Available loose in Batch 1 is 3 pieces.
// Shortfall is 2 pieces -> minimum 1 pack must be opened from Batch 1 (FEFO).
// 1 pack of 12 opened: 2 pieces consumed, 10 leftover loose pieces in Batch 1.
// Remaining in Batch 1 after sale would be: 1 sealed pack, 10 loose pieces = 22 base units.
$quotePieces = $service->reviewQuote([['product_id' => 1, 'selling_form' => 'piece', 'qty' => 5]], 1, 'cashier');
$plan = $quotePieces['sale']['plans'][1] ?? $quotePieces['sale']['items'][0]['product_plan'];
$assert(count($plan['pack_openings']) === 1, 'Exactly 1 pack opening planned');
$assert($plan['pack_openings'][0]['batch_id'] === 1, 'Pack opened from earliest eligible batch (Batch 1)');
$assert($plan['pack_openings'][0]['packs'] === 1, 'Exactly 1 pack opened');
$assert($plan['pack_openings'][0]['pieces_consumed'] === 2, '2 pieces consumed from opened pack');
$assert($plan['pack_openings'][0]['leftover_loose'] === 10, '10 leftover loose pieces retained');

// Test 3.2: Reversing cart line order produces identical combined allocation
// Mixed cart: Line A: 1 pack, Line B: 5 pieces
// Total demand: 1 pack + 5 pieces.
// Step 1: Allocate requested sealed pack: 1 pack from Batch 1 (leaving 1 pack in Batch 1).
// Step 2: Allocate loose pieces: 3 loose pieces from Batch 1. Shortfall = 2 pieces.
// Step 3: Open 1 pack: 1 pack remaining in Batch 1 opened -> 12 pieces, 2 consumed, 10 leftover.
$cartOrder1 = [
    ['product_id' => 1, 'selling_form' => 'pack', 'qty' => 1],
    ['product_id' => 1, 'selling_form' => 'piece', 'qty' => 5],
];
$cartOrder2 = [
    ['product_id' => 1, 'selling_form' => 'piece', 'qty' => 5],
    ['product_id' => 1, 'selling_form' => 'pack', 'qty' => 1],
];
$quote1 = $service->reviewQuote($cartOrder1, 1, 'cashier');
$quote2 = $service->reviewQuote($cartOrder2, 1, 'cashier');
$assert($quote1['total'] === $quote2['total'], 'Totals agree regardless of line order');
$plan1 = $quote1['sale']['plans'][1] ?? $quote1['sale']['items'][0]['product_plan'];
$plan2 = $quote2['sale']['plans'][1] ?? $quote2['sale']['items'][0]['product_plan'];
$assert($plan1['sealed_allocations'] === $plan2['sealed_allocations'], 'Sealed allocations identical regardless of line order');
$assert($plan1['pack_openings'] === $plan2['pack_openings'], 'Pack openings identical regardless of line order');

// Test 3.3: Request a sealed pack when only loose stock exists -> Refused!
$pdo->exec("UPDATE product_batches SET sealed_quantity = 0, loose_quantity = remaining_quantity WHERE product_id = 1");
try {
    $service->reviewQuote([['product_id' => 1, 'selling_form' => 'pack', 'qty' => 1]], 1, 'cashier');
    $assert(false, 'Expected refusal for pack request when only loose stock exists');
} catch (StockConflictException $e) {
    $assert(true, 'Only loose pieces cannot satisfy a pack quote');
}

// Test 3.4: Zero stock mutation during quoting/review
$stockBefore = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn();
$batchesBefore = $pdo->query('SELECT batch_id, remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE product_id = 1')->fetchAll();
$service->reviewQuote([['product_id' => 1, 'selling_form' => 'piece', 'qty' => 5]], 1, 'cashier');
$stockAfter = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn();
$batchesAfter = $pdo->query('SELECT batch_id, remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE product_id = 1')->fetchAll();
$assert($stockBefore === $stockAfter, 'Inventory stock unchanged by review/quote');
$assert($batchesBefore === $batchesAfter, 'Batches completely unchanged by review/quote (zero mutation)');

// =========================================================================
// SECTION 4: FEFO Expiration Eligibility (ADR-0013)
// =========================================================================
echo "\n--- Section 4: ADR-0013 Expiration eligibility ---\n";
// Setup: Batch 1 expired yesterday (2026-10-19): 5 sealed packs
// Batch 2 active (2026-11-30): 1 sealed pack, 2 loose
$pdo = createTestPdo();
$service = new SalesWorkflowService($pdo);
$pdo->exec("INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price)
            VALUES (1, 'EXP-SKU', 'EXP-PC', 10, 'EXP-PK', 90.00, 'Expiring Biscuit', 10.00)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 62)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
            VALUES (1, 1, 'EXPIRED-BATCH', 50, 50, 5, 0, '2026-10-19'),
                   (2, 1, 'ACTIVE-BATCH', 12, 12, 1, 2, '2026-11-30')");

// Test 4.1: Requesting 2 packs: only 1 active pack exists. Expired packs cannot be sold!
try {
    $service->reviewQuote([['product_id' => 1, 'selling_form' => 'pack', 'qty' => 2]], 1, 'cashier');
    $assert(false, 'Expected refusal when eligible sealed packs insufficient');
} catch (StockConflictException $e) {
    $assert(true, 'Expired sealed packs cannot satisfy pack quote');
}

// Test 4.2: Requesting 1 pack succeeds from active batch (Batch 2)
$expQuote = $service->reviewQuote([['product_id' => 1, 'selling_form' => 'pack', 'qty' => 1]], 1, 'cashier');
$plan = $expQuote['sale']['plans'][1] ?? $expQuote['sale']['items'][0]['product_plan'];
$assert($plan['sealed_allocations'][0]['batch_id'] === 2, 'Sealed pack allocated strictly from active batch');

// =========================================================================
// SECTION 5: Held & Resumed Sales with Mixed Forms
// =========================================================================
echo "\n--- Section 5: Held & Resumed sales with mixed forms ---\n";
$pdo = createTestPdo();
$shifts = new CashierShiftService($pdo);
$heldService = new HeldSaleService($pdo, $shifts);

$pdo->exec("INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price)
            VALUES (1, 'BIS-SKU', 'BIS-PC', 12, 'BIS-PK', 100.00, 'Chocolate Biscuit', 10.00)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 36)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity)
            VALUES (1, 1, 'BATCH-1', 36, 36, 2, 12)");

// Mixed cart in POS format
$heldCart = [
    '1:pack' => ['product_id' => 1, 'selling_form' => 'pack', 'qty' => 1, 'price' => 100.00, 'name' => 'Chocolate Biscuit (Pack)'],
    '1:piece' => ['product_id' => 1, 'selling_form' => 'piece', 'qty' => 3, 'price' => 10.00, 'name' => 'Chocolate Biscuit'],
];

// Test 5.1: Hold mixed-form cart
$holdResult = $heldService->hold(1, 'cashier', $heldCart, 'Customer A');
$heldSaleId = $holdResult['held_sale_id'];
$assert($heldSaleId > 0, 'Held sale created successfully');

// Verify stored json in DB preserves selling form, pack contents count, unit price, quantity, and line distinction
$row = $pdo->query("SELECT * FROM held_sales WHERE held_sale_id = {$heldSaleId}")->fetch(PDO::FETCH_ASSOC);
$storedCart = json_decode((string)$row['cart_json'], true);
$assert(count($storedCart) === 2, 'Stored cart retains 2 distinct lines');
$assert($storedCart['1:pack']['selling_form'] === 'pack', 'Preserved selling_form pack');
$assert($storedCart['1:pack']['pack_size'] === 12, 'Preserved pack_size 12');
$assert((float)$storedCart['1:pack']['price'] === 100.00, 'Preserved pack price 100.00');
$assert($storedCart['1:piece']['selling_form'] === 'piece', 'Preserved selling_form piece');
$assert((float)$storedCart['1:piece']['price'] === 10.00, 'Preserved piece price 10.00');
$assert((float)$row['total_amount'] === 130.00, 'Stored total amount is 130.00');

// Test 5.2: Resume held sale preserves forms and lines
$resumed = $heldService->resume(1, 'cashier', $heldSaleId);
$assert(count($resumed['cart']) === 2, 'Resumed cart has 2 distinct lines');
$assert($resumed['cart']['1:pack']['selling_form'] === 'pack', 'Resumed pack line form preserved');
$assert($resumed['cart']['1:piece']['selling_form'] === 'piece', 'Resumed piece line form preserved');

// Test 5.3: Held sale review detects catalog pack price change
$pdo->exec("UPDATE products SET pack_price = 110.00 WHERE product_id = 1");
$review = $heldService->reviewCart($resumed['cart']);
$assert((float)$review['cart']['1:pack']['price'] === 110.00, 'Review proposal updated to new pack price (110.00)');
$changesText = implode(' ', $review['changes']);
$assert(str_contains($changesText, 'price changed from PHP 100.00 to PHP 110.00'), 'Reported exact old and new pack price change');

// Test 5.4: Held sale review detects pack configuration removal
$pdo->exec("UPDATE products SET pack_size = NULL, pack_barcode = NULL, pack_price = NULL WHERE product_id = 1");
$reviewNoPack = $heldService->reviewCart($resumed['cart']);
$assert(!isset($reviewNoPack['cart']['1:pack']), 'Pack line proposed for removal when pack configuration is removed');
$assert(isset($reviewNoPack['cart']['1:piece']), 'Piece line remains active');

// Test 5.5: Discarding held sale leaves stock untouched
$heldService->discard(1, 'cashier', $heldSaleId, 'customer_cancelled');
$discardedRow = $pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$heldSaleId}")->fetch(PDO::FETCH_ASSOC);
$assert($discardedRow['status'] === 'discarded', 'Held sale marked discarded');
$stockUntouched = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn();
$assert($stockUntouched === 36, 'Discarding held sale leaves stock completely unchanged');

echo "\nAll contract and integration checks for Issue #250 PASSED!\n";
