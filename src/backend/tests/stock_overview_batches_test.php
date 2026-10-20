<?php
// Contract and integration tests for Ticket #221:
// Stock Overview: Show On Hand, Expired Qty, Next Expiration; add Missing Expiration filter and Qty Without Date; View Batches modal with Return to Supplier action.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
require_once __DIR__ . '/../app/Services/PhilippineTime.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

echo "Starting Ticket #221 Stock Overview batches tests...\n";

// --- Database Setup ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('LEAST', static fn(...$values) => min($values));
$pdo->sqliteCreateFunction('GREATEST', static fn(...$values) => max($values));
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
$GLOBALS['pdo'] = $pdo;

$pdo->exec("CREATE TABLE branches (
    branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name TEXT NOT NULL,
    branch_code TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Store', 'MAIN', 'active')");

$pdo->exec("CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    username TEXT NOT NULL UNIQUE,
    role_id INTEGER NOT NULL DEFAULT 1,
    branch_id INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO users (user_id, full_name, username, role_id, branch_id) VALUES (1, 'Manager', 'manager1', 2, 1)");

$pdo->exec("CREATE TABLE categories (
    category_id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_name TEXT NOT NULL
)");
$pdo->exec("INSERT INTO categories (category_id, category_name) VALUES (1, 'Bakery'), (2, 'Dairy'), (3, 'Dry Goods')");

$pdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    sku TEXT NOT NULL,
    barcode TEXT NOT NULL DEFAULT '',
    case_barcode TEXT NOT NULL DEFAULT '',
    product_name TEXT NOT NULL,
    brand TEXT NOT NULL DEFAULT '',
    category_id INTEGER NOT NULL DEFAULT 1,
    unit_price REAL NOT NULL DEFAULT 10.0,
    cost_price REAL NOT NULL DEFAULT 5.0,
    reorder_level INTEGER NOT NULL DEFAULT 5,
    safety_stock INTEGER NOT NULL DEFAULT 2,
    minimum_order_quantity INTEGER NOT NULL DEFAULT 1,
    units_per_package INTEGER NOT NULL DEFAULT 1,
    quantity_sold INTEGER NOT NULL DEFAULT 0,
    quantity_purchased INTEGER NOT NULL DEFAULT 0,
    supplier TEXT NULL,
    preferred_supplier TEXT NULL,
    expiration_date TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    created_by INTEGER NULL
)");

$pdo->exec("CREATE TABLE inventory (
    product_id INTEGER PRIMARY KEY,
    quantity_on_hand INTEGER NOT NULL DEFAULT 0
)");

$pdo->exec("CREATE TABLE product_batches (
    batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    receiving_id INTEGER NULL,
    batch_number TEXT NULL,
    quantity INTEGER NOT NULL,
    remaining_quantity INTEGER NOT NULL,
    expiration_date TEXT NULL,
    date_received TEXT NOT NULL,
    supplier TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE stock_receiving (
    receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    accepted_qty INTEGER NOT NULL,
    supplier TEXT NULL,
    batch_number TEXT NULL,
    expiration_date TEXT NULL,
    discrepancy_type TEXT DEFAULT 'none',
    discrepancy_notes TEXT NULL,
    notes TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE inventory_count_batches (
    count_batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    count_id INTEGER NOT NULL,
    batch_id INTEGER NULL,
    system_quantity INTEGER NOT NULL DEFAULT 0,
    physical_quantity INTEGER NOT NULL DEFAULT 0,
    difference_qty INTEGER NOT NULL DEFAULT 0,
    expiration_date TEXT NULL,
    batch_number TEXT NULL,
    notes TEXT NULL
)");

// --- Fixtures ---
$today = '2026-10-18';

// Product 1: "Healthy & Expired Mix Bread"
// Old product-level expiration: 2024-01-01 (should be IGNORED)
// Batch 101: 20 remaining, expired (2026-10-10) -> Opening Stock
// Batch 102: 30 remaining, unexpired (2026-10-25) -> Received Delivery
// Batch 103: 0 remaining, depleted (2026-09-01)
// Total on hand: 50
$pdo->exec("INSERT INTO products (product_id, sku, product_name, category_id, expiration_date) VALUES (1, 'SKU-BRD-01', 'Artisan Bread', 1, '2024-01-01')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 50)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, receiving_id, batch_number, quantity, remaining_quantity, expiration_date, date_received) VALUES
    (101, 1, NULL, 'START-101', 20, 20, '2026-10-10', '2026-10-01'),
    (102, 1, 1, 'RCV-102', 30, 30, '2026-10-25', '2026-10-12'),
    (103, 1, 2, 'RCV-103', 15, 0, '2026-09-01', '2026-08-20')");
$pdo->exec("INSERT INTO stock_receiving (receiving_id, product_id, accepted_qty, notes) VALUES (1, 1, 30, 'Fresh delivery'), (2, 1, 15, 'Old delivery')");

// Product 2: "Mixed Dated and Undated Milk"
// Batch 201: 10 remaining, unexpired (2026-11-01)
// Batch 202: 15 remaining, NULL expiration (Undated)
// Total on hand: 25
$pdo->exec("INSERT INTO products (product_id, sku, product_name, category_id, expiration_date) VALUES (2, 'SKU-MLK-02', 'Fresh Milk', 2, NULL)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 25)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, receiving_id, batch_number, quantity, remaining_quantity, expiration_date, date_received) VALUES
    (201, 2, 3, 'RCV-201', 10, 10, '2026-11-01', '2026-10-15'),
    (202, 2, NULL, 'OPEN-202', 15, 15, NULL, '2026-10-01')");

// Product 3: "Out of Stock with Depleted Undated Batch"
// On hand: 0, Batch remaining: 0, expiration: NULL
$pdo->exec("INSERT INTO products (product_id, sku, product_name, category_id, expiration_date) VALUES (3, 'SKU-CER-03', 'Cereal Box', 3, NULL)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (3, 0)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, receiving_id, batch_number, quantity, remaining_quantity, expiration_date, date_received) VALUES
    (301, 3, NULL, 'DEP-301', 10, 0, NULL, '2026-09-01')");

// Product 4: "Depleted Batches Only"
// On hand: 0, Batch remaining: 0, expiration: 2026-10-01
$pdo->exec("INSERT INTO products (product_id, sku, product_name, category_id, expiration_date) VALUES (4, 'SKU-JUC-04', 'Orange Juice', 2, NULL)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (4, 0)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, receiving_id, batch_number, quantity, remaining_quantity, expiration_date, date_received) VALUES
    (401, 4, 4, 'DEP-401', 5, 0, '2026-10-01', '2026-09-10'),
    (402, 4, NULL, 'DEP-402', 5, 0, NULL, '2026-09-10')");

// Product 5: "Legacy Unbatched Stock"
// On hand: 12, 0 batch rows in product_batches
$pdo->exec("INSERT INTO products (product_id, sku, product_name, category_id, expiration_date) VALUES (5, 'SKU-SOP-05', 'Bar Soap', 3, NULL)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (5, 12)");

// Product 6: "Partially Unbatched Stock"
// On hand: 20, Batch 601 remaining: 14 -> 6 unbatched
$pdo->exec("INSERT INTO products (product_id, sku, product_name, category_id, expiration_date) VALUES (6, 'SKU-RIC-06', 'Jasmine Rice', 3, NULL)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (6, 20)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, receiving_id, batch_number, quantity, remaining_quantity, expiration_date, date_received) VALUES
    (601, 6, 5, 'RCV-601', 14, 14, '2026-12-15', '2026-10-10')");

// Product 7: "Special Sources & Pullout Demo"
// Batch 701: 10 remaining, unexpired (2026-11-10), receiving notes indicate 'replacement' -> Source: Replacement
// Batch 702: 12 remaining, unexpired (2026-11-20), batch_number 'COUNT-CORR-702' -> Source: Count Correction
// Batch 703: 8 remaining, expired (2026-10-05) -> Status: Expired, has return_to_supplier_url
// Batch 704: 0 remaining, depleted (2026-09-01) -> Status: Depleted, return_to_supplier_url is null
// Total on hand: 30
$pdo->exec("INSERT INTO products (product_id, sku, product_name, category_id, expiration_date) VALUES (7, 'SKU-SRC-07', 'Special Sauce', 3, '2026-10-01')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (7, 30)");
$pdo->exec("INSERT INTO stock_receiving (receiving_id, product_id, accepted_qty, discrepancy_type, notes) VALUES (6, 7, 10, 'replacement', 'Supplier replacement delivery')");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, receiving_id, batch_number, quantity, remaining_quantity, expiration_date, date_received) VALUES
    (701, 7, 6, 'RCV-REPLACE-701', 10, 10, '2026-11-10', '2026-10-14'),
    (702, 7, NULL, 'COUNT-CORR-702', 12, 12, '2026-11-20', '2026-10-12'),
    (703, 7, NULL, 'EXP-703', 8, 8, '2026-10-05', '2026-09-20'),
    (704, 7, NULL, 'DEP-704', 5, 0, '2026-09-01', '2026-08-15')");
$pdo->exec("INSERT INTO inventory_count_batches (count_id, batch_id, physical_quantity, difference_qty) VALUES (1, 702, 12, 2)");

$inventoryService = new InventoryService($pdo);

// --- Test 1: One row per product with batch summaries ---
echo "\nTest 1: One row per product and batch summaries\n";
$overviewProducts = $inventoryService->getProductsWithBatchSummaries($today);
$assert(count($overviewProducts) === 7, 'Overview returns exactly 7 products (one row per product)');

$bySku = [];
foreach ($overviewProducts as $op) {
    $bySku[$op['sku']] = $op;
}

// --- Test 2: Next Expiration selects earliest eligible date among unexpired batches (ignores product-level date) ---
echo "\nTest 2: Earliest eligible date among unexpired batches\n";
$bread = $bySku['SKU-BRD-01'];
$assert((int)$bread['quantity_on_hand'] === 50, 'Bread on-hand is 50');
$assert((int)$bread['expired_quantity'] === 20, 'Bread expired quantity is 20');
$assert($bread['next_expiration'] === '2026-10-25', 'Bread next expiration is 2026-10-25 (earliest unexpired batch date)');
$assert($bread['next_expiration'] !== '2024-01-01', 'Bread ignores old product-level expiration 2024-01-01');

// --- Test 3: Product with both healthy and expired batches retains Expired Qty alongside On Hand ---
echo "\nTest 3: Retains Expired Qty alongside On Hand\n";
$assert((int)$bread['quantity_on_hand'] === 50, 'Retains total On Hand (50) including expired stock');
$assert((int)$bread['expired_quantity'] === 20, 'Retains separate Expired Qty (20) alongside On Hand');

// --- Test 4: Missing Expiration filter matches products with undated stock (even when mixed with dated batches) ---
echo "\nTest 4: Missing Expiration filter matches undated stock\n";
$milk = $bySku['SKU-MLK-02'];
$assert($milk['has_missing_expiration'] === true, 'Milk with mixed dated/undated stock has_missing_expiration = true');
$assert((int)$milk['undated_quantity'] === 15, 'Qty Without Date accurately reflects 15 undated units');
$assert($milk['next_expiration'] === '2026-11-01', 'Milk next expiration is 2026-11-01 for its dated batch');

// --- Test 5: Out of stock and depleted batches excluded from Missing Expiration filter ---
echo "\nTest 5: Out of stock and depleted batches excluded\n";
$cereal = $bySku['SKU-CER-03'];
$juice = $bySku['SKU-JUC-04'];
$assert($cereal['has_missing_expiration'] === false, 'Out-of-stock product with depleted undated batch has_missing_expiration = false');
$assert($juice['has_missing_expiration'] === false, 'Out-of-stock product with depleted batches has_missing_expiration = false');
$assert($juice['next_expiration'] === null, 'Depleted batches produce null next expiration');

// --- Test 6: Legacy unbatched stock identified as reconciliation exception ---
echo "\nTest 6: Legacy unbatched stock identified as reconciliation exception\n";
$soap = $bySku['SKU-SOP-05'];
$rice = $bySku['SKU-RIC-06'];
$assert($soap['has_unbatched_stock'] === true, 'Soap with zero batches has_unbatched_stock = true');
$assert((int)$soap['unbatched_quantity'] === 12, 'Soap unbatched quantity is 12');
$assert($rice['has_unbatched_stock'] === true, 'Rice with partial batch coverage has_unbatched_stock = true');
$assert((int)$rice['unbatched_quantity'] === 6, 'Rice unbatched quantity is 6 (20 on hand - 14 in batches)');

// --- Test 7: View Batches returns batches with source, remaining quantity, expiration, status, and Return to Supplier link ---
echo "\nTest 7: View Batches details and actions\n";
$batches = $inventoryService->getProductBatches(7, $today);
$assert(count($batches) === 4, 'Product 7 has exactly 4 batches');

// Order check: dated unexpired first by expiration ASC, then expired with remaining, then depleted
$assert((int)$batches[0]['batch_id'] === 701, 'First batch is earliest unexpired (701, 2026-11-10)');
$assert((int)$batches[1]['batch_id'] === 702, 'Second batch is next unexpired (702, 2026-11-20)');
$assert((int)$batches[2]['batch_id'] === 703, 'Third batch is expired with positive remaining (703, 2026-10-05)');
$assert((int)$batches[3]['batch_id'] === 704, 'Fourth batch is depleted (704, remaining = 0)');

// Source attribution check
$assert($batches[0]['source'] === 'Replacement', 'Batch 701 source is Replacement');
$assert($batches[1]['source'] === 'Count Correction', 'Batch 702 source is Count Correction');
$breadBatches = $inventoryService->getProductBatches(1, $today);
$b101 = array_values(array_filter($breadBatches, fn($b) => $b['batch_id'] === 101))[0];
$b102 = array_values(array_filter($breadBatches, fn($b) => $b['batch_id'] === 102))[0];
$assert($b101['source'] === 'Opening Stock', 'Batch 101 source is Opening Stock');
$assert($b102['source'] === 'Received Delivery', 'Batch 102 source is Received Delivery');

// Status check
$assert($batches[0]['status'] === 'Active', 'Batch 701 status is Active');
$assert($batches[1]['status'] === 'Active', 'Batch 702 status is Active');
$assert($batches[2]['status'] === 'Expired', 'Batch 703 status is Expired');
$assert($batches[3]['status'] === 'Depleted', 'Batch 704 status is Depleted');

// Days remaining check (today = 2026-10-18)
$assert($batches[0]['days_remaining'] === 23, 'Batch 701 (exp 2026-11-10) days remaining is 23');
$assert($batches[2]['days_remaining'] === -13, 'Batch 703 (exp 2026-10-05) days remaining is -13');

// Return to Supplier link check
$assert(!empty($batches[0]['return_to_supplier_url']), 'Active batch 701 has Return to Supplier link');
$assert(str_contains($batches[0]['return_to_supplier_url'], 'product_id=7'), 'Return link contains product_id=7');
$assert(str_contains($batches[0]['return_to_supplier_url'], 'batch_id=701'), 'Return link contains batch_id=701');
$assert(!empty($batches[2]['return_to_supplier_url']), 'Expired positive-remaining batch 703 has Return to Supplier link');
$assert($batches[3]['return_to_supplier_url'] === null, 'Depleted batch 704 has NO Return to Supplier link (null)');

// --- Test 8: No eligible dated batch displays null and falls back to "—" ---
echo "\nTest 8: No eligible dated batch\n";
// Product with only expired stock
$pdo->exec("INSERT INTO products (product_id, sku, product_name, category_id, expiration_date) VALUES (8, 'SKU-EXP-08', 'Expired Pastry', 1, '2026-12-31')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (8, 5)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, receiving_id, batch_number, quantity, remaining_quantity, expiration_date, date_received) VALUES
    (801, 8, NULL, 'EXP-801', 5, 5, '2026-10-01', '2026-09-15')");
$p8Summary = array_values(array_filter($inventoryService->getProductsWithBatchSummaries($today), fn($p) => $p['product_id'] === 8))[0];
$assert($p8Summary['next_expiration'] === null, 'Product 8 with only expired batches has next_expiration = null (never displays product-level 2026-12-31)');
$assert((int)$p8Summary['expired_quantity'] === 5, 'Product 8 expired quantity is 5');

// --- Final Results ---
if ($failures) {
    fwrite(STDERR, "\nStock overview batches tests failed with " . count($failures) . " failure(s):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nAll Ticket #221 Stock Overview batches tests passed successfully!\n";
