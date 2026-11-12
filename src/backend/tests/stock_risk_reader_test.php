<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap/app.php';

use App\Inventory\StockRiskReader;

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, sale_date TEXT);
    CREATE TABLE sale_items (sale_id INTEGER, product_id INTEGER, quantity INTEGER, selling_form TEXT, pack_size INTEGER);
    CREATE TABLE stock_movements (movement_id INTEGER PRIMARY KEY, product_id INTEGER, change_qty INTEGER, moved_at TEXT)');
$products = [];
foreach ([60, 8, 18, 24, 12, 20, 36, 24, 0] as $i => $stock) {
    $products[] = ['product_id' => $i + 1, 'product_name' => 'Product ' . ($i + 1), 'quantity_on_hand' => $stock,
        'created_at' => $i === 1 ? '2026-11-08 10:00:00' : '2026-01-01 00:00:00',
        'status' => $i === 5 ? 'inactive' : 'active', 'expired_quantity' => $i === 4 ? 4 : 0];
}
$pdo->exec("INSERT INTO sales VALUES (1, '2026-11-10 10:00:00'), (2, '2026-07-01 10:00:00'), (3, '2026-12-01 10:00:00');
    INSERT INTO sale_items VALUES (1,1,6,'piece',NULL),(1,2,2,'piece',NULL),(1,5,12,'piece',NULL),(1,7,2,'pack',6),
    (2,4,10,'piece',NULL),(3,4,10,'piece',NULL);
    INSERT INTO stock_movements VALUES (1,3,18,'2026-11-10 12:00:00')");
$rows = StockRiskReader::read($pdo, $products, [['product_id' => 8, 'remaining_quantity' => 3]], '2026-11-12');
$byId = array_column($rows, null, 'product_id');
$expect = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$expect(count($rows) === 8 && !isset($byId[6]), 'Only scoped active products may appear.');
$expect($byId[1]['days_of_stock'] === 300.0 && in_array('excess', $byId[1]['flags'], true), 'Slow stock uses the 30-day base-unit sales pace.');
$expect($byId[2]['risk_kind'] === 'limited' && $byId[2]['days_of_stock'] === null, 'New products cannot receive mature coverage or excess labels.');
$expect($byId[3]['risk_kind'] === 'limited' && !in_array('no-sales', $byId[3]['flags'], true), 'A restocked product must not be labelled as having no demand.');
$expect($byId[4]['units90'] === 0 && $byId[4]['risk_kind'] === 'no-sales', 'Old and future sales are excluded from the no-sales observation window.');
$expect($byId[5]['risk_label'] === 'Expired stock' && $byId[5]['days_of_stock'] === 20.0, 'Expired units are excluded from saleable stock coverage.');
$expect($byId[7]['units30'] === 12, 'Sales Packs must be converted to Base Units.');
$expect($byId[8]['flags'] === ['expiry', 'no-sales'], 'Overlapping concerns must remain discoverable through filters and details.');
$expect($byId[9]['flags'] === [], 'Empty stock must not be classified as dead or excess stock.');
$expect($byId[3]['opening_balance'] === 0 && $byId[3]['stock_in90'] === 18, 'Movement detail must reconcile the recorded opening and remaining balances.');
$expect(StockRiskReader::read($pdo, [], [], '2026-11-12') === [], 'An empty Store must not need a sales or movement query.');
$legacy = file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/inventory_insights.php');
$expect(str_contains($legacy, 'inventory_overview.php?tab=risk') && !str_contains($legacy, 'cycle_count_schedules'), 'The legacy route must retire schedule mutations and point to Stock Risk.');
echo "Stock Risk reader: passed\n";
