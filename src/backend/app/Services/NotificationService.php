<?php
// app/Services/NotificationService.php

class NotificationService
{
    private PDO $pdo;
    private \App\Attention\Clock $clock;
    private ?string $storageDir;

    public function __construct(PDO $pdo, ?\App\Attention\Clock $clock = null, ?string $storageDir = null)
    {
        $this->pdo = $pdo;
        $this->clock = $clock ?? new \App\Attention\SystemClock();
        $this->storageDir = $storageDir;
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
                $this->reconcileStockAlerts((int)$user['user_id'], $role);
                $items = [];
                foreach ($this->getLowStockProducts() as $product) {
                    $out = (int)$product['quantity_on_hand'] <= 0;
                    if ($role === 'admin' && !$out) continue;
                    $target = (int)$product['reorder_level'];
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

    /** Resolve existing stock updates on normal renders, without delivering new updates. */
    public function reconcileStockAlerts(int $userId, string $workspace): void
    {
        if (!in_array($workspace, ['inventory_manager', 'admin'], true) || !$this->inWorkspace($userId, $workspace)) return;
        $stmt = $this->pdo->prepare("SELECT n.notification_id, n.attention_key, n.type, n.reference_id, s.is_active
            FROM notifications n
            LEFT JOIN attention_states s ON s.user_id = n.user_id AND s.attention_key = n.attention_key
            WHERE n.user_id = ? AND n.workspace = ? AND n.resolved_at IS NULL
                AND (n.type = 'low_stock' OR n.type IN ('expiring_stock', 'expired_stock')
                    OR (n.type = 'system' AND n.reference_type LIKE 'product_batch%'))");
        $stmt->execute([$userId, $workspace]);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$alerts) return;
        $lowStock = array_filter($alerts, static fn(array $alert): bool => $alert['type'] === 'low_stock')
            ? array_column($this->getLowStockProducts(), null, 'product_id') : [];
        $eligibleBatches = array_filter($alerts, static fn(array $alert): bool => $alert['type'] !== 'low_stock')
            ? array_column($this->getEligibleExpiryReminders(), null, 'batch_id') : [];
        $resolve = $this->pdo->prepare('UPDATE notifications SET resolved_at = ?, email_pending = 0 WHERE notification_id = ? AND user_id = ? AND resolved_at IS NULL');
        $resolveState = $this->pdo->prepare('UPDATE attention_states SET is_active = 0, resolved_at = ? WHERE user_id = ? AND attention_key = ? AND is_active = 1');
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            foreach ($alerts as $alert) {
                $active = $alert['type'] === 'low_stock'
                    ? isset($lowStock[$alert['reference_id']])
                        && ($workspace !== 'admin' || (int)$lowStock[$alert['reference_id']]['quantity_on_hand'] <= 0)
                    : isset($eligibleBatches[$alert['reference_id']]);
                if ($active && $workspace === 'admin' && $alert['type'] !== 'low_stock') {
                    $active = $this->expiryOverdue($eligibleBatches[$alert['reference_id']]['expiration_date']);
                }
                if (!$active || ($alert['is_active'] !== null && !(bool)$alert['is_active'])) {
                    $resolve->execute([$now, $alert['notification_id'], $userId]);
                    if (!$active) $resolveState->execute([$now, $userId, $alert['attention_key']]);
                }
            }
            if ($ownsTransaction) $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function checkAndNotifyExpiringStock(int $days = 30): void
    {
        $reminders = $this->getEligibleExpiryReminders($days);
        foreach (['inventory_manager', 'admin'] as $role) {
            foreach ($this->recipients($role) as $user) {
                $this->reconcileStockAlerts((int)$user['user_id'], $role);
                $items = [];
                foreach ($reminders as $reminder) {
                    if ($role === 'admin' && !$this->expiryOverdue($reminder['expiration_date'])) continue;
                    $items[] = new \App\Attention\AttentionItem(
                        ($role === 'admin' ? 'oversight' : 'inventory') . '.expiry.' . $reminder['batch_id'],
                        $reminder['type'] === 'expired_stock' ? 'critical' : 'warning', 'inventory',
                        ($role === 'admin' && $reminder['supplier_pullout_eligible'] ? 'Overdue Supplier Pullout: ' . $reminder['product_name'] : $reminder['title']),
                        $reminder['message'], $this->clock->now(),
                        ($reminder['supplier_pullout_eligible']
                            ? 'components/inventory_management/supplier_pullouts.php?' . http_build_query(['product_id' => $reminder['product_id'], 'batch_id' => $reminder['batch_id']])
                            : 'components/' . ($role === 'admin' ? 'administrator' : 'inventory_management') . '/stock_issues.php'),
                        null, $role === 'admin', $reminder['type'], (int)$reminder['batch_id'], $reminder['reference_type']
                    );
                }
                $this->synchronize($user, $items, (bool)($user['notify_inapp'] ?? true), ($role === 'admin' ? 'oversight' : 'inventory') . '.expiry.');
            }
        }
    }

    private function synchronize(array $user, array $items, bool $inApp, string $scope): void
    {
        (new \App\Attention\AttentionCenter($this->pdo, $this->clock, null, $this->storageDir))
            ->synchronizeItems((int)$user['user_id'], $items, $inApp, $scope);
    }

    private function expiryOverdue(string $expirationDate): bool
    {
        // Printed expiration dates remain saleable through the end of that Manila day.
        $expiration = new DateTimeImmutable($expirationDate . ' 00:00:00', new DateTimeZone('Asia/Manila'));
        return $this->clock->now() >= $expiration->modify('+2 days');
    }

    public function checkAndNotifyForecasts(): void
    {
        $spikes = [];
        try {
            $twentyEightDaysAgo = $this->clock->now()->modify('-28 days')->format('Y-m-d H:i:s');
            $spikes = $this->pdo->query(
                "SELECT p.product_id, p.product_name, sp.predicted_demand_next_7_days,
                        COALESCE(hist.avg_weekly, 0) AS avg_weekly
                 FROM stock_predictions sp
                 JOIN products p ON p.product_id = sp.product_id
                 LEFT JOIN (
                     SELECT si.product_id, SUM(si.quantity) / 4 AS avg_weekly
                     FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
                     WHERE s.sale_date >= '{$twentyEightDaysAgo}'
                     GROUP BY si.product_id
                 ) hist ON hist.product_id = sp.product_id
                 WHERE sp.prediction_id = (
                     SELECT sp2.prediction_id FROM stock_predictions sp2
                     WHERE sp2.product_id = sp.product_id ORDER BY sp2.generated_at DESC, sp2.prediction_id DESC LIMIT 1
                 )
                    AND hist.avg_weekly > 0
                    AND sp.predicted_demand_next_7_days >= hist.avg_weekly * 1.75"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {}
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
        $conditionNotifications = new \App\Attention\AttentionCenter($this->pdo, $this->clock, null, $this->storageDir);
        foreach ($this->recipients('inventory_manager') as $recipient) {
            $conditionNotifications->synchronizeItems((int)$recipient['user_id'], $demandItems,
                (bool)($recipient['notify_inapp'] ?? true), 'inventory.demand.');
        }
        $now = $this->clock->now();
        $failedRun = null;
        try {
            $oneDayAgo = $this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s');
            $failedRun = $this->pdo->query("SELECT training_run_id, error_message, started_at FROM model_training_runs
                WHERE status = 'failed' AND started_at >= '{$oneDayAgo}'
                ORDER BY started_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {}
        $trainingItems = $failedRun ? [new \App\Attention\AttentionItem(
            'platform-training.failure.' . $failedRun['training_run_id'], 'critical', 'ml_health',
            'Random Forest training failure',
            'The latest model training failed on ' . $failedRun['started_at'] . '. '
                . mb_strimwidth((string)$failedRun['error_message'], 0, 500, '...'),
            $now, 'components/system_administrator/ml_settings.php', null, true,
            'system', (int)$failedRun['training_run_id'], 'model_training'
        )] : [];
        $readiness = [];
        $eligible = [];
        try {
            require_once __DIR__ . '/../Forecasting/ForecastReadinessQuery.php';
            $readiness = (new \App\Forecasting\ForecastReadinessQuery($this->pdo))->rows();
            $eligible = array_filter($readiness, static fn(array $item): bool => !empty($item['training_eligible']));
            if ($readiness !== [] && $eligible === []) {
                $message = 'No Product is ready for training. Each Product needs '
                    . \App\Forecasting\StoreTrainingEligibilityService::MINIMUM_USABLE_DAYS
                    . ' consecutive usable history days through yesterday.';
                $trainingItems[] = new \App\Attention\AttentionItem(
                    'platform-training.readiness', 'warning', 'ml_health',
                    'Store training is not ready', $message, $now,
                    'components/system_administrator/ml_settings.php', count($readiness), true,
                    'system', null, 'training_readiness'
                );
            }
        } catch (\Throwable $exception) {
            error_log('Training readiness notification skipped: ' . $exception->getMessage());
        }
        $stalledItem = null;
        try {
            $staleBefore = $now->modify('-5 minutes')->format('Y-m-d H:i:s');
            $statement = $this->pdo->prepare(
                "SELECT job_id, attempts FROM ml_jobs
                 WHERE trigger_type IN ('model_retraining', 'forecast_generation')
                   AND status = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at <= ?
                 ORDER BY job_id DESC LIMIT 1"
            );
            $statement->execute([$staleBefore]);
            $stalled = $statement->fetch(PDO::FETCH_ASSOC);
            if ($stalled) {
                $stalledItem = new \App\Attention\AttentionItem(
                    'platform-training.stalled', 'critical', 'ml_health',
                    'Forecasting worker is stalled',
                    (int)$stalled['attempts'] >= 3
                        ? 'The forecasting worker lease expired after the retry limit. Review the training job before retrying.'
                        : 'The forecasting worker lease expired. The job will be retried automatically.',
                    $now, 'components/system_administrator/ml_settings.php', 1, true,
                    'system', (int)$stalled['job_id'], 'ml_job'
                );
                $trainingItems[] = $stalledItem;
            }
        } catch (\Throwable $exception) {
            error_log('Forecasting stall notification skipped: ' . $exception->getMessage());
        }
        foreach ($this->recipients('super_admin') as $recipient) {
            $conditionNotifications->synchronizeItems((int)$recipient['user_id'], $trainingItems,
                (bool)($recipient['notify_inapp'] ?? true), 'platform-training.');
        }
        $attentionCenter = new \App\Attention\AttentionCenter($this->pdo, $this->clock, null, $this->storageDir);
        $adminForecastingItems = array_values(array_filter(
            $attentionCenter->getAttention(0, 'admin'),
            static fn(\App\Attention\AttentionItem $item): bool => str_starts_with($item->key, 'store.forecasting.')
        ));
        if ($readiness !== [] && $eligible === []) {
            $adminForecastingItems[] = new \App\Attention\AttentionItem(
                'store.forecasting.readiness', 'warning', 'forecasting',
                'Store training is not ready',
                'No Product is ready for training. Each Product needs '
                    . \App\Forecasting\StoreTrainingEligibilityService::MINIMUM_USABLE_DAYS
                    . ' consecutive usable history days through yesterday.',
                $now, 'components/system_administrator/ml_settings.php', count($readiness), true,
                'attention', null, 'training_readiness'
            );
        }
        if ($stalledItem !== null) {
            $adminForecastingItems[] = new \App\Attention\AttentionItem(
                'store.forecasting.stalled', 'critical', 'forecasting',
                'Forecasting worker is stalled', $stalledItem->explanation, $now,
                'components/system_administrator/ml_settings.php', 1, true,
                'attention', $stalledItem->referenceId, 'ml_job'
            );
        }
        foreach ($this->recipients('admin') as $recipient) {
            $conditionNotifications->synchronizeItems((int)$recipient['user_id'], $adminForecastingItems,
                (bool)($recipient['notify_inapp'] ?? true), 'store.forecasting.');
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
        $stmt = $this->pdo->prepare("SELECT n.type, n.title, n.message, n.attention_destination AS destination
            FROM attention_states s JOIN notifications n ON n.user_id = s.user_id AND n.attention_key = s.attention_key
            WHERE s.user_id = ? AND s.is_active = 1 AND n.workspace = ? AND n.inapp_enabled = 1
                AND s.attention_key <> 'platform.settings.changed'
                AND s.attention_key NOT LIKE 'oversight.stock.%' AND s.attention_key NOT LIKE 'oversight.expiry.%'
                AND n.notification_id = (SELECT MAX(n2.notification_id) FROM notifications n2
                    WHERE n2.user_id = n.user_id AND n2.attention_key = n.attention_key)
            ORDER BY n.created_at DESC");
        $stmt->execute([$userId, $workspace]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Shared eligibility and wording for transaction checks and scheduled delivery. */
    public function getEligibleExpiryReminders(int $days = 30): array
    {
        return \App\Inventory\StockConditionReader::eligibleExpiryReminders($this->pdo, $this->clock, $days);
    }

    /** Current batch state for existing alerts, independent of read status or history pagination. */
    public function getBatchReminders(int $userId, ?string $todayDate = null): array
    {
        $today = $todayDate ?? $this->clock->now()->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT pb.batch_id, pb.product_id, pb.batch_number, pb.expiration_date,
                    pb.remaining_quantity, p.product_name, p.sku, p.warning_lead_time_days, p.supplier_pullout_eligible
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
                && $daysRemaining <= (int)($batch['warning_lead_time_days'] ?? 30);
            $batch['title'] = ($daysRemaining !== null && $daysRemaining < 0
                ? ($batch['supplier_pullout_eligible'] ? 'Expired bread—waiting for supplier collection: ' : 'Expired Stock Alert: ')
                : ($batch['supplier_pullout_eligible'] ? 'Prepare for supplier collection: ' : 'Expiry Alert: ')) . $batch['product_name'];
            $batch['message'] = "Batch {$batch['batch_number']} (#{$batch['batch_id']}) of {$batch['product_name']} ({$batch['sku']}). Expiration: "
                . ($expiration ?? 'Not recorded') . ". Remaining: {$batch['remaining_quantity']}.";
            $reminders[(int)$batch['batch_id']] = $batch;
        }
        return $reminders;
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

    private function getLowStockProducts(): array
    {
        return \App\Inventory\StockConditionReader::lowStockProductsForAlerts($this->pdo);
    }

    private function getExpiringBatches(int $days, bool $expired): array
    {
        return $expired
            ? \App\Inventory\StockConditionReader::expiredStockBatchesForAlerts($this->pdo, $this->clock)
            : \App\Inventory\StockConditionReader::expiringStockBatchesForAlerts($this->pdo, $this->clock, $days);
    }


}
