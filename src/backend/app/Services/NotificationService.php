<?php
// app/Services/NotificationService.php

class NotificationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function checkAndNotifyLowStock(): void
    {
        $usersStmt = $this->pdo->query(
            "SELECT u.user_id, np.low_stock_threshold
             FROM users u
             LEFT JOIN notification_preferences np ON np.user_id = u.user_id
             WHERE COALESCE(np.notify_low_stock, 1) = 1"
        );
        $users = $usersStmt->fetchAll();

        foreach ($users as $user) {
            $threshold = isset($user['low_stock_threshold']) && (int)$user['low_stock_threshold'] > 0
                ? (int)$user['low_stock_threshold']
                : null;
            $lowStock = $this->getLowStockProducts($threshold);

            foreach ($lowStock as $product) {
                $checkStmt = $this->pdo->prepare("SELECT notification_id FROM notifications 
                                                WHERE user_id = ? AND type = 'low_stock' 
                                                AND reference_id = ?
                                                AND DATE(created_at) = CURDATE()");
                $checkStmt->execute([$user['user_id'], $product['product_id']]);

                if (!$checkStmt->fetch()) {
                    $reorderTarget = $threshold !== null ? $threshold : $product['reorder_level'];
                    $this->createNotification(
                        $user['user_id'],
                        'low_stock',
                        'Low Stock Alert: ' . $product['product_name'],
                        "Product {$product['product_name']} ({$product['sku']}) is at or below the threshold. Current: {$product['quantity_on_hand']}, Threshold: {$reorderTarget}",
                        $product['product_id'],
                        'product'
                    );
                }
            }
        }
    }

    public function checkAndNotifyExpiringStock(int $days = 30): void
    {
        $users = $this->getInventoryNotificationUsers();
        if (!$users) {
            return;
        }

        $thresholds = $this->getExpiryAlertThresholds($days);
        $maxDays = max($thresholds);
        foreach ($this->getExpiringBatches($maxDays, false) as $batch) {
            $daysRemaining = (int)$batch['days_remaining'];
            $eligible = array_values(array_filter($thresholds, static fn(int $threshold): bool => $daysRemaining <= $threshold));
            if (!$eligible) {
                continue;
            }
            $threshold = min($eligible);
            foreach ($users as $user) {
                if (!$this->hasBatchThresholdNotification((int)$user['user_id'], (int)$batch['batch_id'], $threshold)) {
                    $this->createNotification(
                        (int)$user['user_id'],
                        'expiring_stock',
                        "Expiry {$threshold}-Day Alert: " . $batch['product_name'],
                        "Batch {$batch['batch_number']} of {$batch['product_name']} ({$batch['sku']}) expires on {$batch['expiration_date']} ({$daysRemaining} day(s) remaining). Stock: {$batch['remaining_quantity']}.",
                        (int)$batch['batch_id'],
                        'product_batch_expiry_' . $threshold
                    );
                }
            }
        }

        foreach ($this->getExpiringBatches($maxDays, true) as $batch) {
            foreach ($users as $user) {
                if (!$this->hasBatchNotificationToday((int)$user['user_id'], 'expired_stock', (int)$batch['batch_id'])) {
                    $this->createNotification(
                        (int)$user['user_id'],
                        'expired_stock',
                        'Expired Stock Blocked: ' . $batch['product_name'],
                        "Batch {$batch['batch_number']} of {$batch['product_name']} ({$batch['sku']}) expired on {$batch['expiration_date']}. Remaining: {$batch['remaining_quantity']}.",
                        (int)$batch['batch_id'],
                        'product_batch'
                    );
                }
            }
        }
    }

    private function getExpiryAlertThresholds(int $fallbackDays): array
    {
        $value = '';
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM store_settings WHERE setting_key='expiry_alert_days'");
            $stmt->execute();
            $value = (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $value = '';
        }
        $thresholds = array_values(array_unique(array_filter(array_map(
            static fn(string $part): int => max(0, min(365, (int)trim($part))),
            explode(',', $value !== '' ? $value : '90,60,30,14,7')
        ), static fn(int $day): bool => $day > 0)));
        if (!$thresholds) {
            $thresholds = [max(1, $fallbackDays)];
        }
        rsort($thresholds, SORT_NUMERIC);
        return $thresholds;
    }

    public function createNotification(int $userId, string $type, string $title, string $message, ?int $referenceId = null, ?string $referenceType = null): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $type, $title, $message, $referenceId, $referenceType]);
    }

    private function getLowStockProducts(?int $threshold = null): array
    {
        $sql = "SELECT p.product_id, p.product_name, p.sku, i.quantity_on_hand, p.reorder_level
                FROM products p
                JOIN inventory i ON p.product_id = i.product_id";
        $products = $this->pdo->query($sql)->fetchAll();

        if ($threshold !== null && $threshold >= 0) {
            return array_values(array_filter($products, function ($product) use ($threshold): bool {
                return (int)$product['quantity_on_hand'] <= $threshold;
            }));
        }

        return array_values(array_filter($products, function ($product): bool {
            return (int)$product['quantity_on_hand'] <= (int)$product['reorder_level'];
        }));
    }

    private function getInventoryNotificationUsers(): array
    {
        return $this->pdo->query(
            "SELECT DISTINCT u.user_id
             FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'active'
               AND r.role_name IN ('super_admin', 'admin', 'inventory_manager')"
        )->fetchAll();
    }

    private function getExpiringBatches(int $days, bool $expired): array
    {
        $dateFilter = $expired
            ? 'pb.expiration_date < CURDATE()'
            : 'pb.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)';

        $stmt = $this->pdo->prepare(
            "SELECT pb.batch_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
                    DATEDIFF(pb.expiration_date, CURDATE()) AS days_remaining, p.sku, p.product_name
             FROM product_batches pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.remaining_quantity > 0
               AND {$dateFilter}
             ORDER BY pb.expiration_date ASC, p.product_name ASC"
        );
        $expired ? $stmt->execute() : $stmt->execute([$days]);
        return $stmt->fetchAll();
    }


    private function hasBatchThresholdNotification(int $userId, int $batchId, int $threshold): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT notification_id FROM notifications
             WHERE user_id=? AND type='expiring_stock' AND reference_id=? AND reference_type=? LIMIT 1"
        );
        $stmt->execute([$userId, $batchId, 'product_batch_expiry_' . $threshold]);
        return (bool)$stmt->fetch();
    }

    private function hasBatchNotificationToday(int $userId, string $type, int $batchId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT notification_id
             FROM notifications
             WHERE user_id = ?
               AND type = ?
               AND reference_id = ?
               AND reference_type = 'product_batch'
               AND DATE(created_at) = CURDATE()"
        );
        $stmt->execute([$userId, $type, $batchId]);
        return (bool)$stmt->fetch();
    }
}
