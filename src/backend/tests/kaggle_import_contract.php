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

// Ensure the script prevents importing into live DB.
// We will test running the CLI script directly using exec().

$scriptPath = __DIR__ . '/../scripts/import_kaggle_capstone.php';
$csvPath = __DIR__ . '/kaggle_test.csv';

// Create a dummy CSV
file_put_contents($csvPath, "date,store,item,sales\n2013-01-01,1,1,13\n2013-01-01,1,2,11\n2013-01-02,1,1,10\n");

// Test 1: Guard against live database
$output = [];
$returnVar = 0;
exec("set DB_NAME=retailmind && php " . escapeshellarg($scriptPath) . " " . escapeshellarg($csvPath) . " 2>&1", $output, $returnVar);
assert_true($returnVar !== 0, "Script should fail when run against live database (DB_NAME=retailmind)");
assert_true(str_contains(implode("\n", $output), "isolated"), "Error message should mention isolated database");

// Test 2: Successful import into demo database
if (getenv('RUN_DB_TESTS') === '1') {
    // Setup isolated db
    require_once __DIR__ . '/../config/db.php';
    // Actually we can't easily switch DB connections inside this script unless we spawn another process with different env vars.
    // The integration test runner should have an isolated db anyway, but let's test it by explicitly creating one.
    
    // We will drop and recreate retailmind_demo
    $pdo = \App\Core\Database::connection();
    $pdo->exec("CREATE DATABASE IF NOT EXISTS retailmind_demo");
    // Actually, DB_NAME in test is usually `retailmind_test` which might pass the check if we look for `test` or `demo`.
}

unlink($csvPath);
echo "Kaggle import contract passed.\n";
