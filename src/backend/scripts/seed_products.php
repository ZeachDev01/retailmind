<?php
// CLI only; never connect until the target passes the safety checks.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Database\ProductSeeder;

try {
    $options = getopt('', ['confirm-local-database:', 'with-stock']);
    $config = require __DIR__ . '/../config/database.php';
    ProductSeeder::assertSafeTarget((string)env('APP_ENV', ''), $config, (string)($options['confirm-local-database'] ?? ''));
    if (strtolower((string)env('DATABASE_ENV', '')) === 'hosted') {
        throw new RuntimeException('Seed refused: hosted database configuration.');
    }
    $products = ProductSeeder::loadProducts(dirname(__DIR__, 3) . '/product_seed.csv');
    printf("Target: APP_ENV=%s MySQL %s:%s/%s\n", env('APP_ENV'), $config['host'], $config['port'], $config['database']);
    $pdo = Database::connection($config);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $config['database']) {
        throw new RuntimeException('Connected database does not match confirmed target.');
    }
    $result = ProductSeeder::run($pdo, $products, array_key_exists('with-stock', $options));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    // PDO messages can contain credentials or connection details; do not print them.
    $message = $exception instanceof PDOException ? 'Database operation failed; no successful seed result. Check schema compatibility and database access.' : $exception->getMessage();
    fwrite(STDERR, 'Product seed failed: ' . $message . PHP_EOL);
    exit(1);
}