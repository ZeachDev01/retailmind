<?php
// Ticket #255: prove a fresh schema.sql install and an upgraded pre-pack database
// converge with every Sales Pack migration applied, and that legacy rows keep
// their meaning (loose/unknown, piece sales) after the upgrade.
//
// Creates only uniquely named disposable databases; never the configured Store.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Sales pack schema parity: skipped (set RUN_DB_TESTS=1; requires CREATE DATABASE)\n";
    exit(0);
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/backup.php';

use App\Database\MigrationRunner;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "  ok - {$message}\n";
    } else {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    }
};

$config = require __DIR__ . '/../config/database.php';
$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']);
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$server = new PDO($dsn, $config['username'], $config['password'], $options);
$migrationPath = __DIR__ . '/../database/migrations';
$schemaPath = __DIR__ . '/../database/sql/schema.sql';
$updatePath = __DIR__ . '/../database/sql/update.sql';

// importSchema mirrors a normal dump import: FOREIGN_KEY_CHECKS off for the whole
// file (the dump defines gcash_adjustments before gcash_transactions), one
// statement at a time.
$importSchema = static function (PDO $db, string $file): void {
    $sql = preg_replace('/\/\*!\d{5}\s*(.*?)\*\//s', '$1', (string)file_get_contents($file));
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (split_sql_statements($sql) as $statement) {
        if (stripos($statement, 'FOREIGN_KEY_CHECKS') !== false) {
            continue; // Session keeps checks off across the whole dump.
        }
        $db->query($statement)->closeCursor();
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
};

// The Sales Pack surface introduced by the #245 slices.
$packColumns = [
    'products' => ['pack_size', 'pack_barcode', 'pack_price'],
    'product_batches' => ['sealed_quantity', 'loose_quantity'],
    'stock_receiving' => ['received_packs', 'accepted_packs', 'damaged_packs', 'rejected_packs', 'received_loose', 'accepted_loose', 'damaged_loose', 'rejected_loose', 'pack_size_used'],
    'sale_items' => ['selling_form', 'pack_size'],
    'inventory_counts' => ['system_sealed_quantity', 'system_loose_quantity', 'physical_sealed_quantity', 'physical_loose_quantity', 'difference_sealed_qty', 'difference_loose_qty'],
    'inventory_count_batches' => ['system_sealed_quantity', 'system_loose_quantity', 'physical_sealed_quantity', 'physical_loose_quantity', 'difference_sealed_qty', 'difference_loose_qty'],
    'inventory_adjustments' => ['form', 'reported_qty', 'pack_size'],
    'stock_issue_batches' => ['form', 'reported_qty'],
    'supplier_pullouts' => ['pulled_packs', 'pulled_loose', 'pack_size_used'],
    'cash_refund_items' => ['selling_form', 'pack_size'],
];

$columnShapes = static function (PDO $db, array $columns): array {
    $shapes = [];
    $stmt = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach ($columns as $table => $names) {
        foreach ($names as $name) {
            $stmt->execute([$table, $name]);
            $row = $stmt->fetch();
            $shapes["{$table}.{$name}"] = $row === false
                ? null
                : ['type' => $row['COLUMN_TYPE'], 'nullable' => $row['IS_NULLABLE'], 'default' => $row['COLUMN_DEFAULT']];
        }
    }
    return $shapes;
};

$names = ['rm_pack_parity_fresh_' . bin2hex(random_bytes(4)), 'rm_pack_parity_legacy_' . bin2hex(random_bytes(4))];
$allPackColumns = [];
foreach ($packColumns as $table => $cols) {
    foreach ($cols as $c) {
        $allPackColumns[] = $c;
    }
}

echo "Starting Sales Pack schema parity tests...\n";

try {
    // ---- Fresh install: schema.sql + every migration ----
    $server->exec("CREATE DATABASE `{$names[0]}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $fresh = new PDO($dsn . ';dbname=' . $names[0], $config['username'], $config['password'], $options);
    $importSchema($fresh, $schemaPath);
    $freshRunner = new MigrationRunner($fresh, $migrationPath);
    $freshRunner->runPending();
    $assert($freshRunner->pending() === [], 'Fresh schema.sql install plus migrations leaves no pending migration');
    $freshShapes = $columnShapes($fresh, $packColumns);
    $assert(!in_array(null, $freshShapes, true), 'Fresh install exposes every recorded Sales Pack column');
    $assert((int)$fresh->query("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'uq_products_branch_pack_barcode'")->fetchColumn() === 1,
        'Fresh install enforces the Sale Pack barcode uniqueness key');

    // ---- Upgraded legacy database: simulate a pre-pack schema from the same dump ----
    $server->exec("CREATE DATABASE `{$names[1]}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $legacy = new PDO($dsn . ';dbname=' . $names[1], $config['username'], $config['password'], $options);
    $importSchema($legacy, $schemaPath);

    $legacy->exec("ALTER TABLE products DROP INDEX uq_products_branch_pack_barcode, DROP COLUMN pack_price, DROP COLUMN pack_barcode, DROP COLUMN pack_size");
    $legacy->exec('ALTER TABLE product_batches DROP COLUMN loose_quantity, DROP COLUMN sealed_quantity');
    $legacy->exec('ALTER TABLE stock_receiving DROP COLUMN received_packs, DROP COLUMN accepted_packs, DROP COLUMN damaged_packs, DROP COLUMN rejected_packs, DROP COLUMN received_loose, DROP COLUMN accepted_loose, DROP COLUMN damaged_loose, DROP COLUMN rejected_loose, DROP COLUMN pack_size_used');
    $legacy->exec('ALTER TABLE sale_items DROP COLUMN pack_size, DROP COLUMN selling_form');
    $legacy->exec('ALTER TABLE inventory_counts DROP COLUMN system_sealed_quantity, DROP COLUMN system_loose_quantity, DROP COLUMN physical_sealed_quantity, DROP COLUMN physical_loose_quantity, DROP COLUMN difference_sealed_qty, DROP COLUMN difference_loose_qty');
    $legacy->exec('ALTER TABLE inventory_count_batches DROP COLUMN system_sealed_quantity, DROP COLUMN system_loose_quantity, DROP COLUMN physical_sealed_quantity, DROP COLUMN physical_loose_quantity, DROP COLUMN difference_sealed_qty, DROP COLUMN difference_loose_qty');
    $legacy->exec('ALTER TABLE inventory_adjustments DROP COLUMN pack_size, DROP COLUMN reported_qty, DROP COLUMN form');
    $legacy->exec('ALTER TABLE stock_issue_batches DROP COLUMN reported_qty, DROP COLUMN form');
    $legacy->exec('ALTER TABLE supplier_pullouts DROP COLUMN pack_size_used, DROP COLUMN pulled_loose, DROP COLUMN pulled_packs');
    $legacy->exec('ALTER TABLE cash_refund_items DROP COLUMN pack_size, DROP COLUMN selling_form');

    // Recorded historical rows that predate Sales Packs.
    $pid = (int)$legacy->query('SELECT product_id FROM products ORDER BY product_id LIMIT 1')->fetchColumn();
    $uid = (int)$legacy->query('SELECT user_id FROM users WHERE is_recovery_account = 0 ORDER BY user_id LIMIT 1')->fetchColumn();
    $bid = (int)$legacy->query('SELECT batch_id FROM product_batches ORDER BY batch_id LIMIT 1')->fetchColumn();
    $legacy->exec("UPDATE product_batches SET remaining_quantity = 12, quantity = 12 WHERE batch_id = {$bid}");
    $legacy->exec("INSERT INTO stock_receiving (product_id, received_qty, accepted_qty, damaged_qty, received_by) VALUES ({$pid}, 5, 4, 1, {$uid})");
    $legacy->exec("INSERT INTO inventory_adjustments (product_id, adjustment_qty, adjustment_type, reported_by) VALUES ({$pid}, -3, 'damaged', {$uid})");
    $adjId = (int)$legacy->lastInsertId();
    $legacy->exec("INSERT INTO stock_issue_batches (adjustment_id, batch_id, quantity) VALUES ({$adjId}, {$bid}, 2)");
    $legacy->exec("INSERT INTO inventory_counts (product_id, system_quantity, physical_quantity, difference_qty, discrepancy_reason, counted_by) VALUES ({$pid}, 10, 8, -2, 'legacy count', {$uid})");
    $countId = (int)$legacy->lastInsertId();
    $legacy->exec("INSERT INTO inventory_count_batches (count_id, batch_id, system_quantity, physical_quantity, difference_qty) VALUES ({$countId}, {$bid}, 10, 8, -2)");
    $legacy->exec("INSERT INTO supplier_pullouts (product_id, batch_id, quantity, reason, pulled_by) VALUES ({$pid}, {$bid}, 3, 'legacy pullout', {$uid})");
    // Historical receipt and refund rows that predate the form columns.
    $legacy->exec("INSERT INTO sales (cashier_id, total_amount, payment_method) VALUES ({$uid}, 10.00, 'cash')");
    $legacySaleId = (int)$legacy->lastInsertId();
    $legacy->exec("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES ({$legacySaleId}, {$pid}, 2, 5.00, 10.00)");
    $legacyShiftId = (int)$legacy->query('SELECT shift_id FROM cashier_shifts ORDER BY shift_id LIMIT 1')->fetchColumn();
    $legacyRefundItem = 0;
    if ($legacyShiftId > 0) {
        $legacy->exec("INSERT INTO cash_refunds (sale_id, shift_id, cashier_id, refund_amount, payment_method, reason) VALUES ({$legacySaleId}, {$legacyShiftId}, {$uid}, 5.00, 'cash', 'customer_return')");
        $legacyRefundId = (int)$legacy->lastInsertId();
        $legacySaleItemId = (int)$legacy->query("SELECT sale_item_id FROM sale_items WHERE sale_id = {$legacySaleId}")->fetchColumn();
        $legacy->exec("INSERT INTO cash_refund_items (refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition) VALUES ({$legacyRefundId}, {$legacySaleItemId}, {$pid}, 1, 5.00, 5.00, 'restockable')");
        $legacyRefundItem = 1;
    }

    // The pre-pack dump counts as "migrations not yet recorded" for the pack slices.
    $packKeys = [
        '202610200001_sales_packs_and_stock_forms',
        '202610200002_stock_receiving_sales_pack_forms',
        '202610200003_sale_items_sales_pack_forms',
        '202610200004_stock_counts_and_issues_forms',
        '202610200005_supplier_movements_stock_forms',
        '202610200006_cash_refund_sales_pack_forms',
    ];
    $legacy->exec("DELETE FROM schema_migrations WHERE migration_key IN ('" . implode("','", $packKeys) . "')");

    $legacyRunner = new MigrationRunner($legacy, $migrationPath);
    $legacyRunner->runPending();
    $assert($legacyRunner->pending() === [], 'Upgraded legacy database has no pending Sales Pack migration');

    // Migration must be safe to run again (a re-imported ledger or repeated upgrade).
    $legacyRunner->runPending();
    $assert($columnShapes($legacy, $packColumns) === $freshShapes, 'Upgraded legacy Sales Pack columns match the fresh schema exactly');

    // Legacy stock is unknown/loose: never inferred as sealed from a total.
    $assert((int)$legacy->query('SELECT COUNT(*) FROM product_batches WHERE remaining_quantity > 0 AND sealed_quantity <> 0')->fetchColumn() === 0,
        'Upgraded legacy stock starts loose/unknown (no inferred sealed packs)');
    $assert((int)$legacy->query('SELECT COUNT(*) FROM product_batches WHERE remaining_quantity > 0 AND loose_quantity <> remaining_quantity')->fetchColumn() === 0,
        'Upgraded legacy stock records its remaining total as loose pieces');
    $assert((int)$legacy->query('SELECT loose_quantity FROM product_batches WHERE batch_id = ' . $bid)->fetchColumn() === 12,
        'Legacy batch keeps its exact remaining quantity as loose pieces');

    // Historical receipt/refund rows keep piece meaning.
    $assert((int)$legacy->query("SELECT COUNT(*) FROM sale_items WHERE sale_id = {$legacySaleId} AND selling_form = 'piece' AND pack_size IS NULL")->fetchColumn() === 1,
        'Historical sale_items remain piece-form with no invented pack size');
    $assert($legacyRefundItem === 0 || (int)$legacy->query("SELECT COUNT(*) FROM cash_refund_items WHERE refund_id = {$legacyRefundId} AND selling_form = 'piece' AND pack_size IS NULL")->fetchColumn() === 1,
        'Historical cash_refund_items remain piece-form with no invented pack size');

    // Form backfills keep the original totals readable.
    $assert((int)$legacy->query("SELECT received_loose FROM stock_receiving WHERE received_qty = 5 AND received_by = {$uid} ORDER BY receiving_id DESC LIMIT 1")->fetchColumn() === 5,
        'Legacy receiving records its whole received quantity as loose pieces');
    $assert((int)$legacy->query("SELECT COUNT(*) FROM stock_receiving WHERE received_packs <> 0")->fetchColumn() === 0,
        'Legacy receiving does not invent sealed packs');
    $assert((int)$legacy->query("SELECT pulled_loose FROM supplier_pullouts WHERE quantity = 3 ORDER BY pullout_id DESC LIMIT 1")->fetchColumn() === 3,
        'Legacy supplier pullout records its whole quantity as loose pieces');
    $assert((int)$legacy->query("SELECT pack_size_used FROM supplier_pullouts WHERE quantity = 3 ORDER BY pullout_id DESC LIMIT 1")->fetchColumn() === 1,
        'Legacy supplier pullout records an unknown pack size as 1');
    $assert((int)$legacy->query("SELECT physical_loose_quantity FROM inventory_counts WHERE count_id = {$countId}")->fetchColumn() === 8
        && (int)$legacy->query("SELECT system_loose_quantity FROM inventory_counts WHERE count_id = {$countId}")->fetchColumn() === 10
        && (int)$legacy->query("SELECT difference_loose_qty FROM inventory_counts WHERE count_id = {$countId}")->fetchColumn() === -2,
        'Legacy cycle count keeps its total as a loose-piece comparison');
    $assert((int)$legacy->query("SELECT reported_qty FROM inventory_adjustments WHERE adjustment_id = {$adjId}")->fetchColumn() === 3
        && $legacy->query("SELECT form FROM inventory_adjustments WHERE adjustment_id = {$adjId}")->fetchColumn() === 'piece',
        'Legacy Stock Issue keeps its absolute quantity as piece form');
    $assert((int)$legacy->query('SELECT reported_qty FROM stock_issue_batches ORDER BY id DESC LIMIT 1')->fetchColumn() === 2
        && $legacy->query('SELECT form FROM stock_issue_batches ORDER BY id DESC LIMIT 1')->fetchColumn() === 'piece',
        'Legacy batch-level Stock Issue keeps piece form');

    // update.sql must not silently declare the pack columns; the runner owns them.
    // (Generic names such as `form` are ignored.)
    $distinctivePackColumns = array_values(array_filter($allPackColumns, static fn(string $column): bool => $column !== 'form' && $column !== 'reported_qty'));
    $updateSql = (string)file_get_contents($updatePath);
    $declaredInUpdate = array_values(array_filter($distinctivePackColumns, static fn(string $column): bool => str_contains($updateSql, $column)));
    $assert($declaredInUpdate === [], 'update.sql contains no Sales Pack columns, so every upgrade relies on the shared migration runner');

    // The runner is the sole convergence path for both lineages.
    $assert($freshShapes === $columnShapes($legacy, $packColumns), 'Fresh and upgraded lineages converge on the same Sales Pack schema');
} finally {
    foreach ($names as $name) {
        if (!preg_match('/^rm_pack_parity_(fresh|legacy)_[a-f0-9]{8}$/', $name)) {
            throw new RuntimeException('Unsafe parity test database name');
        }
        $server->exec("DROP DATABASE IF EXISTS `{$name}`");
    }
}

if ($failures) {
    fwrite(STDERR, 'Sales pack schema parity: ' . count($failures) . " failure(s)\n");
    exit(1);
}
echo "Sales pack schema parity: passed\n";
