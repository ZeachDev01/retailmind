<?php
// Session bootstrap, login throttling, account validation, and role access.

require_once __DIR__ . '/../bootstrap/app.php';

App\Core\Session::start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';

function app_base_url(): string {
    $documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    if ($documentRoot === '' || $projectRoot === '') {
        return '';
    }
    if (strpos($projectRoot, $documentRoot) === 0) {
        $basePath = substr($projectRoot, strlen($documentRoot));
        return $basePath === '' ? '' : '/' . trim($basePath, '/');
    }
    return '';
}

function app_url(string $path = ''): string {
    $baseUrl = app_base_url();
    $normalizedPath = ltrim($path, '/');
    if ($normalizedPath === '') {
        return $baseUrl === '' ? '/' : $baseUrl . '/';
    }
    return ($baseUrl === '' ? '' : $baseUrl) . '/' . $normalizedPath;
}

function ensure_auth_security_schema(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        attempt_id BIGINT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        was_successful BOOLEAN NOT NULL DEFAULT FALSE,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login_attempts_identity (username, ip_address, attempted_at),
        INDEX idx_login_attempts_time (attempted_at)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        reset_id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash VARCHAR(255) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_password_reset_tokens_expiry (expires_at),
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )");

    $existing = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $existing[$column['Field']] = true;
    }
    $columns = [
        'failed_login_attempts' => 'INT NOT NULL DEFAULT 0',
        'locked_until' => 'DATETIME NULL',
        'last_login_at' => 'DATETIME NULL',
        'session_version' => 'INT NOT NULL DEFAULT 1',
        'password_changed_at' => 'DATETIME NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (!isset($existing[$name])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$name} {$definition}");
        }
    }
    $ensured = true;
}

ensure_auth_security_schema($pdo);

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function current_role(): ?string {
    return $_SESSION['role'] ?? null;
}

function password_policy_error(string $password): ?string {
    $minimum = max(8, (int)env('PASSWORD_MIN_LENGTH', 10));
    if (strlen($password) < $minimum) {
        return "Password must contain at least {$minimum} characters.";
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must include uppercase, lowercase, and numeric characters.';
    }
    return null;
}

function login_security_limits(): array {
    return [
        'max_attempts' => max(3, (int)env('LOGIN_MAX_ATTEMPTS', 5)),
        'window_minutes' => max(5, (int)env('LOGIN_WINDOW_MINUTES', 15)),
        'lockout_minutes' => max(5, (int)env('LOGIN_LOCKOUT_MINUTES', 15)),
    ];
}

function login_attempt_count(PDO $pdo, string $username, string $ipAddress, int $windowMinutes): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE username = ? AND ip_address = ? AND was_successful = 0
           AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([$username, $ipAddress, $windowMinutes]);
    return (int)$stmt->fetchColumn();
}

function record_login_attempt(PDO $pdo, string $username, string $ipAddress, bool $success): void {
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (username, ip_address, was_successful) VALUES (?, ?, ?)'
    );
    $stmt->execute([$username, $ipAddress, $success ? 1 : 0]);
    if ($success) {
        $cleanup = $pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND ip_address = ? AND was_successful = 0');
        $cleanup->execute([$username, $ipAddress]);
    }
}

function last_login_error(): string {
    return (string)($_SESSION['_login_error'] ?? 'Invalid username or password.');
}

