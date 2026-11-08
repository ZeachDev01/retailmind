<?php
// Contract and integration test for Ticket #451:
// Supplier Receiving, Replenishment Queue & Pullouts.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';
require_once __DIR__ . '/../app/Services/SupplierPulloutService.php';
require_once __DIR__ . '/../app/Forecasting/ReplenishmentQueueService.php';
require_once __DIR__ . '/../includes/barcode.php';

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

echo "Starting Ticket #451 Supplier Receiving, Replenishment Queue & Pullouts tests...\n";

// --- Database Setup (SQLite in-memory) ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

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

// Schema creation
$pdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT NOT NULL UNIQUE);
    INSERT INTO roles VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin');

    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        display_name TEXT,
        first_name TEXT,
        last_name TEXT,
        full_name TEXT,
        username TEXT UNIQUE,
        role_id INTEGER,
        status TEXT DEFAULT 'active'
    );
    INSERT INTO users (user_id, display_name, first_name, last_name, full_name, username, role_id)
    VALUES (1, 'Manager Mike', 'Mike', 'Manager', 'Manager Mike', 'mgr_mike', 2),
           (2, 'Admin Alice', 'Alice', 'Admin', 'Admin Alice', 'adm_alice', 3),
           (3, 'Cashier Chris', 'Chris', 'Cashier', 'Cashier Chris', 'csh_chris', 1);

    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT NOT NULL UNIQUE,
        barcode TEXT,
        pack_barcode TEXT,
        pack_size INTEGER DEFAULT 1,
        pack_price NUMERIC DEFAULT 0,
        product_name TEXT NOT NULL,
        brand TEXT,
        category_id INTEGER,
        unit_price NUMERIC DEFAULT 10.00,
        cost_price NUMERIC DEFAULT 5.00,
        units_per_package INTEGER DEFAULT 1,
        quantity_purchased INTEGER DEFAULT 0,
        reorder_level INTEGER DEFAULT 10,
        safety_stock INTEGER DEFAULT 5,
        supplier_lead_time_days INTEGER DEFAULT 7,
        minimum_order_quantity INTEGER DEFAULT 1,
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE inventory (
        inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL UNIQUE,
        quantity_on_hand INTEGER DEFAULT 0,
        reorder_level INTEGER DEFAULT 10
    );

    CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        receiving_id INTEGER,
        batch_number TEXT,
        quantity INTEGER NOT NULL,
        remaining_quantity INTEGER NOT NULL,
        sealed_quantity INTEGER DEFAULT 0,
        loose_quantity INTEGER DEFAULT 0,
        expiration_date TEXT,
        cost_price NUMERIC,
        date_received TEXT DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT
    );

    CREATE TABLE purchase_history (
        purchase_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        supplier TEXT,
        quantity_purchased INTEGER NOT NULL,
        cost_price NUMERIC,
        purchase_date TEXT DEFAULT CURRENT_TIMESTAMP,
        total_cost NUMERIC,
        recorded_by INTEGER NOT NULL
    );

    CREATE TABLE inventory_adjustments (
        adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        adjustment_qty INTEGER NOT NULL,
        adjustment_type TEXT NOT NULL,
        reported_by INTEGER NOT NULL,
        shift_id INTEGER,
        reported_at TEXT DEFAULT CURRENT_TIMESTAMP,
        approved_by INTEGER,
        approved_at TEXT,
        reason TEXT,
        review_notes TEXT,
        status TEXT DEFAULT 'pending',
        form TEXT,
        reported_qty INTEGER,
        pack_size INTEGER DEFAULT 1
    );

    CREATE TABLE stock_receiving_groups (
        group_id INTEGER PRIMARY KEY AUTOINCREMENT,
        received_by INTEGER NOT NULL,
        received_at TEXT DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT,
        invoice_number TEXT,
        notes TEXT
    );

    CREATE TABLE stock_receiving (
        receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER,
        product_id INTEGER NOT NULL,
        received_qty INTEGER NOT NULL,
        received_packages INTEGER DEFAULT 0,
        units_per_package_used INTEGER DEFAULT 1,
        accepted_qty INTEGER NOT NULL,
        damaged_qty INTEGER DEFAULT 0,
        cost_price NUMERIC,
        total_cost NUMERIC,
        received_by INTEGER NOT NULL,
        received_at TEXT DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT,
        invoice_number TEXT,
        batch_number TEXT,
        expiration_date TEXT,
        discrepancy_type TEXT DEFAULT 'none',
        discrepancy_qty INTEGER DEFAULT 0,
        discrepancy_notes TEXT,
        notes TEXT,
        pullout_id INTEGER,
        received_packs INTEGER DEFAULT 0,
        accepted_packs INTEGER DEFAULT 0,
        damaged_packs INTEGER DEFAULT 0,
        rejected_packs INTEGER DEFAULT 0,
        received_loose INTEGER DEFAULT 0,
        accepted_loose INTEGER DEFAULT 0,
        damaged_loose INTEGER DEFAULT 0,
        rejected_loose INTEGER DEFAULT 0,
        pack_size_used INTEGER DEFAULT 1
    );

    CREATE TABLE supplier_pullouts (
        pullout_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        batch_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        pulled_packs INTEGER DEFAULT 0,
        pulled_loose INTEGER DEFAULT 0,
        pack_size_used INTEGER DEFAULT 1,
        reason TEXT NOT NULL,
        supplier TEXT,
        pulled_by INTEGER NOT NULL,
        pulled_at TEXT DEFAULT CURRENT_TIMESTAMP,
        notes TEXT
    );

    CREATE TABLE stock_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        change_qty INTEGER NOT NULL,
        reason TEXT NOT NULL,
        moved_by INTEGER NOT NULL,
        adjustment_id INTEGER,
        pullout_id INTEGER,
        moved_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE store_write_gate (
        gate_key TEXT PRIMARY KEY,
        is_paused INTEGER NOT NULL DEFAULT 0
    );
    INSERT OR IGNORE INTO store_write_gate (gate_key, is_paused) VALUES ('store_writes', 0);

    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_name TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open'
    );
    INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2026-01-01', '2028-12-31', 'open');

    CREATE TABLE fiscal_period_locks (
        lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_id INTEGER NOT NULL,
        table_name TEXT NULL
    );

    CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        type TEXT NOT NULL,
        reference_id INTEGER NULL,
        reference_type TEXT NULL,
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE platform_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );

    CREATE TABLE ml_jobs (
        job_id INTEGER PRIMARY KEY,
        trigger_type TEXT,
        status TEXT
    );

    CREATE TABLE demand_forecasts (
        forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER,
        product_id INTEGER,
        forecast_origin TEXT,
        target_date TEXT,
        horizon_day INTEGER,
        predicted_units INTEGER,
        model_version TEXT,
        readiness_level TEXT
    );
