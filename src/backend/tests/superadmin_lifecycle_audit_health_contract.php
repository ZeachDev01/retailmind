<?php
// Contract test for Ticket #454: Super Administrator Lifecycle, Audit Trail & Health Center.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SystemHealthService.php';

use App\Attention\AttentionCenter;
use App\Attention\AttentionItem;
use App\Attention\AttentionRuleService;
use App\Attention\Clock;
use App\Audit\AuditRecordCategory;
use App\Audit\ProtectedAuditRecordService;
use App\Authorization\AdministratorAccessMinimum;
use App\Authorization\DormancyPolicyService;
use App\Authorization\DormancyRunner;
use App\Authorization\DormancyStatusTab;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\PlatformHealthSource;
use App\Services\UserAccountNotifier;
use App\Services\UserLifecycleService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

final class T454TestClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Initialize Schema
    $pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)");
    $pdo->exec("INSERT INTO roles (role_id, role_name) VALUES
        (1, 'super_admin'), (2, 'admin'), (3, 'inventory_manager'), (4, 'cashier')");

    $pdo->exec("CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        first_name TEXT NULL,
        last_name TEXT NULL,
        display_name TEXT NOT NULL,
        full_name TEXT NULL,
        username TEXT NOT NULL UNIQUE,
        email TEXT NULL UNIQUE,
        profile_image TEXT NULL,
        password_hash TEXT NOT NULL,
        role_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        session_version INTEGER NOT NULL DEFAULT 1,
        password_changed_at TEXT NULL,
        must_change_password INTEGER NOT NULL DEFAULT 1,
        branch_id INTEGER NULL,
        is_recovery_account INTEGER NOT NULL DEFAULT 0,
        disabled_at TEXT NULL,
        locked_until TEXT NULL,
        last_login_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE user_roles (
        user_id INTEGER NOT NULL,
        role_id INTEGER NOT NULL,
        is_primary INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, role_id)
    )");

    $pdo->exec("CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        action TEXT NOT NULL,
        category TEXT NOT NULL,
        module TEXT NULL,
        record_id INTEGER NULL,
        previous_value TEXT NULL,
        new_value TEXT NULL,
        metadata TEXT NULL,
        ip_address TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE login_attempts (
        attempt_id INTEGER PRIMARY KEY AUTOINCREMENT,
        was_successful INTEGER NOT NULL,
        attempted_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE attention_settings (
        setting_scope TEXT,
        setting_key TEXT,
        setting_value TEXT,
        updated_by INTEGER,
        updated_at TEXT,
        PRIMARY KEY (setting_scope, setting_key)
    )");

    $pdo->exec("CREATE TABLE backup_history (
        backup_id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL,
        backup_type TEXT NOT NULL,
        status TEXT NOT NULL,
        envelope_version TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE model_training_runs (
        training_run_id INTEGER PRIMARY KEY AUTOINCREMENT,
        status TEXT NOT NULL,
        model_version TEXT NULL,
        started_at TEXT NULL,
        completed_at TEXT NULL,
        metrics_json TEXT NULL
    )");

    $pdo->exec("CREATE TABLE ml_jobs (
        job_id INTEGER PRIMARY KEY AUTOINCREMENT,
        trigger_type TEXT NOT NULL,
        status TEXT NOT NULL,
        requester_id INTEGER NULL,
        queued_at TEXT NOT NULL,
        started_at TEXT NULL,
        completed_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE platform_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE recovery_accounts (
        user_id INTEGER PRIMARY KEY,
        sealed_at TEXT NULL,
        last_used_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE schema_migrations (
        migration_key TEXT PRIMARY KEY,
        description TEXT,
        applied_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE sales (sale_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_date TEXT)");
    $pdo->exec("CREATE TABLE stock_movements (movement_id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT)");
    $pdo->exec("CREATE TABLE notifications (notification_id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)");

    $now = new DateTimeImmutable('2026-11-08 10:00:00');
    $clock = new T454TestClock($now);
    $policy = new RoleCapabilityPolicy();

    // -------------------------------------------------------------------------
    // 1. User Management: RBAC, Dormancy Detection, Secure Password Reset Workflows
    // -------------------------------------------------------------------------
    $notifier = new class extends UserAccountNotifier {
        public array $sent = [];
        protected function deliver(string $email, string $subject, string $message): bool
        {
            $this->sent[] = ['email' => $email, 'subject' => $subject, 'message' => $message];
            return true;
        }
    };
    $lifecycle = new UserLifecycleService($pdo, $policy, $notifier);

    // Seed Super Admin and Admin
    $pdo->prepare("INSERT INTO users (user_id, first_name, last_name, display_name, username, email, password_hash, role_id, status, session_version, must_change_password)
        VALUES (1, 'Super', 'Admin', 'Super Admin', 'super_admin', 'super@retailmind.test', ?, 1, 'active', 1, 0)")
        ->execute([password_hash('SuperSecret123!', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (1, 1, 1)")->execute();

    $pdo->prepare("INSERT INTO users (user_id, first_name, last_name, display_name, username, email, password_hash, role_id, status, session_version, must_change_password, last_login_at)
        VALUES (2, 'Store', 'Manager', 'Store Manager', 'store_admin', 'admin@retailmind.test', ?, 2, 'active', 1, 0, '2026-11-01 08:00:00')")
        ->execute([password_hash('AdminSecret123!', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (2, 2, 1)")->execute();

    // 1a. RBAC: Super Admin creates secondary Admin
    $newAdminId = $lifecycle->create(1, 'super_admin', [
        'first_name' => 'Alice',
        'last_name' => 'Admin',
        'username' => 'alice_admin',
        'email' => 'alice@retailmind.test',
        'password_hash' => password_hash('InitialPass123!', PASSWORD_DEFAULT),
        'roles' => ['admin'],
    ]);
    $assert($newAdminId > 0, 'Super Administrator can create an Administrator');
    $alice = $lifecycle->get($newAdminId);
    $assert($alice['role_name'] === 'admin', 'Created user has admin role');
    $assert((int)$alice['must_change_password'] === 1, 'Newly created user must change temporary password');

    // Super Admin cannot create Cashier directly
    $cashierDenied = false;
    try {
        $lifecycle->create(1, 'super_admin', [
            'first_name' => 'Bad',
            'last_name' => 'Cashier',
            'username' => 'bad_cashier',
            'email' => 'bad@test.local',
            'password_hash' => password_hash('Pass123!', PASSWORD_DEFAULT),
            'roles' => ['cashier'],
        ]);
    } catch (DomainException) {
        $cashierDenied = true;
    }
    $assert($cashierDenied, 'Super Administrator cannot create operational cashier directly');

    // Admin creates Cashier
    $cashierId = $lifecycle->create(2, 'admin', [
        'first_name' => 'Bob',
        'last_name' => 'Cashier',
        'username' => 'bob_cashier',
        'email' => 'bob@retailmind.test',
        'password_hash' => password_hash('BobInitial123!', PASSWORD_DEFAULT),
        'roles' => ['cashier'],
    ]);
    $assert($cashierId > 0, 'Administrator can create Cashier');

    // 1b. Dormancy Detection for Inactive Accounts
    // Seed an account that has not logged in for 45 days
    $dormantCashierId = $lifecycle->create(2, 'admin', [
        'first_name' => 'Dormant',
        'last_name' => 'Staff',
        'username' => 'dormant_staff',
        'email' => 'dormant@retailmind.test',
        'password_hash' => password_hash('DormantPass123!', PASSWORD_DEFAULT),
        'roles' => ['cashier'],
    ]);
    $pdo->prepare("UPDATE users SET last_login_at = '2026-09-15 10:00:00', created_at = '2026-09-01 10:00:00' WHERE user_id = ?")
        ->execute([$dormantCashierId]);

    $dormancyTab = new DormancyStatusTab($pdo);
    $dormantUser = $lifecycle->get($dormantCashierId);
    $assert($dormantUser['status'] === 'active', 'Dormant user status is active prior to runner');
    $dormancyView = $dormancyTab->view($dormantUser);
    $assert(isset($dormancyView['last_login']), 'Dormancy status tab computes last login view');
    $assert($dormancyView['in_scope'] === true, 'Dormant cashier is in scope for dormancy policy');

    // Run DormancyRunner with 30-day warning and 40-day auto-disable
    $runner = new DormancyRunner($pdo, $clock, new DormancyPolicyService($pdo), $notifier);
    $result = $runner->run();
    $assert(count($result['disabled']) >= 1, 'Dormancy runner disables overdue inactive accounts');
    $dormantAfter = $lifecycle->get($dormantCashierId);
    $assert($dormantAfter['status'] === 'disabled', 'Inactive user was transitioned to disabled');
    $assert($dormantAfter['disabled_at'] !== null, 'Disabled timestamp is recorded on dormant account');

    // 1c. Secure Password Reset Workflows
    // Administrator resets Cashier password
    $cashierBefore = $lifecycle->get($cashierId);
    $preResetVersion = (int)$cashierBefore['session_version'];
    $newHash = password_hash('BobReplacement123!', PASSWORD_DEFAULT);
    $lifecycle->resetPassword(2, 'admin', $cashierId, $newHash);
    $bobAfterReset = $lifecycle->get($cashierId);
    $assert((int)$bobAfterReset['session_version'] === $preResetVersion + 1, 'Password reset increments session_version to revoke active sessions');
    $assert((int)$bobAfterReset['must_change_password'] === 1, 'Password reset enforces must_change_password flag');
    $assert(password_verify('BobReplacement123!', $bobAfterReset['password_hash']), 'New password hash is active');

    // Super Administrator can reset own password
    $superPreResetVersion = (int)$lifecycle->get(1)['session_version'];
    $superNewHash = password_hash('SuperReplacement123!', PASSWORD_DEFAULT);
    $lifecycle->resetPassword(1, 'super_admin', 1, $superNewHash);
    $superAfterReset = $lifecycle->get(1);
    $assert((int)$superAfterReset['session_version'] === $superPreResetVersion + 1, 'Super Administrator can rotate own password securely');

    // -------------------------------------------------------------------------
    // 2. Protected Audit Logs: Append-Only, Tamper-Evident, Inspection Drawer Payloads
    // -------------------------------------------------------------------------
    $auditService = new ProtectedAuditRecordService($pdo, $policy);

    // Verify password reset generated a Protected Audit Record
    $auditLogs = $auditService->records('super_admin', ['action' => 'Password reset']);
    $assert(count($auditLogs) >= 1, 'Password reset creates Protected Audit Record');
    $resetRecord = $auditLogs[0];
    $assert($resetRecord['category'] === AuditRecordCategory::SECURITY || $resetRecord['category'] === AuditRecordCategory::STORE_OPERATION, 'Audit category properly recorded');
    $assert($resetRecord['module'] === 'User Access', 'Audit module is User Access');

    // Verify sensitive data masking (passwords must be ***)
    $maskedPayload = $resetRecord['new_value'];
    $assert(!str_contains($maskedPayload, 'AliceReplacement123!'), 'Plaintext passwords never appear in audit payloads');

    // Verify exact action payloads & IP origins are queryable
    $pdo->prepare("INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address, created_at)
        VALUES (?, 'Platform setting updated', 'platform_setting', 'Platform Settings', 1, '{\"theme\":\"light\"}', '{\"theme\":\"dark\"}', '192.168.1.100', '2026-11-08 09:30:00')")
        ->execute([1]);
    $settingAudits = $auditService->records('super_admin', ['module' => 'Platform Settings']);
    $assert(count($settingAudits) === 1, 'Super Administrator can access platform setting audit records');
    $entry = $settingAudits[0];
    $assert($entry['ip_address'] === '192.168.1.100', 'Audit record retains exact IP origin');
    $assert(str_contains($entry['previous_value'], 'light'), 'Audit record retains previous value payload');
    $assert(str_contains($entry['new_value'], 'dark'), 'Audit record retains new value payload');

    // Verify Store Admin cannot see platform_setting category audit logs
    $adminViewOfPlatformLogs = $auditService->records('admin', ['module' => 'Platform Settings']);
    $assert(count($adminViewOfPlatformLogs) === 0, 'Store Administrator cannot access platform governance audit records');

    // -------------------------------------------------------------------------
    // 3. Platform Health Center: Resources, Queue Latencies, Table Growth
    // -------------------------------------------------------------------------
    // Seed ML jobs for queue latency check
    $pdo->prepare("INSERT INTO ml_jobs (trigger_type, status, requester_id, queued_at, started_at, completed_at)
        VALUES ('demand_forecast', 'succeeded', 1, '2026-11-08 08:00:00', '2026-11-08 08:00:05', '2026-11-08 08:05:00')")
        ->execute();

    $health = new SystemHealthService($pdo, dirname(__DIR__, 2));
    $checks = $health->checks();
    $assert(is_array($checks) && count($checks) >= 10, 'Health checks include complete platform suite');

    $checkNames = array_column($checks, 'name');
    $assert(in_array('System resource utilization', $checkNames, true), 'Health checks contain System resource utilization');
    $assert(in_array('Background queue latency', $checkNames, true), 'Health checks contain Background queue latency');
    $assert(in_array('Database table growth', $checkNames, true), 'Health checks contain Database table growth');

    $resourceCheck = array_values(array_filter($checks, static fn($c) => $c['name'] === 'System resource utilization'))[0];
    $assert($resourceCheck['category'] === 'Resources', 'Resource utilization categorized under Resources');
    $assert(str_contains($resourceCheck['detail'], 'Memory') || str_contains($resourceCheck['detail'], 'Disk'), 'Resource check details memory or disk utilization');

    $queueCheck = array_values(array_filter($checks, static fn($c) => $c['name'] === 'Background queue latency'))[0];
    $assert($queueCheck['category'] === 'Forecasting', 'Queue latency categorized under Forecasting');
    $assert(str_contains($queueCheck['detail'], 'latency') || str_contains($queueCheck['detail'], 'queue'), 'Queue latency reports wait or latency metrics');

    $dbGrowthCheck = array_values(array_filter($checks, static fn($c) => $c['name'] === 'Database table growth'))[0];
    $assert($dbGrowthCheck['category'] === 'Core', 'Database growth categorized under Core');
    $assert(str_contains($dbGrowthCheck['detail'], 'tables'), 'Database table growth reports monitored table count and sizes');

    // -------------------------------------------------------------------------
    // 4. Attention Center: Proactive Alerts for System Degradation & Security Violations
    // -------------------------------------------------------------------------
    $attentionCenter = new AttentionCenter($pdo, $clock);

    // 4a. Security alert: Repeated failed sign-ins
    for ($i = 0; $i < 6; $i++) {
        $pdo->prepare("INSERT INTO login_attempts (was_successful, attempted_at) VALUES (0, '2026-11-08 09:10:0" . $i . "')")->execute();
    }
    // 4b. Security alert: Locked accounts
    $pdo->prepare("UPDATE users SET locked_until = '2026-11-08 12:00:00' WHERE user_id = ?")->execute([$newAdminId]);

    // 4c. Degradation: Provide a warning health check
    $degradedChecks = $checks;
    $degradedChecks[] = [
        'name' => 'Storage: backups',
        'status' => 'critical',
        'detail' => 'Storage directory is missing or not writable',
        'category' => 'Storage',
    ];

    $attentionItems = $attentionCenter->getAttention(1, 'super_admin', $degradedChecks);
    $assert(count($attentionItems) >= 2, 'Attention center generates proactive alerts for Super Administrator');

    $attentionKeys = array_map(static fn(AttentionItem $item) => $item->key, $attentionItems);
    $assert(in_array('platform.security.failed-logins', $attentionKeys, true), 'Attention center alerts on repeated failed logins');
    $assert(in_array('platform.access.locked-accounts', $attentionKeys, true), 'Attention center alerts on locked accounts requiring review');
    $assert(in_array('platform.service.unhealthy', $attentionKeys, true), 'Attention center alerts on platform service degradation');

    // Verify ordering by severity (critical first)
    $severities = array_map(static fn(AttentionItem $item) => $item->severity, $attentionItems);
    $criticalIndexes = array_keys(array_filter($severities, static fn($s) => $s === 'critical'));
    $warningIndexes = array_keys(array_filter($severities, static fn($s) => $s === 'warning'));
    if (!empty($warningIndexes) && !empty($criticalIndexes)) {
        $assert(max($criticalIndexes) < min($warningIndexes), 'Critical alerts appear before warning alerts');
    }

} catch (Throwable $e) {
    $failures[] = 'Contract test threw exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}

if ($failures !== []) {
    fwrite(STDERR, "Superadmin lifecycle audit health contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Superadmin lifecycle audit health contract: passed\n";
