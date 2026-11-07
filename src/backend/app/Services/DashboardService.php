<?php
// app/Services/DashboardService.php

require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/../Forecasting/ForecastAggregateReader.php';

use App\Forecasting\ForecastAggregateReader;

class DashboardService
{
    private PDO $pdo;
    private App\Audit\ProtectedAuditRecordService $protectedAuditRecords;
    /** @var array<string, true>|null */
    private ?array $saleItemColumns = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->protectedAuditRecords = new App\Audit\ProtectedAuditRecordService(
            $pdo,
            new App\Authorization\RoleCapabilityPolicy()
        );
    }

    public function getAdminMetrics(string $actorRole, int $days = 28): array
    {
        $days = max(1, min(365, $days));
        return [
            'total_users' => (int)$this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'active_cashiers_today' => (int)$this->pdo->query(
                "SELECT COUNT(DISTINCT s.cashier_id)
                 FROM sales s
                 JOIN users u ON u.user_id = s.cashier_id
                 JOIN roles r ON r.role_id = u.role_id
                 WHERE r.role_name = 'cashier' AND s.sale_date >= CURDATE() AND s.sale_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)"
            )->fetchColumn(),
            'total_sales' => (float)$this->pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales")->fetchColumn(),
            'period_sales' => (float)$this->pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)")->fetchColumn(),
            'audit_events' => $this->protectedAuditRecords->count($actorRole),
            'open_periods' => (int)$this->pdo->query("SELECT COUNT(*) FROM fiscal_periods WHERE status = 'open'")->fetchColumn(),
            'closed_periods' => (int)$this->pdo->query("SELECT COUNT(*) FROM fiscal_periods WHERE status IN ('closed', 'locked')")->fetchColumn(),
        ];
    }

    public function getRecentCriticalActions(string $actorRole, int $limit = 8): array
    {
        return array_map(
            static fn(array $record): array => [
                'action' => $record['action'],
                'created_at' => $record['created_at'],
                'user_name' => $record['display_name'] ?: ($record['username'] ?: 'System'),
            ],
            $this->protectedAuditRecords->records($actorRole, [
                'critical_only' => true,
                'limit' => $limit,
            ])
        );
    }

    public function getManagerMetrics(): array
    {
        return [
            'low_stock_count' => (int)$this->pdo->query(
                "SELECT COUNT(*)
                 FROM products p
                 JOIN inventory i ON i.product_id = p.product_id
                 WHERE p.status = 'active' AND i.quantity_on_hand > 0 AND i.quantity_on_hand <= p.reorder_level"
            )->fetchColumn(),
            'out_of_stock_count' => (int)$this->pdo->query(
                "SELECT COUNT(*)
                 FROM products p
                 JOIN inventory i ON i.product_id = p.product_id
                 WHERE p.status = 'active' AND i.quantity_on_hand <= 0"
            )->fetchColumn(),
            'expiring_count' => count((new InventoryService($this->pdo))->getExpiringSoonBatches(30)),
            'forecasted_demand' => (int)array_sum(array_column(
                $this->forecastAggregateReader()->readLatestSuccessful(),
                'forecast_28_day'
            )),
            'inventory_value' => (float)$this->pdo->query(
                "SELECT COALESCE(SUM(i.quantity_on_hand * p.cost_price), 0)
                 FROM inventory i
                 JOIN products p ON p.product_id = i.product_id"
            )->fetchColumn(),
        ];
    }

    public function getFastMovingProducts(int $limit = 5, int $days = 28): array
    {
        $days = max(1, min(365, $days));
        $unitsExpr = $this->salesUnitsExpression('si');
        $stmt = $this->pdo->prepare(
            "SELECT p.sku, p.product_name, SUM({$unitsExpr}) AS units_sold
             FROM sale_items si
             JOIN sales s ON s.sale_id = si.sale_id
             JOIN products p ON p.product_id = si.product_id
             WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
             GROUP BY p.product_id, p.sku, p.product_name
             ORDER BY units_sold DESC, p.product_name ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getSlowMovingProducts(int $limit = 5, int $days = 28): array
    {
        $days = max(1, min(365, $days));
        $unitsExpr = $this->salesUnitsExpression('si');
        $stmt = $this->pdo->prepare(
            "SELECT p.sku, p.product_name,
                    COALESCE(SUM(CASE WHEN s.sale_id IS NULL THEN 0 ELSE {$unitsExpr} END), 0) AS units_sold,
                    i.quantity_on_hand
             FROM products p
             JOIN inventory i ON i.product_id = p.product_id
             LEFT JOIN sale_items si ON si.product_id = p.product_id
             LEFT JOIN sales s ON s.sale_id = si.sale_id
                AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
             WHERE p.status = 'active' AND i.quantity_on_hand > 0
             GROUP BY p.product_id, p.sku, p.product_name, i.quantity_on_hand
             ORDER BY units_sold ASC, i.quantity_on_hand DESC, p.product_name ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getLatestForecasts(int $limit = 5): array
    {
        $aggregates = $this->forecastAggregateReader()->readLatestSuccessful();
        $products = $this->productIndex(array_keys($aggregates));

        $rows = [];
        foreach ($aggregates as $productId => $aggregate) {
            if (!isset($products[$productId])) {
                continue;
            }
            $rows[] = [
                'sku' => $products[$productId]['sku'],
                'product_name' => $products[$productId]['product_name'],
                'predicted_demand_next_28_days' => $aggregate['forecast_28_day'],
                'reorder_suggested' => 0,
                'suggested_reorder_qty' => 0,
                'confidence_score' => 1.0,
            ];
        }

        usort($rows, static fn(array $a, array $b): int =>
            $b['predicted_demand_next_28_days'] <=> $a['predicted_demand_next_28_days']);

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    public function getSalesTrend(int $days = 28, ?DateTimeImmutable $asOf = null): array
    {
        $days = max(7, min(365, $days));
        $manila = new \DateTimeZone('Asia/Manila');
        $asOf = ($asOf ?? new DateTimeImmutable('today', $manila))->setTimezone($manila)->setTime(0, 0);
        $start = $asOf->modify('-' . ($days - 1) . ' days');
        $endExclusive = $asOf->modify('+1 day');

        // Database::connection pins the shared read session to +08:00. Do not
        // mutate it here: later receipt/history queries use the same projection.

        $stmt = $this->pdo->prepare(
            "SELECT DATE(sale_date) AS sale_day,
                    COALESCE(SUM(total_amount), 0) AS total_sales,
                    COUNT(*) AS transactions
             FROM sales s
             JOIN users u ON u.user_id = s.cashier_id
             WHERE s.sale_date >= ? AND s.sale_date < ?
             GROUP BY DATE(s.sale_date)
             ORDER BY sale_day ASC"
        );
        $stmt->execute([
            $start->format('Y-m-d H:i:s'),
            $endExclusive->format('Y-m-d H:i:s'),
        ]);

        $recordedByDay = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recordedByDay[(string)$row['sale_day']] = [
                'sale_day' => (string)$row['sale_day'],
                'total_sales' => number_format((float)$row['total_sales'], 2, '.', ''),
                'transactions' => (int)$row['transactions'],
            ];
        }

        $trend = [];
        for ($day = $start; $day <= $asOf; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $trend[] = $recordedByDay[$date] ?? [
                'sale_day' => $date,
                'total_sales' => '0.00',
                'transactions' => 0,
            ];
        }

        return $trend;
    }

    public function getInventoryValueByCategory(int $limit = 8): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(c.category_name, 'Uncategorized') AS category_name,
                    COALESCE(SUM(i.quantity_on_hand * p.cost_price), 0) AS inventory_value,
                    COALESCE(SUM(i.quantity_on_hand), 0) AS units_on_hand
             FROM products p
             JOIN inventory i ON i.product_id = p.product_id
             LEFT JOIN categories c ON c.category_id = p.category_id
             WHERE p.status = 'active'
             GROUP BY c.category_id, c.category_name
             ORDER BY inventory_value DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, max(1, min(20, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getForecastVsActual(int $limit = 8): array
    {
        $forecasts = $this->forecastAggregateReader()->readLatestSuccessful();

        $unitsExpr = $this->salesUnitsExpression('si');
        $stmt = $this->pdo->prepare(
            "SELECT si.product_id, SUM({$unitsExpr}) AS units_sold
             FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
             WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
             GROUP BY si.product_id"
        );
        $stmt->execute();
        $actuals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $actuals[(int)$row['product_id']] = $row['units_sold'];
        }

        $productIds = array_values(array_unique(array_merge(array_keys($forecasts), array_keys($actuals))));
        $products = $this->productIndex($productIds);

        $rows = [];
        foreach ($productIds as $productId) {
            if (!isset($products[$productId])) {
                continue;
            }
            $rows[] = [
                'sku' => $products[$productId]['sku'],
                'product_name' => $products[$productId]['product_name'],
                'forecast_demand' => $forecasts[$productId]['forecast_28_day'] ?? 0,
                'actual_units' => $actuals[$productId] ?? 0,
            ];
        }

        usort($rows, static fn(array $a, array $b): int =>
            max((float)$b['forecast_demand'], (float)$b['actual_units'])
            <=> max((float)$a['forecast_demand'], (float)$a['actual_units']));

        return $limit > 0 ? array_slice($rows, 0, max(1, min(20, $limit))) : $rows;
    }

    public function getCashierMetrics(int $cashierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT HOUR(sale_date) AS sale_hour,
                    COALESCE(SUM(total_amount), 0) AS today_sales,
                    COUNT(*) AS transactions
             FROM sales
             WHERE cashier_id = ? AND sale_date >= CURDATE() AND sale_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
             GROUP BY HOUR(sale_date)
             ORDER BY sale_hour"
        );
        $stmt->execute([$cashierId]);
        $hourlySales = array_fill(0, 24, 0.0);
        $transactions = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hourlySales[(int)$row['sale_hour']] = (float)$row['today_sales'];
            $transactions += (int)$row['transactions'];
        }
        $todaySales = array_sum($hourlySales);

        return [
            'today_sales' => $todaySales,
            'transactions' => $transactions,
            'average_transaction' => $transactions ? $todaySales / $transactions : 0.0,
            'hourly_sales' => $hourlySales,
        ];
    }

    public function getCashierRecentTransactions(int $cashierId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.sale_id, s.total_amount, s.payment_method, s.sale_date, COUNT(si.sale_item_id) AS item_count
             FROM sales s
             LEFT JOIN sale_items si ON si.sale_id = s.sale_id
             WHERE s.cashier_id = ?
             GROUP BY s.sale_id
             ORDER BY s.sale_date DESC, s.sale_id DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $cashierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getCashierLowStockWarnings(int $cashierId, int $limit = 6): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT p.product_id, p.sku, p.product_name, i.quantity_on_hand, p.reorder_level
             FROM sale_items si
             JOIN sales s ON s.sale_id = si.sale_id
             JOIN products p ON p.product_id = si.product_id
             JOIN inventory i ON i.product_id = p.product_id
             WHERE s.cashier_id = ?
               AND s.sale_date >= CURDATE() AND s.sale_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
               AND i.quantity_on_hand <= p.reorder_level
             ORDER BY i.quantity_on_hand ASC, p.product_name ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $cashierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function forecastAggregateReader(): ForecastAggregateReader
    {
        return new ForecastAggregateReader($this->pdo);
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, array<string, mixed>> keyed by product_id
     */
    private function productIndex(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT product_id, sku, product_name FROM products WHERE product_id IN ({$placeholders})"
        );
        $stmt->execute(array_values($productIds));
        $index = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $index[(int)$row['product_id']] = $row;
        }
        return $index;
    }

    /** Sale quantities in Base Units; a Sales Pack line contributes its contents. */
    private function salesUnitsExpression(string $alias): string
    {
        $columns = $this->saleItemColumns ?? [];
        if ($this->saleItemColumns === null) {
            $probed = true;
            try {
                if ((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    foreach ($this->pdo->query('PRAGMA table_info(sale_items)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                        $columns[(string)$column['name']] = true;
                    }
                } else {
                    foreach ($this->pdo->query('SHOW COLUMNS FROM `sale_items`')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                        $columns[(string)$column['Field']] = true;
                    }
                }
            } catch (Throwable) {
                // Cache only a successful probe, so a transient failure can retry.
                $probed = false;
            }
            if ($probed) {
                $this->saleItemColumns = $columns;
            }
        }

        if (!isset($columns['selling_form'], $columns['pack_size'])) {
            return "{$alias}.quantity";
        }

        return "(CASE WHEN {$alias}.selling_form = 'pack' AND {$alias}.pack_size IS NOT NULL"
            . " THEN {$alias}.quantity * {$alias}.pack_size ELSE {$alias}.quantity END)";
    }

    public function getSalesSummary(): array
    {
        $total = $this->pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM sales');
        $today = $this->pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sale_date >= CURDATE() AND sale_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)');
        return [
            'total_sales' => (float)$total->fetchColumn(),
            'total_sales_today' => (float)$today->fetchColumn(),
        ];
    }

    public function getRecentTransactions(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        return $this->pdo->query(
            "SELECT 
                s.sale_id,
                s.sale_id AS reference_no,
                CONCAT('#', s.sale_id) AS receipt_no,
                s.total_amount,
                s.payment_method,
                s.sale_date,
                u.display_name AS cashier_name,
                COUNT(si.sale_item_id) AS item_count
             FROM sales s
             JOIN users u ON s.cashier_id = u.user_id
             LEFT JOIN sale_items si ON s.sale_id = si.sale_id
             GROUP BY s.sale_id
             ORDER BY s.sale_date DESC
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
