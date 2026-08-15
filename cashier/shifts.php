<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_role(['admin','super_admin','cashier']);

$service = new CashierShiftService($pdo);
$isCashier = current_role() === 'cashier';
$cashiers = [];
if (!$isCashier) {
    $cashiers = $pdo->query("SELECT u.user_id,u.full_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='cashier' AND u.status='active' ORDER BY u.full_name")->fetchAll(PDO::FETCH_ASSOC);
}
$targetCashierId = $isCashier ? (int)$_SESSION['user_id'] : (int)($_GET['cashier_id'] ?? ($cashiers[0]['user_id'] ?? 0));
if (!$isCashier && $targetCashierId > 0 && !in_array($targetCashierId, array_map(static fn(array $c): int => (int)$c['user_id'], $cashiers), true)) {
    $targetCashierId = (int)($cashiers[0]['user_id'] ?? 0);
}
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'open') {
            $shiftId = $service->openShift($targetCashierId, (float)($_POST['opening_cash'] ?? 0));
            log_activity($pdo, (int)$_SESSION['user_id'], 'Opened cashier shift', 'Cashier Shifts', $shiftId);
            $message = "Shift #{$shiftId} opened successfully.";
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
        $error = $e->getMessage();
    }
}

$openShift = $targetCashierId > 0 ? $service->getOpenShift($targetCashierId) : null;
$summary = $openShift ? $service->calculateShift((int)$openShift['shift_id']) : null;
$movements = $openShift ? $service->drawerMovements((int)$openShift['shift_id']) : [];
$recent = $service->recentShifts($isCashier ? $targetCashierId : null);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cashier Shifts</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"><style>.shift-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}.metric{font-size:1.35rem;font-weight:700}.positive{color:#15803d}.negative{color:#b91c1c}</style></head>
<body><div class="app-shell"><?php include __DIR__ . '/../modules/sidebar.php'; ?><main class="main-content"><div class="topbar"><div><h1>Cashier Shifts</h1><p class="page-subtitle">Open the register, record pay-ins or pay-outs, and reconcile cash at closing.</p></div><?php if($openShift): ?><a class="btn" href="<?= htmlspecialchars(app_url('cashier/pos.php')) ?>">Go to POS</a><?php endif; ?></div>
<?php if($message): ?><div class="message success"><?= htmlspecialchars($message) ?></div><?php endif; ?><?php if($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if(!$isCashier): ?><section class="dashboard-section"><form method="get"><label>View cashier</label><select name="cashier_id" onchange="this.form.submit()"><?php foreach($cashiers as $c): ?><option value="<?= (int)$c['user_id'] ?>" <?= $targetCashierId===(int)$c['user_id']?'selected':'' ?>><?= htmlspecialchars($c['full_name']) ?></option><?php endforeach; ?></select></form></section><?php endif; ?>
<div class="shift-grid">
<section class="dashboard-section"><h3><?= $openShift ? 'Open shift' : 'Open a shift' ?></h3><?php if(!$isCashier && $targetCashierId <= 0): ?><p>No active cashier account is available. Create or activate a cashier first.</p><?php elseif(!$openShift): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>"><input type="hidden" name="action" value="open"><label>Opening cash</label><input type="number" name="opening_cash" min="0" step="0.01" value="0" required><button class="btn" type="submit">Open shift</button></form><?php else: ?><p><strong>Shift #<?= (int)$openShift['shift_id'] ?></strong><br>Opened <?= htmlspecialchars($openShift['opened_at']) ?></p><div class="card-grid"><div class="stat-card"><div class="value">₱<?= number_format((float)$summary['opening_cash'],2) ?></div><div class="label">Opening cash</div></div><div class="stat-card"><div class="value">₱<?= number_format((float)$summary['cash_sales'],2) ?></div><div class="label">Cash sales</div></div><div class="stat-card"><div class="value">₱<?= number_format((float)$summary['calculated_expected_cash'],2) ?></div><div class="label">Expected drawer</div></div><div class="stat-card"><div class="value"><?= (int)$summary['sale_count'] ?></div><div class="label">Transactions</div></div></div><?php endif; ?></section>
<?php if($openShift): ?><section class="dashboard-section"><h3>Drawer movement</h3><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>"><input type="hidden" name="action" value="movement"><div class="form-row"><div><label>Type</label><select name="movement_type"><option value="pay_in">Pay in</option><option value="pay_out">Pay out</option></select></div><div><label>Amount</label><input type="number" name="amount" min="0.01" step="0.01" required></div></div><label>Reason</label><input type="text" name="reason" maxlength="255" required><button class="btn" type="submit">Record movement</button></form></section>
<section class="dashboard-section"><h3>Close and reconcile</h3><form method="post" data-confirm="Close and reconcile this cashier shift using the counted cash amount?" data-confirm-title="Close cashier shift" data-confirm-button="Close shift"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>"><input type="hidden" name="action" value="close"><label>Actual cash counted</label><input type="number" name="actual_cash" min="0" step="0.01" required><label>Closing notes</label><textarea name="closing_notes"></textarea><button class="btn" type="submit">Close shift</button></form></section><?php endif; ?>
</div>
<?php if($openShift && $movements): ?><section class="dashboard-section"><h3>Current shift movements</h3><div class="table-wrap"><table><tr><th>Time</th><th>Type</th><th>Amount</th><th>Reason</th></tr><?php foreach($movements as $m): ?><tr><td><?= htmlspecialchars($m['created_at']) ?></td><td><?= htmlspecialchars(str_replace('_',' ',ucfirst($m['movement_type']))) ?></td><td>₱<?= number_format((float)$m['amount'],2) ?></td><td><?= htmlspecialchars($m['reason']) ?></td></tr><?php endforeach; ?></table></div></section><?php endif; ?>
<section class="dashboard-section"><h3>Recent shifts</h3><div class="table-wrap"><table><tr><th>Cashier</th><th>Opened</th><th>Closed</th><th>Status</th><th>Expected</th><th>Actual</th><th>Variance</th></tr><?php foreach($recent as $r): ?><tr><td><?= htmlspecialchars($r['full_name']) ?></td><td><?= htmlspecialchars($r['opened_at']) ?></td><td><?= htmlspecialchars($r['closed_at'] ?? '-') ?></td><td><?= htmlspecialchars($r['status']) ?></td><td><?= $r['expected_cash']!==null?'₱'.number_format((float)$r['expected_cash'],2):'-' ?></td><td><?= $r['actual_cash']!==null?'₱'.number_format((float)$r['actual_cash'],2):'-' ?></td><td class="<?= (float)($r['cash_variance']??0)>=0?'positive':'negative' ?>"><?= $r['cash_variance']!==null?'₱'.number_format((float)$r['cash_variance'],2):'-' ?></td></tr><?php endforeach; ?></table></div></section>
</main></div></body></html>
