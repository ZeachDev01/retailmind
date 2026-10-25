<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_role(['inventory_manager']);
require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$kind = $_GET['kind'] ?? '';
$token = $_GET['token'] ?? '';
$kinds = ['pullout', 'receiving', 'count', 'approveCount', 'rejectCount', 'createProduct', 'adjustStock', 'reconcileStock', 'approveReport', 'rejectReport', 'returnReport'];
if (!is_string($kind) || !in_array($kind, $kinds, true) || !is_string($token) || !preg_match('/^[a-f0-9]{48}$/D', $token)) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}
try {
    $stmt = $pdo->prepare('SELECT 1 FROM inventory_submissions WHERE user_id = ? AND operation_kind = ? AND operation_token = ?');
    $stmt->execute([(int)$_SESSION['user_id'], $kind, $token]);
    echo json_encode(['success' => true, 'completed' => (bool)$stmt->fetchColumn()]);
} catch (Throwable $e) {
    error_log('Inventory submission status unavailable: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false]);
}
