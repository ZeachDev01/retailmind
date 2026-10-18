<?php
// app/Services/NotificationService.php

class NotificationService
{
    private PDO $pdo;
    private \App\Attention\Clock $clock;

    public function __construct(PDO $pdo, ?\App\Attention\Clock $clock = null)
    {
        $this->pdo = $pdo;
        $this->clock = $clock ?? new \App\Attention\SystemClock();
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
        foreach ($this->getEligibleExpiryReminders($days) as $reminder) {
            foreach ($users as $user) {
                $userId = (int)$user['user_id'];
                $batchId = (int)$reminder['batch_id'];
                $exists = $reminder['type'] === 'expired_stock'
                    ? $this->hasBatchNotification($userId, 'expired_stock', $batchId)
                    : $this->hasBatchThresholdNotification($userId, $batchId, $reminder['threshold']);
                if (!$exists) {
                    $this->createNotification($userId, $reminder['type'], $reminder['title'],
                        $reminder['message'], $batchId, $reminder['reference_type']);
                }
            }
        }
    }

    /** Shared eligibility and wording for transaction checks and scheduled delivery. */
    public function getEligibleExpiryReminders(int $days = 30): array
    {
        $thresholds = $this->getExpiryAlertThresholds($days);
        $maxDays = max($thresholds);
        $reminders = [];
        foreach ($this->getExpiringBatches($maxDays, false) as $batch) {
            $daysRemaining = (int)$batch['days_remaining'];
            $leadTime = $batch['warning_lead_time_days'];
            if ($leadTime !== null && $leadTime !== '') {
                if ($daysRemaining > (int)$leadTime) {
                    continue;
                }
                $threshold = (int)$leadTime;
            } else {
                $eligible = array_filter($thresholds, static fn(int $threshold): bool => $daysRemaining <= $threshold);
                if (!$eligible) {
                    continue;
                }
                $threshold = min($eligible);
            }
            $reminders[] = $batch + [
                'type' => 'expiring_stock',
                'threshold' => $threshold,
                'reference_type' => 'product_batch_expiry_' . $threshold,
                'title' => "Expiry {$threshold}-Day Alert: " . $batch['product_name'],
                'message' => "Batch {$batch['batch_number']} of {$batch['product_name']} ({$batch['sku']}) expires on {$batch['expiration_date']} ({$daysRemaining} day(s) remaining). Stock: {$batch['remaining_quantity']}.",
            ];
        }
        foreach ($this->getExpiringBatches($maxDays, true) as $batch) {
            $reminders[] = $batch + [
                'type' => 'expired_stock',
                'reference_type' => 'product_batch',
                'title' => 'Expired stock—waiting for supplier collection: ' . $batch['product_name'],
                'message' => "Batch {$batch['batch_number']} of {$batch['product_name']} ({$batch['sku']}) expired on {$batch['expiration_date']}. Remaining: {$batch['remaining_quantity']}.",
            ];
        }
        return $reminders;
    }

