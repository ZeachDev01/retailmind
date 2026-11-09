<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Forecasting\DemandHistoryService;
use App\Forecasting\StoreTrainingEligibilityService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
        echo "  [FAIL] $message\n";
    } else {
        echo "  [PASS] $message\n";
    }
};

echo "Testing StoreTrainingEligibilityService & DemandHistoryService Ticket #365...\n\n";

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqliteFn = static function (PDO $pdo, string $name, callable $callback, int $numArgs = -1): void {
        if ($pdo instanceof \Pdo\Sqlite || method_exists($pdo, 'createFunction')) {
            $pdo->createFunction($name, $callback, $numArgs);
            return;
        }
        @$pdo->sqliteCreateFunction($name, $callback, $numArgs);
    };
    $sqliteFn($pdo, 'NOW', static fn() => '2026-11-02 01:00:00');
    $sqliteFn($pdo, 'CURDATE', static fn() => '2026-11-02');
    $sqliteFn($pdo, 'DATEDIFF', static fn($d1, $d2) => (int)round((strtotime((string)$d1) - strtotime((string)$d2)) / 86400));
    $sqliteFn($pdo, 'GREATEST', static fn(...$args) => max($args));

    $pdo->exec("
        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY,
            sku TEXT,
            barcode TEXT,
            product_name TEXT,
            category_id INTEGER,
            branch_id INTEGER DEFAULT 1,
            reorder_level INTEGER DEFAULT 10,
            supplier_lead_time_days INTEGER DEFAULT 7,
            safety_stock INTEGER DEFAULT 0,
            minimum_order_quantity INTEGER DEFAULT 1,
            units_per_package INTEGER DEFAULT 1,
            preferred_supplier TEXT,
            supplier TEXT,
            status TEXT DEFAULT 'active'
        );
        CREATE TABLE categories (
            category_id INTEGER PRIMARY KEY,
            category_name TEXT
        );
        CREATE TABLE inventory (
            product_id INTEGER PRIMARY KEY,
            quantity_on_hand INTEGER DEFAULT 100
        );
        CREATE TABLE forecast_sales_imports (
            product_id INTEGER,
            sale_date TEXT,
            quantity INTEGER,
            imported_at TEXT DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (product_id, sale_date)
        );
        CREATE TABLE store_calendar (
            calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
            calendar_date TEXT NOT NULL,
            status TEXT NOT NULL,
            recorded_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE store_weekly_schedule (
            schedule_id INTEGER PRIMARY KEY AUTOINCREMENT,
            effective_date TEXT NOT NULL,
            monday_open INTEGER DEFAULT 1,
            tuesday_open INTEGER DEFAULT 1,
            wednesday_open INTEGER DEFAULT 1,
            thursday_open INTEGER DEFAULT 1,
            friday_open INTEGER DEFAULT 1,
            saturday_open INTEGER DEFAULT 0,
            sunday_open INTEGER DEFAULT 0,
            recorded_by INTEGER DEFAULT 1,
            source TEXT DEFAULT 'manual',
            recorded_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE sales (
            sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER DEFAULT 1,
            sale_date TEXT NOT NULL
        );
        CREATE TABLE sale_items (
            sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER,
            product_id INTEGER,
            quantity INTEGER,
            selling_form TEXT DEFAULT 'base',
            pack_size INTEGER DEFAULT 1
        );
        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            change_qty INTEGER,
            moved_at TEXT
        );
        CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, created_at TEXT);
        CREATE TABLE cash_refund_items (refund_id INTEGER, sale_item_id INTEGER, product_id INTEGER, quantity INTEGER);
        CREATE TABLE sale_reversals (reversal_id INTEGER PRIMARY KEY, status TEXT, approved_at TEXT);
        CREATE TABLE sale_reversal_items (reversal_id INTEGER, sale_item_id INTEGER, product_id INTEGER, quantity INTEGER);
        CREATE TABLE scheduled_demand_drivers (
            driver_id INTEGER PRIMARY KEY,
            product_id INTEGER,
            driver_type TEXT,
            effective_date TEXT,
            scheduled_at TEXT
        );
        CREATE TABLE platform_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT
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
            readiness_level TEXT,
            limitation_marker TEXT
        );
        CREATE TABLE stock_predictions (
            prediction_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            forecast_run_id INTEGER,
            forecast_period_days INTEGER,
            forecast_value REAL,
            predicted_demand_next_7_days REAL,
            predicted_demand_next_30_days REAL,
            lower_bound_7_days REAL,
            upper_bound_7_days REAL,
            lower_bound_30_days REAL,
            upper_bound_30_days REAL,
            forecasted_demand_during_lead_time REAL,
            lead_time_lower_bound REAL,
            lead_time_upper_bound REAL,
            actual_demand REAL,
            evaluation_result TEXT,
            supplier_lead_time_days INTEGER,
            safety_stock_used REAL,
            current_stock_used REAL,
            incoming_stock_used REAL,
            minimum_order_quantity_used REAL,
            units_per_package_used REAL,
            reorder_suggested INTEGER,
            suggested_reorder_qty REAL,
            confidence_score REAL,
            forecast_explanation TEXT,
            model_version TEXT,
            generated_at TEXT
        );
        CREATE TABLE forecast_evaluations (
            evaluation_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            mae REAL,
            rmse REAL,
            wape REAL,
            smape REAL,
            generated_at TEXT
        );
    ");

    // Products:
    // P1: Monday-Friday store, 140 accumulated usable days across 28 weeks (no gaps), clean holdouts -> Ready for training
    // P2: Monday-Friday store, only 139 accumulated usable days (1 day short) -> Blocked (Needs at least 140)
    // P3: Monday-Friday store, 145 accumulated usable days, but has stockout in recent feature window -> Blocked (unsupported recent inputs)
    // P4: Monday-Friday store, 145 accumulated usable days, clean recent window, but stockout in holdout period -> Blocked (insufficient holdout coverage)
    $pdo->exec("
        INSERT INTO products (product_id, sku, product_name, status) VALUES
            (1, 'SKU-001', 'Product 1 Mature Mon-Fri', 'active'),
            (2, 'SKU-002', 'Product 2 Under 140 Days', 'active'),
            (3, 'SKU-003', 'Product 3 Recent Stockout', 'active'),
            (4, 'SKU-004', 'Product 4 Holdout Stockout', 'active'),
            (5, 'SKU-005', 'Product 5 Holiday Closure', 'active'),
            (6, 'SKU-006', 'Product 6 Missing Evidence Gap', 'active'),
            (7, 'SKU-007', 'Product 7 Genuine Zero Days', 'active');
    ");

    // Holiday closure on Wednesday 2024-07-17 in store_calendar
    $pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2024-07-17', 'closed', '2024-01-01 00:00:00')");

    // Mon-Fri schedule effective 2024-01-01
    $pdo->exec("
        INSERT INTO store_weekly_schedule
            (effective_date, monday_open, tuesday_open, wednesday_open, thursday_open, friday_open, saturday_open, sunday_open, recorded_at)
        VALUES ('2024-01-01', 1, 1, 1, 1, 1, 0, 0, '2024-01-01 00:00:00');
    ");

    // Generate 30 weeks of calendar days starting 2024-01-01 (Monday)
    // Cutoff: 2024-07-28 (Sunday)
    $cutoff = '2024-07-28';
    $start = new DateTimeImmutable('2024-01-01');
    $current = $start;
    $end = new DateTimeImmutable($cutoff);

    $insertImport = $pdo->prepare("INSERT INTO forecast_sales_imports (product_id, sale_date, quantity) VALUES (?, ?, ?)");
    
    // We populate P1..P4
    $p1Usable = 0;
    $p2Usable = 0;
    $p3Usable = 0;
    $p4Usable = 0;
    $p7ZeroDays = 0;

    // Holdout periods (3 x 28 days = 84 calendar days before cutoff 2024-07-28):
    // Holdout 3: 2024-07-01 to 2024-07-28 (28 days)
    // Holdout 2: 2024-06-03 to 2024-06-30 (28 days)
    // Holdout 1: 2024-05-06 to 2024-06-02 (28 days)
    // Recent feature window at cutoff: 2024-07-01 to 2024-07-28

    while ($current <= $end) {
        $dateStr = $current->format('Y-m-d');
        $dow = (int)$current->format('N'); // 1 = Monday, 7 = Sunday
        $isWeekday = ($dow <= 5);

        if ($isWeekday) {
            // P1: Always has sales on weekdays (150 total weekdays)
            $insertImport->execute([1, $dateStr, 5]);
            $p1Usable++;

            // P2: Skipped first 11 weekdays -> 139 total weekdays
            if ($p1Usable > 10) {
                $insertImport->execute([2, $dateStr, 5]);
                $p2Usable++;
            }

            // P3: Has 150 weekdays, but we inject a stockout on 2024-07-25 (in recent window)
            // For P3, on 2024-07-25 we don't insert sales, or we insert stockout movement
            if ($dateStr !== '2024-07-25') {
                $insertImport->execute([3, $dateStr, 5]);
                $p3Usable++;
            }

            // P4: Has 150 weekdays, but we inject a stockout on 2024-05-15 (inside Holdout 1)
            if ($dateStr !== '2024-05-15') {
                $insertImport->execute([4, $dateStr, 5]);
                $p4Usable++;
            }

            // P5: Has 149 weekdays, with supported holiday closure on Wed 2024-07-17 (closed in store_calendar)
            if ($dateStr !== '2024-07-17') {
                $insertImport->execute([5, $dateStr, 5]);
            }

            // P6: Has missing evidence gap on Wed 2024-07-10 (not closed in calendar, no sales, no stock)
            if ($dateStr !== '2024-07-10') {
                $insertImport->execute([6, $dateStr, 5]);
            }

            // P7: Genuine observed zeros on the 1st and 15th of each month count as usable
            // days without counting as days with sales.
            if (in_array($current->format('d'), ['01', '15'], true)) {
                $insertImport->execute([7, $dateStr, 0]);
                $p7ZeroDays++;
            } else {
                $insertImport->execute([7, $dateStr, 5]);
            }
        }

        $current = $current->modify('+1 day');
    }

    // Now insert stockout evidence for P3 on 2024-07-25 and P4 on 2024-05-15
    // Initial balance + movement to zero
    $pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, moved_at) VALUES (?, ?, ?)")
        ->execute([3, 0, '2024-07-25 08:00:00']);
    $pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, moved_at) VALUES (?, ?, ?)")
        ->execute([4, 0, '2024-05-15 08:00:00']);
    $pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, moved_at) VALUES (?, ?, ?)")
        ->execute([4, 100, '2024-05-16 08:00:00']);

    $service = new StoreTrainingEligibilityService();
    
    // Test P1:
    $res1 = $service->evaluateProductEligibility($pdo, 1, $cutoff);
    $assert($res1['accumulated_usable_days'] >= 140, "P1 must have >= 140 accumulated usable days (actual: {$res1['accumulated_usable_days']})");
    $assert($res1['has_feature_window'] === true, "P1 must have valid recent feature window with Mon-Fri weekend closures");
    $assert($res1['has_holdout_coverage'] === true, "P1 must have 3-holdout coverage");
    $assert($res1['eligible'] === true, "P1 must be eligible for training");
    $assert($res1['status'] === 'Ready for training', "P1 status must be 'Ready for training'");
    $assert($res1['reason'] === null, "P1 reason must be null when ready");

    // Test P2:
    $res2 = $service->evaluateProductEligibility($pdo, 2, $cutoff);
    $assert($res2['accumulated_usable_days'] === 139, "P2 must have exactly 139 accumulated usable days");
    $assert($res2['eligible'] === false, "P2 must be ineligible due to 139 < 140 days");
    $assert(str_contains((string)$res2['reason'], '140'), "P2 reason must state 140 days required");
    $assert(str_contains((string)$res2['reason'], '139'), "P2 reason must show current count 139");

    $pdo->prepare("INSERT INTO forecast_sales_imports (product_id, sale_date, quantity, imported_at) VALUES (2, '2024-01-12', 0, '2024-07-29 00:00:00')")->execute();
    $res140 = $service->evaluateProductEligibility($pdo, 2, $cutoff);
    $assert($res140['accumulated_usable_days'] === 140 && $res140['eligible'], "Exactly 140 usable days including a genuine zero meets the requirement");
    $pdo->exec("DELETE FROM forecast_sales_imports WHERE product_id = 2 AND sale_date = '2024-01-12'");

    // Test P3:
    $res3 = $service->evaluateProductEligibility($pdo, 3, $cutoff);
    $assert($res3['accumulated_usable_days'] >= 140, "P3 has >= 140 accumulated days");
    $assert($res3['has_feature_window'] === false, "P3 must fail recent feature window due to stockout gap");
    $assert($res3['eligible'] === false, "P3 must not be eligible");
    $assert(str_contains(strtolower((string)$res3['reason']), 'recent') || str_contains(strtolower((string)$res3['reason']), 'feature window'),
            "P3 reason must identify unsupported recent inputs: " . $res3['reason']);

    // Test P4:
    $res4 = $service->evaluateProductEligibility($pdo, 4, $cutoff);
    $assert($res4['accumulated_usable_days'] >= 140, "P4 has >= 140 accumulated days");
    $assert($res4['has_holdout_coverage'] === false, "P4 must fail holdout coverage due to stockout in holdout");
    $assert($res4['eligible'] === false, "P4 must not be eligible");
    $assert(str_contains(strtolower((string)$res4['reason']), 'holdout') || str_contains(strtolower((string)$res4['reason']), 'evaluation'),
            "P4 reason must identify insufficient evaluation coverage: " . $res4['reason']);

    // Test P5 (Holiday closure):
    $res5 = $service->evaluateProductEligibility($pdo, 5, $cutoff);
    $assert($res5['accumulated_usable_days'] >= 140, "P5 has >= 140 accumulated days");
    $assert($res5['has_feature_window'] === true, "P5 must pass feature window: supported holiday closure does not block");
    $assert($res5['has_holdout_coverage'] === true, "P5 must pass holdout coverage with holiday closure");
    $assert($res5['eligible'] === true, "P5 must be eligible for training with supported closure");
    $assert($res5['status'] === 'Ready for training', "P5 status is Ready for training");

    // Test P6 (Missing evidence gap):
    $res6 = $service->evaluateProductEligibility($pdo, 6, $cutoff);
    $assert($res6['accumulated_usable_days'] >= 140, "P6 has >= 140 accumulated days");
    $assert($res6['has_feature_window'] === false, "P6 must fail feature window due to missing evidence gap on 2024-07-10");
    $assert($res6['eligible'] === false, "P6 must not be eligible");
    $assert(str_contains(strtolower((string)$res6['reason']), 'missing') || str_contains(strtolower((string)$res6['reason']), 'unsupported'),
            "P6 reason identifies missing evidence: " . $res6['reason']);

    // Ticket #422: one effective policy, usable days separate from elapsed span and days with sales.
    $policy = StoreTrainingEligibilityService::policy();
    $assert($policy['minimum_usable_days'] === 140, "Policy exposes the fixed 140 usable-day requirement");
    $assert($policy['input_window_days'] === 28, "Policy exposes the supported 28-calendar-day recent input window");
    $assert($policy['evaluation_periods'] === 3 && $policy['evaluation_period_days'] === 28,
        "Policy exposes three chronological 28-day evaluation periods");
    $assert(!isset($policy['configurable']), "Policy is not configurable");

    $assert($res1['days_with_sales'] === 150, "P1 counts recorded sales on its closed date separately from usable days");
    $assert($res1['accumulated_usable_days'] === 149, "P1 counts 149 usable days including the closed day exclusion");
    $assert($res1['genuine_zero_days'] === 0, "P1 has no genuine zero days");
    $assert($res1['calendar_span_days'] === 210, "P1 elapsed calendar span is 210 days, not 149");
    $assert($res1['closed_days'] === 61, "P1 records supported weekend closures without counting them usable");
    $assert($res1['usable_days_remaining'] === 0, "P1 has no usable days remaining");

    $assert($res2['usable_days_remaining'] === 1, "P2 is exactly one usable day short of 140");
    $assert($res2['days_with_sales'] === 140, "P2 days with sales includes its closed date with imported sales");
    $assert(!empty($res2['actions']), "P2 exposes an actionable usable-day blocker");
    $assert(str_contains($res2['actions'][0], '1 more usable day'), "P2 action states how many usable days remain");

    $res7 = $service->evaluateProductEligibility($pdo, 7, $cutoff);
    $assert($res7['eligible'] === true, "P7 with genuine observed zeros is eligible");
    $assert($res7['accumulated_usable_days'] === 149, "P7 genuine zeros count as usable days");
    $assert($res7['genuine_zero_days'] === 12, "P7 records 12 genuine observed zero days");
    $assert($res7['days_with_sales'] === 138, "P7 days with sales excludes genuine zeros");

    $assert($res5['accumulated_usable_days'] === 149, "P5 supported closure does not add a usable day");
    $assert($res5['closed_days'] >= 2, "P5 records the holiday closure alongside weekend closures");
    $assert($res5['eligible'] === true, "P5 supported closure does not invalidate otherwise supported windows");
    $assert($res3['stockout_days'] >= 1, "P3 records stockout days separately");
    $partialSales = $service->evaluateProductEligibility($pdo, 3, '2024-07-25', [
        '2024-07-24' => ['classification' => 'valid_demand', 'valid_sales_units' => 5],
        '2024-07-25' => ['classification' => 'stockout_censored', 'valid_sales_units' => 3],
    ]);
    $assert($partialSales['days_with_sales'] === 2, "Sales before a stockout still count as a day with sales");
    $assert($partialSales['accumulated_usable_days'] === 1 && $partialSales['stockout_days'] === 1,
        "A stockout day with recorded sales remains unusable for training");
    $assert($res3['accumulated_usable_days'] === 147, "P3 stockout days do not add usable days (stockouts: {$res3['stockout_days']})");
    $assert(!empty($res3['actions']) && str_contains($res3['actions'][0], '2024-07-25'),
        "P3 recent-input blocker names the unsupported date and the required action");
    $assert($res6['missing_evidence_days'] >= 1, "P6 records unknown observation days separately");
    $assert($res6['accumulated_usable_days'] === 148, "P6 unknown observation does not add a usable day");
    $assert(!empty($res4['actions']) && str_contains(strtolower($res4['actions'][0]), 'evaluation'),
        "P4 evaluation blocker explains the missing chronological evidence");

    // Test CandidateEvaluationService prospective observation with closures and stockouts (#415):
    require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
    $evalService = new App\Forecasting\CandidateEvaluationService($pdo);
    $candidateVersion = 'v_candidate_closure_test';

    // Insert 28 daily forecasts for Product 1 from 2024-07-01 to 2024-07-28
    $insForecast = $pdo->prepare("INSERT INTO demand_forecasts
        (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level, limitation_marker)
        VALUES (900, 1, '2024-06-30', ?, ?, 5, '{$candidateVersion}', 'Medium', 'Observation only')");

    for ($h = 1; $h <= 28; $h++) {
        $tDate = date('Y-m-d', strtotime("2024-06-30 +$h days"));
        $insForecast->execute([$tDate, $h]);
    }

    $obsProgress1 = $evalService->getCandidateObservationProgress($candidateVersion, '2024-07-28');
    // In that period: Mon-Fri store has 20 trading days (sales=5), 8 weekend closures (closed), 1 holiday (2024-07-17 closed)
    // Usable observed days = 19 (excluding 1 holiday + 8 weekends)
    $assert($obsProgress1['observation_complete'] === false, "Candidate observation must be incomplete (under 28 completed usable days)");
    $assert($obsProgress1['status'] === 'under_evaluation', "Candidate status is under_evaluation");
    $assert($obsProgress1['closures_paused'] >= 8, "Supported closures are recorded as paused without resetting observation");
    $assert(str_contains($obsProgress1['explanation'], 'closures pause accumulation'), "Explanation confirms closures pause accumulation");

    // Test DemandHistoryService::getTrainingHistory integration:
    $demandService = new DemandHistoryService();
    $trainingPayload = $demandService->getTrainingHistory($pdo, $cutoff);
    $eligibilityMap = [];
    foreach ($trainingPayload['eligibility'] as $item) {
        $eligibilityMap[$item['product_id']] = $item;
    }
    $assert($eligibilityMap[1]['eligible'] === true, "DemandHistoryService reports P1 eligible");
    $assert($eligibilityMap[1]['days_with_sales'] === 150, "Training history retains sales-day counts through recorded-sales evidence");
    $assert($eligibilityMap[2]['eligible'] === false, "DemandHistoryService reports P2 ineligible");
    $assert($eligibilityMap[3]['eligible'] === false, "DemandHistoryService reports P3 ineligible");
    $assert($eligibilityMap[4]['eligible'] === false, "DemandHistoryService reports P4 ineligible");

    // Only P1 history is exported
    $exportedPids = array_unique(array_column($trainingPayload['history'], 'product_id'));
    $assert(in_array(1, $exportedPids, true) && in_array(5, $exportedPids, true) && !in_array(6, $exportedPids, true), "Only mature eligible Products (1 and 5) exported for training");

    // Test get_forecasting_readiness() parity with worker eligibility:
    if (!function_exists('store_scope_id')) {
        function store_scope_id(PDO $pdo): int { return 1; }
    }
    require_once __DIR__ . '/../includes/functions.php';
    $readinessRows = get_forecasting_readiness($pdo, $cutoff);
    $readinessByPid = [];
    foreach ($readinessRows as $r) {
        $readinessByPid[(int)$r['product_id']] = $r;
    }
    $assert($readinessByPid[1]['forecast_status'] === 'Ready for training', "Data readiness reports P1 as 'Ready for training'");
    $assert($readinessByPid[1]['accumulated_usable_days'] >= 140, "Data readiness shows accumulated usable days >= 140");
    $assert($readinessByPid[2]['forecast_status'] === 'Insufficient Data', "Data readiness reports P2 as 'Insufficient Data'");
    $assert(str_contains($readinessByPid[2]['forecast_reason'], '140'), "Data readiness explains P2 requires 140 accumulated days");
    $assert($readinessByPid[3]['forecast_status'] === 'Insufficient Data', "Data readiness reports P3 as 'Insufficient Data' due to recent stockout");
    $assert($readinessByPid[4]['forecast_status'] === 'Insufficient Data', "Data readiness reports P4 as 'Insufficient Data' due to holdout stockout");

    // Ticket #422: readiness exposes the same policy and separates usable days from span/sales days.
    $assert($readinessByPid[1]['training_eligible'] === true, "Data readiness exposes training eligibility separately");
    $assert($readinessByPid[1]['required_usable_days'] === 140, "Data readiness shows the fixed 140-day requirement");
    $assert($readinessByPid[1]['days_with_sales'] === 150, "Data readiness shows days with sales separately");
    $assert($readinessByPid[1]['calendar_history_days'] === 210, "Data readiness keeps the elapsed calendar span separate");
    $assert($readinessByPid[2]['usable_days_remaining'] === 1, "Data readiness shows one usable day remaining for P2");
    $assert(!empty($readinessByPid[2]['eligibility_actions']), "Data readiness exposes actionable eligibility blockers");
    $assert($readinessByPid[7]['days_with_sales'] === 138, "Data readiness reports genuine zeros separately from days with sales");
    $assert($readinessByPid[7]['accumulated_usable_days'] === 149, "Data readiness counts genuine zeros as usable days");
    $assert(!str_contains(strtolower((string)$readinessByPid[1]['forecast_reason']), 'minimum history'),
        "Data readiness no longer describes a configurable minimum history");
    $assert(!str_contains(strtolower((string)$readinessByPid[1]['forecast_reason']), 'preferred'),
        "Data readiness no longer describes a preferred history target");
    $assert(str_contains((string)$readinessByPid[1]['forecast_reason'], 'Forecast Readiness'),
        "Data readiness distinguishes eligibility from Forecast Readiness bands");

    echo "\nAll StoreTrainingEligibilityService PHP tests passed!\n";
} catch (Throwable $e) {
    $failures[] = "Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    echo "  [EXCEPTION] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

if (!empty($failures)) {
    echo "\nTotal failures: " . count($failures) . "\n";
    exit(1);
}
