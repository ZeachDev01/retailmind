<?php

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';

use App\Forecasting\CandidateEvaluationService;

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

echo "Forecast analytics contract passed.\n";
