<?php
// Contract for shared live attention evaluation, threshold ownership, and notification synchronization.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Attention\AttentionCenter;
use App\Attention\AttentionNotificationService;
use App\Attention\AttentionRuleService;
use App\Attention\AttentionSettingsService;
use App\Attention\Clock;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

final class AttentionContractClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE attention_settings (
        setting_scope VARCHAR(20) NOT NULL,
        setting_key VARCHAR(100) NOT NULL,
        setting_value VARCHAR(100) NOT NULL,
        updated_by INTEGER NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (setting_scope, setting_key)
    )');
    $pdo->exec('CREATE TABLE attention_states (
        user_id INTEGER NOT NULL,
        attention_key VARCHAR(120) NOT NULL,
        fingerprint VARCHAR(64) NOT NULL,
        is_active INTEGER NOT NULL,
        first_detected_at DATETIME NOT NULL,
        last_detected_at DATETIME NOT NULL,
        resolved_at DATETIME NULL,
        PRIMARY KEY (user_id, attention_key)
    )');
    $pdo->exec('CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        reference_id INTEGER NULL,
        reference_type VARCHAR(100) NULL,
        attention_key VARCHAR(120) NULL,
        attention_severity VARCHAR(20) NULL,
        attention_count INTEGER NULL,
        attention_destination VARCHAR(255) NULL,
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL
    )');

    $clock = new AttentionContractClock(new DateTimeImmutable('2026-09-18 09:30:00'));
    $settings = new AttentionSettingsService($pdo, $clock);

    try {
        $settings->updatePlatform('admin', ['failed_login_count' => 20], 7);
        $assert(false, 'Administrator must not govern Platform attention thresholds');
    } catch (DomainException $exception) {
        $assert(true, 'Platform ownership is enforced');
    }
    $settings->updatePlatform('super_admin', [
        'failed_login_count' => 4,
        'store_cash_variance_amount_max' => 500,
        'store_unusual_discount_percent_max' => 35,
    ], 1);
    $settings->updateStore('admin', [
        'cash_variance_amount' => 900,
        'unusual_discount_percent' => 50,
        'reversal_count' => 2,
    ], 7);
    $storeThresholds = $settings->thresholdsFor('admin');
    $assert($storeThresholds['cash_variance_amount'] === 500.0, 'Store cash threshold should be capped by the Platform safety limit');
    $assert($storeThresholds['unusual_discount_percent'] === 35.0, 'Store discount threshold should be capped by the Platform safety limit');
    $assert($storeThresholds['reversal_count'] === 2.0, 'Permitted Store threshold should remain Administrator-owned');

    $rules = new AttentionRuleService($settings, $clock);
    $notifications = new AttentionNotificationService($pdo, $clock);
    $center = new AttentionCenter($rules, $notifications);

    $platformSignals = [
        'failed_login_count' => ['value' => 6, 'count' => 6, 'detected_at' => '2026-09-18 09:10:00'],
        'backup_age_days' => ['value' => 9, 'detected_at' => '2026-09-18 08:00:00'],
        'service_unhealthy_count' => ['value' => 1, 'count' => 1],
        'ml_model_age_days' => ['value' => 10],
        'platform_setting_change_count' => ['value' => 1, 'count' => 1],
        'emergency_access_count' => ['value' => 1, 'count' => 1],
        'recovery_account_active_count' => ['value' => 1, 'count' => 1],
    ];
    $platformItems = $center->refresh(1, 'super_admin', $platformSignals);
    $platformKeys = array_column($platformItems, 'key');
    $assert($platformItems[0]['severity'] === 'critical', 'Critical platform attention should sort before warnings');
    $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
    for ($index = 1; $index < count($platformItems); $index++) {
        $assert($severityOrder[$platformItems[$index - 1]['severity']] <= $severityOrder[$platformItems[$index]['severity']], 'Attention items should remain severity ordered');
    }
    $assert(in_array('platform.backup.stale', $platformKeys, true), 'Stale backup should produce recovery attention');
    $assert(in_array('platform.service.unhealthy', $platformKeys, true), 'Service health should produce attention');
    $assert(in_array('platform.ml.stale-model', $platformKeys, true), 'ML health should produce attention');
    $assert(in_array('platform.settings.changed', $platformKeys, true), 'Platform Setting changes should produce attention');
    $assert(in_array('platform.emergency-access.active', $platformKeys, true), 'Emergency Access should produce attention');
    $assert(in_array('platform.recovery-account.active', $platformKeys, true), 'Recovery Account state should produce attention');
    foreach ($platformItems as $item) {
        $assert(isset($item['key'], $item['severity'], $item['category'], $item['title'], $item['explanation'], $item['detected_at'], $item['destination']), 'Every attention item should expose the stable contract');
        $assert(str_starts_with($item['destination'], 'components/'), 'Attention destinations must be application-relative permitted routes');
    }
    $firstNotificationCount = (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    $laterPlatformSignals = $platformSignals;
    foreach ($laterPlatformSignals as &$signal) {
        $signal['detected_at'] = '2026-09-18 10:00:00';
    }
    unset($signal);
    $refreshedPlatformItems = $center->refresh(1, 'super_admin', $laterPlatformSignals);
    $refreshedByKey = array_column($refreshedPlatformItems, null, 'key');
    $assert($refreshedByKey['platform.security.failed-logins']['detected_at'] === '2026-09-18 09:10:00', 'An unchanged condition should retain its first detected time');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $firstNotificationCount, 'Unchanged active conditions must not create duplicate notifications');

    $storeSignals = [
        'reversal_count' => ['value' => 3, 'count' => 3],
        'max_discount_percent' => ['value' => 40, 'count' => 2],
        'cash_variance_amount' => ['value' => 600, 'count' => 1],
        'oldest_approval_hours' => ['value' => 30, 'count' => 4],
        'inventory_escalated_count' => ['value' => 2, 'count' => 2],
        'inventory_routine_count' => ['value' => 99, 'count' => 99],
        'fiscal_period_overdue_count' => ['value' => 1, 'count' => 1],
    ];
    $storeItems = $center->refresh(7, 'admin', $storeSignals);
    $storeKeys = array_column($storeItems, 'key');
    $assert(in_array('store.sales.reversals', $storeKeys, true), 'Reversals should produce Store attention');
    $assert(in_array('store.sales.unusual-discounts', $storeKeys, true), 'Unusual discounts should produce Store attention');
    $assert(in_array('store.cash.variance', $storeKeys, true), 'Cash variance should produce Store attention');
    $assert(in_array('store.approvals.overdue', $storeKeys, true), 'Overdue approvals should produce Store attention');
    $assert(in_array('store.inventory.escalated', $storeKeys, true), 'Escalated inventory risk should produce Store attention');
    $assert(in_array('store.fiscal-period.overdue', $storeKeys, true), 'Fiscal Period problems should produce Store attention');
    $assert(count(array_filter($storeKeys, static fn(string $key): bool => str_contains($key, 'routine'))) === 0, 'Routine low-level inventory work must remain excluded');
    $assert(!array_intersect($platformKeys, $storeKeys), 'Role visibility should keep platform conditions out of Administrator results');

    $notificationRows = $pdo->query("SELECT attention_key, attention_severity, attention_count, attention_destination FROM notifications WHERE user_id = 7 ORDER BY notification_id")->fetchAll(PDO::FETCH_ASSOC);
    $assert(array_column($notificationRows, 'attention_key') === $storeKeys, 'Notifications and dashboard-ready results should use the same ordered stable keys');
    foreach ($notificationRows as $index => $notification) {
        $assert($notification['attention_severity'] === $storeItems[$index]['severity'], 'Notification severity should come from the shared evaluated item');
        $assert((int)$notification['attention_count'] === (int)($storeItems[$index]['count'] ?? 0), 'Notification count should come from the shared evaluated item');
        $assert($notification['attention_destination'] === $storeItems[$index]['destination'], 'Notification destination should come from the shared evaluated item');
    }

    $emailOnly = $center->refreshResult(11, 'admin', $storeSignals, false);
    $assert(count($emailOnly->newItems()) === count($storeItems), 'Email-only recipients should receive each newly active attention item');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 11')->fetchColumn() === 0, 'Email-only evaluation should not create in-app notifications');
    $emailOnlyRepeat = $center->refreshResult(11, 'admin', $storeSignals, false);
    $assert($emailOnlyRepeat->newItems() === [], 'Email-only attention should use the same deduplication state');

    $resolved = $center->refresh(7, 'admin', []);
    $assert($resolved === [], 'Resolved live conditions should disappear without a manual lifecycle');
    $activeStates = (int)$pdo->query('SELECT COUNT(*) FROM attention_states WHERE user_id = 7 AND is_active = 1')->fetchColumn();
    $assert($activeStates === 0, 'Resolved conditions should be marked inactive for future reactivation');

    $pdo->exec("CREATE TRIGGER fail_attention_state BEFORE INSERT ON attention_states BEGIN SELECT RAISE(ABORT, 'state failure'); END");
    try {
        $center->refresh(99, 'admin', ['reversal_count' => ['value' => 3, 'count' => 3]]);
        $assert(false, 'Attention synchronization should surface persistence failures');
    } catch (PDOException $exception) {
        $assert((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 99')->fetchColumn() === 0, 'Notification and deduplication state must commit atomically');
    }
    $pdo->exec('DROP TRIGGER fail_attention_state');
} catch (Throwable $exception) {
    $failures[] = 'Attention rules contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Attention rules contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Attention rules contract: passed\n";
