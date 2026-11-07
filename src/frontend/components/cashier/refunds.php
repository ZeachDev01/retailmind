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
require_once __DIR__ . '/../../../backend/app/Receipts/ReceiptEngine.php';

require_once __DIR__ . '/../../../backend/app/Services/CashierOperationalHistoryService.php';

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
            $rawQty = trim((string)$quantity);
            if ($rawQty === '' || $rawQty === '0') {
                continue;
            }
            $items[(int)$lineId] = [
                'quantity' => $rawQty,
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
        $receiptUrl = app_url('components/cashier/refunds.php?refund_id=' . $refundId);
        if (($_POST['auto_print_receipt'] ?? '') === '1') {
            $_SESSION['auto_print_refund'] = $refundId;
            $receiptUrl .= '&print_receipt=1';
        }
        // A refresh or canceled print revisits a GET, never another payout.
        header('Location: ' . $receiptUrl, true, 303);
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
$refundHistory = (new \App\Services\CashierOperationalHistoryService($pdo))->resolve($actorId, [
    'type' => 'refunds', 'page' => $_GET['page'] ?? 1, 'length' => $_GET['length'] ?? 10,
]);
$refunds = $refundHistory['rows'];
['page' => $page, 'pageSize' => $pageSize, 'totalRows' => $totalRows,
    'totalPages' => $totalPages, 'hasNext' => $hasNext] = $refundHistory;
$paginationFilters = array_intersect_key($_GET, array_flip(['sale_id', 'refund_id']));
$paginationFilters = array_filter($paginationFilters, static fn($value): bool => is_scalar($value) && (int)$value > 0);
$url = static fn(array $params): string => app_url('components/cashier/refunds.php?' . http_build_query($params + $paginationFilters + ['length' => $pageSize])) . '#refund-history';
$receiptId = (int)($_GET['refund_id'] ?? 0);
$receipt = $receiptId > 0 ? (new \App\Receipts\ReceiptEngine($pdo))->fetchRefundForCashier($receiptId, $actorId, $storeId) : null;
$autoPrintRefund = $receipt !== null && (int)($_SESSION['auto_print_refund'] ?? 0) === $receiptId;
if ($autoPrintRefund) unset($_SESSION['auto_print_refund']);
$autoPrintRefund = $autoPrintRefund && ($_GET['print_receipt'] ?? '') === '1';
$paperWidthMm = (new \App\Receipts\ReceiptEngine($pdo))->resolvePaperWidth($actorId, $actorRole);
if ($receiptId > 0 && $receipt === null) {
    // Missing and unauthorized receipts share one response, without exposing ownership.
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Refunds</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier-pages.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/cashier-pages.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/refunds.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/refunds.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/sale-receipt.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/sale-receipt.css')) ?>">
    <script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt-dialog.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/sale-receipt-dialog.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/sale-receipt.js')) ?>" defer></script>
</head>

<body class="cashier-refunds-page">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content" id="main-content">
            <div class="topbar refund-topbar">
                <div class="refund-topbar-title">
                    <div class="refund-title-row">
                        <h1>Refunds</h1>
                        <span class="refund-badge <?= $canRefund ? 'badge-active' : 'badge-inactive' ?>">
                            <i class="bi <?= $canRefund ? 'bi-check-circle-fill' : 'bi-lock-fill' ?>" aria-hidden="true"></i>
                            <?= $canRefund ? 'Register Ready' : ($registerLocked ? 'Register Locked' : 'No Open Shift') ?>
                        </span>
                    </div>
                </div>
                <div class="refund-topbar-actions">
                    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i>Return to POS</a>
                </div>
            </div>
            <?php if ($error): ?><div class="message error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($registerLocked): ?>
                <div class="workflow-notice" role="note" data-rm-alert-processed="1">
                    <div>
                        <h2>Register locked</h2>
                        <p><?= htmlspecialchars(CashierShiftService::LOCKED_MESSAGE) ?></p>
                    </div>
                    <a class="btn" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>">Unlock at POS</a>
                </div>
            <?php endif; ?>

            <?php if ($receipt !== null): ?>
            <dialog id="receiptModal" class="sale-receipt-dialog" aria-labelledby="saleReceiptTitle"
                data-receipt-param="refund_id" data-initial-receipt="1" data-auto-print="<?= $autoPrintRefund ? '1' : '0' ?>">
                <div class="sale-receipt-dialog-header">
                    <h2 id="saleReceiptTitle" tabindex="-1">Refund Receipt #<?= (int)$receipt['refund']['refund_id'] ?></h2>
                    <button type="button" class="btn btn-secondary" data-close-receipt aria-label="Close Refund Receipt">Close</button>
                </div>
                <div id="receiptContent">
                    <section class="receipt-container" aria-label="Completed Refund Receipt">
                        <div class="checkout-complete-banner no-print" role="status">
                            <div class="checkout-complete-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
                            <div class="checkout-complete-copy"><strong>Refund completed</strong><span>Refund Receipt #<?= (int)$receipt['refund']['refund_id'] ?> was saved successfully.</span></div>
                            <div class="checkout-complete-change"><span>Amount refunded</span><strong><?= htmlspecialchars($receipt['store']['currency_symbol']) . number_format((float)$receipt['refund']['refund_amount'], 2) ?></strong></div>
                        </div>
                        <div class="receipt-actions no-print">
                            <button class="btn btn-secondary" type="button" onclick="printReceiptSection(this)" data-print-receipt><i class="bi bi-printer" aria-hidden="true"></i> Print Refund Receipt</button>
                        </div>
                        <?php \App\Receipts\ReceiptEngine::renderRefund($receipt, $paperWidthMm); ?>
                    </section>
                </div>
            </dialog>
            <?php elseif ($receiptId > 0): ?>
            <div class="message warning" role="status" aria-live="polite">Refund Receipt not found or unavailable for your account.</div>
            <?php endif; ?>

            <section class="dashboard-section refund-search">
                <div class="refund-section-header">
                    <div class="refund-section-icon">
                        <i class="bi bi-receipt" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h2>Find a sale to refund</h2>
                    </div>
                </div>
                <form method="GET" class="refund-search-form">
                    <div class="refund-input-wrap">
                        <label for="sale_id">Receipt number</label>
                        <div class="refund-input-group">
                            <i class="bi bi-search refund-input-icon" aria-hidden="true"></i>
                            <input type="number" min="1" inputmode="numeric" autocomplete="off" name="sale_id" id="sale_id" value="<?= $saleId ?: '' ?>" placeholder="e.g. 1042…" required>
                        </div>
                    </div>
                    <button class="btn refund-submit-btn" type="submit"><i class="bi bi-arrow-right" aria-hidden="true"></i> Load sale</button>
                </form>
                <details class="refund-supervisor-details">
                    <summary><i class="bi bi-shield-lock" aria-hidden="true"></i> Refund another Cashier’s sale <span class="refund-override-pill">Supervisor override</span></summary>
                    <form method="POST" class="refund-form refund-supervisor-form" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                        <input type="hidden" name="action" value="exception_lookup">
                        <div class="refund-supervisor-grid">
                            <div>
                                <label for="exception-sale">Receipt number</label>
                                <input id="exception-sale" name="sale_id" type="number" min="1" inputmode="numeric" autocomplete="off" placeholder="e.g. 1042…" required>
                            </div>
                            <div>
                                <label for="exception-reason">Override reason</label>
                                <input id="exception-reason" name="exception_reason" maxlength="255" autocomplete="off" placeholder="e.g. Shift ended, absent…" required>
                            </div>
                            <div>
                                <label for="lookup-approver">Administrator username or email</label>
                                <input id="lookup-approver" name="approver_username" autocomplete="off" spellcheck="false" placeholder="e.g. admin@example.com…" required>
                            </div>
                            <div>
                                <label for="lookup-password">Administrator password</label>
                                <input id="lookup-password" name="approver_password" type="password" autocomplete="current-password" placeholder="Password…" required>
                            </div>
                        </div>
                        <button class="btn" type="submit" <?= $canRefund ? '' : 'disabled' ?>><i class="bi bi-unlock" aria-hidden="true"></i> Authorize and open sale</button>
                    </form>
                </details>
            </section>

            <?php if ($sale): ?>
                <section class="dashboard-section refund-sale">
                    <div class="refund-sale-heading">
                        <div>
                            <div class="refund-sale-title-row">
                                <h2>Refund sale #<?= (int)$sale['sale_id'] ?></h2>
                                <span class="refund-method-badge method-<?= strtolower((string)$sale['payment_method']) ?>"><?= htmlspecialchars(strtoupper((string)$sale['payment_method'])) ?></span>
                            </div>
                            <span class="refund-timestamp"><i class="bi bi-clock-history" aria-hidden="true"></i> <?= htmlspecialchars(format_display_datetime((string)$sale['sale_date'])) ?></span>
                        </div>
                    </div>
                    <dl class="refund-sale-details">
                        <div class="refund-stat-card"><dt><i class="bi bi-cart-check" aria-hidden="true"></i> Original total</dt><dd>&#8369;<?= number_format((float)$sale['total_amount'], 2) ?></dd></div>
                        <div class="refund-stat-card"><dt><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Refunded</dt><dd>&#8369;<?= number_format((float)$sale['refunded_amount'], 2) ?></dd></div>
                        <div class="refund-stat-card stat-refundable"><dt><i class="bi bi-wallet2" aria-hidden="true"></i> Available to refund</dt><dd>&#8369;<?= number_format((float)$sale['refundable_amount'], 2) ?></dd></div>
                    </dl>

                    <?php if (!array_filter($sale['items'], static fn(array $item): bool => $item['refundable_quantity'] > 0)): ?>
                        <div class="message warning" role="status">This sale has already been fully refunded.</div>
                    <?php elseif (!in_array((string)$sale['payment_method'], CashRefundService::SUPPORTED_PAYMENT_METHODS, true)): ?>
                        <div class="message warning" role="status">This payment method cannot be refunded here. Ask your Administrator for help.</div>
                    <?php else: ?>
                        <form method="POST" class="refund-form" data-confirm="Record this refund on the original payment method? Only Restockable items return to available inventory. Do not report damaged returns as Stock Issues. An exchange needs a separate paid sale." data-confirm-title="Record refund" data-confirm-button="Record refund">
                            <input type="hidden" name="auto_print_receipt" value="0">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                            <input type="hidden" name="sale_id" value="<?= (int)$sale['sale_id'] ?>">
                            <?php if ($exceptionToken !== ''): ?>
                            <input type="hidden" name="exception_token" value="<?= htmlspecialchars($exceptionToken) ?>">
                            <?php endif; ?>
                            <h3 id="return-items-heading">Items to return</h3>
                            <div class="table-wrap" role="region" aria-labelledby="return-items-heading" tabindex="0">
                                <table class="refund-items-table">
                                    <thead><tr>
                                        <th>Product</th>
                                        <th>Form</th>
                                        <th>Sold</th>
                                        <th>Still refundable</th>
                                        <th>Returning</th>
                                        <th>Condition</th>
                                    </tr></thead><tbody>
                                    <?php foreach ($sale['items'] as $line): ?>
                                        <?php
                                        $remaining = (int)$line['refundable_quantity'];
                                        $isPack = ($line['selling_form'] ?? 'piece') === 'pack';
                                        $packSize = isset($line['pack_size']) ? (int)$line['pack_size'] : null;
                                        $formLabel = CashRefundService::formatSellingForm($isPack ? 'pack' : 'piece', $packSize);
                                        $unitLabel = $isPack ? 'per pack' : 'each';
                                        $qtyUnit = $isPack ? ($line['quantity'] === 1 ? 'pack' : 'packs') : ($line['quantity'] === 1 ? 'pc' : 'pcs');
                                        $remUnit = $isPack ? ($remaining === 1 ? 'pack' : 'packs') : ($remaining === 1 ? 'pc' : 'pcs');
                                        ?>
                                        <tr data-selling-form="<?= htmlspecialchars($isPack ? 'pack' : 'piece') ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($line['product_name']) ?></strong><br>
                                                <small><?= htmlspecialchars($line['sku']) ?> &middot; &#8369;<?= number_format((float)$line['unit_price'], 2) ?> <?= htmlspecialchars($unitLabel) ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($formLabel) ?></td>
                                            <td class="refund-qty-cell"><?= (int)$line['quantity'] ?>&nbsp;<?= htmlspecialchars($qtyUnit) ?></td>
                                            <td class="refund-qty-cell"><?= $remaining ?>&nbsp;<?= htmlspecialchars($remUnit) ?></td>
                                            <td>
                                                <label class="refund-sr-only" for="return-quantity-<?= (int)$line['sale_item_id'] ?>">Returning quantity for <?= htmlspecialchars($line['product_name']) ?> (<?= htmlspecialchars($formLabel) ?>)</label>
                                                <input id="return-quantity-<?= (int)$line['sale_item_id'] ?>" type="number" name="quantity[<?= (int)$line['sale_item_id'] ?>]" min="0" max="<?= $remaining ?>" step="1" value="0" inputmode="numeric" autocomplete="off" <?= $remaining <= 0 ? 'disabled' : '' ?>>
                                            </td>
                                            <td>
                                                <label class="refund-sr-only" for="return-condition-<?= (int)$line['sale_item_id'] ?>">Condition for <?= htmlspecialchars($line['product_name']) ?> (<?= htmlspecialchars($formLabel) ?>)</label>
                                                <select id="return-condition-<?= (int)$line['sale_item_id'] ?>" name="disposition[<?= (int)$line['sale_item_id'] ?>]" <?= $remaining <= 0 ? 'disabled' : '' ?>>
                                                    <option value="">Choose condition</option>
                                                    <?php foreach (CashRefundService::DISPOSITIONS as $value => $label): ?>
                                                        <?php $optionLabel = ($isPack && $value === CashRefundService::RESTOCKABLE) ? 'Restockable (whole, sealed, complete pack)' : $label; ?>
                                                        <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($optionLabel) ?></option>
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
                                    <label for="note">Note (required for Other)</label>
                                    <input type="text" name="note" id="note" maxlength="255" placeholder="e.g. Customer changed mind, incorrect item size…">
                                </div>
                            </div>

                            <?php if ($sale['payment_method'] !== 'cash'): ?>
                            <fieldset><legend>External refund settlement</legend>
                                <label for="refund-reference">Payment Reference</label><input id="refund-reference" name="payment_reference" maxlength="100" autocomplete="off" spellcheck="false" placeholder="e.g. REF-2026-0891…" required>
                                <label><input type="checkbox" name="external_completed" value="1" required> I completed and verified this refund outside RetailMind.</label>
                            </fieldset>
                            <?php endif; ?>
                            <?php if ($exceptionToken !== ''): ?>
                            <fieldset><legend>Administrator refund approval</legend>
                                <label for="refund-approver">Administrator username or email</label><input id="refund-approver" name="approver_username" autocomplete="off" spellcheck="false" placeholder="e.g. admin@example.com…" required>
                                <label for="refund-password">Administrator password</label><input id="refund-password" name="approver_password" type="password" autocomplete="current-password" placeholder="Password…" required>
                            </fieldset>
                            <?php endif; ?>

                            <button class="btn btn-primary" type="submit" <?= $canRefund ? '' : 'disabled' ?>><i class="bi bi-check2-circle" aria-hidden="true"></i> Record refund</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php elseif ($saleId > 0): ?>
                <section class="dashboard-section refund-not-found">
                    <div class="refund-not-found-inner">
                        <div class="refund-not-found-icon"><i class="bi bi-search" aria-hidden="true"></i></div>
                        <div>
                            <h3>Sale #<?= (int)$saleId ?> was not found</h3>
                            <p>Sale not found in your completed transactions. Use Supervisor Override if completed by another cashier.</p>
                            <a href="<?= htmlspecialchars(app_url('components/cashier/refunds.php')) ?>" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Clear search</a>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="dashboard-section refund-history" id="refund-history">
                <div class="refund-history-heading">
                    <div>
                        <h2 id="refund-history-heading">My refunds</h2>
                    </div>
                    <?php if ($totalRows > 0): ?><span><?= $totalRows ?>&nbsp;refund<?= $totalRows === 1 ? '' : 's' ?></span><?php endif; ?>
                </div>
                <?php if (!$refunds): ?>
                    <div class="refund-empty">
                        <?php if ($totalRows === 0): ?>
                            <svg class="refund-empty-illustration" width="220" height="170" viewBox="0 0 220 170" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <defs>
                                    <linearGradient id="rm-receipt-bg" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%" stop-color="var(--card-bg, #ffffff)" />
                                        <stop offset="100%" stop-color="var(--theme-surface-soft, #f1f5f9)" />
                                    </linearGradient>
                                    <linearGradient id="rm-badge-grad" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%" stop-color="#3b82f6" />
                                        <stop offset="100%" stop-color="#1d4ed8" />
                                    </linearGradient>
                                    <filter id="rm-empty-shadow" x="-20%" y="-15%" width="140%" height="140%" filterUnits="userSpaceOnUse">
                                        <feDropShadow dx="0" dy="6" stdDeviation="10" flood-color="rgba(15, 23, 42, 0.12)" />
                                    </filter>
                                </defs>
                                <ellipse cx="110" cy="148" rx="76" ry="12" fill="var(--theme-surface-soft, rgba(37, 99, 235, 0.08))" />
                                <circle cx="110" cy="80" r="54" fill="var(--primary, #2563eb)" opacity="0.06" />
                                <g filter="url(#rm-empty-shadow)">
                                    <path d="M68 26 C68 21 72 17 78 17 L142 17 C148 17 152 21 152 26 L152 134 L144 128 L136 134 L128 128 L120 134 L112 128 L104 134 L96 128 L88 134 L80 128 L72 134 L68 130 Z"
                                          fill="url(#rm-receipt-bg)" stroke="var(--border, #cbd5e1)" stroke-width="1.5" stroke-linejoin="round" />
                                </g>
                                <rect x="82" y="28" width="3" height="12" rx="1" fill="var(--muted, #64748b)" opacity="0.75" />
                                <rect x="88" y="28" width="5" height="12" rx="1" fill="var(--muted, #64748b)" opacity="0.75" />
                                <rect x="96" y="28" width="2" height="12" rx="1" fill="var(--muted, #64748b)" opacity="0.75" />
                                <rect x="101" y="28" width="4" height="12" rx="1" fill="var(--muted, #64748b)" opacity="0.75" />
                                <rect x="108" y="28" width="2" height="12" rx="1" fill="var(--muted, #64748b)" opacity="0.75" />
                                <rect x="113" y="28" width="6" height="12" rx="1" fill="var(--muted, #64748b)" opacity="0.75" />
                                <rect x="122" y="28" width="3" height="12" rx="1" fill="var(--muted, #64748b)" opacity="0.75" />
                                <rect x="128" y="28" width="5" height="12" rx="1" fill="var(--muted, #64748b)" opacity="0.75" />
                                <rect x="136" y="28" width="2" height="12" rx="1" fill="var(--muted, #64748b)" opacity="0.75" />
                                <rect x="80" y="50" width="60" height="4" rx="2" fill="var(--muted, #94a3b8)" opacity="0.4" />
                                <rect x="80" y="60" width="44" height="4" rx="2" fill="var(--muted, #94a3b8)" opacity="0.3" />
                                <rect x="80" y="70" width="52" height="4" rx="2" fill="var(--muted, #94a3b8)" opacity="0.3" />
                                <line x1="80" y1="82" x2="140" y2="82" stroke="var(--border, #cbd5e1)" stroke-width="1.5" stroke-dasharray="3 3" />
                                <rect x="80" y="92" width="28" height="5" rx="2" fill="var(--primary, #2563eb)" opacity="0.8" />
                                <rect x="118" y="92" width="22" height="5" rx="2" fill="var(--primary, #2563eb)" opacity="0.9" />
                                <g transform="translate(132, 90)">
                                    <circle cx="16" cy="16" r="22" fill="url(#rm-badge-grad)" filter="url(#rm-empty-shadow)" />
                                    <circle cx="16" cy="16" r="20" stroke="rgba(255,255,255,0.3)" stroke-width="1.5" fill="none" />
                                    <path d="M16 8 C11.6 8 8 11.6 8 16 C8 18.2 8.9 20.2 10.3 21.7 L8 24 L15 24 L15 17 L12.8 19.2 C11.7 18.1 11 16.6 11 15 C11 12.2 13.2 10 16 10 C18.8 10 21 12.2 21 15 C21 16.4 20.4 17.7 19.5 18.5 L21 20 C22.2 18.8 23 17 23 15 C23 11.1 19.9 8 16 8 Z" fill="#ffffff" />
                                </g>
                                <path d="M52 48 L54 42 L60 40 L54 38 L52 32 L50 38 L44 40 L50 42 Z" fill="var(--primary, #2563eb)" opacity="0.75" />
                                <path d="M168 36 L169.5 31 L174.5 29.5 L169.5 28 L168 23 L166.5 28 L161.5 29.5 L166.5 31 Z" fill="#f59e0b" opacity="0.85" />
                                <circle cx="48" cy="78" r="3" fill="#10b981" opacity="0.7" />
                                <circle cx="172" cy="70" r="2.5" fill="var(--primary, #2563eb)" opacity="0.6" />
                            </svg>
                            <h3>No refunds yet</h3>
                            <p class="refund-empty-desc">No refunds recorded during this register session.</p>
                            <a href="#sale_id" class="refund-empty-btn"><i class="bi bi-search" aria-hidden="true"></i> Find a sale to refund</a>
                        <?php else: ?>
                            <div class="refund-empty-past-icon"><i class="bi bi-compass" aria-hidden="true"></i></div>
                            <h3>No refunds on this page</h3>
                            <p class="refund-empty-desc">You have browsed past the last available page of your refund history.</p>
                            <a href="<?= htmlspecialchars($url(['page' => 1])) ?>" class="btn btn-secondary refund-empty-btn"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> View first page</a>
                        <?php endif; ?>
                    </div>
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
                                    <td><span class="refund-id-tag">#<?= (int)$refund['refund_id'] ?></span></td>
                                    <td>#<?= (int)$refund['sale_id'] ?></td>
                                    <td>#<?= (int)$refund['shift_id'] ?></td>
                                    <td><?= htmlspecialchars(format_display_datetime((string)$refund['created_at'])) ?></td>
                                    <td>
                                        <?= htmlspecialchars(CashRefundService::REFUND_REASONS[(string)$refund['reason']] ?? (string)$refund['reason']) ?>
                                        <?php if (!empty($refund['note'])): ?><br><small><?= htmlspecialchars((string)$refund['note']) ?></small><?php endif; ?>
                                    </td>
                                    <td><span class="refund-method"><?= htmlspecialchars(strtoupper((string)$refund['payment_method'])) ?></span></td>
                                    <td class="refund-money">&#8369;<?= number_format((float)$refund['refund_amount'], 2) ?></td>
                                    <td>
                                        <div class="refund-actions-group">
                                            <a href="<?= htmlspecialchars(app_url('components/cashier/refunds.php?refund_id=' . (int)$refund['refund_id'])) ?>" class="btn btn-secondary btn-sm" aria-label="Preview Refund Receipt #<?= (int)$refund['refund_id'] ?>">Receipt</a>
                                            <button type="button" class="btn btn-secondary btn-sm refund-open-drawer" data-refund="<?= htmlspecialchars(json_encode($refund), ENT_QUOTES, 'UTF-8') ?>" aria-label="Details for refund #<?= (int)$refund['refund_id'] ?>">Details</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?></tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php if ($totalRows > 0): ?>
                    <?php $rows = $refunds; require __DIR__ . '/../../../backend/app/Views/table_pagination.php'; ?>
                <?php endif; ?>
                <dialog id="refund-details-drawer" class="refund-details-drawer" aria-labelledby="refund-drawer-title">
                    <div class="history-drawer-header">
                        <div class="history-drawer-header-left">
                            <button type="button" class="history-drawer-back-btn" id="refund-drawer-back-btn" aria-label="Back to details" hidden>
                                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                            </button>
                            <div>
                                <span class="history-drawer-badge" id="refund-drawer-badge">Cash Refund</span>
                                <h2 id="refund-drawer-title">Refund Details</h2>
                            </div>
                        </div>
                        <button type="button" class="history-drawer-close" id="refund-drawer-close" aria-label="Close details">&times;</button>
                    </div>
                    <div class="drawer-view-stage" id="refund-drawer-stage">
                        <!-- View 1: Details -->
                        <div class="drawer-view drawer-view-details is-active" id="refund-drawer-view-details">
                            <div class="history-drawer-body">
                                <dl class="history-drawer-kv" id="refund-drawer-fields"></dl>
                            </div>
                            <div class="history-drawer-footer">
                                <button type="button" class="btn btn-primary" id="refund-drawer-receipt-btn" hidden><i class="bi bi-receipt" aria-hidden="true"></i> View receipt</button>
                                <button type="button" class="btn btn-secondary" id="refund-drawer-dismiss">Close</button>
                            </div>
                        </div>
                        <!-- View 2: Receipt -->
                        <div class="drawer-view drawer-view-receipt" id="refund-drawer-view-receipt" hidden>
                            <div class="history-drawer-body history-drawer-receipt-body" id="refund-drawer-receipt-body">
                                <div class="history-drawer-receipt-loading" id="refund-drawer-receipt-loading" hidden>
                                    <div class="drawer-spinner" aria-hidden="true"></div>
                                    <span>Loading receipt…</span>
                                </div>
                                <div class="history-drawer-receipt-error" id="refund-drawer-receipt-error" role="alert" hidden>
                                    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                                    <p id="refund-drawer-error-msg">The receipt could not be loaded.</p>
                                    <button type="button" class="btn btn-secondary btn-sm" id="refund-drawer-retry-btn">Retry</button>
                                </div>
                                <div class="history-drawer-receipt-content" id="refund-drawer-receipt-content"></div>
                            </div>
                            <div class="history-drawer-footer">
                                <button type="button" class="btn btn-secondary" id="refund-drawer-return-btn"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to details</button>
                                <button type="button" class="btn btn-secondary" id="refund-drawer-receipt-dismiss">Close</button>
                            </div>
                        </div>
                    </div>
                </dialog>
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

        document.querySelectorAll('[data-print-receipt]').forEach(btn => {
            btn.addEventListener('click', () => {
                if (typeof window.printReceiptSection === 'function') {
                    window.printReceiptSection(btn);
                }
            });
        });

        const refundForm = document.querySelector('.refund-sale form.refund-form');
        if (refundForm) {
            let isSubmitting = false;
            refundForm.addEventListener('submit', () => {
                isSubmitting = true;
            });
            window.addEventListener('beforeunload', (e) => {
                if (isSubmitting) return;
                const qtyInputs = refundForm.querySelectorAll('input[name^="quantity["]');
                const hasDirty = Array.from(qtyInputs).some(input => parseInt(input.value || '0', 10) > 0);
                if (hasDirty) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        }

        (() => {
            const drawer = document.getElementById('refund-details-drawer');
            if (!drawer) return;
            const titleEl = document.getElementById('refund-drawer-title');
            const badgeEl = document.getElementById('refund-drawer-badge');
            const fieldsEl = document.getElementById('refund-drawer-fields');
            const closeBtn = document.getElementById('refund-drawer-close');
            const dismissBtn = document.getElementById('refund-drawer-dismiss');
            const receiptDismissBtn = document.getElementById('refund-drawer-receipt-dismiss');
            const receiptBtn = document.getElementById('refund-drawer-receipt-btn');
            const backBtn = document.getElementById('refund-drawer-back-btn');
            const returnBtn = document.getElementById('refund-drawer-return-btn');
            const retryBtn = document.getElementById('refund-drawer-retry-btn');
            const viewDetails = document.getElementById('refund-drawer-view-details');
            const viewReceipt = document.getElementById('refund-drawer-view-receipt');
            const receiptContent = document.getElementById('refund-drawer-receipt-content');
            const receiptLoading = document.getElementById('refund-drawer-receipt-loading');
            const receiptError = document.getElementById('refund-drawer-receipt-error');
            const receiptErrorMsg = document.getElementById('refund-drawer-error-msg');
            const receiptEndpoint = <?= json_encode(app_url('components/invoice/sales.php?action=view&ajax=1')) ?>;

            let currentRefund = null;
            let activeSaleId = null;
            let currentView = 'details';
            let isAnimating = false;
            let pendingFetch = null;

            const switchView = (targetView, animated = true) => {
                if (currentView === targetView) return;
                const outgoing = currentView === 'details' ? viewDetails : viewReceipt;
                const incoming = targetView === 'details' ? viewDetails : viewReceipt;
                const isForward = targetView === 'receipt';
                const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                if (!animated || prefersReducedMotion) {
                    outgoing.classList.remove('is-active', 'anim-slide-out-left', 'anim-slide-out-right');
                    outgoing.hidden = true;
                    incoming.hidden = false;
                    incoming.classList.remove('anim-slide-in-left', 'anim-slide-in-right');
                    incoming.classList.add('is-active');
                    currentView = targetView;
                    return;
                }

                if (isAnimating) return;
                isAnimating = true;

                const outClass = isForward ? 'anim-slide-out-left' : 'anim-slide-out-right';
                const inClass = isForward ? 'anim-slide-in-right' : 'anim-slide-in-left';

                incoming.hidden = false;
                incoming.classList.add(inClass);
                outgoing.classList.add(outClass);

                setTimeout(() => {
                    outgoing.classList.remove('is-active', outClass);
                    outgoing.hidden = true;
                    incoming.classList.remove(inClass);
                    incoming.classList.add('is-active');
                    isAnimating = false;
                    currentView = targetView;
                }, 240);
            };

            const loadReceipt = async (saleId) => {
                if (pendingFetch) {
                    pendingFetch.abort();
                    pendingFetch = null;
                }
                const controller = new AbortController();
                pendingFetch = controller;

                receiptLoading.hidden = false;
                receiptError.hidden = true;
                receiptContent.replaceChildren();

                try {
                    const url = new URL(receiptEndpoint, window.location.href);
                    url.searchParams.set('sale_id', Number(saleId));
                    const res = await fetch(url.toString(), {
                        signal: controller.signal,
                        headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!res.ok) throw new Error('Receipt unavailable: HTTP ' + res.status);
                    const html = await res.text();
                    if (pendingFetch !== controller) return;
                    receiptLoading.hidden = true;
                    receiptContent.innerHTML = html;
                } catch (err) {
                    if (err.name === 'AbortError') return;
                    receiptLoading.hidden = true;
                    receiptError.hidden = false;
                    if (receiptErrorMsg) receiptErrorMsg.textContent = 'Unable to load receipt for sale #' + saleId + '.';
                } finally {
                    if (pendingFetch === controller) pendingFetch = null;
                }
            };

            const openDrawer = refund => {
                if (!refund) return;
                currentRefund = refund;
                switchView('details', false);
                if (backBtn) backBtn.hidden = true;
                if (badgeEl) badgeEl.textContent = 'Cash Refund';
                if (titleEl) titleEl.textContent = `Refund #${refund.refund_id ?? ''}`;
                if (fieldsEl) {
                    fieldsEl.replaceChildren();
                    const map = {
                        'Refund ID': '#' + (refund.refund_id ?? '—'),
                        'Sale ID': '#' + (refund.sale_id ?? '—'),
                        'Shift ID': '#' + (refund.shift_id ?? '—'),
                        'Recorded At': refund.created_at ?? '—',
                        'Payment Method': (refund.payment_method || '—').toUpperCase(),
                        'Refund Amount': '₱' + Number(refund.refund_amount || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}),
                        'Reason': refund.reason ?? '—',
                        'Note': refund.note || 'None'
                    };
                    for (const [label, val] of Object.entries(map)) {
                        const dt = document.createElement('dt');
                        dt.textContent = label;
                        const dd = document.createElement('dd');
                        dd.textContent = val;
                        fieldsEl.append(dt, dd);
                    }
                }

                activeSaleId = refund.sale_id ? Number(refund.sale_id) : null;
                if (receiptBtn) {
                    if (activeSaleId) {
                        receiptBtn.hidden = false;
                        receiptBtn.onclick = () => {
                            if (badgeEl) badgeEl.textContent = 'Sale Receipt';
                            if (titleEl) titleEl.textContent = `Sale Receipt #${activeSaleId}`;
                            if (backBtn) backBtn.hidden = false;
                            switchView('receipt', true);
                            loadReceipt(activeSaleId);
                        };
                    } else {
                        receiptBtn.hidden = true;
                        receiptBtn.onclick = null;
                    }
                }
                drawer.showModal();
            };

            const returnToDetails = () => {
                if (!currentRefund) { drawer.close(); return; }
                if (badgeEl) badgeEl.textContent = 'Cash Refund';
                if (titleEl) titleEl.textContent = `Refund #${currentRefund.refund_id ?? ''}`;
                if (backBtn) backBtn.hidden = true;
                switchView('details', true);
                receiptBtn?.focus();
            };

            backBtn?.addEventListener('click', returnToDetails);
            returnBtn?.addEventListener('click', returnToDetails);
            retryBtn?.addEventListener('click', () => {
                if (activeSaleId) loadReceipt(activeSaleId);
            });

            document.querySelectorAll('.refund-open-drawer').forEach(btn => {
                btn.addEventListener('click', () => {
                    const raw = btn.getAttribute('data-refund');
                    if (raw) {
                        try { openDrawer(JSON.parse(raw)); } catch (_) {}
                    }
                });
            });

            const closeDrawer = () => {
                if (pendingFetch) {
                    pendingFetch.abort();
                    pendingFetch = null;
                }
                drawer.close();
            };
            closeBtn?.addEventListener('click', closeDrawer);
            dismissBtn?.addEventListener('click', closeDrawer);
            receiptDismissBtn?.addEventListener('click', closeDrawer);
            drawer.addEventListener('click', e => {
                if (e.target === drawer) closeDrawer();
            });
        })();
    </script>
</body>

</html>
