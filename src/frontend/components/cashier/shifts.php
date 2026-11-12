<?php
// cashier/shifts.php — Cashier Shift opening and drawer reconciliation (#88).
//
// Opening binds the signed-in Cashier, in the Cashier workspace, to one
// available Register and a confirmed opening float. A shift is never shared or
// opened on somebody's behalf: the Cashier who sells owns the drawer. The
// Administrators may reconcile another Cashier's abandoned shift without
// taking ownership or opening a shift on the Cashier's behalf.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/app/Services/CashierShiftService.php';

use App\Services\CashierShiftService;
use App\Services\CashDenominationCounter;

require_role(['admin', 'super_admin', 'cashier']);

$service = new CashierShiftService($pdo);
$actorId = (int)$_SESSION['user_id'];
$actorRole = (string)current_role();
// Issue #86/#88: the active workspace decides who may open a shift. An
// Administrator who also holds the Cashier role must switch workspaces first.
$isCashier = $actorRole === 'cashier';
$cashiers = [];
if (!$isCashier) {
    $cashiers = $pdo->query("SELECT DISTINCT u.user_id,u.display_name FROM users u JOIN cashier_shifts cs ON cs.cashier_id=u.user_id AND cs.status='open' ORDER BY u.display_name")->fetchAll(PDO::FETCH_ASSOC);
}
$targetCashierId = $isCashier ? $actorId : (int)($_GET['cashier_id'] ?? ($cashiers[0]['user_id'] ?? 0));
if (!$isCashier && $targetCashierId > 0 && !in_array($targetCashierId, array_map(static fn(array $c): int => (int)$c['user_id'], $cashiers), true)) {
    $targetCashierId = (int)($cashiers[0]['user_id'] ?? 0);
}
$message = (string)($_SESSION['drawer_movement_message'] ?? '');
unset($_SESSION['drawer_movement_message']);
$error = '';
$countPreview = null;
$closedSummary = null;
// Ticket #90: a locked Register pauses the drawer, not just the sale screen.
// The refusal lives in CashierShiftService so it cannot be bypassed by posting
// straight to this page; the notice here is only there to explain the pause.
$ownRegisterLocked = $isCashier && $service->isRegisterLocked($actorId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'open') {
            // Ticket #88: the Cashier opens their own shift on a Register they
            // choose. The service records the Protected Audit Record.
            $registerId = (int)($_POST['register_id'] ?? 0);
            $shiftId = $service->openShift($actorId, $actorRole, $registerId, (float)($_POST['opening_float'] ?? 0));
            $message = "Shift #{$shiftId} opened on " . ($service->registerName($registerId) ?? 'your register') . '.';
        } elseif ($action === 'movement') {
            $submission = $_SESSION['drawer_movement_submission'] ?? [];
            $currentShift = $service->getOpenShift($actorId);
            if (!is_string($_POST['movement_token'] ?? null)
                || !isset($submission['token']) || !hash_equals($submission['token'], $_POST['movement_token'])
                || !$currentShift || (int)$submission['shift_id'] !== (int)$currentShift['shift_id']) {
                throw new DomainException('This movement form has expired or was already used. Check the movement history before recording again.');
            }
            $sourceDestination = trim((string)($_POST['source_destination'] ?? ''));
            if ($sourceDestination === '') {
                throw new DomainException('Enter where the cash came from or where it is going.');
            }
            $note = trim((string)($_POST['note'] ?? ''));
            if (($_POST['reason'] ?? '') === 'other' && $note === '') {
                throw new DomainException('Explain the Other reason before recording this movement.');
            }
            $details = (($_POST['movement_type'] ?? '') === 'cash_in' ? 'From: ' : 'To: ') . $sourceDestination
                . ($note === '' ? '' : ' | ' . $note);
            $movementId = $service->addDrawerMovement($actorId, $actorRole, (string)($_POST['movement_type'] ?? ''), (float)($_POST['amount'] ?? 0), (string)($_POST['reason'] ?? ''), $details, (string)($_POST['approver_username'] ?? ''), (string)($_POST['approver_password'] ?? ''));
            // Keep the session lock until the successful submission is consumed.
            unset($_SESSION['drawer_movement_submission']);
            $_SESSION['drawer_movement_message'] = "Drawer movement #{$movementId} recorded.";
            header('Location: ' . app_url('components/cashier/shifts.php?tab=current'), true, 303);
            exit;
        } elseif ($action === 'count' || $action === 'close') {
            if (function_exists('is_remote_request') && is_remote_request()) {
                throw new DomainException('Register balancing and shift closure are restricted to local store premises.', 403);
            }
            if (!$isCashier && $actorRole !== 'admin') {
                throw new DomainException('Only an Administrator can close another Cashier\'s Shift.');
            }
            $currentShift = $service->getOpenShift($targetCashierId);
            if (!$currentShift) {
                throw new DomainException('No open Cashier Shift was found.');
            }
            if ((string)($_POST['shift_id'] ?? '') !== (string)$currentShift['shift_id']) {
                throw new DomainException('This form belongs to a different shift. Reload and count the current drawer.');
            }
            $pendingCount = $_SESSION['cashier_shift_count'] ?? null;
            $sameShift = is_array($pendingCount)
                && (int)($pendingCount['shift_id'] ?? 0) === (int)$currentShift['shift_id']
                && (int)($pendingCount['cashier_id'] ?? 0) === $targetCashierId
                && (int)($pendingCount['actor_id'] ?? 0) === $actorId
                && ($pendingCount['actor_role'] ?? '') === $actorRole;
            if ($action === 'count') {
                if (!$sameShift) {
                    $breakdown = CashDenominationCounter::count($_POST['denominations'] ?? null);
                    $rawCount = $_POST['actual_cash'] ?? '';
                    $counted = CashDenominationCounter::pesos($breakdown['total_cents']);
                    if (!is_string($rawCount) || !preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/D', $rawCount)
                        || number_format((float)$rawCount, 2, '.', '') !== $counted) {
                        throw new InvalidArgumentException('Cash counted must match the bill and coin total.');
                    }
                    $pendingCount = [
                        'cashier_id' => $targetCashierId,
                        'actor_id' => $actorId,
                        'actor_role' => $actorRole,
                        'shift_id' => (int)$currentShift['shift_id'],
                        'counted_cash' => (float)$counted,
                        'denominations' => $breakdown['quantities'],
                    ];
                }
                $countedCash = (float)$pendingCount['counted_cash'];
                $countPreview = $service->previewReconciliation($targetCashierId, $countedCash, !$isCashier);
                $_SESSION['cashier_shift_count'] = $pendingCount;
                $message = 'Cash count submitted. Review the totals before closing.';
            } else {
                if (!$sameShift) {
                    throw new DomainException('Submit the cash count before closing this Cashier Shift.');
                }
                $countedCash = (float)$pendingCount['counted_cash'];
                if (!isset($pendingCount['denominations'])) {
                    unset($_SESSION['cashier_shift_count']);
                    throw new DomainException('Count the bills and coins before closing this shift.');
                }
                $closedSummary = $isCashier
                    ? $service->closeShift($actorId, $countedCash, (string)($_POST['closing_notes'] ?? ''), $pendingCount['denominations'], (int)$pendingCount['shift_id'])
                    : $service->closeAbandonedShift($actorId, $actorRole, $targetCashierId, $countedCash,
                        (string)($_POST['closing_notes'] ?? ''), (string)($_POST['intervention_reason'] ?? ''), $pendingCount['denominations'], (int)$pendingCount['shift_id'],
                        isset($_POST['cancel_held_sales_reason']) ? (string)$_POST['cancel_held_sales_reason'] : null);
                unset($_SESSION['cashier_shift_count']);
                $message = 'Cashier Shift closed and reconciled.';
            }
        }
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The shift change could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.');
    }
}

