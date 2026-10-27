<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/products.php');
$start = strpos($source, '<td class="column-profit">');
$end = strpos($source, '</td>', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Product catalog profit cell missing');
}

$product = ['cost_price' => 30, 'unit_price' => 45];
ob_start();
eval('?>' . substr($source, $start, $end + 5 - $start));
$cell = trim((string) ob_get_clean());
if ($cell !== '<td class="column-profit">₱15.00</td>') {
    throw new RuntimeException("Expected ₱15.00 profit for ₱45.00 selling price and ₱30.00 cost; got {$cell}");
}

echo "Product catalog profit: passed\n";
