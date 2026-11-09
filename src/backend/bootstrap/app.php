<?php

use App\Core\Environment;
use App\Support\ErrorFallback;
use App\Support\OperatorAlert;

$rootPath = dirname(__DIR__, 2);
$backendPath = dirname(__DIR__);
$frontendPath = $rootPath . '/frontend';

$composerAutoload = dirname($rootPath) . '/vendor/autoload.php';
if (!is_file($composerAutoload)) {
    $composerAutoload = $rootPath . '/vendor/autoload.php';
}
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(static function (string $class) use ($backendPath): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }

    static $resolved = [];
    if (isset($resolved[$class])) {
        if ($resolved[$class] !== false) {
            require_once $resolved[$class];
        }
        return;
    }

    $relativeClass = substr($class, 4);
    $file = $backendPath . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        $resolved[$class] = $file;
        require_once $file;
    } else {
        $resolved[$class] = false;
    }
});

$workspacePath = dirname($rootPath);
$envPath = is_file($workspacePath . '/.env')
    ? $workspacePath . '/.env'
    : (is_file($rootPath . '/.env') ? $rootPath . '/.env' : $backendPath . '/.env');
Environment::load($envPath);

if (!function_exists('env')) {
    function env(string $key, $default = null) {
        return Environment::get($key, $default);
    }
}

require_once dirname(__DIR__) . '/includes/access_denied.php';

$appEnv = (string)env('APP_ENV', 'production');
$debugDefault = $appEnv !== 'production' ? 'true' : 'false';
$debug = filter_var(env('APP_DEBUG', $debugDefault), FILTER_VALIDATE_BOOLEAN);
$timezone = (string)env('APP_TIMEZONE', 'Asia/Manila');

date_default_timezone_set($timezone);

$storagePath = $backendPath . '/storage';
$logPath = $storagePath . '/logs';
if (!is_dir($logPath)) {
    mkdir($logPath, 0775, true);
}

ini_set('log_errors', '1');
ini_set('error_log', $logPath . '/app.log');
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

$GLOBALS['app'] = [
    'root_path' => $rootPath,
    'backend_path' => $backendPath,
    'frontend_path' => $frontendPath,
    'storage_path' => $storagePath,
    'log_path' => $logPath,
    'env' => $appEnv,
    'debug' => $debug,
];

set_exception_handler(static function (Throwable $exception): void {
    error_log((string)$exception);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Unhandled application exception: ' . $exception->getMessage() . PHP_EOL);
        // CLI/test callers must observe a fatal through a nonzero exit code;
        // logging and returning would silently truncate a suite.
        exit(1);
    }

    // A Database Backup capture is pausing Store writes (#69). That is a
    // retryable condition, not a failure, so it answers with a clear
    // "saving is paused" result and never with a technical error page.
    if ($exception instanceof App\Store\StoreWritePausedException) {
        if (!headers_sent()) {
            http_response_code(409);
        }
        if (App\Support\ErrorFallback::expectsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'paused' => true,
                'message' => $exception->getMessage(),
                'errors' => [],
            ]);
            return;
        }
        echo App\Support\ErrorFallback::page(
            $exception,
            App\Support\OperatorAlert::isDebug(),
            $exception->getMessage()
        );
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    $debug = OperatorAlert::isDebug();

    if (ErrorFallback::expectsJson()) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(ErrorFallback::json($exception, $debug));
        return;
    }

    echo ErrorFallback::page($exception, $debug);
});

// The filesystem lease survives MySQL DDL commits and database replacement.
// Only the explicit offline recovery command can enter an incomplete restore.
$scriptFile = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
$requestUri = str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? ''));

if (!function_exists('app_is_secure')) {
    function app_is_secure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        if (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower((string)$_SERVER['HTTP_FRONT_END_HTTPS']) === 'on') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $protos = array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
            if (in_array('https', array_map('strtolower', $protos), true)) {
                return true;
            }
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PORT']) && (int)trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PORT'])[0]) === 443) {
            return true;
        }
        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $visitor = (string)$_SERVER['HTTP_CF_VISITOR'];
            if (str_contains(strtolower($visitor), '"scheme":"https"')) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('app_scheme')) {
    function app_scheme(): string
    {
        return app_is_secure() ? 'https' : 'http';
    }
}

if (!function_exists('app_host')) {
    function app_host(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
            if ($host !== '') {
                return $host;
            }
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            return trim((string)$_SERVER['HTTP_HOST']);
        }
        if (!empty($_SERVER['SERVER_NAME'])) {
            return trim((string)$_SERVER['SERVER_NAME']);
        }
        return 'localhost';
    }
}

if (!function_exists('app_origin')) {
    function app_origin(): string
    {
        return app_scheme() . '://' . app_host();
    }
}

if (!function_exists('tunnel_host_suffixes')) {
    /**
     * Hostnames that identify a developer/supervisor tunnel in front of the
     * local Store PC. Requests arriving through these hosts are treated as
     * local requests (ADR-0030): full workspace parity, no monitoring whitelist.
     */
    function tunnel_host_suffixes(): array
    {
        return [
            '.trycloudflare.com',
            '.ngrok-free.app',
            '.ngrok-free.dev',
            '.ngrok.io',
            '.ngrok.app',
            '.loca.lt',
            '.lhr.life',
            '.localtunnel.me',
        ];
    }
}

