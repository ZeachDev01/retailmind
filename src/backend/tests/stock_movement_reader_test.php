<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use App\Inventory\StockMovementReader;

$expect = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$period = StockMovementReader::period('2026-11-12');
$expect($period['start'] === '2026-08-14' && $period['recent_start'] === '2026-10-13' && $period['end'] === '2026-11-12', 'The review must contain 90 complete Philippine days ending yesterday.');
$expect($period['label'] === 'Oct 13, 2026 – Nov 11, 2026', 'Dates and month names must identify the latest 30 days.');
foreach (['2026-11-12', '2027-01-01', '2028-03-01'] as $today) {
    $windows = StockMovementReader::period($today)['windows'];
    foreach ($windows as $i => $window) {
        $expect((new DateTimeImmutable($window['start']))->diff(new DateTimeImmutable($window['end']))->days === 30, 'Each window must be exactly 30 days, including leap years.');
        if ($i > 0) $expect($window['end'] === $windows[$i - 1]['start'], 'Windows must be consecutive without overlap.');
    }
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, sale_date TEXT);
    CREATE TABLE sale_items (sale_id INTEGER, product_id INTEGER, quantity INTEGER, selling_form TEXT, pack_size INTEGER);
    CREATE TABLE stock_movements (movement_id INTEGER PRIMARY KEY, product_id INTEGER, change_qty INTEGER, reason TEXT, moved_at TEXT)');
