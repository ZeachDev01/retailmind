<?php
// Pure checks: deliberately do not load .env or connect to a database.
require_once __DIR__ . '/../app/Database/ProductSeeder.php';

use App\Database\ProductSeeder;

$config = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'retailmind_dev', 'charset' => 'utf8mb4'];
$checks = 0;
$reject = static function (callable $operation) use (&$checks): void {
    try {
        $operation();
    } catch (RuntimeException $exception) {
        $checks++;
        return;
    }
    throw new RuntimeException('Expected unsafe target or invalid fixture to be rejected.');
};
foreach (['production', 'staging', '', 'infinityfree'] as $environment) {
    $reject(static fn () => ProductSeeder::assertSafeTarget($environment, $config, 'retailmind_dev'));
}
foreach (['remote.example.com', '192.168.1.10', 'localhost;dbname=production'] as $host) {
    $reject(static fn () => ProductSeeder::assertSafeTarget('development', array_replace($config, ['host' => $host]), 'retailmind_dev'));
}
$reject(static fn () => ProductSeeder::assertSafeTarget('development', $config, ''));
$reject(static fn () => ProductSeeder::assertSafeTarget('development', $config, 'other_database'));
$reject(static fn () => ProductSeeder::assertSafeTarget('development', array_replace($config, ['driver' => 'sqlite']), 'retailmind_dev'));
foreach (['local', 'development'] as $environment) {
    ProductSeeder::assertSafeTarget($environment, $config, 'retailmind_dev');
    $checks++;
}
$products = ProductSeeder::loadProducts(dirname(__DIR__, 3) . '/product_seed.csv');
if (count($products) !== 60 || count(array_unique(array_column($products, 'category_name'))) !== 11) {
    throw new RuntimeException('Expected 60 representative products in 11 categories.');
}
$checks++;
$fixture = tempnam(sys_get_temp_dir(), 'rm_seed_test_');
if ($fixture === false) {
    throw new RuntimeException('Could not create temporary fixture.');
}
try {
    file_put_contents($fixture, "invalid,header\n");
    $reject(static fn () => ProductSeeder::loadProducts($fixture));
    $source = file(dirname(__DIR__, 3) . '/product_seed.csv');
    file_put_contents($fixture, $source[0] . $source[1] . $source[1]);
    $reject(static fn () => ProductSeeder::loadProducts($fixture));
    file_put_contents($fixture, $source[0] . str_replace(',0.75,', ',-1,', $source[1]));
    $reject(static fn () => ProductSeeder::loadProducts($fixture));
} finally {
    unlink($fixture);
}
$distribution = ['out' => 0, 'low' => 0, 'available' => 0];
foreach ($products as $product) {
    $quantity = ProductSeeder::openingStock($product);
    if ($quantity !== ProductSeeder::openingStock($product) || $quantity < 0) {
        throw new RuntimeException('Opening stock must be deterministic and nonnegative.');
    }
    $group = $quantity === 0 ? 'out' : ($quantity <= (int)$product['reorder_level'] ? 'low' : 'available');
    $distribution[$group]++;
}
if ($distribution !== ['out' => 12, 'low' => 12, 'available' => 36]) {
    throw new RuntimeException('Expected a varied opening stock distribution.');
}
$checks++;
echo "Product seed checks passed: {$checks}\n";