$openShift = $targetCashierId > 0 ? $service->getOpenShift($targetCashierId) : null;
if ($isCashier && $openShift && (int)($_SESSION['drawer_movement_submission']['shift_id'] ?? 0) !== (int)$openShift['shift_id']) {
    $_SESSION['drawer_movement_submission'] = ['shift_id' => (int)$openShift['shift_id'], 'token' => bin2hex(random_bytes(24))];
}
$movementToken = (string)($_SESSION['drawer_movement_submission']['token'] ?? '');
$pendingCount = $_SESSION['cashier_shift_count'] ?? null;
if (is_array($pendingCount) && !isset($pendingCount['denominations'])) {
    unset($_SESSION['cashier_shift_count']);
    $pendingCount = null;
    $countPreview = null;
}
if ($countPreview === null && $openShift && is_array($pendingCount)
    && (int)($pendingCount['shift_id'] ?? 0) === (int)$openShift['shift_id']
    && (int)($pendingCount['cashier_id'] ?? 0) === $targetCashierId
    && (int)($pendingCount['actor_id'] ?? 0) === $actorId
    && ($pendingCount['actor_role'] ?? '') === $actorRole) {
    try {
        $countPreview = $service->previewReconciliation($targetCashierId, (float)$pendingCount['counted_cash'], !$isCashier);
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The shift count could not be shown. Check unresolved held sales and try again.');
    }
}
$summary = $openShift ? $service->calculateShift((int)$openShift['shift_id']) : null;
$movements = $openShift ? $service->drawerMovements((int)$openShift['shift_id']) : [];
// Ticket #91: the shift cannot close while any of these is unresolved, so the
// closing form names them rather than the cashier being told only that there are
// some. The refusal itself lives in CashierShiftService::closeShift(), because
// this form can be posted directly.
$unresolvedHeldSales = $openShift ? $service->unresolvedHeldSales((int)$openShift['shift_id']) : [];
$recent = $service->recentShifts($isCashier ? $targetCashierId : null);
// Only the Cashier workspace offers a Register to open on, and only Registers
// that are enabled and not already anchoring somebody else's open shift.
$availableRegisters = $isCashier && !$openShift ? $service->availableRegisters() : [];
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Cashier Shifts</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier-pages.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/cashier-pages.css')) ?>">
    <script src="<?= htmlspecialchars(app_url('assets/js/cash-drawer-counter.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/cash-drawer-counter.js')) ?>" defer></script>
