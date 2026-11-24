<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/OpenFoodFactsCatalog.php';

use App\Core\Database;
use App\Database\ProductSeeder;

$staged = [];
$committed = false;
try {
    $options = getopt('', ['dry-run', 'confirm-local-database:']);
    $snapshot = json_decode(file_get_contents(__DIR__ . '/../database/seeds/philippine_products.json'), true, flags: JSON_THROW_ON_ERROR);
    $products = [];
    foreach ($snapshot['products'] as $row) {
        if (!OpenFoodFactsCatalog::supports($row['barcode'])
            || !preg_match('~^https://images\.openfoodfacts\.org/images/products/[0-9/]+/front_[a-z0-9_.-]+\.jpg$~D', $row['photo_url'])) {
            throw new RuntimeException('Invalid barcode or photo URL in Philippine product snapshot.');
        }
        foreach (['product_name' => 150, 'brand' => 100, 'category_name' => 100, 'declared_size' => 100] as $field => $limit) {
            if (!is_string($row[$field]) || mb_strlen($row[$field]) > $limit || ($field !== 'declared_size' && trim($row[$field]) === '')) {
                throw new RuntimeException('Invalid snapshot field: ' . $field);
            }
        }
        $products[] = $row + [
            'sku' => 'OFF-' . $row['barcode'], 'unit_price' => '0.00', 'cost_price' => '0.00',
            'cost_known' => false, 'quantity_on_hand' => 0, 'expiration_date' => null,
            'reorder_level' => 0, 'preferred_supplier' => '', 'supplier_lead_time_days' => 7,
            'safety_stock' => 0, 'minimum_order_quantity' => 1, 'units_per_package' => 1,
            'product_image_source' => 'https://world.openfoodfacts.org/product/' . $row['barcode'],
        ];
    }
    if (count($products) !== 50 || count(array_unique(array_column($products, 'barcode'))) !== 50) {
        throw new RuntimeException('Expected exactly 50 unique Philippine products.');
    }
    if (array_key_exists('dry-run', $options)) {
        echo json_encode(['count' => count($products), 'categories' => array_values(array_unique(array_column($products, 'category_name'))), 'products' => $products], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $config = require __DIR__ . '/../config/database.php';
    ProductSeeder::assertSafeTarget((string)env('APP_ENV', ''), $config, (string)($options['confirm-local-database'] ?? ''));
    if (strtolower((string)env('DATABASE_ENV', '')) === 'hosted') {
        throw new RuntimeException('Seed refused: hosted database configuration.');
    }
    $pdo = Database::connection($config);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $config['database']) {
        throw new RuntimeException('Connected database does not match confirmed target.');
    }
    foreach (['product_image', 'product_image_source', 'declared_size', 'cost_known'] as $column) {
        if (!App\Database\Schema::columnExists($pdo, 'products', $column)) {
            throw new RuntimeException('Run the existing database migrations before seeding. Missing products.' . $column);
        }
    }
    $directory = $GLOBALS['app']['storage_path'] . '/images';
    $existing = $pdo->prepare('SELECT product_id FROM products WHERE sku = ? AND barcode = ?');
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    foreach ($products as &$product) {
        $existing->execute([$product['sku'], $product['barcode']]);
        if ($existing->fetchColumn() !== false) continue;
        fwrite(STDERR, 'Downloading photo: ' . $product['product_name'] . PHP_EOL);
        $body = OpenFoodFactsCatalog::downloadPhoto($product['photo_url'], 5 * 1024 * 1024, $openFoodFactsImageRequest ?? null);
        $mime = $body === null ? '' : (new finfo(FILEINFO_MIME_TYPE))->buffer($body);
        if (!isset($extensions[$mime]) || !@getimagesizefromstring($body ?? '')) {
            throw new RuntimeException('Photo unavailable or invalid for barcode ' . $product['barcode'] . '. No products imported; retry later.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create product photo directory.');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        $staged[$product['sku']] = $directory . '/' . $filename;
        if (file_put_contents($staged[$product['sku']], $body, LOCK_EX) !== strlen($body)) {
            throw new RuntimeException('Cannot save product photo.');
        }
        $product['product_image'] = 'storage/images/' . $filename;
    }
    unset($product);
    $result = ProductSeeder::run($pdo, $products);
    $committed = true;
    foreach ($result['records'] as $record) {
        // Another seed may have inserted the same product while photos downloaded.
        if (!$record['created'] && isset($staged[$record['sku']])) unlink($staged[$record['sku']]);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if (!$committed) foreach ($staged as $path) if (is_file($path)) unlink($path);
    $message = $exception instanceof PDOException ? 'Database operation failed; check schema compatibility and database access.' : $exception->getMessage();
    fwrite(STDERR, 'Philippine product seed failed: ' . $message . PHP_EOL);
    exit(1);
}
