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

// Unit checks
assert_true(method_exists($calendar, 'recordWeeklySchedule'), 'recordWeeklySchedule method must exist');
assert_true(method_exists($calendar, 'getWeeklyScheduleAtCutoff'), 'getWeeklyScheduleAtCutoff method must exist');
assert_true(method_exists($calendar, 'hasCompletedSaleOnDateAtCutoff'), 'hasCompletedSaleOnDateAtCutoff method must exist');
assert_true(method_exists($calendar, 'getCalendarDateEvidenceAtCutoff'), 'getCalendarDateEvidenceAtCutoff method must exist');
assert_true(method_exists($calendar, 'getCalendarDateEvidenceRangeAtCutoff'), 'getCalendarDateEvidenceRangeAtCutoff method must exist');

if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Store weekly and sales calendar contract unit tests passed (set RUN_DB_TESTS=1 for DB tests).\n";
    exit(0);
}

require_once __DIR__ . '/../config/db.php';
/** @var PDO $pdo */

echo "Running Store weekly schedule and sales opening contract tests...\n";

// Cleanup test fixtures
$pdo->exec("DELETE FROM store_weekly_schedule WHERE effective_date >= '2026-11-01'");
$pdo->exec("DELETE FROM store_calendar WHERE calendar_date >= '2026-11-01'");
$pdo->exec("DELETE FROM sale_items WHERE sale_id IN (SELECT sale_id FROM sales WHERE sale_date >= '2026-11-01 00:00:00')");
$pdo->exec("DELETE FROM sales WHERE sale_date >= '2026-11-01 00:00:00'");

// 1. Unrecorded date defaults to null
$evidence = $calendar->getCalendarDateEvidenceAtCutoff($pdo, '2026-11-02', '2026-11-02 23:59:59');
assert_same(null, $evidence['status'], 'Unrecorded date without schedule or sale must have null status');
assert_same('unrecorded', $evidence['source'], 'Unrecorded date source must be unrecorded');

// 2. Record weekly schedule (effective 2026-11-01: Mon-Fri open, Sat-Sun closed)
$recordTime1 = '2026-11-01 06:00:00';
$stmt = $pdo->prepare("INSERT INTO store_weekly_schedule 
    (effective_date, monday_open, tuesday_open, wednesday_open, thursday_open, friday_open, saturday_open, sunday_open, recorded_by, source, recorded_at)
    VALUES ('2026-11-01', 1, 1, 1, 1, 1, 0, 0, 1, 'manual', ?)");
$stmt->execute([$recordTime1]);

// At cutoff before schedule recorded (2026-11-01 05:00:00), schedule was unknown -> status null
$statusBefore = $calendar->getCalendarDateStatusAtCutoff($pdo, '2026-11-02', '2026-11-01 05:00:00');
assert_same(null, $statusBefore, 'Cutoff before schedule record must yield null');

// At cutoff after schedule recorded (2026-11-01 07:00:00):
// 2026-11-02 is Monday -> open
$evidenceMon = $calendar->getCalendarDateEvidenceAtCutoff($pdo, '2026-11-02', '2026-11-01 07:00:00');
assert_same('open', $evidenceMon['status'], 'Monday must be open under weekly schedule');
assert_same('weekly_schedule', $evidenceMon['source'], 'Source must be weekly_schedule');
assert_same(false, $evidenceMon['is_exception'], 'Weekly schedule is not an exception');

// 2026-11-08 is Sunday -> closed
$evidenceSun = $calendar->getCalendarDateEvidenceAtCutoff($pdo, '2026-11-08', '2026-11-01 07:00:00');
assert_same('closed', $evidenceSun['status'], 'Sunday must be closed under weekly schedule');
assert_same('weekly_schedule', $evidenceSun['source'], 'Source must be weekly_schedule');

// 3. Date exception overrides weekly schedule
// Record exception: 2026-11-06 (Friday) is closed (e.g. maintenance)
$exceptionTime = '2026-11-02 08:00:00';
$stmt = $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_by, source, recorded_at)
    VALUES ('2026-11-06', 'exceptional-closure', 1, 'manual', ?)");
$stmt->execute([$exceptionTime]);

// Before exception recorded: Friday was open under weekly schedule
$friBefore = $calendar->getCalendarDateStatusAtCutoff($pdo, '2026-11-06', '2026-11-02 07:00:00');
assert_same('open', $friBefore, 'Friday before exception was open under weekly schedule');

// After exception recorded: Friday is exceptional_closure
$friAfter = $calendar->getCalendarDateEvidenceAtCutoff($pdo, '2026-11-06', '2026-11-02 09:00:00');
assert_same('exceptional_closure', $friAfter['status'], 'Date exception overrides weekly schedule');
assert_same(true, $friAfter['is_exception'], 'is_exception flag must be true');

