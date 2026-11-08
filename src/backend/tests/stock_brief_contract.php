<?php
// Characterizes StockBriefService buildReport/dispatch behavior before/after slice-1 refactor.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/StockBriefService.php';
restore_exception_handler();

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE store_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
CREATE TABLE products (product_id INTEGER PRIMARY KEY, product_name TEXT, sku TEXT, reorder_level INTEGER, warning_lead_time_days INTEGER);
CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER);
CREATE TABLE product_batches (
    batch_id INTEGER PRIMARY KEY, product_id INTEGER, batch_number TEXT, remaining_quantity INTEGER,
    expiration_date TEXT, date_received TEXT, supplier TEXT
);");
$pdo->exec("INSERT INTO store_settings (setting_key, setting_value) VALUES ('store_name','Test Store'),('store_email','')");

$today = date('Y-m-d');
$future = date('Y-m-d', strtotime('+10 days'));
$expired = date('Y-m-d', strtotime('-2 days'));
$farFuture = date('Y-m-d', strtotime('+40 days'));

$pdo->exec("INSERT INTO products (product_id, product_name, sku, reorder_level, warning_lead_time_days) VALUES
(1,'Out Item','OUT-1',5,30),
(2,'Low Item','LOW-1',10,30),
(3,'Ok Item','OK-1',2,30),
(4,'Exp Item','EXP-1',2,30),
(5,'Expired Item','EXD-1',2,30),
(6,'Far Item','FAR-1',2,30)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES
(1,0),(2,3),(3,9),(4,9),(5,9),(6,9)");
$stmt = $pdo->prepare('INSERT INTO product_batches (batch_id, product_id, batch_number, remaining_quantity, expiration_date) VALUES (?,?,?,?,?)');
foreach ([
    [1, 4, 'B-EXP', 4, $future],
    [2, 5, 'B-OLD', 2, $expired],
    [3, 6, 'B-FAR', 7, $farFuture], // outside 30-day lead time, should not appear
    [4, 4, 'B-EMPTY', 0, $future],  // depleted
] as $row) $stmt->execute($row);

$service = new App\Services\StockBriefService($pdo);
$report = $service->buildReport();

$assert(($report['metrics']['out_of_stock'] ?? null) === 1, 'Out-of-stock metric mismatch');
$assert(($report['metrics']['low_stock'] ?? null) === 1, 'Low-stock metric mismatch');
$assert(($report['metrics']['expiring'] ?? null) === 2, 'Expiring+expired batch metric mismatch');
$assert(str_contains((string)$report['subject'], 'Test Store Daily Stock Brief'), 'Subject missing store name');
$assert(str_contains((string)$report['text'], 'OUT OF STOCK (1)'), 'Text missing out-of-stock section');
$assert(str_contains((string)$report['text'], 'LOW STOCK (1)'), 'Text missing low-stock section');
$assert(str_contains((string)$report['text'], 'EXPIRING / EXPIRED BATCHES (2)'), 'Text missing expiry section');
$assert(str_contains((string)$report['text'], 'Exp Item (Batch B-EXP)'), 'Text missing expiring batch');
$assert(str_contains((string)$report['text'], 'Expired Item (Batch B-OLD)'), 'Text missing expired batch');
$assert(!str_contains((string)$report['text'], 'Far Item'), 'Far-future batch should be excluded');
$assert(!str_contains((string)$report['text'], 'B-EMPTY'), 'Depleted batch should be excluded');
$assert(str_contains((string)$report['html'], 'Daily Stock Brief'), 'HTML missing brief title');
$assert(str_contains((string)$report['html'], 'Out of Stock'), 'HTML missing out-of-stock heading');
$assert(str_contains((string)$report['html'], 'B-EXP') && str_contains((string)$report['html'], 'B-OLD'), 'HTML missing batch rows');

// dispatch() short-circuits when nothing actionable and no specific recipient.
$emptyPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$emptyPdo->exec("CREATE TABLE store_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
CREATE TABLE products (product_id INTEGER PRIMARY KEY, product_name TEXT, sku TEXT, reorder_level INTEGER, warning_lead_time_days INTEGER);
CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER);
CREATE TABLE product_batches (batch_id INTEGER PRIMARY KEY, product_id INTEGER, batch_number TEXT, remaining_quantity INTEGER, expiration_date TEXT);");
$emptyPdo->exec("INSERT INTO store_settings (setting_key, setting_value) VALUES ('store_name','Empty Store'),('store_email','')");
$emptyPdo->exec("INSERT INTO products VALUES (1,'Fine','F-1',1,30); INSERT INTO inventory VALUES (1,5);");
$empty = new App\Services\StockBriefService($emptyPdo);
$dispatch = $empty->dispatch();
$assert(($dispatch['report']['out_of_stock'] ?? null) === 0 && ($dispatch['report']['low_stock'] ?? null) === 0 && ($dispatch['report']['expiring'] ?? null) === 0, 'Empty report metrics mismatch');
$assert(($dispatch['deliveries'] ?? null) === [], 'Empty scheduled dispatch should have no deliveries');

if ($failures) {
    fwrite(STDERR, "Stock brief contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Stock brief contract: passed\n";
