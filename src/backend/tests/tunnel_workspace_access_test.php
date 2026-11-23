<?php
declare(strict_types=1);

// Exercise shipped authentication/gates on an isolated database, never the Store database.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';

use App\Authorization\RoleCapabilityPolicy;

$roles = ['super_admin', 'admin', 'inventory_manager', 'cashier'];
$capabilities = [
    'super_admin' => RoleCapabilityPolicy::PLATFORM_GOVERNANCE,
    'admin' => RoleCapabilityPolicy::STORE_OPERATIONS,
    'inventory_manager' => RoleCapabilityPolicy::MUTATE_INVENTORY,
    'cashier' => RoleCapabilityPolicy::OPERATE_POINT_OF_SALE,
];

if (!isset($argv[1])) {
    foreach (['store.trycloudflare.com', 'store.ngrok-free.app', 'localhost'] as $host) {
        foreach ($roles as $role) {
            foreach (['allowed', 'wrong-role', 'wrong-capability'] as $scenario) {
                $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __FILE__, $role, $scenario, $host]));
                $output = [];
                exec($command . ' 2>&1', $output, $status);
                $result = implode("\n", $output);
                $expected = $scenario === 'allowed' ? 'ACCESS GRANTED' : 'ACCESS DENIED 403';
                if ($status !== 0 || !str_contains($result, $expected)) {
                    fwrite(STDERR, "FAIL: {$host} {$role} {$scenario}\n{$result}\n");
                    exit(1);
                }
                echo "PASS: {$host} {$role} {$scenario}\n";
            }
        }
    }
    echo "Tunnel workspace access: passed.\n";
    exit;
}

[$script, $role, $scenario, $host] = $argv;
$_SERVER['HTTP_HOST'] = $host;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/dashboard.php';
$_SESSION = [];

// auth.php opens the live database on include; load its shipped public entry points instead.
$auth = (string)file_get_contents(__DIR__ . '/../includes/auth.php');
preg_match_all('/^function \w+\(.*?^}/ms', $auth, $functions);
foreach ($functions[0] as $function) {
    eval($function);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, is_primary INTEGER);
    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY, display_name TEXT, username TEXT, email TEXT,
        profile_image TEXT, password_hash TEXT, role_id INTEGER, status TEXT,
        failed_login_attempts INTEGER DEFAULT 0, locked_until TEXT, last_login_at TEXT,
        session_version INTEGER DEFAULT 1, must_change_password INTEGER DEFAULT 0,
        is_recovery_account INTEGER DEFAULT 0, last_workspace_role TEXT);
    CREATE TABLE login_attempts (username TEXT, ip_address TEXT, was_successful INTEGER,
        attempted_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE activity_log (user_id INTEGER, action TEXT)");
foreach ($roles as $index => $name) {
    $pdo->prepare('INSERT INTO roles VALUES (?, ?)')->execute([$index + 1, $name]);
}
$roleId = array_search($role, $roles, true) + 1;
$pdo->prepare("INSERT INTO users (user_id, display_name, username, password_hash, role_id, status)
    VALUES (1, 'Tunnel user', 'tunnel_user', ?, ?, 'active')")
    ->execute([password_hash('TunnelTest123!', PASSWORD_BCRYPT, ['cost' => 4]), $roleId]);
$pdo->prepare('INSERT INTO user_roles VALUES (1, ?, 1)')->execute([$roleId]);

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$check(!login_user($pdo, 'tunnel_user', 'wrong-password'), 'Wrong password must fail');
$check(login_user($pdo, 'tunnel_user', 'TunnelTest123!'), 'Assigned role must sign in');
$check(current_role() === $role, 'Sign-in must select the assigned workspace');
$check(current_workspace_roles() === [$role], 'Switcher must offer only the assigned workspace');
$check(!switch_current_workspace($role === 'cashier' ? 'super_admin' : 'cashier'), 'Unassigned workspace must be refused');
$check(switch_current_workspace($role), 'Assigned workspace must be switchable');

if ($scenario !== 'allowed') {
    register_shutdown_function(static function (): void {
        if (http_response_code() === 403) {
            echo "\nACCESS DENIED 403\n";
        }
    });
}

if ($scenario === 'wrong-role') {
    require_role([$role === 'super_admin' ? 'cashier' : 'super_admin']);
} else {
    require_role([$role]);
    require_capability($scenario === 'wrong-capability'
        ? $capabilities[$role === 'super_admin' ? 'cashier' : 'super_admin']
        : $capabilities[$role]);
}
echo "ACCESS GRANTED\n";
