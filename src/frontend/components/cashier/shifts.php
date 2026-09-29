<?php
// cashier/shifts.php — Cashier Shift opening and drawer reconciliation (#88).
//
// Opening binds the signed-in Cashier, in the Cashier workspace, to one
// available Register and a confirmed opening float. A shift is never shared or
// opened on somebody's behalf: the Cashier who sells owns the drawer. The
// Administrator and Super Administrator workspaces keep oversight of shifts but
// never open one.
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
    $cashiers = $pdo->query("SELECT u.user_id,u.full_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='cashier' AND u.status='active' ORDER BY u.full_name")->fetchAll(PDO::FETCH_ASSOC);
}
$targetCashierId = $isCashier ? $actorId : (int)($_GET['cashier_id'] ?? ($cashiers[0]['user_id'] ?? 0));
if (!$isCashier && $targetCashierId > 0 && !in_array($targetCashierId, array_map(static fn(array $c): int => (int)$c['user_id'], $cashiers), true)) {
    $targetCashierId = (int)($cashiers[0]['user_id'] ?? 0);
}
$message = '';
$error = '';
// Ticket #90: a locked Register pauses the drawer, not just the sale screen.
// The refusal lives in CashierShiftService so it cannot be bypassed by posting
// straight to this page; the notice here is only there to explain the pause.
$ownRegisterLocked = $isCashier && $service->isRegisterLocked($actorId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (current_role() === 'super_admin') {
        require_capability(\App\Authorization\RoleCapabilityPolicy::STORE_OPERATIONS);
    }
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
            $movementId = $service->addDrawerMovement($targetCashierId, (string)($_POST['movement_type'] ?? ''), (float)($_POST['amount'] ?? 0), (string)($_POST['reason'] ?? ''), (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Recorded cash drawer movement', 'Cashier Shifts', $movementId);
            $message = 'Cash drawer movement recorded.';
        } elseif ($action === 'close') {
            $summary = $service->closeShift($targetCashierId, (float)($_POST['actual_cash'] ?? 0), (string)($_POST['closing_notes'] ?? ''), $isCashier ? null : (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Closed cashier shift', 'Cashier Shifts', (int)$summary['shift_id'], null, $summary);
            $message = 'Shift closed. Cash variance: ₱' . number_format((float)$summary['cash_variance'], 2) . '.';
        }
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The shift change could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.');
    }
}

$openShift = $targetCashierId > 0 ? $service->getOpenShift($targetCashierId) : null;
$summary = $openShift ? $service->calculateShift((int)$openShift['shift_id']) : null;
$movements = $openShift ? $service->drawerMovements((int)$openShift['shift_id']) : [];
// Ticket #91: the shift cannot close while any of these is parked on it, so the
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
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier-pages.css')) ?>">
</head>

<body>
    <div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
            <div class="topbar">
                <div>
                    <h1>Cashier Shifts</h1>
                    <p class="page-subtitle">Open the register, record pay-ins or pay-outs, and reconcile cash at closing.</p>
                </div><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back</a>
            </div>
            <?php if ($message): ?><div class="message success"><?= htmlspecialchars($message) ?></div><?php endif; ?><?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($ownRegisterLocked): ?><div class="message error"><?= htmlspecialchars(CashierShiftService::LOCKED_MESSAGE) ?> <a href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>">Unlock it at the point of sale</a> to carry on with this same shift.</div><?php endif; ?>
            <?php if (!$isCashier): ?><section class="dashboard-section">
                    <form method="get"><label>View cashier</label><select name="cashier_id" onchange="this.form.submit()"><?php foreach ($cashiers as $c): ?><option value="<?= (int)$c['user_id'] ?>" <?= $targetCashierId === (int)$c['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['full_name']) ?></option><?php endforeach; ?></select></form>
                </section><?php endif; ?>
            <div class="shift-grid">
                <section class="dashboard-section">
                    <h3><?= $openShift ? 'Open shift' : 'Open a shift' ?></h3><?php if (!$isCashier): ?><p>A Cashier Shift is opened and owned by the Cashier who sells. Ask the cashier to open their own shift in the Cashier workspace; you can still review and close it here.</p><?php elseif (!$openShift && !$availableRegisters): ?><p>No Register is free right now. Every Register is either disabled or already on an open shift. Tell your Administrator.</p><?php elseif (!$openShift): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>"><input type="hidden" name="action" value="open"><div class="form-row">
                            <div><label for="open-register">Register</label><select id="open-register" name="register_id" required><?php foreach ($availableRegisters as $register): ?><option value="<?= (int)$register['register_id'] ?>"><?= htmlspecialchars($register['name']) ?></option><?php endforeach; ?></select></div>
                            <div><label for="opening-float">Opening float</label><input id="opening-float" type="number" name="opening_float" min="0" step="0.01" value="0" required></div>
                        </div><button class="btn" type="submit">Open shift</button></form><?php else: ?><p><strong>Shift #<?= (int)$openShift['shift_id'] ?></strong><br>Register <?= htmlspecialchars($openShift['register_name'] ?? 'Unassigned') ?><br>Opened <?= htmlspecialchars($openShift['opened_at']) ?></p>
                        <div class="card-grid">
                            <div class="stat-card">
                                <div class="value">₱<?= number_format((float)$summary['opening_cash'], 2) ?></div>
                                <div class="label">Opening float</div>
                            </div>
                            <div class="stat-card">
                                <div class="value">₱<?= number_format((float)$summary['cash_sales'], 2) ?></div>
                                <div class="label">Cash sales</div>
                            </div>
                            <div class="stat-card">
                                <div class="value">₱<?= number_format((float)$summary['calculated_expected_cash'], 2) ?></div>
                                <div class="label">Expected drawer</div>
                            </div>
                            <div class="stat-card">
                                <div class="value"><?= (int)$summary['sale_count'] ?></div>
                                <div class="label">Transactions</div>
                            </div>
                        </div><?php endif; ?>
                </section>
                <?php if ($openShift): ?><section class="dashboard-section">
                        <h3>Drawer movement</h3>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>"><input type="hidden" name="action" value="movement">
                            <div class="form-row">
                                <div><label>Type</label><select name="movement_type">
                                        <option value="pay_in">Pay in</option>
                                        <option value="pay_out">Pay out</option>
                                    </select></div>
                                <div><label>Amount</label><input type="number" name="amount" min="0.01" step="0.01" required></div>
                            </div><label>Reason</label><input type="text" name="reason" maxlength="255" required><button class="btn" type="submit">Record movement</button>
                        </form>
                    </section>
                    <section class="dashboard-section">
                        <h3>Close and reconcile</h3>
                        <?php if ($unresolvedHeldSales): ?><div class="message error">
                            This shift still has <?= count($unresolvedHeldSales) ?> held sale<?= count($unresolvedHeldSales) === 1 ? '' : 's' ?>. Complete or discard <?= count($unresolvedHeldSales) === 1 ? 'it' : 'them' ?> at the point of sale before closing.
                            <ul><?php foreach ($unresolvedHeldSales as $parked): ?>
                                    <li><?= htmlspecialchars($parked['reference_no']) ?> &middot; <?= (int)$parked['item_count'] ?> item(s) &middot; &#8369;<?= number_format((float)$parked['total_amount'], 2) ?> &middot; <?= $parked['status'] === 'resumed' ? 'resumed' : 'held' ?> since <?= htmlspecialchars((string)$parked['created_at']) ?></li>
                                <?php endforeach; ?></ul>
                        </div><?php endif; ?>
                        <form method="POST" data-confirm="Close and reconcile this cashier shift using the counted cash amount?" data-confirm-title="Close cashier shift" data-confirm-button="Close shift"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>"><input type="hidden" name="action" value="close"><label>Actual cash counted</label><input type="number" name="actual_cash" min="0" step="0.01" required><label>Closing notes</label><textarea name="closing_notes"></textarea><button class="btn" type="submit" <?= $unresolvedHeldSales ? 'disabled' : '' ?>>Close shift</button></form>
                    </section><?php endif; ?>
            </div>
            <?php if ($openShift && $movements): ?><section class="dashboard-section">
                    <h3>Current shift movements</h3>
                    <div class="table-wrap">
                        <table>
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Reason</th>
                            </tr><?php foreach ($movements as $m): ?><tr>
                                    <td><?= htmlspecialchars(format_display_datetime($m['created_at'])) ?></td>
                                    <td><?= htmlspecialchars(str_replace('_', ' ', ucfirst($m['movement_type']))) ?></td>
                                    <td>₱<?= number_format((float)$m['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($m['reason']) ?></td>
                                </tr><?php endforeach; ?>
                        </table>
                    </div>
                </section><?php endif; ?>
            <section class="dashboard-section">
                <h3>Recent shifts</h3>
                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Cashier</th>
                            <th>Register</th>
                            <th>Opened</th>
                            <th>Closed</th>
                            <th>Status</th>
                            <th>Expected</th>
                            <th>Actual</th>
                            <th>Variance</th>
                        </tr><?php foreach ($recent as $r): ?><tr>
                                <td><?= htmlspecialchars($r['full_name']) ?></td>
                                <td><?= htmlspecialchars($r['register_name'] ?? 'Unassigned') ?></td>
                                <td><?= htmlspecialchars($r['opened_at']) ?></td>
                                <td><?= htmlspecialchars($r['closed_at'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['status']) ?></td>
                                <td><?= $r['expected_cash'] !== null ? '₱' . number_format((float)$r['expected_cash'], 2) : '-' ?></td>
                                <td><?= $r['actual_cash'] !== null ? '₱' . number_format((float)$r['actual_cash'], 2) : '-' ?></td>
                                <td class="<?= (float)($r['cash_variance'] ?? 0) >= 0 ? 'positive' : 'negative' ?>"><?= $r['cash_variance'] !== null ? '₱' . number_format((float)$r['cash_variance'], 2) : '-' ?></td>
                            </tr><?php endforeach; ?>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>

</html>