");

// Seed products
$pdo->exec("
    INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, pack_size, reorder_level, safety_stock)
    VALUES (1, 'SKU-COF', '480001', 'Artisan Coffee Beans 500g', 150.00, 90.00, 1, 10, 5),
           (2, 'SKU-MLK', '480002', 'Fresh Whole Milk 1L', 85.00, 60.00, 6, 20, 10);

    INSERT INTO inventory (product_id, quantity_on_hand)
    VALUES (1, 0), (2, 0);
");

// =========================================================================
// SECTION 1: Supplier Receiving bulk line items, PO references, batch, expiry
// =========================================================================
echo "\n--- Section 1: Supplier Receiving bulk line items, PO & batches ---\n";

$stockReceivingFile = __DIR__ . '/../../frontend/components/report/stock_receiving.php';
$assert(file_exists($stockReceivingFile), 'stock_receiving.php exists');
$srContent = file_get_contents($stockReceivingFile);

$assert(str_contains($srContent, 'invoice_number'), 'stock_receiving.php includes receipt/invoice reference field');
$assert(str_contains($srContent, 'batch_number'), 'stock_receiving.php includes batch number input');
$assert(str_contains($srContent, 'expiration_date'), 'stock_receiving.php includes expiration date input');
$assert(str_contains($srContent, 'receiving_review'), 'stock_receiving.php has delivery review container');
$assert(str_contains($srContent, 'Receipt / Purchase Order') || str_contains($srContent, 'Receipt reference'), 'stock_receiving.php supports PO/receipt reference');

