<?php
// Dormancy runner contract (ticket #62): end-to-end Dormancy Policy application
// through the public runner seam — thresholds from Platform Settings, day
// boundaries (including never-logged-in accounts measured from creation),
// role scope, last-active-Administrator guard, disable side effects (status,
// disabled_at, session bump, row retention, distinct system audit), in-app
// warnings, and holder emails through an injected fake notifier.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Attention\Clock;
use App\Authorization\DormancyPolicyService;
use App\Authorization\DormancyRunner;
use App\Services\UserAccountNotifier;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

final class DormancyRunnerContractClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE platform_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_by INTEGER NULL,
        updated_at TEXT NULL
    )');
    $pdo->exec("INSERT INTO platform_settings (setting_key, setting_value)
                VALUES ('dormancy_disable_days', '40'), ('dormancy_warn_days', '25')");
    $pdo->exec('CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)');
    $pdo->exec("INSERT INTO roles (role_id, role_name) VALUES
        (1, 'super_admin'), (2, 'admin'), (3, 'inventory_manager'), (4, 'cashier')");
    $pdo->exec("CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        username TEXT NOT NULL UNIQUE,
        email TEXT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        session_version INTEGER NOT NULL DEFAULT 1,
        must_change_password INTEGER NOT NULL DEFAULT 1,
        branch_id TEXT NULL,
        is_recovery_account INTEGER NOT NULL DEFAULT 0,
        disabled_at TEXT NULL,
        last_login_at TEXT NULL,
        created_at TEXT NULL
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
        ip_address TEXT NULL,
        timestamp TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        reference_id INTEGER NULL,
        reference_type TEXT NULL,
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NULL
    )");

    $clock = new DormancyRunnerContractClock(new DateTimeImmutable('2026-09-24 12:00:00'));
    $at = static fn(int $days, int $seconds = 0): string =>
        (new DateTimeImmutable('2026-09-24 12:00:00'))
            ->modify("-{$days} days +{$seconds} seconds")
            ->format('Y-m-d H:i:s');

    $insert = $pdo->prepare(
        'INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status,
                            is_recovery_account, last_login_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $fixtures = [
        [1, 'Super Owner', 'super_owner', 'super@example.test', 1, 'active', 0, $at(1), $at(90)],
        [2, 'Active Admin', 'active_admin', 'adminb@example.test', 2, 'active', 0, $at(1), $at(90)],
        [3, 'Warn Cashier', 'warn_cashier', 'warn@example.test', 4, 'active', 0, $at(25), $at(90)],
        [4, 'Fresh Cashier', 'fresh_cashier', 'fresh@example.test', 4, 'active', 0, $at(25, 1), $at(90)],
        [5, 'No Email Warmer', 'no_email_warmer', null, 4, 'active', 0, $at(25), $at(90)],
        [6, 'Boundary Manager', 'boundary_manager', 'boundary@example.test', 3, 'active', 0, $at(40, 1), $at(90)],
        [7, 'Disable Manager', 'disable_manager', 'disable@example.test', 3, 'active', 0, $at(40), $at(90)],
        [8, 'Never Signed In', 'never_signed_in', null, 4, 'active', 0, null, $at(40)],
        [9, 'Old Super', 'old_super', 'oldsuper@example.test', 1, 'active', 0, null, $at(200)],
        [10, 'Sealed Recovery', 'sealed_recovery', null, 2, 'active', 1, null, $at(200)],
        [11, 'Dormant Admin', 'dormant_admin', 'admina@example.test', 2, 'active', 0, $at(40), $at(90)],
        [12, 'Never Warned', 'never_warned', 'neverwarn@example.test', 4, 'active', 0, null, $at(25)],
    ];
    foreach ($fixtures as [$id, $name, $username, $email, $roleId, $status, $recovery, $lastLogin, $created]) {
        $insert->execute([$id, $name, $username, $email, 'hash-' . $username, $roleId, $status, $recovery, $lastLogin, $created]);
    }

    $notifier = new class extends UserAccountNotifier {
        /** @var array<int, array{email: string, subject: string, message: string}> */
        public array $delivered = [];
        protected function deliver(string $email, string $subject, string $message): bool
        {
            $this->delivered[] = ['email' => $email, 'subject' => $subject, 'message' => $message];
            return true;
        }
    };

    $user = static function (int $id) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    };
    $warningsFor = static function (int $targetId) use ($pdo): array {
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE reference_type = 'dormancy_warn' AND reference_id = ? ORDER BY notification_id");
        $stmt->execute([$targetId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $emailsTo = static fn(string $email): array => array_values(array_filter(
        $notifier->delivered,
        static fn(array $mail): bool => $mail['email'] === $email
    ));

    $policy = new DormancyPolicyService($pdo);
    $thresholds = $policy->thresholds();
    $assert(
        $thresholds === ['disable_days' => 40, 'warn_days' => 25],
        'thresholds must load from Platform Settings rows, got ' . json_encode($thresholds)
    );
    $assert(
        DormancyRunner::SCOPE_ROLES === ['admin', 'inventory_manager', 'cashier'],
        'policy scope must be Administrator, Inventory Manager, and Cashier only'
    );
    $assert(
        DormancyRunner::ALERT_ROLES === ['super_admin', 'admin'],
        'warn recipients must be Super Administrator and Administrator'
    );

    $runner = new DormancyRunner($pdo, $clock, $policy, $notifier);
    $first = $runner->run();

    $assert(
        $first['thresholds'] === ['disable_days' => 40, 'warn_days' => 25],
        'the run must report the loaded Platform Settings thresholds'
    );
    $assert(
        $first['disabled'] === [7, 8, 11],
        'policy disable must target accounts at or past the disable threshold, got ' . json_encode($first['disabled'])
    );
    $assert(
        $first['warned'] === [3, 5, 6, 12],
        'the warn threshold must target accounts in the warn window, got ' . json_encode($first['warned'])
    );
    $assert(
        $first['skipped_last_admin'] === [],
        'a dormant Administrator must be disabled while another active Administrator remains'
    );

    $disabledManager = $user(7);
    $assert($disabledManager['status'] === 'disabled', 'policy disable must move the account to Disabled');
    $assert(
        $disabledManager['disabled_at'] === '2026-09-24 12:00:00',
        'policy disable must stamp disabled_at from the injected clock, got ' . var_export($disabledManager['disabled_at'], true)
    );
    $assert(
        (int)$disabledManager['session_version'] === 2,
        'policy disable must bump session_version to revoke sessions'
    );
    $assert(
        $disabledManager['username'] === 'disable_manager',
        'policy disable must retain the row and historical attribution'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM users WHERE user_id = 7')->fetchColumn() === 1,
        'the policy-disabled row must remain queryable'
    );

    $neverSignedIn = $user(8);
    $assert(
        $neverSignedIn['status'] === 'disabled' && $neverSignedIn['disabled_at'] !== null,
        'never-logged-in accounts must be measured from creation and disabled at the threshold'
    );
    $dormantAdmin = $user(11);
    $assert(
        $dormantAdmin['status'] === 'disabled' && (int)$dormantAdmin['session_version'] === 2,
        'a dormant Administrator must be disabled while another active Administrator remains'
    );

    $fresh = $user(4);
    $assert(
        $fresh['status'] === 'active' && $fresh['disabled_at'] === null && (int)$fresh['session_version'] === 1,
        'an account one second inside the warn boundary must remain untouched'
    );
    $boundary = $user(6);
    $assert(
        $boundary['status'] === 'active' && (int)$boundary['session_version'] === 1,
        'an account one second inside the disable boundary must be warned, not disabled'
    );
    $assert($user(3)['status'] === 'active', 'reaching the warn threshold alone must not disable');
    $assert($user(12)['status'] === 'active', 'the warn threshold measured from creation must not disable');
    foreach ([9, 10] as $protectedId) {
        $protected = $user($protectedId);
        $assert(
            $protected['status'] === 'active' && $protected['disabled_at'] === null,
            'Super Administrator and Recovery Account must never be policy targets'
        );
    }

    $audits = $pdo->query("SELECT * FROM activity_log WHERE action LIKE '%Dormancy Policy%' ORDER BY log_id")->fetchAll(PDO::FETCH_ASSOC);
    $assert(
        count($audits) === 3,
        'each policy disable must write one Protected Audit Record, got ' . count($audits)
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'User disabled'")->fetchColumn() === 0,
        'policy disable must not reuse the manual User disabled action'
    );
    $auditedIds = array_map(static fn(array $row): int => (int)$row['record_id'], $audits);
    sort($auditedIds);
    $assert($auditedIds === [7, 8, 11], 'audit records must reference every policy-disabled account');
    $categories = [];
    foreach ($audits as $audit) {
        $assert($audit['action'] !== 'User disabled', 'policy audit action must be distinct from a person clicking disable');
        $assert(
            str_contains($audit['action'], 'Dormant Account')
                && str_contains($audit['action'], 'automatically')
                && str_contains($audit['action'], 'Dormancy Policy'),
            'policy audit action must clearly read as an automatic dormancy disable, got: ' . $audit['action']
        );
        $assert($audit['user_id'] === null, 'policy audit must record system as actor');
        $assert($audit['ip_address'] === 'system', 'policy audit must record the system actor address');
        $assert($audit['module'] === 'User Access', 'policy audit must live in the user lifecycle module');
        $new = json_decode((string)$audit['new_value'], true);
        $assert(
            ($new['actor_role'] ?? null) === 'system' && ($new['status'] ?? null) === 'disabled',
            'policy audit payload must record the system actor and Disabled status'
        );
        $previous = json_decode((string)$audit['previous_value'], true);
        $assert(($previous['status'] ?? null) === 'active', 'policy audit must retain the prior status for history');
        $categories[(int)$audit['record_id']] = (string)$audit['category'];
    }
    $assert(
        ($categories[11] ?? null) === 'security',
        'disabling a privileged Administrator must be categorized as security'
    );
    $assert(
        ($categories[7] ?? null) === 'store_operation',
        'disabling Store staff must remain visible to the Administrator'
    );

    $expectedWarnRecipients = [3 => [1, 2, 9, 11], 5 => [1, 2, 9, 11], 6 => [1, 2, 9, 11], 12 => [1, 2, 9]];
    foreach ($expectedWarnRecipients as $targetId => $expectedRecipients) {
        $rows = $warningsFor($targetId);
        $assert(
            count($rows) === count($expectedRecipients),
            'the warn threshold must notify Super Administrator and Administrator recipients, got '
                . count($rows) . ' for target ' . $targetId
        );
        $assert(
            array_map(static fn(array $row): int => (int)$row['user_id'], $rows) === $expectedRecipients,
            'warn recipients must be the active Super Administrator and Administrators excluding the target'
        );
        foreach ($rows as $row) {
            $assert($row['type'] === 'system', 'warn must arrive through the notification center');
            $assert((int)$row['is_read'] === 0, 'warn must arrive unread');
            $assert(str_contains($row['title'], 'Dormant Account'), 'warn title must use Dormant Account vocabulary, got: ' . $row['title']);
            $assert(str_contains($row['message'], 'Dormancy Policy'), 'warn message must name the Dormancy Policy');
        }
    }
    $assert(count($warningsFor(4)) === 0, 'accounts under the warn threshold must receive no in-app warning');
    $assert(
        str_contains($warningsFor(3)[0]['title'], 'Warn Cashier'),
        'warn title must identify the Dormant Account holder'
    );
    $neverWarnedWarning = $warningsFor(12)[0] ?? null;
    $assert(
        $neverWarnedWarning !== null && str_contains($neverWarnedWarning['message'], '25 days'),
        'warn copy must state days measured from creation for never-logged-in accounts'
    );
    foreach ([9, 10] as $protectedId) {
        $assert(
            count($warningsFor($protectedId)) === 0,
            'out-of-scope accounts must never receive dormancy warnings'
        );
    }

    $assert(
        count($notifier->delivered) === 5,
        'run one must deliver exactly the expected holder emails through the injected notifier, got '
            . count($notifier->delivered)
    );
    $assert(count($emailsTo('warn@example.test')) === 1, 'the warn threshold must email the holder once');
    $warnMail = $emailsTo('warn@example.test')[0];
    $assert(
        str_contains(strtolower($warnMail['subject'] . ' ' . $warnMail['message']), 'sign in soon'),
        'warn email must be a plain-language sign-in-soon notice, got: ' . $warnMail['subject']
    );
    $assert(
        str_contains(strtolower($warnMail['message']), 'disabled'),
        'warn email must plainly say what the Dormancy Policy does at the threshold'
    );
    $assert(count($emailsTo('boundary@example.test')) === 1, 'every account in the warn window must receive the holder notice');
    $assert(count($emailsTo('neverwarn@example.test')) === 1, 'warn measured from creation must email the holder');
    $assert(count($emailsTo('disable@example.test')) === 1, 'policy disable must email the holder once');
    $disableMail = $emailsTo('disable@example.test')[0];
    $assert(
        str_contains(strtolower($disableMail['subject']), 'disabled'),
        'policy disable email must plainly say the account was disabled, got: ' . $disableMail['subject']
    );
    $assert(count($emailsTo('admina@example.test')) === 1, 'a policy-disabled Administrator must receive the holder email');
    $assert(count($emailsTo('fresh@example.test')) === 0, 'accounts under the warn threshold must receive no email');
    $assert(count($emailsTo('oldsuper@example.test')) === 0, 'out-of-scope accounts must never receive dormancy emails');
    foreach ($notifier->delivered as $mail) {
        $assert(
            !str_contains(strtolower($mail['subject'] . ' ' . $mail['message']), 'inactive'),
            'holder emails must use glossary vocabulary, not inactive'
        );
    }

    $pdo->exec("UPDATE users SET status = 'active', disabled_at = NULL WHERE user_id = 11");
    $pdo->exec("UPDATE users SET status = 'disabled' WHERE user_id = 2");
    $second = $runner->run();
    $assert(
        $second['skipped_last_admin'] === [11],
        'the last active Administrator must never be policy-disabled, got ' . json_encode($second['skipped_last_admin'])
    );
    $assert($second['disabled'] === [], 'the last active Administrator must be skipped rather than disabled');
    $assert(
        $second['warned'] === [11],
        'the warn must still fire for the protected last Administrator, got ' . json_encode($second['warned'])
    );
    $guarded = $user(11);
    $assert(
        $guarded['status'] === 'active' && $guarded['disabled_at'] === null,
        'the last active Administrator must remain an active account'
    );
    $assert(
        (int)$guarded['session_version'] === 2,
        'a skipped disable must not bump session_version'
    );
    $guardedWarnings = $warningsFor(11);
    $assert(
        array_map(static fn(array $row): int => (int)$row['user_id'], $guardedWarnings) === [1, 9],
        'with the other Administrator disabled, the Super Administrator recipients must receive the in-app warn'
    );
    $assert(
        count($emailsTo('admina@example.test')) === 2,
        'the protected Administrator must still receive the warn email'
    );
    $guardedMail = $emailsTo('admina@example.test')[1];
    $assert(
        str_contains(strtolower($guardedMail['subject'] . ' ' . $guardedMail['message']), 'sign in soon'),
        'the guard-skip warn must email the holder a sign-in-soon notice, got: ' . $guardedMail['subject']
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE action LIKE '%Dormancy Policy%'")->fetchColumn() === 3,
        'a skipped disable must not write additional audit records'
    );

    $notificationsBefore = (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    $emailsBefore = count($notifier->delivered);
    $third = $runner->run();
    $assert($third['disabled'] === [] && $third['warned'] === [], 'a repeated run at the same clock must not repeat side effects');
    $assert(
        $third['skipped_last_admin'] === [11],
        'the last active Administrator guard must keep reporting on repeated runs'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $notificationsBefore,
        'dormancy warnings must not duplicate within one Dormant Account period'
    );
    $assert(
        count($notifier->delivered) === $emailsBefore,
        'holder emails must not repeat within one Dormant Account period'
    );
    $assert($user(2)['status'] === 'disabled', 'an already-disabled account must stay untouched on a repeated run');
} catch (Throwable $exception) {
    $failures[] = 'Dormancy runner contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Dormancy runner contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Dormancy runner contract: passed\n";
