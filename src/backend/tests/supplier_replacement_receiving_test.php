<?php
// Contract and integration test for Ticket #225: Receive replacements linked to Supplier Pullouts.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';
require_once __DIR__ . '/../app/Services/SupplierPulloutService.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
require_once __DIR__ . '/../app/Services/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Store/StoreWriteGate.php';

use App\Authorization\RoleCapabilityPolicy;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

$throws = static function (callable $operation, string $expectedMessagePart, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, "{$label} (expected exception, none thrown)");
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $matches = str_contains($msg, $expectedMessagePart);
        $cls = get_class($e);
        $assert($matches, "{$label} (threw {$cls}: {$msg})");
    }
};

echo "Starting Ticket #225 supplier replacement receiving tests...\n";

// --- Database Setup (SQLite in-memory with MySQL compatibility functions) ---
class ReplacementSqlitePdo extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query), $options);
    }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return parent::query(preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query), ...$fetchModeArgs);
    }
}

$pdo = new ReplacementSqlitePdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo'] = $pdo;

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

// Create schema
$pdo->exec("
    CREATE TABLE branches (
        branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_name TEXT NOT NULL UNIQUE,
        branch_code TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT 'active'
    );
    INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Store', 'MAIN', 'active');

    CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE);
    INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin');

    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NULL,
        role_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'active'
    );
    INSERT INTO users (user_id, full_name, username, role_id, branch_id, status)
    VALUES (1, 'Manager User', 'mgr1', 2, 1, 'active'),
           (2, 'Admin User', 'admin1', 3, 1, 'active');

    CREATE TABLE categories (category_id INTEGER PRIMARY KEY AUTOINCREMENT, category_name TEXT NOT NULL);
    INSERT INTO categories (category_id, category_name) VALUES (1, 'Bakery');

    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL DEFAULT 1,
        sku TEXT NOT NULL,
        barcode TEXT NOT NULL,
        case_barcode TEXT NULL,
        product_name TEXT NOT NULL,
        category_id INTEGER NULL,
        units_per_package INTEGER NOT NULL DEFAULT 1,
        base_unit TEXT NOT NULL DEFAULT 'piece',
        receiving_unit TEXT NOT NULL DEFAULT 'package',
        cost_price REAL NOT NULL DEFAULT 0.0,
        quantity_purchased INTEGER NOT NULL DEFAULT 0,
        reorder_level INTEGER NOT NULL DEFAULT 5,
        safety_stock INTEGER NOT NULL DEFAULT 0,
        variant_label TEXT NULL,
        product_image TEXT NULL,
        expiration_date TEXT NULL,
        warning_lead_time_days INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'active'
    );

    CREATE TABLE inventory (
        inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL UNIQUE,
        quantity_on_hand INTEGER NOT NULL DEFAULT 0,
        reorder_level INTEGER NOT NULL DEFAULT 5,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        receiving_id INTEGER NULL,
        batch_number TEXT NULL,
        quantity INTEGER NOT NULL,
        remaining_quantity INTEGER NOT NULL,
        expiration_date TEXT NULL,
        date_received TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT NULL
    );

    CREATE TABLE stock_receiving_groups (
        group_id INTEGER PRIMARY KEY AUTOINCREMENT,
        received_by INTEGER NOT NULL,
        supplier TEXT NULL,
        invoice_number TEXT NULL,
        notes TEXT NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE stock_receiving (
        receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER NULL,
        pullout_id INTEGER NULL,
        product_id INTEGER NOT NULL,
        received_qty INTEGER NOT NULL,
        received_packages INTEGER NOT NULL DEFAULT 0,
        units_per_package_used INTEGER NOT NULL DEFAULT 1,
        accepted_qty INTEGER NOT NULL,
        damaged_qty INTEGER NOT NULL DEFAULT 0,
        cost_price REAL NULL,
        total_cost REAL NOT NULL DEFAULT 0.0,
        received_by INTEGER NOT NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT NULL,
        invoice_number TEXT NULL,
        batch_number TEXT NULL,
        expiration_date TEXT NULL,
        discrepancy_type TEXT NOT NULL DEFAULT 'none',
        discrepancy_qty INTEGER NOT NULL DEFAULT 0,
        discrepancy_notes TEXT NULL,
        notes TEXT NULL
    );

    CREATE TABLE stock_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        change_qty INTEGER NOT NULL,
        reason TEXT NOT NULL,
        moved_by INTEGER NULL,
        adjustment_id INTEGER NULL,
        pullout_id INTEGER NULL,
        moved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE supplier_pullouts (
        pullout_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        batch_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        reason TEXT NOT NULL,
        supplier TEXT NULL,
        pulled_by INTEGER NOT NULL,
        pulled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        notes TEXT NULL
    );

    CREATE TABLE purchase_history (
        history_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        supplier TEXT NULL,
        quantity_purchased INTEGER NOT NULL,
        cost_price REAL NOT NULL,
        total_cost REAL NOT NULL,
        recorded_by INTEGER NOT NULL,
        purchase_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE inventory_adjustments (
        adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        adjustment_qty INTEGER NOT NULL,
        adjustment_type TEXT NOT NULL,
        reported_by INTEGER NOT NULL,
        reason TEXT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        review_notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE store_write_gate (
        gate_key TEXT PRIMARY KEY,
        is_paused INTEGER NOT NULL DEFAULT 0
    );
    INSERT INTO store_write_gate (gate_key, is_paused) VALUES ('store_writes', 0);

    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_name TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open'
    );
    INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2026-01-01', '2026-12-31', 'open');

    CREATE TABLE fiscal_period_locks (
        lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_id INTEGER NOT NULL,
        table_name TEXT NULL
    );

    CREATE TABLE store_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );
    INSERT INTO store_settings (setting_key, setting_value) VALUES ('expiry_alert_days', '30,14,7');

    CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        type TEXT,
        title TEXT,
        message TEXT,
        reference_id INTEGER,
        status TEXT DEFAULT 'unread',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
");

$managerId = 1;
$receivingService = new ReceivingService($pdo);
$pulloutService = new SupplierPulloutService($pdo, null, 'inventory_manager');
$inventoryService = new InventoryService($pdo);

echo "\n--- Scenario 1: Spec Line 107 - Pullout 8 of 20, Receive 30 Fresh Linked Replacement ---\n";
// Insert product: Loaf Bread
$pdo->prepare("INSERT INTO products (product_id, sku, barcode, product_name, units_per_package, cost_price, status) VALUES (1, 'BREAD-01', '10001', 'Loaf Bread', 1, 50.00, 'active')")->execute();
$pdo->prepare("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 20)")->execute();
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date, supplier) VALUES (1, 1, 'BATCH-OLD', 20, 20, '2026-10-20', 'Gardenia')")->execute();