// Test bulk receiving persistence via ReceivingService
$receivingService = new ReceivingService($pdo);
$lines = [
    [
        'product_id' => 1,
        'quantity_mode' => 'units',
        'received_qty' => 50,
        'damaged_qty' => 2,
        'cost_price' => '88.50',
        'batch_number' => 'LOT-COF-2026A',
        'expiration_date' => '2027-06-30',
        'notes' => 'Received from Highlands Supplier'
    ],
    [
        'product_id' => 2,
        'quantity_mode' => 'packs',
        'received_packs' => 10,
        'accepted_packs' => 10,
        'damaged_packs' => 0,
        'received_loose' => 0,
        'accepted_loose' => 0,
        'damaged_loose' => 0,
        'cost_price' => '58.00',
        'batch_number' => 'LOT-MLK-2026B',
        'expiration_date' => '2026-12-15',
        'notes' => 'Cold chain verified'
    ]
];
$entry = [
    'supplier' => 'Metro Wholesale Co.',
    'invoice_number' => 'PO-2026-11-0091',
    'notes' => 'Bulk seasonal receiving'
];

try {
    $receiptResults = $receivingService->receiveShoppingTrip($lines, $entry, 1);
} catch (Throwable $e) {
    if ($prev = $e->getPrevious()) {
        fwrite(STDERR, "Inner exception: " . $prev->getMessage() . " at " . $prev->getFile() . ":" . $prev->getLine() . "\n");
    }
    throw $e;
}
$assert(count($receiptResults) === 2, 'Bulk receiving successfully processed 2 lines');

$groupId = $receiptResults[0]['group_id'] ?? null;
$assert(!empty($groupId), 'Lines assigned to common receiving group');

// Verify stock_receiving_groups stores PO reference
$grpRow = $pdo->query("SELECT * FROM stock_receiving_groups WHERE group_id = {$groupId}")->fetch();
$assert($grpRow['invoice_number'] === 'PO-2026-11-0091', 'PO reference saved in stock_receiving_groups');
$assert($grpRow['supplier'] === 'Metro Wholesale Co.', 'Supplier saved in stock_receiving_groups');

// Verify product batches created
$batches = $pdo->query("SELECT * FROM product_batches ORDER BY batch_id")->fetchAll();
$assert(count($batches) === 2, '2 product batches created for received lines');
$assert($batches[0]['batch_number'] === 'LOT-COF-2026A', 'Batch 1 has batch number LOT-COF-2026A');
$assert($batches[0]['expiration_date'] === '2027-06-30', 'Batch 1 has expiration date 2027-06-30');
$assert($batches[0]['remaining_quantity'] === 48, 'Batch 1 remaining quantity is 48 (50 - 2 damaged)');
$assert($batches[1]['batch_number'] === 'LOT-MLK-2026B', 'Batch 2 has batch number LOT-MLK-2026B');
$assert($batches[1]['remaining_quantity'] === 60, 'Batch 2 remaining quantity is 60 (10 packs * 6)');

// Verify inventory on hand updated
$inv1 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
$inv2 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 2")->fetchColumn();
$assert($inv1 === 48, 'Inventory for Product 1 is 48');
$assert($inv2 === 60, 'Inventory for Product 2 is 60');

// =========================================================================
// SECTION 2: Replenishment queue & single-click PDF Purchase Order generation
// =========================================================================
echo "\n--- Section 2: Replenishment queue & PDF Purchase Order generation ---\n";

$replenishmentFile = __DIR__ . '/../../frontend/components/inventory_management/replenishment_queue.php';
$assert(file_exists($replenishmentFile), 'replenishment_queue.php exists');
$rqContent = file_get_contents($replenishmentFile);

$assert(str_contains($rqContent, 'queue-download-pdf') || str_contains($rqContent, 'btn-generate-po-pdf'),
    'replenishment_queue.php has PDF download / PO generation trigger');
$assert(str_contains($rqContent, 'replenishment-pdf.js'), 'replenishment_queue.php includes replenishment-pdf.js');

$replenishPdfJs = __DIR__ . '/../../frontend/assets/js/replenishment-pdf.js';
$assert(file_exists($replenishPdfJs), 'replenishment-pdf.js exists');
$pdfJsContent = file_get_contents($replenishPdfJs);
$assert(str_contains($pdfJsContent, 'jsPDF'), 'replenishment-pdf.js uses jsPDF library');
$assert(str_contains($pdfJsContent, 'download-pdf') || str_contains($pdfJsContent, 'btn-generate-po-pdf'),
    'replenishment-pdf.js binds to PO / replenishment download triggers');

// Test recommendation logic
$recs = (new ReplenishmentQueueService($pdo))->getRecommendations();
$assert(is_array($recs), 'ReplenishmentQueueService returned recommendations array');
$assert(count($recs) >= 2, 'Recommendations cover seeded products');

