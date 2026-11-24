<?php
// Public seam: the CLI preview must work without a database or network.
$command = [PHP_BINARY, __DIR__ . '/../scripts/seed_philippine_products.php', '--dry-run'];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
if (proc_close($process) !== 0) throw new RuntimeException('Seed preview failed: ' . $errors . $output);
$preview = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
if ($preview['count'] !== 50 || count($preview['categories']) !== 14) throw new RuntimeException('Expected 50 products across 14 categories.');
$products = $preview['products'];
if (count(array_unique(array_column($products, 'barcode'))) !== 50) throw new RuntimeException('Expected unique real barcodes.');
if ($products[0]['barcode'] !== '0750515018402' || $products[0]['brand'] !== 'M. Y San') throw new RuntimeException('Expected API-sourced Sky Flakes.');
foreach ($products as $product) {
    if ($product['quantity_on_hand'] !== 0 || $product['expiration_date'] !== null
        || !str_starts_with($product['photo_url'], 'https://images.openfoodfacts.org/images/products/')
        || $product['unit_price'] !== '0.00' || $product['cost_known'] !== false) {
        throw new RuntimeException('Expected a photo, zero stock, no expiry and no invented prices.');
    }
}
echo "Philippine seed CLI preview: passed (50 products, 14 categories).\n";
