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
               p.product_name ASC
             LIMIT 10"
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

    public function getProductsWithBatchSummaries(?string $todayDate = null): array
    {
        $today = $todayDate ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        [$scopeSql, $scopeParams] = $this->storeProductScope();

        $stmt = $this->pdo->prepare(
            "SELECT p.*, COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand, c.category_name
             FROM products p
             LEFT JOIN inventory i ON p.product_id = i.product_id
             LEFT JOIN categories c ON p.category_id = c.category_id
             WHERE 1 = 1{$scopeSql}
             ORDER BY p.product_name"
        );
        $stmt->execute($scopeParams);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($products)) {
            return [];
        }

        $productIds = array_map('intval', array_column($products, 'product_id'));
        $inPlaceholders = implode(',', array_fill(0, count($productIds), '?'));

        $batchStmt = $this->pdo->prepare(
            "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.quantity, pb.remaining_quantity,
                    pb.expiration_date, pb.date_received, pb.supplier
             FROM product_batches pb
             WHERE pb.product_id IN ({$inPlaceholders})
             ORDER BY
                pb.product_id ASC,
                CASE WHEN pb.expiration_date IS NULL OR pb.expiration_date = '' THEN 1 ELSE 0 END ASC,
                pb.expiration_date ASC,
                pb.date_received ASC,
                pb.batch_id ASC"
        );
        $batchStmt->execute($productIds);

        $batchesByProduct = [];
        while ($batch = $batchStmt->fetch(PDO::FETCH_ASSOC)) {
            $batchesByProduct[(int)$batch['product_id']][] = $batch;
        }

        foreach ($products as &$product) {
            $pid = (int)$product['product_id'];
            $onHand = (int)($product['quantity_on_hand'] ?? 0);
            $batches = $batchesByProduct[$pid] ?? [];

            $totalBatchRemaining = 0;
            $expiredQuantity = 0;
            $undatedQuantity = 0;
            $nextExpiration = null;

            foreach ($batches as $b) {
                $rem = (int)$b['remaining_quantity'];
                if ($rem <= 0) {
                    continue;
                }
                $totalBatchRemaining += $rem;
                $expDate = !empty($b['expiration_date']) ? trim((string)$b['expiration_date']) : null;
                if ($expDate === '') {
                    $expDate = null;
                }

                if ($expDate === null) {
                    $undatedQuantity += $rem;
                } elseif ($expDate < $today) {
                    $expiredQuantity += $rem;
                } else {
                    if ($nextExpiration === null || $expDate < $nextExpiration) {
                        $nextExpiration = $expDate;
                    }
                }
            }

            $product['quantity_on_hand'] = $onHand;
            $product['expired_quantity'] = $expiredQuantity;
            $product['undated_quantity'] = $undatedQuantity;
            $product['next_expiration'] = $nextExpiration;
            $product['has_missing_expiration'] = ($onHand > 0 && $undatedQuantity > 0);
            $product['has_unbatched_stock'] = ($onHand > 0 && $onHand > $totalBatchRemaining);
            $product['unbatched_quantity'] = max(0, $onHand - $totalBatchRemaining);
            $product['total_batch_remaining'] = $totalBatchRemaining;
        }
        unset($product);

        return $products;
    }

    public function getProductBatches(int $productId, ?string $todayDate = null): array
    {
        if ($productId <= 0) {
            return [];
        }

        $today = $todayDate ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

        $hasReceivingTable = true;
        try {
            $this->pdo->query("SELECT 1 FROM stock_receiving LIMIT 1");
        } catch (Throwable) {
            $hasReceivingTable = false;
        }

        $orderBySql = "
            ORDER BY
                CASE
                    WHEN pb.remaining_quantity > 0 AND pb.expiration_date IS NOT NULL AND pb.expiration_date != '' AND pb.expiration_date >= ? THEN 1
                    WHEN pb.remaining_quantity > 0 AND (pb.expiration_date IS NULL OR pb.expiration_date = '') THEN 2
                    WHEN pb.remaining_quantity > 0 THEN 3
                    ELSE 4
                END ASC,
                CASE
                    WHEN pb.remaining_quantity > 0 AND pb.expiration_date IS NOT NULL AND pb.expiration_date != '' THEN pb.expiration_date
                    ELSE pb.date_received
                END ASC,
                pb.batch_id ASC";

        if ($hasReceivingTable) {
            $stmt = $this->pdo->prepare(
                "SELECT
                    pb.*,
                    sr.notes AS receiving_notes,
                    sr.discrepancy_type,
                    sr.discrepancy_notes
                 FROM product_batches pb
                 LEFT JOIN stock_receiving sr ON pb.receiving_id = sr.receiving_id
                 WHERE pb.product_id = ?" . $orderBySql
            );
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT pb.*, NULL AS receiving_notes, NULL AS discrepancy_type, NULL AS discrepancy_notes
                 FROM product_batches pb
                 WHERE pb.product_id = ?" . $orderBySql
            );
        }

        $stmt->execute([$productId, $today]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countBatchIds = [];
        $batchIds = array_column($rows, 'batch_id');
        if (!empty($batchIds)) {
            try {
                $countStmt = $this->pdo->prepare(
                    "SELECT DISTINCT batch_id FROM inventory_count_batches WHERE batch_id IN (" .
                    implode(',', array_fill(0, count($batchIds), '?')) . ")"
                );
                $countStmt->execute($batchIds);
                $countBatchIds = array_fill_keys(array_map('intval', $countStmt->fetchAll(PDO::FETCH_COLUMN)), true);
            } catch (Throwable) {
                // Ignore if table does not exist
            }
        }

        $batches = [];
        foreach ($rows as $row) {
            $batchId = (int)$row['batch_id'];
            $remaining = (int)$row['remaining_quantity'];
            $quantity = (int)($row['quantity'] ?? $remaining);
            $expDate = !empty($row['expiration_date']) ? trim((string)$row['expiration_date']) : null;
            if ($expDate === '') {
                $expDate = null;
            }

            if ($remaining <= 0) {
                $status = 'Depleted';
            } elseif ($expDate !== null && $expDate < $today) {
                $status = 'Expired';
            } else {
                $status = 'Active';
            }

            $daysRemaining = null;
            if ($expDate !== null) {
                try {
                    $expDt = new DateTimeImmutable($expDate, new DateTimeZone('Asia/Manila'));
                    $todayDt = new DateTimeImmutable($today, new DateTimeZone('Asia/Manila'));
                    $diff = $todayDt->diff($expDt);
                    $daysRemaining = (int)$diff->format('%r%a');
                } catch (Throwable) {
                    $daysRemaining = null;
                }
            }

            $batchNumLower = strtolower((string)($row['batch_number'] ?? ''));
            $supplierLower = strtolower((string)($row['supplier'] ?? ''));
            $receivingNotesLower = strtolower((string)($row['receiving_notes'] ?? ''));
            $discrepancyTypeLower = strtolower((string)($row['discrepancy_type'] ?? ''));
            $discrepancyNotesLower = strtolower((string)($row['discrepancy_notes'] ?? ''));

            if (
                str_contains($batchNumLower, 'replace') ||
                str_contains($supplierLower, 'replace') ||
                str_contains($receivingNotesLower, 'replace') ||
                str_contains($discrepancyTypeLower, 'replace') ||
                str_contains($discrepancyNotesLower, 'replace')
            ) {
                $source = 'Replacement';
            } elseif (
                isset($countBatchIds[$batchId]) ||
                str_contains($batchNumLower, 'count') ||
                str_contains($batchNumLower, 'correct') ||
                str_contains($batchNumLower, 'adj')
            ) {
                $source = 'Count Correction';
            } elseif (!empty($row['receiving_id'])) {
                $source = 'Received Delivery';
            } else {
                $source = 'Opening Stock';
            }

            $returnUrl = null;
            if ($remaining > 0) {
                $relUrl = 'components/inventory_management/supplier_pullouts.php?product_id=' . $productId . '&batch_id=' . $batchId;
                $returnUrl = function_exists('app_url') ? app_url($relUrl) : $relUrl;
            }

            $batches[] = [
                'batch_id' => $batchId,
                'product_id' => $productId,
                'batch_number' => !empty($row['batch_number']) ? (string)$row['batch_number'] : ('#' . $batchId),
                'quantity' => $quantity,
                'remaining_quantity' => $remaining,
                'expiration_date' => $expDate,
                'expiration_label' => $expDate ?? 'Not recorded',
                'date_received' => $row['date_received'] ?? null,
                'supplier' => $row['supplier'] ?? null,
                'source' => $source,
                'status' => $status,
                'days_remaining' => $daysRemaining,
                'return_to_supplier_url' => $returnUrl,
            ];
        }

        return $batches;
    }
}
