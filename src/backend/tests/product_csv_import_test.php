<?php
// Exercise the actual product import loop against an isolated database.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE categories (category_id INTEGER PRIMARY KEY, category_name TEXT)');
$pdo->exec('CREATE TABLE products (product_id INTEGER PRIMARY KEY, sku TEXT UNIQUE, barcode TEXT, product_name TEXT, brand TEXT, category_id INTEGER REFERENCES categories(category_id), unit_price REAL, cost_price REAL, reorder_level INTEGER, supplier TEXT, preferred_supplier TEXT, supplier_lead_time_days INTEGER, safety_stock INTEGER, minimum_order_quantity INTEGER, units_per_package INTEGER, created_by INTEGER, branch_id INTEGER)');
$pdo->exec('CREATE TABLE inventory (product_id INTEGER PRIMARY KEY REFERENCES products(product_id), quantity_on_hand INTEGER)');
$pdo->exec('CREATE TABLE scheduled_demand_drivers (product_id INTEGER REFERENCES products(product_id), driver_type TEXT, effective_date TEXT, scheduled_at TEXT, details_json TEXT)');
$pdo->exec('CREATE TABLE activity_log (user_id INTEGER, action TEXT)');
$_SESSION['user_id'] = 1;
$import_store_id = 1;

$source = file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/csv_import.php');
$start = strpos($source, '            while (($row = fgetcsv($fp)) !== false) {');
$end = strpos($source, '            $message = "Product import completed:', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Product import loop not found');
}
$loop = substr($source, $start, $end - $start);
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$run = static function (string $csv) use ($pdo, $import_store_id, $loop): array {
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csv);
    rewind($fp);
    fgetcsv($fp);
    $successful = $failed = 0;
    $errors = [];
    eval($loop);
    fclose($fp);
    return [$successful, $failed];
};
$csv = file_get_contents(dirname(__DIR__, 3) . '/retailmind-products-import-sample.csv');
$check($run($csv) === [50, 0], 'All 50 sample products must import');
$check((int)$pdo->query('SELECT COUNT(*) FROM inventory')->fetchColumn() === 50, 'Each product needs inventory');
$pdo->exec('UPDATE inventory SET quantity_on_hand = 12 WHERE product_id = 2');
$pdo->exec('DELETE FROM inventory WHERE product_id = 1');
$check($run($csv) === [50, 0], 'Retry must succeed');
$check((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 50, 'Retry must not duplicate products');
$check((int)$pdo->query('SELECT COUNT(*) FROM inventory')->fetchColumn() === 50, 'Retry must restore missing inventory');
$check((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 2')->fetchColumn() === 12, 'Retry must preserve stock');
$pdo->exec('DROP TABLE scheduled_demand_drivers');
$check($run("header\nFAIL-1,FAIL-1,Failure product,Brand,New category,45,30\n") === [0, 1], 'Missing driver table must fail the row');
$check((int)$pdo->query("SELECT COUNT(*) FROM products WHERE sku = 'FAIL-1'")->fetchColumn() === 0, 'Failed row must roll back product');
$check((int)$pdo->query("SELECT COUNT(*) FROM categories WHERE category_name = 'New category'")->fetchColumn() === 0, 'Failed row must roll back category');
$check(!$pdo->inTransaction(), 'Failed row must close its transaction');
echo "PASS: 50 products, retries, stock preservation, and failed-row rollback\n";
