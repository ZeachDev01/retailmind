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

require_role(['admin', 'super_admin', 'cashier']);

$service = new CashierShiftService($pdo);
$actorId = (int)$_SESSION['user_id'];
$actorRole = (string)current_role();
// Issue #86/#88: the active workspace decides who may open a shift. An
// Administrator who also holds the Cashier role must switch workspaces first.
$isCashier = $actorRole === 'cashier';
$cashiers = [];
if (!$isCashier) {
    $cashiers = $pdo->query("SELECT DISTINCT u.user_id,u.full_name FROM users u JOIN cashier_shifts cs ON cs.cashier_id=u.user_id AND cs.status='open' ORDER BY u.full_name")->fetchAll(PDO::FETCH_ASSOC);
}
$targetCashierId = $isCashier ? $actorId : (int)($_GET['cashier_id'] ?? ($cashiers[0]['user_id'] ?? 0));
if (!$isCashier && $targetCashierId > 0 && !in_array($targetCashierId, array_map(static fn(array $c): int => (int)$c['user_id'], $cashiers), true)) {
    $targetCashierId = (int)($cashiers[0]['user_id'] ?? 0);
}
$message = '';
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
            $movementId = $service->addDrawerMovement($actorId, $actorRole, (string)($_POST['movement_type'] ?? ''), (float)($_POST['amount'] ?? 0), (string)($_POST['reason'] ?? ''), (string)($_POST['note'] ?? ''));
            $message = 'Cash drawer movement recorded.';
        } elseif ($action === 'count' || $action === 'close') {
            if (!$isCashier && $actorRole !== 'admin') {
                throw new DomainException('Only an Administrator can close another Cashier\'s Shift.');
            }
            $currentShift = $service->getOpenShift($targetCashierId);
            if (!$currentShift) {
                throw new DomainException('No open Cashier Shift was found.');
            }
            $pendingCount = $_SESSION['cashier_shift_count'] ?? null;
            $sameShift = is_array($pendingCount)
                && (int)($pendingCount['shift_id'] ?? 0) === (int)$currentShift['shift_id']
                && (int)($pendingCount['cashier_id'] ?? 0) === $targetCashierId
                && (int)($pendingCount['actor_id'] ?? 0) === $actorId
                && ($pendingCount['actor_role'] ?? '') === $actorRole;
            if ($action === 'count') {
                if (!$sameShift) {
                    $rawCount = (string)($_POST['actual_cash'] ?? '');
                    if (!is_numeric($rawCount)) {
                        throw new InvalidArgumentException('Enter the counted cash amount.');
                    }
                    $pendingCount = [
                        'cashier_id' => $targetCashierId,
                        'actor_id' => $actorId,
                        'actor_role' => $actorRole,
                        'shift_id' => (int)$currentShift['shift_id'],
                        'counted_cash' => (float)$rawCount,
                    ];
                }
                $countedCash = (float)$pendingCount['counted_cash'];
                $countPreview = $service->previewReconciliation($targetCashierId, $countedCash);
                $_SESSION['cashier_shift_count'] = $pendingCount;
            } else {
                if (!$sameShift) {
                    throw new DomainException('Submit the cash count before closing this Cashier Shift.');
                }
                $countedCash = (float)$pendingCount['counted_cash'];
                $closedSummary = $isCashier
                    ? $service->closeShift($actorId, $countedCash, (string)($_POST['closing_notes'] ?? ''))
                    : $service->closeAbandonedShift($actorId, $actorRole, $targetCashierId, $countedCash,
                        (string)($_POST['closing_notes'] ?? ''), (string)($_POST['intervention_reason'] ?? ''));
                unset($_SESSION['cashier_shift_count']);
                $message = 'Cashier Shift closed and reconciled.';
            }
        }
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The shift change could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.');
    }
}

