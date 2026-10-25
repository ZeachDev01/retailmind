<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['cashier']);
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
verify_csrf_token($_POST['csrf_token'] ?? '');
try {
    $deployment = (string)env('STORE_PRINT_DEPLOYMENT', '');
    if ($deployment === '' || strlen($deployment) > 100) throw new DomainException('Store Printer is not configured. Ask your Administrator.');
    $service = new App\Receipts\StorePrintService($pdo, $deployment);
    $actor = (int)$_SESSION['user_id'];
    $type = (string)($_POST['receipt_type'] ?? '');
    $receipt = (int)($_POST['receipt_id'] ?? 0);
    $result = ($_POST['action'] ?? '') === 'status'
        ? $service->status($actor, $type, $receipt, (string)($_POST['job_id'] ?? ''))
        : $service->submit($actor, (string)current_role(), store_scope_id($pdo), $type, $receipt, (string)($_POST['reprint_of'] ?? ''));
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code($error instanceof DomainException ? 422 : 503);
    echo json_encode(['error' => App\Support\OperatorAlert::message($error, 'Printing is unavailable. Your receipt is saved. Try again or ask your Administrator.')]);
}
