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

$calendar = new StoreCalendar();

// Test 1: Method recordCalendarRange exists
assert_true(method_exists($calendar, 'recordCalendarRange'), "recordCalendarRange method must exist on StoreCalendar");

if (getenv('RUN_DB_TESTS') === '1') {
    require_once __DIR__ . '/../config/db.php';
    /** @var PDO $pdo */

    // Test 2: Validation of reversed bounds
    try {
        $calendar->recordCalendarRange($pdo, '2026-10-15', '2026-10-10', 'open', 1);
        assert_true(false, "Reversed date range must throw exception");
    } catch (InvalidArgumentException $e) {
        assert_true(str_contains(strtolower($e->getMessage()), 'reversed') || str_contains(strtolower($e->getMessage()), 'after') || str_contains(strtolower($e->getMessage()), 'order'), "Exception should indicate reversed bounds");
    }

    // Test 3: Validation of excessive range (> 366 days)
    try {
        $calendar->recordCalendarRange($pdo, '2025-01-01', '2026-02-01', 'open', 1); // 397 days
        assert_true(false, "Date range exceeding 366 days must throw exception");
    } catch (InvalidArgumentException $e) {
        assert_true(str_contains(strtolower($e->getMessage()), '366') || str_contains(strtolower($e->getMessage()), 'exceed'), "Exception should mention 366 limit");
    }

    // Test 4: Atomic range update across leap day
    $pdo->exec("DELETE FROM store_calendar WHERE calendar_date BETWEEN '2024-02-28' AND '2024-03-01'");
    $changed = $calendar->recordCalendarRange($pdo, '2024-02-28', '2024-03-01', 'closed', 1);
    assert_same(3, count($changed), "Leap year range 2024-02-28 to 2024-03-01 must include 3 days (Feb 28, Feb 29, Mar 1)");

    $range = $calendar->getCalendarDateRangeAtCutoff($pdo, '2024-02-28', '2024-03-01', date('Y-m-d H:i:s', time() + 5));
    assert_same('closed', $range['2024-02-28']);
    assert_same('closed', $range['2024-02-29']);
    assert_same('closed', $range['2024-03-01']);

    // Test 5: Deduplication in range - running same range again changes 0 dates
    $changed2 = $calendar->recordCalendarRange($pdo, '2024-02-28', '2024-03-01', 'closed', 1);
    assert_same(0, count($changed2), "Matching dates in range must be skipped without duplicate history");
}

echo "Store calendar range contract passed.\n";
