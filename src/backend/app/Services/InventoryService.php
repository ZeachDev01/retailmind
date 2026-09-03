<?php
// app/Services/InventoryService.php

class InventoryService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getLowStockProducts(): array
    {
        $sql = "SELECT p.product_id, p.product_name, p.sku, i.quantity_on_hand, p.reorder_level
                FROM products p
                JOIN inventory i ON p.product_id = i.product_id
                WHERE i.quantity_on_hand <= p.reorder_level";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function getInventorySummary(): array
    {
        return [
            'total_products' => (int)$this->pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
            'current_stock' => (int)$this->pdo->query("SELECT COALESCE(SUM(quantity_on_hand), 0) FROM inventory")->fetchColumn(),
            'low_stock_count' => count($this->getLowStockProducts()),
            'expiring_soon_count' => count($this->getExpiringSoonBatches()),
            'expired_batch_count' => count($this->getExpiredBatches()),
        ];
    }

    public function getExpiringSoonBatches(int $days = 30): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity,
                    pb.expiration_date, pb.date_received, pb.supplier, p.sku, p.product_name
             FROM product_batches pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.remaining_quantity > 0
               AND pb.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY pb.expiration_date ASC, p.product_name ASC"
        );
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }

    public function getExpiredBatches(): array
    {
        return $this->pdo->query(
            "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity,
                    pb.expiration_date, pb.date_received, pb.supplier, p.sku, p.product_name
             FROM product_batches pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.remaining_quantity > 0
               AND pb.expiration_date < CURDATE()
             ORDER BY pb.expiration_date ASC, p.product_name ASC"
        )->fetchAll();
    }

    public function getFefoRecommendations(): array
    {
        return $this->pdo->query(
            "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity,
                    pb.expiration_date, pb.date_received, pb.supplier, p.sku, p.product_name
             FROM product_batches pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.remaining_quantity > 0
               AND (pb.expiration_date IS NULL OR pb.expiration_date >= CURDATE())
             ORDER BY
               CASE WHEN pb.expiration_date IS NULL THEN 1 ELSE 0 END,
               pb.expiration_date ASC,
               pb.date_received ASC,
               p.product_name ASC"
        )->fetchAll();
    }
}
