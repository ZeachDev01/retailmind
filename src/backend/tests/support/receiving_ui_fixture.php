<?php
require __DIR__ . '/theme_fixture.php';
if ($argv[1] !== 'create') exit;
$pdo->exec("UPDATE products SET units_per_package=24, receiving_unit='case' WHERE product_id=1");
$insert = $pdo->prepare('INSERT INTO products (sku,barcode,product_name,unit_price,cost_price,branch_id) VALUES (?,?,?,25,3,1)');
$pdo->beginTransaction();
for ($index = 0; $index < 1000; $index++) {
    $insert->execute(['UI-' . $index, 'UI-BARCODE-' . $index, 'Receiving test product ' . $index]);
}
$pdo->commit();
