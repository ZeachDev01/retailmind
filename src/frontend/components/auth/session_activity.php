<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['remaining' => 0]);
    exit;
}
validate_current_session($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    exit;
}
if ($method === 'POST') {
    csrf_verify();
    $_SESSION['_last_activity'] = time();
    if (isset($_SESSION['_cashier_login_token'])) {
        (new App\Services\CashierLoginService($pdo))->owns((int)$_SESSION['user_id'], $_SESSION['_cashier_login_token'], time());
    }
}
$remaining = min(
    max(1, (int)App\Core\Environment::get('SESSION_LIFETIME', 3600)) - (time() - (int)$_SESSION['_last_activity']),
    max(1, (int)App\Core\Environment::get('SESSION_ABSOLUTE_LIFETIME', 43200)) - (time() - (int)$_SESSION['_authenticated_at'])
);
$pendingLogin = isset($_SESSION['_cashier_login_token'])
    ? (new App\Services\CashierLoginService($pdo))->pending((int)$_SESSION['user_id'], $_SESSION['_cashier_login_token'])
    : null;
echo json_encode(['remaining' => max(0, $remaining), 'pendingLogin' => $pendingLogin]);
