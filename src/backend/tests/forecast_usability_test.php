<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Forecasting\ForecastUsability;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "  [FAIL] {$message}\n";
    } else {
        echo "  [PASS] {$message}\n";
    }
};

echo "Starting Ticket #432 ForecastUsability contract test...\n\n";

$job = [
    'job_id' => 10,
    'trigger_type' => 'forecast_generation',
    'status' => 'succeeded',
    'attempts' => 1,
];

$aggregate = [
    'product_id' => 1,
    'forecast_origin' => '2026-11-01',
    'model_version' => 'v1',
    'readiness_level' => 'High',
    'limitation_marker' => null,
    'forecast_7_day' => 7,
    'forecast_14_day' => 14,
    'forecast_28_day' => 28,
    'complete_forecast' => true,
    'job_id' => 10,
];

$context = [
    'observation' => false,
    'suspended' => false,
    'approved_model_version' => 'v1',
    'is_demo_db' => false,
    'expected_origin' => '2026-11-01',
    'latest_usable_job_id' => 10,
    'job_id' => 10,
    'job_meta' => ['is_demo' => false, 'dataset_kind' => 'store', 'feature_version' => '2.0.0'],
];

// -----------------------------------------------------------------------------
// assess: the buying-usability decision
// -----------------------------------------------------------------------------

// Baseline: complete, approved, current, compatible Store forecast.
$r = ForecastUsability::assess($aggregate, $job, $context);
$assert($r['usable'] === true, 'Baseline complete approved forecast is usable');
$assert($r['status'] === 'High readiness forecast', 'Usable status is the readiness band');
$assert($r['exclusion_reason'] === null && $r['exclusion_category'] === null && $r['exclusion_action'] === null,
    'Usable forecast has no exclusion metadata');

// Observation Mode.
$obs = ForecastUsability::assess($aggregate, $job, ['observation' => true] + $context);
$assert($obs['usable'] === false, 'Observation Mode is not usable');
$assert($obs['exclusion_reason'] === 'Observation Mode: forecast is observation telemetry only', 'Observation reason is exact');
$assert($obs['exclusion_category'] === 'restriction', 'Observation category is restriction');
$assert($obs['exclusion_action'] === 'Administrator: Complete candidate observation', 'Observation action is exact');
$assert($obs['status'] === 'Observation Mode: forecast is observation telemetry only', 'Observation display status is exact');

// Recommendation suspension.
$sus = ForecastUsability::assess($aggregate, $job, ['suspended' => true] + $context);
$assert($sus['usable'] === false, 'Suspension is not usable');
$assert($sus['exclusion_reason'] === 'Recommendations Suspended by Administrator', 'Suspension reason is exact');
$assert($sus['exclusion_category'] === 'restriction', 'Suspension category is restriction');
$assert($sus['exclusion_action'] === 'Administrator: Resume forecast recommendations', 'Suspension action is exact');

// No approved model at all.
$none = ForecastUsability::assess($aggregate, $job, ['approved_model_version' => ''] + $context);
$assert($none['usable'] === false, 'Missing approved model is not usable');
$assert($none['exclusion_reason'] === 'Unapproved model: no approved Store model', 'No-approved-model reason is exact');
$assert($none['exclusion_category'] === 'approval', 'No-approved-model category is approval');

// Stored model differs from the approved model.
$otherAgg = ['model_version' => 'v0'] + $aggregate;
$other = ForecastUsability::assess($otherAgg, $job, $context);
$assert($other['usable'] === false, 'Unapproved stored model is not usable');
$assert($other['exclusion_reason'] === 'Unapproved model: model v0 is not approved', 'Unapproved stored model reason is exact');
$assert($other['exclusion_category'] === 'approval', 'Unapproved stored model category is approval');
$assert($other['status'] === 'Unapproved model: v0', 'Unapproved stored model display status is exact');

// Supersession: displayed run is older than the latest usable run.
$super = ForecastUsability::assess($aggregate, $job, ['latest_usable_job_id' => 11] + $context);
$assert($super['usable'] === false, 'Superseded run is not usable');
$assert($super['exclusion_reason'] === 'Superseded forecast: run #10', 'Superseded reason is exact');
$assert($super['exclusion_category'] === 'freshness', 'Superseded category is freshness');
$assert($super['exclusion_action'] === 'Use latest forecast run', 'Superseded action is exact');

