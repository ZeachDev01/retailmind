<?php
// app/Services/DashboardService.php

class DashboardService
{
    private PDO $pdo;
    private App\Store\StoreScope $storeScope;
    private App\Audit\ProtectedAuditRecordService $protectedAuditRecords;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->storeScope = new App\Store\StoreScope($pdo);
        $this->protectedAuditRecords = new App\Audit\ProtectedAuditRecordService(
            $pdo,
            new App\Authorization\RoleCapabilityPolicy()
        );
    }

    public function getAdminMetrics(string $actorRole, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        return [
            'total_users' => (int)$this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'active_cashiers_today' => (int)$this->pdo->query(
                "SELECT COUNT(DISTINCT s.cashier_id)
                 FROM sales s
                 JOIN users u ON u.user_id = s.cashier_id
                 JOIN roles r ON r.role_id = u.role_id
                 WHERE r.role_name = 'cashier' AND DATE(s.sale_date) = CURDATE()"
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
                'user_name' => $record['full_name'] ?: ($record['username'] ?: 'System'),
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
            'expiring_count' => (int)$this->pdo->query(
                "SELECT COUNT(*)
                 FROM product_batches
                 WHERE remaining_quantity > 0
                   AND expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
            )->fetchColumn(),
            'pending_replenishment' => (int)$this->pdo->query(
                "SELECT COUNT(*) FROM replenishment_requests WHERE status = 'pending'"
            )->fetchColumn(),
            'forecasted_demand' => (int)$this->pdo->query(
                "SELECT COALESCE(SUM(predicted_units), 0)
                 FROM demand_forecasts df
                 WHERE df.job_id = (
                    SELECT job_id
                    FROM ml_jobs
                    WHERE trigger_type = 'forecast_generation' AND status = 'succeeded'
                    ORDER BY completed_at DESC, job_id DESC
                    LIMIT 1
                 ) AND df.horizon_day <= 28"
            )->fetchColumn(),
            'inventory_value' => (float)$this->pdo->query(
                "SELECT COALESCE(SUM(i.quantity_on_hand * p.cost_price), 0)
                 FROM inventory i
                 JOIN products p ON p.product_id = i.product_id"
            )->fetchColumn(),
        ];
    }

    public function getFastMovingProducts(int $limit = 5, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $stmt = $this->pdo->prepare(
            "SELECT p.sku, p.product_name, SUM(si.quantity) AS units_sold
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

    public function getSlowMovingProducts(int $limit = 5, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $stmt = $this->pdo->prepare(
            "SELECT p.sku, p.product_name,
                    COALESCE(SUM(CASE WHEN s.sale_id IS NULL THEN 0 ELSE si.quantity END), 0) AS units_sold,
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
        $stmt = $this->pdo->prepare(
            "SELECT p.sku, p.product_name, SUM(df.predicted_units) as predicted_demand_next_30_days,
                    0 as reorder_suggested, 0 as suggested_reorder_qty, 1.0 as confidence_score
             FROM demand_forecasts df
             JOIN products p ON p.product_id = df.product_id
             WHERE df.job_id = (
                SELECT job_id
                FROM ml_jobs
                WHERE trigger_type = 'forecast_generation' AND status = 'succeeded'
                ORDER BY completed_at DESC, job_id DESC
                LIMIT 1
             ) AND df.horizon_day <= 30
             GROUP BY p.product_id, p.sku, p.product_name
             ORDER BY predicted_demand_next_30_days DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getPendingReplenishmentRequests(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT rr.request_id, rr.request_qty, rr.request_date, p.sku, p.product_name
             FROM replenishment_requests rr
             JOIN products p ON p.product_id = rr.product_id
             WHERE rr.status = 'pending'
             ORDER BY rr.request_date ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getSalesTrend(int $days = 30, ?DateTimeImmutable $asOf = null): array
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
             WHERE (u.branch_id = ? OR EXISTS (
                 SELECT 1 FROM sale_items scope_si
                 JOIN products scope_p ON scope_p.product_id = scope_si.product_id
                 WHERE scope_si.sale_id = s.sale_id AND scope_p.branch_id = ?
             )) AND s.sale_date >= ? AND s.sale_date < ?
             GROUP BY DATE(s.sale_date)
             ORDER BY sale_day ASC"
        );
        $storeId = $this->storeScope->id();
        $stmt->execute([
            $storeId,
            $storeId,
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
        $stmt = $this->pdo->prepare(
            "SELECT p.sku, p.product_name,
                    COALESCE(df_agg.forecast_demand, 0) AS forecast_demand,
                    COALESCE(actual.units_sold, 0) AS actual_units
             FROM products p
             LEFT JOIN (
                SELECT product_id, SUM(predicted_units) as forecast_demand
                FROM demand_forecasts
                WHERE job_id = (
                    SELECT job_id FROM ml_jobs WHERE trigger_type = 'forecast_generation' AND status = 'succeeded' ORDER BY completed_at DESC, job_id DESC LIMIT 1
                ) AND horizon_day <= 28
                GROUP BY product_id
             ) df_agg ON df_agg.product_id = p.product_id
             LEFT JOIN (
                SELECT si.product_id, SUM(si.quantity) AS units_sold
                FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
                WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY si.product_id
             ) actual ON actual.product_id = p.product_id
             WHERE df_agg.forecast_demand IS NOT NULL OR actual.units_sold IS NOT NULL
             ORDER BY GREATEST(COALESCE(df_agg.forecast_demand, 0), COALESCE(actual.units_sold, 0)) DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, max(1, min(20, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getCashierMetrics(int $cashierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) AS today_sales,
                    COUNT(*) AS transactions,
                    COALESCE(AVG(total_amount), 0) AS average_transaction
             FROM sales
             WHERE cashier_id = ? AND DATE(sale_date) = CURDATE()"
        );
        $stmt->execute([$cashierId]);
        $row = $stmt->fetch() ?: [];

        return [
            'today_sales' => (float)($row['today_sales'] ?? 0),
            'transactions' => (int)($row['transactions'] ?? 0),
            'average_transaction' => (float)($row['average_transaction'] ?? 0),
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
             ORDER BY s.sale_date DESC
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
               AND DATE(s.sale_date) = CURDATE()
               AND i.quantity_on_hand <= p.reorder_level
             ORDER BY i.quantity_on_hand ASC, p.product_name ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $cashierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
