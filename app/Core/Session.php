<?php

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::enforceTimeout();
            return;
        }

        self::configureSavePath();

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        $sessionName = (string)Environment::get('SESSION_NAME', 'retailmind_session');
        if ($sessionName !== '') {
            session_name($sessionName);
        }

        $cookieParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookieParams['path'] ?: '/',
            'domain' => $cookieParams['domain'] ?: '',
            'secure' => self::shouldUseSecureCookie(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::enforceTimeout();
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        $params = session_get_cookie_params();
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?: '',
                'secure' => (bool)$params['secure'],
                'httponly' => (bool)$params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    private static function enforceTimeout(): void
    {
        $lifetime = (int)Environment::get('SESSION_LIFETIME', 7200);
        if ($lifetime <= 0) {
            return;
        }

        $now = time();
        $lastActivity = (int)($_SESSION['_last_activity'] ?? $now);

        if (($now - $lastActivity) > $lifetime) {
            self::destroy();
            session_start();
            $_SESSION['_flash_error'] = 'Your session expired. Please sign in again.';
        }

        $_SESSION['_last_activity'] = $now;
    }

    private static function configureSavePath(): void
    {
        $configuredPath = trim((string)Environment::get('SESSION_SAVE_PATH', ''));
        $rootPath = dirname(__DIR__, 2);
        $savePath = $configuredPath !== ''
            ? $configuredPath
            : (($GLOBALS['app']['storage_path'] ?? ($rootPath . '/storage')) . '/sessions');

        if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', $savePath) && strpos($savePath, '/') !== 0) {
            $savePath = $rootPath . '/' . $savePath;
        }

        if (!is_dir($savePath)) {
            mkdir($savePath, 0775, true);
        }

        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }
    }

    private static function shouldUseSecureCookie(): bool
    {
        $configured = Environment::get('SESSION_SECURE_COOKIE', null);
        if ($configured !== null) {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
}