// 1. Collect 8 old loaves via supplier pullout
$pulloutResult = $pulloutService->recordPullout([
    'product_id' => 1,
    'batch_id' => 1,
    'quantity' => 8,
    'reason' => 'Near expiry return',
    'supplier' => 'Gardenia',
], $managerId);

$pulloutId = $pulloutResult['pullout_id'];
$assert($pulloutId > 0, "Pullout created with ID {$pulloutId}");

// Invariants after pullout:
// old batch remaining = 12, inventory on hand = 12
$oldBatch = $pdo->query("SELECT remaining_quantity, expiration_date FROM product_batches WHERE batch_id = 1")->fetch();
$onHand = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert((int)$oldBatch['remaining_quantity'] === 12, "Old batch remaining quantity is 12 (deducted 8 from 20)");
$assert($onHand === 12, "Inventory on hand is 12 (deducted 8 from 20)");

// 2. Receive 30 fresh loaves linked to pullout_id with fresh expiration date
$receiveResult = $receivingService->receiveStock([
    'product_id' => 1,
    'quantity_mode' => 'units',
    'received_qty' => 30,
    'cost_price' => '50.00',
    'supplier' => 'Gardenia',
    'invoice_number' => 'REP-001',
    'batch_number' => 'BATCH-FRESH-30',
    'expiration_date' => '2026-11-20',
    'pullout_id' => $pulloutId,
], $managerId);

