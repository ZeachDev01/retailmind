<?php
// Exercise the shipped create/update handlers without connecting to the Store DB.
require_once __DIR__ . '/../bootstrap/app.php';
restore_exception_handler();

use App\Services\ProfileImageStorage;

if (($argv[1] ?? '') === 'request') {
    $role = $argv[2];
    $action = $argv[3];
    $scenario = $argv[4];
    $directory = sys_get_temp_dir() . '/rm-picture-management-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $old = str_repeat('a', 64) . '.gif';
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
    file_put_contents($directory . '/' . $old, $gif);
    $uploadPath = tempnam(sys_get_temp_dir(), 'rm-management-upload-');
    file_put_contents($uploadPath, $scenario === 'gif' ? $gif : '<?php echo "spoofed"; ?>');
    $storage = new ProfileImageStorage($directory, 'is_file', 'copy');
    function profile_image_storage(): ProfileImageStorage { return $GLOBALS['storage']; }
    function store_scope_id(PDO $pdo): int { return 1; }
    function role_capability_policy(): App\Authorization\RoleCapabilityPolicy { return new App\Authorization\RoleCapabilityPolicy(); }
    function current_role(): string { return $GLOBALS['role']; }
    function password_policy_error(string $password): ?string { return null; }
    $helpers = file_get_contents(__DIR__ . '/../includes/profile_images.php');
    $helpers = preg_replace('/function profile_image_storage\(\).*?^}\R/ms', '', $helpers);
    eval('?>' . $helpers);
    require_once __DIR__ . '/../includes/csrf.php';
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE roles (role_id INTEGER, role_name TEXT);
        INSERT INTO roles VALUES (4, 'cashier');
        CREATE TABLE users (user_id INTEGER, full_name TEXT, username TEXT, email TEXT, profile_image TEXT,
            password_hash TEXT, status TEXT, disabled_at TEXT, session_version INTEGER,
            must_change_password INTEGER, is_recovery_account INTEGER, role_id INTEGER, branch_id INTEGER)");
    $pdo->prepare("INSERT INTO users VALUES (2, 'Saved Person', 'saved', 'saved@example.test', ?, 'hash', 'active', NULL, 1, 0, 0, 4, 1)")->execute([$old]);
    $before = $pdo->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $_SESSION = ['user_id' => 1, 'role' => $role, 'csrf_token' => 'valid-token'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = [];
    $_POST = ['action' => $action, 'csrf_token' => 'valid-token', 'user_id' => 2,
        'first_name' => 'Changed', 'last_name' => 'Person', 'username' => 'changed', 'email' => 'changed@example.test',
        'password' => 'ValidPassword123', 'new_password' => 'ValidPassword123', 'status' => 'disabled',
        'role_ids' => [4], 'remove_profile_image' => 1];
    $_FILES = ['profile_image' => ['tmp_name' => $uploadPath, 'size' => filesize($uploadPath),
        'error' => UPLOAD_ERR_OK, 'name' => 'avatar.png', 'type' => 'image/png']];
    if ($scenario === 'oversized') $_FILES['profile_image']['size'] = 5 * 1024 * 1024 + 1;
    if ($scenario === 'php-limit') $_FILES['profile_image']['error'] = UPLOAD_ERR_INI_SIZE;
    if ($scenario === 'request-too-large') {
        $_POST = []; $_FILES = [];
        $_SERVER['CONTENT_LENGTH'] = 1024 * 1024 * 1024;
    }
    ob_start();
    register_shutdown_function(static function () use ($pdo, $directory, $uploadPath, $old, $before): void {
        $output = ob_get_clean();
        $result = ['status' => http_response_code() ?: 200, 'message' => $GLOBALS['message'] ?? $output,
            'unchanged' => $pdo->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC) === $before,
            'old_exists' => is_file($directory . '/' . $old), 'files' => count(glob($directory . '/*'))];
        if ($output !== '') $result['message'] = $output;
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
        unlink($uploadPath);
        echo json_encode($result);
    });
    $source = file_get_contents(__DIR__ . '/../../frontend/components/user_manager/user_manager.php');
    $source = substr($source, 0, strpos($source, '$roles = array_values'));
    $source = preg_replace('/^(require_once |require_capability\().*;\R/m', '', $source);
    eval('?>' . $source);
    exit;
}

foreach (['admin', 'super_admin'] as $role) {
    foreach (['create', 'update'] as $action) {
        foreach (['gif', 'malformed', 'oversized', 'php-limit', 'request-too-large'] as $scenario) {
            $process = proc_open([PHP_BINARY, __FILE__, 'request', $role, $action, $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $code = proc_close($process);
            $result = json_decode($output, true);
            if ($code !== 0 || !is_array($result) || !$result['unchanged'] || !$result['old_exists'] || $result['files'] !== 1
                || !str_contains($result['message'], in_array($scenario, ['oversized', 'php-limit', 'request-too-large'], true) ? '5 MB' : 'JPG')) {
                throw new RuntimeException("{$role} {$action} {$scenario}: validation must explain failure, preserve account and picture, and leave no staged files: {$output} {$errors}");
            }
        }
    }
}
echo "Profile picture management tests: passed\n";
