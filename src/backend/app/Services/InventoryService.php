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
        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $sql = "SELECT p.product_id, p.product_name, p.sku, i.quantity_on_hand, p.reorder_level
                FROM products p
                JOIN inventory i ON p.product_id = i.product_id
            WHERE i.quantity_on_hand <= p.reorder_level{$scopeSql}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($scopeParams);
        return $stmt->fetchAll();
    }

    public function getInventorySummary(): array
    {
        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $productStmt = $this->pdo->prepare("SELECT COUNT(*) FROM products p WHERE 1 = 1{$scopeSql}");
        $productStmt->execute($scopeParams);
        $stockStmt = $this->pdo->prepare("SELECT COALESCE(SUM(i.quantity_on_hand), 0) FROM inventory i JOIN products p ON p.product_id = i.product_id WHERE 1 = 1{$scopeSql}");
        $stockStmt->execute($scopeParams);
        return [
            'total_products' => (int)$productStmt->fetchColumn(),
            'current_stock' => (int)$stockStmt->fetchColumn(),
            'low_stock_count' => count($this->getLowStockProducts()),
            'expiring_soon_count' => count($this->getExpiringSoonBatches(30)),
            'expired_batch_count' => count($this->getExpiredBatches()),
        ];
    }

    public function getExpiringSoonBatches(int $days = 30): array
    {
        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $stmt = $this->pdo->prepare(
            "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity,
                    pb.expiration_date, pb.date_received, pb.supplier, p.sku, p.product_name
             FROM product_batches pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.remaining_quantity > 0{$scopeSql}
               AND pb.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY pb.expiration_date ASC, p.product_name ASC"
        );
        $stmt->execute(array_merge($scopeParams, [$days]));
        return $stmt->fetchAll();
    }

    public function getExpiredBatches(): array
    {
        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $stmt = $this->pdo->prepare(
            "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity,
                    pb.expiration_date, pb.date_received, pb.supplier, p.sku, p.product_name
             FROM product_batches pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.remaining_quantity > 0{$scopeSql}
               AND pb.expiration_date < CURDATE()
             ORDER BY pb.expiration_date ASC, p.product_name ASC"
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll();
    }

    public function getFefoRecommendations(): array
    {
        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $stmt = $this->pdo->prepare(
            "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity,
                    pb.expiration_date, pb.date_received, pb.supplier, p.sku, p.product_name
             FROM product_batches pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.remaining_quantity > 0{$scopeSql}
               AND (pb.expiration_date IS NULL OR pb.expiration_date >= CURDATE())
             ORDER BY
               CASE WHEN pb.expiration_date IS NULL THEN 1 ELSE 0 END,
               pb.expiration_date ASC,
               pb.date_received ASC,
               p.product_name ASC"
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll();
    }

    private function storeProductScope(): array
    {
        if (function_exists('store_product_scope')) {
            return store_product_scope('p');
        }

        return (new App\Store\StoreScope($this->pdo))->productScope('p');
    }
}
