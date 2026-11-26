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
if (!in_array(current_role(), ['admin', 'cashier'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'This workspace cannot view shift status.']);
    exit;
}
try {
    $shifts = new \App\Services\CashierShiftService($pdo);
    if (current_role() === 'admin') {
        $openShifts = $shifts->openShifts();
        ob_start();
        require __DIR__ . '/../../../backend/app/Views/open_cashier_shifts.php';
        $html = ob_get_clean();
        echo json_encode(['html' => $html]);
    } else {
        $shift = $shifts->getOpenShift((int)$_SESSION['user_id']);
        $due = \App\Services\ShiftReminder::dueAt(get_store_settings($pdo), $shift);
        echo json_encode([
            'shift_id' => (int)($shift['shift_id'] ?? 0),
            'locked' => !empty($shift['locked_at']),
            'due_at' => $due?->getTimestamp(),
            'now' => time(),
        ]);
    }
} catch (Throwable $error) {
    error_log('Shift status unavailable: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Shift information is unavailable. Try again shortly.']);
}
