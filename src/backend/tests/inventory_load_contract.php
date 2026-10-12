<?php
// Query-count and inventory-result regression checks without a live database.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
restore_exception_handler();

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

// auth.php opens the live connection; exercise just its shipped scope helpers.
$auth = file_get_contents(__DIR__ . '/../includes/auth.php');
foreach (['store_scope_id', 'store_product_scope'] as $function) {
    if (!preg_match('/function ' . $function . '\(.*?^}/ms', $auth, $match)) {
        throw new RuntimeException('Missing Store scope helper: ' . $function);
    }
    eval($match[0]);
}

final class InventoryLoadPdo extends PDO
{
    public int $scopeReads = 0;
    public int $preparedReads = 0;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, 'FROM branches b')) $this->scopeReads++;
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_starts_with(ltrim($query), 'SELECT')) $this->preparedReads++;
        $query = str_replace('DATE_ADD(CURDATE(), INTERVAL ? DAY)', "DATE(CURDATE(), '+' || ? || ' day')", $query);
        return parent::prepare($query, $options);
    }
}

$fixture = static function (int $storeId): InventoryLoadPdo {
    $connection = new InventoryLoadPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $connection->sqliteCreateFunction('CURDATE', static fn(): string => '2026-10-09', 0);
    $connection->exec("CREATE TABLE branches (branch_id INTEGER PRIMARY KEY, branch_name TEXT, branch_code TEXT, status TEXT);
        CREATE TABLE users (user_id INTEGER PRIMARY KEY, branch_id INTEGER);
        CREATE TABLE products (product_id INTEGER PRIMARY KEY, branch_id INTEGER, product_name TEXT, sku TEXT, reorder_level INTEGER);
        CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER);
        CREATE TABLE product_batches (batch_id INTEGER PRIMARY KEY, product_id INTEGER, batch_number TEXT,
            remaining_quantity INTEGER, expiration_date TEXT, date_received TEXT, supplier TEXT);
        INSERT INTO branches VALUES ({$storeId}, 'Store', 'STORE', 'active');
        INSERT INTO users VALUES (1, {$storeId});
        INSERT INTO products VALUES (1, {$storeId}, 'Alpha', 'A', 10), (2, {$storeId}, 'Zulu', 'Z', 10),
            (3, {$storeId}, 'No inventory', 'N', 10), (4, 9999, 'Foreign', 'F', 10);
        INSERT INTO inventory VALUES (1, 8), (2, 0), (4, 50)");
    return $connection;
};

$pdo = $fixture(7);
$assert(store_scope_id($pdo) === 7, 'Store identity must come from the database');
$assert(store_product_scope() === [' AND p.branch_id = ?', [7]], 'Default product scope must preserve its predicate');
$assert(store_product_scope('products_2') === [' AND products_2.branch_id = ?', [7]], 'Valid alternate aliases must remain supported');
foreach (['', 'p.branch_id', 'p; DROP TABLE products', '1product'] as $alias) {
    try {
        store_product_scope($alias);
        $assert(false, 'Invalid product alias must be rejected: ' . $alias);
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() === 'Invalid product scope alias.', 'Alias errors must retain their explanation');
    }
}

$batch = $pdo->prepare('INSERT INTO product_batches VALUES (?, ?, ?, ?, ?, ?, NULL)');
foreach ([
    [1, 1, 'late-receipt', 2, '2026-10-10', '2026-10-03'],
    [2, 2, 'zulu', 2, '2026-10-10', '2026-10-01'],
    [3, 1, 'alpha', 2, '2026-10-10', '2026-10-01'],
    [12, 1, 'undated', 2, null, '2026-09-01'],
    [20, 1, 'expired', 2, '2026-10-08', '2026-10-01'],
    [21, 1, 'empty', 0, '2026-10-09', '2026-10-01'],
    [22, 4, 'foreign', 2, '2026-10-09', '2026-10-01'],
] as $row) $batch->execute($row);
for ($id = 4; $id <= 11; $id++) {
    $batch->execute([$id, 1, 'dated-' . $id, 2, '2026-10-' . (9 + $id), '2026-10-01']);
}

$inventory = new InventoryService($pdo);
$assert(count($inventory->getLowStockProducts()) === 2, 'Low-stock list must keep zero-stock products and exclude foreign products');
$assert(count($inventory->getExpiringSoonBatches(30)) === 11, 'Expiring list must keep eligible in-Store dated batches');
$assert(array_column($inventory->getExpiredBatches(), 'batch_id') === [20], 'Expired list must retain the expired in-Store batch');
$readsBeforeSummary = $pdo->preparedReads;
$summary = $inventory->getInventorySummary();
$assert($summary === ['total_products' => 3, 'current_stock' => 8], 'Summary must count inventory-less products and keep scoped stock totals');
$assert($pdo->preparedReads - $readsBeforeSummary === 2, 'Summary must run only the two totals queries, without repeating loaded lists');
$assert(array_column($inventory->getFefoRecommendations(), 'batch_id') === [3, 2, 1, 4, 5, 6, 7, 8, 9, 10],
    'FEFO must fetch only the first ten eligible batches, ordered by expiry, receipt date, then product name');
$pdo->exec('DELETE FROM product_batches WHERE batch_id BETWEEN 4 AND 11');
$assert(array_column($inventory->getFefoRecommendations(), 'batch_id') === [3, 2, 1, 12],
    'Undated batches must follow dated stock; expired, empty, and foreign batches must stay excluded');
$assert($pdo->scopeReads === 1, 'All inventory reads and aliases must reuse one Store preflight per connection');

$first = $pdo;
$pdo = $fixture(9);
$assert(store_scope_id($pdo) === 9 && store_product_scope('p') === [' AND p.branch_id = ?', [9]],
    'A different PDO connection must resolve its own Store identity');
$assert($pdo->scopeReads === 1, 'The second connection must also perform only one Store preflight');
$pdo = $first;
$assert(store_product_scope('p') === [' AND p.branch_id = ?', [7]] && $pdo->scopeReads === 1,
    'Returning to an existing connection must retain its own cached Store identity');

$pdo = $fixture(11);
$pdo->exec("INSERT INTO branches VALUES (12, 'Other Store', 'OTHER', 'active'); INSERT INTO users VALUES (2, 12)");
try {
    store_product_scope();
    $assert(false, 'Multiple data-bearing branches must still require consolidation');
} catch (App\Store\StoreConsolidationRequired $exception) {
    $assert(count($exception->report()['branches']) === 2, 'Consolidation errors must identify both branches');
}
$pdo->exec('DELETE FROM users WHERE user_id = 2');
$assert(store_scope_id($pdo) === 11, 'A failed preflight must not poison the request cache');

if ($failures) {
    fwrite(STDERR, "Inventory load contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Inventory load contract: passed\n";
