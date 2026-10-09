<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Forecasting\ReplenishmentQueueService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT,
            product_name TEXT,
            status TEXT,
            supplier_lead_time_days INTEGER,
            minimum_order_quantity INTEGER
        );
        CREATE TABLE inventory (
            inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            quantity_on_hand INTEGER
        );
        CREATE TABLE demand_forecasts (
            forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER,
            product_id INTEGER,
            horizon_day INTEGER,
            predicted_units INTEGER,
            forecast_origin TEXT,
            readiness_level TEXT,
            model_version TEXT
        );
        CREATE TABLE supplier_products (
            supplier_product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INTEGER,
            product_id INTEGER,
            lead_time_days INTEGER,
            minimum_order_quantity INTEGER,
            is_preferred INTEGER
        );
        CREATE TABLE purchase_orders (
            purchase_order_id INTEGER PRIMARY KEY AUTOINCREMENT,
            status TEXT
        );
        CREATE TABLE purchase_order_items (
            purchase_order_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            purchase_order_id INTEGER,
            product_id INTEGER,
            ordered_qty INTEGER,
            received_qty INTEGER
        );
    ");

    // Product 1: Urgent. Stock 0, Inbound 0, Lead time demand 10. Exposure -10.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (1, 'SKU1', 'P1 Urgent', 'active', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 0)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 1, 7, 10, '2026-10-09', 'High')");

    // Product 2: Watch. Stock 5, Inbound 0, Lead time 7. Demand is 5 (so exposure = 0). It covers lead time, but watch out.
    // Wait, my logic was if exposure < forecast_7_day * 0.5. Let's make demand 4, so exposure 1. 7 day demand 10.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (2, 'SKU2', 'P2 Watch', 'active', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 5)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 2, 7, 4, '2026-10-09', 'High')");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 2, 14, 10, '2026-10-09', 'High')");

    // Product 3: Covered. Stock 100.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (3, 'SKU3', 'P3 Covered', 'active', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (3, 100)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 3, 7, 1, '2026-10-09', 'High')");

    // Product 4: Ties with P1 (exposure -10). P4 has product_id 4.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (4, 'SKU4', 'P4 Tie', 'active', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (4, 0)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 4, 7, 10, '2026-10-09', 'Low')");

    $service = new ReplenishmentQueueService($pdo);
    $queue = $service->getQueue(10);
    
    $assert(count($queue) === 4, "Expected 4 items in queue");
    
    // Sort by exposure ascending, then product_id ascending
    $assert($queue[0]['product_id'] === 1, "P1 should be first");
    $assert($queue[1]['product_id'] === 4, "P4 should be second due to tie on exposure -10");
    $assert($queue[2]['product_id'] === 2, "P2 should be third (Watch)");
    $assert($queue[3]['product_id'] === 3, "P3 should be fourth (Covered)");

    // Test arithmetic
    $assert($queue[0]['stock_exposure'] === -10, "P1 exposure should be -10");
    $assert($queue[2]['urgency_label'] === 'Watch', "P2 should be Watch, got " . $queue[2]['urgency_label']);
    $assert($queue[3]['urgency_label'] === 'Covered', "P3 should be Covered, got " . $queue[3]['urgency_label']);

    // Test low readiness
    $assert($queue[3]['readiness_level'] === 'High', "P3 should have High readiness");
    $assert($queue[1]['readiness_level'] === 'Low', "P4 should have Low readiness");

    if (empty($failures)) {
        echo "PASS: replenishment_queue_contract\n";
    } else {
        echo "FAIL:\n" . implode("\n", $failures) . "\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
