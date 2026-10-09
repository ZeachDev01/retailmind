<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false]);
    exit;
}
if (!csrf_token_is_valid()) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}
$mode = $_POST['mode'] ?? null;
if (!is_string($mode) || !in_array($mode, ['light', 'dark', 'system'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false]);
    exit;
}
try {
    App\Store\StoreWriteGate::begin($pdo);
    $statement = $pdo->prepare('UPDATE users SET theme_preference = ? WHERE user_id = ?');
    $statement->execute([$mode, (int)$_SESSION['user_id']]);
    $pdo->commit();
    $_SESSION['theme_preference'] = $mode;
    echo json_encode(['success' => true, 'mode' => $mode]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Theme preference save failed: ' . $exception);
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Your display theme was not saved. Check your connection and try again.']);
}
