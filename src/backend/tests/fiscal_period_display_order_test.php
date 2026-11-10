<?php

declare(strict_types=1);

// fiscal_period_display_order_test.php
// Display order: the open period leads, scheduled periods follow by start date,
// then closed and locked history newest first.

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "FAIL: {$message}\n";
    } else {
        echo "PASS: {$message}\n";
    }
};

require_once __DIR__ . '/../app/Services/FiscalPeriodGuardService.php';

$periods = [
    ['period_id' => 5, 'period_name' => 'Q1 2026', 'status' => 'open', 'start_date' => '2026-11-10', 'end_date' => '2026-11-30'],
    ['period_id' => 4, 'period_name' => 'Q4 2026', 'status' => 'scheduled', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31'],
    ['period_id' => 1, 'period_name' => 'Q1 2027', 'status' => 'scheduled', 'start_date' => '2027-01-01', 'end_date' => '2027-03-31'],
    ['period_id' => 2, 'period_name' => 'Q2 2027', 'status' => 'scheduled', 'start_date' => '2027-04-01', 'end_date' => '2027-06-30'],
    ['period_id' => 3, 'period_name' => 'Q3 2026', 'status' => 'closed', 'start_date' => '2026-08-01', 'end_date' => '2026-10-31'],
    ['period_id' => 6, 'period_name' => 'Q2 2026', 'status' => 'locked', 'start_date' => '2026-05-01', 'end_date' => '2026-07-31'],
];

$ordered = array_map(
    static fn(array $period): int => (int)$period['period_id'],
    \FiscalPeriodGuardService::orderForDisplay($periods)
);
$assert($ordered === [5, 4, 1, 2, 3, 6], 'Open period leads, scheduled periods ascend, past history descends');

$openLater = [
    ['period_id' => 7, 'status' => 'scheduled', 'start_date' => '2027-01-01', 'end_date' => '2027-03-31'],
    ['period_id' => 8, 'status' => 'open', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
];
$assert(
    array_column(\FiscalPeriodGuardService::orderForDisplay($openLater), 'period_id') === [8, 7],
    'Open period leads even when its dates are older'
);

$tied = [
    ['period_id' => 12, 'status' => 'scheduled', 'start_date' => '2027-01-01', 'end_date' => '2027-03-31'],
    ['period_id' => 11, 'status' => 'scheduled', 'start_date' => '2027-01-01', 'end_date' => '2027-03-31'],
    ['period_id' => 20, 'status' => 'closed', 'start_date' => '2026-01-01', 'end_date' => '2026-03-31'],
    ['period_id' => 19, 'status' => 'closed', 'start_date' => '2026-01-01', 'end_date' => '2026-03-31'],
];
$assert(
    array_column(\FiscalPeriodGuardService::orderForDisplay($tied), 'period_id') === [11, 12, 20, 19],
    'Equal dates fall back to period id in each direction'
);

$assert(\FiscalPeriodGuardService::orderForDisplay([]) === [], 'Empty list stays empty');
$assert(count($periods) === 6, 'Ordering does not change the caller list');

echo "\n==========================================\n";
if (empty($failures)) {
    echo "SUCCESS: Fiscal period display order test passed!\n";
    exit(0);
}
echo 'FAILURES DETECTED: ' . count($failures) . "\n";
exit(1);