$receivingId = $receiveResult['receiving_id'];
$assert($receivingId > 0, "Receiving created with ID {$receivingId}");
$assert($receiveResult['pullout_id'] === $pulloutId, "receiveLine return includes pullout_id {$pulloutId}");

// Invariants after receiving fresh replacements:
// - old batch retains 12 with old date 2026-10-20
// - new batch has 30 with new date 2026-11-20
// - total inventory is 42 (12 + 30)
// - stock_receiving links to pullout_id
$oldBatchAfter = $pdo->query("SELECT remaining_quantity, expiration_date FROM product_batches WHERE batch_id = 1")->fetch();
$assert((int)$oldBatchAfter['remaining_quantity'] === 12, "Old batch retains 12 remaining units");
$assert($oldBatchAfter['expiration_date'] === '2026-10-20', "Old batch retains original expiration date 2026-10-20");

$batches = $inventoryService->getProductBatches(1);
$assert(count($batches) === 2, "Product has exactly two batches");

$newBatch = null;
$verifiedOldBatch = null;
foreach ($batches as $b) {
    if ($b['batch_number'] === 'BATCH-FRESH-30') {
        $newBatch = $b;
    } elseif ($b['batch_id'] === 1) {
        $verifiedOldBatch = $b;
    }
}

$assert($newBatch !== null, "Fresh replacement batch exists in getProductBatches");
$assert($newBatch['remaining_quantity'] === 30, "Fresh batch has 30 remaining units");
$assert($newBatch['expiration_date'] === '2026-11-20', "Fresh batch has new expiration date 2026-11-20");
$assert($newBatch['source'] === 'Replacement', "Fresh batch source is strictly 'Replacement'");
$assert($newBatch['pullout_id'] === $pulloutId, "Fresh batch exposes pullout_id {$pulloutId}");
$assert($verifiedOldBatch['source'] === 'Opening Stock', "Old batch retains Opening Stock source");
$assert($verifiedOldBatch['pullout_id'] === null, "Old batch pullout_id is null");

$totalOnHand = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$assert($totalOnHand === 42, "Total inventory on hand equals 42 (12 remaining + 30 fresh)");

$receivingRow = $pdo->query("SELECT pullout_id, accepted_qty, supplier FROM stock_receiving WHERE receiving_id = {$receivingId}")->fetch();
$assert((int)$receivingRow['pullout_id'] === $pulloutId, "stock_receiving row links to pullout_id {$pulloutId}");
$assert((int)$receivingRow['accepted_qty'] === 30, "stock_receiving accepted_qty is 30");

echo "\n--- Scenario 2: Partial & Delayed Replacement (Pullout 10, receive 4, then receive 8) ---\n";
// Insert Product 2: Milk
$pdo->prepare("INSERT INTO products (product_id, sku, barcode, product_name, units_per_package, cost_price, status) VALUES (2, 'MILK-01', '10002', 'Fresh Milk 1L', 1, 80.00, 'active')")->execute();
$pdo->prepare("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 50)")->execute();
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date, supplier) VALUES (10, 2, 'MILK-BATCH-1', 50, 50, '2026-10-19', 'DairyCorp')")->execute();

// Pullout 10 milk
$milkPullout = $pulloutService->recordPullout([
    'product_id' => 2,
    'batch_id' => 10,
    'quantity' => 10,
    'reason' => 'Spoiled milk pullout',
    'supplier' => 'DairyCorp',
], $managerId);
$milkPulloutId = $milkPullout['pullout_id'];
$assert($milkPulloutId > 0, "Milk pullout recorded with ID {$milkPulloutId}");