$products = [];
foreach (range(1,17) as $id) {
    $products[] = ['product_id' => $id, 'product_name' => 'Product ' . $id, 'quantity_on_hand' => match($id) {1=>90,6,11=>20,7,17=>0,default=>100},
        'created_at' => match($id) {5=>'2026-10-29',8=>'',12=>'2026-11-12',13=>'2026-11-13',15=>'2026-08-14',16=>'2026-08-15',default=>'2026-01-01'},
        'status' => $id === 10 ? 'inactive' : 'active', 'expired_quantity' => $id === 4 ? 4 : 0];
}
$saleId = 0;
$sale = static function (int $id, string $date, int $qty = 1, string $form = 'piece', ?int $pack = null) use ($pdo, &$saleId): void {
    $saleId++;
    $pdo->prepare('INSERT INTO sales VALUES (?,?)')->execute([$saleId,$date]);
    $pdo->prepare('INSERT INTO sale_items VALUES (?,?,?,?,?)')->execute([$saleId,$id,$qty,$form,$pack]);
    $pdo->prepare("INSERT INTO stock_movements (product_id,change_qty,reason,moved_at) VALUES (?,?,'sale',?)")->execute([$id,-$qty * ($form === 'pack' ? $pack : 1),$date]);
};
$sale(1,'2026-11-11 23:59:59',30);
$sale(1,'2026-11-11 09:00:00',2,'pack',6);
$sale(1,'2026-11-11 08:00:00',5);
$sale(1,'2026-09-13 00:00:00');
$sale(1,'2026-08-14 00:00:00',2);
$sale(1,'2026-08-13 23:59:59',100);
$sale(1,'2026-11-12 00:00:00',10);
$sale(17,'2026-11-12 00:00:00');
foreach (range(13,22) as $day) $sale(2,sprintf('2026-10-%02d 12:00:00',$day));
foreach (range(13,21) as $day) $sale(3,sprintf('2026-10-%02d 12:00:00',$day));
$sale(6,'2026-10-20 12:00:00');
$sale(7,'2026-10-20 12:00:00');
$sale(9,'2026-09-13 00:00:00',2);
foreach (range(13,22) as $day) $sale(14,sprintf('2026-10-%02d 12:00:00',$day));
$sale(14,'2026-10-12 23:59:59');
$sale(14,'2026-09-12 23:59:59');
$sale(14,'2026-09-11 12:00:00');
$pdo->exec("INSERT INTO stock_movements (product_id,change_qty,reason,moved_at) VALUES
    (1,20,'purchase','2026-10-13 00:00:00'),(1,2,'return','2026-10-15 10:00:00'),
    (1,-3,'adjustment','2026-10-16 10:00:00'),(1,-5,'supplier_pullout','2026-10-17 10:00:00'),
    (6,20,'purchase','2026-11-10 10:00:00'),(11,20,'purchase','2026-11-10 10:00:00')");
$rows = StockMovementReader::read($pdo, $products, $period);
$byId = array_column($rows, null, 'product_id');
$expect(count($rows) === 15 && !isset($byId[10]) && !isset($byId[13]), 'Exclude inactive and future-created products.');
$expect($byId[1]['period_sales'] === [47,1,2] && $byId[1]['period_days'] === [1,1,1], 'Bulk quantity, packs and multiple receipts on one day must count as one selling day.');
$expect($byId[1]['movement_kind'] === 'slow' && $byId[1]['trend'] === 0, 'Bulk buying must not turn a product Fast-Moving.');
$expect($byId[2]['selling_days'] === 10 && $byId[2]['movement_kind'] === 'fast', 'Ten distinct selling days is Fast-Moving.');
$expect($byId[3]['selling_days'] === 9 && $byId[3]['movement_kind'] === 'slow', 'Nine distinct selling days is Slow-Moving.');
$expect($byId[4]['movement_kind'] === 'dead', 'Ninety days without recorded sales and positive current stock is Dead Stock, regardless of expiry.');
$expect($byId[5]['movement_kind'] === 'limited' && $byId[8]['movement_kind'] === 'limited', 'New or unknown history cannot be Dead Stock.');
$expect($byId[6]['movement_kind'] === 'stockout' && $byId[6]['trend'] === null, 'Stockout-affected sales must not receive a misleading Fast/Slow label or trend.');
$expect($byId[7]['movement_kind'] === 'stockout' && $byId[7]['quantity_on_hand'] === 0, 'Zero-stock products remain available to All products and are never Dead Stock.');
$expect($byId[17]['movement_kind'] === 'stockout' && $byId[17]['trend'] === null && $byId[17]['remaining'] === 1, 'An item that sold out today without earlier sales must not become Slow-Moving or Dead Stock.');
$expect($byId[9]['movement_kind'] === 'slow' && $byId[9]['selling_days'] === 0 && $byId[9]['trend'] === -1, 'No recent sales with older sales is Slow-Moving, with a downward trend.');
$expect($byId[11]['movement_kind'] === 'dead' && $byId[11]['trend'] === null, 'The agreed Dead Stock rule remains applicable after restocking; availability still withholds trend.');
$expect($byId[12]['movement_kind'] === 'limited', 'A product added today must remain visible with Limited history.');
$expect($byId[14]['period_days'] === [10,1,2] && $byId[14]['previous_average'] === 1.5 && $byId[14]['trend'] === 1, 'Trend must compare with the exact average of the preceding two periods.');
$expect($byId[15]['movement_kind'] === 'dead' && $byId[16]['movement_kind'] === 'limited', 'The complete-history boundary must be inclusive and exactly 90 days.');
$expect($byId[1]['quantity_on_hand'] === 90 && $byId[1]['remaining'] === 100, 'Current stock includes today; yesterday closing stock reverses today movements.');
$expect($byId[1]['received'] === 20 && $byId[1]['returns'] === 2 && $byId[1]['other'] === -8 && $byId[1]['balance'] === 133, 'Latest-period receiving, sales, returns and corrections must reconcile separately.');
foreach ($rows as $row) $expect($row['balance'] + $row['received'] + $row['sale_out'] + $row['returns'] + $row['other'] === $row['remaining'], 'Latest-period stock flow must reconcile.');
$days = array_column($rows,'selling_days');
$sorted = $days; sort($sorted);
$expect($days === $sorted, 'Initially sort by fewest selling days.');
$expect(StockMovementReader::read($pdo, [], $period) === [], 'An empty Store must not need queries.');
$legacy = file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/inventory_insights.php');
$expect(str_contains($legacy, 'inventory_overview.php?tab=risk') && !str_contains($legacy, 'cycle_count_schedules'), 'Legacy links must reach Stock Movement without mutations.');
echo "Stock Movement reader: passed\n";