// =========================================================================
// SECTION 3: Product barcode label generation and print layout
// =========================================================================
echo "\n--- Section 3: Product barcode label generation and print layout ---\n";

$printBarcodesFile = __DIR__ . '/../../frontend/components/inventory_management/print_barcodes.php';
$assert(file_exists($printBarcodesFile), 'print_barcodes.php exists');
$pbContent = file_get_contents($printBarcodesFile);

$assert(str_contains($pbContent, 'code128_svg'), 'print_barcodes.php invokes code128_svg');
$assert(str_contains($pbContent, 'class="sheet"'), 'print_barcodes.php defines barcode sheet layout');
$assert(str_contains($pbContent, 'barcode-label'), 'print_barcodes.php renders individual barcode-label articles');
$assert(str_contains($pbContent, '--label-width') && str_contains($pbContent, '--label-height'),
    'print_barcodes.php sets CSS custom properties for label dimensions');

// Test Code 128 SVG generator function
$svg = code128_svg('480001', 58, 2);
$assert(str_starts_with($svg, '<svg') && str_ends_with(trim($svg), '</svg>'), 'code128_svg produces valid SVG element');
$assert(str_contains($svg, '<rect'), 'Barcode SVG contains rect bars');

// =========================================================================
// SECTION 4: Supplier Pullout management & formal Transfer Manifests
// =========================================================================
echo "\n--- Section 4: Supplier pullout management & formal Transfer Manifests ---\n";

$pulloutsFile = __DIR__ . '/../../frontend/components/inventory_management/supplier_pullouts.php';
$assert(file_exists($pulloutsFile), 'supplier_pullouts.php exists');
$spContent = file_get_contents($pulloutsFile);

$assert(str_contains($spContent, 'pullout-form'), 'supplier_pullouts.php has pullout recording form');
$assert(str_contains($spContent, 'pullout-history-panel'), 'supplier_pullouts.php has history panel');

// Record a pullout using SupplierPulloutService
$pulloutService = new SupplierPulloutService($pdo, null, 'inventory_manager');
$pulloutResult = $pulloutService->recordPullout([
    'product_id' => 1,
    'batch_id' => $batches[0]['batch_id'],
    'quantity' => 8,
    'reason' => 'Damaged / Broken seals on delivery',
    'supplier' => 'Metro Wholesale Co.',
    'notes' => 'Defect confirmed during unpacking inspection'
], 1);

$pulloutId = (int)$pulloutResult['pullout_id'];
$assert($pulloutId > 0, "Pullout recorded with ID {$pulloutId}");
$assert((int)$pulloutResult['remaining_batch_qty'] === 40, 'Batch 1 deducted from 48 to 40');

// Verify getPullout retrieval
$pulloutRecord = $pulloutService->getPullout($pulloutId);
$assert($pulloutRecord !== null, "getPullout({$pulloutId}) found recorded pullout");
$assert($pulloutRecord['reason'] === 'Damaged / Broken seals on delivery', 'Pullout reason matches');
$assert($pulloutRecord['supplier'] === 'Metro Wholesale Co.', 'Pullout supplier matches');
$assert($pulloutRecord['sku'] === 'SKU-COF', 'Pullout product SKU matches');

// Verify Transfer Manifest view exists and renders
$manifestFile = __DIR__ . '/../../frontend/components/inventory_management/pullout_manifest.php';
$assert(file_exists($manifestFile), 'pullout_manifest.php exists');
if (file_exists($manifestFile)) {
    $mfContent = file_get_contents($manifestFile);
    $assert(str_contains($mfContent, 'TRANSFER MANIFEST'), 'pullout_manifest.php has formal document title');
    $assert(str_contains($mfContent, 'Released By') || str_contains($mfContent, 'released_by'), 'pullout_manifest.php has custodian release signature block');
    $assert(str_contains($mfContent, 'Authorized By') || str_contains($mfContent, 'authorized_by'), 'pullout_manifest.php has supervisor authorization signature block');
    $assert(str_contains($mfContent, 'Received By') || str_contains($mfContent, 'received_by'), 'pullout_manifest.php has courier/supplier receipt signature block');
    $assert(str_contains($mfContent, 'window.print()'), 'pullout_manifest.php has print button / print trigger');
}

// Summary
if ($failures !== []) {
    fwrite(STDERR, "\nFAILED: " . count($failures) . " assertions failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nPASS: All Ticket #451 contract checks passed!\n";