// Receive partial replacement: 4 units
$partial1 = $receivingService->receiveStock([
    'product_id' => 2,
    'quantity_mode' => 'units',
    'received_qty' => 4,
    'batch_number' => 'MILK-REP-PART1',
    'expiration_date' => '2026-11-15',
    'pullout_id' => $milkPulloutId,
], $managerId);
$assert($partial1['receiving_id'] > 0, "First partial replacement received 4 units");

// Check pullout summary: 4 replaced
$summary1 = $pulloutService->getPullout($milkPulloutId);
$assert((int)$summary1['total_replaced_qty'] === 4, "Pullout summary shows 4 units replaced");
$assert((int)$summary1['replacement_count'] === 1, "Pullout summary shows 1 replacement receipt");

// Later receive another replacement: 8 units (differing total quantity: 4 + 8 = 12 vs 10 pulled)
$partial2 = $receivingService->receiveStock([
    'product_id' => 2,
    'quantity_mode' => 'units',
    'received_qty' => 8,
    'batch_number' => 'MILK-REP-PART2',
    'expiration_date' => '2026-11-25',
    'pullout_id' => $milkPulloutId,
], $managerId);
$assert($partial2['receiving_id'] > 0, "Second delayed replacement received 8 units");

// Check pullout summary: 12 total replaced across 2 receipts
$summary2 = $pulloutService->getPullout($milkPulloutId);
$assert((int)$summary2['total_replaced_qty'] === 12, "Aggregated total_replaced_qty is 12 (4 + 8)");
$assert((int)$summary2['replacement_count'] === 2, "Replacement count is 2 distinct receipts");

// Check milk batches: both replacement batches have source 'Replacement' and pullout_id
$milkBatches = $inventoryService->getProductBatches(2);
$repBatches = array_filter($milkBatches, static fn($b) => $b['pullout_id'] === $milkPulloutId);
$assert(count($repBatches) === 2, "Product 2 has 2 distinct replacement batches linked to pullout");
foreach ($repBatches as $rb) {
    $assert($rb['source'] === 'Replacement', "Batch {$rb['batch_number']} source is 'Replacement'");
    $assert($rb['pullout_id'] === $milkPulloutId, "Batch {$rb['batch_number']} exposes pullout_id {$milkPulloutId}");
}

echo "\n--- Scenario 3: Validation - Reject Mismatched or Non-existent Pullouts ---\n";
// Insert Product 3: Eggs
$pdo->prepare("INSERT INTO products (product_id, sku, barcode, product_name, units_per_package, cost_price, status) VALUES (3, 'EGG-01', '10003', 'Eggs Tray', 1, 120.00, 'active')")->execute();
$pdo->prepare("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (3, 20)")->execute();

// 1. Reject non-existent pullout_id
$throws(
    static function () use ($receivingService, $managerId) {
        $receivingService->receiveStock([
            'product_id' => 3,
            'received_qty' => 5,
            'pullout_id' => 99999,
        ], $managerId);
    },
    'Linked supplier pullout not found',
    'Rejects non-existent pullout ID'
);

// 2. Reject pullout belonging to a different product
$throws(
    static function () use ($receivingService, $managerId, $milkPulloutId) {
        // Attempt to receive Product 3 (Eggs) linked to Milk pullout
        $receivingService->receiveStock([
            'product_id' => 3,
            'received_qty' => 5,
            'pullout_id' => $milkPulloutId,
        ], $managerId);
    },
    'Linked supplier pullout does not match the product being received',
    'Rejects pullout belonging to a different product'
);

echo "\n--- Scenario 4: Rollback on Failure in Multi-Line Receiving ---\n";
$breadOnHandBefore = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$eggOnHandBefore = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 3")->fetchColumn();
$groupsCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving_groups")->fetchColumn();
$receivingCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving")->fetchColumn();
$batchesCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM product_batches")->fetchColumn();

