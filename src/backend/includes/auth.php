<?php
// Session bootstrap, account validation, and role access.

require_once __DIR__ . '/../bootstrap/app.php';

App\Core\Session::start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/profile_images.php';

function app_base_url(): string
{
    $documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2) . '/frontend'), '/');
    if ($documentRoot === '' || $projectRoot === '') {
        return '';
    }
    if (strpos($projectRoot, $documentRoot) === 0) {
        $basePath = substr($projectRoot, strlen($documentRoot));
        return $basePath === '' ? '' : '/' . trim($basePath, '/');
    }
    return '';
}

function app_url(string $path = ''): string
{
    $baseUrl = app_base_url();
    $normalizedPath = ltrim($path, '/');
    if ($normalizedPath === '') {
        return $baseUrl === '' ? '/' : $baseUrl . '/';
    }
    return ($baseUrl === '' ? '' : $baseUrl) . '/' . $normalizedPath;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function password_policy_error(string $password): ?string
{
    $minimum = max(8, (int)env('PASSWORD_MIN_LENGTH', 10));
    if (strlen($password) < $minimum) {
        return "Password must contain at least {$minimum} characters.";
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must include uppercase, lowercase, and numeric characters.';
    }
    return null;
}

function login_security_limits(): array
{
    return [
        'max_attempts' => max(3, (int)env('LOGIN_MAX_ATTEMPTS', 5)),
        'window_minutes' => max(5, (int)env('LOGIN_WINDOW_MINUTES', 15)),
        'lockout_minutes' => max(5, (int)env('LOGIN_LOCKOUT_MINUTES', 15)),
    ];
}

function login_attempt_count(PDO $pdo, string $username, string $ipAddress, int $windowMinutes): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE username = ? AND ip_address = ? AND was_successful = 0
           AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([$username, $ipAddress, $windowMinutes]);
    return (int)$stmt->fetchColumn();
}

function record_login_attempt(PDO $pdo, string $username, string $ipAddress, bool $success): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (username, ip_address, was_successful) VALUES (?, ?, ?)'
    );
    $stmt->execute([$username, $ipAddress, $success ? 1 : 0]);
    if ($success) {
        $cleanup = $pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND ip_address = ? AND was_successful = 0');
        $cleanup->execute([$username, $ipAddress]);
    }
}

function last_login_error(): string
{
    return (string)($_SESSION['_login_error'] ?? 'Invalid username or password.');
}

