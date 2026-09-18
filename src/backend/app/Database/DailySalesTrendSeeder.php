<?php

namespace App\Database;

use App\Store\StoreScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class DailySalesTrendSeeder
{
    public const SEED_KEY = 'RM_SEED_SALES_TREND_V1';
    public const USERNAME = 'rm_seed_sales_trend';
    public const RECEIVER_USERNAME = 'rm_seed_sales_receiver';
    public const HISTORY_DAYS = 91;

    private const PRODUCT_SKUS = [
        'SKU-BEV-001', 'SKU-BEV-002', 'SKU-BEV-004', 'SKU-BEV-006',
        'SKU-BEV-007', 'SKU-BEV-008', 'SKU-SNK-001', 'SKU-SNK-003',
        'SKU-SNK-004', 'SKU-DRY-001', 'SKU-DRY-004', 'SKU-DRY-008',
    ];

    public function __construct(private PDO $pdo, private ?DateTimeImmutable $asOf = null)
    {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $this->asOf = ($this->asOf ?? new DateTimeImmutable('today', $timezone))->setTime(0, 0);
    }

    public function run(): array
    {
        $this->assertSafeEnvironment();
        $this->assertRequiredSchema();
        $storeId = (new StoreScope($this->pdo))->id();
        $products = $this->loadProductSeedDependency($storeId);
        $plan = $this->buildPlan($products);

        $this->pdo->beginTransaction();
        try {
            $seedUserId = $this->resolveSeedUser(
                self::USERNAME,
                'RetailMind Sales Trend Seed',
                'rm-seed-sales-trend@example.test',
                'cashier',
                $storeId
            );
            $receiverUserId = $this->resolveSeedUser(
                self::RECEIVER_USERNAME,
                'RetailMind Sales Seed Receiver',
                'rm-seed-sales-receiver@example.test',
                'admin',
                $storeId
            );
            $this->removePreviousRun($seedUserId, $receiverUserId);
            $batches = $this->receiveSimulationStock($products, $plan['units_by_product'], $receiverUserId);
            $inserted = $this->insertSales($plan['sales'], $batches, $seedUserId);
            $this->pdo->commit();

            return [
                'seed_key' => self::SEED_KEY,
                'start_date' => $plan['start_date'],
                'end_date' => $plan['end_date'],
                'calendar_days' => self::HISTORY_DAYS,
                'sales_days' => $plan['sales_days'],
                'zero_sales_days' => $plan['zero_sales_days'],
                'fiscal_blocked_days' => $plan['fiscal_blocked_days'],
                'sales' => $inserted['sales'],
                'sale_items' => $inserted['sale_items'],
                'units_sold' => array_sum($plan['units_by_product']),
                'products' => count($products),
                'gross_total' => number_format($inserted['gross_total_cents'] / 100, 2, '.', ''),
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function ownedSummary(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT s.sale_id) AS sales,
                    COUNT(si.sale_item_id) AS sale_items,
                    COALESCE(SUM(si.quantity), 0) AS units_sold,
                    MIN(DATE(s.sale_date)) AS start_date,
                    MAX(DATE(s.sale_date)) AS end_date,
                    COUNT(DISTINCT DATE(s.sale_date)) AS sales_days
             FROM users u
             LEFT JOIN sales s ON s.cashier_id = u.user_id
             LEFT JOIN sale_items si ON si.sale_id = s.sale_id
             WHERE u.username = ?"
        );
        $stmt->execute([self::USERNAME]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Calculate the monetary total independently because the line-item join above
        // intentionally repeats each sale while counting its items and units.
        $totalStmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(s.total_amount), 0) FROM sales s JOIN users u ON u.user_id = s.cashier_id WHERE u.username = ?'
        );
        $totalStmt->execute([self::USERNAME]);
        $summary['gross_total'] = number_format((float)$totalStmt->fetchColumn(), 2, '.', '');

        return $summary;
    }

    private function assertRequiredSchema(): void
    {
        foreach (['users', 'roles', 'branches', 'products', 'inventory', 'sales', 'sale_items',
                  'stock_movements', 'stock_receiving', 'purchase_history', 'product_batches',
                  'sale_item_batches', 'cashier_shifts', 'fiscal_periods', 'fiscal_period_locks'] as $table) {
            if (!Schema::tableExists($this->pdo, $table)) {
                throw new RuntimeException("Required table '{$table}' is missing. Run migrations before seeding.");
            }
        }
    }

    private function assertSafeEnvironment(): void
    {
        $appEnv = strtolower(trim((string)env('APP_ENV', '')));
        $databaseEnv = strtolower(trim((string)env('DATABASE_ENV', '')));
        $dbHost = strtolower(trim((string)env('DB_HOST', 'localhost')));
        if ($appEnv !== 'development'
            || $databaseEnv === 'hosted'
            || !in_array($dbHost, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException(
                'Sales trend seed is restricted to APP_ENV=development with a localhost/loopback database.'
            );
        }
    }

    private function loadProductSeedDependency(int $storeId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::PRODUCT_SKUS), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT p.product_id, p.sku, p.product_name, p.unit_price, p.cost_price
             FROM products p
             JOIN inventory i ON i.product_id = p.product_id
             WHERE p.branch_id = ? AND p.sku IN ({$placeholders})
               AND p.status = 'active' AND p.unit_price > 0
             ORDER BY FIELD(p.sku, {$placeholders})"
        );
        $stmt->execute(array_merge([$storeId], self::PRODUCT_SKUS, self::PRODUCT_SKUS));
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($products) < 8) {
            throw new RuntimeException(
                'Sales trend seed requires at least 8 active Store products from product_seed.csv. '
                . 'Import that product seed first; no products were created automatically.'
            );
        }

        return array_values($products);
    }

    private function buildPlan(array $products): array
    {
        $start = $this->asOf->modify('-' . (self::HISTORY_DAYS - 1) . ' days');
        $sales = [];
        $unitsByProduct = array_fill_keys(array_column($products, 'product_id'), 0);
        $salesDays = 0;
        $zeroSalesDays = 0;
        $fiscalBlockedDays = 0;

        for ($dayIndex = 0; $dayIndex < self::HISTORY_DAYS; $dayIndex++) {
            $day = $start->modify("+{$dayIndex} days");
            $intentionalZero = $dayIndex % 13 === 4
                || $dayIndex % 17 === 9
                || $dayIndex === self::HISTORY_DAYS - 2;
            $fiscalBlocked = !$this->isDateOpenForTables($day, ['sales', 'stock_movements']);
            if ($intentionalZero || $fiscalBlocked) {
                $zeroSalesDays++;
                $fiscalBlockedDays += $fiscalBlocked ? 1 : 0;
                continue;
            }

            $salesDays++;
            $weekday = (int)$day->format('N');
            $transactionCount = 1 + (($dayIndex * 7 + $weekday) % 4) + ($weekday >= 6 ? 2 : 0);
            for ($transactionIndex = 0; $transactionIndex < $transactionCount; $transactionIndex++) {
                $items = [];
                $itemCount = 1 + (($dayIndex + $transactionIndex * 3) % 3);
                for ($lineIndex = 0; $lineIndex < $itemCount; $lineIndex++) {
                    $productIndex = ($dayIndex * 3 + $transactionIndex * 5 + $lineIndex * 7) % count($products);
                    $product = $products[$productIndex];
                    $quantity = 1 + (($dayIndex + $transactionIndex + $lineIndex * 2) % 4);
                    $baseCents = max(1, (int)round((float)$product['unit_price'] * 100));
                    $pricePercent = [95, 100, 105, 110][($dayIndex + $transactionIndex + $lineIndex) % 4];
                    $unitPriceCents = max(1, (int)round($baseCents * $pricePercent / 100));
                    $subtotalCents = $quantity * $unitPriceCents;
                    $productId = (int)$product['product_id'];
                    $unitsByProduct[$productId] += $quantity;
                    $items[] = [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'unit_price_cents' => $unitPriceCents,
                        'subtotal_cents' => $subtotalCents,
                    ];
                }

                $hour = 8 + (($dayIndex + $transactionIndex * 2) % 13);
                $minute = ($dayIndex * 11 + $transactionIndex * 17) % 60;
                $sales[] = [
                    'sequence' => count($sales) + 1,
                    'sale_date' => $day->setTime($hour, $minute, 0),
                    'payment_method' => ['cash', 'card', 'ewallet'][($dayIndex + $transactionIndex) % 3],
                    'items' => $items,
                    'total_cents' => array_sum(array_column($items, 'subtotal_cents')),
                ];
            }
        }

        return [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $this->asOf->format('Y-m-d'),
            'sales' => $sales,
            'units_by_product' => $unitsByProduct,
            'sales_days' => $salesDays,
            'zero_sales_days' => $zeroSalesDays,
            'fiscal_blocked_days' => $fiscalBlockedDays,
        ];
    }

    private function isDateOpenForTables(DateTimeImmutable $day, array $tables): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT fp.period_id, fp.status
             FROM fiscal_periods fp
             WHERE ? BETWEEN fp.start_date AND fp.end_date
             ORDER BY fp.start_date DESC, fp.period_id DESC
             LIMIT 1"
        );
        $stmt->execute([$day->format('Y-m-d')]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            return true;
        }
        if (in_array($period['status'], ['closed', 'locked'], true)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $lockStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM fiscal_period_locks
             WHERE period_id = ?
               AND (table_name IS NULL OR table_name = '' OR table_name IN ({$placeholders}))"
        );
        $lockStmt->execute(array_merge([(int)$period['period_id']], $tables));
        return (int)$lockStmt->fetchColumn() === 0;
    }

    private function resolveSeedUser(
        string $username,
        string $fullName,
        string $email,
        string $roleName,
        int $storeId
    ): int
    {
        $roleStmt = $this->pdo->prepare('SELECT role_id FROM roles WHERE role_name = ? LIMIT 1');
        $roleStmt->execute([$roleName]);
        $roleId = (int)$roleStmt->fetchColumn();
        if ($roleId <= 0) {
            throw new RuntimeException("The {$roleName} role is required before seeding sales.");
        }

        $stmt = $this->pdo->prepare('SELECT user_id, full_name, email FROM users WHERE username = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$username]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['full_name'] !== $fullName || $existing['email'] !== $email) {
                throw new RuntimeException("The reserved seed username '{$username}' is owned by an unexpected user.");
            }
            $this->pdo->prepare("UPDATE users SET role_id = ?, status = 'disabled', branch_id = ? WHERE user_id = ?")
                ->execute([$roleId, $storeId, (int)$existing['user_id']]);
            return (int)$existing['user_id'];
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO users
                (full_name, username, email, password_hash, role_id, status, must_change_password, branch_id)
             VALUES (?, ?, ?, '!RM_SEED_LOGIN_DISABLED!', ?, 'disabled', 1, ?)"
        );
        $insert->execute([$fullName, $username, $email, $roleId, $storeId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function removePreviousRun(int $seedUserId, int $receiverUserId): void
    {
        $externalBatchUse = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM sale_item_batches sib
             JOIN product_batches pb ON pb.batch_id = sib.batch_id
             JOIN sale_items si ON si.sale_item_id = sib.sale_item_id
             JOIN sales s ON s.sale_id = si.sale_id
             WHERE pb.batch_number LIKE ? AND s.cashier_id <> ?"
        );
        $externalBatchUse->execute([self::SEED_KEY . '-%', $seedUserId]);
        if ((int)$externalBatchUse->fetchColumn() > 0) {
            throw new RuntimeException('Seed-owned batches were used by non-seed sales; refusing destructive cleanup.');
        }

        $reversalStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM sale_reversals sr JOIN sales s ON s.sale_id = sr.sale_id WHERE s.cashier_id = ?'
        );
        $reversalStmt->execute([$seedUserId]);
        if ((int)$reversalStmt->fetchColumn() > 0) {
            throw new RuntimeException('Seed-owned sales have reversal records; refusing destructive cleanup.');
        }

        $soldStmt = $this->pdo->prepare(
            "SELECT si.product_id, SUM(si.quantity) AS quantity
             FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
             WHERE s.cashier_id = ? GROUP BY si.product_id"
        );
        $soldStmt->execute([$seedUserId]);
        $sold = $soldStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $receivedStmt = $this->pdo->prepare(
            "SELECT product_id, SUM(quantity) AS quantity
             FROM product_batches WHERE batch_number LIKE ? GROUP BY product_id"
        );
        $receivedStmt->execute([self::SEED_KEY . '-%']);
        $received = $receivedStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach (array_unique(array_merge(array_keys($sold), array_keys($received))) as $productId) {
            $soldQty = (int)($sold[$productId] ?? 0);
            $receivedQty = (int)($received[$productId] ?? 0);
            $lock = $this->pdo->prepare(
                'SELECT i.quantity_on_hand, p.quantity_sold, p.quantity_purchased
                 FROM inventory i JOIN products p ON p.product_id = i.product_id
                 WHERE i.product_id = ? FOR UPDATE'
            );
            $lock->execute([(int)$productId]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$current
                || (int)$current['quantity_on_hand'] + $soldQty - $receivedQty < 0
                || (int)$current['quantity_sold'] - $soldQty < 0
                || (int)$current['quantity_purchased'] - $receivedQty < 0) {
                throw new RuntimeException("Seed cleanup would violate stock counters for product #{$productId}; refusing cleanup.");
            }
            $this->pdo->prepare('UPDATE inventory SET quantity_on_hand = quantity_on_hand + ? - ? WHERE product_id = ?')
                ->execute([$soldQty, $receivedQty, (int)$productId]);
            $this->pdo->prepare(
                'UPDATE products SET quantity_sold = quantity_sold - ?, quantity_purchased = quantity_purchased - ? WHERE product_id = ?'
            )->execute([$soldQty, $receivedQty, (int)$productId]);
        }

        $this->pdo->prepare(
            'DELETE sib FROM sale_item_batches sib JOIN sale_items si ON si.sale_item_id = sib.sale_item_id JOIN sales s ON s.sale_id = si.sale_id WHERE s.cashier_id = ?'
        )->execute([$seedUserId]);
        $this->pdo->prepare(
            'DELETE si FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id WHERE s.cashier_id = ?'
        )->execute([$seedUserId]);
        $this->pdo->prepare('DELETE FROM sales WHERE cashier_id = ?')->execute([$seedUserId]);
        $this->pdo->prepare('DELETE FROM product_batches WHERE batch_number LIKE ?')->execute([self::SEED_KEY . '-%']);
        $this->pdo->prepare('DELETE FROM stock_receiving WHERE received_by IN (?, ?) AND invoice_number LIKE ?')
            ->execute([$seedUserId, $receiverUserId, self::SEED_KEY . '-%']);
        $this->pdo->prepare('DELETE FROM purchase_history WHERE recorded_by IN (?, ?)')
            ->execute([$seedUserId, $receiverUserId]);
        $this->pdo->prepare('DELETE FROM stock_movements WHERE moved_by IN (?, ?)')->execute([$seedUserId, $receiverUserId]);
        $this->pdo->prepare('DELETE FROM cashier_shifts WHERE cashier_id = ? AND closing_notes LIKE ?')
            ->execute([$seedUserId, self::SEED_KEY . '%']);
    }

    private function receiveSimulationStock(array $products, array $unitsByProduct, int $seedUserId): array
    {
        $receivedAt = $this->asOf->modify('-' . self::HISTORY_DAYS . ' days')->setTime(7, 0);
        $receivingTables = ['stock_receiving', 'purchase_history', 'stock_movements'];
        for ($attempt = 0; $attempt < 365 && !$this->isDateOpenForTables($receivedAt, $receivingTables); $attempt++) {
            $receivedAt = $receivedAt->modify('-1 day');
        }
        if (!$this->isDateOpenForTables($receivedAt, $receivingTables)) {
            throw new RuntimeException('No open fiscal date was found for the simulation stock receipt.');
        }
        $batches = [];
        foreach ($products as $index => $product) {
            $productId = (int)$product['product_id'];
            $quantity = (int)$unitsByProduct[$productId] + 100 + $index * 3;
            $cost = (float)$product['cost_price'];
            $batchNumber = self::SEED_KEY . '-' . $this->asOf->format('Ymd') . '-' . $productId;

            $receiving = $this->pdo->prepare(
                "INSERT INTO stock_receiving
                    (product_id, received_qty, received_packages, units_per_package_used, accepted_qty,
                     damaged_qty, cost_price, total_cost, received_by, received_at, supplier, po_number,
                     invoice_number, batch_number, discrepancy_type, discrepancy_qty, notes)
                 VALUES (?, ?, 0, 1, ?, 0, ?, ?, ?, ?, 'RetailMind Seed Supplier', ?, ?, ?, 'none', 0, ?)"
            );
            $receiving->execute([
                $productId, $quantity, $quantity, $cost, round($cost * $quantity, 2), $seedUserId,
                $receivedAt->format('Y-m-d H:i:s'), self::SEED_KEY, $batchNumber, $batchNumber,
                'Deterministic dashboard sales-trend simulation stock',
            ]);
            $receivingId = (int)$this->pdo->lastInsertId();

            $batch = $this->pdo->prepare(
                "INSERT INTO product_batches
                    (product_id, receiving_id, batch_number, quantity, remaining_quantity, expiration_date, date_received, supplier)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, 'RetailMind Seed Supplier')"
            );
            $batch->execute([$productId, $receivingId, $batchNumber, $quantity, $quantity, $receivedAt->format('Y-m-d H:i:s')]);
            $batches[$productId] = ['batch_id' => (int)$this->pdo->lastInsertId(), 'remaining' => $quantity];

            $this->pdo->prepare(
                'INSERT INTO purchase_history (product_id, supplier, quantity_purchased, cost_price, purchase_date, total_cost, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $productId, 'RetailMind Seed Supplier', $quantity, $cost,
                $receivedAt->format('Y-m-d H:i:s'), round($cost * $quantity, 2), $seedUserId,
            ]);
            $this->pdo->prepare(
                "INSERT INTO stock_movements (product_id, change_qty, reason, moved_by, moved_at)
                 VALUES (?, ?, 'purchase', ?, ?)"
            )->execute([$productId, $quantity, $seedUserId, $receivedAt->format('Y-m-d H:i:s')]);
            $this->pdo->prepare('UPDATE inventory SET quantity_on_hand = quantity_on_hand + ? WHERE product_id = ?')
                ->execute([$quantity, $productId]);
            $this->pdo->prepare('UPDATE products SET quantity_purchased = quantity_purchased + ? WHERE product_id = ?')
                ->execute([$quantity, $productId]);
        }

        return $batches;
    }

    private function insertSales(array $sales, array &$batches, int $seedUserId): array
    {
        $saleInsert = $this->pdo->prepare(
            "INSERT INTO sales
                (cashier_id, shift_id, total_amount, gross_amount, discount_type, discount_value,
                 discount_amount, payment_method, cash_received, change_due, payment_reference, sale_date)
             VALUES (?, ?, ?, ?, 'none', 0, 0, ?, ?, ?, ?, ?)"
        );
        $itemInsert = $this->pdo->prepare(
            'INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)'
        );
        $allocationInsert = $this->pdo->prepare(
            'INSERT INTO sale_item_batches (sale_item_id, batch_id, quantity) VALUES (?, ?, ?)'
        );
        $batchUpdate = $this->pdo->prepare(
            'UPDATE product_batches SET remaining_quantity = remaining_quantity - ? WHERE batch_id = ? AND remaining_quantity >= ?'
        );
        $inventoryUpdate = $this->pdo->prepare(
            'UPDATE inventory SET quantity_on_hand = quantity_on_hand - ? WHERE product_id = ? AND quantity_on_hand >= ?'
        );
        $productUpdate = $this->pdo->prepare('UPDATE products SET quantity_sold = quantity_sold + ? WHERE product_id = ?');
        $movementInsert = $this->pdo->prepare(
            "INSERT INTO stock_movements (product_id, change_qty, reason, moved_by, moved_at) VALUES (?, ?, 'sale', ?, ?)"
        );

        $grouped = [];
        foreach ($sales as $sale) {
            $grouped[$sale['sale_date']->format('Y-m-d')][] = $sale;
        }

        $saleCount = 0;
        $itemCount = 0;
        $grossTotalCents = 0;
        foreach ($grouped as $day => $daySales) {
            $openingCashCents = 100000 + ((int)str_replace('-', '', $day) % 5) * 10000;
            $shiftInsert = $this->pdo->prepare(
                "INSERT INTO cashier_shifts
                    (cashier_id, opened_at, opening_cash, status, closed_at, expected_cash,
                     actual_cash, cash_variance, closing_notes)
                 VALUES (?, ?, ?, 'closed', ?, ?, ?, 0, ?)"
            );
            $cashSalesCents = array_sum(array_map(
                static fn(array $sale): int => $sale['payment_method'] === 'cash' ? $sale['total_cents'] : 0,
                $daySales
            ));
            $expectedCash = ($openingCashCents + $cashSalesCents) / 100;
            $shiftInsert->execute([
                $seedUserId, $day . ' 07:30:00', $openingCashCents / 100, $day . ' 21:30:00',
                $expectedCash, $expectedCash, self::SEED_KEY . ' ' . $day,
            ]);
            $shiftId = (int)$this->pdo->lastInsertId();

            foreach ($daySales as $sale) {
                $total = $sale['total_cents'] / 100;
                $cashReceivedCents = $sale['payment_method'] === 'cash'
                    ? (int)(ceil($sale['total_cents'] / 1000) * 1000)
                    : null;
                $reference = $sale['payment_method'] === 'cash'
                    ? null
                    : self::SEED_KEY . '-' . strtoupper($sale['payment_method']) . '-' . str_pad((string)$sale['sequence'], 5, '0', STR_PAD_LEFT);
                $saleInsert->execute([
                    $seedUserId, $shiftId, $total, $total, $sale['payment_method'],
                    $cashReceivedCents !== null ? $cashReceivedCents / 100 : null,
                    $cashReceivedCents !== null ? ($cashReceivedCents - $sale['total_cents']) / 100 : null,
                    $reference, $sale['sale_date']->format('Y-m-d H:i:s'),
                ]);
                $saleId = (int)$this->pdo->lastInsertId();
                $saleCount++;
                $grossTotalCents += $sale['total_cents'];

                foreach ($sale['items'] as $item) {
                    $productId = $item['product_id'];
                    $quantity = $item['quantity'];
                    $itemInsert->execute([
                        $saleId, $productId, $quantity,
                        $item['unit_price_cents'] / 100, $item['subtotal_cents'] / 100,
                    ]);
                    $saleItemId = (int)$this->pdo->lastInsertId();
                    $itemCount++;

                    $batchUpdate->execute([$quantity, $batches[$productId]['batch_id'], $quantity]);
                    $inventoryUpdate->execute([$quantity, $productId, $quantity]);
                    if ($batchUpdate->rowCount() !== 1 || $inventoryUpdate->rowCount() !== 1) {
                        throw new RuntimeException("Insufficient simulation stock for product #{$productId}.");
                    }
                    $allocationInsert->execute([$saleItemId, $batches[$productId]['batch_id'], $quantity]);
                    $productUpdate->execute([$quantity, $productId]);
                    $movementInsert->execute([
                        $productId, -$quantity, $seedUserId, $sale['sale_date']->format('Y-m-d H:i:s'),
                    ]);
                    $batches[$productId]['remaining'] -= $quantity;
                }
            }
        }

        return ['sales' => $saleCount, 'sale_items' => $itemCount, 'gross_total_cents' => $grossTotalCents];
    }
}