$tripFailed = false;
try {
    $receivingService->receiveShoppingTrip([
        [
            'product_id' => 1,
            'received_qty' => 10,
            'pullout_id' => $pulloutId, // Valid
        ],
        [
            'product_id' => 3,
            'received_qty' => 5,
            'pullout_id' => 999999, // Invalid: does not exist
        ],
    ], [
        'supplier' => 'MultiSupplier',
    ], $managerId);
} catch (RuntimeException $e) {
    $tripFailed = true;
    $assert(str_contains($e->getMessage(), 'Linked supplier pullout not found'), "Shopping trip threw expected validation error: {$e->getMessage()}");
}

$assert($tripFailed, "Multi-line trip with invalid pullout failed");

// Assert complete rollback:
$breadOnHandAfter = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$eggOnHandAfter = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 3")->fetchColumn();
$groupsCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving_groups")->fetchColumn();
$receivingCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving")->fetchColumn();
$batchesCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM product_batches")->fetchColumn();

$assert($breadOnHandAfter === $breadOnHandBefore, "Bread inventory unchanged after rollback ({$breadOnHandAfter} === {$breadOnHandBefore})");
$assert($eggOnHandAfter === $eggOnHandBefore, "Eggs inventory unchanged after rollback ({$eggOnHandAfter} === {$eggOnHandBefore})");
$assert($groupsCountAfter === $groupsCountBefore, "No orphaned stock_receiving_groups row created");
$assert($receivingCountAfter === $receivingCountBefore, "No orphaned stock_receiving rows created");
$assert($batchesCountAfter === $batchesCountBefore, "No orphaned product_batches rows created");

echo "\n--- Scenario 5: Static UI & Contract Checks ---\n";
// 1. supplier_pullouts.php UI checks
$pulloutPhp = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/inventory_management/supplier_pullouts.php');
$assert(str_contains($pulloutPhp, 'Receive Replacement'), "supplier_pullouts.php contains 'Receive Replacement' action button");
$assert(str_contains($pulloutPhp, 'stock_receiving.php?pullout_id='), "supplier_pullouts.php links to stock_receiving.php?pullout_id=");
$assert(str_contains($pulloutPhp, 'Replaced:'), "supplier_pullouts.php shows 'Replaced:' summary");
$assert(str_contains($pulloutPhp, 'Pending replacement'), "supplier_pullouts.php shows 'Pending replacement'");

// 2. stock_receiving.php UI checks
$receivingPhp = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/report/stock_receiving.php');
$assert(str_contains($receivingPhp, '$_GET[\'pullout_id\']'), "stock_receiving.php supports \$_GET['pullout_id'] prefill");
$assert(str_contains($receivingPhp, 'data-field="pullout_id"'), "stock_receiving.php contains pullout_id form field");
$assert(str_contains($receivingPhp, 'data-field="pullout_badge"'), "stock_receiving.php contains pullout badge element");
$assert(str_contains($receivingPhp, 'Replacement Receiving:'), "stock_receiving.php contains linked pullout notice");

// 3. ReceivingService.php checks
$receivingServiceCode = (string)file_get_contents(dirname(__DIR__, 2) . '/backend/app/Services/ReceivingService.php');
$assert(str_contains($receivingServiceCode, 'Linked supplier pullout'), "ReceivingService validates linked supplier pullout");
$assert(str_contains($receivingServiceCode, 'pullout_id'), "ReceivingService persists pullout_id");

// 4. InventoryService.php checks
$invServiceCode = (string)file_get_contents(dirname(__DIR__, 2) . '/backend/app/Services/InventoryService.php');
$assert(str_contains($invServiceCode, 'sr.pullout_id'), "InventoryService queries sr.pullout_id");
$assert(str_contains($invServiceCode, '\'pullout_id\' => $pulloutId'), "InventoryService exposes pullout_id in batch array");

if (!empty($failures)) {
    echo "\nFailed assertions (" . count($failures) . "):\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\nAll Ticket #225 supplier replacement receiving tests passed!\n";