</head>

<body class="cashier-shifts-page">
    <a class="skip-link" href="#shift-main">Skip to main content</a>
    <div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content" id="shift-main">
            <div class="topbar shift-topbar">
                <div>
                    <h1>Cashier Shifts</h1>
                </div><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i>Return to POS</a>
            </div>
            <?php if ($error !== ''): ?><div class="message error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php foreach ([[$message, 'success'], [$error, 'error']] as [$notice, $noticeType]): ?>
                <?php if ($notice !== ''): ?><script>RetailMindUI.toast(<?= json_encode($notice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($noticeType) ?>, undefined, 4300);</script><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($ownRegisterLocked): ?>
                <div class="workflow-notice" role="note" data-rm-alert-processed="1">
                    <div>
                        <h2>Register locked</h2>
                        <p><?= htmlspecialchars(CashierShiftService::LOCKED_MESSAGE) ?></p>
                    </div>
                    <a class="btn" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>">Unlock at POS</a>
                </div>
            <?php endif; ?>
            <?php if (!$isCashier): ?><section class="dashboard-section shift-selector">
                    <form method="get"><label for="shift-cashier">View open shift</label><select id="shift-cashier" name="cashier_id" onchange="this.form.submit()" <?= !$cashiers ? 'disabled' : '' ?>><?php if (!$cashiers): ?><option>No open shifts</option><?php endif; ?><?php foreach ($cashiers as $c): ?><option value="<?= (int)$c['user_id'] ?>" <?= $targetCashierId === (int)$c['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['display_name'] ?? $c['full_name'] ?? 'Cashier') ?></option><?php endforeach; ?></select><noscript><button class="btn" type="submit">View shift</button></noscript></form>
                </section><?php endif; ?>
                <section class="dashboard-section shift-overview">
                    <div class="shift-section-heading">
                        <?php if ($openShift): ?>
                            <div class="shift-heading-group">
                                <h2>Shift #<?= (int)$openShift['shift_id'] ?></h2>
                                <span class="shift-status shift-status--open"><span class="shift-dot" aria-hidden="true"></span>Open</span>
                            </div>
                        <?php else: ?>
                            <h2><?= $isCashier ? 'Open a shift' : 'No open shift' ?></h2>
                        <?php endif; ?>
                    </div>
                    <?php if (!$isCashier && !$openShift): ?><p class="shift-help">Cashier session required to open drawer.</p><?php endif; ?>
                    <?php if (!$openShift && $isCashier && !$availableRegisters): ?><p class="shift-empty">No registers available.</p><?php elseif (!$openShift && $isCashier): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>"><input type="hidden" name="action" value="open"><div class="form-row">
                            <div><label for="open-register">Register</label><select id="open-register" name="register_id" required><?php foreach ($availableRegisters as $register): ?><option value="<?= (int)$register['register_id'] ?>"><?= htmlspecialchars($register['name']) ?></option><?php endforeach; ?></select></div>
                            <div><label for="opening-float">Starting cash (₱)</label><input id="opening-float" type="number" name="opening_float" min="0" step="0.01" value="0" required></div>
                        </div><button class="btn" type="submit">Open shift</button></form><?php elseif ($openShift): ?>
                        <div class="shift-overview-content">
                            <dl class="shift-details">
                                <div><dt><i class="bi bi-display" aria-hidden="true"></i> Register</dt><dd><?= htmlspecialchars($openShift['register_name'] ?? 'Unassigned') ?></dd></div>
                                <div><dt><i class="bi bi-person" aria-hidden="true"></i> Cashier</dt><dd><?= htmlspecialchars($openShift['display_name'] ?? $openShift['full_name'] ?? 'Cashier') ?></dd></div>
                                <div><dt><i class="bi bi-clock" aria-hidden="true"></i> Opened</dt><dd><?= htmlspecialchars(format_display_datetime($openShift['opened_at'])) ?></dd></div>
                            </dl>
                        </div>
                        <?php
                        $moneyIn = ['Cash sales' => (float)$summary['cash_sales'], 'Drawer cash in' => (float)($summary['cash_in'] ?? 0)];
                        $moneyOut = [
                            'Cash refunds' => (float)($summary['cash_refunds'] ?? 0),
                            'Drawer cash out' => (float)($summary['cash_out'] ?? 0),
                            'Safe drops' => (float)($summary['safe_drop'] ?? 0),
                            'Legacy cash reversals' => (float)($summary['reversal_cash'] ?? 0),
                        ];
                        ?>
                        <dl class="shift-money-flow" aria-label="Expected drawer calculation">
                            <div><dt><i class="bi bi-wallet2" aria-hidden="true"></i>Starting cash</dt><dd><strong class="shift-flow-total">₱<?= number_format((float)$summary['opening_cash'], 2) ?></strong></dd></div>
                            <div><dt><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>+ Money in</dt><dd>
                                <strong class="shift-flow-total">₱<?= number_format(round(array_sum($moneyIn), 2), 2) ?></strong>
                                <ul class="shift-flow-breakdown"><?php foreach ($moneyIn as $label => $amount): ?><li><span><?= $label ?></span> <span>₱<?= number_format($amount, 2) ?></span></li><?php endforeach; ?></ul>
                            </dd></div>
                            <div><dt><i class="bi bi-box-arrow-up" aria-hidden="true"></i>− Money out</dt><dd>
                                <strong class="shift-flow-total">₱<?= number_format(round(array_sum($moneyOut), 2), 2) ?></strong>
                                <ul class="shift-flow-breakdown"><?php foreach ($moneyOut as $label => $amount): ?><?php if ($amount > 0): ?><li><span><?= $label ?></span> <span>₱<?= number_format($amount, 2) ?></span></li><?php endif; ?><?php endforeach; ?></ul>
                            </dd></div>
                            <div><dt><i class="bi bi-cash-stack" aria-hidden="true"></i>= Expected drawer</dt><dd><strong class="shift-flow-total">₱<?= number_format((float)$summary['calculated_expected_cash'], 2) ?></strong></dd></div>
                        </dl>
                        <div class="shift-activity-row">
                            <dl class="shift-activity-counts" aria-label="Shift activity">
                                <div><dt><i class="bi bi-receipt" aria-hidden="true"></i> Sales</dt><dd><?= (int)$summary['sale_count'] ?></dd></div>
                                <div><dt><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Refunds</dt><dd><?= (int)($summary['refund_count'] ?? 0) ?></dd></div>
                                <div><dt><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Drawer movements</dt><dd><?= count($movements) ?></dd></div>
                            </dl>
                            <p>Sales and refunds include all payment methods.</p>
                        </div>
                    <?php endif; ?>
                </section>
            <?php
            $shiftTabs = ['record' => ['Record movement', 'bi-plus-circle'], 'current' => ['Current movements', 'bi-arrow-left-right'], 'close' => ['Close shift', 'bi-calculator'], 'recent' => ['Recent shifts', 'bi-clock-history']];
            if (!$isCashier) unset($shiftTabs['record']);
            if (!$isCashier && $actorRole !== 'admin') unset($shiftTabs['close']);
            $activeShiftTab = $openShift ? ($isCashier && !$ownRegisterLocked ? 'record' : 'current') : 'recent';
            $requestedTab = $_GET['tab'] ?? '';
            if (is_string($requestedTab) && isset($shiftTabs[$requestedTab])) $activeShiftTab = $requestedTab;
            if ($countPreview || in_array($_POST['action'] ?? '', ['count', 'close'], true)) $activeShiftTab = 'close';
            if (($_POST['action'] ?? '') === 'movement' && $isCashier) $activeShiftTab = 'record';
            if ($closedSummary) $activeShiftTab = 'recent';
            if (!isset($shiftTabs[$activeShiftTab])) $activeShiftTab = 'recent';
            ?>
            <div class="shift-workspace" id="shift-workspace">
                <nav class="shift-tablist" role="tablist" aria-label="Cashier Shift sections">
                    <?php foreach ($shiftTabs as $key => [$label, $icon]): ?>
                        <a class="shift-tab" role="tab" id="shift-tab-<?= $key ?>" href="<?= htmlspecialchars(app_url('components/cashier/shifts.php?' . http_build_query(['tab' => $key] + (!$isCashier ? ['cashier_id' => $targetCashierId] : [])))) ?>" aria-selected="<?= $activeShiftTab === $key ? 'true' : 'false' ?>" aria-controls="shift-panel-<?= $key ?>" tabindex="<?= $activeShiftTab === $key ? '0' : '-1' ?>"><i class="bi <?= $icon ?>" aria-hidden="true"></i><?= $label ?></a>
                    <?php endforeach; ?>
                </nav>
                <?php if (isset($shiftTabs['record'])): ?><div class="shift-tabpanel" role="tabpanel" id="shift-panel-record" aria-labelledby="shift-tab-record" tabindex="0" <?= $activeShiftTab === 'record' ? '' : 'hidden' ?>>
                <?php if ($openShift && $isCashier && !$ownRegisterLocked && !$countPreview): ?><section class="dashboard-section shift-movement">
                        <h2>Record drawer cash</h2>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>"><input type="hidden" name="action" value="movement"><input type="hidden" name="movement_token" value="<?= htmlspecialchars($movementToken) ?>">
                            <div class="form-row">
                                <div><label for="movement-type">Cash movement</label><select id="movement-type" name="movement_type">
                                        <?php foreach (CashierShiftService::DRAWER_REASONS as $type => $reasons): ?>
                                            <option value="<?= htmlspecialchars($type) ?>" <?= ($_POST['movement_type'] ?? '') === $type ? 'selected' : '' ?>><?= htmlspecialchars(['cash_in' => 'Cash in', 'cash_out' => 'Cash out', 'safe_drop' => 'Safe drop'][$type]) ?></option>
                                        <?php endforeach; ?>
                                    </select></div>
                                <div><label for="movement-amount">Amount (₱)</label><input id="movement-amount" type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required value="<?= htmlspecialchars((string)($_POST['amount'] ?? '')) ?>"></div>
                            </div><div class="form-row"><div><label for="movement-reason">Reason</label><select id="movement-reason" name="reason" required>
                                <?php foreach (CashierShiftService::DRAWER_REASONS as $type => $reasons): ?>
                                    <?php foreach ($reasons as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>" data-types="<?= htmlspecialchars($type) ?>" <?= ($_POST['movement_type'] ?? '') === $type && ($_POST['reason'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </select></div><div><label for="movement-source" id="movement-source-label">Cash from</label><input id="movement-source" name="source_destination" type="text" maxlength="200" required value="<?= htmlspecialchars((string)($_POST['source_destination'] ?? '')) ?>"></div></div>
                            <label for="movement-note">Explanation (required for Other)</label><textarea id="movement-note" name="note" maxlength="255" rows="2"><?= htmlspecialchars((string)($_POST['note'] ?? '')) ?></textarea>
                            <p class="shift-help">Customer refunds belong in Cash Refunds. Supplier payments and petty cash need Administrator approval.</p>
                            <button class="btn" type="submit">Record movement</button>
                            <dialog id="movement-authorization" aria-labelledby="movement-authorization-title" aria-describedby="movement-authorization-summary">
                                <h2 id="movement-authorization-title">Authorize drawer movement</h2>
                                <p id="movement-authorization-summary" class="shift-help"></p>
                                <label for="movement-approver">Authorizing Administrator username or email</label><input id="movement-approver" name="approver_username" autocomplete="off" spellcheck="false" required disabled>
                                <label for="movement-approval-password">Administrator password</label><input id="movement-approval-password" name="approver_password" type="password" autocomplete="new-password" required disabled>
                                <div class="modal-actions">
                                    <button class="btn btn-secondary" type="button" id="movement-authorization-cancel">Cancel</button>
                                    <button class="btn" type="submit">Authorize and record</button>
                                </div>
                            </dialog>
                        </form>
                    </section><?php else: ?><section class="dashboard-section"><h2>Record drawer cash</h2><p class="shift-empty"><?= !$openShift ? 'Open your Cashier Shift to record drawer cash.' : ($ownRegisterLocked ? 'Unlock your register at POS to record drawer cash.' : 'Your cash count is submitted. Finish closing this shift before recording another movement.') ?></p></section><?php endif; ?>
                </div><?php endif; ?>
                <?php if (isset($shiftTabs['current'])): ?><div class="shift-tabpanel" role="tabpanel" id="shift-panel-current" aria-labelledby="shift-tab-current" tabindex="0" <?= $activeShiftTab === 'current' ? '' : 'hidden' ?>>
            <?php if ($openShift): ?><section class="dashboard-section" id="current-shift-movements">
                    <div class="shift-section-heading"><h2>Current shift movements</h2><?php if ($isCashier): ?><a href="<?= htmlspecialchars(app_url('components/cashier/history.php?type=movements')) ?>">All drawer movements</a><?php endif; ?></div>
                    <?php if ($movements): ?>
                    <div class="table-wrap">
                        <table class="shift-movement-table" data-page-size="10">
                            <thead><tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Reason</th><th>Source / destination and note</th><th>Cashier</th>
                            </tr></thead><tbody><?php foreach ($movements as $m): ?><tr>
                                    <td data-label="Time"><?= htmlspecialchars(format_display_datetime($m['created_at'])) ?></td>
                                    <td data-label="Type"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($m['movement_type']))) ?></td>
                                    <td data-label="Amount"><?= in_array($m['movement_type'], ['cash_in', 'pay_in'], true) ? '+' : '−' ?>₱<?= number_format((float)$m['amount'], 2) ?></td>
                                    <td data-label="Reason"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($m['reason']))) ?></td>
                                    <td data-label="Source / destination and note"><?= htmlspecialchars($m['note'] ?? '') ?></td>
                                    <td data-label="Cashier"><?= htmlspecialchars($m['display_name'] ?? $m['full_name'] ?? 'Cashier') ?></td>
                                </tr><?php endforeach; ?>
                        </tbody></table>
                    </div>
                    <?php else: ?><p class="shift-empty">No drawer movements recorded for this shift.</p><?php endif; ?>
                </section><?php else: ?><section class="dashboard-section"><h2>Current shift movements</h2><p class="shift-empty">Open a shift to see its drawer movements.</p></section><?php endif; ?>
                </div><?php endif; ?>
                <?php if (isset($shiftTabs['close'])): ?><div class="shift-tabpanel" role="tabpanel" id="shift-panel-close" aria-labelledby="shift-tab-close" tabindex="0" <?= $activeShiftTab === 'close' ? '' : 'hidden' ?>>
                <?php if ($openShift && ($isCashier || $actorRole === 'admin')): ?><section class="dashboard-section shift-reconciliation">
                        <div class="shift-reconciliation-header">
                            <span class="shift-money-header-badge" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                    <path d="M1 3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1zm7 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4"/>
                                    <path d="M0 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H1a1 1 0 0 1-1-1zm3 0a2 2 0 0 1-2 2v4a2 2 0 0 1 2 2h10a2 2 0 0 1 2-2V7a2 2 0 0 1-2-2z"/>
                                </svg>
                            </span>
                            <div class="shift-reconciliation-title-group">
                                <h2><?= $countPreview ? 'Review count and close shift' : 'Count cash to close shift' ?></h2>
                                <?php if (!$countPreview): ?>
                                    <p class="shift-help shift-count-instruction" id="count-help">Enter the quantity of each bill and coin.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$isCashier): ?><p class="shift-help">Closing for <?= htmlspecialchars($openShift['display_name'] ?? $openShift['full_name'] ?? 'Cashier') ?>.</p><?php endif; ?>
                        <?php if ($unresolvedHeldSales): ?><div class="message error shift-held-alert" role="note" data-rm-alert-processed="1">
                            <div class="shift-held-alert-head">
                                <span><strong><?= count($unresolvedHeldSales) ?> unresolved held sale<?= count($unresolvedHeldSales) === 1 ? '' : 's' ?></strong> blocking close</span>
                                <?php if ($isCashier): ?><a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>">Open POS</a><?php endif; ?>
                            </div>
                            <ul><?php foreach ($unresolvedHeldSales as $unresolved): ?>
                                    <li>#<?= htmlspecialchars($unresolved['reference_no']) ?> &middot; <?= (int)$unresolved['item_count'] ?> item(s) &middot; &#8369;<?= number_format((float)$unresolved['total_amount'], 2) ?> &middot; <?= $unresolved['status'] === 'resumed' ? 'resumed' : 'held' ?></li>
                                <?php endforeach; ?></ul>
                        </div><?php endif; ?>
                        <?php if ($countPreview): ?>
                            <div class="shift-variance-card <?= $countPreview['cash_variance'] < 0 ? 'is-shortage' : ($countPreview['cash_variance'] > 0 ? 'is-overage' : 'is-balanced') ?>">
                                <div class="variance-badge">
                                    <i class="bi <?= $countPreview['cash_variance'] < 0 ? 'bi-exclamation-triangle-fill' : ($countPreview['cash_variance'] > 0 ? 'bi-plus-circle-fill' : 'bi-check-circle-fill') ?>" aria-hidden="true"></i>
                                    <span><?= $countPreview['cash_variance'] < 0 ? 'Cash shortage' : ($countPreview['cash_variance'] > 0 ? 'Cash overage' : 'Balanced') ?></span>
                                </div>
                                <div class="variance-amount">
                                    <?= $countPreview['cash_variance'] != 0 ? ($countPreview['cash_variance'] > 0 ? '+₱' : '-₱') . number_format(abs((float)$countPreview['cash_variance']), 2) : '₱0.00' ?>
                                </div>
                            </div>
                            <p class="count-match visually-hidden <?= $countPreview['cash_variance'] < 0 ? 'negative' : 'positive' ?>" role="status"><?= $countPreview['cash_variance'] < 0 ? 'Cash shortage' : ($countPreview['cash_variance'] > 0 ? 'Cash overage' : 'Exact match') ?><?= $countPreview['cash_variance'] != 0 ? ': ₱' . number_format(abs((float)$countPreview['cash_variance']), 2) : ' — cash is balanced.' ?></p>
                            <div class="card-grid shift-preview-grid">
                                <div class="stat-card"><div class="value">₱<?= number_format((float)$countPreview['counted_cash'], 2) ?></div><div class="label">Counted</div></div>
                                <div class="stat-card"><div class="value">₱<?= number_format((float)$countPreview['expected_cash'], 2) ?></div><div class="label">Expected</div></div>
                                <div class="stat-card"><div class="value <?= $countPreview['cash_variance'] < 0 ? 'negative' : ($countPreview['cash_variance'] > 0 ? 'positive' : '') ?>"><?= ($countPreview['cash_variance'] > 0 ? '+' : '') ?>₱<?= number_format((float)$countPreview['cash_variance'], 2) ?></div><div class="label">Difference</div></div>
                            </div>
                            <details class="money-count-breakdown">
                                <summary>Cash count breakdown</summary>
                                <div class="breakdown-grid">
                                <?php foreach (CashDenominationCounter::DENOMINATIONS as $group => $denominations): ?>
                                    <div class="breakdown-group">
                                        <h3><?= $group ?></h3>
                                        <dl><?php foreach ($denominations as $key => $cents): ?>
                                            <?php $quantity = (int)($pendingCount['denominations'][$key] ?? 0); ?>
                                            <?php if ($quantity > 0): ?><div><dt>₱<?= CashDenominationCounter::pesos($cents) ?> × <?= $quantity ?></dt><dd>₱<?= CashDenominationCounter::pesos($cents * $quantity) ?></dd></div><?php endif; ?>
                                        <?php endforeach; ?></dl>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            </details>
                            <?php if ($countPreview['variance_review_required']): ?><p class="message error" role="note" data-rm-alert-processed="1">Variance exceeds threshold (₱<?= number_format((float)$countPreview['variance_threshold'], 2) ?>). Notes required.</p><?php endif; ?>
                            <form method="POST" data-confirm="Close this Cashier Shift with the submitted count?" data-confirm-title="Close cashier shift" data-confirm-button="Close shift">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="action" value="close">
                                <input type="hidden" name="shift_id" value="<?= (int)$openShift['shift_id'] ?>">
                                <label for="closing-notes">Closing notes<?= $countPreview['variance_review_required'] ? ' (required)' : ' (optional)' ?></label>
                                <textarea id="closing-notes" name="closing_notes" <?= $countPreview['variance_review_required'] ? 'required' : '' ?> placeholder="Notes or variance explanation…"></textarea>
                                <?php if (!$isCashier): ?><label for="intervention-reason">Intervention reason (required)</label><textarea id="intervention-reason" name="intervention_reason" required placeholder="Reason for closing another cashier's shift…"></textarea>
                                <?php if ($unresolvedHeldSales): ?><label for="cancel-held-sales-reason">Reason to cancel unpaid held sales (required)</label><textarea id="cancel-held-sales-reason" name="cancel_held_sales_reason" required></textarea><?php endif; ?><?php endif; ?>
                                <button class="btn" type="submit">Close shift</button>
                            </form>
                        <?php else: ?>
                            <?php require __DIR__ . '/../../../backend/app/Views/cash_drawer_counter.php'; ?>
                        <?php endif; ?>
                    </section><?php else: ?><section class="dashboard-section"><h2>Count cash to close shift</h2><p class="shift-empty">Open a Cashier Shift before counting its drawer.</p></section><?php endif; ?>
                </div><?php endif; ?>
                <?php if (isset($shiftTabs['recent'])): ?><div class="shift-tabpanel" role="tabpanel" id="shift-panel-recent" aria-labelledby="shift-tab-recent" tabindex="0" <?= $activeShiftTab === 'recent' ? '' : 'hidden' ?>>
            <?php if ($closedSummary): ?><section class="dashboard-section shift-reconciled-banner" role="status">
                <h2>Closing reconciliation</h2>
                <div class="shift-reconciled-grid">
                    <div><span class="label">Counted</span><span class="value">₱<?= number_format((float)$closedSummary['actual_cash'], 2) ?></span></div>
                    <div><span class="label">Expected</span><span class="value">₱<?= number_format((float)$closedSummary['expected_cash'], 2) ?></span></div>
                    <div><span class="label">Difference</span><span class="value <?= (float)$closedSummary['cash_variance'] < 0 ? 'negative' : 'positive' ?>"><?= (float)$closedSummary['cash_variance'] >= 0 ? '+' : '' ?>₱<?= number_format((float)$closedSummary['cash_variance'], 2) ?></span></div>
                </div>
                <?php if ($closedSummary['variance_review_required']): ?><p class="shift-history-meta"><i class="bi bi-flag-fill" aria-hidden="true"></i> Flagged for Administrator review.</p><?php endif; ?>
            </section><?php endif; ?>
            <section class="dashboard-section shift-history">
                <div class="shift-section-heading"><h2 id="recent-shifts-heading">Recent shifts</h2><span class="shift-history-count"><?= count($recent) ?> shift<?= count($recent) === 1 ? '' : 's' ?></span></div>
                <?php if ($isCashier): ?><p><a href="<?= htmlspecialchars(app_url('components/cashier/history.php?type=movements')) ?>">Drawer movement history across shifts</a></p><?php endif; ?>
                <?php if ($recent): ?><div class="table-wrap" role="region" aria-labelledby="recent-shifts-heading" tabindex="0">
                    <table class="shift-history-table" data-page-size="10">
                        <thead><tr>
                            <th>Cashier / register</th>
                            <th>Opened</th>
                            <th>Closed</th>
                            <th>Status</th>
                            <th>Expected cash</th>
                            <th>Counted cash</th>
                            <th>Difference</th>
                        </tr></thead><tbody><?php foreach ($recent as $r): ?><tr>
                                <td data-label="Cashier / register"><?= htmlspecialchars($r['display_name'] ?? $r['full_name'] ?? 'Cashier') ?><span class="shift-history-meta"><?= htmlspecialchars($r['register_name'] ?? 'Unassigned') ?></span></td>
                                <td data-label="Opened" class="shift-date"><?= htmlspecialchars(format_display_datetime($r['opened_at'])) ?></td>
                                <td data-label="Closed" class="shift-date"><?= $r['closed_at'] ? htmlspecialchars(format_display_datetime($r['closed_at'])) : '—' ?>
                                    <?php if (!empty($r['closing_actor_name'])): ?><span class="shift-history-meta">By <?= htmlspecialchars($r['closing_actor_name']) ?></span><?php endif; ?>
                                    <?php if (!empty($r['intervention_reason'])): ?><details class="shift-intervention"><summary>Intervention reason</summary><p><?= htmlspecialchars($r['intervention_reason']) ?></p></details><?php endif; ?>
                                </td>
                                <td data-label="Status"><span class="shift-status <?= $r['status'] === 'open' ? 'shift-status--open' : 'shift-status--closed' ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span><?php if (!empty($r['variance_review_required'])): ?><span class="shift-history-meta"><i class="bi bi-flag-fill" aria-hidden="true"></i> Review</span><?php endif; ?></td>
                                <td data-label="Expected cash"><?= $r['expected_cash'] !== null ? '₱' . number_format((float)$r['expected_cash'], 2) : '—' ?></td>
                                <td data-label="Counted cash"><?= $r['actual_cash'] !== null ? '₱' . number_format((float)$r['actual_cash'], 2) : '—' ?></td>
                                <td data-label="Difference"><?php if ($r['cash_variance'] === null): ?>—<?php else: ?><span class="variance-badge-cell <?= (float)$r['cash_variance'] < 0 ? 'is-shortage' : ((float)$r['cash_variance'] > 0 ? 'is-overage' : 'is-balanced') ?>"><?= (float)$r['cash_variance'] >= 0 ? '+' : '' ?>₱<?= number_format((float)$r['cash_variance'], 2) ?></span><?php endif; ?></td>
                            </tr><?php endforeach; ?></tbody>
                    </table>
                </div><?php else: ?><p class="shift-empty">No shifts yet.</p><?php endif; ?>
            </section>
                </div><?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        const shiftWorkspace = document.getElementById('shift-workspace');
        const shiftTabs = [...shiftWorkspace.querySelectorAll('.shift-tablist [role="tab"]')];
        const alignShiftTab = tab => {
            const nav = tab.parentElement;
            nav.scrollLeft += tab.getBoundingClientRect().left < nav.getBoundingClientRect().left
                ? tab.getBoundingClientRect().left - nav.getBoundingClientRect().left
                : Math.max(0, tab.getBoundingClientRect().right - nav.getBoundingClientRect().right);
        };
        const selectShiftTab = (tab, focus = false) => {
            shiftTabs.forEach(item => {
                const selected = item === tab;
                item.setAttribute('aria-selected', String(selected));
                item.tabIndex = selected ? 0 : -1;
                document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
            });
            if (focus) tab.focus({preventScroll: true});
            alignShiftTab(tab);
            history.replaceState(null, '', tab.href);
        };
        shiftTabs.forEach((tab, index) => {
            tab.addEventListener('click', event => {
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                selectShiftTab(tab);
            });
            tab.addEventListener('keydown', event => {
                let next = index;
                if (event.key === 'ArrowRight') next = (index + 1) % shiftTabs.length;
                else if (event.key === 'ArrowLeft') next = (index - 1 + shiftTabs.length) % shiftTabs.length;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = shiftTabs.length - 1;
                else return;
                event.preventDefault();
                selectShiftTab(shiftTabs[next], true);
            });
        });
        const alignActiveShiftTab = () => alignShiftTab(shiftTabs.find(tab => tab.getAttribute('aria-selected') === 'true'));
        window.addEventListener('resize', alignActiveShiftTab);
        alignActiveShiftTab();
        const movementType = document.getElementById('movement-type');
        const movementReason = document.getElementById('movement-reason');
        if (movementType && movementReason) {
            const authorization = document.getElementById('movement-authorization');
            const credentials = authorization.querySelectorAll('input');
            movementType.form.addEventListener('submit', event => {
                const spending = movementType.value === 'cash_out' && ['petty_cash', 'supplier_payment'].includes(movementReason.value);
                if (!spending || authorization.open) return;
                event.preventDefault();
                document.getElementById('movement-authorization-summary').textContent =
                    movementReason.selectedOptions[0].textContent + ' · ₱' + Number(document.getElementById('movement-amount').value).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                credentials.forEach(input => { input.disabled = false; });
                authorization.showModal();
                window.RetailMindUI?.syncScrollLock?.();
            });
            document.getElementById('movement-authorization-cancel').addEventListener('click', () => authorization.close());
            authorization.addEventListener('close', () => {
                credentials.forEach(input => { input.value = ''; input.disabled = true; });
                window.RetailMindUI?.syncScrollLock?.();
            });
            const syncReasons = () => {
                document.getElementById('movement-source-label').textContent = movementType.value === 'cash_in' ? 'Cash from' : 'Cash to';
                for (const option of movementReason.options) {
                    option.hidden = !option.dataset.types.split(' ').includes(movementType.value);
                    option.disabled = option.hidden;
                }
                if (movementReason.selectedOptions[0]?.disabled) {
                    movementReason.selectedIndex = [...movementReason.options].findIndex(option => !option.disabled);
                }
                document.getElementById('movement-note').required = movementReason.value === 'other';
            };
            movementType.addEventListener('change', syncReasons);
            movementReason.addEventListener('change', syncReasons);
            syncReasons();
        }
    </script>
</body>

</html>
