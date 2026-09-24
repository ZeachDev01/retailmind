<?php

use App\Core\Environment;
use App\Support\ErrorFallback;
use App\Support\OperatorAlert;

$rootPath = dirname(__DIR__, 2);
$backendPath = dirname(__DIR__);
$frontendPath = $rootPath . '/frontend';

$composerAutoload = $rootPath . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(static function (string $class) use ($backendPath): void {
    $prefix = 'App\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $backendPath . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
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

$appEnv = (string)env('APP_ENV', 'development');
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

return $GLOBALS['app'];
