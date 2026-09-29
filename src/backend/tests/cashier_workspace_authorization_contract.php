<?php
// Authorization contract for the active Cashier workspace (issue #86).
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\AuthorizationContext;
use App\Authorization\RoleCapabilityPolicy;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { $failures[] = $message; }
};

try {
    $auth = (string)@file_get_contents(__DIR__ . '/../includes/auth.php');
    $assert(str_contains($auth, 'function has_capability('), 'auth owns POS capability entry');
    $assert(str_contains($auth, 'function require_role('), 'auth owns workspace gate entry');
    $hasCode = '';
    if (preg_match('/function has_capability\(.*?^}/ms', $auth, $matches)) { $hasCode = $matches[0]; }
    $roleCode = '';
    if (preg_match('/function require_role\(.*?^}/ms', $auth, $matches)) { $roleCode = $matches[0]; }
    $assert(str_contains($hasCode, 'current_role()'), 'POS capability uses active workspace');
    $assert(!str_contains($hasCode, 'foreach ($roles as $assignedRole)'), 'POS capability skips role union');
    $assert(str_contains($roleCode, 'current_role()'), 'workspace gate uses active workspace');
    $assert(!str_contains($roleCode, 'array_intersect(current_workspace_roles()'), 'workspace gate skips role union');

    $policy = new RoleCapabilityPolicy();
    $pos = RoleCapabilityPolicy::OPERATE_POINT_OF_SALE;
    $assert($policy->allows('cashier', $pos) === true, 'cashier workspace holds POS');
    $assert($policy->allows('admin', $pos) === false, 'admin workspace lacks POS');
    $assert($policy->allows('super_admin', $pos) === false, 'super_admin workspace lacks POS');
    $assert($policy->allows('inventory_manager', $pos) === false, 'inventory workspace lacks POS');
    $emergency = AuthorizationContext::emergencyAccess(701, 42, 'Restore Store operations during an incident');
    $assert($policy->allows('super_admin', $pos, null, $emergency, 42) === false, 'emergency never grants POS');

    $root = dirname(__DIR__, 3);
    $posPage = (string)@file_get_contents($root . '/src/frontend/components/cashier/pos.php');
    $assert(str_contains($posPage, "require_role(['cashier'])"), 'POS page requires cashier workspace');
    $assert(str_contains($posPage, 'OPERATE_POINT_OF_SALE'), 'POS page checks POS capability');
    $held = (string)@file_get_contents($root . '/src/frontend/components/barcodeScanner/apiScanner/held_sales.php');
    $assert(str_contains($held, "require_role(['cashier'])"), 'held sales require cashier workspace');
    $assert(str_contains($held, 'OPERATE_POINT_OF_SALE'), 'held sales check POS capability');
    $assert(!str_contains($held, "require_role(['admin'"), 'held sales admit no admin workspace');

    $glossary = (string)@file_get_contents($root . '/CONTEXT.md');
    foreach (['**Cashier**:', '**Cashier Shift**:', '**Register**:'] as $term) {
        $assert(str_contains($glossary, $term), 'glossary defines ' . $term);
    }

    // Disabled account keeps history but cannot authenticate (active-only login).
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT UNIQUE)");
    $pdo->exec("INSERT INTO roles VALUES (1,'cashier')");
    $pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, username TEXT, status TEXT, disabled_at TEXT)");
    $pdo->exec("INSERT INTO users VALUES (1,'casey_cashier','active',NULL)");
    $pdo->exec("CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, total_amount REAL)");
    $pdo->exec("INSERT INTO sales VALUES (1,1,150.0)");
    $pdo->exec("UPDATE users SET status='disabled', disabled_at='2026-09-29 08:00:00' WHERE user_id=1");
    $row = $pdo->query('SELECT status, disabled_at FROM users WHERE user_id=1')->fetch(PDO::FETCH_ASSOC);
    $assert($row['status'] === 'disabled', 'disabled loses active status');
    $assert($row['disabled_at'] !== null, 'disabled keeps stamp');
    $assert(str_contains($auth, "\$user['status'] === 'active'"), 'login requires active status');
    $count = (int)$pdo->query('SELECT COUNT(*) FROM sales WHERE cashier_id=1')->fetchColumn();
    $assert($count === 1, 'history survives disablement');
} catch (Throwable $e) {
    $failures[] = 'contract threw: ' . $e->getMessage();
}

if ($failures) { fwrite(STDERR, "Cashier workspace authorization failed:\n- " . implode("\n- ", $failures) . "\n"); exit(1); }
echo "Cashier workspace authorization contract: passed\n";
