<?php

require_once __DIR__ . '/../bootstrap/app.php';

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: $message");
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed: $message. Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

$scriptPath = __DIR__ . '/../scripts/import_kaggle_capstone.php';
$testCsvDir = __DIR__ . '/tmp_test_data';
if (!is_dir($testCsvDir)) {
    mkdir($testCsvDir, 0777, true);
}

$validHeader = "Date,Store ID,Product ID,Category,Region,Inventory Level,Units Sold,Units Ordered,Demand Forecast,Price,Discount,Weather Condition,Holiday/Promotion,Competitor Pricing,Seasonality\n";

// Test 1: Guard against live database
$dummyCsv = $testCsvDir . '/dummy.csv';
file_put_contents($dummyCsv, $validHeader . "2022-01-01,S001,P0001,Groceries,North,231,127,55,135.47,33.5,20,Rainy,0,29.69,Autumn\n");

$output = [];
$returnVar = 0;
exec("set DB_NAME=retailmind && php " . escapeshellarg($scriptPath) . " " . escapeshellarg($dummyCsv) . " 2>&1", $output, $returnVar);
assert_true($returnVar !== 0, "Script should fail when run against live database (DB_NAME=retailmind)");
assert_true(str_contains(implode("\n", $output), "isolated"), "Error message should mention isolated database");

// Test 2: Guard against invalid / wrong headers
$badHeaderCsv = $testCsvDir . '/bad_header.csv';
file_put_contents($badHeaderCsv, "date,store,item,sales\n2013-01-01,1,1,13\n");
$output = [];
$returnVar = 0;
exec("set DB_NAME=retailmind_test && php " . escapeshellarg($scriptPath) . " " . escapeshellarg($badHeaderCsv) . " 2>&1", $output, $returnVar);
assert_true($returnVar !== 0, "Script should fail when CSV headers do not match expected schema");
assert_true(str_contains(implode("\n", $output), "header") || str_contains(implode("\n", $output), "Header"), "Error message should mention invalid headers");

// Test 3: Guard against duplicate daily keys
$dupCsv = $testCsvDir . '/duplicate_keys.csv';
file_put_contents($dupCsv, $validHeader . 
    "2022-01-01,S001,P0001,Groceries,North,231,127,55,135.47,33.5,20,Rainy,0,29.69,Autumn\n" .
    "2022-01-01,S001,P0001,Groceries,North,231,120,55,135.47,33.5,20,Rainy,0,29.69,Autumn\n"
);
$output = [];
$returnVar = 0;
exec("set DB_NAME=retailmind_test && php " . escapeshellarg($scriptPath) . " " . escapeshellarg($dupCsv) . " 2>&1", $output, $returnVar);
assert_true($returnVar !== 0, "Script should reject duplicate daily keys for same store and product");
assert_true(str_contains(implode("\n", $output), "duplicate") || str_contains(implode("\n", $output), "Duplicate"), "Error message should mention duplicate key");

// Test 4: Guard against invalid numeric or date bounds
$badBoundsCsv = $testCsvDir . '/bad_bounds.csv';
file_put_contents($badBoundsCsv, $validHeader . 
    "invalid-date,S001,P0001,Groceries,North,231,-5,55,135.47,33.5,20,Rainy,0,29.69,Autumn\n"
);
$output = [];
$returnVar = 0;
exec("set DB_NAME=retailmind_test && php " . escapeshellarg($scriptPath) . " " . escapeshellarg($badBoundsCsv) . " 2>&1", $output, $returnVar);
assert_true($returnVar !== 0, "Script should reject invalid date or negative sales");

// Test 5: Verify real dataset SHA-256 calculation
$realDatasetPath = dirname(__DIR__) . '/dataset/retail_store_inventory.csv';
if (file_exists($realDatasetPath)) {
    $expectedHash = 'c7b6019887d85e6bb311a76da72560463e6bf269bfadbe4b326fbff3b1000468';
    $actualHash = hash_file('sha256', $realDatasetPath);
    assert_same($expectedHash, $actualHash, "Official dataset SHA-256 must match parent spec");
}

// Cleanup temp files
foreach (glob($testCsvDir . '/*') as $f) {
    unlink($f);
}
rmdir($testCsvDir);

echo "All Kaggle import contract tests passed.\n";
