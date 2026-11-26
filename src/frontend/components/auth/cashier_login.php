<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
csrf_verify();
$service = new App\Services\CashierLoginService($pdo);
$action = $_POST['action'] ?? '';
if ($action === 'approve' || $action === 'reject') {
    if (!is_logged_in() || !isset($_SESSION['_cashier_login_token'])) {
        http_response_code(401);
        exit;
    }
    $success = $service->decide((int)$_SESSION['user_id'], $_SESSION['_cashier_login_token'],
        (string)($_POST['request'] ?? ''), $action === 'approve');
    if (!$success) http_response_code(409);
    if ($success && $action === 'approve') App\Core\Session::destroy();
    echo json_encode(['success' => $success]);
    exit;
}

$pending = $_SESSION['_cashier_login_pending'] ?? null;
if ($action !== 'status' || !is_array($pending) || is_logged_in()) {
    http_response_code(400);
    echo json_encode(['state' => 'expired']);
    exit;
}
$user = resolve_login_user($pdo, (string)$pending['username'])['user'];
$roles = $user ? user_role_names($pdo, (int)$user['user_id'], $user['role_name']) : [];
$state = 'expired';
if ($user && (int)$user['user_id'] === (int)$pending['user_id'] && in_array('cashier', $roles, true)
    && (int)$pending['until'] > time()) {
    $state = $service->claim((int)$pending['user_id'], (int)$pending['version'], $pending['token']);
}
if ($state === 'ready') {
    $_SESSION['_cashier_login_token'] = $pending['token'];
    complete_staff_login($pdo, $user, 'device_approval');
    unset($_SESSION['_cashier_login_pending']);
} elseif ($state === 'expired') {
    unset($_SESSION['_cashier_login_pending']);
}
echo json_encode(['state' => $state, 'redirect' => $state === 'ready'
    ? app_url(App\Authorization\RoleWorkspaceRouter::pathFor(current_role())) : null]);
