<?php
/**
 * Kaggle Capstone Dataset Importer (Ticket #151)
 * 
 * Imports the agreed Retail Store Inventory dataset for Store S001 (Products P0001-P0020)
 * into an explicitly isolated demo environment, preserving source meaning and isolation.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';

// 1. Destination isolation check
$dbConfig = require __DIR__ . '/../config/database.php';
$dbName = strtolower($dbConfig['database'] ?? '');

$appEnv = strtolower(getenv('APP_ENV') ?: 'local');
if ($appEnv === 'production' || $appEnv === 'live') {
    fwrite(STDERR, "FATAL: Operational environment detected (APP_ENV={$appEnv}). Must use an explicitly isolated demo environment.\n");
    exit(1);
}

if ($dbName === 'retailmind' || (!str_contains($dbName, 'demo') && !str_contains($dbName, 'kaggle') && !str_contains($dbName, 'test'))) {
    fwrite(STDERR, "FATAL: Must use an explicitly isolated demo database (DB name must contain 'demo', 'kaggle', or 'test' and cannot be operational 'retailmind'). Found: '{$dbName}'.\n");
    exit(1);
}

// 2. CSV source path resolution
$defaultCsv = dirname(__DIR__) . '/dataset/retail_store_inventory.csv';
$csvPath = ($argc >= 2) ? $argv[1] : $defaultCsv;

if (!file_exists($csvPath)) {
    fwrite(STDERR, "FATAL: Dataset file not found: {$csvPath}\n");
    exit(1);
}

// 3. Expected headers definition
$expectedHeaders = [
    'date',
    'store id',
    'product id',
    'category',
    'region',
    'inventory level',
    'units sold',
    'units ordered',
    'demand forecast',
    'price',
    'discount',
    'weather condition',
    'holiday/promotion',
    'competitor pricing',
    'seasonality'
];

$fh = fopen($csvPath, 'r');
if (!$fh) {
    fwrite(STDERR, "FATAL: Cannot open file: {$csvPath}\n");
    exit(1);
}

$rawHeader = fgetcsv($fh);
if (!$rawHeader) {
    fclose($fh);
    fwrite(STDERR, "FATAL: Empty CSV file or invalid header.\n");
    exit(1);
}

$headerNormalized = array_map(function($h) {
    return strtolower(trim($h));
}, $rawHeader);

if (count($headerNormalized) !== count($expectedHeaders)) {
    fclose($fh);
    fwrite(STDERR, "FATAL: Header column count mismatch. Expected " . count($expectedHeaders) . ", got " . count($headerNormalized) . ".\n");
    exit(1);
}

for ($i = 0; $i < count($expectedHeaders); $i++) {
    if ($headerNormalized[$i] !== $expectedHeaders[$i]) {
        fclose($fh);
        fwrite(STDERR, "FATAL: Header mismatch at column {$i}. Expected '{$expectedHeaders[$i]}', got '{$headerNormalized[$i]}'.\n");
        exit(1);
    }
}

// 4. Pre-validation scan (in-memory verification before database mutations)
$targetStore = 'S001';
$validProducts = [];
for ($p = 1; $p <= 20; $p++) {
    $validProducts[sprintf('P%04d', $p)] = $p;
}

$seenKeys = [];
$rowsToImport = [];
$minDate = null;
$maxDate = null;
$lineNum = 1;

while (($row = fgetcsv($fh)) !== false) {
    $lineNum++;
    if (count($row) < 15) {
        continue;
    }

    $dateStr = trim($row[0]);
    $storeId = trim($row[1]);
    $productId = trim($row[2]);
    $invLevel = $row[5];
    $unitsSold = $row[6];
    $unitsOrdered = $row[7];
    // Column 8 is Demand Forecast (excluded from inputs and targets)
    $price = $row[9];

    // Only process target store S001
    if (strcasecmp($storeId, $targetStore) !== 0) {
        continue;
    }

    // Only products P0001 - P0020
    if (!isset($validProducts[$productId])) {
        continue;
    }

    // Validate date format YYYY-MM-DD
    $d = DateTime::createFromFormat('Y-m-d', $dateStr);
    if (!$d || $d->format('Y-m-d') !== $dateStr) {
        fclose($fh);
        fwrite(STDERR, "FATAL: Invalid date format at line {$lineNum}: '{$dateStr}'.\n");
        exit(1);
    }

    // Duplicate daily key check for (Date, Store, Product)
    $key = "{$dateStr}_{$storeId}_{$productId}";
    if (isset($seenKeys[$key])) {
        fclose($fh);
        fwrite(STDERR, "FATAL: Duplicate key detected at line {$lineNum}: {$key}.\n");
        exit(1);
    }
    $seenKeys[$key] = true;

    // Validate numeric bounds
    if (!is_numeric($unitsSold) || (float)$unitsSold < 0) {
        fclose($fh);
        fwrite(STDERR, "FATAL: Invalid Units Sold at line {$lineNum}: '{$unitsSold}'. Must be >= 0.\n");
        exit(1);
    }

    if (!is_numeric($invLevel) || (float)$invLevel < 0) {
        fclose($fh);
        fwrite(STDERR, "FATAL: Invalid Inventory Level at line {$lineNum}: '{$invLevel}'. Must be >= 0.\n");
        exit(1);
    }

    if (!is_numeric($price) || (float)$price < 0) {
        fclose($fh);
        fwrite(STDERR, "FATAL: Invalid Price at line {$lineNum}: '{$price}'. Must be >= 0.\n");
        exit(1);
    }

    if ($minDate === null || $dateStr < $minDate) {
        $minDate = $dateStr;
    }
    if ($maxDate === null || $dateStr > $maxDate) {
        $maxDate = $dateStr;
    }

    $rowsToImport[] = [
        'date' => $dateStr,
        'product_id' => $validProducts[$productId],
        'product_code' => $productId,
        'units_sold' => (int)round((float)$unitsSold),
        'price' => (float)$price,
        'inventory_level' => (int)round((float)$invLevel)
    ];
}
fclose($fh);

$recordCount = count($rowsToImport);
if ($recordCount === 0) {
    fwrite(STDERR, "FATAL: No valid records found for store {$targetStore}.\n");
    exit(1);
}

// 5. Database mutation wrapped in atomic transaction
require_once __DIR__ . '/../config/db.php';
/** @var PDO $pdo */

