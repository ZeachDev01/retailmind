<?php

function app_url(string $path = ''): string { return '/shop/' . ltrim($path, '/'); }
function app_base_url(): string { return '/shop'; }
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$helper = __DIR__ . '/../includes/product_images.php';
if (is_file($helper)) require_once $helper;
$source = file_get_contents(__DIR__ . '/../../frontend/components/cashier/pos.php');
$start = strpos($source, '$quickProducts = array_map(');
$end = strpos($source, '}, array_slice($quickProductRows, 0, 120));', $start);
$quickProductRows = [[
    'product_id' => 7, 'product_name' => 'Milk', 'unit_price' => 20,
    'quantity_on_hand' => 5, 'reorder_level' => 1, 'safety_stock' => 1,
    'quantity_sold' => 0, 'category_name' => 'Dairy',
    'pack_size' => null, 'pack_price' => null, 'sealed_packs' => 0,
    'product_image' => 'storage/images/milk.png',
]];
$row = $quickProductRows[0];
$mappingStart = strpos($source, 'return $products = array_map(');
$mappingEnd = strpos($source, '}, $catalogStmt->fetchAll(PDO::FETCH_ASSOC));', $mappingStart);
$mapping = substr($source, $mappingStart, $mappingEnd - $mappingStart) . '}, [$row]);';
$quickProductRows = eval($mapping);
eval(substr($source, $start, $end + strlen('}, array_slice($quickProductRows, 0, 120));') - $start));
check(str_starts_with($quickProducts[0]['product_image'], '/shop/components/inventory_management/product_image.php?'), 'POS photo still points at blocked storage instead of the image endpoint.');

$directory = sys_get_temp_dir() . '/retailmind-product-images-' . bin2hex(random_bytes(5));
mkdir($directory . '/images', 0700, true);
$GLOBALS['app']['storage_path'] = $directory;
try {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    file_put_contents($directory . '/images/milk.png', $png);
    file_put_contents($directory . '/images/fake.png', '<?php echo "secret";');
    $image = product_image_file('storage/images/milk.png');
    check($image !== null && $image['mime'] === 'image/png', 'Stored product PNG must resolve.');
    check(file_get_contents($image['path']) === $png, 'Image bytes must remain unchanged.');
    foreach (['', 'storage/images/../secret.png', 'storage/images/nested/milk.png', 'storage/images/fake.png', 'storage/images/missing.png', 'https://example.com/a.png'] as $invalid) {
        check(product_image_file($invalid) === null, 'Unsafe, missing, or non-image files must be rejected: ' . $invalid);
    }
    check(product_image_url('') === '', 'Missing photos must use the UI placeholder.');
    check(product_image_url('../secret.png') === '', 'Unsafe references must not produce URLs.');
} finally {
    unlink($directory . '/images/milk.png');
    unlink($directory . '/images/fake.png');
    rmdir($directory . '/images');
    rmdir($directory);
}
echo "Product image delivery: passed\n";
