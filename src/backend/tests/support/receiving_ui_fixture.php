<?php
require __DIR__ . '/theme_fixture.php';
if ($argv[1] !== 'create') exit;
$pdo->exec("UPDATE products SET units_per_package=24, receiving_unit='case', case_barcode='THEME-CASE' WHERE product_id=1");
$pdo->exec("INSERT INTO product_batches (product_id,batch_number,quantity,remaining_quantity,loose_quantity) VALUES (1,'OPENING',10,10,10)");
$insert = $pdo->prepare('INSERT INTO products (sku,barcode,product_name,unit_price,cost_price) VALUES (?,?,?,25,3)');
$pdo->beginTransaction();
for ($index = 0; $index < 1000; $index++) {
    $insert->execute(['UI-' . $index, 'UI-BARCODE-' . $index, 'Receiving test product ' . $index]);
}
$pdo->exec('INSERT INTO inventory (product_id,quantity_on_hand) SELECT product_id,0 FROM products WHERE product_id NOT IN (SELECT product_id FROM inventory)');
$pdo->commit();
