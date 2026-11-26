<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Sign in to continue.']);
    exit;
}
validate_current_session($pdo);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
$workspace = current_role();
if (!in_array($workspace, ['admin', 'cashier', 'inventory_manager'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'This workspace cannot view fiscal closing tasks.']);
    exit;
}
try {
    $userId = (int)$_SESSION['user_id'];
    $items = (new \App\Services\FiscalPeriodClosingReminder($pdo))->synchronize($userId, $workspace);
    $reminders = [];
    foreach ($items as $item) $reminders[$item->explanation] = $item->toArray();
    $shift = $workspace === 'cashier' ? (new \App\Services\CashierShiftService($pdo))->getOpenShift($userId) : null;
    echo json_encode([
        'workspace' => $workspace,
        'day' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d'),
        'locked' => !empty($shift['locked_at']),
        'reminders' => array_values($reminders),
        'unread' => get_notification_count($pdo, $userId),
    ]);
} catch (Throwable $error) {
    error_log('Fiscal closing information unavailable: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Closing tasks are unavailable. Try again shortly.']);
}
