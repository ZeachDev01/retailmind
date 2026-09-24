<?php
// Staff account rules contract (ticket #66): Username shape rule (@/space
// rejection) + email uniqueness, verified through the staff account service
// seam in the same spec-run style (behavior and sentences, not query text).
//
// Externally visible behavior only:
// - Staff creation and update reject Usernames containing `@` or whitespace
//   with an easy inline message (passes OperatorAlert as a plain domain
//   sentence, no technical leak).
// - Usernames with letters, numbers, `.`, `_`, `-` keep working.
// - Email stays optional (empty means NULL, never collides); email-less
//   accounts are unaffected.
// - Email stays unique on create and update (duplicate rejected, no second
//   account); the availability seam keeps backing live duplicate notices.
// - Changing a staff email invalidates the old address immediately (old
//   address no longer stored, reports free; new address stored, reports
//   taken). No schema change, no backfill, no verified-only gate.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\UserLifecycleService;
use App\Store\StoreScope;
use App\Support\OperatorAlert;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$expectShapeRejected = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (InvalidArgumentException $exception) {
        $text = $exception->getMessage();
        if (!str_contains($text, '@')) {
            $failures[] = $message . " (inline message must name '@', got: {$text})";
        }
        if (!str_contains(strtolower($text), 'space')) {
            $failures[] = $message . " (inline message must name spaces, got: {$text})";
        }
        if (OperatorAlert::isTechnicalMessage($text)) {
            $failures[] = $message . " (inline message must stay an easy sentence, got technical text: {$text})";
        }
    }
};
$expectUniqueRejected = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (Throwable $exception) {
        // Any rejection keeps the store unambiguous: shape error, capability
        // denial, or the unique-constraint race fallback all block submit.
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

    $adminId = $insertActor('Store Admin', 'admin');
    $service = new UserLifecycleService($pdo, new RoleCapabilityPolicy(), new StoreScope($pdo));
    $countUsers = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $buildAccount = static fn(string $username, $email): array => [
        'full_name' => 'Contract Staff',
        'username' => $username,
        'email' => $email,
        'password_hash' => password_hash('StaffPassword123', PASSWORD_DEFAULT),
        'role' => 'cashier',
    ];

    // Creation rejects `@` in the Username with an easy inline message.
    $expectShapeRejected(
        fn() => $service->create($adminId, 'admin', $buildAccount('cashier@example.test', 'shape_at@example.test')),
        'creation must reject a Username containing @'
    );

    // Creation rejects whitespace inside the Username (space, tab, newline).
    foreach (['cashier one', "cashier\tone", "cashier\none"] as $spaced) {
        $expectShapeRejected(
            fn() => $service->create($adminId, 'admin', $buildAccount($spaced, null)),
            "creation must reject a Username containing whitespace (" . json_encode($spaced) . ")"
        );
    }
    $assert($service->availability('admin', 'cashier@example.test', '')['username_taken'] === false, 'rejected @ Username must not be stored');

    // Update rejects `@` and whitespace with the same easy inline message.
    $editableId = $service->create($adminId, 'admin', $buildAccount('editable.cashier_01', 'editable@example.test'));
    $expectShapeRejected(
        fn() => $service->update($adminId, 'admin', $editableId, [
            'full_name' => 'Contract Staff', 'username' => 'new@example.test',
            'email' => 'editable@example.test', 'role' => 'cashier',
        ]),
        'update must reject a Username containing @'
    );
    $expectShapeRejected(
        fn() => $service->update($adminId, 'admin', $editableId, [
            'full_name' => 'Contract Staff', 'username' => 'new name',
            'email' => 'editable@example.test', 'role' => 'cashier',
        ]),
        'update must reject a Username containing a space'
    );
    $assert($service->get($editableId)['username'] === 'editable.cashier_01', 'rejected update must leave the stored Username unchanged');

    // Letters, numbers, `.`, `_`, `-` keep working on create and update.
    $dottedId = $service->create($adminId, 'admin', $buildAccount('john.doe_99-x', 'john.doe@example.test'));
    $assert($service->get($dottedId)['username'] === 'john.doe_99-x', 'dotted/underscored/hyphenated Username must keep working on create');
    $service->update($adminId, 'admin', $dottedId, [
        'full_name' => 'Contract Staff', 'username' => 'Jane.DOE_01-y',
        'email' => 'john.doe@example.test', 'role' => 'cashier',
    ]);
    $assert($service->get($dottedId)['username'] === 'Jane.DOE_01-y', 'dotted/underscored/hyphenated Username must keep working on update');

    // Email stays optional: empty, whitespace-only, and NULL all store NULL
    // and never collide, so email-less accounts keep working by Username.
    $noEmailOne = $service->create($adminId, 'admin', $buildAccount('noemail.one', ''));
    $noEmailTwo = $service->create($adminId, 'admin', $buildAccount('noemail.two', '   '));
    $noEmailThree = $service->create($adminId, 'admin', $buildAccount('noemail-three', null));
    foreach ([$noEmailOne, $noEmailTwo, $noEmailThree] as $id) {
        $assert($service->get($id)['email'] === null, 'empty email must be stored as NULL (no email sign-in for that person)');
    }
    $assert($service->availability('admin', '', '')['email_taken'] === false, 'empty email must always report free');

    // Email stays unique on create: duplicate rejected, no second account.
    $holderId = $service->create($adminId, 'admin', $buildAccount('email.holder', 'unique@example.test'));
    $assert($holderId > 0, 'first holder of an email must succeed');
    $beforeDuplicate = $countUsers();
    $expectUniqueRejected(
        fn() => $service->create($adminId, 'admin', $buildAccount('email.copycat', 'unique@example.test')),
        'creation with a duplicate email must be rejected'
    );
    $assert($countUsers() === $beforeDuplicate, 'rejected duplicate email must not create a second account');
    $assert($service->availability('admin', '', 'unique@example.test')['email_taken'] === true, 'live duplicate notice seam must keep reporting the taken email');

    // Email stays unique on update: taking another account's address fails.
    $expectUniqueRejected(
        fn() => $service->update($adminId, 'admin', $editableId, [
            'full_name' => 'Contract Staff', 'username' => 'editable.cashier_01',
            'email' => 'unique@example.test', 'role' => 'cashier',
        ]),
        'update to a duplicate email must be rejected'
    );
    $assert($service->get($editableId)['email'] === 'editable@example.test', 'rejected update must leave the stored email unchanged');

    // Changing a staff email immediately invalidates the old address: the old
    // value is gone from storage and reports free, the new value is stored.
    $service->update($adminId, 'admin', $holderId, [
        'full_name' => 'Contract Staff', 'username' => 'email.holder',
        'email' => 'rotated@example.test', 'role' => 'cashier',
    ]);
    $assert($service->get($holderId)['email'] === 'rotated@example.test', 'changed email must be stored immediately');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM users WHERE email = 'unique@example.test'")->fetchColumn() === 0, 'old email must no longer resolve to any account after the change');
    $assert($service->availability('admin', '', 'unique@example.test')['email_taken'] === false, 'old email must report free for live notices after the change');
    $assert($service->availability('admin', '', 'rotated@example.test')['email_taken'] === true, 'new email must report taken for live notices after the change');

    // Page wiring: creation and update surfaces carry the same easy inline
    // rule next to the Username field; the submit-time duplicate fallback and
    // the live-notice blocking behavior stay in place.
    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);
    $page = $read('src/frontend/components/user_manager/user_manager.php');
    $addModal = $read('src/frontend/components/user_manager/modals/add_user_modal.php');
    $editModal = $read('src/frontend/components/user_manager/modals/manage_user_modal.php');
    $script = $read('src/frontend/assets/js/store-staff-availability.js');
    $legacy = $read('src/backend/legacy/routes/admin/manage_users.php');

    foreach (['add dialog' => $addModal, 'edit dialog' => $editModal] as $label => $modal) {
        $assert(str_contains($modal, 'cannot contain @'), "{$label} must carry the easy Username shape rule inline");
    }
    $assert(str_contains($page, 'Username or email may already exist'), 'submit-time duplicate rejection must remain as the enforcing fallback');
    $assert(str_contains($script, 'username_taken') && str_contains($script, 'email_taken'), 'live duplicate notices must keep reading both taken flags');
    $assert(str_contains($script, 'disabled'), 'live duplicate notices must keep blocking submit on duplicates');
    $assert(str_contains($legacy, 'cannot contain @') || str_contains($legacy, 'usernameShapeError'), 'legacy Manage Users route must enforce the same Username shape rule');
} catch (Throwable $exception) {
    $failures[] = 'Staff account rules contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Staff account rules contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Staff account rules contract: passed\n";
