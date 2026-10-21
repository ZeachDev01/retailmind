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

    /** Assigned roles are authoritative; legacy accounts without mappings use their primary role. */
    public function recipients(string $role): array
    {
        $stmt = $this->pdo->prepare("SELECT np.*, u.* FROM users u
            LEFT JOIN notification_preferences np ON np.user_id = u.user_id
            WHERE u.status = 'active' AND EXISTS (
                SELECT 1 FROM roles r WHERE r.role_name = ? AND (
                    EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.user_id AND ur.role_id = r.role_id)
                    OR (r.role_id = u.role_id AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.user_id))
                ))");
        $stmt->execute([$role]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function checkAndNotifyLowStock(): void
    {
        foreach (['inventory_manager', 'admin'] as $role) {
            foreach ($this->recipients($role) as $user) {
                $threshold = (int)($user['low_stock_threshold'] ?? 0) ?: null;
                $items = [];
                foreach ($this->getLowStockProducts($threshold) as $product) {
                    $out = (int)$product['quantity_on_hand'] <= 0;
                    if ($role === 'admin' && !$out) continue;
                    $target = $threshold ?? $product['reorder_level'];
                    $items[] = new \App\Attention\AttentionItem(
                        ($role === 'admin' ? 'oversight' : 'inventory') . '.stock.' . $product['product_id'],
                        $out ? 'critical' : 'warning', 'inventory',
                        ($out ? 'Out of stock: ' : 'Low Stock Alert: ') . $product['product_name'],
                        "Product {$product['product_name']} ({$product['sku']}) is at or below the threshold. Current: {$product['quantity_on_hand']}, Threshold: {$target}",
                        $this->clock->now(), 'components/inventory_management/products.php?' . http_build_query(['q' => $product['sku']]),
                        null, $role === 'admin', 'low_stock', (int)$product['product_id'], 'product'
                    );
                }
                $inApp = (bool)($user['notify_inapp'] ?? true) && (bool)($user['notify_low_stock'] ?? true);
                $this->synchronize($user, $items, $inApp, ($role === 'admin' ? 'oversight' : 'inventory') . '.stock.');
            }
        }
    }

    public function checkAndNotifyExpiringStock(int $days = 30): void
    {
        $reminders = $this->getEligibleExpiryReminders($days);
        foreach (['inventory_manager', 'admin'] as $role) {
            foreach ($this->recipients($role) as $user) {
                $items = [];
                foreach ($reminders as $reminder) {
                    // Printed expiration dates remain saleable through the end of that Manila day.
                    $overdueAt = new DateTimeImmutable($reminder['expiration_date'] . ' 00:00:00', new DateTimeZone('Asia/Manila'));
                    $overdue = $this->clock->now() >= $overdueAt->modify('+2 days');
                    if ($role === 'admin' && !$overdue) continue;
                    $items[] = new \App\Attention\AttentionItem(
                        ($role === 'admin' ? 'oversight' : 'inventory') . '.expiry.' . $reminder['batch_id'],
                        $reminder['type'] === 'expired_stock' ? 'critical' : 'warning', 'inventory',
                        ($role === 'admin' ? 'Overdue Supplier Pullout: ' . $reminder['product_name'] : $reminder['title']),
                        $reminder['message'], $this->clock->now(),
                        'components/inventory_management/supplier_pullouts.php?' . http_build_query(['product_id' => $reminder['product_id'], 'batch_id' => $reminder['batch_id']]),
                        null, $role === 'admin', $reminder['type'], (int)$reminder['batch_id'], $reminder['reference_type']
                    );
                }
                $this->synchronize($user, $items, (bool)($user['notify_inapp'] ?? true), ($role === 'admin' ? 'oversight' : 'inventory') . '.expiry.');
            }
        }
    }

    private function synchronize(array $user, array $items, bool $inApp, string $scope): void
    {
        (new \App\Attention\AttentionNotificationService($this->pdo, $this->clock))
            ->synchronize((int)$user['user_id'], $items, $inApp, $scope);
    }

    public function checkAndNotifyForecasts(): void
    {
        $spikes = $this->pdo->query(
            "SELECT p.product_id, p.product_name, sp.predicted_demand_next_7_days,
                    COALESCE(hist.avg_weekly, 0) AS avg_weekly
             FROM stock_predictions sp
             JOIN products p ON p.product_id = sp.product_id
             LEFT JOIN (
                 SELECT si.product_id, SUM(si.quantity) / 4 AS avg_weekly
                 FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
                 WHERE s.sale_date >= DATE_SUB(NOW(), INTERVAL 28 DAY)
                 GROUP BY si.product_id
             ) hist ON hist.product_id = sp.product_id
             WHERE sp.prediction_id = (
                 SELECT sp2.prediction_id FROM stock_predictions sp2
                 WHERE sp2.product_id = sp.product_id ORDER BY sp2.generated_at DESC, sp2.prediction_id DESC LIMIT 1
             )
               AND hist.avg_weekly > 0
               AND sp.predicted_demand_next_7_days >= hist.avg_weekly * 1.75"
        )->fetchAll(PDO::FETCH_ASSOC);
        $demandItems = [];
        foreach ($spikes as $spike) {
            $demandItems[] = new \App\Attention\AttentionItem(
                'inventory.demand.' . $spike['product_id'], 'warning', 'inventory',
                'Unusual demand forecast: ' . $spike['product_name'],
                sprintf('The 7-day forecast is %d units compared with a recent weekly average of %.1f units.',
                    (int)$spike['predicted_demand_next_7_days'], (float)$spike['avg_weekly']),
                $this->clock->now(), 'components/inventory_management/replenishment_queue.php',
                null, false, 'system', (int)$spike['product_id'], 'product'
            );
        }
        $conditionNotifications = new \App\Attention\AttentionNotificationService($this->pdo, $this->clock);
        foreach ($this->recipients('inventory_manager') as $recipient) {
            $conditionNotifications->synchronize((int)$recipient['user_id'], $demandItems,
                (bool)($recipient['notify_inapp'] ?? true), 'inventory.demand.');
        }
        $failedRun = $this->pdo->query("SELECT training_run_id, error_message, started_at FROM model_training_runs
            WHERE status = 'failed' AND started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ORDER BY started_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $trainingItems = $failedRun ? [new \App\Attention\AttentionItem(
            'platform-training.failure.' . $failedRun['training_run_id'], 'critical', 'ml_health',
            'Random Forest training failure',
            'The latest model training failed on ' . $failedRun['started_at'] . '. '
                . mb_strimwidth((string)$failedRun['error_message'], 0, 500, '...'),
            $this->clock->now(), 'components/system_administrator/ml_settings.php', null, true,
            'system', (int)$failedRun['training_run_id'], 'model_training'
        )] : [];
        foreach ($this->recipients('super_admin') as $recipient) {
            $conditionNotifications->synchronize((int)$recipient['user_id'], $trainingItems,
                (bool)($recipient['notify_inapp'] ?? true), 'platform-training.');
        }
    }

    /** True only while the account still holds the Workspace's role and is active. */
    public function inWorkspace(int $userId, string $workspace): bool
    {
        return in_array($userId, array_map('intval', array_column($this->recipients($workspace), 'user_id')), true);
    }

    /** Live work remains actionable after its notification is read. */
    public function requiredWork(int $userId, string $workspace): array
    {
        if (!$this->inWorkspace($userId, $workspace)) return [];
        if (in_array($workspace, ['cashier', 'inventory_manager'], true)) {
            $stmt = $this->pdo->prepare("SELECT adjustment_id, reason FROM inventory_adjustments
                WHERE status = ?" . ($workspace === 'cashier' ? ' AND reported_by = ?' : '') . ' ORDER BY reported_at');
            $stmt->execute($workspace === 'cashier' ? ['returned', $userId] : ['pending']);
            return array_map(static fn(array $row): array => [
                'title' => ($workspace === 'cashier' ? 'Returned Stock Issue #' : 'Pending Stock Issue #') . $row['adjustment_id'],
                'message' => $row['reason'],
                'destination' => 'components/' . ($workspace === 'cashier' ? 'cashier' : 'inventory_management') . '/stock_issues.php',
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        $stmt = $this->pdo->prepare("SELECT n.title, n.message, n.attention_destination AS destination
            FROM attention_states s JOIN notifications n ON n.user_id = s.user_id AND n.attention_key = s.attention_key
            WHERE s.user_id = ? AND s.is_active = 1 AND n.workspace = ? AND n.inapp_enabled = 1
                AND s.attention_key <> 'platform.settings.changed'
                AND n.notification_id = (SELECT MAX(n2.notification_id) FROM notifications n2
                    WHERE n2.user_id = n.user_id AND n2.attention_key = n.attention_key)
            ORDER BY n.created_at DESC");
        $stmt->execute([$userId, $workspace]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
               AND n.inapp_enabled = 1 AND n.workspace = 'inventory_manager'
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

    /** Optional-delivery preferences never suppress required-action in-app alerts. */
    public function inAppEnabled(int $userId, string $preferenceKey = 'notify_inapp'): bool
    {
        if (!in_array($preferenceKey, ['notify_inapp', 'notify_low_stock', 'notify_adjustment'], true)) {
            throw new InvalidArgumentException('Unknown notification preference.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM notification_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        $prefs = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($prefs === false) {
            return true;
        }
        return (bool)($prefs['notify_inapp'] ?? 1) && (bool)($prefs[$preferenceKey] ?? 1);
    }

    public function createNotification(int $userId, string $type, string $title, string $message, ?int $referenceId = null, ?string $referenceType = null, string $workspace = 'inventory_manager', bool $inApp = true): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type, workspace, inapp_enabled, email_pending) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$userId, $type, $title, $message, $referenceId, $referenceType, $workspace, (int)$inApp]);
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

    private function getExpiringBatches(int $days, bool $expired): array
    {
        $today = $this->clock->now()->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $diffExpr = "CAST(ROUND(julianday(pb.expiration_date) - julianday(?)) AS INTEGER)";
            if ($expired) {
                $sql = "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
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

            $sql = "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
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
            $sql = "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
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

        $sql = "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
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


}
