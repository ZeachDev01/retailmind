<?php
namespace RecoveryEmailFixture;

// Test-only mail boundary; all authorization, persistence and UI use production code.
$temp = getenv('RM_RECOVERY_EMAIL_TEST_DIR');
$database = getenv('RM_THEME_TEST_DATABASE');
if (PHP_SAPI !== 'cli-server' || !$temp || !is_dir($temp)
    || !preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', (string)$database)) {
    http_response_code(403); exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) return false;
require_once __DIR__ . '/../../bootstrap/app.php';
$_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $database;
$_ENV['SESSION_SAVE_PATH'] = $temp . '/sessions';
$_ENV['SESSION_SECURE_COOKIE'] = 'false';
$_ENV['MAIL_MAILER'] = 'disabled';
if ($path === '/fixture-mail') {
    header('Content-Type: application/json');
    echo is_file($temp . '/mail.json') ? file_get_contents($temp . '/mail.json') : '{}';
    exit;
}

function send_email_notification(string $email, string $subject, string $message, ?string $html = null): bool
{
    $temp = getenv('RM_RECOVERY_EMAIL_TEST_DIR');
    file_put_contents($temp . '/mail.json', json_encode(compact('email', 'subject', 'message')));
    return !is_file($temp . '/mail-fails');
}

$root = realpath(__DIR__ . '/../../../frontend');
$file = realpath($root . ($path === '/' ? '/index.php' : $path));
if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) { http_response_code(404); exit; }
if (!str_ends_with($file, '.php')) return false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && in_array($path, ['/components/user_manager/user_manager.php', '/components/auth/forgot_password.php'], true)) {
    // Namespace only the mail caller; keep includes anchored to their real location.
    $source = str_replace('__DIR__', var_export(dirname($file), true), file_get_contents($file));
    eval('namespace RecoveryEmailFixture; use \InvalidArgumentException; use \DomainException; use \Throwable; use \PDO; use \PDOException; ?>' . $source);
} else {
    require $file;
}
