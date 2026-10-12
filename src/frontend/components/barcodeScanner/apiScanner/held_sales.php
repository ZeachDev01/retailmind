<?php
// apiScanner/held_sales.php — the held-sale surface of the point of sale (#91).
//
// Every rule lives in HeldSaleService. This file decides who may reach it and
// which JSON shape the till already expects; it holds no rule of its own, because
// a rule kept here is a rule the Cashier Shift page and the closing form cannot
// see. In particular it writes no held sale and no Protected Audit Record: the
// discard reason, its note, and the record that they happened are all settled
// where they cannot be skipped.
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../../backend/app/Services/CashierShiftService.php';
require_once __DIR__ . '/../../../../backend/app/Services/HeldSaleService.php';

// Issue #86: held sales live inside the Cashier workspace; holding, resuming,
// or discarding requires the active Cashier workspace even when the account also
// holds administrative roles.
require_role(['cashier']);

use App\Services\CashierShiftService;
use App\Services\HeldSaleService;

$userId = (int)$_SESSION['user_id'];
$shiftService = new CashierShiftService($pdo);
$heldService = new HeldSaleService($pdo, $shiftService);

// Ticket #90: a locked Register is on a break. Holding, resuming, discarding, and
// even listing a held sale are point-of-sale work on that Register, so the lock
// refuses the whole endpoint rather than only the writes — a half-guarded
// endpoint would still hand a locked till the carts it can resume. The page hides
// these controls; this is the seam that cannot be bypassed. The refusal is raised
// in its own try so every method gets the same JSON error shape.
try {
    $shiftService->requireUnlockedRegister($userId);
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => \App\Support\OperatorAlert::message($e, 'The register is locked. Unlock it to keep selling.')]);
    exit;
}

// Ticket #91: held sales are swept by the service, and only where no open
// Cashier Shift is waiting on them. A cart that belongs to an open shift is never
// retired by the passage of time — that is the Cashier's to complete or discard,
// and the shift cannot close until they do.
try {
    $heldService->sweepExpiredOrphans();
} catch (Throwable $e) {
    // A failed sweep is not a reason to refuse the Cashier their own carts. It
    // only means an orphan nobody is waiting on stays listed a little longer,
    // and the next request will try again. OperatorAlert logs the full
    // exception to the server log; nothing technical is echoed back.
    \App\Support\OperatorAlert::message($e, 'The held sale expiry sweep could not run. This is not a problem with your carts.');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    App\Core\Session::closeWrite();
    echo json_encode(['success' => true, 'held_sales' => $heldService->openForCashier($userId)]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
// Issue #86: POS capability is evaluated from the active Cashier workspace.
// require_role above already guarantees the workspace; double-check the
// capability so non-Cashier pages can never drive this endpoint.
require_capability(\App\Authorization\RoleCapabilityPolicy::OPERATE_POINT_OF_SALE);
$input = json_decode((string)file_get_contents('php://input'), true) ?: [];
csrf_verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null));
$action = (string)($input['action'] ?? '');
$actorRole = (string)current_role();
$respond = static function (array $payload) use ($heldService, $userId): void {
    echo json_encode(array_merge($payload, ['held_sales' => $heldService->openForCashier($userId)]));
    exit;
};

try {
    App\Store\StoreWriteGate::begin($pdo);
    $heldService->assertCartContext($userId, (int)($input['cashier_context'] ?? 0), (int)($input['shift_context'] ?? 0));

    if ($action === 'review') {
        $review = $heldService->review($userId, $actorRole, (array)($input['cart'] ?? []));
        $pdo->commit();
        $respond(['success' => true, 'review' => $review]);
    }
    if ($action === 'discard_cart') {
        $heldService->discardCart($userId, $actorRole, (array)($input['cart'] ?? []), (string)($input['discard_reason'] ?? ''), isset($input['discard_note']) ? (string)$input['discard_note'] : null);
        $pdo->commit();
        $respond(['success' => true]);
    }
    if ($action === 'hold') {
        $held = $heldService->hold($userId, $actorRole, (array)($input['cart'] ?? []), (string)($input['customer_label'] ?? ''));
        $pdo->commit();
        $respond(['success' => true, 'id' => $held['held_sale_id'], 'reference_no' => $held['reference_no'], 'review' => $held['review']]);
    }

    if ($action === 'resume') {
        $resumed = $heldService->resume($userId, $actorRole, (int)($input['id'] ?? 0));
        $pdo->commit();
        $respond(['success' => true, 'id' => $resumed['held_sale_id'], 'shift_id' => $resumed['shift_id'], 'cart' => $resumed['cart'], 'review' => $resumed['review']]);
    }

    // Ticket #91: the discard replaced the bare cancel. There is no way to drop a
    // held sale without saying why, and one with no shift behind it cannot
    // be dropped this way at all — that residue is the sweep's, not the Cashier's.
    if ($action === 'discard') {
        $heldService->discard(
            $userId,
            $actorRole,
            (int)($input['id'] ?? 0),
            (string)($input['discard_reason'] ?? ''),
            isset($input['discard_note']) ? (string)$input['discard_note'] : null
        );
        $pdo->commit();
        $respond(['success' => true]);
    }

    throw new RuntimeException('Invalid action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => \App\Support\OperatorAlert::message($e, 'The held sale could not be completed. Check your connection and try again. Tell your Administrator if this keeps happening.')]);
}
