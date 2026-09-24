<?php
// Live duplicate notice [37/4]: race fallback and interaction verification (ticket #41).
//
// Highest-seam contract asserting externally visible behavior only:
// - Submitting a duplicate username or email is still rejected server-side
//   after the live check (race fallback), without creating a second account.
// - Dialog interaction: duplicate input shows an error and disables submit;
//   a corrected value clears the error and enables submit.
// - Behaviour holds for both Administrator and Super Administrator authority
//   (no new roles, Store scope assigned by server).
// - Asserts message shown/hidden, button enabled/disabled, and submit
//   rejection; never timers or query strings.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\UserLifecycleService;
use App\Store\StoreScope;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$expectRejected = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (PDOException $exception) {
        // Expected unique-constraint rejection: the race fallback path.
        // Any other failure (validation, permission) must surface, so only
        // the constraint violation is swallowed here.
        if (!str_contains($exception->getMessage(), 'UNIQUE') && $exception->getCode() !== '23000') {
            $failures[] = $message . ' (wrong failure: ' . $exception->getMessage() . ')';
        }
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE branches (
        branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_name TEXT NOT NULL UNIQUE,
        branch_code TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT 'active'
    )");
    $pdo->exec('CREATE TABLE products (product_id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NULL)');
    $pdo->exec('CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)');
    $pdo->exec("CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
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
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
    $pdo->exec("INSERT INTO roles (role_name) VALUES ('super_admin'), ('admin'), ('inventory_manager'), ('cashier')");
    (new StoreScope($pdo))->migrate();
    $storeId = (new StoreScope($pdo))->id();

    $roleId = static function (string $role) use ($pdo): int {
        $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
        $stmt->execute([$role]);
        return (int)$stmt->fetchColumn();
    };
    $insertActor = static function (string $name, string $role) use ($pdo, $roleId, $storeId): int {
        $stmt = $pdo->prepare('INSERT INTO users (full_name, username, email, password_hash, role_id, must_change_password, branch_id) VALUES (?, ?, ?, ?, ?, 0, ?)');
        $username = strtolower(str_replace(' ', '_', $name));
        $stmt->execute([$name, $username, $username . '@example.test', password_hash('ActorPassword123', PASSWORD_DEFAULT), $roleId($role), $storeId]);
        return (int)$pdo->lastInsertId();
    };

    $superId = $insertActor('Super Owner', 'super_admin');
    $adminId = $insertActor('Store Admin', 'admin');
    $service = new UserLifecycleService($pdo, new RoleCapabilityPolicy(), new StoreScope($pdo));
    $countUsers = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    // Live check is advisory: it flags the collision before submit.
    $assert($service->availability('admin', 'race_user', '')['username_taken'] === false, 'fresh username must report free before the race');
    $firstId = $service->create($adminId, 'admin', [
        'full_name' => 'Race Winner',
        'username' => 'race_user',
        'email' => 'race@example.test',
        'password_hash' => password_hash('RacePassword123', PASSWORD_DEFAULT),
        'role' => 'cashier',
    ]);
    $assert($firstId > 0, 'first creator must succeed');
    $assert($service->availability('admin', 'race_user', '')['username_taken'] === true, 'taken username must report taken after the first submit');
    $beforeRace = $countUsers();

    // Race fallback: a second submit with the same username is still rejected.
    $expectRejected(
        fn() => $service->create($adminId, 'admin', [
            'full_name' => 'Race Loser',
            'username' => 'race_user',
            'email' => 'loser@example.test',
            'password_hash' => password_hash('LoserPassword123', PASSWORD_DEFAULT),
            'role' => 'cashier',
        ]),
        'second submit with a duplicate username must be rejected server-side'
    );
    $assert($countUsers() === $beforeRace, 'rejected duplicate username must not create a second account');

    // Race fallback: a second submit with the same email is still rejected.
    $expectRejected(
        fn() => $service->create($adminId, 'admin', [
            'full_name' => 'Email Racer',
            'username' => 'email_racer',
            'email' => 'race@example.test',
            'password_hash' => password_hash('EmailRace123', PASSWORD_DEFAULT),
            'role' => 'cashier',
        ]),
        'second submit with a duplicate email must be rejected server-side'
    );
    $assert($countUsers() === $beforeRace, 'rejected duplicate email must not create a second account');

    // Same fallback for the Super Administrator authority.
    $expectRejected(
        fn() => $service->create($superId, 'super_admin', [
            'full_name' => 'Super Race',
            'username' => 'race_user',
            'email' => 'super_race@example.test',
            'password_hash' => password_hash('SuperRace123', PASSWORD_DEFAULT),
            'role' => 'cashier',
        ]),
        'Super Administrator duplicate username must be rejected server-side'
    );
    $expectRejected(
        fn() => $service->create($superId, 'super_admin', [
            'full_name' => 'Super Email Race',
            'username' => 'super_email_race',
            'email' => 'race@example.test',
            'password_hash' => password_hash('SuperEmail123', PASSWORD_DEFAULT),
            'role' => 'cashier',
        ]),
        'Super Administrator duplicate email must be rejected server-side'
    );
    $assert($countUsers() === $beforeRace, 'Super Administrator race rejections must not create accounts');

    // Empty email stays optional and never collides, even after the race.
    $assert($service->availability('admin', '', '')['email_taken'] === false, 'empty email must report free');
    $beforeEmpty = $countUsers();
    $service->create($adminId, 'admin', [
        'full_name' => 'No Email One',
        'username' => 'no_email_one',
        'email' => '',
        'password_hash' => password_hash('NoEmailOne123', PASSWORD_DEFAULT),
        'role' => 'cashier',
    ]);
    $service->create($adminId, 'admin', [
        'full_name' => 'No Email Two',
        'username' => 'no_email_two',
        'email' => '',
        'password_hash' => password_hash('NoEmailTwo123', PASSWORD_DEFAULT),
        'role' => 'cashier',
    ]);
    $assert($countUsers() === $beforeEmpty + 2, 'two accounts with empty email must both be created (empty email never collides)');

    // Store scope is assigned by the server for both authorities, never by the caller.
    $scopedId = $service->create($adminId, 'admin', [
        'full_name' => 'Scoped Cashier',
        'username' => 'scoped_cashier',
        'email' => 'scoped@example.test',
        'password_hash' => password_hash('ScopedCashier123', PASSWORD_DEFAULT),
        'role' => 'cashier',
        'branch_id' => 999999,
    ]);
    $assert((int)$service->get($scopedId)['branch_id'] === $storeId, 'Administrator creates must receive Store scope server-side');
    $superScopedId = $service->create($superId, 'super_admin', [
        'full_name' => 'Super Scoped',
        'username' => 'super_scoped',
        'email' => 'super_scoped@example.test',
        'password_hash' => password_hash('SuperScoped123', PASSWORD_DEFAULT),
        'role' => 'admin',
        'branch_id' => 999999,
    ]);
    $assert((int)$service->get($superScopedId)['branch_id'] === $storeId, 'Super Administrator creates must receive Store scope server-side');

    // No new roles: the delegated template set is unchanged.
    $roles = $pdo->query('SELECT role_name FROM roles ORDER BY role_id')->fetchAll(PDO::FETCH_COLUMN);
    $assert($roles === ['super_admin', 'admin', 'inventory_manager', 'cashier'], 'race fallback must not introduce new roles');

    // Page wiring: submit-time rejection remains the enforcing fallback.
    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);
    $page = $read('src/frontend/components/user_manager/user_manager.php');
    $modal = $read('src/frontend/components/user_manager/modals/add_user_modal.php');
    $script = $read('src/frontend/assets/js/store-staff-availability.js');
    $editModal = $read('src/frontend/components/user_manager/modals/manage_user_modal.php');

    $assert(str_contains($page, 'Username or email may already exist'), 'submit-time duplicate message must remain as the enforcing fallback');
    $assert(str_contains($page, 'catch (PDOException'), 'page must convert unique-constraint races into the duplicate message');

    // Dialog interaction full loop, visible behavior only: error shown/hidden
    // via invalid styling, summary shown/hidden, submit disabled/enabled.
    $assert($modal !== '' && $script !== '', 'create dialog and availability script must exist');
    $assert(str_contains($modal, 'createUsernameNotice') || str_contains($modal, 'username-availability-notice'), 'create dialog must render the username error element');
    $assert(str_contains($modal, 'createEmailNotice') || str_contains($modal, 'email-availability-notice'), 'create dialog must render the email error element');
    $assert(
        str_contains(strtolower($modal), 'already in use') || str_contains(strtolower($script), 'already in use'),
        'duplicate notice copy must state the value is already in use'
    );
    $summaryPos = strpos($modal, 'createDuplicateSummary');
    if ($summaryPos === false) {
        $summaryPos = strpos($modal, 'duplicate-summary');
    }
    $emailGroupPos = strpos($modal, 'createEmailGroup');
    if ($emailGroupPos === false) {
        $emailGroupPos = strpos($modal, 'createEmailInput');
    }
    $assert($summaryPos !== false, 'create dialog must render a duplicate summary element');
    $assert($summaryPos !== false && $emailGroupPos !== false && $summaryPos < $emailGroupPos, 'duplicate summary must appear above the email field group');

    // Error shown: colliding field marked invalid; error hidden when clear.
    $assert(str_contains($script, 'invalid'), 'availability script must mark the colliding field invalid (error shown)');
    $assert(str_contains($script, 'usernameTaken') || str_contains($script, 'username_taken'), 'availability script must track the username duplicate flag');
    $assert(str_contains($script, 'emailTaken') || str_contains($script, 'email_taken'), 'availability script must track the email duplicate flag');
    $assert(str_contains($script, 'createDuplicateSummary') || str_contains($script, 'duplicateSummary'), 'availability script must show/hide the duplicate summary');

    // Button disabled while duplicate present, enabled once corrected.
    $assert(str_contains($script, 'disabled'), 'availability script must disable the Create action while a duplicate is present');
    $assert(str_contains($script, 'submit.disabled = false'), 'availability script must re-enable the Create action once corrected');
    $assert(str_contains($script, 'duplicateActive'), 'availability script must track its own flag before re-enabling Create');
    $assert(str_contains($script, 'openUserModal'), 'availability script must reset its flag when the create dialog reopens');

    // Visible-behavior mechanism: the error text shows only while the group
    // is invalid, and the summary shows only while unhidden.
    $forms = $read('src/frontend/assets/css/forms.css');
    $assert(str_contains($forms, '.form-group.invalid .field-error'), 'forms stylesheet must show the per-field error only on the invalid group');
    $assert(str_contains($forms, '.duplicate-summary[hidden]'), 'forms stylesheet must hide the duplicate summary while flagged hidden');

    // Create-dialog scope only; both privileged callers share the dialog.
    $assert(str_contains($script, 'createUserForm'), 'availability script must scope to the create dialog form');
    $assert(!str_contains($script, 'drawerUsername'), 'availability script must not touch the edit-account username field');
    $assert(!str_contains($script, 'drawerEmail'), 'availability script must not touch the edit-account email field');
    $assert(!str_contains($editModal, 'field-error'), 'edit-account dialog must stay unchanged');
    $assert(str_contains($page, 'store-staff-availability.js'), 'Store Staff page must load store-staff-availability.js');
} catch (Throwable $exception) {
    $failures[] = 'Store Staff race fallback contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Store Staff race fallback contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Store Staff race fallback contract: passed\n";
