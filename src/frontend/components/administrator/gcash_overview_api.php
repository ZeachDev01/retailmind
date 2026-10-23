<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin', 'super_admin']);

use App\Services\GCashTransactionService;

try {
    $service = new GCashTransactionService($pdo, require __DIR__ . '/../../../backend/config/gcash.php');
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $filters = [
            'type' => (string)($_GET['type'] ?? 'all'),
            'search' => trim((string)($_GET['search'] ?? '')),
            'actor_role' => (string)current_role(),
            'actor_id' => (int)($_SESSION['user_id'] ?? 0),
            'start_date' => isset($_GET['start_date']) ? (string)$_GET['start_date'] : null,
            'end_date' => isset($_GET['end_date']) ? (string)$_GET['end_date'] : null,
            'cashier_id' => $_GET['cashier_id'] ?? null,
            'page' => filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1,
        ];
        if (isset($_GET['period'])) $filters['period'] = (string)$_GET['period'];
        $result = $service->adminOverview($filters);
        echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
    } elseif ($method === 'POST') {
        if (!csrf_token_is_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            throw new DomainException('Security check failed. Reload the page and try again.', 403);
        }
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        if (strlen($raw) > 4096) {
            throw new DomainException('Request too large.');
        }
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            throw new DomainException('Invalid request.');
        }

        $action = (string)($input['action'] ?? '');
        if ($action === 'resolve_exchange') {
            $txId = filter_var($input['transaction_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$txId || ($input['confirmed_wallet'] ?? false) !== true || ($input['confirmed_cash'] ?? false) !== true) {
                throw new DomainException('Confirm both the wallet transfer and physical service cash exchange.');
            }
            $result = $service->resolveNeedsChecking((int)$_SESSION['user_id'], (int)$txId, 'complete', [
                'reference' => (string)($input['reference'] ?? ''),
                'notes' => 'Administrator verified wallet transfer and physical service cash exchange.',
            ]);
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
        } elseif ($action === 'waive_discrepancy') {
            $sessId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$sessId) {
                throw new DomainException('Invalid session ID.');
            }
            $waiverNotes = trim((string)($input['waiver_notes'] ?? $input['notes'] ?? ''));
            $adminId = (int)$_SESSION['user_id'];
            $adminRole = (string)current_role();
            $service->waiveDiscrepancy($adminId, $adminRole, (int)$sessId, $waiverNotes);
            echo json_encode(['success' => true, 'session_id' => $sessId, 'waived' => true], JSON_UNESCAPED_UNICODE);
        } else {
            throw new DomainException('Unsupported action.', 400);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed.']);
    }
} catch (DomainException $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred.']);
}
