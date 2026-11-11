<?php
declare(strict_types=1);

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE products (product_id INTEGER, sku TEXT, product_name TEXT, branch_id INTEGER);
    CREATE TABLE users (user_id INTEGER, display_name TEXT);
    CREATE TABLE stock_movements (movement_id INTEGER PRIMARY KEY, product_id INTEGER, change_qty INTEGER, reason TEXT, moved_by INTEGER, moved_at TEXT)');
$pdo->exec("INSERT INTO products VALUES (1, 'RICE', 'Rice', 1), (2, 'OTHER', 'Outside scope', 2);
    INSERT INTO users VALUES (1, 'Inventory Manager')");
$insert = $pdo->prepare('INSERT INTO stock_movements (product_id, change_qty, reason, moved_by, moved_at) VALUES (?, ?, ?, 1, ?)');
foreach (['purchase', 'adjustment', 'Physical reconciliation: Verified count', 'sale', 'return', 'stock_issue', 'supplier_pullout'] as $reason) {
    $insert->execute([1, 5, $reason, '2026-11-10 10:00:00']);
}
for ($i = 0; $i < 12; $i++) $insert->execute([1, -1, 'sale', '2026-11-11 10:00:00']);
$insert->execute([2, 20, 'purchase', '2026-11-11 10:00:00']);

$source = file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/inventory_overview.php');
$start = strpos($source, '$recentStmt =');
$end = strpos($source, '$recent_movements = $recentStmt->fetchAll();', $start);
if ($start === false || $end === false) throw new RuntimeException('Recent movement query not found.');
$scopeSql = ' AND p.branch_id = ?';
$scopeParams = [1];
eval(substr($source, $start, $end - $start));
$movements = $recentStmt->fetchAll();
if (array_column($movements, 'reason') !== ['Physical reconciliation: Verified count', 'adjustment', 'purchase']) {
    throw new RuntimeException('Recent movements must retain additions and corrections before limiting, exclude sales/returns/issues, preserve Store scope, and break date ties by movement ID.');
}
echo "Inventory recent movements: passed\n";
