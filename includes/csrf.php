<?php
// includes/csrf.php
// Simple session-based CSRF protection.
// Include this after session_start() has run (auth.php does this).

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (class_exists('App\Core\Session')) {
        App\Core\Session::start();
    } else {
        session_start();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    // Prints a hidden input to drop inside any <form method="POST">.
    function csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_verify')) {
    // Call at the top of every POST handler, before touching the database.
    // Aborts the request with 403 if the token is missing or does not match.
    function csrf_verify(?string $submittedToken = null): void {
        $submitted = $submittedToken ?? ($_POST['csrf_token'] ?? '');
        $expected = $_SESSION['csrf_token'] ?? '';

        if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
            http_response_code(403);
            die('Security check failed. Please go back and try again.');
        }
    }
}

if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token(): string {
        return csrf_token();
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(?string $token = null): void {
        csrf_verify($token);
    }
}