if (!function_exists('is_tunnel_host')) {
    function is_tunnel_host(?string $host = null): bool
    {
        $host = strtolower(trim((string)$host));
        if ($host === '') {
            return false;
        }
        if ($host[0] === '[') {
            $end = strpos($host, ']');
            $host = $end === false ? trim($host, '[]') : substr($host, 1, $end - 1);
        } elseif (substr_count($host, ':') === 1) {
            $host = explode(':', $host, 2)[0];
        }
        foreach (tunnel_host_suffixes() as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('is_tunnel_request')) {
    function is_tunnel_request(): bool
    {
        if (is_tunnel_host($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null)) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            return is_tunnel_host((string)explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
        }
        return false;
    }
}

if (!function_exists('is_remote_request')) {
    function is_remote_request(): bool
    {
        // Tunnel ingress is the Store PC's own link, not remote monitoring.
        if (is_tunnel_request()) {
            return false;
        }
        if (!empty($_SESSION['is_remote'])) {
            return true;
        }
        if (!empty($_SERVER['RETAILMIND_REMOTE']) || !empty($_ENV['RETAILMIND_REMOTE'])) {
            return true;
        }
        if (!empty($_SERVER['HTTP_CF_RAY'])
            || !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
            || !empty($_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'])
            || !empty($_SERVER['HTTP_CF_VISITOR'])) {
            return true;
        }

        $isLocalAddress = static function (string $raw): bool {
            $target = strtolower(trim(explode(':', trim($raw))[0]));
            if ($target === '' || $target === 'localhost' || $target === '127.0.0.1' || $target === '::1' || $target === '[::1]') {
                return true;
            }
            if (str_ends_with($target, '.local') || str_ends_with($target, '.localhost')) {
                return true;
            }
            if (filter_var($target, FILTER_VALIDATE_IP)) {
                return filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
            }
            return false;
        };

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            $clientIp = trim($ips[0]);
            if (!$isLocalAddress($clientIp)) {
                return true;
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
            if (!$isLocalAddress($host)) {
                return true;
            }
        }

        if (!empty($_SERVER['HTTP_HOST'])) {
            if (!$isLocalAddress($_SERVER['HTTP_HOST'])) {
                return true;
            }
        }

        return false;
    }
}

$offlineRecovery = PHP_SAPI === 'cli'
    && $scriptFile === realpath($backendPath . '/scripts/restore_database.php');

$remoteRequest = is_remote_request();

if (PHP_SAPI !== 'cli' && $remoteRequest) {
    $projectPath = dirname($rootPath);
    $remoteReadRoutes = [
        $projectPath . '/index.php',
        $frontendPath . '/index.php',
        $frontendPath . '/components/administrator/dashboard.php',
        $frontendPath . '/components/administrator/shift_report.php',
        $frontendPath . '/components/cashier/dashboard.php',
        $frontendPath . '/components/cashier/shifts.php',
        $frontendPath . '/components/inventory_management/inventory_overview.php',
        $frontendPath . '/components/invoice/sales.php',
        $frontendPath . '/components/notification/notifications.php',
        $frontendPath . '/components/report/receiving_history.php',
        $frontendPath . '/components/report/report_generation.php',
        $frontendPath . '/components/report/inventory_adjustments.php',
        $frontendPath . '/components/super_administrator/dashboard.php',
        $frontendPath . '/components/system_administrator/audit_logs.php',
        $frontendPath . '/components/auth/session_activity.php',
        $frontendPath . '/components/auth/workspace.php',
        $frontendPath . '/components/auth/logout.php',
    ];
    $allowedRemoteFiles = array_filter(array_map('realpath', $remoteReadRoutes));
    $remoteSessionRoutes = [
        realpath($projectPath . '/index.php'),
        realpath($frontendPath . '/index.php'),
        realpath($frontendPath . '/components/auth/session_activity.php'),
        realpath($frontendPath . '/components/auth/workspace.php'),
    ];
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $routeAllowed = $scriptFile !== false && in_array($scriptFile, $allowedRemoteFiles, true);
    $methodAllowed = in_array($method, ['GET', 'HEAD'], true)
        || ($method === 'POST' && in_array($scriptFile, $remoteSessionRoutes, true));

    if (!$routeAllowed || !$methodAllowed) {
        $expectsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($expectsJson) {
            http_response_code(403);
            header('Cache-Control: no-store');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'This action is not available during remote monitoring.']);
            exit;
        }
        access_denied_page(
            'This page can only be opened from the Store computer or an approved tunnel link.',
            'Remote monitoring · read-only route'
        );
    }
}

$isRecoveryRoute = PHP_SAPI !== 'cli' && (
    str_ends_with($scriptName, '/components/backup/recovery.php')
    || str_contains($requestUri, '/components/backup/recovery.php')
    || str_ends_with($scriptName, '/components/backup/backup_status.php')
    || str_contains($requestUri, '/components/backup/backup_status.php')
    || $requestUri === '/recovery'
    || str_starts_with($requestUri, '/recovery?')
);

if (!$offlineRecovery && !$isRecoveryRoute && !App\Backup\RecoveryStore::admitRequest()) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
        header('Retry-After: 30');
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Database recovery is in progress. Store access is paused. Authorized administrators can check recovery status at /components/backup/recovery.php or finish recovery using the offline restore command.';
    exit(1);
}

return $GLOBALS['app'];
