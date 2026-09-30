<?php

require_once __DIR__ . '/../app/Services/ReceiptDetailsService.php';

use App\Services\ReceiptDetailsService;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, email TEXT, branch_id INTEGER)');
$pdo->exec('CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, register_id INTEGER)');
$pdo->exec('CREATE TABLE products (product_id INTEGER PRIMARY KEY, branch_id INTEGER)');
$pdo->exec('CREATE TABLE sale_items (sale_item_id INTEGER PRIMARY KEY, sale_id INTEGER, product_id INTEGER)');
$pdo->exec('CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, shift_id INTEGER,
    total_amount REAL, payment_method TEXT, sale_date TEXT)');
$pdo->exec("INSERT INTO users VALUES (7, 'Alice & Co', 'private_username', 'private@example.test', 1)");
$pdo->exec("INSERT INTO users VALUES (8, 'Other Cashier', 'other_private', 'other@example.test', 2)");
$pdo->exec("INSERT INTO registers VALUES (12, 'Front <Counter>')");
$pdo->exec('INSERT INTO cashier_shifts VALUES (23, 12)');
$pdo->exec("INSERT INTO sales VALUES (42, 7, 23, 10, 'cash', '2026-09-30 10:11:12')");
$pdo->exec("INSERT INTO sales VALUES (43, 7, NULL, 10, 'cash', '2026-09-30 10:12:13')");
$pdo->exec("INSERT INTO sales VALUES (44, 8, NULL, 10, 'cash', '2026-09-30 10:13:14')");
$pdo->exec('INSERT INTO products VALUES (5, 1)');
$pdo->exec('INSERT INTO sale_items VALUES (6, 43, 5)');

$service = new ReceiptDetailsService($pdo);
$sale = $service->fetchSale(42);
$html = ReceiptDetailsService::renderMetadata($sale);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assert(str_contains($html, 'Receipt #42'), 'receipt number is rendered');
$assert(str_contains($html, '2026-09-30 10:11:12'), 'transaction timestamp is rendered');
$assert(str_contains($html, 'Cashier: Alice &amp; Co'), 'Cashier display name is rendered safely');
$assert(str_contains($html, 'Register: Front &lt;Counter&gt;'), 'Register display name is rendered safely');
$assert(!str_contains($html, 'private_username') && !str_contains($html, 'private@example.test'),
    'private Staff account fields are absent');
$assert(!str_contains($html, 'user_id') && !str_contains($html, 'cashier_id') && !str_contains($html, 'shift_id')
    && !str_contains($html, 'session'), 'internal identifiers and session details are absent');
$assert(!array_key_exists('username', $sale) && !array_key_exists('email', $sale),
    'private Staff account fields are not fetched');

$pdo->exec("UPDATE registers SET name = 'Main Till' WHERE register_id = 12");
$renamed = $service->fetchSale(42);
$assert(str_contains(ReceiptDetailsService::renderMetadata($renamed), 'Register: Main Till'),
    'a rename updates the displayed name through the stable Register identity');
$assert((int)$pdo->query('SELECT register_id FROM cashier_shifts WHERE shift_id = 23')->fetchColumn() === 12,
    'a rename preserves the shift Register identity');

$legacy = $service->fetchSale(43);
$assert(str_contains(ReceiptDetailsService::renderMetadata($legacy), 'Register: Legacy / Unassigned'),
    'an unlinked sale receives an explicit safe Register fallback');
$assert($legacy['register_name'] === null, 'an unlinked sale is not assigned a Register');
$assert($service->fetchSale(42, 1) !== null, 'the frontend Store can fetch its receipt');
$assert($service->fetchSale(44, 1) === null, 'the frontend Store cannot fetch another Store receipt');
$assert($service->fetchSale(43, 1) !== null, 'the frontend Store accepts a matching product for an older sale');
$assert($service->fetchSale(44, 2) !== null, 'the other Store can fetch its own receipt');

// Execute each real receipt renderer with the queried row. This covers the
// complete customer-facing receipt, including its printable content.
function receipt_store_info(): array {
    return ['name' => 'Test Store', 'tagline' => 'Official sales receipt', 'address' => 'Test Street',
        'contact' => '555', 'tin' => 'T-1', 'currency_symbol' => '$', 'footer' => 'Thank you'];
}
function receipt_money($value): string {
    return '$' . number_format((float)$value, 2);
}
function receipt_verification_code(array $sale): string {
    return 'TEST-CODE';
}
function app_url(string $path): string {
    return '/' . $path;
}

$root = dirname(__DIR__, 3);
$items = [['sku' => 'SKU-1', 'product_name' => 'Test item', 'quantity' => 1,
    'unit_price' => 10, 'subtotal' => 10]];
foreach ([
    ['src/frontend/components/invoice/sales.php', 'render_frontend_receipt', $service->fetchSale(42, 1)],
    ['src/backend/legacy/routes/invoice/receipt.php', 'render_legacy_receipt', $service->fetchSale(42)],
] as [$route, $function, $queriedSale]) {
    $source = file_get_contents($root . '/' . $route);
    if ($function === 'render_frontend_receipt') {
        $assert(str_contains($source, '(new ReceiptDetailsService($pdo))->fetchSale($sale_id, $storeId)'),
            'the frontend receipt query uses the scoped service');
        $assert(substr_count($source, 'receipt_fetch_sale($pdo, $sale_id, $storeId)') >= 2,
            'both frontend view paths fetch Store-scoped receipt details');
    }
    $assert((bool)preg_match('/function receipt_render_details\([^)]*\): void \{.*?^\}/ms', $source, $match),
        $route . ' has a complete receipt renderer');
    $renderer = preg_replace('/^function receipt_render_details/', 'function ' . $function, $match[0]);
    if ($function === 'render_frontend_receipt') {
        $renderer = str_replace('ReceiptDetailsService::', '\\App\\Services\\ReceiptDetailsService::', $renderer);
    }
    eval($renderer);
    ob_start();
    $function($queriedSale, $items, false);
    $receipt = ob_get_clean();
    $assert(str_contains($receipt, 'Historical receipt: original Store and item details were not preserved'),
        $route . ' visibly marks pre-feature receipt details as reconstructed');
    foreach (['Transaction #42', 'Receipt #42', '2026-09-30 10:11:12',
        'Cashier: Alice &amp; Co', 'Register: Main Till', 'Test item', 'Total Amount'] as $required) {
        $assert(str_contains($receipt, $required), $route . ' renders ' . $required);
    }
    foreach (['private_username', 'private@example.test', 'cashier_id', 'shift_id', 'register_id',
        'Cashier Shift:', 'session'] as $private) {
        $assert(!str_contains($receipt, $private), $route . ' hides ' . $private);
    }
}

echo "Receipt attribution contract: passed\n";
