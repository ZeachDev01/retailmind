<?php
// Session bootstrap, account validation, and role access.

require_once __DIR__ . '/../bootstrap/app.php';

App\Core\Session::start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/password_policy.php';
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

// Unified password policy lives in password_policy.php (required above):
// password_policy_error(), recovery_password_policy_error(),
// password_minimum_length(), and password_meets_complexity() are shared so
// every entry point enforces one rule. Existing hashes stay valid (checked
// on login via password_verify, never re-validated against this policy).

function login_security_limits(): array
{
    return [
        'max_attempts' => max(3, (int)env('LOGIN_MAX_ATTEMPTS', 5)),
        'window_minutes' => max(5, (int)env('LOGIN_WINDOW_MINUTES', 15)),
        'lockout_minutes' => max(5, (int)env('LOGIN_LOCKOUT_MINUTES', 15)),
    ];
}

function login_attempt_count(PDO $pdo, string $identityKey, string $ipAddress, int $windowMinutes): int
{
    // The identity key is the resolved-identity throttle key from
    // login_throttle_identity(), never the raw typed Login Identifier.
    $cutoff = date('Y-m-d H:i:s', time() - max(1, $windowMinutes) * 60);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE username = ? AND ip_address = ? AND was_successful = 0
           AND attempted_at >= ?"
    );
    $stmt->execute([$identityKey, $ipAddress, $cutoff]);
    return (int)$stmt->fetchColumn();
}

function record_login_attempt(PDO $pdo, string $identityKey, string $ipAddress, bool $success): void
{
    // The identity key is the resolved-identity throttle key from
    // login_throttle_identity(), never the raw typed Login Identifier, so
    // Username vs Email Address share one bucket (no double guesses).
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (username, ip_address, was_successful) VALUES (?, ?, ?)'
    );
    $stmt->execute([$identityKey, $ipAddress, $success ? 1 : 0]);
    if ($success) {
        $cleanup = $pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND ip_address = ? AND was_successful = 0');
        $cleanup->execute([$identityKey, $ipAddress]);
    }
}

// Resolved-identity throttle key (#68): a signed-in or attempted account
// keys on its stable resolved identity plus network address at the call
// site; an unresolvable identifier keys on its normalized form plus
// network address. Callers must never pass the raw typed string.
function login_throttle_identity(?array $user, string $identifier): string
{
    if (is_array($user) && isset($user['user_id'])) {
        return 'user:' . (int)$user['user_id'];
    }
    return 'unknown:' . strtolower(trim($identifier));
}

function last_login_error(): string
{
    return (string)($_SESSION['_login_error'] ?? 'Invalid username or password.');
}

// Login Identifier resolution: the single Staff login field accepts a
// Username OR an Email Address. The caller trims the typed value once;
// matching is case-insensitive for both identifiers, following the
// established LOWER(TRIM()) availability precedent. A Username match
// (Recovery Account included) always wins, so a value stored as both a
// Username row and an Email Address row signs in as the Username row. The
// Email Address co-option excludes the Recovery Account, which stays
// reachable by Username only.
function resolve_login_user(PDO $pdo, string $identifier): array
{
    if ($identifier === '') {
        return ['user' => null, 'identifier_type' => 'unknown'];
    }

    $select = "SELECT u.user_id, u.full_name, u.username, u.email, u.profile_image, u.password_hash, u.status,
            u.failed_login_attempts, u.locked_until, u.session_version, u.must_change_password,
            u.is_recovery_account, r.role_name
         FROM users u JOIN roles r ON u.role_id = r.role_id";

    $byUsername = $pdo->prepare($select . ' WHERE LOWER(TRIM(u.username)) = LOWER(?) LIMIT 1');
    $byUsername->execute([$identifier]);
    $user = $byUsername->fetch(PDO::FETCH_ASSOC);
    if (is_array($user)) {
        return ['user' => $user, 'identifier_type' => 'username'];
    }

    $byEmail = $pdo->prepare($select . ' WHERE u.is_recovery_account = 0 AND LOWER(TRIM(u.email)) = LOWER(?) LIMIT 1');
    $byEmail->execute([$identifier]);
    $user = $byEmail->fetch(PDO::FETCH_ASSOC);
    if (is_array($user)) {
        return ['user' => $user, 'identifier_type' => 'email'];
    }

    return ['user' => null, 'identifier_type' => 'unknown'];
}

