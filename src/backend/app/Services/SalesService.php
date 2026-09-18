<?php
// app/Services/SalesService.php

class SalesService
{
    private PDO $pdo;
    private App\Store\StoreScope $storeScope;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->storeScope = new App\Store\StoreScope($pdo);
    }

    public function getRecentTransactions(): array
    {
        return $this->pdo->query(
            "SELECT 
                s.sale_id,
                s.sale_id AS reference_no,
                CONCAT('#', s.sale_id) AS receipt_no,
                s.total_amount,
                s.payment_method,
                s.sale_date,
                u.full_name AS cashier_name,
                COUNT(si.sale_item_id) AS item_count
             FROM sales s
             JOIN users u ON s.cashier_id = u.user_id
             LEFT JOIN sale_items si ON s.sale_id = si.sale_id
             WHERE u.branch_id = " . $this->storeScope->id() . " OR EXISTS (
                 SELECT 1 FROM sale_items scope_si
                 JOIN products scope_p ON scope_p.product_id = scope_si.product_id
                 WHERE scope_si.sale_id = s.sale_id AND scope_p.branch_id = " . $this->storeScope->id() . "
             )
             GROUP BY s.sale_id
             ORDER BY s.sale_date DESC
             LIMIT 10"
        )->fetchAll();
    }

    public function getSalesSummary(): array
    {
        $storeId = $this->storeScope->id();
        $scopeSql = '(u.branch_id = ? OR EXISTS (
            SELECT 1 FROM sale_items scope_si
            JOIN products scope_p ON scope_p.product_id = scope_si.product_id
            WHERE scope_si.sale_id = s.sale_id AND scope_p.branch_id = ?
        ))';
        $total = $this->pdo->prepare('SELECT COALESCE(SUM(s.total_amount), 0) FROM sales s JOIN users u ON u.user_id = s.cashier_id WHERE ' . $scopeSql);
        $total->execute([$storeId, $storeId]);
        $today = $this->pdo->prepare('SELECT COALESCE(SUM(s.total_amount), 0) FROM sales s JOIN users u ON u.user_id = s.cashier_id WHERE ' . $scopeSql . ' AND DATE(s.sale_date) = CURDATE()');
        $today->execute([$storeId, $storeId]);
        return [
            'total_sales' => (float)$total->fetchColumn(),
            'total_sales_today' => (float)$today->fetchColumn(),
        ];
    }
}
