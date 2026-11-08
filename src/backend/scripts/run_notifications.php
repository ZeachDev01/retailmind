<?php
// Run daily from Task Scheduler/cron to create and email operational alerts.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $exception): void {
    error_log((string)$exception);
    fwrite(STDERR, 'Daily notification checks failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
});
$pdo = App\Core\Database::connection();
require_once dirname(__DIR__) . '/includes/functions.php';

$clock ??= new App\Attention\SystemClock();
(new App\Authorization\DormancyRunner($pdo, $clock))->run();

require_once dirname(__DIR__) . '/app/Services/NotificationService.php';
$notifications = new NotificationService($pdo, $clock);
$notifications->checkAndNotifyLowStock();
$notifications->checkAndNotifyExpiringStock();

require_once dirname(__DIR__) . '/app/Services/SystemHealthService.php';
$healthChecks = (new SystemHealthService($pdo, dirname(__DIR__)))->checks();
$attentionCenter = new App\Attention\AttentionCenter($pdo, $clock);
foreach (['super_admin', 'admin'] as $role) {
    $external = $role === 'super_admin' ? $healthChecks : [];
    foreach ($notifications->recipients($role) as $recipient) {
        $attentionCenter->synchronize(
            (int)$recipient['user_id'],
            $role,
            $external,
            (bool)($recipient['notify_inapp'] ?? true)
        );
    }
}

$notifications->checkAndNotifyForecasts();

// Persisted events let transaction checks queue email for the daily run, including email-only accounts.
// Only a genuinely new or escalated condition is queued, so an unchanged rerun has nothing pending.
$emailDeliveryDisabled = strtolower((string)env('MAIL_MAILER', '')) === 'disabled';
$storeSettings = get_store_settings($pdo);
$storeEmailEnabled = !empty($storeSettings['store_email_alerts_enabled']);
$storeEmailAddress = trim((string)($storeSettings['store_email'] ?? ''));
$storeEmailTypes = ['low_stock', 'expiring_stock', 'expired_stock'];
if ($storeEmailEnabled && !$emailDeliveryDisabled) {
    // Deliver consolidated Daily Stock Brief to Inventory Managers and Store Email
    require_once dirname(__DIR__) . '/app/Services/StockBriefService.php';
    try {
        $briefService = new App\Services\StockBriefService($pdo);
        $briefResults = $briefService->dispatch();
        // Clear pending flags for low_stock and expiring items covered by the brief
        $pdo->exec("UPDATE notifications SET email_pending = 0 WHERE email_pending = 1 AND type IN ('low_stock', 'expiring_stock', 'expired_stock')");
    } catch (Throwable $briefError) {
        error_log('Daily Stock Brief dispatch failed: ' . $briefError->getMessage());
    }
} elseif ($storeEmailEnabled) {
    error_log('Store email alerts are enabled but delivery is disabled or store email address is invalid.');
}
foreach (['super_admin', 'admin', 'inventory_manager', 'cashier'] as $role) {
    foreach ($notifications->recipients($role) as $recipient) {
        $pdo->beginTransaction();
        try {
            $pending = $pdo->prepare('SELECT n.notification_id, n.type, n.title, n.message
                FROM notifications n
                LEFT JOIN attention_states s ON s.user_id = n.user_id AND s.attention_key = n.attention_key
                WHERE n.user_id = ? AND n.workspace = ? AND n.email_pending = 1
                  AND (n.attention_key IS NULL OR s.is_active = 1)
                ORDER BY n.notification_id FOR UPDATE');
            $pending->execute([(int)$recipient['user_id'], $role]);
            $clear = $pdo->prepare('UPDATE notifications SET email_pending = 0 WHERE notification_id = ?');
            foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $event) {
                if ($storeEmailEnabled && in_array($event['type'], $storeEmailTypes, true)) {
                    continue;
                }
                $optionalLowStock = $event['type'] === 'low_stock';
                $address = trim((string)($recipient['email'] ?? ''));
                $optedIn = !empty($recipient['notify_email'])
                    && (!$optionalLowStock || (bool)($recipient['notify_low_stock'] ?? true));
                if (!$optedIn || $address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    // Nothing deliverable: stop retrying, but a failed attempt below stays queued.
                    $clear->execute([(int)$event['notification_id']]);
                    continue;
                }
                if (send_email_notification($address, $event['title'], $event['message']) || $emailDeliveryDisabled) {
                    // Failed Brevo sends stay queued for the next run; disabled delivery has nothing to retry.
                    $clear->execute([(int)$event['notification_id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }
}

echo "Notification checks completed.\n";