$pdo->beginTransaction();

try {
    // Ensure demo category exists
    $pdo->exec("INSERT INTO categories (category_id, category_name) 
                VALUES (999, 'Demo Inventory (Kaggle)') 
                ON DUPLICATE KEY UPDATE category_name = 'Demo Inventory (Kaggle)'");

    // Ensure demo products 1..20 exist with stable mappings
    $stmtProd = $pdo->prepare("INSERT INTO products (product_id, sku, barcode, product_name, category_id, unit_price, cost_price) 
                               VALUES (?, ?, ?, ?, 999, ?, ?) 
                               ON DUPLICATE KEY UPDATE 
                                 product_name = VALUES(product_name),
                                 unit_price = VALUES(unit_price),
                                 cost_price = VALUES(cost_price)");

    foreach ($validProducts as $pCode => $pNum) {
        $sku = "DEMO-{$pCode}";
        $barcode = "BAR-{$pCode}";
        $name = "Demo Product {$pNum} ({$pCode})";
        $stmtProd->execute([$pNum, $sku, $barcode, $name, 25.00, 15.00]);
    }

    // System cashier user 999
    $pdo->exec("INSERT IGNORE INTO users (user_id, full_name, username, password_hash, role_id, branch_id) 
                VALUES (999, 'Kaggle Importer System', 'kaggle_sys', 'none', 1, 1)");

    // Clean slate for demo cashier 999 to guarantee idempotency
    $pdo->exec("DELETE FROM sale_items WHERE sale_id IN (SELECT sale_id FROM sales WHERE cashier_id = 999)");
    $pdo->exec("DELETE FROM sales WHERE cashier_id = 999");

    $saleIdStart = (int)$pdo->query("SELECT COALESCE(MAX(sale_id), 0) + 1 FROM sales")->fetchColumn();
    $saleItemIdStart = (int)$pdo->query("SELECT COALESCE(MAX(sale_item_id), 0) + 1 FROM sale_items")->fetchColumn();

    $insertSale = $pdo->prepare("INSERT INTO sales (sale_id, cashier_id, total_amount, sale_date) VALUES (?, 999, ?, ?)");
    $insertSaleItem = $pdo->prepare("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");

    $saleId = $saleIdStart;
    $saleItemId = $saleItemIdStart;

    foreach ($rowsToImport as $row) {
        $saleDate = $row['date'] . " 12:00:00";
        $subtotal = $row['units_sold'] * $row['price'];

        $insertSale->execute([$saleId, $subtotal, $saleDate]);
        $insertSaleItem->execute([$saleItemId, $saleId, $row['product_id'], $row['units_sold'], $row['price'], $subtotal]);

        $saleId++;
        $saleItemId++;
    }

    // Record provenance metadata in platform_settings if table exists
    $sha256 = hash_file('sha256', $csvPath);
    $metaJson = json_encode([
        'source_file' => basename($csvPath),
        'sha256' => $sha256,
        'store_id' => $targetStore,
        'products_mapped' => 20,
        'date_window' => [$minDate, $maxDate],
        'total_records' => $recordCount,
        'imported_at' => date('Y-m-d H:i:s'),
        'exclusions' => [
            'demand_forecast',
            'unstable_category_region',
            'weather_condition',
            'competitor_pricing',
            'seasonality'
        ]
    ]);

    $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_at) 
                   VALUES ('demo_import_metadata', ?, NOW()) 
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
        ->execute([$metaJson]);

    $pdo->commit();

    echo "Kaggle demo dataset imported successfully.\n";
    echo "- Destination: isolated database '{$dbName}'\n";
    echo "- Records: {$recordCount} daily observations for {$targetStore}\n";
    echo "- Window: {$minDate} to {$maxDate}\n";
    echo "- Source SHA-256: {$sha256}\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "FATAL: Import transaction failed and was rolled back: " . $e->getMessage() . "\n");
    exit(1);
}
