<?php
require __DIR__ . '/receiving_ui_fixture.php';
if ($argv[1] !== 'create') exit;
$pdo->exec('UPDATE products SET cost_price = 0, cost_known = 0 WHERE product_id = 1');
$pdo->exec('UPDATE products SET cost_price = 0, cost_known = 1 WHERE product_id = 2');
$pdo->exec('UPDATE products SET cost_price = 5 WHERE product_id = 3');
