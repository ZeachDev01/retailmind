<?php
// Run after an authorized operational reset; this script never deletes existing data.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/PhilippineTime.php';

use App\Core\Database;
use App\Database\DailySalesTrendSeeder;
use App\Database\ProductSeeder;
use App\Database\Schema;
use App\Services\GrossProfitService;
use App\Store\StoreScope;

try {
    $options = getopt('', ['confirm-local-database:']);
    $config = require __DIR__ . '/../config/database.php';
    ProductSeeder::assertSafeTarget((string)env('APP_ENV', ''), $config, (string)($options['confirm-local-database'] ?? ''));
    if (env('APP_ENV') !== 'development' || strtolower((string)env('DATABASE_ENV', '')) === 'hosted') {
        throw new RuntimeException('Demo seed requires a local development database.');
    }
    $products = ProductSeeder::loadProducts(__DIR__ . '/../database/seeds/product_seed.csv');
    foreach ($products as $product) {
        if ((float)$product['cost_price'] <= 0 || (float)$product['cost_price'] >= (float)$product['unit_price']) {
            throw new RuntimeException('Demo products must have positive costs below selling prices.');
        }
    }
    $pdo = Database::connection($config);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $config['database']) {
        throw new RuntimeException('Connected database differs from the confirmed target.');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() !== 0
        || (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() !== 0) {
        throw new RuntimeException('Reset demo products and operational history first. Existing data was preserved.');
    }
    $reset = new App\Services\OperationalDataResetService($pdo, new App\Authorization\RoleCapabilityPolicy());
    if ($reset->blockers()) {
        throw new RuntimeException(implode(' ', $reset->blockers()));
    }
    foreach (['unit_cost', 'cost_price', 'cost_status', 'cost_source', 'cost_provenance'] as $column) {
        if (!Schema::columnExists($pdo, 'sale_item_batches', $column)) {
            throw new RuntimeException('Apply the sold-stock cost snapshot migration before seeding.');
        }
    }
    ProductSeeder::run($pdo, $products);
    $storeId = (new StoreScope($pdo))->id();
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $seed = (new DailySalesTrendSeeder($pdo, $now))->run();

    $pdo->beginTransaction();
    try {
        // Today's demo transactions must be visible even before simulated shop hours.
        $pdo->prepare('UPDATE sales s JOIN users u ON u.user_id = s.cashier_id
            SET s.sale_date = ? WHERE u.username = ? AND DATE(s.sale_date) = ? AND s.sale_date > ?')
            ->execute([$now->format('Y-m-d H:i:s'), DailySalesTrendSeeder::USERNAME, $now->format('Y-m-d'), $now->format('Y-m-d H:i:s')]);
        $pdo->prepare('UPDATE cashier_shifts cs JOIN users u ON u.user_id = cs.cashier_id
            SET cs.opened_at = LEAST(cs.opened_at, ?), cs.closed_at = LEAST(cs.closed_at, ?)
            WHERE u.username = ? AND DATE(cs.opened_at) = ?')
            ->execute([$now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), DailySalesTrendSeeder::USERNAME, $now->format('Y-m-d')]);
        $pdo->prepare('UPDATE stock_movements sm JOIN users u ON u.user_id = sm.moved_by
            SET sm.moved_at = ? WHERE u.username = ? AND sm.moved_at > ?')
            ->execute([$now->format('Y-m-d H:i:s'), DailySalesTrendSeeder::USERNAME, $now->format('Y-m-d H:i:s')]);

        $profit = new GrossProfitService($pdo);
        $profit->establishHistoricalCosts(null, ['store_id' => $storeId, 'fallback_to_product_cost' => false]);
        $summaries = [];
        foreach (['today', 'week', 'month'] as $period) {
            $summary = $profit->calculate(['period' => $period, 'store_id' => $storeId])['summary'];
            if ($summary['status'] !== GrossProfitService::STATUS_RECORDED
                || $summary['total_net_sales'] <= 0 || $summary['total_gross_profit'] <= 0
                || abs($summary['total_net_sales'] - $summary['total_net_cost'] - $summary['total_gross_profit']) > 0.01) {
                throw new RuntimeException('Demo profit verification failed for ' . $period . '.');
            }
            $summaries[$period] = $summary;
        }
        $invalidCosts = (int)$pdo->query("SELECT COUNT(*) FROM sale_item_batches
            WHERE unit_cost IS NULL OR unit_cost <= 0 OR cost_status <> 'recorded'")->fetchColumn();
        if ($invalidCosts !== 0) {
            throw new RuntimeException('Demo has missing or estimated sale cost snapshots.');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    echo json_encode(['products' => count($products), 'seed' => $seed, 'verified_profit' => $summaries], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    error_log((string)$exception);
    $message = $exception instanceof PDOException ? 'Database operation failed; inspect the application log.' : $exception->getMessage();
    fwrite(STDERR, 'Gross profit demo seed failed: ' . $message . PHP_EOL);
    exit(1);
}
