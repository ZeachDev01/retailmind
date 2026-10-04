<?php
// Exercise the shipped Profile route with isolated DB and upload boundaries.
require_once __DIR__ . '/../bootstrap/app.php';
restore_exception_handler();

use App\Services\ProfileImageStorage;

if (($argv[1] ?? '') === 'request') {
    $role = $argv[2];
    $scenario = $argv[3];
    $directory = sys_get_temp_dir() . '/rm-profile-route-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $old = str_repeat('a', 64) . '.png';
    $uploadPath = tempnam(sys_get_temp_dir(), 'rm-profile-upload-');
    file_put_contents($uploadPath, $png);
    $storage = new ProfileImageStorage($directory, 'is_file', 'copy',
        static fn(string $path): bool => in_array($scenario, ['delete-error', 'remove-delete-error'], true) && basename($path) === $old ? false : unlink($path)
    );
    function profile_image_storage(): ProfileImageStorage { return $GLOBALS['storage']; }
    function app_url(string $path = ''): string { return '/' . ltrim($path, '/'); }
    function retailmind_theme_head(): void {}
    function format_display_datetime(string $value): string { return $value; }
    function log_activity(...$args): void {}
    function user_role_names(PDO $pdo, int $id, string $role): array { return [$role]; }
    function has_capability(string $capability): bool {
        return (new App\Authorization\RoleCapabilityPolicy())->allows(current_role(), $capability);
    }
    $helpers = file_get_contents(__DIR__ . '/../includes/profile_images.php');
    $helpers = preg_replace('/function profile_image_storage\(\).*?^}\R/ms', '', $helpers);
    eval('?>' . $helpers);
    require_once __DIR__ . '/../includes/csrf.php';
    $auth = file_get_contents(__DIR__ . '/../includes/auth.php');
    foreach (['is_logged_in', 'current_role', 'current_roles', 'current_workspace_roles', 'validate_current_session'] as $name) {
        preg_match('/function ' . $name . '\(.*?^}/ms', $auth, $match);
        eval($match[0]);
    }
    $pdo = new class('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO {
        public function commit(): bool {
            if (in_array($GLOBALS['scenario'], ['commit-error', 'remove-commit-error'], true)) {
                throw new PDOException('Commit failed');
            }
            return parent::commit();
        }
    };
    $pdo->exec('CREATE TABLE roles (role_id INTEGER, role_name TEXT)');
    $pdo->prepare('INSERT INTO roles VALUES (1, ?)')->execute([$role]);
    $pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, email TEXT,
        profile_image TEXT, status TEXT, session_version INTEGER, must_change_password INTEGER,
        is_recovery_account INTEGER, role_id INTEGER, last_login_at TEXT, password_changed_at TEXT, created_at TEXT)');
    $initialImage = in_array($scenario, ['optional', 'upload'], true) ? null : $old;
    if ($initialImage !== null) file_put_contents($directory . '/' . $old, $png);
    if ($scenario === 'missing') unlink($directory . '/' . $old);
    $pdo->prepare("INSERT INTO users VALUES (1, 'Test Person', 'tester', NULL, ?, 'active', 1, ?, ?, 1, NULL, NULL, NULL)")
        ->execute([$initialImage, (int)($scenario === 'mandatory'), (int)($scenario === 'recovery')]);
    $pdo->exec("INSERT INTO users VALUES (2, 'Other Person', 'other', NULL, NULL, 'active', 1, 0, 0, 1, NULL, NULL, NULL)");
    if ($scenario === 'persistence') {
        $pdo->exec("CREATE TRIGGER reject_picture BEFORE UPDATE OF profile_image ON users BEGIN SELECT RAISE(ABORT, 'Persistence failed'); END");
    }
    $_SESSION = ['user_id' => 1, 'role' => $role, 'session_version' => 1, 'csrf_token' => 'valid-token'];
    if ($scenario === 'anonymous') unset($_SESSION['user_id']);
    $_SERVER['SCRIPT_NAME'] = '/components/auth/user_info.php';
    $_SERVER['REQUEST_METHOD'] = in_array($scenario, ['optional', 'missing'], true) ? 'GET' : 'POST';
    $_POST = ['action' => in_array($scenario, ['remove', 'remove-delete-error', 'remove-commit-error'], true) ? 'remove_profile_image' : 'replace_profile_image', 'csrf_token' => 'valid-token', 'crop' => ['x' => 0, 'y' => 0, 'size' => 1]];
    $_GET = [];
    if ($scenario === 'target') $_POST['user_id'] = 2;
    if ($scenario === 'query-target') $_GET['user_id'] = 2;
    if ($scenario === 'csrf') unset($_POST['csrf_token']);
    if ($scenario === 'bad-csrf') $_POST['csrf_token'] = 'wrong';
    $_FILES = ['profile_image' => ['tmp_name' => $uploadPath, 'size' => filesize($uploadPath), 'error' => UPLOAD_ERR_OK]];
    if ($scenario === 'upload-error') $_FILES['profile_image']['error'] = UPLOAD_ERR_PARTIAL;
    if ($scenario === 'invalid-image') file_put_contents($uploadPath, '<?php echo "spoofed"; ?>');
    if ($scenario === 'gif') {
        file_put_contents($uploadPath, base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='));
        $_FILES['profile_image']['size'] = filesize($uploadPath);
    }
    if ($scenario === 'oversized') $_FILES['profile_image']['size'] = 5 * 1024 * 1024 + 1;
    if ($scenario === 'request-too-large') {
        $_POST = []; $_FILES = [];
        $_SERVER['CONTENT_LENGTH'] = 1024 * 1024 * 1024;
    }
    ob_start();
    register_shutdown_function(static function () use ($pdo, $directory, $uploadPath, $old): void {
        $html = ob_get_clean();
        $filename = $pdo->query('SELECT profile_image FROM users WHERE user_id = 1')->fetchColumn();
        $result = [
            'status' => http_response_code() ?: 200, 'html' => $html, 'image' => $filename,
            'session_image' => $_SESSION['profile_image'] ?? null,
            'old_exists' => is_file($directory . '/' . $old),
            'files' => count(glob($directory . '/*')),
            'name' => $pdo->query('SELECT full_name FROM users WHERE user_id = 1')->fetchColumn(),
            'other_image' => $pdo->query('SELECT profile_image FROM users WHERE user_id = 2')->fetchColumn(),
        ];
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
        unlink($uploadPath);
        echo json_encode($result);
    });
    validate_current_session($pdo);
    $source = file_get_contents(__DIR__ . '/../../frontend/components/auth/user_info.php');
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
    // Shell dependencies are outside this route seam; use the same avatar renderer.
    $source = str_replace("include __DIR__ . '/../sidebar.php';", "echo profile_avatar_html(1, 'Test Person', \$_SESSION['profile_image'] ?? null, 'sidebar-avatar');", $source);
    eval('?>' . $source);
    exit;
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$request = static function (string $role, string $scenario): array {
    $process = proc_open([PHP_BINARY, __FILE__, 'request', $role, $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    $result = json_decode($output, true);
    if ($code !== 0 || !is_array($result)) throw new RuntimeException("Request failed: {$output} {$errors}");
    return $result;
};
foreach (['cashier', 'inventory_manager', 'admin', 'super_admin'] as $role) {
    foreach (['optional', 'missing'] as $scenario) {
        $result = $request($role, $scenario);
        $assert(str_contains($result['html'], 'Save picture'), "{$role}: picture action must be available.");
        $assert(str_contains($result['html'], 'accept="image/jpeg,image/png,image/webp"') && str_contains($result['html'], 'Maximum 5 MB. No animation.') && str_contains($result['html'], 'value="5242880"'), "{$role}: picker, help text, and original-file limit must agree.");
        $assert(substr_count($result['html'], 'profile-avatar-fallback">TP') === 3, "{$role}: Profile and menu must share initials fallback.");
        $assert(str_contains($result['html'], 'hidden onerror="this.hidden=true"'), "{$role}: missing picture preview must show initials.");
    }
    foreach (['upload', 'replace'] as $scenario) {
        $result = $request($role, $scenario);
        $assert($result['image'] && $result['session_image'] === $result['image'], "{$role}: {$scenario} must refresh current account.");
        $assert(!$result['old_exists'] && $result['files'] === 1, "{$role}: {$scenario} must retain only current image.");
        $assert($result['name'] === 'Test Person' && $result['other_image'] === null, "{$role}: picture save must be independent and self-scoped.");
    }
    $result = $request($role, 'remove');
    $assert($result['image'] === null && $result['session_image'] === null && $result['files'] === 0, "{$role}: removal must clear picture and file.");
    foreach (['target', 'query-target', 'csrf', 'bad-csrf', 'recovery'] as $scenario) {
        $result = $request($role, $scenario);
        $assert($result['status'] === 403 && $result['old_exists'] && $result['files'] === 1, "{$role}: {$scenario} must reject without mutation.");
    }
    foreach (['persistence', 'upload-error', 'invalid-image', 'gif', 'oversized', 'delete-error', 'remove-delete-error', 'commit-error', 'remove-commit-error'] as $scenario) {
        $result = $request($role, $scenario);
        $assert($result['old_exists'] && $result['files'] === 1 && $result['session_image'] === $result['image'], "{$role}: {$scenario} must preserve prior image.");
        $assert(str_contains($result['html'], 'tag-warning'), "{$role}: {$scenario} must show failure.");
        $assert($result['name'] === 'Test Person' && $result['other_image'] === null, "{$role}: failed validation must preserve account data.");
    }
    $result = $request($role, 'request-too-large');
    $assert($result['status'] === 413 && str_contains($result['html'], 'no larger than 5 MB') && $result['old_exists'] && $result['files'] === 1, "{$role}: discarded oversized POST must show a clear size error and preserve picture.");
    foreach (['mandatory', 'anonymous'] as $scenario) {
        $result = $request($role, $scenario);
        $assert($result['html'] === '' && $result['old_exists'] && $result['files'] === 1, "{$role}: {$scenario} must block Profile access.");
    }
}
if ($failures) {
    fwrite(STDERR, "Profile picture self-service tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Profile picture self-service tests: passed\n";
