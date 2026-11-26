<?php
// Test-only HTTP adapter: real authentication and frontend, disposable SQLite data.
$temp = getenv('RM_CONCURRENT_TEST_DIR');
if (PHP_SAPI !== 'cli-server' || !$temp || !is_dir($temp)) { http_response_code(403); exit; }
$frontend = dirname(__DIR__, 3) . '/frontend';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) { return false; }
require_once __DIR__ . '/../../bootstrap/app.php';
$_ENV['SESSION_SAVE_PATH'] = $temp . '/sessions';
$_ENV['SESSION_SECURE_COOKIE'] = 'false';
$database = getenv('RM_CONCURRENT_TEST_DATABASE');
if ($database) {
    if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', $database)) { throw new RuntimeException('Disposable database required.'); }
    $_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $database;
    $pdo = App\Core\Database::connection();
} else {
    $pdo = new PDO('sqlite:' . $temp . '/auth.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
if (!$database && !$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='users'")->fetchColumn()) {
    $pdo->exec("CREATE TABLE roles(role_id INTEGER PRIMARY KEY,role_name TEXT);
        INSERT INTO roles VALUES(1,'super_admin'),(2,'admin'),(3,'cashier'),(4,'inventory_manager');
        CREATE TABLE users(user_id INTEGER PRIMARY KEY,role_id INTEGER,username TEXT,email TEXT,display_name TEXT,
            profile_image TEXT,password_hash TEXT,status TEXT,failed_login_attempts INTEGER DEFAULT 0,locked_until TEXT,
            last_login_at TEXT,last_workspace_role TEXT,session_version INTEGER DEFAULT 1,must_change_password INTEGER DEFAULT 0,is_recovery_account INTEGER DEFAULT 0);
        CREATE TABLE user_roles(user_id INTEGER,role_id INTEGER,is_primary INTEGER);
        INSERT INTO user_roles VALUES(1,1,1),(2,2,1),(3,3,1),(4,4,1),(4,3,0);
        CREATE TABLE activity_log(log_id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,category TEXT,module TEXT,
            record_id INTEGER,previous_value TEXT,new_value TEXT,metadata TEXT,ip_address TEXT);");
    $insert = $pdo->prepare("INSERT INTO users(user_id,role_id,username,email,display_name,password_hash,status) VALUES(?,?,?,?,?,?,'active')");
    foreach ([1 => 'super_admin', 2 => 'admin', 3 => 'cashier', 4 => 'inventory_manager'] as $id => $role) {
        $insert->execute([$id,$id,$role,$role . '@example.test',$role,password_hash('Fixture-password-123!', PASSWORD_DEFAULT)]);
    }
}
$migration = require __DIR__ . '/../../database/migrations/202611260001_cashier_login_sessions.php';
$migration['up']($pdo);
(new ReflectionProperty(App\Core\Database::class, 'connection'))->setValue(null, $pdo);
if ($path === '/components/auth/session_activity.php') { require $frontend . $path; exit; }
if ($path === '/components/auth/cashier_login.php' || $path === '/components/auth/logout.php') { require $frontend . $path; exit; }
if ($path === '/fixture-expire') {
    $pdo->exec('UPDATE cashier_login_sessions SET last_activity = ' . (time() - 3600));
    echo 'expired'; exit;
}
if ($path === '/' || $path === '/index.php') { require $frontend . '/index.php'; exit; }
if ($path === '/components/auth/forgot_password.php') {
    require $frontend . $path; exit;
}
require_once __DIR__ . '/../../includes/auth.php';
if (!is_logged_in()) { header('Location: /?login=1'); exit; }
if ($path === '/fixture-request-login') {
    (new App\Services\CashierLoginService($pdo))->request((int)$_SESSION['user_id'], (int)$_SESSION['session_version'], bin2hex(random_bytes(32)));
    header('Location: /workspace'); exit;
}
if ($path === '/protected') { echo 'Authenticated: ' . current_role(); exit; }
?>
<!doctype html><html><head><link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/assets/vendor/sweetalert2/sweetalert2.min.css"></head>
<body><h1>Authenticated: <?= htmlspecialchars(current_role()) ?></h1>
<script src="/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<?php require $frontend . '/components/auth/session_activity_client.php'; ?>
</body></html>
