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
        CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT);
        INSERT INTO ml_jobs VALUES (10, 'forecast_generation', 'succeeded');
        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT,
            product_name TEXT,
            status TEXT,
            supplier_lead_time_days INTEGER,
            minimum_order_quantity INTEGER,
            reorder_level INTEGER,
            safety_stock INTEGER,
            units_per_package INTEGER
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
            status TEXT,
            expected_delivery_date TEXT
        );
        CREATE TABLE purchase_order_items (
            purchase_order_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            purchase_order_id INTEGER,
            product_id INTEGER,
            ordered_qty INTEGER,
            received_qty INTEGER
        );
    ");

    $asOfDate = '2026-10-09';

    // Product 1: Urgent. Stock 0, Inbound 0, Lead time demand 10. Exposure -10.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (1, 'SKU1', 'P1 Urgent', 'active', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 0)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 1, 7, 10, '$asOfDate', 'High')");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 1, 28, 20, '$asOfDate', 'High')");

    // Product 2: Watch. Stock 5, Inbound 0, Lead time 7. Demand is 4, 7-day demand 10. Exposure = 1.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (2, 'SKU2', 'P2 Watch', 'active', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 5)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 2, 7, 4, '$asOfDate', 'High')");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 2, 14, 10, '$asOfDate', 'High')");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 2, 28, 15, '$asOfDate', 'High')");

    // Product 3: Covered. Stock 100.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (3, 'SKU3', 'P3 Covered', 'active', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (3, 100)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 3, 7, 1, '$asOfDate', 'High')");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 3, 28, 5, '$asOfDate', 'High')");

    // Product 4: Ties with P1 (exposure -10). P4 has product_id 4.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (4, 'SKU4', 'P4 Tie', 'active', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (4, 0)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 4, 7, 10, '$asOfDate', 'Low')");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 4, 28, 20, '$asOfDate', 'Low')");

    // Product 5: Missing forecast completely (no rows in demand_forecasts). Must be 'Unknown', never zero demand or Covered.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (5, 'SKU5', 'P5 Missing Forecast', 'active', 7, 10)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (5, 20)");

    // Product 6: Zero forecast (row exists in demand_forecasts, but predicted_units = 0 across horizon). Stock 8.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (6, 'SKU6', 'P6 Zero Forecast', 'active', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (6, 8)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 6, 7, 0, '$asOfDate', 'High')");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 6, 28, 0, '$asOfDate', 'High')");

    // Product 7: Reliable vs Late/Unknown Inbound.
    // Stock 0, Lead time 7 days, Lead time demand 15.
    // PO 1 (timely): expected in 3 days (2026-10-12), 10 units. Counted!
    // PO 2 (late): expected in 20 days (2026-10-29), 20 units. Outside 7-day lead time! Excluded.
    // PO 3 (untimed): expected_delivery_date = NULL, 15 units. Excluded.
    // PO 4 (overdue): expected in past (2026-10-05), 5 units. Excluded.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (7, 'SKU7', 'P7 Inbound Timing', 'active', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (7, 0)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 7, 7, 15, '$asOfDate', 'High')");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 7, 28, 30, '$asOfDate', 'High')");

    $pdo->exec("INSERT INTO purchase_orders (purchase_order_id, status, expected_delivery_date) VALUES (1, 'approved', '2026-10-12')");
    $pdo->exec("INSERT INTO purchase_order_items (purchase_order_id, product_id, ordered_qty, received_qty) VALUES (1, 7, 10, 0)");

    $pdo->exec("INSERT INTO purchase_orders (purchase_order_id, status, expected_delivery_date) VALUES (2, 'sent', '2026-10-29')");
    $pdo->exec("INSERT INTO purchase_order_items (purchase_order_id, product_id, ordered_qty, received_qty) VALUES (2, 7, 20, 0)");

    $pdo->exec("INSERT INTO purchase_orders (purchase_order_id, status, expected_delivery_date) VALUES (3, 'approved', NULL)");
    $pdo->exec("INSERT INTO purchase_order_items (purchase_order_id, product_id, ordered_qty, received_qty) VALUES (3, 7, 15, 0)");

    $pdo->exec("INSERT INTO purchase_orders (purchase_order_id, status, expected_delivery_date) VALUES (4, 'approved', '2026-10-05')");
    $pdo->exec("INSERT INTO purchase_order_items (purchase_order_id, product_id, ordered_qty, received_qty) VALUES (4, 7, 5, 0)");

    // Product 8: MOQ Constraint rounding.
    // Stock 0, Inbound 0, Lead time demand 10, 28-day demand 15. Target = 25. Deficit = 25.
    // MOQ = 12. Recommended quantity must be ceil(25 / 12) * 12 = 36.
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (8, 'SKU8', 'P8 MOQ Rounding', 'active', 7, 12)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (8, 0)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 8, 7, 10, '$asOfDate', 'High')");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 8, 28, 15, '$asOfDate', 'High')");

    $initialPoCount = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn();
    $initialInventoryQty = (int)$pdo->query("SELECT SUM(quantity_on_hand) FROM inventory")->fetchColumn();

    $service = new ReplenishmentQueueService($pdo);
    $queue = $service->getQueue(10, $asOfDate);
    
    $assert(count($queue) === 8, "Expected 8 items in queue, got " . count($queue));

    // Verify non-binding property: queue retrieval never created POs or mutated stock
    $afterPoCount = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn();
    $afterInventoryQty = (int)$pdo->query("SELECT SUM(quantity_on_hand) FROM inventory")->fetchColumn();
    $assert($initialPoCount === $afterPoCount, "Queue generation must never create purchase orders");
    $assert($initialInventoryQty === $afterInventoryQty, "Queue generation must never modify inventory stock");

    // Index by product_id for granular checks
    $byProduct = [];
    foreach ($queue as $item) {
        $byProduct[$item['product_id']] = $item;
    }

    // P1 Urgent check
    $assert($byProduct[1]['urgency_label'] === 'Urgent', "P1 must be Urgent");
    $assert($byProduct[1]['stock_exposure'] === -10, "P1 exposure should be -10");

    // P4 Tie check
    $assert($byProduct[4]['urgency_label'] === 'Urgent', "P4 must be Urgent");
    $assert($byProduct[4]['stock_exposure'] === -10, "P4 exposure should be -10");
    $assert($byProduct[4]['readiness_level'] === 'Low', "P4 readiness should be Low");

    // Product 7: Open POs in database must be ignored. Goods bought outside RetailMind count only when received.
    // Stock 0, Lead time 7 days, Lead time demand 15. Exposure = 0 - 15 = -15.
    $assert($byProduct[7]['urgency_label'] === 'Urgent', "P7 must be Urgent");
    $assert($byProduct[7]['inbound_quantity'] === 0, "P7 inbound must be 0 (open POs ignored), got " . $byProduct[7]['inbound_quantity']);
    $assert($byProduct[7]['total_inbound_quantity'] === 0, "P7 total inbound must be 0, got " . $byProduct[7]['total_inbound_quantity']);
    $assert($byProduct[7]['stock_exposure'] === -15, "P7 exposure must be -15, got " . $byProduct[7]['stock_exposure']);

    // P8 MOQ check
    $assert($byProduct[8]['urgency_label'] === 'Urgent', "P8 must be Urgent");
    $assert($byProduct[8]['recommended_quantity'] === 36, "P8 recommended quantity must round up to MOQ multiple (36), got " . $byProduct[8]['recommended_quantity']);

    // Product 9: Supplier-free product with custom product-level planning settings
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity, reorder_level, safety_stock, units_per_package)
        VALUES (9, 'SKU9', 'P9 Supplier Free', 'active', 5, 8, 15, 10, 4)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (9, 0)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 9, 5, 12, '$asOfDate', 'High')");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level) VALUES (10, 9, 28, 20, '$asOfDate', 'High')");

    $queueWithP9 = $service->getQueue(10, $asOfDate);
    $p9Queue = array_column($queueWithP9, null, 'product_id')[9] ?? null;
    $assert($p9Queue !== null, "P9 must be present in queue without supplier records");
    $assert($p9Queue['lead_time_days'] === 5, "P9 lead time must be 5 from product-level settings, got " . ($p9Queue['lead_time_days'] ?? 'null'));
    $assert($p9Queue['moq'] === 8, "P9 MOQ must be 8 from product-level settings, got " . ($p9Queue['moq'] ?? 'null'));
    // Target = leadTimeDemand (12) + f28 (12 + 20 = 32) = 44. Deficit = 44. MOQ = 8. ceil(44 / 8) * 8 = 48.
    $assert($p9Queue['recommended_quantity'] === 48, "P9 recommended quantity must be 48, got " . ($p9Queue['recommended_quantity'] ?? 'null'));

    // Test getRecommendations() method
    $recs = $service->getRecommendations(10);
    $recsByPid = array_column($recs, null, 'product_id');
    $assert(isset($recsByPid[9]), "P9 must be present in getRecommendations");
    $assert($recsByPid[9]['basis'] === 'Demand Forecast', "P9 basis must be Demand Forecast");
    $assert($recsByPid[9]['recommended_quantity'] === 48, "P9 recommendation with package size 4 must be 48");

    // Stock rule only: product with no forecast
    $assert(isset($recsByPid[5]), "P5 must be present in getRecommendations");
    $assert($recsByPid[5]['basis'] === 'Stock rules', "P5 basis must be Stock rules");
    $assert($recsByPid[5]['forecast_status'] === 'Forecast unavailable', "P5 forecast_status must be Forecast unavailable");

    // Committed stock update reflection
    $pdo->exec("UPDATE inventory SET quantity_on_hand = 48 WHERE product_id = 9");
    $recsAfterStock = $service->getRecommendations(10);
    $p9After = array_column($recsAfterStock, null, 'product_id')[9];
    $assert($p9After['current_stock'] === 48, "P9 current_stock must reflect committed inventory");
    $assert($p9After['recommended_quantity'] === 0, "P9 suggested units must be 0 after receiving stock");

    // Non-mutation check
    $finalPoCount = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn();
    $assert($finalPoCount === $initialPoCount, "Recommendations retrieval must never mutate purchase orders");

    // P2 Watch check
    $assert($byProduct[2]['urgency_label'] === 'Watch', "P2 must be Watch");
    $assert($byProduct[2]['stock_exposure'] === 1, "P2 exposure should be 1");

    // P5 Missing forecast check
    $assert($byProduct[5]['urgency_label'] === 'Unknown', "P5 missing forecast must be Unknown, got " . $byProduct[5]['urgency_label']);
    $assert($byProduct[5]['stock_exposure'] === null, "P5 missing forecast exposure must be null");
    $assert($byProduct[5]['readiness_level'] === null, "P5 missing forecast readiness must be null");
    $assert($byProduct[5]['forecast_7_day'] === null, "P5 missing forecast 7-day demand must be null");
    $assert($byProduct[5]['recommended_quantity'] === 0, "P5 missing forecast recommended quantity must be 0");

    // P6 Zero forecast check
    $assert($byProduct[6]['urgency_label'] === 'Covered', "P6 zero forecast must be Covered, got " . $byProduct[6]['urgency_label']);
    $assert($byProduct[6]['stock_exposure'] === 8, "P6 zero forecast exposure should be 8");
    $assert($byProduct[6]['recommended_quantity'] === 0, "P6 zero forecast recommended quantity should be 0");

    // P3 Covered check
    $assert($byProduct[3]['urgency_label'] === 'Covered', "P3 must be Covered");
    $assert($byProduct[3]['stock_exposure'] === 99, "P3 exposure should be 99");

    // Verify Risk Ordering:
    // Urgent (rank 1): P7 (-15), P1 (-10), P4 (-10), P8 (-10)
    // Watch (rank 2): P2 (1)
    // Unknown (rank 3): P5 (null)
    // Covered (rank 4): P6 (8), P3 (99)
    $urgencies = array_map(fn($item) => $item['urgency_label'], $queue);
    
    // First 4 must be Urgent
    for ($i = 0; $i < 4; $i++) {
        $assert($urgencies[$i] === 'Urgent', "Queue index $i must be Urgent, got " . $urgencies[$i]);
    }
    // Index 4 must be Watch
    $assert($urgencies[4] === 'Watch', "Queue index 4 must be Watch, got " . $urgencies[4]);
    // Index 5 must be Unknown
    $assert($urgencies[5] === 'Unknown', "Queue index 5 must be Unknown, got " . $urgencies[5]);
    // Last 2 must be Covered
    $assert($urgencies[6] === 'Covered', "Queue index 6 must be Covered, got " . $urgencies[6]);
    $assert($urgencies[7] === 'Covered', "Queue index 7 must be Covered, got " . $urgencies[7]);

    // Check tie breaking on P1 vs P4 (both Urgent exposure -10; P7 is exposure -15)
    $assert($queue[1]['product_id'] === 1, "P1 should precede P4 due to tie-break on product_id");
    $assert($queue[2]['product_id'] === 4, "P4 should follow P1 due to tie-break on product_id");

    $pdo->exec("UPDATE ml_jobs SET trigger_type = 'forecast_observation' WHERE job_id = 10");
    $assert($service->getQueue(10, $asOfDate) === [], 'Observation job must not expose purchasing recommendations even with explicit job ID');

    if (empty($failures)) {
        echo "PASS: replenishment_queue_contract\n";
    } else {
        echo "FAIL:\n" . implode("\n", $failures) . "\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
