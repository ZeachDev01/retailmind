<?php
// Page-level contract for the Store Staff availability query seam (ticket #38).
//
// Highest seam reachable from the CLI: the Store Staff page controller delegates
// to UserLifecycleService::availability() under the page's existing
// staff-management capability. This contract asserts the externally visible
// behavior (taken/free booleans per field, trimmed + case-insensitive, empty
// email always free, unprivileged callers denied, booleans only, read-only)
// plus the page wiring that exposes it.
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
$expectDenied = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (DomainException $exception) {
        // Expected staff-management capability rejection.
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
    $insertStaff = static function (string $username, ?string $email) use ($pdo, $roleId, $storeId): int {
        $stmt = $pdo->prepare('INSERT INTO users (full_name, username, email, password_hash, role_id, must_change_password, branch_id) VALUES (?, ?, ?, ?, ?, 0, ?)');
        $stmt->execute(['Seeded Staff', $username, $email, password_hash('StaffPassword123', PASSWORD_DEFAULT), $roleId('cashier'), $storeId]);
        return (int)$pdo->lastInsertId();
    };

    $insertStaff('TakenUser', 'taken@example.test');
    $service = new UserLifecycleService($pdo, new RoleCapabilityPolicy(), new StoreScope($pdo));

    // Existing username reports taken; free value reports free.
    $assert($service->availability('admin', 'TakenUser', '')['username_taken'] === true, 'existing username must report taken');
    $assert($service->availability('admin', 'free_username', '')['username_taken'] === false, 'free username must report free');

    // Case and whitespace variants report taken.
    $assert($service->availability('admin', 'takenuser', '')['username_taken'] === true, 'lowercase variant of an existing username must report taken');
    $assert($service->availability('admin', '  TAKENUSER  ', '')['username_taken'] === true, 'padded uppercase variant of an existing username must report taken');

    // Existing email reports taken; empty email always reports free; free email reports free.
    $assert($service->availability('admin', '', 'taken@example.test')['email_taken'] === true, 'existing email must report taken');
    $assert($service->availability('admin', '', '  TAKEN@EXAMPLE.TEST  ')['email_taken'] === true, 'padded case variant of an existing email must report taken');
    $assert($service->availability('admin', '', '')['email_taken'] === false, 'empty email must report free');
    $assert($service->availability('admin', '', '   ')['email_taken'] === false, 'whitespace-only email must report free');
    $assert($service->availability('admin', '', 'free@example.test')['email_taken'] === false, 'free email must report free');

    // Per-field independence: one colliding field must not flip the other.
    $both = $service->availability('admin', 'TakenUser', 'free@example.test');
    $assert($both === ['username_taken' => true, 'email_taken' => false], 'colliding username with free email must report only the username taken');

    // Empty username never blocks the field.
    $assert($service->availability('admin', '   ', '') === ['username_taken' => false, 'email_taken' => false], 'empty username must report free');

    // Super Administrator shares the same guardrail.
    $assert($service->availability('super_admin', 'TakenUser', 'taken@example.test') === ['username_taken' => true, 'email_taken' => true], 'Super Administrator must see the same taken flags');

    // Callers without the staff-management capability are denied.
    $expectDenied(
        fn() => $service->availability('cashier', 'TakenUser', 'taken@example.test'),
        'Cashier must be denied the availability query'
    );
    $expectDenied(
        fn() => $service->availability('inventory_manager', 'free_username', 'free@example.test'),
        'Inventory Manager must be denied the availability query'
    );

    // Response carries only existence booleans, no account details.
    $shape = $service->availability('admin', 'TakenUser', 'taken@example.test');
    $assert(array_keys($shape) === ['username_taken', 'email_taken'], 'availability response must carry only existence booleans');
    $assert(is_bool($shape['username_taken']) && is_bool($shape['email_taken']), 'availability flags must be booleans');
    $assert(!str_contains(json_encode($shape), 'TakenUser'), 'availability response must not leak account details');

    // Read-only: no accounts created, no audit written.
    $assert((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'availability query must not create accounts');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() === 0, 'availability query must not write audit records');

    // Page wiring: the Store Staff page exposes the seam under its existing capability.
    $page = (string)file_get_contents(__DIR__ . '/../../frontend/components/user_manager/user_manager.php');
    $capabilityPos = strpos($page, 'require_capability(');
    $seamPos = strpos($page, "'availability'");
    $assert($capabilityPos !== false && $seamPos !== false && $capabilityPos < $seamPos, 'availability seam must sit behind the page staff-management capability check');
    foreach (['$_GET', '->availability(', 'application/json', 'username_taken', 'email_taken', 'exit;'] as $needle) {
        $assert(str_contains($page, $needle), "Store Staff page must wire the availability seam ({$needle})");
    }
} catch (Throwable $exception) {
    $failures[] = 'Store Staff availability contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Store Staff availability contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Store Staff availability contract: passed\n";
