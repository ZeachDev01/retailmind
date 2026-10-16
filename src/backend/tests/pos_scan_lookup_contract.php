<?php
// Exercise the POS handler with the real shared lookup and an isolated database.
if (!isset($argv[1])) {
    $expected = ['valid' => true, 'unknown' => false, 'ambiguous' => false,
        'stock' => false, 'expired' => false, 'invalid' => false, 'quantity' => false, 'locked' => false];
    foreach ($expected as $case => $success) {
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case));
        $result = json_decode((string)$output, true);
        if (!is_array($result) || ($result['success'] ?? null) !== $success) {
            throw new RuntimeException("POS scan lookup failed: {$case}: {$output}");
        }
        if ($success && ((int)$result['product']['quantity_on_hand'] !== 3 || (float)$result['product']['unit_price'] !== 12.0)) {
            throw new RuntimeException('POS scan lookup returned incorrect stock or price.');
        }
    }
    echo "POS scan lookup: passed\n";
    exit;
}

function csrf_verify(): void {
    if (($_POST['csrf_token'] ?? '') !== 'fixture') throw new RuntimeException('CSRF check failed');
}
function store_product_scope(string $alias): array { return ['', []]; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('CURDATE', static fn() => '2026-10-15');
$pdo->sqliteCreateFunction('LEAST', static fn(...$values) => min($values));
$pdo->exec('CREATE TABLE products (product_id INTEGER, sku TEXT, barcode TEXT, case_barcode TEXT,
    product_name TEXT, unit_price REAL, reorder_level INTEGER, safety_stock INTEGER, status TEXT)');
$pdo->exec('CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER)');
$pdo->exec('CREATE TABLE product_batches (product_id INTEGER, expiration_date TEXT, remaining_quantity INTEGER)');
$pdo->exec("INSERT INTO products VALUES (1, 'MILK', '123456', NULL, 'Milk', 12, 0, 0, 'active')");
$pdo->exec('INSERT INTO inventory VALUES (1, 3)');
$posShiftOpen = true;
$posRegisterLocked = $argv[1] === 'locked';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'scan_lookup', 'code' => '123456', 'quantities' => '{"1":1}', 'csrf_token' => 'fixture'];
switch ($argv[1]) {
    case 'unknown': $_POST['code'] = '999999'; break;
    case 'ambiguous':
        $pdo->exec("INSERT INTO products VALUES (2, 'OTHER', '123456', NULL, 'Other', 12, 0, 0, 'active')"); break;
    case 'stock': $_POST['quantities'] = '{"1":3}'; break;
    case 'expired': $pdo->exec("INSERT INTO product_batches VALUES (1, '2026-10-14', 3)"); break;
    case 'invalid': $_POST['code'] = "123\n456"; break;
    case 'quantity': $_POST['quantities'] = '{"1":-1}'; break;
}
$page = realpath(__DIR__ . '/../../frontend/components/cashier/pos.php');
require_once __DIR__ . '/../app/Services/ProductCodeLookupService.php';
$source = file_get_contents($page);
$start = strpos($source, "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && (\$_POST['action'] ?? '') === 'scan_lookup')");
$end = strpos($source, '// Ticket #90: the lock screen', $start);
$handler = substr($source, $start, $end - $start);
$handler = str_replace('__DIR__', var_export(dirname($page), true), $handler);
eval($handler);
