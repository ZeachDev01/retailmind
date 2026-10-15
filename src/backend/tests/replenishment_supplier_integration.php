<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_REPLENISHMENT_DB_TESTS') !== '1') {
    echo "Replenishment suppliers: skipped (set RUN_REPLENISHMENT_DB_TESTS=1; requires CREATE DATABASE)\n";
    exit;
}
require_once __DIR__ . '/../bootstrap/app.php';

$config = require __DIR__ . '/../config/database.php';
$connect = static fn (?string $database = null): PDO => new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4%s', $config['host'], $config['port'], $database ? ';dbname=' . $database : ''),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false]);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$database = 'retailmind_replenishment_test_' . bin2hex(random_bytes(6));
$server = $connect();
$created = false;
$failed = false;
try {
    $server->exec("CREATE DATABASE `{$database}`");
    $created = true;
    $pdo = $connect($database);
    $pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("CREATE TABLE ml_jobs (job_id INT PRIMARY KEY, trigger_type VARCHAR(50), status VARCHAR(20));
        INSERT INTO ml_jobs VALUES (10, 'forecast_generation', 'succeeded');
        CREATE TABLE products (product_id INT PRIMARY KEY, sku VARCHAR(30), product_name VARCHAR(50),
            status VARCHAR(20), supplier_lead_time_days INT, minimum_order_quantity INT);
        CREATE TABLE inventory (product_id INT PRIMARY KEY, quantity_on_hand INT);
        CREATE TABLE supplier_products (supplier_product_id INT PRIMARY KEY, supplier_id INT,
            product_id INT, lead_time_days INT, minimum_order_quantity INT, is_preferred INT,
            UNIQUE KEY uq_supplier_product (supplier_id, product_id));
        CREATE TABLE demand_forecasts (forecast_id INT AUTO_INCREMENT PRIMARY KEY, job_id INT,
            product_id INT, horizon_day INT, predicted_units INT, readiness_level VARCHAR(20), forecast_origin DATE);
        INSERT INTO products VALUES (1, 'MULTIPLE', 'Multiple preferred', 'active', 7, 5),
            (2, 'SINGLE', 'One preferred', 'active', 7, 5),
            (3, 'FALLBACK', 'No preferred', 'active', 4, 6),
            (4, 'DEFAULT', 'No terms', 'active', NULL, NULL),
            (5, 'NULL-TERMS', 'Preferred null terms', 'active', 4, 6),
            (6, 'NO-SUPPLIER', 'No supplier entry', 'active', 3, 10);
        INSERT INTO supplier_products VALUES (30, 3, 1, 2, 4, 1), (20, 2, 1, 5, 12, 1),
            (10, 1, 1, 1, 1, 0), (40, 1, 2, 2, 8, 1), (50, 1, 3, 1, 20, 0),
            (60, 1, 5, NULL, NULL, 1), (70, 2, 5, 2, 8, 1)");
    $insert = $pdo->prepare("INSERT INTO demand_forecasts
        (job_id, product_id, horizon_day, predicted_units, readiness_level, forecast_origin)
        VALUES (10, ?, ?, 2, 'High', '2026-10-09')");
    foreach ([1, 2, 3, 4, 5, 6] as $productId) {
        foreach (range(1, 7) as $day) $insert->execute([$productId, $day]);
    }
    $service = new App\Forecasting\ReplenishmentQueueService($pdo);
    foreach (range(1, 2) as $run) {
        $queue = $service->getQueue(10, '2026-10-09');
        $assert(count($queue) === 6, 'Each product must have exactly one queue row');
        $rows = array_column($queue, null, 'product_id');
        // Supplier-products table rows must be ignored; product-level settings and defaults apply
        foreach ([
            1 => [7, 5, 14, 30],
            2 => [7, 5, 14, 30],
            3 => [4, 6, 8, 24],
            4 => [7, 1, 14, 28],
            5 => [4, 6, 8, 24],
            6 => [3, 10, 6, 20],
        ] as $id => $expected) {
            $actual = array_map(static fn ($key) => $rows[$id][$key],
                ['lead_time_days', 'moq', 'forecast_lead_time', 'recommended_quantity']);
            $assert($actual === $expected, "Product {$id}: product-level settings and recommendations must agree");
        }
    }
    echo "Replenishment supplier MySQL integration passed (ONLY_FULL_GROUP_BY).\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $failed = true;
} finally {
    if ($created) $server->exec("DROP DATABASE `{$database}`");
}
if ($failed) exit(1);
