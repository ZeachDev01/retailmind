<?php

require_once __DIR__ . '/../bootstrap/app.php';

use App\Store\ScheduledDemandDriverService;

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

if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Database integration tests: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Database/MigrationRunner.php';

echo "Testing App\\Store\\ScheduledDemandDriverService domain seam...\n";

$runner = new \App\Database\MigrationRunner($pdo, __DIR__ . '/../database/migrations');
$runner->runPending();

// We need a product
$stmt = $pdo->query("SELECT product_id FROM products WHERE status = 'active' LIMIT 1");
$productId = (int)$stmt->fetchColumn();
if (!$productId) {
    $pdo->exec("INSERT INTO categories (category_name) VALUES ('Test Category')");
    $catId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO products (sku, barcode, product_name, category_id, unit_price, cost_price) VALUES ('TEST-SKU', 'TEST-BAR', 'Test Product', $catId, 100, 50)");
    $productId = (int)$pdo->lastInsertId();
}

$pdo->exec("DELETE FROM scheduled_demand_drivers");

$service = new ScheduledDemandDriverService();

// Test 1: Defaults to empty
$drivers = $service->getEffectiveDrivers($pdo, $productId, '2026-10-15', date('Y-m-d H:i:s'));
assert_same([], $drivers, 'An unrecorded date must return empty array.');

// Test 2: Can record a driver
$scheduleTime = date('Y-m-d H:i:s');
$service->recordDriver($pdo, $productId, 'promotion', '2026-10-15', $scheduleTime, '{"discount": 10}');
$drivers = $service->getEffectiveDrivers($pdo, $productId, '2026-10-15', date('Y-m-d H:i:s', time() + 5));
assert_same(1, count($drivers), 'Must return 1 driver.');
assert_same('promotion', $drivers[0]['driver_type'], 'Driver type must match.');
assert_same('{"discount": 10}', $drivers[0]['details_json'], 'Details must match.');

// Test 3: Can reproduce status at a cutoff time (future-data leakage prevention)
$cutoff = date('Y-m-d H:i:s', time() + 1);
sleep(2);
$service->recordDriver($pdo, $productId, 'promotion', '2026-10-15', date('Y-m-d H:i:s'), '{"discount": 20}');

$driversAfterCutoff = $service->getEffectiveDrivers($pdo, $productId, '2026-10-15', date('Y-m-d H:i:s', time() + 5));
assert_same('{"discount": 20}', $driversAfterCutoff[0]['details_json'], 'Latest driver should be returned if cutoff is after the change.');

$driversAtCutoff = $service->getEffectiveDrivers($pdo, $productId, '2026-10-15', $cutoff);
assert_same('{"discount": 10}', $driversAtCutoff[0]['details_json'], 'Historical queries reproduce driver information available at the requested cutoff.');

// Test 4: Cancellation/Neutral representation
sleep(1);
$service->recordDriver($pdo, $productId, 'promotion', '2026-10-15', date('Y-m-d H:i:s'), '{"cancelled": true}');
$driversCancelled = $service->getEffectiveDrivers($pdo, $productId, '2026-10-15', date('Y-m-d H:i:s', time() + 5));
assert_same('{"cancelled": true}', $driversCancelled[0]['details_json'], 'Must return cancellation status.');

echo "Domain seam tests passed.\n";
