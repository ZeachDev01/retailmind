<?php

require_once __DIR__ . '/../bootstrap/app.php';

use App\Store\StoreCalendar;

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

if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Database integration tests: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Database/MigrationRunner.php';

echo "Testing App\\Store\\StoreCalendar domain seam...\n";

$runner = new \App\Database\MigrationRunner($pdo, __DIR__ . '/../database/migrations');
$runner->runPending();

// We need a store/branch
$stmt = $pdo->query("SELECT branch_id FROM branches LIMIT 1");
$branchId = (int)$stmt->fetchColumn();
if (!$branchId) {
    $pdo->exec("INSERT INTO branches (branch_name, branch_code) VALUES ('Test Store', 'TST')");
    $branchId = (int)$pdo->lastInsertId();
}

$pdo->exec("DELETE FROM store_calendar");

// Test 1: Defaults to unrecorded
$calendar = new StoreCalendar();
$status = $calendar->getCalendarDateStatusAtCutoff($pdo, $branchId, '2026-10-09', date('Y-m-d H:i:s'));
assert_same(null, $status, 'An unrecorded date must return null to avoid assuming it was genuinely open or closed.');

// Test 2: Can record a status
$calendar->recordCalendarDate($pdo, $branchId, '2026-10-09', 'holiday', null);
$status = $calendar->getCalendarDateStatusAtCutoff($pdo, $branchId, '2026-10-09', date('Y-m-d H:i:s', time() + 5));
assert_same('holiday', $status, 'A recorded date must return its status.');

// Test 3: Can reproduce status at a cutoff time
$cutoff = date('Y-m-d H:i:s');
sleep(1);
$calendar->recordCalendarDate($pdo, $branchId, '2026-10-09', 'open', null);

$statusAfterCutoff = $calendar->getCalendarDateStatusAtCutoff($pdo, $branchId, '2026-10-09', date('Y-m-d H:i:s', time() + 5));
assert_same('open', $statusAfterCutoff, 'The latest status should be returned if cutoff is after the change.');

$statusAtCutoff = $calendar->getCalendarDateStatusAtCutoff($pdo, $branchId, '2026-10-09', $cutoff);
assert_same('holiday', $statusAtCutoff, 'Historical queries reproduce calendar information available at the requested cutoff.');

// Test 4: Can get a range of dates
$calendar->recordCalendarDate($pdo, $branchId, '2026-10-10', 'closed', null);
$range = $calendar->getCalendarDateRangeAtCutoff($pdo, $branchId, '2026-10-09', '2026-10-10', date('Y-m-d H:i:s', time() + 5));
assert_same('open', $range['2026-10-09'] ?? null, 'Range should return correct status for 10-09.');
assert_same('closed', $range['2026-10-10'] ?? null, 'Range should return correct status for 10-10.');
assert_true(!isset($range['2026-10-11']), 'Unrecorded date in range should not be present (or should be null).');

echo "Domain seam tests passed.\n";