// Staleness: Forecast Origin older than the expected origin.
$staleAgg = ['forecast_origin' => '2026-10-31'] + $aggregate;
$stale = ForecastUsability::assess($staleAgg, $job, $context);
$assert($stale['usable'] === false, 'Stale forecast is not usable');
$assert($stale['exclusion_reason'] === 'Stale forecast: 2026-10-31 (expected 2026-11-01)', 'Stale reason is exact');
$assert($stale['exclusion_category'] === 'freshness', 'Stale category is freshness');
$assert($stale['exclusion_action'] === 'Wait for next scheduled forecast run at 01:00 Asia/Manila', 'Stale action is exact');
$assert($stale['status'] === 'Stale forecast: 2026-10-31', 'Stale display status is exact');

// Incomplete 28-day horizon.
$incAgg = ['complete_forecast' => false] + $aggregate;
$inc = ForecastUsability::assess($incAgg, $job, $context);
$assert($inc['usable'] === false, 'Incomplete forecast is not usable');
$assert($inc['exclusion_reason'] === 'Incomplete forecast: missing full 28-day predictions', 'Incomplete reason is exact');
$assert($inc['exclusion_category'] === 'evidence', 'Incomplete category is evidence');
$assert($inc['exclusion_action'] === 'Wait for next scheduled forecast run', 'Incomplete action is exact');

// Unsupported readiness level.
$insAgg = ['readiness_level' => 'Insufficient'] + $aggregate;
$ins = ForecastUsability::assess($insAgg, $job, $context);
$assert($ins['usable'] === false, 'Insufficient readiness is not usable');
$assert($ins['exclusion_reason'] === 'Insufficient history: readiness level is Insufficient', 'Insufficient reason is exact');
$assert($ins['exclusion_category'] === 'evidence', 'Insufficient category is evidence');
$assert($ins['exclusion_action'] === 'Accumulate more sales history; replenishment continues on stock rules', 'Insufficient action is exact');

// No forecast row at all.
$missing = ForecastUsability::assess([], $job, $context);
$assert($missing['usable'] === false, 'Missing forecast is not usable');
$assert($missing['exclusion_reason'] === 'Forecast unavailable: no forecast generated for this product', 'Missing reason is exact');
$assert($missing['exclusion_category'] === 'evidence', 'Missing category is evidence');
$assert($missing['status'] === 'Forecast unavailable', 'Missing display status is exact');

// Demo/Store database mismatch.
$demoMeta = ['is_demo' => true, 'dataset_kind' => 'demo', 'feature_version' => '2.0.0'];
$demo = ForecastUsability::assess($aggregate, $job, ['job_meta' => $demoMeta] + $context);
$assert($demo['usable'] === false, 'Demo model in Store database is not usable');
$assert($demo['exclusion_reason'] === 'Incompatible model: Demo model cannot be used in Store database', 'Demo mismatch reason is exact');
$assert($demo['exclusion_category'] === 'recovery', 'Demo mismatch category is recovery');

$storeMeta = ['is_demo' => false, 'dataset_kind' => 'store', 'feature_version' => '2.0.0'];
$storeInDemo = ForecastUsability::assess($aggregate, $job, ['is_demo_db' => true, 'job_meta' => $storeMeta] + $context);
$assert($storeInDemo['usable'] === false, 'Store model in Demo database is not usable');
$assert($storeInDemo['exclusion_reason'] === 'Incompatible model: Store model cannot be used in Demo database', 'Store mismatch reason is exact');

$obsoleteMeta = ['is_demo' => false, 'dataset_kind' => 'store', 'feature_version' => '1.0.0'];
$obsolete = ForecastUsability::assess($aggregate, $job, ['job_meta' => $obsoleteMeta] + $context);
$assert($obsolete['exclusion_reason'] === 'Incompatible model: Obsolete feature schema 1.0.0', 'Obsolete schema reason is exact');

