<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['cashier']);
require_capability(\App\Authorization\RoleCapabilityPolicy::OPERATE_POINT_OF_SALE);

use App\Services\GCashTransactionService;

try {
    $service = new GCashTransactionService($pdo, require __DIR__ . '/../../../backend/config/gcash.php');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        if (isset($_GET['reference'])) {
            if (!is_string($_GET['reference']) || strlen($_GET['reference']) > 128) { throw new DomainException('Invalid reference.'); }
            $result = ['duplicate' => $service->exists($_GET['reference'])];
        } else {
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
            if ($page === false) { throw new DomainException('Invalid history page.'); }
            $result = $service->history((int)$_SESSION['user_id'], $page);
        }
    } elseif ($method === 'POST') {
        if (!csrf_token_is_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            throw new DomainException('Security check failed. Reload the page and try again.', 403);
        }
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        if (strlen($raw) > 4096) { throw new DomainException('Request too large.'); }
        $input = json_decode($raw, true);
        if (!is_array($input)) { throw new DomainException('Invalid request.'); }
        foreach (['action', 'reference', 'amount', 'type', 'status', 'note'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) { throw new DomainException('Invalid field: ' . $key); }
        }
        $cashierId = (int)$_SESSION['user_id'];
        if (($input['action'] ?? '') === 'save') {
            $result = ['id' => $service->save($cashierId, $input['reference'] ?? '', $input['amount'] ?? '', $input['type'] ?? '')];
        } elseif (($input['action'] ?? '') === 'review') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) { throw new DomainException('Invalid transaction.'); }
            $service->review($cashierId, $id, $input['status'] ?? '', ($input['confirmed'] ?? false) === true, $input['note'] ?? '');
            $result = [];
        } else { throw new DomainException('Invalid action.'); }
    } else {
        header('Allow: GET, POST');
        throw new DomainException('Method not allowed.', 405);
    }
    echo json_encode(['success' => true] + $result, JSON_THROW_ON_ERROR);
} catch (DomainException $e) {
    http_response_code(in_array($e->getCode(), [403, 404, 405, 409], true) ? $e->getCode() : 422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => \App\Support\OperatorAlert::message($e, 'GCash records are unavailable. Ask your Administrator to check the database migration or try again later.')]);
}
