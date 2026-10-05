<?php

require_once __DIR__ . '/../app/Services/PhilippineTime.php';
require_once __DIR__ . '/../app/Services/CashierOperationalHistoryService.php';
require_once __DIR__ . '/../app/Services/CashierOperationalHistoryRequest.php';

use App\Services\PhilippineTime;
use App\Services\CashierOperationalHistoryService;
use App\Services\CashierOperationalHistoryRequest;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
date_default_timezone_set('America/New_York');
$expected = 'Oct 1, 2026 · 8:39 AM';
$assert(PhilippineTime::format('2026-10-01 08:39:18') === $expected, 'SQL +08 instant is not shifted again');
$assert(PhilippineTime::format('2026-10-01T00:39:18Z') === $expected, 'Explicit UTC instant converts to Manila');
$assert(PhilippineTime::format(new DateTimeImmutable('2026-09-30T20:39:18-04:00')) === $expected, 'Zoned object uses Manila');
$assert(PhilippineTime::format('2026-09-30T16:00:00Z') === 'Oct 1, 2026 · 12:00 AM', 'Manila midnight is the preceding UTC date');
foreach ([null, '', 'not a timestamp', '2026-02-30 08:00:00', '0000-00-00 00:00:00'] as $invalid) {
    $assert(PhilippineTime::format($invalid) === '—', 'Invalid or missing time never becomes render time');
}
$assert(PhilippineTime::dayStart('2026-10-01') === '2026-10-01 00:00:00', 'Inclusive local midnight');
$assert(PhilippineTime::dayAfter('2026-12-31') === '2027-01-01 00:00:00', 'Exclusive next day crosses the year');
$assert(PhilippineTime::dayAfter('2028-02-28') === '2028-02-29 00:00:00', 'Leap day remains valid');
foreach (['2026-02-29', '2026-10-01 trailing', '2026-1-1'] as $invalid) {
    try { PhilippineTime::dayStart($invalid); throw new RuntimeException('Invalid calendar day accepted'); }
    catch (InvalidArgumentException) {}
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, shift_id INTEGER, sale_date TEXT, total_amount REAL, payment_method TEXT, cash_received REAL, change_due REAL)');
$insert = $pdo->prepare('INSERT INTO sales VALUES (?, ?, 1, ?, 10, "cash", 10, 0)');
foreach ([[1,1,'2026-09-30 23:59:59'],[2,1,'2026-10-01 00:00:00'],[3,1,'2026-10-01 23:59:59.999999'],[4,1,'2026-10-02 00:00:00'],[5,2,'2026-10-01 08:00:00'],[6,1,'2026-10-01 00:00:00']] as $row) $insert->execute($row);
$before = $pdo->query('SELECT total_changes()')->fetchColumn();
$history = new CashierOperationalHistoryService($pdo);
$filtered = $history->page('sales', 1, 1, 25, '2026-10-01', '2026-10-01');
$assert(array_column($filtered, 'sale_id') === [3,6,2], 'Underlying timestamps and IDs sort within half-open Manila day, retaining fractions and ownership');
$assert(array_column($history->page('sales', 1, 2, 1, '2026-10-01', '2026-10-01'), 'sale_id') === [6], 'Pagination preserves date constraints');
$request = CashierOperationalHistoryRequest::resolve($pdo, 1, ['date_from'=>'2026-10-01','date_to'=>'2026-10-01','cashier_id'=>2]);
$assert(array_column($request['rows'], 'sale_id') === [3,6,2], 'Date request retains authenticated identity');
foreach ([['date_from'=>'2026-02-30'],['date_from'=>['2026-10-01']],['date_from'=>'2026-10-02','date_to'=>'2026-10-01']] as $query) {
    $request = CashierOperationalHistoryRequest::resolve($pdo, 1, $query);
    $assert($request['dateError'] !== '' && $request['rows'] === [], 'Invalid date filters do not widen the history');
}
$assert($pdo->query('SELECT total_changes()')->fetchColumn() === $before, 'Formatting and history filters never alter records');
echo "Philippine time and private day boundaries: passed\n";
