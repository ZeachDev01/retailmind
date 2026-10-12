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

// Unit test without DB: test status normalization
assert_same('exceptional_closure', StoreCalendar::normalizeStatus('exceptional-closure'), 'Hyphenated status must normalize to exceptional_closure');
assert_same('exceptional_closure', StoreCalendar::normalizeStatus('exceptional_closure'), 'Underscored status must remain exceptional_closure');
assert_same('open', StoreCalendar::normalizeStatus('Open'), 'Case insensitive normalization');

if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Store calendar unit tests passed. (Database integration tests skipped, set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Database/MigrationRunner.php';

echo "Testing App\\Store\\StoreCalendar domain seam...\n";

$runner = new \App\Database\MigrationRunner($pdo, __DIR__ . '/../database/migrations');
$runner->runPending();

$pdo->exec("DELETE FROM store_calendar");

$calendar = new StoreCalendar();

// Test 1: Defaults to unrecorded
$status = $calendar->getCalendarDateStatusAtCutoff($pdo, '2026-10-09', date('Y-m-d H:i:s'));
assert_same(null, $status, 'An unrecorded date must return null to avoid assuming it was genuinely open or closed.');

// Test 2: Can record a status using new signature
$calendar->recordCalendarDate($pdo, '2026-10-09', 'holiday', null);
$status = $calendar->getCalendarDateStatusAtCutoff($pdo, '2026-10-09', date('Y-m-d H:i:s', time() + 5));
assert_same('holiday', $status, 'A recorded date must return its status.');

// Test 3: Deduplication - recording identical status does not insert duplicate row
$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM store_calendar WHERE calendar_date = '2026-10-09'")->fetchColumn();
$calendar->recordCalendarDate($pdo, '2026-10-09', 'holiday', null);
$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM store_calendar WHERE calendar_date = '2026-10-09'")->fetchColumn();
assert_same($countBefore, $countAfter, 'Matching status must not add duplicate history record.');

// Test 4: Can record using legacy branch signature
$calendar->recordCalendarDate($pdo, 1, '2026-10-09', 'open', null);
$statusAfter = $calendar->getCalendarDateStatusAtCutoff($pdo, 1, '2026-10-09', date('Y-m-d H:i:s', time() + 5));
assert_same('open', $statusAfter, 'Legacy signature must remain compatible and return latest status.');

// Test 5: Can get range of dates
$calendar->recordCalendarDate($pdo, '2026-10-10', 'exceptional_closure', null);
$range = $calendar->getCalendarDateRangeAtCutoff($pdo, '2026-10-09', '2026-10-10', date('Y-m-d H:i:s', time() + 5));
assert_same('open', $range['2026-10-09'] ?? null, 'Range returns 10-09 status.');
assert_same('exceptional_closure', $range['2026-10-10'] ?? null, 'Range returns 10-10 exceptional_closure.');
assert_true(!isset($range['2026-10-11']), 'Unrecorded date in range is null/absent.');

echo "Domain seam tests passed.\n";
