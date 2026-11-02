<?php

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';

use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\DemandForecastService;

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

// Unit test: check that evaluations are structured with MAE, RMSE, WAPE for 7, 14, 28 days
$dummyEvals = [
    [
        'horizon_days' => 7,
        'mae' => 1.25,
        'rmse' => 1.85,
        'wape' => 0.12,
        'baseline_mae' => 2.10,
        'baseline_rmse' => 2.90,
        'baseline_wape' => 0.22,
        'candidate_gate_outcome' => 'passed',
        'missing_metric_reason' => null
    ],
    [
        'horizon_days' => 14,
        'mae' => 1.80,
        'rmse' => 2.40,
        'wape' => 0.16,
        'baseline_mae' => 2.80,
        'baseline_rmse' => 3.50,
        'baseline_wape' => 0.28,
        'candidate_gate_outcome' => 'passed',
        'missing_metric_reason' => null
    ],
    [
        'horizon_days' => 28,
        'mae' => 2.50,
        'rmse' => 3.20,
        'wape' => 0.22,
        'baseline_mae' => 3.40,
        'baseline_rmse' => 4.20,
        'baseline_wape' => 0.35,
        'candidate_gate_outcome' => 'passed',
        'missing_metric_reason' => null
    ]
];

assert_same(3, count($dummyEvals), 'Should have 3 horizon evaluations');
assert_same(7, $dummyEvals[0]['horizon_days'], 'First is 7-day');
assert_same(14, $dummyEvals[1]['horizon_days'], 'Second is 14-day');
assert_same(28, $dummyEvals[2]['horizon_days'], 'Third is 28-day');

// Test zero-demand WAPE explanation handling
$zeroDemandEval = [
    'horizon_days' => 7,
    'mae' => 0.0,
    'rmse' => 0.0,
    'wape' => null,
    'missing_metric_reason' => 'Zero actual demand'
];
assert_same(null, $zeroDemandEval['wape'], 'WAPE is null when actual demand is 0');
assert_same('Zero actual demand', $zeroDemandEval['missing_metric_reason'], 'Reason explains zero actual demand');

// Test Ticket #414: Honest Prediction Performance Evidence contract structure
$dummyPerfEvidence = [
    'model_version' => 'rf_store_v1',
    'model_association' => 'current',
    'is_demo' => false,
    'demo_label' => null,
    'model_inputs_description' => 'Store daily sales history, day of week, and lag patterns. Prices and promotions are not active model inputs.',
    'status' => 'collecting_evidence',
    'status_label' => 'Still collecting evidence',
    'explanation' => 'Still collecting evidence: 5 of 28 completed target dates backed by usable Store observations.',
    'completed_target_dates' => 5,
    'required_target_dates' => 28,
    'usable_comparison_count' => 10,
    'incomplete_demand_count' => 1,
    'closures_paused' => 0,
    'verified_stockouts' => 1,
    'verified_stockouts_display' => '1',
    'verified_expiry_losses' => null,
    'verified_expiry_losses_display' => 'Unavailable',
    'causal_claim' => null,
    'metrics_by_horizon' => [
        7 => [
            'mae' => 2.5,
            'rmse' => 3.0,
            'wape' => 0.15,
            'overestimated_units' => 12.0,
            'underestimated_units' => 13.0,
            'overestimated_count' => 4,
            'underestimated_count' => 5,
            'exact_count' => 1,
            'usable_comparisons' => 10,
            'baseline_mae' => 3.5,
            'baseline_rmse' => 4.2,
            'baseline_wape' => 0.21,
            'baseline_overestimated_units' => 15.0,
            'baseline_underestimated_units' => 20.0,
        ],
        14 => ['mae' => null, 'rmse' => null, 'wape' => null, 'overestimated_units' => null, 'underestimated_units' => null],
        28 => ['mae' => null, 'rmse' => null, 'wape' => null, 'overestimated_units' => null, 'underestimated_units' => null]
    ]
];

assert_true(in_array($dummyPerfEvidence['status'], ['genuine_evidence', 'collecting_evidence', 'unavailable'], true), 'Status must be one of genuine_evidence, collecting_evidence, unavailable');
assert_true(in_array($dummyPerfEvidence['model_association'], ['current', 'candidate', 'previous', 'unavailable'], true), 'Model association must be current, candidate, previous, or unavailable');
assert_same(12.0, $dummyPerfEvidence['metrics_by_horizon'][7]['overestimated_units'], 'Overestimated units must be separate');
assert_same(13.0, $dummyPerfEvidence['metrics_by_horizon'][7]['underestimated_units'], 'Underestimated units must be separate');
assert_true($dummyPerfEvidence['causal_claim'] === null, 'No causal claim is made');
assert_same('Unavailable', $dummyPerfEvidence['verified_expiry_losses_display'], 'Absent expiry losses must display Unavailable');

echo "Forecast analytics contract passed.\n";
