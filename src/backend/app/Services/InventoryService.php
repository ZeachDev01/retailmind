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

    public function getExpiringSoonBatches(int $days = 30, ?string $todayDate = null): array
    {
        $today = $todayDate ?? \App\Services\PhilippineTime::today();
        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $diffExpr = "CAST(ROUND(julianday(pb.expiration_date) - julianday(?)) AS INTEGER)";
            $sql = "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity,
                           pb.expiration_date, pb.date_received, pb.supplier, p.sku, p.product_name,
                           p.warning_lead_time_days,
                           {$diffExpr} AS days_remaining
                    FROM product_batches pb
                    JOIN products p ON p.product_id = pb.product_id
                    WHERE pb.remaining_quantity > 0{$scopeSql}
                      AND pb.expiration_date IS NOT NULL
                      AND {$diffExpr} >= 0
                      AND {$diffExpr} <= COALESCE(p.warning_lead_time_days, ?)
                    ORDER BY pb.expiration_date ASC, p.product_name ASC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge([$today], $scopeParams, [$today, $today, $days]));
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $this->pdo->prepare(
            "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity,
                    pb.expiration_date, pb.date_received, pb.supplier, p.sku, p.product_name,
                    p.warning_lead_time_days,
                    DATEDIFF(pb.expiration_date, ?) AS days_remaining
             FROM product_batches pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.remaining_quantity > 0{$scopeSql}
               AND pb.expiration_date IS NOT NULL
               AND DATEDIFF(pb.expiration_date, ?) >= 0
               AND DATEDIFF(pb.expiration_date, ?) <= COALESCE(p.warning_lead_time_days, ?)
             ORDER BY pb.expiration_date ASC, p.product_name ASC"
        );
        $stmt->execute(array_merge([$today], $scopeParams, [$today, $today, $days]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExpiredBatches(?string $todayDate = null): array
    {
        $today = $todayDate ?? \App\Services\PhilippineTime::today();
        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $stmt = $this->pdo->prepare(
            "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity,
                    pb.expiration_date, pb.date_received, pb.supplier, p.sku, p.product_name,
                    p.warning_lead_time_days
             FROM product_batches pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.remaining_quantity > 0{$scopeSql}
               AND pb.expiration_date IS NOT NULL
               AND pb.expiration_date < ?
             ORDER BY pb.expiration_date ASC, p.product_name ASC"
        );
        $stmt->execute(array_merge($scopeParams, [$today]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFefoRecommendations(?string $todayDate = null): array
    {
        $today = $todayDate ?? \App\Services\PhilippineTime::today();
        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $stmt = $this->pdo->prepare(
            "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity,
                    pb.expiration_date, pb.date_received, pb.supplier, p.sku, p.product_name,
                    p.warning_lead_time_days
             FROM product_batches pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.remaining_quantity > 0{$scopeSql}
               AND (pb.expiration_date IS NULL OR pb.expiration_date >= ?)
             ORDER BY
               CASE WHEN pb.expiration_date IS NULL THEN 1 ELSE 0 END,
               pb.expiration_date ASC,
               pb.date_received ASC,
               p.product_name ASC
             LIMIT 10"
        );
        $stmt->execute(array_merge($scopeParams, [$today]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        $today = $todayDate ?? \App\Services\PhilippineTime::today();
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
                    pb.sealed_quantity, pb.loose_quantity,
                    pb.expiration_date, pb.date_received, pb.supplier
             FROM product_batches pb
             WHERE pb.product_id IN ({$inPlaceholders})
             ORDER BY
                pb.product_id ASC,
                CASE WHEN pb.expiration_date IS NULL THEN 1 ELSE 0 END ASC,
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
            $physicalSealedPacks = 0;
            $physicalLoosePieces = 0;
            $saleableSealedPacks = 0;
            $saleableLoosePieces = 0;
            $expiredSealedPacks = 0;
            $expiredLoosePieces = 0;

            foreach ($batches as $b) {
                $rem = (int)$b['remaining_quantity'];
                if ($rem <= 0) {
                    continue;
                }
                $totalBatchRemaining += $rem;
                $sealed = (int)($b['sealed_quantity'] ?? 0);
                $loose = (int)($b['loose_quantity'] ?? 0);
                if ($sealed === 0 && $loose === 0) {
                    $loose = $rem;
                }
                $physicalSealedPacks += $sealed;
                $physicalLoosePieces += $loose;

                $expDate = !empty($b['expiration_date']) ? trim((string)$b['expiration_date']) : null;
                if ($expDate === '') {
                    $expDate = null;
                }

                if ($expDate === null) {
                    $undatedQuantity += $rem;
                    $saleableSealedPacks += $sealed;
                    $saleableLoosePieces += $loose;
                } elseif ($expDate < $today) {
                    $expiredQuantity += $rem;
                    $expiredSealedPacks += $sealed;
                    $expiredLoosePieces += $loose;
                } else {
                    $saleableSealedPacks += $sealed;
                    $saleableLoosePieces += $loose;
                    if ($nextExpiration === null || $expDate < $nextExpiration) {
                        $nextExpiration = $expDate;
                    }
                }
            }

            if ($onHand > $totalBatchRemaining) {
                $unbatched = $onHand - $totalBatchRemaining;
                $physicalLoosePieces += $unbatched;
                $saleableLoosePieces += $unbatched;
            }

            $product['quantity_on_hand'] = $onHand;
            $product['expired_quantity'] = $expiredQuantity;
            $product['undated_quantity'] = $undatedQuantity;
            $product['next_expiration'] = $nextExpiration;
            $product['has_missing_expiration'] = ($onHand > 0 && $undatedQuantity > 0);
            $product['has_unbatched_stock'] = ($onHand > 0 && $onHand > $totalBatchRemaining);
            $product['unbatched_quantity'] = max(0, $onHand - $totalBatchRemaining);
            $product['total_batch_remaining'] = $totalBatchRemaining;
            $product['physical_sealed_packs'] = $physicalSealedPacks;
            $product['physical_loose_pieces'] = $physicalLoosePieces;
            $product['saleable_sealed_packs'] = $saleableSealedPacks;
            $product['saleable_loose_pieces'] = $saleableLoosePieces;
            $product['expired_sealed_packs'] = $expiredSealedPacks;
            $product['expired_loose_pieces'] = $expiredLoosePieces;
        }
        unset($product);

        return $products;
    }

    public function getProductBatches(int $productId, ?string $todayDate = null): array
    {
        if ($productId <= 0) {
            return [];
        }

        $today = $todayDate ?? \App\Services\PhilippineTime::today();

        $hasReceivingTable = true;
        $hasPulloutId = true;
        try {
            $this->pdo->query("SELECT pullout_id FROM stock_receiving LIMIT 1");
        } catch (Throwable) {
            $hasPulloutId = false;
            try {
                $this->pdo->query("SELECT 1 FROM stock_receiving LIMIT 1");
            } catch (Throwable) {
                $hasReceivingTable = false;
            }
        }

        $orderBySql = "
            ORDER BY
                CASE
                    WHEN pb.remaining_quantity > 0 AND pb.expiration_date IS NOT NULL AND pb.expiration_date >= ? THEN 1
                    WHEN pb.remaining_quantity > 0 AND pb.expiration_date IS NULL THEN 2
                    WHEN pb.remaining_quantity > 0 THEN 3
                    ELSE 4
                END ASC,
                CASE
                    WHEN pb.remaining_quantity > 0 AND pb.expiration_date IS NOT NULL THEN pb.expiration_date
                    ELSE pb.date_received
                END ASC,
                pb.batch_id ASC";

        if ($hasReceivingTable) {
            $pulloutExpr = $hasPulloutId ? 'sr.pullout_id' : 'NULL AS pullout_id';
            $stmt = $this->pdo->prepare(
                "SELECT
                    pb.*,
                    sr.notes AS receiving_notes,
                    sr.discrepancy_type,
                    sr.discrepancy_notes,
                    {$pulloutExpr}
                 FROM product_batches pb
                 LEFT JOIN stock_receiving sr ON pb.receiving_id = sr.receiving_id
                 WHERE pb.product_id = ?" . $orderBySql
            );
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT pb.*, NULL AS receiving_notes, NULL AS discrepancy_type, NULL AS discrepancy_notes, NULL AS pullout_id
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

            $pulloutId = !empty($row['pullout_id']) ? (int)$row['pullout_id'] : null;

            if (
                $pulloutId !== null ||
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
                'sealed_quantity' => (int)($row['sealed_quantity'] ?? 0),
                'loose_quantity' => (int)($row['loose_quantity'] ?? $remaining),
                'expiration_date' => $expDate,
                'expiration_label' => $expDate ?? 'Not recorded',
                'date_received' => $row['date_received'] ?? null,
                'supplier' => $row['supplier'] ?? null,
                'source' => $source,
                'status' => $status,
                'days_remaining' => $daysRemaining,
                'return_to_supplier_url' => $returnUrl,
                'pullout_id' => $pulloutId,
            ];
        }

        return $batches;
    }
}
