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

if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Database integration tests: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Database/MigrationRunner.php';

echo "Testing Promotions and Products Workflow seam...\n";

$runner = new \App\Database\MigrationRunner($pdo, __DIR__ . '/../database/migrations');
$runner->runPending();

$pdo->exec("DELETE FROM scheduled_demand_drivers");

// Fetch existing user
$stmt = $pdo->query("SELECT user_id FROM users LIMIT 1");
$userId = (int)$stmt->fetchColumn();
if (!$userId) {
    $pdo->exec("INSERT INTO roles (role_name) VALUES ('Admin')");
    $roleId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO users (username, password_hash, full_name, role_id, status) VALUES ('testuser', 'hash', 'Test User', $roleId, 'active')");
    $userId = (int)$pdo->lastInsertId();
}

// Ensure store scope
$stmt = $pdo->query("SELECT branch_id FROM branches LIMIT 1");
$branchId = (int)$stmt->fetchColumn();
if (!$branchId) {
    $pdo->exec("INSERT INTO branches (branch_code, branch_name, status) VALUES ('B1', 'Test Branch', 'active')");
    $branchId = (int)$pdo->lastInsertId();
}
$_SESSION['user_id'] = $userId;
$_SESSION['branch_id'] = $branchId;
$_SESSION['role_id'] = 1; // Admin to bypass capability checks if any

require_once __DIR__ . '/../app/Services/ProductService.php';
$productService = new \ProductService($pdo);

// 1. Create a product with a selling price
$productId = $productService->createProduct([
    'product_name' => 'Integration Test Product',
    'barcode' => 'INT-TEST-01',
    'selling_price' => 123.45,
    'cost_price' => 100.0,
    'category_id' => null
], $userId);

// Check if scheduled demand driver for price was created
$stmt = $pdo->prepare("SELECT * FROM scheduled_demand_drivers WHERE product_id = ? AND driver_type = 'price'");
$stmt->execute([$productId]);
$priceDrivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
assert_same(1, count($priceDrivers), 'Price driver must be recorded on creation.');
$details = json_decode($priceDrivers[0]['details_json'], true);
assert_same(123.45, (float)$details['unit_price'], 'Price details must match on creation.');

// 2. Update price via purchaseStock
$productService->purchaseStock([
    'search_term' => 'INT-TEST-01',
    'purchase_quantity' => 5,
    'purchase_cost_price' => 100.0,
    'selling_price' => 150.0
], $userId);

$stmt->execute([$productId]);
$priceDrivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
assert_same(2, count($priceDrivers), 'Second price driver must be recorded on purchase stock update.');

$latestDriver = end($priceDrivers);
$details = json_decode($latestDriver['details_json'], true);
assert_same(150.0, (float)$details['unit_price'], 'Price details must match on update.');

echo "Promotions and Products Workflow seam tests passed.\n";
