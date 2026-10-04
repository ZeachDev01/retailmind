<?php
// Real HTTP/multipart route seam, isolated SQLite/filesystem; never uses the Store DB.
if (PHP_SAPI !== 'cli-server' || !getenv('RM_PROFILE_CROP_TEST_DIR')) exit;
$directory = getenv('RM_PROFILE_CROP_TEST_DIR');
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) require __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
use App\Services\ProfileImageStorage;
session_start();
$role = $_GET['role'] ?? $_SESSION['test_role'] ?? 'cashier';
$roles = ['cashier', 'inventory_manager', 'admin', 'super_admin'];
$id = array_search($role, $roles, true);
if ($id === false) { http_response_code(403); exit; }
$id++;
$failure = $_GET['failure'] ?? '';
$pdo = new class('sqlite:' . $directory . '/accounts.sqlite') extends PDO {
    public function commit(): bool {
        if ($GLOBALS['failure'] === 'commit') throw new PDOException('Injected commit failure');
        return parent::commit();
    }
};
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE IF NOT EXISTS roles (role_id INTEGER, role_name TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT,
    email TEXT, profile_image TEXT, status TEXT, session_version INTEGER, must_change_password INTEGER,
    is_recovery_account INTEGER, role_id INTEGER, last_login_at TEXT, password_changed_at TEXT, created_at TEXT)');
if (!$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
    foreach ($roles as $index => $name) {
        $pdo->prepare('INSERT INTO roles VALUES (?, ?)')->execute([$index + 1, $name]);
        $pdo->prepare("INSERT INTO users VALUES (?, 'Test Person', ?, NULL, NULL, 'active', 1, 0, 0, ?, NULL, NULL, NULL)")
            ->execute([$index + 1, $name, $index + 1]);
    }
}
$pdo->exec('DROP TRIGGER IF EXISTS reject_picture');
if ($failure === 'persistence') $pdo->exec("CREATE TRIGGER reject_picture BEFORE UPDATE OF profile_image ON users BEGIN SELECT RAISE(ABORT, 'Injected persistence failure'); END");
$current = $pdo->query('SELECT profile_image FROM users WHERE user_id = ' . $id)->fetchColumn();
$storage = new ProfileImageStorage($failure === 'storage' ? $directory . '/accounts.sqlite' : $directory . '/images',
    null, null, static fn(string $path): bool => $GLOBALS['failure'] === 'delete' && basename($path) === $GLOBALS['current'] ? false : unlink($path));
function profile_image_storage(): ProfileImageStorage { return $GLOBALS['storage']; }
function app_url(string $path = ''): string { return '/src/frontend/' . ltrim($path, '/'); }
function format_display_datetime(string $value): string { return $value; }
function log_activity(...$args): void {}
function current_role(): string { return $GLOBALS['role']; }
function is_logged_in(): bool { return true; }
function retailmind_theme_head(): void {}
$helpers = file_get_contents(__DIR__ . '/../includes/profile_images.php');
$helpers = preg_replace('/function profile_image_storage\(\).*?^}\R/ms', '', $helpers);
eval('?>' . $helpers);
require __DIR__ . '/../includes/csrf.php';
$_SESSION = ['test_role' => $role, 'user_id' => $id, 'role' => $role, 'profile_image' => $current, 'csrf_token' => 'crop-test-token'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/state') {
    $image = $current ? imagecreatefrompng($directory . '/images/' . $current) : null;
    header('Content-Type: application/json');
    echo json_encode(['filename' => $current, 'files' => count(glob($directory . '/images/*') ?: []),
        'width' => $image ? imagesx($image) : null, 'height' => $image ? imagesy($image) : null,
        'center' => $image ? imagecolorat($image, 256, 256) & 0xffffff : null]);
    exit;
}
if (str_ends_with($path, '/profile_image.php')) {
    $target = (int)($_GET['user_id'] ?? 0);
    if ($target !== $id && !in_array($role, ['admin', 'super_admin'], true)) { http_response_code(403); exit; }
    $filename = $pdo->query('SELECT profile_image FROM users WHERE user_id = ' . $target)->fetchColumn();
    $image = $storage->read($filename ?: null);
    if (!$image) { http_response_code(404); exit; }
    header('Content-Type: ' . $image['mime']); echo $image['contents']; exit;
}
if (str_contains($path, '/assets/')) return false;
$source = file_get_contents(__DIR__ . '/../../frontend/components/auth/user_info.php');
$source = preg_replace('/^require_once .*;\R/m', '', $source);
$source = str_replace("include __DIR__ . '/../sidebar.php';", "echo profile_avatar_html((int)\$_SESSION['user_id'], 'Test Person', \$_SESSION['profile_image'] ?? null, 'sidebar-avatar');", $source);
eval('?>' . $source);
