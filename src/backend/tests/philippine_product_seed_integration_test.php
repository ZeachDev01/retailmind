<?php
// Public seams: CLI import, then ProductService's existing catalog/search.
if (getenv('RUN_DB_TESTS') !== '1') { echo "Philippine seed integration: skipped (RUN_DB_TESTS=1 required).\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../includes/product_images.php';
function app_url(string $path = ''): string { return '/' . ltrim($path, '/'); }

$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', getenv('DB_TEST_USER') ?: 'root', getenv('DB_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database = 'rm_ph_seed_test_' . bin2hex(random_bytes(6));
$storage = sys_get_temp_dir() . '/rm_ph_seed_' . bin2hex(random_bytes(6));
mkdir($storage);
$GLOBALS['app']['storage_path'] = $storage;
$check = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$run = static function (array $overrides = []) use ($database, $storage): array {
    $environment = array_merge(getenv(), ['APP_ENV' => 'local', 'DATABASE_ENV' => 'local', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_NAME' => $database, 'DB_USER' => getenv('DB_TEST_USER') ?: 'root', 'DB_PASSWORD' => getenv('DB_TEST_PASSWORD') ?: '', 'RM_SEED_TEST_STORAGE' => $storage], $overrides);
    $process = proc_open([PHP_BINARY, __DIR__ . '/support/philippine_seed_cli.php', '--confirm-local-database=' . $database], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $schema = file_get_contents(__DIR__ . '/../database/sql/schema.sql');
    foreach (['categories', 'products', 'inventory', 'product_batches'] as $table) {
        preg_match('/CREATE TABLE `' . $table . '` \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $match);
        $pdo->exec($match[0]);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $migration = require __DIR__ . '/../database/migrations/202611170001_known_product_costs.php';
    $migration['up']($pdo);
    $service = new ProductService($pdo);
    [$status, $out, $err] = $run(['APP_ENV' => 'production']);
    $check($status !== 0 && str_contains($err, 'Seed refused'), 'Unsafe database environment was accepted.');
    [$status, $out, $err] = $run(['RM_SEED_TEST_PHOTO' => 'invalid']);
    $check($status !== 0 && str_contains($err, 'Photo unavailable or invalid'), 'Invalid photo was accepted.');
    $check($service->getProductsForManagement() === [] && count(glob($storage . '/images/*')) === 0, 'Photo failure left partial products or files.');
    [$status, $out, $err] = $run();
    $check($status === 0, 'Import failed: ' . $err . $out);
    $result = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    $check($result['inserted'] === 50, 'Expected 50 inserted products.');
    $catalog = $service->getProductsForManagement();
    $check(count($catalog) === 50, 'Expected 50 catalog products.');
    foreach ($catalog as $product) {
        $check((int)$product['quantity_on_hand'] === 0 && $product['expiration_date'] === null && $product['next_expiration_date'] === null, 'Seed invented stock or expiry.');
        $check((int)$product['quantity_purchased'] === 0 && (int)$product['cost_known'] === 0, 'Seed invented purchasing or cost.');
        $check(product_image_file($product['product_image']) !== null, 'Catalog photo is not deliverable.');
        $check($product['product_image_source'] === 'https://world.openfoodfacts.org/product/' . $product['barcode'], 'Missing photo attribution.');
    }
    $before = $service->findProductBySearchTerm('0750515018402');
    // Existing Store data is fixture setup; observe preservation through the catalog.
    $pdo->exec("UPDATE products SET unit_price = 42, status = 'inactive' WHERE barcode = '0750515018402'");
    $pdo->exec('UPDATE inventory SET quantity_on_hand = 7 WHERE product_id = ' . (int)$before['product_id']);
    $before = $service->findProductBySearchTerm('0750515018402');
    [$status, $out, $err] = $run();
    $check($status === 0, 'Repeat import failed: ' . $err . $out);
    $result = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    $check($result['inserted'] === 0 && $result['skipped'] === 50 && count($service->getProductsForManagement()) === 50, 'Repeat import duplicated products.');
    $check($before === $service->findProductBySearchTerm('0750515018402'), 'Repeat import changed existing product.');
    $check(count(glob($storage . '/images/*')) === 50, 'Repeat import leaked photos.');
    echo "Philippine seed integration: passed (catalog, local photos, no stock/expiry, repeat import).\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    foreach (glob($storage . '/images/*') ?: [] as $file) unlink($file);
    if (is_dir($storage . '/images')) rmdir($storage . '/images');
    rmdir($storage);
}