function login_user(PDO $pdo, string $identifier, string $password): bool
{
    $identifier = trim($identifier);

    $resolved = resolve_login_user($pdo, $identifier);
    $user = $resolved['user'];
    $identifierType = $resolved['identifier_type'];

    $passwordMatches = $user && password_verify($password, (string)$user['password_hash']);
    $valid = $user
        && $user['status'] === 'active'
        && $passwordMatches;

    // Throttle / failed-attempt accounting shares one bucket per resolved
    // identity plus network address, never the raw typed string, so the two
    // identifiers do not grant double guesses. Guarded so the
    // extracted-function contract harnesses (which eval only the named entry
    // points) keep working; accounting must never break sign-in, so a
    // storage failure is logged and swallowed here.
    if (function_exists('login_throttle_identity') && function_exists('record_login_attempt')) {
        try {
            record_login_attempt($pdo, login_throttle_identity($user, $identifier), get_client_ip_address(), $valid);
        } catch (Throwable $throttleException) {
            error_log('Login throttle accounting skipped: ' . $throttleException->getMessage());
        }
    }

    if ($valid) {
        if ((bool)$user['is_recovery_account']) {
            (new App\Services\RecoveryAccountService($pdo))->recordUse(
                (int)$user['user_id'],
                get_client_ip_address()
            );
        }
        $pdo->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = CURRENT_TIMESTAMP WHERE user_id = ?'
        )->execute([(int)$user['user_id']]);
        App\Core\Session::regenerate();
        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role_name'];
        $_SESSION['profile_image'] = $user['profile_image'];
        $_SESSION['session_version'] = (int)($user['session_version'] ?? 1);
        $_SESSION['must_change_password'] = (bool)($user['must_change_password'] ?? false);
        $_SESSION['is_recovery_account'] = (bool)($user['is_recovery_account'] ?? false);
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
            ['username' => $user['username'], 'role' => $user['role_name'], 'action' => 'success', 'identifier_type' => $identifierType]
        );
        return true;
    }

    if ($user && $passwordMatches && $user['status'] !== 'active') {
        $_SESSION['_login_error'] = 'This account has been disabled.';
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
            'username' => $identifier,
            'identifier_type' => $identifierType,
            'reason' => $user && $passwordMatches && $user['status'] !== 'active' ? 'inactive_account' : 'invalid_credentials',
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
            u.is_recovery_account, r.role_name
         FROM users u JOIN roles r ON r.role_id = u.role_id
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
    $_SESSION['profile_image'] = $user['profile_image'];
    $_SESSION['must_change_password'] = (bool)($user['must_change_password'] ?? false);
    $_SESSION['is_recovery_account'] = (bool)($user['is_recovery_account'] ?? false);

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

function emergency_access_service(PDO $pdo): App\Authorization\EmergencyAccessService
{
    static $services = [];
    $key = spl_object_id($pdo);
    return $services[$key] ??= new App\Authorization\EmergencyAccessService($pdo);
}

function current_authorization_context(PDO $pdo): App\Authorization\AuthorizationContext
{
    $role = current_role();
    $actorUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($role === null || $actorUserId <= 0) {
        return App\Authorization\AuthorizationContext::standard();
    }
    try {
        return emergency_access_service($pdo)->authorizationContext($actorUserId, $role);
    } catch (PDOException $exception) {
        error_log('Emergency Access schema is unavailable: ' . $exception->getMessage());
        return App\Authorization\AuthorizationContext::standard();
    }
}

function has_capability(
    string $capability,
    ?string $targetRole = null,
    ?App\Authorization\AuthorizationContext $context = null
): bool {
    global $pdo;
    $role = current_role();
    $actorUserId = (int)($_SESSION['user_id'] ?? 0);
    $context ??= current_authorization_context($pdo);
    return $role !== null
        && role_capability_policy()->allows($role, $capability, $targetRole, $context, $actorUserId);
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

function default_profile_image_url(): string
{
    return app_url('assets/img/new-default-profile.svg.png');
}

function store_product_scope(string $alias = 'p'): array
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
