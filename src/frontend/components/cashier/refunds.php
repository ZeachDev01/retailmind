<?php
// cashier/refunds.php — Append-only Cash Refunds (ticket #92).
//
// A Cashier issues a full or partial refund against one of their own completed
// sales. The refund is a new record: the completed sale is never edited, and
// what is left refundable on it is a sum over the refunds already issued
// against it. This page holds no rules of its own — the workspace check, the
// remaining-balance caps, the reason set, the Restockable/Damaged
// classification, and the Protected Audit Record all live in CashRefundService,
// because this form can be posted directly and a hand-crafted request must not
// be able to skip any of them.
//
// The refund is settled on the original payment method, so a card or e-wallet
// refund is recorded here too; only a cash refund reduces the drawer's expected
// cash, and the shift page shows that figure.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/CashierShiftService.php';
require_once __DIR__ . '/../../../backend/app/Services/CashRefundService.php';
require_once __DIR__ . '/../../../backend/app/Services/RefundReceiptPresentation.php';
require_once __DIR__ . '/../../../backend/app/Services/ReceiptPaperService.php';

use App\Services\CashRefundService;
use App\Services\CashierShiftService;

require_role(['cashier']);
$storeId = store_scope_id($pdo);

$shiftService = new CashierShiftService($pdo);
$refundService = new CashRefundService($pdo, $shiftService);

$actorId = (int)$_SESSION['user_id'];
// The active workspace, never the account's stored role (#86).
$actorRole = (string)current_role();
$openShift = $shiftService->getOpenShift($actorId);
$registerLocked = $shiftService->isRegisterLocked($actorId);

// A Cashier can be the operator of a Register even without a shift open, so the
// shift is refreshed rather than captured once, and a locked Register is
// explained here while the refusal itself lives in the service.
$canRefund = $openShift !== null && !$registerLocked;

