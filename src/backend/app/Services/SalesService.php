<?php
// app/Services/SalesService.php

class SalesService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
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
             GROUP BY s.sale_id
             ORDER BY s.sale_date DESC
             LIMIT 10"
        )->fetchAll();
    }

    public function getSalesSummary(): array
    {
        return [
            'total_sales' => (float)$this->pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales")->fetchColumn(),
            'total_sales_today' => (float)$this->pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE DATE(sale_date) = CURDATE()")->fetchColumn(),
        ];
    }
}