$openShift = $targetCashierId > 0 ? $service->getOpenShift($targetCashierId) : null;
$pendingCount = $_SESSION['cashier_shift_count'] ?? null;
if ($countPreview === null && $openShift && is_array($pendingCount)
    && (int)($pendingCount['shift_id'] ?? 0) === (int)$openShift['shift_id']
    && (int)($pendingCount['cashier_id'] ?? 0) === $targetCashierId
    && (int)($pendingCount['actor_id'] ?? 0) === $actorId
    && ($pendingCount['actor_role'] ?? '') === $actorRole) {
    try {
        $countPreview = $service->previewReconciliation($targetCashierId, (float)$pendingCount['counted_cash']);
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

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Cashier Shifts</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier-pages.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/cashier-pages.css')) ?>">
</head>

<body class="cashier-shifts-page">
    <div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
            <div class="topbar">
                <div>
                    <h1>Cashier Shifts</h1>
                    <p class="page-subtitle">Open the register, record drawer cash changes, and reconcile cash at closing.</p>
                </div><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back</a>
            </div>
            <?php if ($message): ?><div class="message success"><?= htmlspecialchars($message) ?></div><?php endif; ?><?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($ownRegisterLocked): ?><div class="message error"><?= htmlspecialchars(CashierShiftService::LOCKED_MESSAGE) ?> <a href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>">Unlock it at the point of sale</a> to carry on with this same shift.</div><?php endif; ?>
            <?php if (!$isCashier): ?><section class="dashboard-section shift-selector">
                    <form method="get"><label for="shift-cashier">View open shift</label><select id="shift-cashier" name="cashier_id" onchange="this.form.submit()" <?= !$cashiers ? 'disabled' : '' ?>><?php if (!$cashiers): ?><option>No open shifts</option><?php endif; ?><?php foreach ($cashiers as $c): ?><option value="<?= (int)$c['user_id'] ?>" <?= $targetCashierId === (int)$c['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['full_name']) ?></option><?php endforeach; ?></select><noscript><button class="btn" type="submit">View shift</button></noscript></form>
                </section><?php endif; ?>
            <div class="shift-grid">
                <section class="dashboard-section shift-overview">
                    <div class="shift-section-heading"><h2><?= $openShift ? 'Open shift' : 'Open a shift' ?></h2><?php if ($openShift): ?><span class="shift-status shift-status--open">Open</span><?php endif; ?></div>
                    <?php if (!$isCashier): ?><p class="shift-help">A Cashier Shift is opened and owned by the Cashier who sells. <?= $actorRole === 'admin' ? 'An Administrator may reconcile an abandoned shift here.' : 'You may review shifts here.' ?></p><?php endif; ?>
                    <?php if (!$openShift && $isCashier && !$availableRegisters): ?><p>No Register is free right now. Every Register is either disabled or already on an open shift. Tell your Administrator.</p><?php elseif (!$openShift && $isCashier): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>"><input type="hidden" name="action" value="open"><div class="form-row">
                            <div><label for="open-register">Register</label><select id="open-register" name="register_id" required><?php foreach ($availableRegisters as $register): ?><option value="<?= (int)$register['register_id'] ?>"><?= htmlspecialchars($register['name']) ?></option><?php endforeach; ?></select></div>
                            <div><label for="opening-float">Opening float</label><input id="opening-float" type="number" name="opening_float" min="0" step="0.01" value="0" required></div>
                        </div><button class="btn" type="submit">Open shift</button></form><?php elseif ($openShift): ?>
                        <div class="shift-overview-content">
                            <dl class="shift-details">
                                <div><dt>Shift</dt><dd>#<?= (int)$openShift['shift_id'] ?></dd></div>
                                <div><dt>Register</dt><dd><?= htmlspecialchars($openShift['register_name'] ?? 'Unassigned') ?></dd></div>
                                <div><dt>Opened</dt><dd><?= htmlspecialchars(format_display_datetime($openShift['opened_at'])) ?></dd></div>
                            </dl>
                        <div class="card-grid shift-metrics">
                            <?php if ($countPreview): ?><div class="stat-card">
                                <div class="value">₱<?= number_format((float)$summary['opening_cash'], 2) ?></div>
                                <div class="label">Opening float</div>
                            </div>
                            <div class="stat-card">
                                <div class="value">₱<?= number_format((float)$summary['cash_sales'], 2) ?></div>
                                <div class="label">Cash sales</div>
                            </div><?php endif; ?>
                            <?php if (!$isCashier && $actorRole === 'super_admin'): ?><div class="stat-card">
                                <div class="value">₱<?= number_format((float)$summary['calculated_expected_cash'], 2) ?></div>
                                <div class="label">Expected drawer</div>
                            </div><?php endif; ?>
                            <div class="stat-card">
                                <div class="value"><?= (int)$summary['sale_count'] ?></div>
                                <div class="label">Transactions</div>
                            </div>
                        </div></div><?php endif; ?>
                </section>
                <?php if ($openShift && $isCashier && !$ownRegisterLocked && !$countPreview): ?><section class="dashboard-section">
                        <h2>Drawer movement</h2>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>"><input type="hidden" name="action" value="movement">
                            <div class="form-row">
                                <div><label for="movement-type">Type</label><select id="movement-type" name="movement_type">
                                        <?php foreach (CashierShiftService::DRAWER_REASONS as $type => $reasons): ?>
                                            <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $type))) ?></option>
                                        <?php endforeach; ?>
                                    </select></div>
                                <div><label for="movement-amount">Amount (₱)</label><input id="movement-amount" type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required></div>
                            </div><label for="movement-reason">Reason</label><select id="movement-reason" name="reason" required>
                                <?php foreach (CashierShiftService::DRAWER_REASONS as $type => $reasons): ?>
                                    <?php foreach ($reasons as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>" data-types="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </select><label for="movement-note">Note <span class="shift-optional">(optional)</span></label><textarea id="movement-note" name="note" maxlength="255" rows="2"></textarea><button class="btn" type="submit">Record movement</button>
                        </form>
                    </section><?php endif; ?>
                <?php if ($openShift && ($isCashier || $actorRole === 'admin')): ?><section class="dashboard-section">
                        <h2>Close and reconcile</h2>
                        <?php if (!$isCashier): ?><p>Intervention for <?= htmlspecialchars($openShift['full_name']) ?> on <?= htmlspecialchars($openShift['register_name'] ?? 'Unassigned Register') ?>. Count the drawer before viewing its expected balance.</p><?php endif; ?>
                        <?php if ($unresolvedHeldSales): ?><div class="message error">
                            This shift still has <?= count($unresolvedHeldSales) ?> held sale<?= count($unresolvedHeldSales) === 1 ? '' : 's' ?>. Complete or discard <?= count($unresolvedHeldSales) === 1 ? 'it' : 'them' ?> at the point of sale before closing.
                            <ul><?php foreach ($unresolvedHeldSales as $unresolved): ?>
                                    <li><?= htmlspecialchars($unresolved['reference_no']) ?> &middot; <?= (int)$unresolved['item_count'] ?> item(s) &middot; &#8369;<?= number_format((float)$unresolved['total_amount'], 2) ?> &middot; <?= $unresolved['status'] === 'resumed' ? 'resumed' : 'held' ?> since <?= htmlspecialchars((string)$unresolved['created_at']) ?></li>
                                <?php endforeach; ?></ul>
                        </div><?php endif; ?>
                        <?php if ($countPreview): ?>
                            <div class="card-grid">
                                <div class="stat-card"><div class="value">₱<?= number_format((float)$countPreview['counted_cash'], 2) ?></div><div class="label">Counted cash</div></div>
                                <div class="stat-card"><div class="value">₱<?= number_format((float)$countPreview['expected_cash'], 2) ?></div><div class="label">Expected cash</div></div>
                                <div class="stat-card"><div class="value">₱<?= number_format((float)$countPreview['cash_variance'], 2) ?></div><div class="label">Variance</div></div>
                            </div>
                            <?php if ($countPreview['variance_review_required']): ?><p class="message error">This variance exceeds ₱<?= number_format((float)$countPreview['variance_threshold'], 2) ?>. Add a reason; the Administrator will review it after closure.</p><?php endif; ?>
                            <form method="POST" data-confirm="Close this Cashier Shift with the submitted count?" data-confirm-title="Close cashier shift" data-confirm-button="Close shift">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="action" value="close">
                                <label for="closing-notes">Closing reason or notes<?= $countPreview['variance_review_required'] ? ' (required)' : ' (optional)' ?></label>
                                <textarea id="closing-notes" name="closing_notes" <?= $countPreview['variance_review_required'] ? 'required' : '' ?>></textarea>
                                <?php if (!$isCashier): ?><label for="intervention-reason">Intervention reason (required)</label><textarea id="intervention-reason" name="intervention_reason" required></textarea><?php endif; ?>
                                <button class="btn" type="submit">Close shift</button>
                            </form>
                        <?php else: ?>
                            <p class="shift-help" id="count-help">Count the drawer cash before seeing its expected balance.</p>
                            <form method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>"><input type="hidden" name="action" value="count"><label for="actual-cash">Cash counted (₱)</label><input id="actual-cash" type="number" name="actual_cash" min="0" step="0.01" placeholder="0.00" aria-describedby="count-help" required><button class="btn" type="submit" <?= $unresolvedHeldSales ? 'disabled' : '' ?>>Submit count</button></form>
                        <?php endif; ?>
                    </section><?php endif; ?>
            </div>
            <?php if ($closedSummary): ?><section class="dashboard-section" role="status">
                <h2>Closing reconciliation</h2>
                <p>Counted cash: ₱<?= number_format((float)$closedSummary['actual_cash'], 2) ?> · Expected cash: ₱<?= number_format((float)$closedSummary['expected_cash'], 2) ?> · Variance: ₱<?= number_format((float)$closedSummary['cash_variance'], 2) ?></p>
                <?php if ($closedSummary['variance_review_required']): ?><p>Flagged for Administrator review.</p><?php endif; ?>
            </section><?php endif; ?>
            <?php if ($openShift && $movements && $countPreview): ?><section class="dashboard-section">
                    <h2>Current shift movements</h2>
                    <div class="table-wrap">
                        <table>
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Reason</th><th>Note</th><th>Cashier</th>
                            </tr><?php foreach ($movements as $m): ?><tr>
                                    <td><?= htmlspecialchars(format_display_datetime($m['created_at'])) ?></td>
                                    <td><?= htmlspecialchars(str_replace('_', ' ', ucfirst($m['movement_type']))) ?></td>
                                    <td>₱<?= number_format((float)$m['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars(str_replace('_', ' ', ucfirst($m['reason']))) ?></td>
                                    <td><?= htmlspecialchars($m['note'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($m['full_name']) ?></td>
                                </tr><?php endforeach; ?>
                        </table>
                    </div>
                </section><?php endif; ?>
            <section class="dashboard-section shift-history">
                <div class="shift-section-heading"><h2 id="recent-shifts-heading">Recent shifts</h2><span class="shift-history-count"><?= count($recent) ?> shift<?= count($recent) === 1 ? '' : 's' ?></span></div>
                <?php if ($recent): ?><div class="table-wrap" role="region" aria-labelledby="recent-shifts-heading" tabindex="0">
                    <table class="shift-history-table">
                        <thead><tr>
                            <th>Cashier</th>
                            <th>Register</th>
                            <th>Opened</th>
                            <th>Closed</th>
                            <th>Closed by</th>
                            <th>Intervention reason</th>
                            <th>Status</th>
                            <th>Review</th>
                            <th>Expected</th>
                            <th>Actual</th>
                            <th>Variance</th>
                        </tr></thead><tbody><?php foreach ($recent as $r): ?><tr>
                                <td><?= htmlspecialchars($r['full_name']) ?></td>
                                <td><?= htmlspecialchars($r['register_name'] ?? 'Unassigned') ?></td>
                                <td class="shift-date"><?= htmlspecialchars(format_display_datetime($r['opened_at'])) ?></td>
                                <td class="shift-date"><?= $r['closed_at'] ? htmlspecialchars(format_display_datetime($r['closed_at'])) : '—' ?></td>
                                <td><?= htmlspecialchars($r['closing_actor_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['intervention_reason'] ?? '-') ?></td>
                                <td><span class="shift-status <?= $r['status'] === 'open' ? 'shift-status--open' : 'shift-status--closed' ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span></td>
                                <td><?= !empty($r['variance_review_required']) ? 'Administrator review needed' : '-' ?></td>
                                <td><?= $r['expected_cash'] !== null ? '₱' . number_format((float)$r['expected_cash'], 2) : '-' ?></td>
                                <td><?= $r['actual_cash'] !== null ? '₱' . number_format((float)$r['actual_cash'], 2) : '-' ?></td>
                                <td class="<?= (float)($r['cash_variance'] ?? 0) >= 0 ? 'positive' : 'negative' ?>"><?= $r['cash_variance'] !== null ? '₱' . number_format((float)$r['cash_variance'], 2) : '-' ?></td>
                            </tr><?php endforeach; ?></tbody>
                    </table>
                </div><?php else: ?><p class="shift-empty">No shifts to show yet. Shift history will appear here after a shift is opened.</p><?php endif; ?>
            </section>
        </main>
    </div>
    <script>
        const movementType = document.getElementById('movement-type');
        const movementReason = document.getElementById('movement-reason');
        if (movementType && movementReason) {
            const syncReasons = () => {
                for (const option of movementReason.options) {
                    option.hidden = !option.dataset.types.split(' ').includes(movementType.value);
                    option.disabled = option.hidden;
                }
                if (movementReason.selectedOptions[0]?.disabled) {
                    movementReason.selectedIndex = [...movementReason.options].findIndex(option => !option.disabled);
                }
            };
            movementType.addEventListener('change', syncReasons);
            syncReasons();
        }
    </script>
</body>

</html>
