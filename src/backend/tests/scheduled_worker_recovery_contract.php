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

$workerPath = dirname(__DIR__) . '/scripts/ml_worker.py';

// Test 1: CLI accepts both --run-once and --once
$out1 = [];
$ret1 = 0;
exec("python " . escapeshellarg($workerPath) . " --run-once", $out1, $ret1);
assert_same(0, $ret1, "--run-once must exit with code 0 without argument parsing error");

$out2 = [];
$ret2 = 0;
exec("python " . escapeshellarg($workerPath) . " --once", $out2, $ret2);
assert_same(0, $ret2, "--once must exit with code 0 without argument parsing error");

// Test 2: Database tests for lease recovery and crash reporting
if (getenv('RUN_DB_TESTS') === '1') {
    require_once dirname(__DIR__) . '/config/db.php';
    /** @var PDO $pdo */

    // Insert a failing job or expired lease with attempts >= 3
    $pdo->exec("INSERT INTO ml_jobs (trigger_type, status, queued_at, lease_expires_at, attempts) 
                VALUES ('forecast_generation', 'running', DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_SUB(NOW(), INTERVAL 1 HOUR), 3)");
    $exhaustedJobId = (int)$pdo->lastInsertId();

    exec("python " . escapeshellarg($workerPath) . " --run-once");

    $stmt = $pdo->prepare("SELECT status, operator_message FROM ml_jobs WHERE job_id = ?");
    $stmt->execute([$exhaustedJobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    assert_same('failed', $job['status'], "Exhausted lease job should be marked as failed");
    assert_true(str_contains(strtolower($job['operator_message']), 'expired') || str_contains(strtolower($job['operator_message']), 'retries') || str_contains(strtolower($job['operator_message']), 'failed'), "Operator message should explain failure calmly");
}

echo "Scheduled worker recovery contract passed.\n";
