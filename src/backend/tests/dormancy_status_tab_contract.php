<?php
// Manage Account Status tab dormancy visibility contract (ticket #64).
//
// Proves at the Status tab seam: last login for active accounts, the
// computed automatic-deactivation date for active in-scope accounts under
// the current Dormancy Policy thresholds, the plain help line about
// automatic disable with history kept and sessions revoked, the plain
// automatic-disable explanation line for policy-disabled accounts, an
// Active/Disabled-only dropdown, and glossary copy needles (no Avoid-list
// synonyms) asserted for the master test runner.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\DormancyPolicyService;
use App\Authorization\DormancyRunner;
use App\Authorization\DormancyStatusTab;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

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
                VALUES ('dormancy_disable_days', '45'), ('dormancy_warn_days', '30')");
    $pdo->exec('CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)');
    $pdo->exec("INSERT INTO roles (role_id, role_name) VALUES
        (1, 'super_admin'), (2, 'admin'), (3, 'inventory_manager'), (4, 'cashier')");
    $pdo->exec('CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        username TEXT NOT NULL UNIQUE,
        email TEXT NULL,
        password_hash TEXT NOT NULL,
        role_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT \'active\',
        session_version INTEGER NOT NULL DEFAULT 1,
        must_change_password INTEGER NOT NULL DEFAULT 1,
        branch_id TEXT NULL,
        is_recovery_account INTEGER NOT NULL DEFAULT 0,
        disabled_at TEXT NULL,
        last_login_at TEXT NULL,
        created_at TEXT NULL
    )');
    $pdo->exec('CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        action TEXT NOT NULL,
        category TEXT NOT NULL,
        module TEXT NULL,
        record_id INTEGER NULL,
        previous_value TEXT NULL,
        new_value TEXT NULL,
        ip_address TEXT NULL,
        created_at TEXT NULL
    )');

    $insert = $pdo->prepare(
        'INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status,
                            is_recovery_account, disabled_at, last_login_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $fixtures = [
        // id, name, username, role, status, recovery, disabled_at, last_login, created
        [1, 'Super Owner', 'super_owner', 1, 'active', 0, null, '2026-09-14 14:14:22', '2026-08-01 08:00:00'],
        [2, 'Active Cashier', 'active_cashier', 4, 'active', 0, null, '2026-08-10 09:00:00', '2026-07-01 08:00:00'],
        [3, 'Never Signed In', 'never_signed_in', 4, 'active', 0, null, null, '2026-09-01 08:00:00'],
        [4, 'Policy Disabled Manager', 'policy_disabled', 3, 'disabled', 0, '2026-09-24 12:00:00', '2026-08-01 09:00:00', '2026-06-01 08:00:00'],
        [5, 'Manually Disabled Admin', 'manual_disabled', 2, 'disabled', 0, '2026-09-20 10:00:00', '2026-09-01 09:00:00', '2026-06-01 08:00:00'],
        [6, 'Policy Then Manual', 'policy_then_manual', 4, 'disabled', 0, '2026-09-22 10:00:00', '2026-07-15 09:00:00', '2026-06-01 08:00:00'],
        [7, 'Manual Then Policy', 'manual_then_policy', 4, 'disabled', 0, '2026-09-23 10:00:00', '2026-07-15 09:00:00', '2026-06-01 08:00:00'],
        [8, 'Sealed Recovery', 'sealed_recovery', 2, 'active', 1, null, null, '2026-06-01 08:00:00'],
        [9, 'Active Inventory Manager', 'active_manager', 3, 'active', 0, null, '2026-09-10 16:30:00', '2026-05-01 08:00:00'],
    ];
    foreach ($fixtures as [$id, $name, $username, $roleId, $status, $recovery, $disabledAt, $lastLogin, $created]) {
        $insert->execute([$id, $name, $username, $username . '@example.test', 'hash-' . $username, $roleId, $status, $recovery, $disabledAt, $lastLogin, $created]);
    }

    $audit = $pdo->prepare(
        'INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $policyPayload = json_encode(['status' => 'disabled', 'disabled_by' => 'dormancy_policy', 'dormant_days' => 47]);
    // Policy disable for user 4.
    $audit->execute([null, DormancyRunner::DISABLE_ACTION, 'store_operation', 'User Access', 4,
        json_encode(['status' => 'active']), $policyPayload, 'system']);
    // Manual disable for user 5.
    $audit->execute([2, 'User disabled', 'security', 'User Access', 5,
        json_encode(['status' => 'active']), json_encode(['status' => 'disabled', 'actor_role' => 'admin']), '127.0.0.1']);
    // User 6: policy disable first, later manual disable wins.
    $audit->execute([null, DormancyRunner::DISABLE_ACTION, 'store_operation', 'User Access', 6,
        json_encode(['status' => 'active']), json_encode(['status' => 'disabled', 'dormant_days' => 44]), 'system']);
    $audit->execute([2, 'User disabled', 'security', 'User Access', 6,
        json_encode(['status' => 'active']), json_encode(['status' => 'disabled', 'actor_role' => 'admin']), '127.0.0.1']);
    // User 7: manual disable first, later policy disable wins.
    $audit->execute([2, 'User disabled', 'security', 'User Access', 7,
        json_encode(['status' => 'active']), json_encode(['status' => 'disabled', 'actor_role' => 'admin']), '127.0.0.1']);
    $audit->execute([null, DormancyRunner::DISABLE_ACTION, 'store_operation', 'User Access', 7,
        json_encode(['status' => 'active']), json_encode(['status' => 'disabled', 'dormant_days' => 52]), 'system']);

    $user = static function (int $id) use ($pdo): array {
        $statement = $pdo->prepare(
            'SELECT u.*, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?'
        );
        $statement->execute([$id]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    };

    $tab = new DormancyStatusTab($pdo);
    $policy = new DormancyPolicyService($pdo);

    // --- Active in-scope account: last login + computed automatic-deactivation date ---
    $activeCashier = $tab->view($user(2));
    $assert($activeCashier['in_scope'] === true, 'a Cashier account must be in scope for the Dormancy Policy');
    $assert(
        $activeCashier['last_login'] === date('m-d-y h:i A', strtotime('2026-08-10 09:00:00')),
        'the Status tab must show last login for active accounts, got ' . $activeCashier['last_login']
    );
    $expectedDate = date('m-d-y', strtotime('2026-08-10 09:00:00 +' . $policy->disableDays() . ' days'));
    $assert(
        $activeCashier['auto_disable_date'] === $expectedDate,
        'the Status tab must show the computed automatic-deactivation date under current thresholds, got '
            . var_export($activeCashier['auto_disable_date'], true) . ' expected ' . $expectedDate
    );
    $assert($activeCashier['policy_disabled_line'] === null, 'an active account must not show the automatic-disable line');

    // --- Never-logged-in active account: measured from creation ---
    $neverSignedIn = $tab->view($user(3));
    $assert($neverSignedIn['last_login'] === 'Never', 'an account with no login must show Never for last login');
    $assert(
        $neverSignedIn['auto_disable_date'] === date('m-d-y', strtotime('2026-09-01 08:00:00 +' . $policy->disableDays() . ' days')),
        'the automatic-deactivation date for a never-logged-in account must be measured from creation, got '
            . var_export($neverSignedIn['auto_disable_date'], true)
    );

    // --- Computed date follows the current Dormancy Policy thresholds ---
    $pdo->exec("UPDATE platform_settings SET setting_value = '60' WHERE setting_key = 'dormancy_disable_days'");
    $raised = (new DormancyStatusTab($pdo))->view($user(2));
    $assert(
        $raised['auto_disable_date'] === date('m-d-y', strtotime('2026-08-10 09:00:00 +60 days')),
        'the computed date must follow the current Dormancy Policy disable threshold, got '
            . var_export($raised['auto_disable_date'], true)
    );
    $pdo->exec("UPDATE platform_settings SET setting_value = '45' WHERE setting_key = 'dormancy_disable_days'");

    // --- Out-of-scope accounts never show a computed deactivation date ---
    $superOwner = $tab->view($user(1));
    $assert($superOwner['in_scope'] === false, 'the Super Administrator account must stay out of Dormancy Policy scope');
    $assert($superOwner['auto_disable_date'] === null, 'an out-of-scope account must not show an automatic-deactivation date');
    $assert(
        $superOwner['last_login'] === date('m-d-y h:i A', strtotime('2026-09-14 14:14:22')),
        'last login must still show for an active out-of-scope account'
    );
    $recovery = $tab->view($user(8));
    $assert($recovery['in_scope'] === false, 'the Recovery Account must stay out of Dormancy Policy scope');
    $assert($recovery['auto_disable_date'] === null, 'the Recovery Account must not show an automatic-deactivation date');
    $activeManager = $tab->view($user(9));
    $assert(
        $activeManager['in_scope'] === true && $activeManager['auto_disable_date'] !== null,
        'an active in-scope Inventory Manager must show the computed automatic-deactivation date'
    );

    // --- Policy-disabled account: plain automatic-disable explanation line ---
    $policyDisabled = $tab->view($user(4));
    $assert(
        $policyDisabled['policy_disabled_line'] === 'Disabled automatically — no login for 47 days (policy)',
        'a policy-disabled account must show the plain automatic-disable line, got '
            . var_export($policyDisabled['policy_disabled_line'], true)
    );
    $assert(
        $policyDisabled['auto_disable_date'] === null,
        'a disabled account must not show a future automatic-deactivation date'
    );

    // --- Manual disable never claims the policy did it ---
    $manualDisabled = $tab->view($user(5));
    $assert(
        $manualDisabled['policy_disabled_line'] === null,
        'a manually disabled account must not show the automatic-disable line'
    );
    $assert(
        $manualDisabled['last_login'] === date('m-d-y h:i A', strtotime('2026-09-01 09:00:00')),
        'last login must remain visible on the Status tab'
    );

    // --- Latest status-change audit wins ---
    $policyThenManual = $tab->view($user(6));
    $assert(
        $policyThenManual['policy_disabled_line'] === null,
        'a later manual disable must clear the automatic-disable explanation'
    );
    $manualThenPolicy = $tab->view($user(7));
    $assert(
        $manualThenPolicy['policy_disabled_line'] === 'Disabled automatically — no login for 52 days (policy)',
        'a later policy disable must show the automatic-disable explanation, got '
            . var_export($manualThenPolicy['policy_disabled_line'], true)
    );

    // --- Plain help line copy (glossary vocabulary, no Avoid-list synonyms) ---
    $helpLine = DormancyStatusTab::HELP_LINE;
    $assert(
        str_contains($helpLine, 'Dormant Accounts') && str_contains($helpLine, 'Dormancy Policy'),
        'the help line must use Dormant Account and Dormancy Policy vocabulary, got: ' . $helpLine
    );
    $assert(
        str_contains($helpLine, 'History is kept') && str_contains($helpLine, 'sessions are revoked'),
        'the help line must explain history retention and session revocation, got: ' . $helpLine
    );
    $policyLineNeedle = 'Disabled automatically — no login for 45 days (policy)';
    foreach ([$helpLine, $policyLineNeedle] as $copy) {
        foreach ([
            'inactive account', 'unused account', 'stale login', 'auto-disable rule',
            'inactivity timeout', 'session timeout', 'deleted user', 'removed history',
        ] as $avoid) {
            $assert(
                !str_contains(strtolower($copy), $avoid),
                "Status tab copy must avoid glossary synonym '{$avoid}', got: {$copy}"
            );
        }
    }

    // --- Page and modal copy needles ---
    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);
    $modal = $read('src/frontend/components/user_manager/modals/manage_user_modal.php');
    $page = $read('src/frontend/components/user_manager/user_manager.php');
    $serviceSource = $read('src/backend/app/Authorization/DormancyStatusTab.php');
    $runAll = $read('src/backend/tests/run_all.sh');

    $assert($modal !== '', 'manage_user_modal.php must be readable');
    $assert($page !== '', 'user_manager.php must be readable');
    $assert($serviceSource !== '', 'DormancyStatusTab.php must be readable');

    $assert(
        str_contains($modal, 'data-user-drawer-panel="status"'),
        'the Manage Account drawer must keep its Status tab'
    );
    $assert(
        str_contains($modal, 'DormancyStatusTab::HELP_LINE'),
        'the Status tab must render the plain dormancy help line'
    );
    foreach (['drawerLastLogin', 'drawerAutoDisableDate', 'drawerPolicyDisabledLine'] as $needle) {
        $assert(str_contains($modal, $needle), "the Status tab must render the {$needle} hook");
    }
    foreach (['data-last-login', 'data-auto-disable-date', 'data-policy-disabled'] as $needle) {
        $assert(str_contains($page, $needle), "the Store Staff page must expose {$needle} for the Status tab");
    }
    $assert(
        str_contains($page, 'DormancyStatusTab'),
        'the Store Staff page must compute Status tab dormancy visibility through DormancyStatusTab'
    );
    $assert(
        str_contains($serviceSource, 'public const HELP_LINE'),
        'the help line copy must live in DormancyStatusTab'
    );
    $assert(
        str_contains($serviceSource, $helpLine),
        'the service source must contain the help line copy needle'
    );
    $assert(
        str_contains($serviceSource, 'Disabled automatically — no login for '),
        'the service source must contain the automatic-disable explanation needle'
    );

    // --- Dropdown stays Active/Disabled only; no new stored user status ---
    $matched = preg_match('/<select[^>]*id="drawerStatus"[^>]*>.*?<\/select>/s', $modal, $selectBlock);
    $assert($matched === 1, 'the Status tab must keep its account status dropdown');
    $options = [];
    if ($matched === 1) {
        preg_match_all('/<option[^>]*value="([^"]+)"/', $selectBlock[0], $options);
        $options = $options[1];
    }
    $assert(
        $options === ['active', 'disabled'],
        'the status dropdown must offer Active/Disabled only, got ' . json_encode($options)
    );
    $assert(
        !str_contains($modal, 'value="dormant"') && !str_contains($modal, 'value="inactive"'),
        'no third user status may be offered on the Status tab'
    );

    // --- Master runner registers this contract ---
    $assert(
        str_contains($runAll, 'dormancy_status_tab_contract.php'),
        'run_all.sh must run the Manage Account Status tab contract'
    );
} catch (Throwable $exception) {
    $failures[] = 'Manage Account Status tab contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Manage Account Status tab contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Manage Account Status tab contract: passed\n";
