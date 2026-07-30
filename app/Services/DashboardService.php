<?php
// app/Services/DashboardService.php

class DashboardService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAdminMetrics(): array
    {
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
            'audit_events' => (int)$this->pdo->query("SELECT COUNT(*) FROM activity_log")->fetchColumn(),
            'open_periods' => (int)$this->pdo->query("SELECT COUNT(*) FROM fiscal_periods WHERE status = 'open'")->fetchColumn(),
            'closed_periods' => (int)$this->pdo->query("SELECT COUNT(*) FROM fiscal_periods WHERE status IN ('closed', 'locked')")->fetchColumn(),
        ];
    }

    public function getRecentCriticalActions(int $limit = 8): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT al.action, al.created_at, COALESCE(u.full_name, 'System') AS user_name
             FROM activity_log al
             LEFT JOIN users u ON u.user_id = al.user_id
             WHERE LOWER(al.action) LIKE '%void%'
                OR LOWER(al.action) LIKE '%delete%'
                OR LOWER(al.action) LIKE '%reversal%'
                OR LOWER(al.action) LIKE '%refund%'
                OR LOWER(al.action) LIKE '%fiscal%'
                OR LOWER(al.action) LIKE '%locked%'
                OR LOWER(al.action) LIKE '%toggled%'
                OR LOWER(al.action) LIKE '%adjustment%'
                OR LOWER(al.action) LIKE '%count%'
             ORDER BY al.created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
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
                "SELECT COALESCE(SUM(sp.predicted_demand_next_30_days), 0)
                 FROM stock_predictions sp
                 WHERE sp.prediction_id = (
                    SELECT sp2.prediction_id
                    FROM stock_predictions sp2
                    WHERE sp2.product_id = sp.product_id
                    ORDER BY sp2.generated_at DESC, sp2.prediction_id DESC
                    LIMIT 1
                 )"
            )->fetchColumn(),
            'inventory_value' => (float)$this->pdo->query(
                "SELECT COALESCE(SUM(i.quantity_on_hand * p.cost_price), 0)
                 FROM inventory i
                 JOIN products p ON p.product_id = i.product_id"
            )->fetchColumn(),
        ];
    }

    public function getFastMovingProducts(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.sku, p.product_name, SUM(si.quantity) AS units_sold
             FROM sale_items si
             JOIN sales s ON s.sale_id = si.sale_id
             JOIN products p ON p.product_id = si.product_id
             WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY p.product_id, p.sku, p.product_name
             ORDER BY units_sold DESC, p.product_name ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getSlowMovingProducts(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.sku, p.product_name,
                    COALESCE(SUM(CASE WHEN s.sale_id IS NULL THEN 0 ELSE si.quantity END), 0) AS units_sold,
                    i.quantity_on_hand
             FROM products p
             JOIN inventory i ON i.product_id = p.product_id
             LEFT JOIN sale_items si ON si.product_id = p.product_id
             LEFT JOIN sales s ON s.sale_id = si.sale_id
                AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
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
            "SELECT p.sku, p.product_name, sp.predicted_demand_next_30_days,
                    sp.reorder_suggested, sp.suggested_reorder_qty, sp.confidence_score
             FROM stock_predictions sp
             JOIN products p ON p.product_id = sp.product_id
             WHERE sp.prediction_id = (
                SELECT sp2.prediction_id
                FROM stock_predictions sp2
                WHERE sp2.product_id = sp.product_id
                ORDER BY sp2.generated_at DESC, sp2.prediction_id DESC
                LIMIT 1
             )
             ORDER BY sp.reorder_suggested DESC, sp.predicted_demand_next_30_days DESC
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
