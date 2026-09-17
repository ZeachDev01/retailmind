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
    $dependencyCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM products WHERE sku IN (
            'SKU-BEV-001','SKU-BEV-002','SKU-BEV-004','SKU-BEV-006',
            'SKU-BEV-007','SKU-BEV-008','SKU-SNK-001','SKU-SNK-003',
            'SKU-SNK-004','SKU-DRY-001','SKU-DRY-004','SKU-DRY-008'
        )"
    )->fetchColumn();
    if ($dependencyCount < 8) {
        $adminId = (int)$pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO branches (branch_name, branch_code, status, created_by)
             VALUES ('Sales Trend Integration Branch', 'RM-TEST-SALES', 'active', ?)
             ON DUPLICATE KEY UPDATE branch_id = LAST_INSERT_ID(branch_id), status = 'active'"
        )->execute([$adminId]);
        $branchId = (int)$pdo->lastInsertId();

        $categoryStmt = $pdo->prepare(
            "INSERT INTO categories (category_name)
             SELECT ? WHERE NOT EXISTS (SELECT 1 FROM categories WHERE category_name = ?)
             ON DUPLICATE KEY UPDATE category_name = VALUES(category_name)"
        );
        $categoryStmt->execute(['Sales Trend Integration', 'Sales Trend Integration']);
        $categoryStmt = $pdo->prepare('SELECT category_id FROM categories WHERE category_name = ? ORDER BY category_id LIMIT 1');
        $categoryStmt->execute(['Sales Trend Integration']);
        $categoryId = (int)$categoryStmt->fetchColumn();

        $csv = fopen(dirname(__DIR__, 3) . '/product_seed.csv', 'r');
        if ($csv === false) {
            throw new RuntimeException('product_seed.csv could not be opened for integration setup.');
        }
        fgetcsv($csv);
        $wantedSkus = [
            'SKU-BEV-001', 'SKU-BEV-002', 'SKU-BEV-004', 'SKU-BEV-006',
            'SKU-BEV-007', 'SKU-BEV-008', 'SKU-SNK-001', 'SKU-SNK-003',
            'SKU-SNK-004', 'SKU-DRY-001', 'SKU-DRY-004', 'SKU-DRY-008',
        ];
        $insertProduct = $pdo->prepare(
            "INSERT INTO products
                (sku, barcode, product_name, brand, category_id, unit_price, cost_price,
                 reorder_level, status, created_by, branch_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)"
        );
        while (($row = fgetcsv($csv)) !== false) {
            if (!in_array((string)($row[0] ?? ''), $wantedSkus, true)) {
                continue;
            }
            $insertProduct->execute([
                $row[0], 'RMTEST-' . $row[1], $row[2], $row[3], $categoryId,
                $row[5], $row[6], $row[7], $adminId, $branchId,
            ]);
            $pdo->prepare('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (?, 0)')
                ->execute([(int)$pdo->lastInsertId()]);
        }
        fclose($csv);
    }

    $seeder = new DailySalesTrendSeeder($pdo, $asOf);
    $first = $seeder->run();
    $firstOwned = $seeder->ownedSummary();
    $second = $seeder->run();
    $secondOwned = $seeder->ownedSummary();

    foreach (['sales', 'sale_items', 'units_sold', 'gross_total', 'start_date', 'end_date', 'sales_days'] as $key) {
        $assert(
            (string)($firstOwned[$key] ?? '') === (string)($secondOwned[$key] ?? ''),
            "Seeder is not repeatable for owned summary field {$key}"
        );
    }
    $assert((int)$secondOwned['sales'] === (int)$second['sales'], 'Owned sale count does not match the second seed plan');
    $assert((int)$secondOwned['sale_items'] === (int)$second['sale_items'], 'Owned line-item count does not match the seed plan');
    $assert((int)$secondOwned['units_sold'] === (int)$second['units_sold'], 'Owned unit count does not match the seed plan');

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
