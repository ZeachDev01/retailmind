<?php
require __DIR__ . '/theme_fixture.php';
if ($argv[1] !== 'create') exit;
$fixtureToday = App\Services\PhilippineTime::today();
$pdo->prepare("INSERT INTO fiscal_periods (period_name,start_date,end_date,status,created_by) VALUES ('Cashier UI Test Period',?,?,'open',1)")->execute([substr($fixtureToday, 0, 4) . '-01-01', substr($fixtureToday, 0, 4) . '-12-31']);
$pdo->exec("INSERT INTO categories (category_id,category_name) VALUES (1,'Groceries'),(2,'Household and personal care essentials')");
$pdo->exec('UPDATE products SET category_id=1 WHERE product_id=1');
$product = $pdo->prepare('INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,category_id,branch_id) VALUES (?,?,?,?,25,3,2,1)');
for ($id = 100; $id < 112; $id++) {
    $product->execute([$id, 'CASHIER-' . $id, 'CASHIER-' . $id, 'Household essentials with a longer product name ' . $id]);
    $pdo->exec("INSERT INTO inventory (product_id,quantity_on_hand) VALUES ({$id}," . ($id === 100 ? 0 : 10) . ')');
}
