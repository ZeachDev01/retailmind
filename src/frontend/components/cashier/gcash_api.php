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
        if (in_array(($_GET['action'] ?? ''), ['overview', 'follow_up'], true)) {
            $result = $service->cashierOverview((int)$_SESSION['user_id']);
        } elseif (($_GET['action'] ?? '') === 'session_status') {
            $session = $service->getCurrentSession();
            if ($session) {
                $expected = $service->calculateSessionExpected((int)$session['session_id']);
                $session['expected_service_cash'] = $expected['expected_service_cash'];
                $session['expected_wallet_balance'] = $expected['expected_wallet_balance'];
                $session['is_controlling_cashier'] = (int)$session['cashier_id'] === (int)$_SESSION['user_id'];
            }
            $lastClosed = null;
            $closedStmt = $pdo->query("SELECT * FROM gcash_service_sessions WHERE status = 'closed' ORDER BY session_id DESC LIMIT 1");
            if ($row = $closedStmt->fetch(PDO::FETCH_ASSOC)) {
                $lastClosed = $row;
            }
            $result = ['session' => $session, 'last_closed_session' => $lastClosed];
        } elseif (($_GET['action'] ?? '') === 'preview' || (isset($_GET['principal']) && !isset($_GET['reference']) && !isset($_GET['page']))) {
            $type = (string)($_GET['type'] ?? '');
            $principal = (string)($_GET['principal'] ?? '');
            $feeMethod = isset($_GET['fee_method']) ? (string)$_GET['fee_method'] : null;
            $preview = $service->calculatePreview($type, $principal, $feeMethod);
            $result = ['preview' => $preview] + $preview;
        } elseif (isset($_GET['reference'])) {
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
        foreach (['action', 'reference', 'type', 'status', 'note', 'notes', 'fee_method', 'reason', 'outcome', 'movement_type', 'fund_type', 'direction', 'evidence_reference', 'evidence', 'approver_username', 'approver_password', 'resolve_action', 'waiver_notes'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key]) && !($key === 'reference' && $input[$key] === null)) { throw new DomainException('Invalid field: ' . $key); }
        }
        if (isset($input['amount']) && !is_string($input['amount']) && !is_int($input['amount']) && !is_float($input['amount'])) {
            throw new DomainException('Invalid field: amount');
        }
        if (isset($input['principal']) && !is_string($input['principal']) && !is_int($input['principal'])) {
            throw new DomainException('Invalid field: principal');
        }
        $cashierId = (int)$_SESSION['user_id'];
        if (($input['action'] ?? '') === 'record_cash_in') {
            $id = $service->recordCashIn($cashierId, $input);
            $result = ['id' => $id, 'transaction_id' => $id, 'receipt_id' => (new \App\Receipts\GCashReceiptService($pdo))->fetch($id, $cashierId, 'cashier') ? $id : null];
        } elseif (($input['action'] ?? '') === 'record_cash_out') {
            $id = $service->recordCashOut($cashierId, $input);
            $result = ['id' => $id, 'transaction_id' => $id, 'receipt_id' => (new \App\Receipts\GCashReceiptService($pdo))->fetch($id, $cashierId, 'cashier') ? $id : null];
        } elseif (($input['action'] ?? '') === 'accept_exchange') {
            $txId = filter_var($input['transaction_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$txId || ($input['accepted'] ?? false) !== true) throw new DomainException('Choose and explicitly accept this unfinished exchange.');
            if (is_remote_request()) throw new DomainException('Follow-up acceptance is unavailable remotely.', 403);
            $result = $service->transferExchangeResponsibility($cashierId, (int)$txId, $cashierId, (string)($input['reason'] ?? ''), true);
        } elseif (($input['action'] ?? '') === 'record_return') {
            $txId = filter_var($input['transaction_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$txId) throw new DomainException('Choose a valid unfinished exchange.');
            foreach (['returned_cash', 'returned_wallet', 'fee_returned'] as $field) {
                if (isset($input[$field]) && !is_scalar($input[$field])) throw new DomainException('Enter valid return amounts.');
            }
            $result = $service->recordFailedExchangeReturn($cashierId, (int)$txId, $input);
        } elseif (($input['action'] ?? '') === 'funding_movement') {
            $id = $service->recordFundingMovement($cashierId, 'cashier', $input);
            $result = ['id' => $id, 'movement_id' => $id];
        } elseif (($input['action'] ?? '') === 'linked_correction') {
            $adminId = $service->authorizeAdmin((string)($input['approver_username'] ?? ''), (string)($input['approver_password'] ?? ''));
            $txId = filter_var($input['transaction_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$txId) { throw new DomainException('Invalid transaction ID.'); }
            $cashDelta = filter_var($input['service_cash_delta'] ?? 0, FILTER_VALIDATE_FLOAT);
            $walletDelta = filter_var($input['wallet_delta'] ?? 0, FILTER_VALIDATE_FLOAT);
            $reason = (string)($input['reason'] ?? '');
            $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $adjId = $service->recordLinkedCorrection($adminId, 'admin', (int)$txId, (float)$cashDelta, (float)$walletDelta, $reason, $sessionId, (string)($input['correction_mode'] ?? 'money_movement'), $cashierId);
            $result = ['id' => $adjId, 'adjustment_id' => $adjId];
        } elseif (($input['action'] ?? '') === 'resolve_needs_checking') {
            $txId = filter_var($input['transaction_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$txId) { throw new DomainException('Invalid transaction ID.'); }
            $resAction = (string)($input['resolve_action'] ?? $input['outcome'] ?? $input['status'] ?? 'complete');
            $result = $service->resolveNeedsChecking($cashierId, (int)$txId, $resAction, $input);
        } elseif (in_array(($input['action'] ?? ''), ['waive_discrepancy', 'mark_reviewed'], true)) {
            $adminId = $service->authorizeAdmin((string)($input['approver_username'] ?? ''), (string)($input['approver_password'] ?? ''));
            $sessId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$sessId) { throw new DomainException('Invalid session ID.'); }
            $waiverNotes = (string)($input['waiver_notes'] ?? $input['notes'] ?? '');
            $service->markReviewed($adminId, 'admin', (int)$sessId, ['explanation' => $waiverNotes] + $input);
            $result = ['session_id' => $sessId, 'waived' => true];
        } elseif (($input['action'] ?? '') === 'preview') {
            $preview = $service->calculatePreview($input['type'] ?? '', $input['principal'] ?? '', $input['fee_method'] ?? null);
            $result = ['preview' => $preview] + $preview;
        } elseif (($input['action'] ?? '') === 'open_session') {
            $openingCash = filter_var($input['opening_cash'] ?? $input['opening_service_cash'] ?? null, FILTER_VALIDATE_FLOAT);
            $openingWallet = filter_var($input['opening_wallet'] ?? $input['opening_wallet_balance'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($openingCash === false || $openingCash < 0 || $openingWallet === false || $openingWallet < 0) {
                throw new DomainException('Enter opening service cash and wallet balance (0 or greater).');
            }
            $sessionId = $service->openSession($cashierId, (float)$openingCash, (float)$openingWallet);
            $result = ['session_id' => $sessionId];
        } elseif (($input['action'] ?? '') === 'close_session') {
            $closingCash = filter_var($input['closing_cash'] ?? $input['closing_service_cash'] ?? null, FILTER_VALIDATE_FLOAT);
            $closingWallet = filter_var($input['closing_wallet'] ?? $input['closing_wallet_balance'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($closingCash === false || $closingCash < 0 || $closingWallet === false || $closingWallet < 0) {
                throw new DomainException('Enter closing service cash and wallet balance (0 or greater).');
            }
            $notes = (string)($input['notes'] ?? $input['note'] ?? '');
            $carryover = filter_var($input['carryover'] ?? $input['unresolved_carryover'] ?? 0, FILTER_VALIDATE_INT);
            if ($carryover === false || $carryover < 0) { $carryover = 0; }
            $result = $service->closeSession($cashierId, (float)$closingCash, (float)$closingWallet, $notes, (int)$carryover);
        } elseif (($input['action'] ?? '') === 'handover_session') {
            $incomingId = filter_var($input['incoming_cashier_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$incomingId) {
                throw new DomainException('Select a valid incoming Cashier.');
            }
            $countedCash = filter_var($input['counted_cash'] ?? null, FILTER_VALIDATE_FLOAT);
            $checkedWallet = filter_var($input['checked_wallet'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($countedCash === false || $countedCash < 0 || $checkedWallet === false || $checkedWallet < 0) {
                throw new DomainException('Enter counted cash and checked wallet balance (0 or greater).');
            }
            $notes = (string)($input['notes'] ?? $input['note'] ?? '');
            $result = $service->handoverSession($cashierId, (int)$incomingId, (float)$countedCash, (float)$checkedWallet, $notes);
        } elseif (($input['action'] ?? '') === 'save') {
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
