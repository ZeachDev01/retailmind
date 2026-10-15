<?php
// Only uniquely named disposable databases are changed.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Procurement schema integration: skipped (set RUN_DB_TESTS=1)\n";
    exit;
}
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/operational_schema.php';
restore_exception_handler();

use App\Database\MigrationRunner;
use App\Database\Schema;

$config = require __DIR__ . '/../config/database.php';
$dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$server = new PDO($dsn, $config['username'], $config['password'], $options);
$name = 'rm_procurement_' . bin2hex(random_bytes(6));
$retired = ['suppliers', 'supplier_products', 'replenishment_requests', 'purchase_orders', 'purchase_order_items'];
$links = ['stock_receiving' => ['replenishment_request_id', 'purchase_order_id', 'purchase_order_item_id', 'po_number'],
    'forecast_decisions' => ['replenishment_request_id'],
    'notification_preferences' => ['notify_replenishment']];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$import = static function (PDO $db, string $file): void {
    $sql = preg_replace('/\/\*!\d{5}\s*(.*?)\*\//s', '$1', file_get_contents($file));
    foreach (split_sql_statements($sql) as $statement) $db->query($statement)->closeCursor();
};
$assertRetired = static function (PDO $db) use ($retired, $links, $assert): void {
    foreach ($retired as $table) $assert(!Schema::tableExists($db, $table), "Retired table reappeared: {$table}");
    foreach ($links as $table => $columns) {
        foreach ($columns as $column) $assert(!Schema::columnExists($db, $table, $column), "Retired link reappeared: {$table}.{$column}");
    }
};
$structures = static function (PDO $db): array {
    $result = [];
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $ddl = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
        $result[$table] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
    }
    return $result;
};
// Snapshot every retained column, including receipts, refunds, stock, costs and Protected Audit Records.
$records = static function (PDO $db) use ($retired, $links): array {
    $result = [];
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (in_array($table, [...$retired, 'schema_migrations'], true)) continue;
        $columns = array_column($db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(), 'Field');
        $columns = array_diff($columns, $links[$table] ?? []);
        $projection = '`' . implode('`,`', $columns) . '`';
        $rows = array_map('json_encode', $db->query("SELECT {$projection} FROM `{$table}`")->fetchAll());
        sort($rows);
        $result[$table] = $rows;
    }
    return $result;
};
try {
    $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new PDO($dsn . ';dbname=' . $name, $config['username'], $config['password'], $options);
    $import($db, __DIR__ . '/../database/sql/schema.sql');
    $assertRetired($db);
    $runner = new MigrationRunner($db, __DIR__ . '/../database/migrations');
    $runner->runPending();
    $assertRetired($db);
    $fresh = $structures($db);

    // Representative development links with real FKs, including a nonstandard constraint name.
    foreach ($retired as $table) $db->exec("CREATE TABLE `{$table}` (id INT PRIMARY KEY) ENGINE=InnoDB; INSERT INTO `{$table}` VALUES (1)");
    foreach ($links as $table => $columns) {
        foreach ($columns as $column) $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` " . ($column === 'po_number' ? 'VARCHAR(100)' : 'INT') . ' NULL');
    }
    $db->exec('ALTER TABLE stock_receiving ADD CONSTRAINT old_development_request FOREIGN KEY (replenishment_request_id) REFERENCES replenishment_requests(id)');
    $db->exec('UPDATE stock_receiving SET replenishment_request_id=1, purchase_order_id=1, purchase_order_item_id=1, po_number=\'RETIRED-PO\'');
    $before = $records($db);
    $migration = require __DIR__ . '/../database/migrations/202610150003_retire_procurement_storage.php';
    $migration['up']($db);
    $assertRetired($db);
    $assert($before === $records($db), 'Upgrade changed retained data');
    $assert($fresh === $structures($db), 'Fresh and upgraded schemas differ');
    $migration['up']($db);
    // Repeated explicit setup and startup cannot restore procurement structures.
    foreach ($runner->available() as $item) ($item['up'])($db);
    ensure_operational_updates_schema($db);
    $runner->runPending();
    $assertRetired($db);
    $assert($before === $records($db), 'Repeated setup changed retained data');
    // Exercise the phpMyAdmin upgrade against the same development links.
    foreach ($retired as $table) $db->exec("CREATE TABLE `{$table}` (id INT PRIMARY KEY) ENGINE=InnoDB; INSERT INTO `{$table}` VALUES (1)");
    foreach ($links as $table => $columns) {
        foreach ($columns as $column) $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` " . ($column === 'po_number' ? 'VARCHAR(100)' : 'INT') . ' NULL');
    }
    $db->exec('ALTER TABLE stock_receiving ADD CONSTRAINT sql_development_request FOREIGN KEY (replenishment_request_id) REFERENCES replenishment_requests(id)');
    $db->exec('UPDATE stock_receiving SET replenishment_request_id=1, purchase_order_id=1, purchase_order_item_id=1');
    $beforeSql = $records($db);
    $import($db, __DIR__ . '/../database/sql/update.sql');
    $assertRetired($db);
    $assert($beforeSql === $records($db), 'SQL upgrade changed retained data');
    $afterSql = $structures($db);
    $import($db, __DIR__ . '/../database/sql/update.sql');
    $assertRetired($db);
    $assert($afterSql === $structures($db), 'Repeated SQL upgrade changed schema');
    $assert($beforeSql === $records($db), 'Repeated SQL upgrade changed retained data');
    echo "Procurement schema integration: passed (fresh install, upgrade, retained data, repeated setup)\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$name}`");
}
