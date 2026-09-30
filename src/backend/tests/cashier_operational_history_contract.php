<?php

require_once __DIR__ . '/../app/Services/CashierOperationalHistoryService.php';
require_once __DIR__ . '/../app/Services/CashierOperationalHistoryRequest.php';

use App\Services\CashierOperationalHistoryService;
use App\Services\CashierOperationalHistoryRequest;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, status TEXT);
    INSERT INTO users VALUES (1, 'Casey', 'active'), (2, 'Morgan', 'active');
    CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, shift_id INTEGER, sale_date TEXT,
        total_amount REAL, payment_method TEXT, cash_received REAL, change_due REAL);
    CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, cashier_id INTEGER, sale_id INTEGER, shift_id INTEGER,
        created_at TEXT, refund_amount REAL, payment_method TEXT, reason TEXT, note TEXT);
    CREATE TABLE cash_drawer_movements (drawer_movement_id INTEGER PRIMARY KEY, cashier_id INTEGER, shift_id INTEGER,
        created_at TEXT, movement_type TEXT, amount REAL, reason TEXT, note TEXT);
    CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, cashier_id INTEGER, register_id INTEGER, opened_at TEXT,
        closed_at TEXT, status TEXT, opening_cash REAL, expected_cash REAL, actual_cash REAL, cash_variance REAL,
        closing_notes TEXT, closed_by INTEGER, intervention_reason TEXT);
    INSERT INTO cashier_shifts VALUES
        (10,1,1,'2026-09-30 08:00','2026-09-30 09:00','closed',100,170,165,-5,'counted',1,NULL),
        (11,2,2,'2026-09-30 08:30','2026-09-30 09:30','closed',200,240,240,0,'counted',2,NULL),
        (12,1,1,'2026-09-30 10:00',NULL,'open',50,NULL,NULL,NULL,NULL,NULL,NULL);
    INSERT INTO sales VALUES
        (20,1,10,'2026-09-30 08:15',70,'cash',70,0),
        (21,2,11,'2026-09-30 08:45',40,'cash',40,0),
        (22,1,12,'2026-09-30 10:15',25,'card',NULL,NULL);
    INSERT INTO cash_refunds VALUES
        (30,1,20,10,'2026-09-30 08:20',10,'cash','other','Casey note'),
        (31,2,21,11,'2026-09-30 08:50',5,'cash','other','Morgan note');
    INSERT INTO cash_drawer_movements VALUES
        (40,1,10,'2026-09-30 08:25','cash_in',10,'other','Casey drawer'),
        (41,2,11,'2026-09-30 08:55','safe_drop',5,'other','Morgan drawer');");

$service = new CashierOperationalHistoryService($pdo);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
foreach (['sales' => [[20, 22], [21]], 'refunds' => [[30], [31]],
    'movements' => [[40], [41]], 'shifts' => [[10, 12], [11]]] as $type => [$caseyIds, $morganIds]) {
    $key = CashierOperationalHistoryService::types()[$type]['id'];
    foreach ([1 => $caseyIds, 2 => $morganIds] as $cashierId => $expectedIds) {
        $actual = array_map(static fn(array $row): int => (int)$row[$key], $service->page($type, $cashierId));
        sort($actual);
        $assert($actual === $expectedIds, "{$type} list contains only Cashier {$cashierId}'s records");
        foreach ($expectedIds as $id) {
            $assert($service->find($type, $cashierId, $id) !== null, "own {$type} detail remains available");
        }
        $other = $cashierId === 1 ? $morganIds[0] : $caseyIds[0];
        $assert($service->find($type, $cashierId, $other) === null, "guessed {$type} ID is private");
    }
}
// Exercise the request path used by history.php, including forged ownership
// filters and direct record URLs against interleaved Cashier records.
$changesBeforeRequests = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
foreach (['sales' => [[20, 22], 21], 'refunds' => [[30], 31],
    'movements' => [[40], 41], 'shifts' => [[10, 12], 11]] as $type => [$ownIds, $otherId]) {
    $key = CashierOperationalHistoryService::types()[$type]['id'];
    $list = CashierOperationalHistoryRequest::resolve($pdo, 1, [
        'type' => $type, 'cashier_id' => 2, 'user_id' => 2, 'page' => 1,
    ]);
    $actual = array_map(static fn(array $row): int => (int)$row[$key], $list['rows']);
    sort($actual);
    $assert($actual === $ownIds, "forged {$type} owner filter cannot change list scope");
    $assert($list['record'] === null && $list['recordId'] === null, "{$type} list remains a list request");

    $denied = CashierOperationalHistoryRequest::resolve($pdo, 1, [
        'type' => $type, 'id' => $otherId, 'cashier_id' => 2, 'user_id' => 2,
    ]);
    $assert($denied['record'] === null && $denied['rows'] === [], "direct {$type} URL cannot reveal another Cashier");
    $allowed = CashierOperationalHistoryRequest::resolve($pdo, 1, ['type' => $type, 'id' => $ownIds[0]]);
    $assert((int)($allowed['record'][$key] ?? 0) === $ownIds[0], "own {$type} direct URL works");
}
$invalid = CashierOperationalHistoryRequest::resolve($pdo, 1, ['type' => 'shifts', 'id' => '-1']);
$assert($invalid['recordId'] === null && count($invalid['rows']) === 2, 'invalid record ID returns private list');
$page = CashierOperationalHistoryRequest::resolve($pdo, 1, ['type' => 'sales', 'page' => 2, 'cashier_id' => 2]);
$assert($page['rows'] === [] && $page['page'] === 2, 'page filter does not switch owners');
$assert((int)$pdo->query('SELECT total_changes()')->fetchColumn() === $changesBeforeRequests,
    'history requests do not write to the database');
$shift = $service->find('shifts', 1, 10);
$assert((float)$shift['expected_cash'] === 170.0 && (float)$shift['actual_cash'] === 165.0
    && (float)$shift['cash_variance'] === -5.0, 'counted, expected, and variance remain visible');
$pdo->exec("UPDATE users SET status='disabled' WHERE user_id=1");
$assert(count($service->page('sales', 1)) === 2 && $service->find('shifts', 1, 10) !== null,
    'disabled Cashier remains attributed in history');
$assert(count($service->page('sales', 1, 1, 1)) === 1 && count($service->page('sales', 1, 2, 1)) === 1,
    'pagination retains private scope');
$root = dirname(__DIR__, 3);
$route = file_get_contents($root . '/src/frontend/components/cashier/history.php');
$assert(str_contains($route, "require_role(['cashier'])"), 'route requires Cashier workspace');
$assert(str_contains($route, "(int)\$_SESSION['user_id']"), 'route derives identity from authenticated session');
$assert(str_contains($route, 'CashierOperationalHistoryRequest::resolve($pdo, $cashierId, $_GET)'),
    'route delegates actual request filtering to exercised request path');
$assert(!str_contains($route, "\$_POST[") && !str_contains($route, 'csrf_verify'), 'history route has no write action');
$assert(str_contains(file_get_contents($root . '/src/frontend/components/sidebar.php'), 'components/cashier/history.php'),
    'Cashier can navigate to history');
echo "Cashier operational history contract: passed\n";
