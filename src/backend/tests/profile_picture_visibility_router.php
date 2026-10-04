<?php
// Test-only auth/DB seam around the shipped image endpoint. No Store DB or credentials.
if (PHP_SAPI !== 'cli-server' || !getenv('RM_PROFILE_VISIBILITY_DIR')) exit;
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) require __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
use App\Services\ProfileImageStorage;
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE roles (role_id INTEGER, role_name TEXT);
    INSERT INTO roles VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin'), (4, 'super_admin');
    CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, email TEXT, profile_image TEXT,
        password_hash TEXT, status TEXT, disabled_at TEXT, session_version INTEGER, must_change_password INTEGER,
        is_recovery_account INTEGER, role_id INTEGER, branch_id INTEGER);
    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, is_primary INTEGER)");
$filename = str_repeat('a', 64) . '.png';
foreach ([1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 4, 6 => 4, 7 => 1, 8 => 1] as $id => $roleId) {
    $pdo->prepare("INSERT INTO users VALUES (?, 'Test Person', 'test', NULL, ?, 'hash', 'active', NULL, 1, 0, ?, ?, 1)")
        ->execute([$id, isset($_GET['removed']) ? null : $filename, (int)($id === 6), $roleId]);
}
$pdo->exec('INSERT INTO user_roles VALUES (7, 1, 1), (7, 3, 0), (8, 1, 1), (8, 4, 0), (5, 1, 1)');
$actor = (int)($_GET['actor'] ?? 0);
session_start();
$_SESSION = ['user_id' => $actor, 'is_recovery_account' => $actor === 6, 'csrf_token' => 'test-token'];
function is_logged_in(): bool { return (int)$_SESSION['user_id'] > 0; }
function current_role(): string { return [1 => 'cashier', 2 => 'inventory_manager', 3 => 'admin', 4 => 'super_admin', 6 => 'super_admin'][$GLOBALS['actor']] ?? ''; }
function role_capability_policy(): App\Authorization\RoleCapabilityPolicy { return new App\Authorization\RoleCapabilityPolicy(); }
function profile_image_storage(): ProfileImageStorage { return new ProfileImageStorage(getenv('RM_PROFILE_VISIBILITY_DIR')); }
function store_scope_id(PDO $pdo): int { return 1; }
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/manage') {
    $pdo->exec('CREATE TABLE activity_log (user_id INTEGER, action TEXT, category TEXT, module TEXT, record_id INTEGER,
        previous_value TEXT, new_value TEXT, ip_address TEXT)');
    require_once __DIR__ . '/../includes/csrf.php';
    $helpers = file_get_contents(__DIR__ . '/../includes/profile_images.php');
    $helpers = preg_replace('/function profile_image_storage\(\).*?^}\R/ms', '', $helpers);
    eval('?>' . $helpers);
    $source = file_get_contents(__DIR__ . '/../../frontend/components/user_manager/user_manager.php');
    $source = substr($source, 0, strpos($source, '$roles = array_values'));
    $source = preg_replace('/^(require_once |require_capability\().*;\R/m', '', $source);
    eval('?>' . $source);
    header('Content-Type: application/json');
    echo json_encode(['action' => $_POST['action'], 'message' => $message, 'account' => $pdo->query('SELECT * FROM users WHERE user_id = 1')->fetch(PDO::FETCH_ASSOC)]);
    exit;
}
$source = file_get_contents(__DIR__ . '/../../frontend/components/auth/profile_image.php');
$source = preg_replace('/^require_once .*;\R/m', '', $source);
eval('?>' . $source);