function login_user(PDO $pdo, string $username, string $password): bool {
    $username = trim($username);
    $ipAddress = get_client_ip_address();
    $limits = login_security_limits();

    $attempts = login_attempt_count($pdo, $username, $ipAddress, $limits['window_minutes']);
    if ($attempts >= $limits['max_attempts']) {
        $_SESSION['_login_error'] = "Too many login attempts. Try again after {$limits['lockout_minutes']} minutes.";
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.full_name, u.username, u.email, u.password_hash, u.status,
                u.failed_login_attempts, u.locked_until, u.session_version, r.role_name
         FROM users u JOIN roles r ON u.role_id = r.role_id
         WHERE u.username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['locked_until'] && strtotime((string)$user['locked_until']) > time()) {
        $_SESSION['_login_error'] = 'This account is temporarily locked. Try again later or contact an administrator.';
        record_login_attempt($pdo, $username, $ipAddress, false);
        return false;
    }

    $valid = $user
        && $user['status'] === 'active'
        && password_verify($password, (string)$user['password_hash']);

    if ($valid) {
        $pdo->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE user_id = ?'
        )->execute([(int)$user['user_id']]);
        record_login_attempt($pdo, $username, $ipAddress, true);
        App\Core\Session::regenerate();
        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role_name'];
        $_SESSION['session_version'] = (int)($user['session_version'] ?? 1);
        $_SESSION['_authenticated_at'] = time();
        unset($_SESSION['_login_error']);
        log_activity(
            $pdo,
            (int)$user['user_id'],
            'Login success',
            'Authentication',
            (int)$user['user_id'],
            null,
            ['username' => $user['username'], 'role' => $user['role_name'], 'status' => 'success']
        );
        return true;
    }

    record_login_attempt($pdo, $username, $ipAddress, false);
    if ($user) {
        $newAttempts = (int)$user['failed_login_attempts'] + 1;
        $lockUntil = $newAttempts >= $limits['max_attempts']
            ? date('Y-m-d H:i:s', time() + ($limits['lockout_minutes'] * 60))
            : null;
        $pdo->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE user_id = ?')
            ->execute([$newAttempts, $lockUntil, (int)$user['user_id']]);
        if ($user['status'] !== 'active') {
            $_SESSION['_login_error'] = 'This account has been disabled.';
        } elseif ($lockUntil) {
            $_SESSION['_login_error'] = "This account is locked for {$limits['lockout_minutes']} minutes.";
        } else {
            $_SESSION['_login_error'] = 'Invalid username or password.';
        }
    } else {
        $_SESSION['_login_error'] = 'Invalid username or password.';
    }

    log_activity(
        $pdo,
        $user ? (int)$user['user_id'] : null,
        'Login failure',
        'Authentication',
        $user ? (int)$user['user_id'] : null,
        null,
        [
            'username' => $username,
            'reason' => $user && $user['status'] !== 'active' ? 'inactive_account' : 'invalid_credentials',
        ]
    );
    return false;
}

function validate_current_session(PDO $pdo): void {
    if (!is_logged_in()) {
        return;
    }
    static $validated = false;
    if ($validated) {
        return;
    }
    $validated = true;
    $stmt = $pdo->prepare(
        "SELECT u.status, u.session_version, u.full_name, r.role_name
         FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?"
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $sessionVersion = (int)($_SESSION['session_version'] ?? 0);
    if (!$user || $user['status'] !== 'active' || (int)$user['session_version'] !== $sessionVersion) {
        App\Core\Session::destroy();
        if (!headers_sent()) {
            header('Location: ' . app_url('login.php?session=invalid'));
        }
        exit;
    }
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role_name'];
}

function require_role(array $allowed_roles): void {
    global $pdo;
    if (!is_logged_in()) {
        header('Location: ' . app_url('login.php'));
        exit;
    }
    validate_current_session($pdo);
    if (current_role() === 'super_admin') {
        return;
    }
    if (!in_array(current_role(), $allowed_roles, true)) {
        http_response_code(403);
        die('Access denied: your role does not have permission to view this page.');
    }
}

function logout_user(): void {
    App\Core\Session::destroy();
}

function redirect_by_role(): void {
    switch (current_role()) {
        case 'super_admin':
        case 'admin':
            header('Location: ' . app_url('admin/dashboard.php'));
            break;
        case 'inventory_manager':
            header('Location: ' . app_url('manager/dashboard.php'));
            break;
        case 'cashier':
            header('Location: ' . app_url('cashier/pos.php'));
            break;
        default:
            header('Location: ' . app_url('login.php'));
    }
    exit;
}

validate_current_session($pdo);