// 4. Later schedule revision does not rewrite historical evidence
// On 2026-11-05 12:00:00, revise schedule so Sunday 2026-11-08 is open
$recordTime2 = '2026-11-05 12:00:00';
$stmt = $pdo->prepare("INSERT INTO store_weekly_schedule 
    (effective_date, monday_open, tuesday_open, wednesday_open, thursday_open, friday_open, saturday_open, sunday_open, recorded_by, source, recorded_at)
    VALUES ('2026-11-01', 1, 1, 1, 1, 1, 0, 1, 1, 'manual', ?)");
$stmt->execute([$recordTime2]);

// At cutoff before revision (2026-11-05 11:00:00): Sunday 2026-11-08 was closed
assert_same('closed', $calendar->getCalendarDateStatusAtCutoff($pdo, '2026-11-08', '2026-11-05 11:00:00'), 'Past cutoff must preserve closed status');

// At cutoff after revision (2026-11-05 13:00:00): Sunday 2026-11-08 is open
assert_same('open', $calendar->getCalendarDateStatusAtCutoff($pdo, '2026-11-08', '2026-11-05 13:00:00'), 'New cutoff must see revised open status');

// 5. Completed Product sale automatically establishes Open, overriding closure and exceptions
// Date 2026-11-15 (Sunday) with schedule closed
$stmt = $pdo->prepare("INSERT INTO store_weekly_schedule 
    (effective_date, monday_open, tuesday_open, wednesday_open, thursday_open, friday_open, saturday_open, sunday_open, recorded_by, source, recorded_at)
    VALUES ('2026-11-10', 1, 1, 1, 1, 1, 0, 0, 1, 'manual', '2026-11-10 00:00:00')");
$stmt->execute();

// Also put a date-specific closure exception on 2026-11-15
$stmt = $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_by, source, recorded_at)
    VALUES ('2026-11-15', 'closed', 1, 'manual', '2026-11-10 01:00:00')");
$stmt->execute();

// Cutoff before any sale: 2026-11-15 08:00:00 -> status closed
assert_same('closed', $calendar->getCalendarDateStatusAtCutoff($pdo, '2026-11-15', '2026-11-15 08:00:00'), 'Before sale, status is closed');

// Insert a completed product sale at 2026-11-15 09:30:00
$pdo->exec("INSERT INTO sales (cashier_id, total_amount, gross_amount, sale_date) VALUES (1, 150.00, 150.00, '2026-11-15 09:30:00')");
$saleId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES ({$saleId}, 1, 2, 75.00, 150.00)");

// Cutoff before sale: 2026-11-15 09:00:00 -> still closed!
$evidenceBeforeSale = $calendar->getCalendarDateEvidenceAtCutoff($pdo, '2026-11-15', '2026-11-15 09:00:00');
assert_same('closed', $evidenceBeforeSale['status'], 'Cutoff before sale must still resolve to closed');

// Cutoff after sale: 2026-11-15 10:00:00 -> automatically OPEN!
$evidenceAfterSale = $calendar->getCalendarDateEvidenceAtCutoff($pdo, '2026-11-15', '2026-11-15 10:00:00');
assert_same('open', $evidenceAfterSale['status'], 'Completed sale automatically establishes open');
assert_same('sale', $evidenceAfterSale['source'], 'Provenance must indicate sale');
assert_same(true, $evidenceAfterSale['has_sale_override'], 'has_sale_override must be true');

// 6. Refunds and non-sales transactions do NOT open
// On 2026-11-22 (Sunday, closed): insert a refund without sale
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, status) VALUES (99999, 1, 'closed') ON DUPLICATE KEY UPDATE status='closed'");
$pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_by, source, recorded_at)
    VALUES ('2026-11-22', 'closed', 1, 'manual', '2026-11-20 00:00:00')");
// Insert cash refund
$pdo->exec("INSERT INTO cash_refunds (sale_id, shift_id, cashier_id, refund_amount, payment_method, reason, created_at)
    VALUES ({$saleId}, 99999, 1, 75.00, 'cash', 'Defective', '2026-11-22 10:00:00')");
$refundId = (int)$pdo->lastInsertId();
assert_same('closed', $calendar->getCalendarDateStatusAtCutoff($pdo, '2026-11-22', '2026-11-22 12:00:00'), 'Refund does not open a closed day');

// 7. Range query consistency
$rangeEvidence = $calendar->getCalendarDateEvidenceRangeAtCutoff($pdo, '2026-11-14', '2026-11-16', '2026-11-16 23:59:59');
assert_same('closed', $rangeEvidence['2026-11-14']['status'], '2026-11-14 is Saturday -> closed');
assert_same('open', $rangeEvidence['2026-11-15']['status'], '2026-11-15 has sale -> open');
assert_same('sale', $rangeEvidence['2026-11-15']['source'], '2026-11-15 source -> sale');
assert_same('open', $rangeEvidence['2026-11-16']['status'], '2026-11-16 is Monday -> open');

// Cleanup test fixtures
$pdo->exec("DELETE FROM store_weekly_schedule WHERE effective_date >= '2026-11-01'");
$pdo->exec("DELETE FROM store_calendar WHERE calendar_date >= '2026-11-01'");
$pdo->exec("DELETE FROM cash_refunds WHERE refund_id = {$refundId}");
$pdo->exec("DELETE FROM sale_items WHERE sale_id IN (SELECT sale_id FROM sales WHERE sale_date >= '2026-11-01 00:00:00')");
$pdo->exec("DELETE FROM sales WHERE sale_date >= '2026-11-01 00:00:00'");

echo "All Store weekly schedule and sales opening contract tests passed.\n";
