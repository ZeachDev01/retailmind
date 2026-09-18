<?php
// Requires a disposable/local MySQL database loaded with src/backend/sql/schema.sql.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Sales trend integration tests: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Services/DashboardService.php';

use App\Database\DailySalesTrendSeeder;
use App\Database\ProductSeeder;
use App\Store\StoreScope;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$timezone = new DateTimeZone((string)env('APP_TIMEZONE', 'Asia/Manila'));
$asOf = (new DateTimeImmutable('today', $timezone))->setTime(0, 0);
$pdo->prepare('SET time_zone = ?')->execute([$asOf->format('P')]);

try {
    $products = ProductSeeder::loadProducts(dirname(__DIR__, 3) . '/product_seed.csv');
    $firstProducts = ProductSeeder::run($pdo, $products, true);
    $secondProducts = ProductSeeder::run($pdo, $products, true);
    $storeId = (new StoreScope($pdo))->id();
    $seedProductStatement = $pdo->prepare('SELECT COUNT(*) FROM products WHERE branch_id = ?');
    $seedProductStatement->execute([$storeId]);
    $seedProductCount = (int)$seedProductStatement->fetchColumn();
    $assert(
        (int)$firstProducts['inserted'] + (int)$firstProducts['skipped'] === count($products),
        'Product dependency seed did not account for every CSV product'
    );
    $assert((int)$secondProducts['inserted'] === 0, 'Repeat product dependency seed inserted duplicate products');
    $assert((int)$secondProducts['skipped'] === count($products), 'Repeat product dependency seed did not recognize every product');
    $assert($seedProductCount === count($products), 'Product dependency produced a duplicate or missing Store product');

    $seeder = new DailySalesTrendSeeder($pdo, $asOf);
    $first = $seeder->run();
    $firstOwned = $seeder->ownedSummary();
    $stockStatement = $pdo->prepare(
        "SELECT SUM(i.quantity_on_hand) AS on_hand, SUM(p.quantity_purchased) AS purchased, SUM(p.quantity_sold) AS sold
         FROM products p JOIN inventory i ON i.product_id = p.product_id
         WHERE p.branch_id = ?"
    );
    $stockStatement->execute([$storeId]);
    $firstStock = $stockStatement->fetch(PDO::FETCH_ASSOC);
    $second = $seeder->run();
    $secondOwned = $seeder->ownedSummary();
    $stockStatement->execute([$storeId]);
    $secondStock = $stockStatement->fetch(PDO::FETCH_ASSOC);

    foreach (['sales', 'sale_items', 'units_sold', 'gross_total', 'start_date', 'end_date', 'sales_days'] as $key) {
        $assert(
            (string)($firstOwned[$key] ?? '') === (string)($secondOwned[$key] ?? ''),
            "Seeder is not repeatable for owned summary field {$key}"
        );
    }
    $assert((int)$secondOwned['sales'] === (int)$second['sales'], 'Owned sale count does not match the second seed plan');
    $assert((int)$secondOwned['sale_items'] === (int)$second['sale_items'], 'Owned line-item count does not match the seed plan');
    $assert((int)$secondOwned['units_sold'] === (int)$second['units_sold'], 'Owned unit count does not match the seed plan');
    $assert($firstStock === $secondStock, 'Repeat sales seed changed the resulting inventory or product counters');
    $seedUsers = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username IN (?, ?) AND branch_id = ?');
    $seedUsers->execute([DailySalesTrendSeeder::USERNAME, DailySalesTrendSeeder::RECEIVER_USERNAME, $storeId]);
    $assert((int)$seedUsers->fetchColumn() === 2, 'Sales seed users are not attached to the singleton Store identity');

    $forecastGrain = $pdo->prepare(
        "SELECT si.product_id, DATE(s.sale_date) AS sale_day, SUM(si.quantity) AS qty_sold
         FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
         JOIN products p ON p.product_id = si.product_id
         WHERE p.branch_id = ?
         GROUP BY si.product_id, DATE(s.sale_date)"
    );
    $forecastGrain->execute([$storeId]);
    $grainRows = $forecastGrain->fetchAll(PDO::FETCH_ASSOC);
    $grainKeys = array_map(static fn(array $row): string => $row['product_id'] . '|' . $row['sale_day'], $grainRows);
    $assert(count($grainKeys) === count(array_unique($grainKeys)), 'Forecast source grain must remain Product + Day for the Store');

    $service = new DashboardService($pdo);
    foreach ([7, 30, 90] as $days) {
        $trend = $service->getSalesTrend($days, $asOf);
        $expectedStart = $asOf->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $assert(count($trend) === $days, "{$days}-day trend did not return exactly {$days} calendar rows");
        $assert(($trend[0]['sale_day'] ?? '') === $expectedStart, "{$days}-day trend start boundary is incorrect");
        $assert(($trend[$days - 1]['sale_day'] ?? '') === $asOf->format('Y-m-d'), "{$days}-day trend end boundary is incorrect");

        $seedStart = $asOf->modify('-' . ($days - 1) . ' days')->format('Y-m-d 00:00:00');
        $seedEnd = $asOf->modify('+1 day')->format('Y-m-d 00:00:00');
        $stmt = $pdo->prepare(
            "SELECT DATE(s.sale_date) AS sale_day, SUM(s.total_amount) AS total_sales, COUNT(*) AS transactions
             FROM sales s
             WHERE s.sale_date >= ? AND s.sale_date < ?
             GROUP BY DATE(s.sale_date)"
        );
        $stmt->execute([$seedStart, $seedEnd]);
        $expected = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $expected[$row['sale_day']] = $row;
        }

        $emptyDays = 0;
        foreach ($trend as $row) {
            $day = $row['sale_day'];
            if (!isset($expected[$day])) {
                $emptyDays++;
                $assert((float)$row['total_sales'] === 0.0, "Empty date {$day} did not have a zero total");
                $assert((int)$row['transactions'] === 0, "Empty date {$day} did not have zero transactions");
                continue;
            }
            $assert(
                abs((float)$row['total_sales'] - (float)$expected[$day]['total_sales']) < 0.001,
                "Daily sales total is incorrect for {$day} in the {$days}-day range"
            );
            $assert(
                (int)$row['transactions'] === (int)$expected[$day]['transactions'],
                "Transaction count is incorrect for {$day} in the {$days}-day range"
            );
        }
        $assert($emptyDays > 0, "{$days}-day trend did not exercise any zero-sales date");
    }

    $mismatchStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM sales s
         JOIN users u ON u.user_id = s.cashier_id
         JOIN (SELECT sale_id, SUM(subtotal) AS item_total FROM sale_items GROUP BY sale_id) totals ON totals.sale_id = s.sale_id
         WHERE u.username = ? AND ABS(s.total_amount - totals.item_total) > 0.001"
    );
    $mismatchStmt->execute([DailySalesTrendSeeder::USERNAME]);
    $assert((int)$mismatchStmt->fetchColumn() === 0, 'One or more sale totals do not equal their line-item subtotals');

    $stockMismatch = $pdo->prepare(
        "SELECT COUNT(*) FROM product_batches pb
         JOIN products p ON p.product_id = pb.product_id
         WHERE pb.batch_number LIKE ?
           AND pb.quantity - pb.remaining_quantity <> (
               SELECT COALESCE(SUM(sib.quantity), 0) FROM sale_item_batches sib WHERE sib.batch_id = pb.batch_id
           )"
    );
    $stockMismatch->execute([DailySalesTrendSeeder::SEED_KEY . '-%']);
    $assert((int)$stockMismatch->fetchColumn() === 0, 'Seed batch depletion does not match sale-item allocations');
} catch (Throwable $exception) {
    $failures[] = 'Sales trend integration setup failed: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Sales trend integration tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Sales trend integration tests: passed (determinism, 7/30/90-day boundaries, empty days, totals, stock)\n";
