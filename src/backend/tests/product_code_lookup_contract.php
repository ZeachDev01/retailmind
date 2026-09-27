<?php
// Contract for the shared read-only product code lookup (ticket #79):
// exact SKU / unit-barcode / case-barcode resolution over active products in
// the single Store, match-kind reporting, zero-stock and inventory-less
// products, ambiguity refusal, inactive and out-of-scope exclusion.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductCodeLookupService.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo'] = $pdo;

$pdo->exec("CREATE TABLE branches (
    branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name VARCHAR(100) NOT NULL UNIQUE,
    branch_code VARCHAR(30) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO branches (branch_name, branch_code, status)
            VALUES ('RetailMind Store', 'RETAILMIND-STORE', 'active')");
$storeId = (int)$pdo->lastInsertId();
// Out-of-Store products point at a branch that is not the singleton Store.
// StoreScope keeps exactly one data-bearing branch, so the foreign product
// must live outside the branches table rather than in a second Store.
$foreignBranchId = 4242;

// StoreScope inspects users and products per branch during its preflight.
$pdo->exec('CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NULL
)');

$pdo->exec('CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NULL,
    sku VARCHAR(50) NOT NULL,
    barcode VARCHAR(50) NOT NULL DEFAULT \'\',
    case_barcode VARCHAR(80) NULL,
    product_name VARCHAR(150) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT \'active\'
)');
$pdo->exec('CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER NOT NULL DEFAULT 0)');

$insertProduct = static function (array $product) use ($pdo): int {
    $pdo->prepare(
        'INSERT INTO products (branch_id, sku, barcode, case_barcode, product_name, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $product['branch_id'],
        $product['sku'],
        $product['barcode'],
        $product['case_barcode'] ?? null,
        $product['product_name'],
        $product['status'] ?? 'active',
    ]);
    return (int)$pdo->lastInsertId();
};
$stock = static function (int $productId, int $quantity) use ($pdo): void {
    $pdo->prepare('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (?, ?)')
        ->execute([$productId, $quantity]);
};

// SKU / unit barcode / case barcode holders, all inside the single Store.
$skuProduct = $insertProduct([
    'branch_id' => $storeId, 'sku' => 'SCAN-SKU-1', 'barcode' => '8400000000001',
    'case_barcode' => 'CASE-111', 'product_name' => 'Scan SKU Product',
]);
$stock($skuProduct, 12);

// Zero-stock active product: must still be reachable by every code kind.
$zeroStockProduct = $insertProduct([
    'branch_id' => $storeId, 'sku' => 'SCAN-ZERO-1', 'barcode' => '8400000000002',
    'case_barcode' => 'CASE-222', 'product_name' => 'Zero Stock Product',
]);
$stock($zeroStockProduct, 0);

// Active product without any inventory row at all: must still be reachable.
$noInventoryProduct = $insertProduct([
    'branch_id' => $storeId, 'sku' => 'SCAN-NOINV-1', 'barcode' => '8400000000003',
    'case_barcode' => null, 'product_name' => 'No Inventory Row Product',
]);

// Inactive product must never be resolved.
$inactiveProduct = $insertProduct([
    'branch_id' => $storeId, 'sku' => 'SCAN-INACTIVE-1', 'barcode' => '8400000000004',
    'case_barcode' => 'CASE-444', 'product_name' => 'Inactive Product', 'status' => 'inactive',
]);
$stock($inactiveProduct, 9);

// Same code on two different active products: one stocked, one not, so a
// sellable-stock filter could not break the tie either. Must stay ambiguous.
$ambiguousStocked = $insertProduct([
    'branch_id' => $storeId, 'sku' => 'AMBIG-A', 'barcode' => 'SHARED-CODE-77',
    'product_name' => 'Ambiguous Stocked Product',
]);
$stock($ambiguousStocked, 25);
$ambiguousEmpty = $insertProduct([
    'branch_id' => $storeId, 'sku' => 'SHARED-CODE-77', 'barcode' => 'AMBIG-B',
    'product_name' => 'Ambiguous Empty Product',
]);
$stock($ambiguousEmpty, 0);

// Out-of-Store product carrying a valid-looking code must stay invisible.
$foreignProduct = $insertProduct([
    'branch_id' => $foreignBranchId, 'sku' => 'FOREIGN-SKU', 'barcode' => '8400000000099',
    'product_name' => 'Foreign Store Product',
]);
$stock($foreignProduct, 5);

$lookup = new ProductCodeLookupService($pdo);

// --- SKU exact match -------------------------------------------------------
$result = $lookup->lookup('SCAN-SKU-1');
$assert($result['outcome'] === ProductCodeLookupService::OUTCOME_MATCH, 'SKU code must resolve to a match');
$assert($result['matched_code_kind'] === ProductCodeLookupService::KIND_SKU, 'SKU code must report the sku match kind');
$assert((int)$result['product']['product_id'] === $skuProduct, 'SKU code must resolve the SKU product');
$assert((string)$result['product']['sku'] === 'SCAN-SKU-1', 'Match must expose the product SKU');
$assert((int)$result['product']['quantity_on_hand'] === 12, 'Match must expose the system quantity');