// Failed run recovery wording.
$failedJob = ['job_id' => 10, 'trigger_type' => 'forecast_generation', 'status' => 'failed', 'attempts' => 1];
$failed = ForecastUsability::assess($aggregate, $failedJob, $context);
$assert($failed['exclusion_reason'] === 'Failed forecast: run #10', 'Failed reason is exact');
$assert($failed['exclusion_category'] === 'recovery', 'Failed category is recovery');
$assert($failed['exclusion_action'] === 'Automatic retry scheduled; no action needed', 'Failed retry action is exact');

$exhaustedJob = ['job_id' => 10, 'trigger_type' => 'forecast_generation', 'status' => 'failed', 'attempts' => 3];
$exhausted = ForecastUsability::assess($aggregate, $exhaustedJob, $context);
$assert($exhausted['exclusion_action'] === 'Super Administrator: Inspect blocked or exhausted recovery before retrying', 'Exhausted recovery action is exact');

// A Low readiness band is still usable and labels itself.
$lowAgg = ['readiness_level' => 'Low'] + $aggregate;
$low = ForecastUsability::assess($lowAgg, $job, $context);
$assert($low['usable'] === true && $low['status'] === 'Low readiness forecast', 'Low readiness band is usable and labelled');

// Observation and suspension can coexist in the display status even though the
// exclusion ladder reports only the first reason.
$both = ForecastUsability::assess($aggregate, $job, ['observation' => true, 'suspended' => true] + $context);
$assert($both['exclusion_reason'] === 'Observation Mode: forecast is observation telemetry only', 'First matching exclusion reason wins');
$assert($both['status'] === 'Observation Mode: forecast is observation telemetry only; Recommendations Suspended by Administrator',
    'Display status lists both governance restrictions');

// -----------------------------------------------------------------------------
// readinessStatus: the Forecast Readiness page ladder
// -----------------------------------------------------------------------------

$eligible = ['eligible' => true, 'accumulated_usable_days' => 150];
$notEligible = ['eligible' => false, 'accumulated_usable_days' => 139, 'reason' => 'Needs at least 140 accumulated usable days (has 139/140)'];

$ready = ForecastUsability::readinessStatus($eligible, null, 'v1', null, null);
$assert($ready['status'] === 'Ready for training', 'Eligible Product with no stored forecast is Ready for training');
$assert(str_contains($ready['reason'], 'Forecast Readiness'), 'Ready reason keeps the readiness-band wording');

$insufficient = ForecastUsability::readinessStatus($notEligible, null, 'v1', null, null);
$assert($insufficient['status'] === 'Insufficient Data', 'Ineligible Product is Insufficient Data');
$assert(str_contains($insufficient['reason'], '140'), 'Insufficient reason carries the eligibility blocker');

$defaultReason = ForecastUsability::readinessStatus(['eligible' => false, 'accumulated_usable_days' => 10], null, 'v1', null, null);
$assert($defaultReason['status'] === 'Insufficient Data', 'Ineligible Product without a reason still reports Insufficient Data');
$assert(str_contains($defaultReason['reason'], '140 accumulated usable days'), 'Default reason names the fixed requirement');

$oldModel = ForecastUsability::readinessStatus($eligible, ['model_version' => 'old'], 'v1', null, null);
$assert($oldModel['status'] === 'Model Requires Retraining', 'Stored forecast from an older model version requires retraining');
$assert($oldModel['reason'] === 'The stored forecast was created by an older model version.', 'Older-model reason is exact');

$saleAfter = ForecastUsability::readinessStatus($eligible, ['model_version' => 'v1'], 'v1',
    '2026-11-02 05:00:00', '2026-11-02 01:00:00');
$assert($saleAfter['status'] === 'Model Requires Retraining', 'A sale after generation requires retraining');
$assert($saleAfter['reason'] === 'Sales history changed after the latest forecast.', 'Sale-after-generation reason is exact');

$generated = ForecastUsability::readinessStatus($eligible, ['model_version' => 'v1'], 'v1',
    '2026-11-01 20:00:00', '2026-11-02 01:00:00');
$assert($generated['status'] === 'Forecast Generated', 'Current stored forecast with no later sale is Forecast Generated');
$assert(str_contains($generated['reason'], 'Forecast available'), 'Forecast Generated reason is exact');

echo "\n================================================================================\n";
if (empty($failures)) {
    echo "PASS: forecast_usability_test\n";
    exit(0);
}
echo "FAIL: " . count($failures) . " assertions failed:\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
exit(1);
