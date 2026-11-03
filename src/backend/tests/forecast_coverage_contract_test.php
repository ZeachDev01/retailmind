<?php
declare(strict_types=1);

/**
 * Contract test for Ticket #411:
 * "Show trustworthy forecast coverage and buying guidance"
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';
require_once __DIR__ . '/../app/Forecasting/ReplenishmentQueueService.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';

use App\Forecasting\DemandForecastService;
use App\Forecasting\ReplenishmentQueueService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "  [FAIL] {$message}\n";
    } else {
        echo "  [PASS] {$message}\n";
    }
};

echo "Starting Ticket #411 Forecast Coverage and Buying Guidance Contract Test...\n\n";

function createTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("
        CREATE TABLE platform_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT
        );
        CREATE TABLE ml_jobs (
            job_id INTEGER PRIMARY KEY AUTOINCREMENT,
            trigger_type TEXT NOT NULL,
            status TEXT NOT NULL,
            queued_at DATETIME NOT NULL,
            completed_at DATETIME,
            error_message TEXT
        );
        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT NOT NULL,
            product_name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            reorder_level INTEGER NOT NULL DEFAULT 10,
            safety_stock INTEGER NOT NULL DEFAULT 5,
            minimum_order_quantity INTEGER NOT NULL DEFAULT 1,
            units_per_package INTEGER NOT NULL DEFAULT 1,
            supplier_lead_time_days INTEGER NOT NULL DEFAULT 7
        );
        CREATE TABLE inventory (
            inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL UNIQUE,
            quantity_on_hand INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE demand_forecasts (
            forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            forecast_origin DATE NOT NULL,
            target_date DATE NOT NULL,
            horizon_day INTEGER NOT NULL,
            predicted_units INTEGER NOT NULL,
            model_version TEXT NOT NULL,
            readiness_level TEXT NOT NULL,
            limitation_marker TEXT
        );
        CREATE TABLE model_training_runs (
            training_run_id INTEGER PRIMARY KEY AUTOINCREMENT,
            model_version TEXT NOT NULL,
            status TEXT NOT NULL,
            metrics_json TEXT,
            started_at DATETIME,
            completed_at DATETIME
        );
    ");

    return $pdo;
}

function insert28DailyForecasts(
    PDO $pdo,
    int $jobId,
    int $productId,
    string $origin,
    int $dailyUnits = 2,
    string $modelVersion = 'v1.0.0',
    string $readiness = 'High',
    ?string $limitation = null
): void {
    $stmt = $pdo->prepare("
        INSERT INTO demand_forecasts
        (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level, limitation_marker)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    for ($h = 1; $h <= 28; $h++) {
        $targetDate = date('Y-m-d', strtotime("{$origin} +{$h} days"));
        $stmt->execute([
            $jobId,
            $productId,
            $origin,
            $targetDate,
            $h,
            $dailyUnits,
            $modelVersion,
            $readiness,
            $limitation,
        ]);
    }
}

// -----------------------------------------------------------------------------
// Test 1: Empty Store handling
// -----------------------------------------------------------------------------
echo "--- Test 1: Empty Store handling ---\n";
$pdo = createTestPdo();
$service = new DemandForecastService($pdo);
$coverage = $service->getCoverage(null, '2026-11-02');

$assert($coverage['scope_count'] === 0, 'Empty store has scope_count 0');
$assert($coverage['forecast_backed_count'] === 0, 'Empty store has forecast_backed_count 0');
$assert($coverage['stock_rule_count'] === 0, 'Empty store has stock_rule_count 0');
$assert($coverage['is_guidance_usable'] === false, 'Empty store guidance is not usable');
$assert($coverage['store_status'] === 'empty', 'Empty store status is empty');
$assert(empty($coverage['products']), 'Empty store products list is empty');

// -----------------------------------------------------------------------------
// Test 2: Active vs Inactive products in scope denominator
// -----------------------------------------------------------------------------
echo "\n--- Test 2: Active vs Inactive products in scope denominator ---\n";
$pdo = createTestPdo();
$pdo->exec("
    INSERT INTO products (product_id, sku, product_name, status) VALUES
    (1, 'ACT-1', 'Active Product 1', 'active'),
    (2, 'ACT-2', 'Active Product 2', 'active'),
    (3, 'INA-1', 'Archived Product 3', 'archived'),
    (4, 'DIS-1', 'Discontinued Product 4', 'inactive');
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES
    (1, 10), (2, 5), (3, 0), (4, 0);
    INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'v1.0.0');
    INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at)
    VALUES (10, 'forecast_generation', 'succeeded', '2026-11-02 01:00:00', '2026-11-02 01:05:00');
");
// Product 1 has complete forecast for accepted origin 2026-11-01
insert28DailyForecasts($pdo, 10, 1, '2026-11-01', 3, 'v1.0.0', 'High');

$service = new DemandForecastService($pdo);
$coverage = $service->getCoverage(10, '2026-11-02');

$assert($coverage['scope_count'] === 2, 'Scope denominator counts ONLY active replenishment products (2 active)');
$assert($coverage['forecast_backed_count'] === 1, 'Product 1 is forecast backed');
$assert($coverage['stock_rule_count'] === 1, 'Product 2 uses stock rules');
$assert($coverage['store_status'] === 'partial', 'Store status is partial coverage');
$assert(count($coverage['products']) === 2, 'Products list contains exactly 2 active products');

// Index by product_id
$pById = array_column($coverage['products'], null, 'product_id');
$assert($pById[1]['is_forecast_backed'] === true, 'Product 1 is_forecast_backed is true');
$assert($pById[1]['basis'] === 'Demand Forecast', 'Product 1 basis is Demand Forecast');
$assert($pById[1]['expected_sales_28_day'] === 84, 'Product 1 expected sales is 84 (28 * 3)');
$assert($pById[1]['exclusion_reason'] === null, 'Product 1 has null exclusion reason');

$assert($pById[2]['is_forecast_backed'] === false, 'Product 2 is_forecast_backed is false');
$assert($pById[2]['basis'] === 'Stock-rule suggestion', 'Product 2 basis is Stock-rule suggestion');
$assert($pById[2]['expected_sales_28_day'] === null, 'Product 2 expected sales is null (unknown, not zero)');
$assert(str_contains($pById[2]['exclusion_reason'] ?? '', 'Forecast unavailable'), 'Product 2 exclusion reason explains missing forecast');
$assert($pById[2]['exclusion_category'] === 'evidence', 'Product 2 exclusion category is evidence');

// -----------------------------------------------------------------------------
// Test 3: Coverage summary text & search independence
// -----------------------------------------------------------------------------
echo "\n--- Test 3: Coverage summary text & search independence ---\n";
// Coverage summary text
$assert(str_contains($coverage['coverage_summary_text'], '1 of 2'), 'Coverage summary text mentions 1 of 2 products');

// Now query with a search filter that matches only Product 2
$filteredCoverage = $service->getCoverage(10, '2026-11-02', 'Active Product 2');
$assert($filteredCoverage['scope_count'] === 2, 'Search filter does NOT change Store-wide scope count (remains 2)');
$assert($filteredCoverage['forecast_backed_count'] === 1, 'Search filter does NOT change Store-wide forecast count (remains 1)');
$assert($filteredCoverage['stock_rule_count'] === 1, 'Search filter does NOT change Store-wide stock rule count (remains 1)');
$assert($filteredCoverage['filtered']['is_filtered'] === true, 'Filtered metadata indicates search was active');
$assert($filteredCoverage['filtered']['count'] === 1, 'Filtered count is 1');
$assert($filteredCoverage['filtered']['forecast_backed_count'] === 0, 'Filtered forecast backed count is 0');
$assert($filteredCoverage['filtered']['stock_rule_count'] === 1, 'Filtered stock rule count is 1');
$assert(count($filteredCoverage['filtered']['products']) === 1, 'Filtered products list contains 1 product');

// -----------------------------------------------------------------------------
// Test 4: Stale forecasts vs accepted latest-completed Philippine-day origin
// -----------------------------------------------------------------------------
echo "\n--- Test 4: Stale forecasts vs accepted latest-completed Philippine-day origin ---\n";
// On 2026-11-02 in Asia/Manila, the latest completed day is 2026-11-01.
// If forecast origin is 2026-10-30, it is STALE.
$pdo = createTestPdo();
$pdo->exec("
    INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'P1', 'Product 1', 'active');
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 10);
    INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'v1.0.0');
    INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at)
    VALUES (10, 'forecast_generation', 'succeeded', '2026-10-31 01:00:00', '2026-10-31 01:05:00');
");
insert28DailyForecasts($pdo, 10, 1, '2026-10-30', 4, 'v1.0.0', 'High');

$service = new DemandForecastService($pdo);
$staleCoverage = $service->getCoverage(10, '2026-11-02');
$assert($staleCoverage['forecast_backed_count'] === 0, 'Stale forecast must not be counted as forecast-backed');
$assert($staleCoverage['stock_rule_count'] === 1, 'Stale forecast product falls back to stock rules');
$assert($staleCoverage['store_status'] === 'stale', 'Store status identifies stale forecast');
$p1 = $staleCoverage['products'][0];
$assert($p1['expected_sales_28_day'] === null, 'Stale product expected sales is null, not 112');
$assert(str_contains($p1['exclusion_reason'], 'Stale forecast'), 'Exclusion reason identifies stale forecast');
$assert($p1['exclusion_category'] === 'freshness', 'Exclusion category is freshness');

// -----------------------------------------------------------------------------
// Test 5: Unapproved model & Replaced model
// -----------------------------------------------------------------------------
echo "\n--- Test 5: Unapproved model & Replaced model ---\n";
$pdo = createTestPdo();
$pdo->exec("
    INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'P1', 'Product 1', 'active');
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 10);
    INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'v2.0.0');
    INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at)
    VALUES (10, 'forecast_generation', 'succeeded', '2026-11-02 01:00:00', '2026-11-02 01:05:00');
");
// Forecast generated with v1.0.0, but approved model is now v2.0.0
insert28DailyForecasts($pdo, 10, 1, '2026-11-01', 5, 'v1.0.0', 'High');

$service = new DemandForecastService($pdo);
$unapprovedCoverage = $service->getCoverage(10, '2026-11-02');
$assert($unapprovedCoverage['forecast_backed_count'] === 0, 'Replaced/unapproved model forecast is not forecast-backed');
$assert($unapprovedCoverage['stock_rule_count'] === 1, 'Product uses stock rules');
$assert(str_contains($unapprovedCoverage['products'][0]['exclusion_reason'], 'Unapproved model'), 'Exclusion reason identifies unapproved model');
$assert($unapprovedCoverage['products'][0]['exclusion_category'] === 'approval', 'Exclusion category is approval');

// -----------------------------------------------------------------------------
// Test 6: Incomplete forecast & Insufficient readiness
// -----------------------------------------------------------------------------
echo "\n--- Test 6: Incomplete forecast & Insufficient readiness ---\n";
$pdo = createTestPdo();
$pdo->exec("
    INSERT INTO products (product_id, sku, product_name, status) VALUES
    (1, 'P1', 'Product Incomplete', 'active'),
    (2, 'P2', 'Product Insufficient', 'active');
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 10), (2, 10);
    INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'v1.0.0');
    INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at)
    VALUES (10, 'forecast_generation', 'succeeded', '2026-11-02 01:00:00', '2026-11-02 01:05:00');
");
// P1 has only 27 daily forecasts
$stmt = $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level) VALUES (10, 1, '2026-11-01', ?, ?, 2, 'v1.0.0', 'High')");
for ($h = 1; $h <= 27; $h++) {
    $stmt->execute([date('Y-m-d', strtotime("2026-11-01 +$h days")), $h]);
}
// P2 has 28 daily forecasts but readiness is 'Insufficient'
insert28DailyForecasts($pdo, 10, 2, '2026-11-01', 2, 'v1.0.0', 'Insufficient');

$service = new DemandForecastService($pdo);
$cov = $service->getCoverage(10, '2026-11-02');
$assert($cov['forecast_backed_count'] === 0, 'Neither incomplete nor insufficient readiness is forecast-backed');
$covById = array_column($cov['products'], null, 'product_id');
$assert(str_contains($covById[1]['exclusion_reason'], 'Incomplete forecast'), 'P1 exclusion explains incomplete forecast');
$assert($covById[1]['exclusion_category'] === 'evidence', 'P1 category is evidence');
$assert(str_contains($covById[2]['exclusion_reason'], 'Insufficient history'), 'P2 exclusion explains insufficient history');
$assert($covById[2]['exclusion_category'] === 'evidence', 'P2 category is evidence');

// -----------------------------------------------------------------------------
// Test 7: Preserving still-eligible guidance when latest update attempt failed
// -----------------------------------------------------------------------------
echo "\n--- Test 7: Preserving still-eligible guidance when latest update attempt failed ---\n";
$pdo = createTestPdo();
$pdo->exec("
    INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'P1', 'Product 1', 'active');
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 10);
    INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'v1.0.0');
    -- Job 10: succeeded at 01:00 today with origin 2026-11-01
    INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at)
    VALUES (10, 'forecast_generation', 'succeeded', '2026-11-02 01:00:00', '2026-11-02 01:05:00');
    -- Job 11: failed attempt at 03:00 today
    INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at, error_message)
    VALUES (11, 'forecast_generation', 'failed', '2026-11-02 03:00:00', '2026-11-02 03:01:00', 'Worker memory error');
");
insert28DailyForecasts($pdo, 10, 1, '2026-11-01', 4, 'v1.0.0', 'High');

$service = new DemandForecastService($pdo);
// When calling without specifying job ID, it should resolve the latest usable job (10) while noting job 11 failed
$cov = $service->getCoverage(null, '2026-11-02');
$assert($cov['effective_job_id'] === 10, 'Effective job ID resolves to still-eligible job 10');
$assert($cov['is_guidance_usable'] === true, 'Guidance is usable from earlier job 10');
$assert($cov['forecast_backed_count'] === 1, 'Product 1 is forecast backed');
$assert($cov['latest_attempt_status'] === 'failed', 'Latest attempt status is failed');
$assert($cov['latest_job']['job_id'] === 11, 'Latest job is recognized as job 11');
$assert(str_contains($cov['coverage_summary_text'], 'retry scheduled') || str_contains($cov['coverage_summary_text'], 'update failed'),
    'Coverage text notes failed latest attempt while preserving guidance');

$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at)
    VALUES (12, 'forecast_generation', 'succeeded', '2026-11-02 04:00:00', '2026-11-02 04:05:00')");
insert28DailyForecasts($pdo, 12, 1, '2026-11-01', 9, 'unapproved_candidate', 'High');
$unapprovedReplacement = $service->getCoverage(null, '2026-11-02');
$assert($unapprovedReplacement['forecast_backed_count'] === 1, 'A later unapproved run cannot supersede still-eligible approved guidance');
$pdo->exec("UPDATE demand_forecasts SET model_version = 'v1.0.0' WHERE job_id = 12; DELETE FROM demand_forecasts WHERE job_id = 12 AND horizon_day = 28");
$incompleteReplacement = $service->getCoverage(null, '2026-11-02');
$assert($incompleteReplacement['effective_job_id'] === 10 && $incompleteReplacement['forecast_backed_count'] === 1, 'A later incomplete run cannot supersede complete approved guidance');

// -----------------------------------------------------------------------------
// Test 8: Pending job is not success; guidance uses stock rules if no prior usable job
// -----------------------------------------------------------------------------
echo "\n--- Test 8: Pending job is not success ---\n";
$pdo = createTestPdo();
$pdo->exec("
    INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'P1', 'Product 1', 'active');
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 10);
    INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'v1.0.0');
    INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at)
    VALUES (1, 'forecast_generation', 'running', '2026-11-02 01:00:00');
");
$service = new DemandForecastService($pdo);
$cov = $service->getCoverage(null, '2026-11-02');
$assert($cov['is_updating'] === true, 'is_updating is true when job is running');
$assert($cov['is_guidance_usable'] === false, 'Pending job does NOT count as usable guidance');
$assert($cov['forecast_backed_count'] === 0, 'No products forecast backed while pending');
$assert($cov['stock_rule_count'] === 1, 'Product uses stock rules');

// -----------------------------------------------------------------------------
// Test 9: DemandForecastService and ReplenishmentQueueService Agreement
// -----------------------------------------------------------------------------
echo "\n--- Test 9: Agreement between DemandForecastService and ReplenishmentQueueService ---\n";
$pdo = createTestPdo();
$pdo->exec("
    INSERT INTO products (product_id, sku, product_name, status, reorder_level, safety_stock, minimum_order_quantity, units_per_package) VALUES
    (1, 'P1', 'Product 1', 'active', 10, 5, 2, 4),
    (2, 'P2', 'Product 2', 'active', 10, 5, 2, 4);
    INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 5), (2, 20);
    INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'v1.0.0');
    INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at)
    VALUES (10, 'forecast_generation', 'succeeded', '2026-11-02 01:00:00', '2026-11-02 01:05:00');
");
// Product 1 forecast backed; Product 2 has no forecast
insert28DailyForecasts($pdo, 10, 1, '2026-11-01', 3, 'v1.0.0', 'High');

$demandService = new DemandForecastService($pdo);
$queueService = new ReplenishmentQueueService($pdo);

$cov = $demandService->getCoverage(10, '2026-11-02');
$recs = $queueService->getRecommendations(10, '2026-11-02');

$recsById = array_column($recs, null, 'product_id');
$covById = array_column($cov['products'], null, 'product_id');

$assert($cov['scope_count'] === count($recs), 'Scope count matches recommendations count exactly');
$assert($covById[1]['basis'] === $recsById[1]['basis'], 'P1 basis agrees between services');
$assert($covById[1]['expected_sales_28_day'] === $recsById[1]['expected_sales_28_day'], 'P1 expected sales agrees between services');
$assert($covById[2]['basis'] === $recsById[2]['basis'], 'P2 basis agrees between services');
$assert($covById[2]['expected_sales_28_day'] === null && $recsById[2]['expected_sales_28_day'] === null, 'P2 null expected sales agrees between services');

// Check products needing no positive purchase suggestion explanation
// P2 has current_stock 20, reorder_level 10 -> stock_rule_quantity is 0
$assert($recsById[2]['recommended_quantity'] === 0, 'P2 recommended quantity is 0 because stock is above reorder level');
$assert(str_contains($recsById[2]['explanation'], 'sufficient') || str_contains($recsById[2]['explanation'], 'above reorder'),
    'P2 explanation explains stock is sufficient and no purchase is needed');

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n================================================================================\n";
if (empty($failures)) {
    echo "PASS: Ticket #411 Forecast Coverage Contract passed cleanly!\n";
    exit(0);
} else {
    echo "FAIL: " . count($failures) . " assertions failed:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