    /** Current batch state for existing alerts, independent of read status or history pagination. */
    public function getBatchReminders(int $userId, ?string $todayDate = null): array
    {
        $today = $todayDate ?? $this->clock->now()->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
        $threshold = max($this->getExpiryAlertThresholds(30));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT pb.batch_id, pb.product_id, pb.batch_number, pb.expiration_date,
                    pb.remaining_quantity, p.product_name, p.sku, p.warning_lead_time_days
             FROM notifications n
             JOIN product_batches pb ON pb.batch_id = n.reference_id
             JOIN products p ON p.product_id = pb.product_id
             WHERE n.user_id = ? AND (n.reference_type = 'product_batch' OR n.reference_type LIKE 'product_batch_expiry_%')
               AND n.type IN ('expiring_stock', 'expired_stock', 'system')
             ORDER BY pb.expiration_date, pb.batch_id"
        );
        $stmt->execute([$userId]);
        $reminders = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $batch) {
            $expiration = $batch['expiration_date'];
            $daysRemaining = $expiration === null ? null : (int)(new DateTimeImmutable($today, new DateTimeZone('Asia/Manila')))
                ->diff(new DateTimeImmutable($expiration, new DateTimeZone('Asia/Manila')))->format('%r%a');
            $batch['active'] = $daysRemaining !== null && (int)$batch['remaining_quantity'] > 0
                && $daysRemaining <= (int)($batch['warning_lead_time_days'] ?? $threshold);
            $batch['title'] = ($daysRemaining !== null && $daysRemaining < 0
                ? 'Expired stock—waiting for supplier collection: ' : 'Prepare for supplier collection: ') . $batch['product_name'];
            $batch['message'] = "Batch {$batch['batch_number']} (#{$batch['batch_id']}) of {$batch['product_name']} ({$batch['sku']}). Expiration: "
                . ($expiration ?? 'Not recorded') . ". Remaining: {$batch['remaining_quantity']}.";
            $reminders[(int)$batch['batch_id']] = $batch;
        }
        return $reminders;
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
             LEFT JOIN notification_preferences np ON np.user_id = u.user_id
             WHERE u.status = 'active'
               AND COALESCE(np.notify_inapp, 1) = 1
               AND r.role_name IN ('super_admin', 'admin', 'inventory_manager')"
        )->fetchAll();
    }

    private function getExpiringBatches(int $days, bool $expired): array
    {
        $today = $this->clock->now()->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $diffExpr = "CAST(ROUND(julianday(pb.expiration_date) - julianday(?)) AS INTEGER)";
            if ($expired) {
                $sql = "SELECT pb.batch_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
                               {$diffExpr} AS days_remaining, p.sku, p.product_name, p.warning_lead_time_days
                        FROM product_batches pb
                        JOIN products p ON p.product_id = pb.product_id
                        WHERE pb.remaining_quantity > 0
                          AND pb.expiration_date IS NOT NULL
                          AND pb.expiration_date < ?
                        ORDER BY pb.expiration_date ASC, p.product_name ASC";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$today, $today]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $sql = "SELECT pb.batch_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
                           {$diffExpr} AS days_remaining, p.sku, p.product_name, p.warning_lead_time_days
                    FROM product_batches pb
                    JOIN products p ON p.product_id = pb.product_id
                    WHERE pb.remaining_quantity > 0
                      AND pb.expiration_date IS NOT NULL
                      AND pb.expiration_date >= ?
                      AND {$diffExpr} <= COALESCE(p.warning_lead_time_days, ?)
                    ORDER BY pb.expiration_date ASC, p.product_name ASC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$today, $today, $today, $days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($expired) {
            $sql = "SELECT pb.batch_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
                           DATEDIFF(pb.expiration_date, ?) AS days_remaining, p.sku, p.product_name, p.warning_lead_time_days
                    FROM product_batches pb
                    JOIN products p ON p.product_id = pb.product_id
                    WHERE pb.remaining_quantity > 0
                      AND pb.expiration_date IS NOT NULL
                      AND pb.expiration_date < ?
                    ORDER BY pb.expiration_date ASC, p.product_name ASC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$today, $today]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $sql = "SELECT pb.batch_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
                       DATEDIFF(pb.expiration_date, ?) AS days_remaining, p.sku, p.product_name, p.warning_lead_time_days
                FROM product_batches pb
                JOIN products p ON p.product_id = pb.product_id
                WHERE pb.remaining_quantity > 0
                  AND pb.expiration_date IS NOT NULL
                  AND pb.expiration_date >= ?
                  AND DATEDIFF(pb.expiration_date, ?) <= COALESCE(p.warning_lead_time_days, ?)
                ORDER BY pb.expiration_date ASC, p.product_name ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$today, $today, $today, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    private function hasBatchNotification(int $userId, string $type, int $batchId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT notification_id
             FROM notifications
             WHERE user_id = ?
               AND type = ?
               AND reference_id = ?
               AND reference_type = 'product_batch' LIMIT 1"
        );
        $stmt->execute([$userId, $type, $batchId]);
        return (bool)$stmt->fetch();
    }
}
