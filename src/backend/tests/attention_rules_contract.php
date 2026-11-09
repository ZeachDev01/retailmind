<?php
// Contract for shared live attention evaluation, threshold ownership, and notification synchronization.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Attention\AttentionCenter;
use App\Attention\AttentionItem;
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
        notification_level INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL,
        first_detected_at DATETIME NOT NULL,
        last_detected_at DATETIME NOT NULL,
        resolved_at DATETIME NULL,
        PRIMARY KEY (user_id, attention_key)
    )');
    $pdo->exec('CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        workspace TEXT NOT NULL DEFAULT "",
        inapp_enabled INTEGER NOT NULL DEFAULT 1,
        email_pending INTEGER NOT NULL DEFAULT 0,
        resolved_at DATETIME NULL,
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
        'store_inventory_overdue_hours_max' => 72,
    ], 1);
    $settings->updateStore('admin', [
        'cash_variance_amount' => 900,
        'inventory_overdue_hours' => 24,
    ], 7);
    $storeThresholds = $settings->thresholdsFor('admin');
    $assert($storeThresholds['cash_variance_amount'] === 500.0, 'Store cash threshold should be capped by the Platform safety limit');
    $assert($storeThresholds['inventory_overdue_hours'] === 24.0, 'Store overdue hours threshold should be Administrator-owned');

    $notifications = new AttentionCenter($pdo, $clock, $settings);
    $center = new AttentionCenter($pdo, $clock, $settings);

    $pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, status TEXT, locked_until TEXT);
        CREATE TABLE login_attempts (attempt_id INTEGER PRIMARY KEY, was_successful INTEGER, attempted_at TEXT);
        CREATE TABLE activity_log (record_id INTEGER, category TEXT, action TEXT, created_at TEXT);
        CREATE TABLE backup_history (status TEXT, backup_type TEXT, created_at TEXT);
        CREATE TABLE model_training_runs (training_run_id INTEGER, status TEXT, completed_at TEXT, model_version TEXT);
        CREATE TABLE recovery_accounts (user_id INTEGER, sealed_at TEXT);
        CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, status TEXT, reviewed_at TEXT, cash_variance REAL);
        CREATE TABLE products (product_id INTEGER PRIMARY KEY, cost_price REAL);
        CREATE TABLE inventory_adjustments (adjustment_id INTEGER PRIMARY KEY, product_id INTEGER, adjustment_qty REAL, reported_at TEXT, status TEXT);
        CREATE TABLE fiscal_periods (status TEXT, end_date TEXT);
        INSERT INTO users VALUES (1,'active',NULL);
        INSERT INTO recovery_accounts VALUES (1,NULL);
        INSERT INTO backup_history VALUES ('completed','manual','2026-09-09 08:00:00');
        INSERT INTO model_training_runs VALUES (1,'completed','2026-09-08 08:00:00',NULL);
        INSERT INTO activity_log VALUES (1,'platform_setting','Updated settings','2026-09-18 09:00:00')");
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $pdo->exec("INSERT INTO login_attempts VALUES ($attempt,0,'2026-09-18 09:10:00')");
    }
    $healthChecks = [['status' => 'critical', 'category' => 'Database']];
    $platformItems = $center->synchronize(1, 'super_admin', $healthChecks)->dashboardItems();
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
    $assert(in_array('platform.recovery-account.active', $platformKeys, true), 'Recovery Account state should produce attention');
    foreach ($platformItems as $item) {
        $assert(isset($item['key'], $item['severity'], $item['category'], $item['title'], $item['explanation'], $item['detected_at'], $item['destination']), 'Every attention item should expose the stable contract');
        $assert(str_starts_with($item['destination'], 'components/'), 'Attention destinations must be application-relative permitted routes');
    }
    $firstNotificationCount = (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    $refreshedPlatformItems = $center->synchronize(1, 'super_admin', $healthChecks)->dashboardItems();
    $refreshedByKey = array_column($refreshedPlatformItems, null, 'key');
    $assert($refreshedByKey['platform.security.failed-logins']['detected_at'] === $platformItems[array_search('platform.security.failed-logins', $platformKeys)]['detected_at'], 'An unchanged condition should retain its first detected time');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $firstNotificationCount, 'Unchanged active conditions must not create duplicate notifications');

    // A failed Scheduled Backup must raise one platform attention condition (#460).
    $assert(!in_array('platform.backup.scheduled-failure', $platformKeys, true), 'No Scheduled Backup failure should be active before one occurs');
    $assert(
        ($settings->thresholdsFor('super_admin')['scheduled_backup_failure_count'] ?? null) === 1.0,
        'Scheduled Backup failure attention should default to a threshold of one'
    );
    $pdo->exec("INSERT INTO backup_history VALUES ('failed','scheduled','2026-09-18 09:00:00')");
    $scheduledFailureItems = $center->getAttention(1, 'super_admin', $healthChecks);
    $scheduledFailure = null;
    foreach ($scheduledFailureItems as $item) {
        if ($item->key === 'platform.backup.scheduled-failure') {
            $scheduledFailure = $item;
        }
    }
    $assert($scheduledFailure !== null, 'A failed Scheduled Backup should raise platform attention');
    if ($scheduledFailure !== null) {
        $assert($scheduledFailure->severity === 'critical', 'A failed Scheduled Backup should be critical');
        $assert($scheduledFailure->category === 'backup_recovery', 'A failed Scheduled Backup should stay in backup_recovery');
        $assert($scheduledFailure->destination === 'components/system_administrator/backup_restore.php', 'The Scheduled Backup failure should link to Backup & Restore');
        $assert(str_contains($scheduledFailure->explanation, 'Scheduled Backup'), 'The explanation should name the Scheduled Backup');
        $assert(!str_contains($scheduledFailure->explanation, ':\\') && !str_contains($scheduledFailure->explanation, '/'), 'The explanation must not expose filesystem paths');
    }
    $pdo->exec("DELETE FROM backup_history WHERE backup_type = 'scheduled' AND status = 'failed'");
    $resolvedFailureKeys = array_map(
        static fn($item): string => $item->key,
        $center->getAttention(1, 'super_admin', $healthChecks)
    );
    $assert(!in_array('platform.backup.scheduled-failure', $resolvedFailureKeys, true),
        'Removing the failed scheduled row should resolve the Scheduled Backup condition');
    $pdo->exec("INSERT INTO backup_history VALUES ('failed','manual','2026-09-18 09:00:00')");
    $manualOnlyKeys = array_map(
        static fn($item): string => $item->key,
        $center->getAttention(1, 'super_admin', $healthChecks)
    );
    $assert(!in_array('platform.backup.scheduled-failure', $manualOnlyKeys, true),
        'A failed manual backup must not raise the Scheduled Backup condition');
    $pdo->exec("DELETE FROM backup_history WHERE backup_type = 'manual' AND status = 'failed'");

    $pdo->exec("INSERT INTO cashier_shifts VALUES (1,'closed',NULL,600);
        INSERT INTO fiscal_periods VALUES ('open','2026-09-17')");
    $storeItems = $center->synchronize(7, 'admin')->dashboardItems();
    $storeKeys = array_column($storeItems, 'key');
    $assert(!in_array('store.sales.reversals', $storeKeys, true), 'Retired reversals must not produce attention');
    $assert(!in_array('store.sales.unusual-discounts', $storeKeys, true), 'Retired unusual discounts must not produce attention');
    $assert(in_array('store.cash.variance', $storeKeys, true), 'Cash variance should produce Store attention');
    $assert(!in_array('store.approvals.overdue', $storeKeys, true), 'Retired purchasing approval signals must not produce attention');
    foreach ($storeItems as $item) {
        if ($item['category'] === 'inventory') {
            $assert($item['destination'] === 'components/administrator/stock_issues.php', 'Stock Issue attention must open read-only oversight');
            $assert(str_contains($item['explanation'], 'Inventory Manager'), 'Stock Issue attention must identify who reviews it');
        }
    }
    $assert(!in_array('store.inventory.escalated', $storeKeys, true), 'Pending count alone must not produce Store attention');
    $assert(in_array('store.fiscal-period.overdue', $storeKeys, true), 'Fiscal Period problems should produce Store attention');
    $assert(count(array_filter($storeKeys, static fn(string $key): bool => str_contains($key, 'routine'))) === 0, 'Routine low-level inventory work must remain excluded');
    $assert(!array_intersect($platformKeys, $storeKeys), 'Role visibility should keep platform conditions out of Administrator results');

    $pdo->exec("INSERT INTO login_attempts VALUES (7,0,'2026-09-18 09:20:00')");
    $assert($center->synchronize(1, 'super_admin', $healthChecks)->newItems() === [], 'Changing counts alone must not notify an unchanged condition');

    $notificationRows = $pdo->query("SELECT attention_key, attention_severity, attention_count, attention_destination FROM notifications WHERE user_id = 7 ORDER BY notification_id")->fetchAll(PDO::FETCH_ASSOC);
    $assert(array_column($notificationRows, 'attention_key') === $storeKeys, 'Notifications and dashboard-ready results should use the same ordered stable keys');
    foreach ($notificationRows as $index => $notification) {
        $assert($notification['attention_severity'] === $storeItems[$index]['severity'], 'Notification severity should come from the shared evaluated item');
        $assert((int)$notification['attention_count'] === (int)($storeItems[$index]['count'] ?? 0), 'Notification count should come from the shared evaluated item');
        $assert($notification['attention_destination'] === $storeItems[$index]['destination'], 'Notification destination should come from the shared evaluated item');
    }

    $emailOnly = $center->synchronize(11, 'admin', [], false);
    $assert(count($emailOnly->newItems()) === count($storeItems), 'Email-only recipients should receive each newly active attention item');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 11 AND inapp_enabled = 1')->fetchColumn() === count($storeItems), 'Required actions override optional in-app delivery');
    $emailOnlyRepeat = $center->synchronize(11, 'admin', [], false);
    $assert($emailOnlyRepeat->newItems() === [], 'Email-only attention should use the same deduplication state');

    $pdo->exec("UPDATE cashier_shifts SET reviewed_at='2026-09-18 09:30:00'; UPDATE fiscal_periods SET status='closed'");
    $resolved = $center->synchronize(7, 'admin')->dashboardItems();
    $assert($center->getAttention(7, 'admin', ['cash_variance_amount' => ['value' => 9999]]) === [], 'Public health input cannot override internal Store signals');
    $assert($resolved === [], 'Resolved live conditions should disappear without a manual lifecycle');
    $activeStates = (int)$pdo->query('SELECT COUNT(*) FROM attention_states WHERE user_id = 7 AND is_active = 1')->fetchColumn();
    $assert($activeStates === 0, 'Resolved conditions should be marked inactive for future reactivation');

    $pdo->exec("CREATE TRIGGER fail_attention_state BEFORE INSERT ON attention_states BEGIN SELECT RAISE(ABORT, 'state failure'); END");
    try {
        $pdo->exec('UPDATE cashier_shifts SET reviewed_at=NULL');
        $center->synchronize(99, 'admin');
        $assert(false, 'Attention synchronization should surface persistence failures');
    } catch (PDOException $exception) {
        $assert((int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 99')->fetchColumn() === 0, 'Notification and deduplication state must commit atomically');
    }
    $pdo->exec('DROP TRIGGER fail_attention_state');

    $pdo->exec("INSERT INTO notifications (user_id, type, title, message, reference_type, created_at) VALUES
        (7, 'replenishment', 'Retired request', 'Pending purchase approval', 'replenishment_request', '2026-09-18'),
        (7, 'system', 'Retired order', 'Order approved', 'purchase_order', '2026-09-18'),
        (7, 'low_stock', 'Retained stock alert', 'Coffee is low', 'product', '2026-09-18')");
    $pdo->exec("INSERT INTO notifications (user_id, type, title, message, attention_key, attention_destination, created_at) VALUES
        (7, 'attention', 'Old approvals', 'Review requests', 'store.approvals.overdue', 'components/inventory_management/replenishment_requests.php', '2026-09-18')");
    $pdo->exec("INSERT INTO attention_states (user_id,attention_key,fingerprint,is_active,first_detected_at,last_detected_at,resolved_at) VALUES (7, 'store.approvals.overdue', 'obsolete', 1, '2026-09-18', '2026-09-18', NULL)");
    $settingsBefore = $settings->thresholdsFor('admin');
    $retainedCount = (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() - 3;
    $migration = require __DIR__ . '/../database/migrations/202610150002_retire_procurement_attention.php';
    $migration['up']($pdo);
    $migration['up']($pdo);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $retainedCount, 'Upgrade removes only obsolete purchasing notifications and is repeatable');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM attention_states WHERE attention_key = 'store.approvals.overdue'")->fetchColumn() === 0, 'Upgrade removes retired approval deduplication state');
    $assert($settings->thresholdsFor('admin') === $settingsBefore, 'Upgrade preserves unrelated operational thresholds');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE attention_key = 'store.cash.variance'")->fetchColumn() > 0, 'Upgrade preserves Administrator cash reviews');

    // Per-report thresholds are exercised through persisted Stock Issues.
    $pdo->exec("UPDATE cashier_shifts SET reviewed_at='2026-09-18 09:30:00';
        INSERT INTO products VALUES (1,1);
        INSERT INTO inventory_adjustments VALUES
            (501,1,-4999.99,'2026-09-17 09:30:01','pending'),
            (502,1,-100,'2026-09-17 09:30:00','pending'),
            (503,1,-5000,'2026-09-18 08:30:00','pending'),
            (506,1,-12.5,'2026-09-18 09:30:00','pending')");
    $issueItems = array_column($center->getAttention(7, 'admin'), null, 'key');
    $assert(!isset($issueItems['store.inventory.issue.501']) && !isset($issueItems['store.inventory.issue.506']), 'Routine reports below both thresholds remain excluded');
    $assert(isset($issueItems['store.inventory.issue.502']) && $issueItems['store.inventory.issue.502']->severity === 'warning', 'Stock Issue escalates exactly at 24 hours');
    $assert(isset($issueItems['store.inventory.issue.503']) && $issueItems['store.inventory.issue.503']->severity === 'critical', 'Stock Issue escalates exactly at PHP 5000');
    $pdo->exec("UPDATE inventory_adjustments SET status='approved'");

    // Issue #263: condition lifecycle on the shared synchronizer.
    $lifecycleUser = 300;
    $lifecycleItem = static fn (string $severity): AttentionItem => new AttentionItem(
        'store.cash.variance',
        $severity,
        'cash',
        'Cash variance exceeds tolerance',
        'Cash variance requires review.',
        new DateTimeImmutable('2026-09-18 08:00:00'),
        'components/report/report_generation.php',
        1
    );
    $lifecycleNotifications = static fn (): int =>
        (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 300")->fetchColumn();
    $lifecycleActiveState = static fn (): int =>
        (int)$pdo->query("SELECT COUNT(*) FROM attention_states WHERE user_id = 300 AND attention_key = 'store.cash.variance' AND is_active = 1")->fetchColumn();

    $notifications->synchronizeItems($lifecycleUser, [$lifecycleItem('warning')], true, 'store.');
    $assert($lifecycleNotifications() === 1, 'First detection of a condition should create exactly one notification');

    $notifications->synchronizeItems($lifecycleUser, [$lifecycleItem('warning')], true, 'store.');
    $assert($lifecycleNotifications() === 1, 'An unchanged rerun must not create a second notification');
    $assert($lifecycleActiveState() === 1, 'An unchanged rerun must not create a second active state');

    $pdo->exec('UPDATE notifications SET is_read = 1 WHERE user_id = 300');
    $assert($lifecycleActiveState() === 1, 'Reading an alert must not resolve its unresolved condition');

    $notifications->synchronizeItems($lifecycleUser, [], true, 'store.');
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM attention_states WHERE user_id = 300 AND attention_key = 'store.cash.variance' AND is_active = 0")->fetchColumn() === 1,
        'Removing a condition from the item list should resolve it'
    );

    $notifications->synchronizeItems($lifecycleUser, [$lifecycleItem('warning')], true, 'store.');
    $assert($lifecycleNotifications() === 2, 'Recurrence after resolution should create a fresh notification');

    $notifications->synchronizeItems($lifecycleUser, [$lifecycleItem('critical')], true, 'store.');
    $assert($lifecycleNotifications() === 3, 'Severity escalation while active should create a fresh notification');
    $assert(
        (int)$pdo->query("SELECT notification_level FROM attention_states WHERE user_id = 300 AND attention_key = 'store.cash.variance'")->fetchColumn() === 3000,
        'Severity escalation should raise the remembered notification level to the critical peak'
    );

    $notifications->synchronizeItems($lifecycleUser, [$lifecycleItem('warning')], true, 'store.');
    $notifications->synchronizeItems($lifecycleUser, [$lifecycleItem('critical')], true, 'store.');
    $assert($lifecycleNotifications() === 3, 'De-escalation then re-escalation to the same prior peak must not create a duplicate');

    // Issue #263: Workspace-scoped lifecycle independence.
    $crossWorkspaceUser = 400;
    $notifications->synchronizeItems($crossWorkspaceUser, [new AttentionItem(
        'platform.backup.stale',
        'critical',
        'backup_recovery',
        'Latest backup is stale',
        'The latest Database Backup is stale.',
        new DateTimeImmutable('2026-09-18 08:00:00'),
        'components/system_administrator/backup_restore.php'
    )], true, 'platform.');
    $notifications->synchronizeItems($crossWorkspaceUser, [new AttentionItem(
        'store.cash.variance',
        'warning',
        'cash',
        'Cash variance exceeds tolerance',
        'Cash variance requires review.',
        new DateTimeImmutable('2026-09-18 08:00:00'),
        'components/report/report_generation.php'
    )], true, 'store.');
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM attention_states WHERE user_id = 400 AND attention_key = 'platform.backup.stale' AND is_active = 1")->fetchColumn() === 1,
        'Synchronizing one Workspace scope must not resolve another Workspace active condition'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM attention_states WHERE user_id = 400 AND is_active = 1')->fetchColumn() === 2,
        'Cross-Workspace conditions should remain independently active for the same user'
    );

    // Issue #263: required conditions override optional in-app preferences per condition.
    $notifications->synchronizeItems(500, [new AttentionItem(
        'store.cash.variance',
        'warning',
        'cash',
        'Cash variance exceeds tolerance',
        'Cash variance requires review.',
        new DateTimeImmutable('2026-09-18 08:00:00'),
        'components/report/report_generation.php',
        1,
        false
    )], false, 'store.');
    $optionalRow = $pdo->query('SELECT inapp_enabled, email_pending FROM notifications WHERE user_id = 500')->fetch(PDO::FETCH_ASSOC);
    $assert((int)$optionalRow['inapp_enabled'] === 0, 'A createInApp=false optional condition must insert an in-app-disabled row');
    $assert((int)$optionalRow['email_pending'] === 1, 'A createInApp=false optional condition must remain queued for optional daily email');

    $notifications->synchronizeItems(501, [new AttentionItem(
        'store.cash.variance',
        'warning',
        'cash',
        'Cash variance exceeds tolerance',
        'Cash variance requires review.',
        new DateTimeImmutable('2026-09-18 08:00:00'),
        'components/report/report_generation.php',
        1,
        true
    )], false, 'store.');
    $assert(
        (int)$pdo->query('SELECT inapp_enabled FROM notifications WHERE user_id = 501')->fetchColumn() === 1,
        'A createInApp=false required condition must override the optional in-app preference'
    );
    // --- Deepened AttentionCenter interface tests ---
    $deepCenter = new AttentionCenter($pdo, $clock, $settings);
    $assert($deepCenter->getAttention(1, 'cashier') === [], 'Deep AttentionCenter must short-circuit cashier to empty array');
    $assert($deepCenter->getAttention(1, 'inventory_manager') === [], 'Deep AttentionCenter must short-circuit inventory manager to empty array');

    $superAttention = $deepCenter->getAttention(1, 'super_admin', $healthChecks);
    $assert(count($superAttention) > 0, 'Deep AttentionCenter must evaluate super_admin rules with external signals');
    $superKeys = array_map(static fn($item): string => $item->key, $superAttention);
    $assert(in_array('platform.service.unhealthy', $superKeys, true), 'Deep AttentionCenter must surface platform service unhealthy item');

    $syncResult = $deepCenter->synchronize(600, 'super_admin', $healthChecks);
    $assert($syncResult instanceof \App\Attention\AttentionRefreshResult, 'Deep AttentionCenter::synchronize must return AttentionRefreshResult');
    $notifRow = $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 600 AND workspace = 'super_admin'")->fetchColumn();
    $assert((int)$notifRow > 0, 'Deep AttentionCenter::synchronize must persist notifications');
    // Comprehensive public-interface verification for AttentionCenter
    // 1. Unauthorized roles return empty array and do not mutate database
    foreach (['cashier', 'inventory_manager', 'viewer', ''] as $unauthRole) {
        $assert($deepCenter->getAttention(99, $unauthRole) === [], "getAttention must return empty array for unauthorized role: {$unauthRole}");
        $unauthSync = $deepCenter->synchronize(99, $unauthRole);
        $assert($unauthSync->items() === [] && $unauthSync->newItems() === [], "synchronize must return empty result for unauthorized role: {$unauthRole}");
    }
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 99")->fetchColumn() === 0, 'Unauthorized role must not create notifications');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM attention_states WHERE user_id = 99")->fetchColumn() === 0, 'Unauthorized role must not create attention states');

    // 2. Both administrator workspaces scoped correctly
    $pdo->exec('UPDATE cashier_shifts SET reviewed_at=NULL');
    $adminSync = $deepCenter->synchronize(701, 'admin');
    $adminNotif = $pdo->query("SELECT workspace, attention_key FROM notifications WHERE user_id = 701")->fetch(PDO::FETCH_ASSOC);
    $assert($adminNotif !== false && $adminNotif['workspace'] === 'admin', 'Admin attention notifications must carry admin workspace');
    $assert(str_starts_with($adminNotif['attention_key'], 'store.'), 'Admin attention keys must use store. prefix');

    $superSync = $deepCenter->synchronize(702, 'super_admin', $healthChecks);
    $superNotif = $pdo->query("SELECT workspace, attention_key FROM notifications WHERE user_id = 702")->fetch(PDO::FETCH_ASSOC);
    $assert($superNotif !== false && $superNotif['workspace'] === 'super_admin', 'Super admin attention notifications must carry super_admin workspace');
    $assert(str_starts_with($superNotif['attention_key'], 'platform.'), 'Super admin attention keys must use platform. prefix');

    // 3. Repeated synchronization idempotency (deduplication)
    $repeatSync = $deepCenter->synchronize(701, 'admin');
    $assert($repeatSync->newItems() === [], 'Repeated synchronize with unchanged condition must return empty newItems');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 701")->fetchColumn() === 1, 'Repeated synchronize must not duplicate notifications');

    // 4. Changing conditions: resolution and reactivation
    $pdo->exec("UPDATE cashier_shifts SET reviewed_at='2026-09-18 09:30:00'");
    $resolveSync = $deepCenter->synchronize(701, 'admin');
    $assert((int)$pdo->query("SELECT is_active FROM attention_states WHERE user_id = 701 AND attention_key = 'store.cash.variance'")->fetchColumn() === 0, 'Condition resolution must mark attention state inactive');
    $pdo->exec('UPDATE cashier_shifts SET reviewed_at=NULL');
    $reactivateSync = $deepCenter->synchronize(701, 'admin');
    $assert(count($reactivateSync->newItems()) === 1, 'Reactivated condition must produce new notification');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 701")->fetchColumn() === 2, 'Reactivation must add second notification');

    // 5. Severity ordering invariant across getAttention
    $orderedItems = $deepCenter->getAttention(1, 'super_admin', $healthChecks);
    for ($i = 1; $i < count($orderedItems); $i++) {
        $assert($severityOrder[$orderedItems[$i - 1]->severity] <= $severityOrder[$orderedItems[$i]->severity], 'getAttention items must be strictly severity-ordered');
    }
} catch (Throwable $exception) {
    $failures[] = 'Attention rules contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Attention rules contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Attention rules contract: passed\n";