function login_user(PDO $pdo, string $username, string $password): bool
{
    $username = trim($username);

    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.full_name, u.username, u.email, u.profile_image, u.password_hash, u.status,
            u.failed_login_attempts, u.locked_until, u.session_version, u.must_change_password,
            u.branch_id, b.branch_name, r.role_name
         FROM users u JOIN roles r ON u.role_id = r.role_id
         LEFT JOIN branches b ON b.branch_id = u.branch_id
         WHERE u.username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $valid = $user
        && $user['status'] === 'active'
        && password_verify($password, (string)$user['password_hash']);

    if ($valid) {
        $pdo->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE user_id = ?'
        )->execute([(int)$user['user_id']]);
        App\Core\Session::regenerate();
        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role_name'];
        $_SESSION['branch_id'] = $user['branch_id'] !== null ? (int)$user['branch_id'] : null;
        $_SESSION['branch_name'] = $user['branch_name'];
        $_SESSION['profile_image'] = $user['profile_image'];
        $_SESSION['session_version'] = (int)($user['session_version'] ?? 1);
        $_SESSION['must_change_password'] = (bool)($user['must_change_password'] ?? false);
        $_SESSION['_authenticated_at'] = time();
        unset($_SESSION['_login_error']);
        unset($_SESSION['_login_username']);
        log_activity(
            $pdo,
            (int)$user['user_id'],
            'Login success',
            'Authentication',
            (int)$user['user_id'],
            null,
            ['username' => $user['username'], 'role' => $user['role_name'], 'action' => 'success']
        );
        return true;
    }

    if ($user) {
        if ($user['status'] !== 'active') {
            $_SESSION['_login_error'] = 'This account has been disabled.';
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

function validate_current_session(PDO $pdo): void
{
    if (!is_logged_in()) {
        return;
    }
    static $validated = false;
    if ($validated) {
        return;
    }
    $validated = true;
    $stmt = $pdo->prepare(
        "SELECT u.status, u.session_version, u.full_name, u.profile_image, u.must_change_password,
            u.branch_id, b.branch_name, r.role_name
         FROM users u JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN branches b ON b.branch_id = u.branch_id
         WHERE u.user_id = ?"
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $sessionVersion = (int)($_SESSION['session_version'] ?? 0);
    if (!$user || $user['status'] !== 'active' || (int)$user['session_version'] !== $sessionVersion) {
        App\Core\Session::destroy();
        if (!headers_sent()) {
            header('Location: ' . app_url('?login=1&session=invalid'));
        }
        exit;
    }
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role_name'];
    $_SESSION['branch_id'] = $user['branch_id'] !== null ? (int)$user['branch_id'] : null;
    $_SESSION['branch_name'] = $user['branch_name'];
    $_SESSION['profile_image'] = $user['profile_image'];
    $_SESSION['must_change_password'] = (bool)($user['must_change_password'] ?? false);

    $currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($_SESSION['must_change_password'] && !in_array($currentScript, ['change_password.php', 'logout.php'], true)) {
        if (!headers_sent()) {
            header('Location: ' . app_url('components/auth/change_password.php'));
        }
        exit;
    }
}

function require_role(array $allowed_roles): void
{
    global $pdo;
    if (!is_logged_in()) {
        header('Location: ' . app_url('?login=1'));
        exit;
    }
    validate_current_session($pdo);
    if (!in_array(current_role(), $allowed_roles, true)) {
        http_response_code(403);
        die('Access denied: your role does not have permission to view this page.');
    }
}

function role_capability_policy(): App\Authorization\RoleCapabilityPolicy
{
    static $policy;
    return $policy ??= new App\Authorization\RoleCapabilityPolicy();
}

function has_capability(
    string $capability,
    ?string $targetRole = null,
    ?App\Authorization\AuthorizationContext $context = null
): bool {
    $role = current_role();
    return $role !== null && role_capability_policy()->allows($role, $capability, $targetRole, $context);
}

function require_capability(
    string $capability,
    ?string $targetRole = null,
    ?App\Authorization\AuthorizationContext $context = null
): void {
    require_any_capability([$capability], $targetRole, $context);
}

function require_any_capability(
    array $capabilities,
    ?string $targetRole = null,
    ?App\Authorization\AuthorizationContext $context = null
): void {
    global $pdo;
    if (!is_logged_in()) {
        header('Location: ' . app_url('?login=1'));
        exit;
    }
    validate_current_session($pdo);
    foreach ($capabilities as $capability) {
        if (has_capability((string)$capability, $targetRole, $context)) {
            return;
        }
    }
    http_response_code(403);
    die('Access denied: your account does not have this capability.');
}

function store_scope_id(PDO $pdo): int
{
    static $storeId;
    return $storeId ??= (new App\Store\StoreScope($pdo))->id();
}

function current_branch_id(): ?int
{
    return isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null
        ? (int)$_SESSION['branch_id']
        : null;
}

function is_system_admin(): bool
{
    return in_array(current_role(), ['super_admin', 'admin'], true);
}

function default_profile_image_url(): string
{
    return app_url('assets/img/new-default-profile.svg.png');
}

function has_privilege(string $privilegeKey): bool
{
    $role = current_role();
    return is_logged_in()
        && $role !== null
        && role_capability_policy()->allowsLegacyPrivilege($role, $privilegeKey);
}

function require_privilege(string $privilegeKey): void
{
    global $pdo;
    if (!is_logged_in()) {
        header('Location: ' . app_url('?login=1'));
        exit;
    }
    validate_current_session($pdo);
    if (!has_privilege($privilegeKey)) {
        http_response_code(403);
        die('Access denied: your account does not have this privilege.');
    }
}

function require_inventory_management(): void
{
    require_privilege('manage_inventory');
}

function require_assigned_branch(): int
{
    if (is_system_admin() && current_branch_id() === null) {
        return 0;
    }
    $branchId = current_branch_id();
    if ($branchId === null) {
        http_response_code(403);
        die('Access denied: your account is not assigned to a branch.');
    }
    return $branchId;
}

function selected_inventory_branch_id(PDO $pdo): int
{
    return store_scope_id($pdo);
}

function branch_scope(string $alias = 'p', ?int $selectedBranchId = null): array
{
    global $pdo;
    return (new App\Store\StoreScope($pdo))->productScope($alias);
}

function logout_user(): void
{
    App\Core\Session::destroy();
}

function redirect_by_role(): void
{
    header('Location: ' . app_url(App\Authorization\RoleWorkspaceRouter::pathFor(current_role())));
    exit;
}

validate_current_session($pdo);
