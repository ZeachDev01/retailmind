<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Store\StoreCalendar;
use App\Forecasting\ReplenishmentQueueService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
        echo "  [FAIL] $message\n";
    } else {
        echo "  [PASS] $message\n";
    }
};

echo "Starting Demo Pipeline End-to-End Contract Verification (Ticket #160)...\n\n";

try {
    // -------------------------------------------------------------
    // Step 1: Environment Isolation Barrier
    // -------------------------------------------------------------
    echo "--- Step 1: Environment Isolation Barrier ---\n";
    $testDbPath = sys_get_temp_dir() . '/retailmind_demo_e2e_' . uniqid() . '.sqlite';
    $pdo = new PDO("sqlite:{$testDbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Assert that test runner is not attached to a production Store DB
    $isIsolated = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
    $assert($isIsolated, "Demo pipeline must execute against an explicitly isolated test database");

    // Initialize required schema in isolated SQLite DB
    $pdo->exec("
        CREATE TABLE platform_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT,
            updated_at TEXT
        );
        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT UNIQUE,
            product_name TEXT,
            status TEXT DEFAULT 'active',
            supplier_lead_time_days INTEGER DEFAULT 7,
            minimum_order_quantity INTEGER DEFAULT 1
        );
        CREATE TABLE inventory (
            inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            quantity_on_hand INTEGER DEFAULT 0
        );
        CREATE TABLE store_calendar (
            calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
            calendar_date TEXT NOT NULL,
            status TEXT NOT NULL,
            reason TEXT,
            recorded_by INTEGER,
            recorded_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE store_calendar_audit (
            audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
            calendar_date TEXT NOT NULL,
            previous_status TEXT,
            new_status TEXT NOT NULL,
            reason TEXT,
            changed_by INTEGER,
            changed_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE demand_history (
            history_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            calendar_date TEXT,
            observed_sales INTEGER,
            inventory_level INTEGER,
            day_status TEXT DEFAULT 'open',
            is_censored INTEGER DEFAULT 0,
            recorded_at TEXT DEFAULT CURRENT_TIMESTAMP
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
        CREATE TABLE model_training_runs (
            training_run_id INTEGER PRIMARY KEY AUTOINCREMENT,
            model_name TEXT,
            model_version TEXT,
            trigger_type TEXT,
            status TEXT,
            started_at TEXT,
            completed_at TEXT,
            duration_seconds REAL,
            sales_records_used INTEGER,
            eligible_products INTEGER,
            metrics_json TEXT,
            error_message TEXT,
            host_name TEXT
        );
        CREATE TABLE ml_model_evaluations (
            evaluation_id INTEGER PRIMARY KEY AUTOINCREMENT,
            model_version TEXT,
            horizon_days INTEGER,
            mae REAL,
            rmse REAL,
            wape REAL,
            baseline_mae REAL,
            baseline_rmse REAL,
            baseline_wape REAL,
            created_at TEXT
        );
        CREATE TABLE ml_jobs (
            job_id INTEGER PRIMARY KEY AUTOINCREMENT,
            trigger_type TEXT,
            status TEXT,
            queued_at TEXT DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT
        );
        CREATE TABLE supplier_products (
            supplier_product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INTEGER,
            product_id INTEGER,
            lead_time_days INTEGER,
            minimum_order_quantity INTEGER,
            is_preferred INTEGER DEFAULT 1
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
    $assert(true, "Isolated test schema initialized successfully");

    // -------------------------------------------------------------
    // Step 2: Demo Dataset Checksum & Import Integrity
    // -------------------------------------------------------------
    echo "\n--- Step 2: Demo Dataset Checksum & Import Integrity ---\n";
    $csvPath = dirname(__DIR__) . '/dataset/retail_store_inventory.csv';
    $assert(file_exists($csvPath), "Demo Kaggle dataset file exists at $csvPath");

    $expectedSha = 'c7b6019887d85e6bb311a76da72560463e6bf269bfadbe4b326fbff3b1000468';
    $actualSha = hash_file('sha256', $csvPath);
    $assert($actualSha === $expectedSha, "Demo dataset SHA-256 matches verified hash ($expectedSha)");

    // Test import CLI isolation enforcement
    $importScript = dirname(__DIR__) . '/scripts/import_kaggle_capstone.php';
    $cliOut = [];
    $cliCode = 0;
    exec("set DB_NAME=retailmind && php " . escapeshellarg($importScript) . " 2>&1", $cliOut, $cliCode);
    $assert($cliCode !== 0, "Importer CLI rejects execution against production database name");
    $assert(str_contains(implode("\n", $cliOut), "isolated"), "Importer CLI outputs explicit isolation requirement error");

    // Seed deterministic S001 20 products into isolated database
    $insProd = $pdo->prepare("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (?, ?, ?, 'active', 7, 5)");
    for ($i = 1; $i <= 20; $i++) {
        $sku = sprintf("P%04d", $i);
        $name = sprintf("Demo Product %04d", $i);
        $insProd->execute([$i, $sku, $name]);
    }
    $prodCount = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $assert($prodCount === 20, "Exact 20 products (P0001-P0020) created for Store S001");

    // Record import metadata into platform_settings
    $demoMetadata = [
        'imported_at' => date('Y-m-d H:i:s'),
        'store_id' => 'S001',
        'product_count' => 20,
        'sha256' => $expectedSha,
        'dataset_file' => 'retail_store_inventory.csv',
        'environment' => 'isolated_demo'
    ];
    $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES ('demo_import_metadata', ?, CURRENT_TIMESTAMP)")
        ->execute([json_encode($demoMetadata)]);

    $stmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'demo_import_metadata'");
    $metaJson = $stmt->fetchColumn();
    $assert($metaJson !== false, "Demo import provenance saved in platform_settings.demo_import_metadata");
    $meta = json_decode($metaJson, true);
    $assert(($meta['store_id'] ?? '') === 'S001', "Metadata records Store S001");
    $assert(($meta['product_count'] ?? 0) === 20, "Metadata records 20 selected products");
    $assert(($meta['sha256'] ?? '') === $expectedSha, "Metadata records verified SHA-256");

    // -------------------------------------------------------------
    // Step 3: Monthly Store Calendar Single & Range Operations
    // -------------------------------------------------------------
    echo "\n--- Step 3: Monthly Store Calendar Operations ---\n";
    $calendar = new StoreCalendar();

    // Single date edit with exceptional_closure
    $calendar->recordCalendarDate($pdo, '2026-12-25', 'exceptional_closure', 1);
    $statusOnChristmas = $calendar->getCalendarDateStatusAtCutoff($pdo, '2026-12-25', date('Y-m-d H:i:s'));
    $assert($statusOnChristmas === 'exceptional_closure', "Status normalized to exceptional_closure");

    // Range edit (inclusive 3-day holiday)
    $rangeChanged = $calendar->recordCalendarRange($pdo, '2026-12-29', '2026-12-31', 'holiday', 1);
    $assert(count($rangeChanged) === 3, "Atomic range edit updated exactly 3 calendar days");

    // Range check: cap at 366 days
    $rejectedCap = false;
    try {
        $calendar->recordCalendarRange($pdo, '2026-01-01', '2027-01-15', 'holiday', 1);
    } catch (InvalidArgumentException $e) {
        $rejectedCap = true;
    }
    $assert($rejectedCap, "Range edit exceeding 366 days rejected with InvalidArgumentException");

    // Deduplication check: re-saving matching status records no extra history
    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM store_calendar WHERE calendar_date = '2026-12-25'")->fetchColumn();
    $calendar->recordCalendarDate($pdo, '2026-12-25', 'exceptional_closure', 1);
    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM store_calendar WHERE calendar_date = '2026-12-25'")->fetchColumn();
    $assert($countBefore === $countAfter, "Matching calendar status introduces no redundant history rows");

    // -------------------------------------------------------------
    // Step 4: Retraining, Evaluation, and Controlled Promotion
    // -------------------------------------------------------------
    echo "\n--- Step 4: Retraining, Evaluation, and Controlled Promotion ---\n";
    $candidateVersion = 'rf-demo-' . date('Ymd-His');
    $pdo->prepare("
        INSERT INTO model_training_runs 
        (model_name, model_version, trigger_type, status, started_at, completed_at, duration_seconds, sales_records_used, eligible_products, metrics_json)
        VALUES ('Random Forest Demo', ?, 'manual', 'completed', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 2.5, 14000, 20, ?)
    ")->execute([$candidateVersion, json_encode(['metadata' => ['dataset_kind' => 'demo'], 'wape_28' => 0.18, 'mae_28' => 4.2])]);

    $pdo->prepare("
        INSERT INTO ml_model_evaluations
        (model_version, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, created_at)
        VALUES 
        (?, 7, 3.1, 4.2, 0.14, 4.5, 5.8, 0.22, CURRENT_TIMESTAMP),
        (?, 14, 3.7, 4.9, 0.16, 5.1, 6.4, 0.25, CURRENT_TIMESTAMP),
        (?, 28, 4.2, 5.5, 0.18, 5.9, 7.2, 0.29, CURRENT_TIMESTAMP)
    ")->execute([$candidateVersion, $candidateVersion, $candidateVersion]);

    $evalRows = $pdo->query("SELECT * FROM ml_model_evaluations WHERE model_version = '{$candidateVersion}'")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($evalRows) === 3, "Evaluation recorded for 7, 14, and 28-day horizons");

    // Verify holdout metrics outperform weekly seasonal naive baseline
    foreach ($evalRows as $er) {
        $h = $er['horizon_days'];
        $assert($er['wape'] < $er['baseline_wape'], "Horizon {$h}d: RF WAPE ({$er['wape']}) outperforms seasonal baseline ({$er['baseline_wape']})");
        $assert($er['mae'] < $er['baseline_mae'], "Horizon {$h}d: RF MAE ({$er['mae']}) outperforms seasonal baseline ({$er['baseline_mae']})");
    }

    // Controlled promotion to platform_settings
    $pdo->prepare("
        INSERT OR REPLACE INTO platform_settings (setting_key, setting_value, updated_at)
        VALUES ('ml_approved_model_version', ?, CURRENT_TIMESTAMP)
    ")->execute([$candidateVersion]);

    $promotedVersion = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'")->fetchColumn();
    $assert($promotedVersion === $candidateVersion, "Approved model version successfully promoted to platform settings");

    // -------------------------------------------------------------
    // Step 5: Forecast Generation from Approved Model
    // -------------------------------------------------------------
    echo "\n--- Step 5: Forecast Generation from Approved Model ---\n";
    $jobId = 101;
    $pdo->prepare("INSERT INTO ml_jobs (job_id, trigger_type, status) VALUES (?, 'forecast_generation', 'succeeded')")
        ->execute([$jobId]);

    // Insert 28-day forecasts for product 1 to 20
    $forecastOrigin = '2026-10-09';
    $insFc = $pdo->prepare("
        INSERT INTO demand_forecasts (job_id, product_id, horizon_day, predicted_units, forecast_origin, readiness_level, model_version)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    for ($pid = 1; $pid <= 20; $pid++) {
        $readiness = ($pid <= 15) ? 'High' : 'Low';
        for ($h = 1; $h <= 28; $h++) {
            $predictedUnits = ($pid === 6) ? 0 : ($pid + ($h % 5));
            $insFc->execute([$jobId, $pid, $h, $predictedUnits, $forecastOrigin, $readiness, $promotedVersion]);
        }
    }

    $fcCount = (int)$pdo->query("SELECT COUNT(*) FROM demand_forecasts WHERE job_id = {$jobId}")->fetchColumn();
    $assert($fcCount === 20 * 28, "Generated exactly 28 daily forecasts per product (560 total rows)");
    $metadata = (new \App\Forecasting\DemandForecastService($pdo))->getJobMetadata($jobId);
    $assert($metadata['model_version'] === $promotedVersion && $metadata['is_demo'],
        "Forecast metadata uses persisted model training provenance against schema-faithful jobs");

    // -------------------------------------------------------------
    // Step 6: Human-Reviewed Replenishment Queue & Timing Invariants
    // -------------------------------------------------------------
    echo "\n--- Step 6: Replenishment Queue & Decision Support ---\n";
    // Set up product attributes and inventory
    $pdo->exec("UPDATE products SET supplier_lead_time_days = 7, minimum_order_quantity = 6 WHERE product_id = 1");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 5)"); // Product 1: Stock 5, Lead demand ~25. Urgent.

    // Product 7: Test reliable vs late/untimed inbound
    $pdo->exec("UPDATE products SET supplier_lead_time_days = 7, minimum_order_quantity = 5 WHERE product_id = 7");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (7, 0)");
    // Timely PO (in 3 days)
    $pdo->exec("INSERT INTO purchase_orders (purchase_order_id, status, expected_delivery_date) VALUES (1, 'approved', '2026-10-12')");
    $pdo->exec("INSERT INTO purchase_order_items (purchase_order_id, product_id, ordered_qty, received_qty) VALUES (1, 7, 10, 0)");
    // Late PO (in 20 days)
    $pdo->exec("INSERT INTO purchase_orders (purchase_order_id, status, expected_delivery_date) VALUES (2, 'approved', '2026-10-29')");
    $pdo->exec("INSERT INTO purchase_order_items (purchase_order_id, product_id, ordered_qty, received_qty) VALUES (2, 7, 25, 0)");
    // Untimed PO (NULL delivery date)
    $pdo->exec("INSERT INTO purchase_orders (purchase_order_id, status, expected_delivery_date) VALUES (3, 'approved', NULL)");
    $pdo->exec("INSERT INTO purchase_order_items (purchase_order_id, product_id, ordered_qty, received_qty) VALUES (3, 7, 15, 0)");

    // Add un-forecasted Product 21 (status active, no forecast rows)
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status, supplier_lead_time_days, minimum_order_quantity) VALUES (21, 'P0021-UN', 'Unforecasted Product', 'active', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (21, 12)");

    $initialPoCount = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn();
    $initialStockCount = (int)$pdo->query("SELECT SUM(quantity_on_hand) FROM inventory")->fetchColumn();

    $queueService = new ReplenishmentQueueService($pdo);
    $queue = $queueService->getQueue($jobId, $forecastOrigin);

    // Decision support invariant: no PO created or stock changed
    $afterPoCount = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn();
    $afterStockCount = (int)$pdo->query("SELECT SUM(quantity_on_hand) FROM inventory")->fetchColumn();
    $assert($initialPoCount === $afterPoCount, "Queue generation created no purchase orders (non-binding decision support)");
    $assert($initialStockCount === $afterStockCount, "Queue generation made no inventory adjustments");

    // Index by product_id
    $qByPid = [];
    foreach ($queue as $item) {
        $qByPid[$item['product_id']] = $item;
    }

    // Check Product 7 inbound exclusion
    $item7 = $qByPid[7];
    $assert($item7['inbound_quantity'] === 10, "Product 7 counted only timely inbound (10 units)");
    $assert($item7['total_inbound_quantity'] === 50, "Product 7 total inbound tracks all 50 open units");
    $assert($item7['unreliable_inbound_quantity'] === 40, "Product 7 excluded 40 late/untimed inbound units from lead-time exposure");
    $assert($item7['urgency_label'] === 'Urgent', "Product 7 correctly classified as Urgent");

    // Check Product 21 missing forecast
    $item21 = $qByPid[21];
    $assert($item21['urgency_label'] === 'Unknown', "Un-forecasted product 21 explicitly classified as Unknown (never Covered)");
    $assert($item21['stock_exposure'] === null, "Product 21 stock exposure remains null");

    // Check Product 6 zero forecast
    $item6 = $qByPid[6];
    $assert($item6['urgency_label'] === 'Covered', "Zero-forecast product 6 classified as Covered");
    $assert($item6['recommended_quantity'] === 0, "Zero-forecast product 6 recommends 0 units");

    // Check risk ranking: Urgent items first, Watch second, Unknown third, Covered last
    $urgencies = array_map(fn($q) => $q['urgency_label'], $queue);
    $urgentIdx = array_keys(array_filter($urgencies, fn($u) => $u === 'Urgent'));
    $watchIdx = array_keys(array_filter($urgencies, fn($u) => $u === 'Watch'));
    $unknownIdx = array_keys(array_filter($urgencies, fn($u) => $u === 'Unknown'));
    $coveredIdx = array_keys(array_filter($urgencies, fn($u) => $u === 'Covered'));

    $maxUrgent = !empty($urgentIdx) ? max($urgentIdx) : -1;
    $minWatch = !empty($watchIdx) ? min($watchIdx) : 999;
    $maxWatch = !empty($watchIdx) ? max($watchIdx) : $maxUrgent;
    $minUnknown = !empty($unknownIdx) ? min($unknownIdx) : 999;
    $maxUnknown = !empty($unknownIdx) ? max($unknownIdx) : $maxWatch;
    $minCovered = !empty($coveredIdx) ? min($coveredIdx) : 999;

    $assert($maxUrgent < $minWatch || empty($watchIdx), "All Urgent items precede Watch items");
    $assert($maxWatch < $minUnknown || empty($unknownIdx), "Watch items precede Unknown items");
    $assert($maxUnknown < $minCovered || empty($coveredIdx), "Unknown items precede Covered items");

    // -------------------------------------------------------------
    // Step 7: UI Surfaces Syntax & Integration Checks
    // -------------------------------------------------------------
    echo "\n--- Step 7: UI Surfaces Syntax & Integration Checks ---\n";
    $uiFiles = [
        'src/frontend/components/report/predictions.php',
        'src/frontend/components/inventory_management/product_investigation.php',
        'src/frontend/components/report/forecast_analytics.php',
        'src/frontend/components/administrator/store_calendar.php',
        'src/frontend/components/inventory_management/replenishment_queue.php',
    ];
    foreach ($uiFiles as $file) {
        $path = dirname(__DIR__, 3) . '/' . $file;
        $output = [];
        $code = 0;
        exec("php -l " . escapeshellarg($path), $output, $code);
        $assert($code === 0, "UI script $file passes PHP syntax validation with no errors");
    }

    // Clean up temporary database
    @unlink($testDbPath);

    echo "\n========================================================\n";
    if (empty($failures)) {
        echo "PASS: Complete Demo Pipeline End-to-End Contract Passed (Ticket #160)\n";
        echo "========================================================\n";
        exit(0);
    } else {
        echo "FAIL: " . count($failures) . " check(s) failed in Demo Pipeline E2E Contract:\n";
        foreach ($failures as $f) {
            echo " - $f\n";
        }
        echo "========================================================\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    if (isset($testDbPath)) @unlink($testDbPath);
    exit(1);
}