$error = '';
$saleId = (int)($_REQUEST['sale_id'] ?? 0);
$exceptionToken = trim((string)($_POST['exception_token'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $saleId = (int)($_POST['sale_id'] ?? 0);

    try {
        if (($_POST['action'] ?? '') === 'exception_lookup') {
            $exceptionToken = (new \App\Services\RefundExceptionService($pdo))->openSale($actorId, $actorRole, $saleId,
                (string)($_POST['approver_username'] ?? ''), (string)($_POST['approver_password'] ?? ''), (string)($_POST['exception_reason'] ?? ''));
        } else {
        // The form posts one row per line: quantity[line_id] and
        // disposition[line_id]. Only the rows with a quantity are returned, so
        // the page can render every line of the sale and let the Cashier leave
        // the ones they are not returning at zero.
        $quantities = (array)($_POST['quantity'] ?? []);
        $dispositions = (array)($_POST['disposition'] ?? []);
        $items = [];
        foreach ($quantities as $lineId => $quantity) {
            if ((int)$quantity <= 0) {
                continue;
            }
            $items[(int)$lineId] = [
                'quantity' => (int)$quantity,
                'disposition' => (string)($dispositions[$lineId] ?? ''),
            ];
        }

        $refundId = $refundService->refund(
            $actorId,
            $actorRole,
            $saleId,
            $items,
            (string)($_POST['reason'] ?? ''),
            (string)($_POST['note'] ?? ''),
            (string)($_POST['payment_reference'] ?? ''),
            ($_POST['external_completed'] ?? '') === '1',
            $exceptionToken === '' ? null : ['token'=>$exceptionToken,
                'username'=>(string)($_POST['approver_username'] ?? ''), 'password'=>(string)($_POST['approver_password'] ?? '')]
        );
        // A refresh or canceled print revisits a GET, never another payout.
        header('Location: ' . app_url('components/cashier/refunds.php?refund_id=' . $refundId), true, 303);
        exit;
        }
    } catch (Throwable $e) {
        // ADR-0002: the operator is told what to do, never a database error.
        $error = \App\Support\OperatorAlert::message(
            $e,
            'The refund could not be recorded. Check the details and try again. Tell your Administrator if this keeps happening.'
        );
    }
}

// Scoped to this Cashier, so a Cashier can only ever open a sale of their own.
try {
    $sale = $saleId > 0 ? $refundService->refundableSaleForCashier($actorId, $saleId, $exceptionToken ?: null) : null;
} catch (DomainException $e) {
    $sale = null;
    $error = \App\Support\OperatorAlert::message($e, 'Ask your Administrator to authorize this sale again.');
    $exceptionToken = '';
}
$refunds = $refundService->recentForCashier($actorId);
$receiptId = (int)($_GET['refund_id'] ?? 0);
$receipt = $receiptId > 0 ? (new \App\Services\RefundReceiptService($pdo))->forCashier($receiptId, $actorId, $storeId) : null;
$paperWidthMm = (new \App\Services\ReceiptPaperService($pdo))->currentWidth($actorId, $actorRole);
if ($receiptId > 0 && $receipt === null) {
    // Missing and unauthorized receipts share one response, without exposing ownership.
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Refunds</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier-pages.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/refunds.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/refunds.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/sale-receipt.css')) ?>">
    <script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt.js')) ?>" defer></script>
</head>

<body class="cashier-refunds-page">
    <div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
            <div class="topbar">
                <div>
                    <h1>Refunds</h1>
                    <p class="page-subtitle">Refunds are added to the record; the original sale is never changed.</p>
                </div><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back</a>
            </div>
            <?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if (!$openShift): ?><div class="message warning">Open a Cashier Shift before issuing a refund. <a href="<?= htmlspecialchars(app_url('components/cashier/shifts.php')) ?>">Go to Cashier Shift</a></div><?php endif; ?>
            <?php if ($registerLocked): ?><div class="message error"><?= htmlspecialchars(CashierShiftService::LOCKED_MESSAGE) ?></div><?php endif; ?>

            <?php if ($receipt !== null): ?>
            <section class="dashboard-section receipt-container" aria-label="Completed Refund Receipt">
                <h2>Refund Receipt</h2>
                <p class="no-print">Review the receipt, then choose Print to select your printer.</p>
                <button class="btn no-print" type="button" onclick="printReceiptSection(this)">Print Refund Receipt</button>
                <?php \App\Services\RefundReceiptPresentation::render($receipt, $paperWidthMm); ?>
            </section>
            <?php elseif ($receiptId > 0): ?>
            <div class="message warning">Refund Receipt not found or unavailable for your account.</div>
            <?php endif; ?>

            <section class="dashboard-section refund-search">
                <h2>Find a sale to refund</h2>
                <p class="refund-help" id="receipt-help">Enter your receipt number. Only sales you rang up yourself can be refunded.</p>
                <form method="GET" class="refund-search-form">
                    <div><label for="sale_id">Receipt number</label><input type="number" min="1" name="sale_id" id="sale_id" value="<?= $saleId ?: '' ?>" placeholder="Enter receipt number" aria-describedby="receipt-help" required></div>
                    <button class="btn" type="submit">Load sale</button>
                </form>
                <details>
                    <summary>Original Cashier absent or disabled?</summary>
                    <p class="refund-help">An Administrator may open this one sale for your refund. The same Administrator must approve the chosen items and amount when you record it. This access expires after 15 minutes and is used once.</p>
                    <form method="POST" class="refund-form" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                        <input type="hidden" name="action" value="exception_lookup">
                        <label for="exception-sale">Receipt number</label><input id="exception-sale" name="sale_id" type="number" min="1" required>
                        <label for="exception-reason">Why is the original Cashier absent or disabled?</label><input id="exception-reason" name="exception_reason" maxlength="255" required>
                        <label for="lookup-approver">Administrator username</label><input id="lookup-approver" name="approver_username" autocomplete="off" required>
                        <label for="lookup-password">Administrator password</label><input id="lookup-password" name="approver_password" type="password" autocomplete="new-password" required>
                        <button class="btn" type="submit" <?= $canRefund ? '' : 'disabled' ?>>Authorize this sale lookup</button>
                    </form>
                </details>
            </section>

            <?php if ($sale): ?>
                <section class="dashboard-section refund-sale">
                    <div class="refund-sale-heading">
                        <div>
                            <h2>Refund sale #<?= (int)$sale['sale_id'] ?></h2>
                            <p class="refund-help"><?= htmlspecialchars(format_display_datetime((string)$sale['sale_date'])) ?> &middot; <?= htmlspecialchars(strtoupper((string)$sale['payment_method'])) ?></p>
                        </div>
                    </div>
                    <dl class="refund-sale-details">
                        <div><dt>Original charge</dt><dd>&#8369;<?= number_format((float)$sale['total_amount'], 2) ?></dd></div>
                        <div><dt>Already refunded</dt><dd>&#8369;<?= number_format((float)$sale['refunded_amount'], 2) ?></dd></div>
                        <div><dt>Still refundable</dt><dd>&#8369;<?= number_format((float)$sale['refundable_amount'], 2) ?></dd></div>
                    </dl>

                    <?php if (!array_filter($sale['items'], static fn(array $item): bool => $item['refundable_quantity'] > 0)): ?>
                        <div class="message warning">This sale has already been fully refunded.</div>
                    <?php elseif (!in_array((string)$sale['payment_method'], CashRefundService::SUPPORTED_PAYMENT_METHODS, true)): ?>
                        <div class="message warning">This payment method cannot be refunded here. Ask your Administrator for help.</div>
                    <?php else: ?>
                        <form method="POST" class="refund-form" data-confirm="Record this refund on the original payment method? Only Restockable items return to available inventory." data-confirm-title="Record refund" data-confirm-button="Record refund">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                            <input type="hidden" name="sale_id" value="<?= (int)$sale['sale_id'] ?>">
                            <?php if ($exceptionToken !== ''): ?>
                            <input type="hidden" name="exception_token" value="<?= htmlspecialchars($exceptionToken) ?>">
                            <p class="refund-help">Administrator exception for this sale only. Review quantities and conditions, then ask the authorizing Administrator to approve this refund below.</p>
                            <?php endif; ?>
                            <h3 id="return-items-heading">Items to return</h3>
                            <p class="refund-help">Refund amounts include the original discount. Only the amount still refundable is paid back.</p>
                            <div class="table-wrap" role="region" aria-labelledby="return-items-heading" tabindex="0">
                                <table class="refund-items-table">
                                    <thead><tr>
                                        <th>Product</th>
                                        <th>Sold</th>
                                        <th>Still refundable</th>
                                        <th>Returning</th>
                                        <th>Condition</th>
                                    </tr></thead><tbody>
                                    <?php foreach ($sale['items'] as $line): ?>
                                        <?php $remaining = (int)$line['refundable_quantity']; ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($line['product_name']) ?></strong><br><small><?= htmlspecialchars($line['sku']) ?> &middot; &#8369;<?= number_format((float)$line['unit_price'], 2) ?> each</small></td>
                                            <td><?= (int)$line['quantity'] ?></td>
                                            <td><?= $remaining ?></td>
                                            <td>
                                                <label class="refund-sr-only" for="return-quantity-<?= (int)$line['sale_item_id'] ?>">Returning quantity for <?= htmlspecialchars($line['product_name']) ?></label>
                                                <input id="return-quantity-<?= (int)$line['sale_item_id'] ?>" type="number" name="quantity[<?= (int)$line['sale_item_id'] ?>]" min="0" max="<?= $remaining ?>" value="0" <?= $remaining <= 0 ? 'disabled' : '' ?>>
                                            </td>
                                            <td>
                                                <label class="refund-sr-only" for="return-condition-<?= (int)$line['sale_item_id'] ?>">Condition for <?= htmlspecialchars($line['product_name']) ?></label>
                                                <select id="return-condition-<?= (int)$line['sale_item_id'] ?>" name="disposition[<?= (int)$line['sale_item_id'] ?>]" <?= $remaining <= 0 ? 'disabled' : '' ?>>
                                                    <option value="">Choose condition</option>
                                                    <?php foreach (CashRefundService::DISPOSITIONS as $value => $label): ?>
                                                        <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?></tbody>
                                </table>
                            </div>

                            <div class="form-row refund-reason-row">
                                <div>
                                    <label for="reason">Reason <span class="required">(required)</span></label>
                                    <select name="reason" id="reason" required>
                                        <option value="">-- Select a reason --</option>
                                        <?php foreach (CashRefundService::REFUND_REASONS as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="note">Note</label>
                                    <input type="text" name="note" id="note" maxlength="255" aria-describedby="refund-note-help">
                                    <small id="refund-note-help">Required when the reason is Other.</small>
                                </div>
                            </div>

                            <p class="refund-help refund-policy">Refund on the original <?= htmlspecialchars(strtoupper((string)$sale['payment_method'])) ?> payment method. Only <strong>Restockable</strong> units return to available inventory. A <strong>Damaged</strong> unit stays off the shelf. Only cash refunds reduce expected drawer cash.</p>
                            <p class="refund-help">Do not report these Damaged returned units as a Stock Issue: they were already removed from available inventory at sale. An exchange needs this refund and a separate paid new sale.</p>
                            <?php if ($sale['payment_method'] !== 'cash'): ?>
                            <fieldset><legend>External refund settlement</legend>
                                <p>Complete and verify the refund outside RetailMind before recording it here. RetailMind does not transfer money.</p>
                                <label for="refund-reference">Payment Reference</label><input id="refund-reference" name="payment_reference" maxlength="100" required>
                                <label><input type="checkbox" name="external_completed" value="1" required> I verified that this refund was completed externally.</label>
                            </fieldset>
                            <?php endif; ?>
                            <?php if ($exceptionToken !== ''): ?>
                            <fieldset><legend>Approve this specific refund</legend>
                                <label for="refund-approver">Administrator username</label><input id="refund-approver" name="approver_username" autocomplete="off" required>
                                <label for="refund-password">Administrator password</label><input id="refund-password" name="approver_password" type="password" autocomplete="new-password" required>
                            </fieldset>
                            <?php endif; ?>

                            <button class="btn" type="submit" <?= $canRefund ? '' : 'disabled' ?>>Record refund</button>
                            <?php if (!$canRefund): ?><p class="section-description">A refund needs your own open Cashier Shift.</p><?php endif; ?>
                        </form>
                    <?php endif; ?>
                </section>
            <?php elseif ($saleId > 0): ?>
                <section class="dashboard-section"><p>Sale #<?= (int)$saleId ?> was not found, or it was not one of your sales.</p></section>
            <?php endif; ?>

            <section class="dashboard-section refund-history" id="refund-history">
                <div class="refund-history-heading"><h2 id="refund-history-heading">My refunds</h2><span><?= count($refunds) ?> refund<?= count($refunds) === 1 ? '' : 's' ?></span></div>
                <?php if (!$refunds): ?>
                    <p class="refund-help refund-empty">You have not issued any refunds yet.</p>
                <?php else: ?>
                    <div class="table-wrap" role="region" aria-labelledby="refund-history-heading" tabindex="0">
                        <table class="refund-history-table">
                            <thead><tr>
                                <th>Refund</th>
                                <th>Sale</th>
                                <th>Shift</th>
                                <th>Time</th>
                                <th>Reason</th>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Receipt</th>
                            </tr></thead><tbody>
                            <?php foreach ($refunds as $refund): ?>
                                <tr>
                                    <td>#<?= (int)$refund['refund_id'] ?></td>
                                    <td>#<?= (int)$refund['sale_id'] ?></td>
                                    <td>#<?= (int)$refund['shift_id'] ?></td>
                                    <td><?= htmlspecialchars(format_display_datetime((string)$refund['created_at'])) ?></td>
                                    <td>
                                        <?= htmlspecialchars(CashRefundService::REFUND_REASONS[(string)$refund['reason']] ?? (string)$refund['reason']) ?>
                                        <?php if (!empty($refund['note'])): ?><br><small><?= htmlspecialchars((string)$refund['note']) ?></small><?php endif; ?>
                                    </td>
                                    <td><span class="refund-method"><?= htmlspecialchars(strtoupper((string)$refund['payment_method'])) ?></span></td>
                                    <td class="refund-money">&#8369;<?= number_format((float)$refund['refund_amount'], 2) ?></td>
                                    <td><a href="<?= htmlspecialchars(app_url('components/cashier/refunds.php?refund_id=' . (int)$refund['refund_id'])) ?>" aria-label="Preview Refund Receipt #<?= (int)$refund['refund_id'] ?>">Preview receipt</a></td>
                                </tr>
                            <?php endforeach; ?></tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script>
        const refundReason = document.getElementById('reason');
        const refundNote = document.getElementById('note');
        if (refundReason && refundNote) {
            const syncNoteRequirement = () => {
                refundNote.required = refundReason.value === 'other';
            };
            refundReason.addEventListener('change', syncNoteRequirement);
            syncNoteRequirement();
        }
    </script>
</body>

</html>