// --- Unit barcode exact match ---------------------------------------------
$result = $lookup->lookup('8400000000001');
$assert($result['outcome'] === ProductCodeLookupService::OUTCOME_MATCH, 'Unit barcode must resolve to a match');
$assert($result['matched_code_kind'] === ProductCodeLookupService::KIND_UNIT_BARCODE, 'Unit barcode must report the unit_barcode kind');
$assert((int)$result['product']['product_id'] === $skuProduct, 'Unit barcode must resolve the labelled product');

// --- Case barcode exact match ---------------------------------------------
$result = $lookup->lookup('CASE-111');
$assert($result['outcome'] === ProductCodeLookupService::OUTCOME_MATCH, 'Case barcode must resolve to a match');
$assert($result['matched_code_kind'] === ProductCodeLookupService::KIND_CASE_BARCODE, 'Case barcode must report the case_barcode kind');
$assert((int)$result['product']['product_id'] === $skuProduct, 'Case barcode must resolve the packaged product');

// --- Zero stock and missing inventory rows --------------------------------
foreach (['SCAN-ZERO-1', '8400000000002', 'CASE-222'] as $code) {
    $result = $lookup->lookup($code);
    $assert($result['outcome'] === ProductCodeLookupService::OUTCOME_MATCH, "Zero-stock code {$code} must still resolve");
    $assert((int)$result['product']['product_id'] === $zeroStockProduct, "Zero-stock code {$code} must resolve the right product");
    $assert((int)$result['product']['quantity_on_hand'] === 0, "Zero-stock code {$code} must report a system quantity of zero");
}
$result = $lookup->lookup('SCAN-NOINV-1');
$assert($result['outcome'] === ProductCodeLookupService::OUTCOME_MATCH, 'Active product without an inventory row must still resolve');
$assert((int)$result['product']['quantity_on_hand'] === 0, 'Missing inventory row must report zero system quantity');

// --- Unknown, blank, inactive and out-of-Store codes -----------------------
$unknown = $lookup->lookup('NO-SUCH-CODE');
$assert($unknown['outcome'] === ProductCodeLookupService::OUTCOME_UNKNOWN, 'Unknown code must report the unknown outcome');
$assert($unknown['product'] === null, 'Unknown code must not resolve a product');
$assert($unknown['matched_code_kind'] === null, 'Unknown code must not report a match kind');
$assert($unknown['candidates'] === [], 'Unknown code must not offer candidates');

$blank = $lookup->lookup('   ');
$assert($blank['outcome'] === ProductCodeLookupService::OUTCOME_UNKNOWN, 'Blank code must report the unknown outcome');
$assert($blank['product'] === null, 'Blank code must not resolve a product');

$inactive = $lookup->lookup('SCAN-INACTIVE-1');
$assert($inactive['outcome'] === ProductCodeLookupService::OUTCOME_UNKNOWN, 'Inactive product code must stay unresolved');
$assert($inactive['product'] === null, 'Inactive product must not be resolved by scan');

$caseOfInactive = $lookup->lookup('CASE-444');
$assert($caseOfInactive['outcome'] === ProductCodeLookupService::OUTCOME_UNKNOWN, 'Inactive product case barcode must stay unresolved');

$foreign = $lookup->lookup('FOREIGN-SKU');
$assert($foreign['outcome'] === ProductCodeLookupService::OUTCOME_UNKNOWN, 'Out-of-Store SKU must stay unresolved');
$foreignBarcode = $lookup->lookup('8400000000099');
$assert($foreignBarcode['outcome'] === ProductCodeLookupService::OUTCOME_UNKNOWN, 'Out-of-Store barcode must stay unresolved');

// --- Ambiguity is refused, never resolved arbitrarily ----------------------
$ambiguous = $lookup->lookup('SHARED-CODE-77');
$assert($ambiguous['outcome'] === ProductCodeLookupService::OUTCOME_AMBIGUOUS, 'Ambiguous code must report the ambiguous outcome');
$assert($ambiguous['product'] === null, 'Ambiguous code must never select one product arbitrarily');
$assert($ambiguous['matched_code_kind'] === null, 'Ambiguous code must not report a single match kind');
$candidateIds = array_map(static fn(array $candidate): int => (int)$candidate['product_id'], $ambiguous['candidates']);
sort($candidateIds);
$expected = [$ambiguousStocked, $ambiguousEmpty];
sort($expected);
$assert($candidateIds === $expected, 'Ambiguous code must list every candidate product');

// --- Whitespace is tolerated around an otherwise exact code ---------------
$padded = $lookup->lookup('  SCAN-SKU-1  ');
$assert($padded['outcome'] === ProductCodeLookupService::OUTCOME_MATCH, 'Whitespace around an exact code must be tolerated');
$assert((int)$padded['product']['product_id'] === $skuProduct, 'Padded code must resolve the same product');

if ($failures) {
    fwrite(STDERR, "Product code lookup contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Product code lookup contract: passed\n";
