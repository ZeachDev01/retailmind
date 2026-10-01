<?php
// invoice/reversals.php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../app/Services/SaleReversalService.php';
require_role(['admin', 'super_admin', 'inventory_manager', 'cashier']);

$service = new SaleReversalService($pdo);
$sale_id = (int)($_GET['sale_id'] ?? $_POST['sale_id'] ?? 0);
$can_approve = in_array(current_role(), ['admin', 'super_admin'], true) && has_capability(App\Authorization\RoleCapabilityPolicy::MANAGE_SALE_REVERSALS);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = $_POST['action'] ?? '';

    try {
        if ($postAction === 'request') {
            throw new RuntimeException('New Legacy Reversals are disabled. Use Cash Refunds; exchanges require a refund plus a new sale.');
        }
        require_capability(App\Authorization\RoleCapabilityPolicy::MANAGE_SALE_REVERSALS);
        $evidence = [
            'no_prior_effects' => isset($_POST['no_prior_effects']),
            'restockable' => isset($_POST['restockable']),
            'refund_ineligibility_acknowledged' => isset($_POST['refund_ineligibility_acknowledged']),
            'paying_shift_id' => (int)($_POST['paying_shift_id'] ?? 0),
            'single_payout_confirmed' => isset($_POST['single_payout_confirmed']),
            'external_completed' => isset($_POST['external_completed']),
            'payment_reference' => trim($_POST['payment_reference'] ?? ''),
        ];
        if ($postAction === 'approve') {
            $service->approveReversal((int)$_POST['reversal_id'], (int)$_SESSION['user_id'], $_POST['decision_reason'] ?? '', $evidence);
            $message = 'Legacy Reversal approved once. Follow the committed record; retries do not require another payout.';
        } elseif ($postAction === 'reject') {
            $service->rejectReversal((int)$_POST['reversal_id'], (int)$_SESSION['user_id'], $_POST['decision_reason'] ?? '', $evidence);
            $message = 'Legacy Reversal rejected without financial effects. Use a separate Cash Refund only after every pending request is resolved and no approved record exists.';
        } else {
            throw new RuntimeException('Choose an explicit Administrator decision.');
        }
    } catch (RuntimeException | DomainException $e) {
        $error = App\Support\OperatorAlert::message($e, 'The Legacy Reversal could not be decided. Ask your Administrator to investigate.');
    } catch (PDOException $e) {
        $error = 'The reversal could not be saved because of a database error.';
    }
}

$sale = $sale_id > 0 ? $service->getSaleWithItems($sale_id) : null;
if ($sale && !$can_approve && (int)$sale['cashier_id'] !== (int)$_SESSION['user_id']) {
    $error = 'You can only view reversals for your own sales.';
    $sale = null;
}

$reversals = $sale_id > 0 && $sale ? $service->getReversals($sale_id) : ($can_approve ? $service->getReversals() : []);
$pendingReversals = array_values(array_filter($reversals, fn($row) => $row['status'] === 'pending'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Legacy Reversals</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
<style>
    .panel { background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 1.1rem; margin-bottom: 1rem; }
    .grid-two { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr); gap: 1rem; align-items: start; }
    .status-pill { display: inline-flex; padding: 0.2rem 0.55rem; border-radius: 999px; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-approved { background: #dcfce7; color: #166534; }
    .status-rejected { background: #fee2e2; color: #991b1b; }
    textarea { width: 100%; min-height: 88px; padding: 0.75rem 0.9rem; border: 1px solid var(--border); border-radius: 10px; font: inherit; }
    .actions-row { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; }
    .muted { color: var(--muted); font-size: 0.9rem; }
    @media (max-width: 980px) { .grid-two { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../../../../frontend/components/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <h1>Legacy Reversals</h1>
                <p class="page-subtitle">Historical records remain readable. New corrections use Cash Refunds.</p>
            </div>
            <span class="badge-role"><?= htmlspecialchars(ucfirst((string)current_role())) ?></span>
        </div>

        <?php if ($message): ?><div class="alert tag-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert tag-warning"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="panel">
            <form method="GET" class="actions-row">
                <div class="form-group" style="margin:0;min-width:240px;">
                    <label for="sale_id">Receipt / Sale ID</label>
                    <input type="number" min="1" name="sale_id" id="sale_id" value="<?= $sale_id ?: '' ?>" placeholder="Enter receipt number">
                </div>
                <button class="btn" type="submit">Load Sale</button>
                <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/invoice/sales.php')) ?>">Back to Receipts</a>
            </form>
        </div>

        <div class="grid-two">
            <div>
                <?php if ($sale): ?>
                    <div class="panel">
                        <div class="section-header">
                            <div>
                                <h3>Sale #<?= (int)$sale['sale_id'] ?></h3>
                                <p class="section-description">
                                    <?= htmlspecialchars($sale['sale_date']) ?> by <?= htmlspecialchars($sale['cashier_name']) ?>,
                                    <?= htmlspecialchars(strtoupper($sale['payment_method'])) ?>,
                                    &#8369;<?= number_format((float)$sale['total_amount'], 2) ?>
                                </p>
                            </div>
                            <?php if (!empty($sale['approved_full_reversal'])): ?>
                                <span class="status-pill status-approved">Cancelled</span>
                            <?php endif; ?>
                        </div>

                        <p>New corrections use full or partial Cash Refunds. Exchanges require a refund plus a separately paid new sale.</p>
                        <a class="btn" href="<?= htmlspecialchars(app_url('components/cashier/refunds.php')) ?>">Cash Refunds</a>
                    </div>
                <?php elseif ($sale_id > 0): ?>
                    <div class="panel">Sale #<?= $sale_id ?> was not found.</div>
                <?php endif; ?>
            </div>

            <div>
                <?php if ($can_approve && $pendingReversals): ?>
                    <div class="panel">
                        <h3>Pending Administrator review</h3>
                        <p class="section-description">Review financial and inventory evidence before an explicit decision.</p>
                        <?php foreach ($pendingReversals as $row): ?>
                            <div style="border-top:1px solid var(--border);padding-top:0.9rem;margin-top:0.9rem;">
                                <strong>#<?= (int)$row['reversal_id'] ?> <?= htmlspecialchars(strtoupper($row['reversal_type'])) ?></strong>
                                <p class="muted">Sale #<?= (int)$row['sale_id'] ?> requested by <?= htmlspecialchars($row['requested_by_name'] ?? 'Unknown') ?></p>
                                <p><?= htmlspecialchars($row['reason']) ?></p>
                                <div class="actions-row" style="margin-top:0.75rem;">
                                    <?php $preview = $service->reviewBalances((int)$row['reversal_id']); ?>
                                    <?php if (isset($preview['limitation'])): ?>
                                        <p><?= htmlspecialchars($preview['limitation']) ?></p>
                                    <?php else: ?>
                                        <p>Preview only; checked again at decision. Nominal paid balance: &#8369;<?= number_format($preview['paid_before'],2) ?> to &#8369;<?= number_format($preview['paid_after'],2) ?>.
                                        Original shift #<?= (int)$preview['shift_id'] ?>: <?= htmlspecialchars($preview['shift_status'] ?? 'unassigned') ?><?= $preview['locked_at'] ? ', locked' : '' ?>.
                                        <?php if (isset($preview['cash_before'])): ?>Expected drawer cash: &#8369;<?= number_format($preview['cash_before'],2) ?> to &#8369;<?= number_format($preview['cash_after'],2) ?>.<?php endif; ?></p>
                                        <table><tr><th>Item</th><th>Stock before / after approval</th><th>Legacy quantity before / after</th></tr>
                                        <?php foreach ($preview['items'] as $item): ?>
                                            <tr><td><?= htmlspecialchars($item['product_name'] ?? 'Missing product') ?></td><td><?= (int)$item['quantity_on_hand'] ?> / <?= (int)$item['quantity_on_hand']+(int)$item['quantity'] ?></td><td><?= (int)$item['sold_quantity']-(int)$item['approved_quantity'] ?> / <?= (int)$item['sold_quantity']-(int)$item['approved_quantity']-(int)$item['quantity'] ?></td></tr>
                                        <?php endforeach; ?></table>
                                        <p>Rejection keeps all cash, stock, and consumed balances unchanged. Approval is refused for missing batches, damaged returns, inconsistent values, exchange credit, closed original Fiscal Periods or a different/closed paying shift. Zero-money returns require a reason and still block future Cash Refunds.</p>
                                    <?php endif; ?>
                                    <p>Settlement <?= htmlspecialchars($row['settlement_method']) ?>  -  amount &#8369;<?= number_format((float)$row['refund_amount'],2) ?>. Approval restores verified Restockable units, consumes remaining quantity/value, and permanently blocks Cash Refunds for this sale. Cash reduces the original open paying shift once; saved closed-shift reconciliation and approval-day report attribution remain historical. Unsupported or externally altered requests must stay pending for investigation.</p>
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="reversal_id" value="<?= (int)$row['reversal_id'] ?>">
                                        <label>Decision reason <input name="decision_reason" maxlength="255" required></label>
                                        <label><input type="checkbox" name="no_prior_effects" required> Verified no prior payout or stock restoration outside this pending record</label>
                                        <label><input type="checkbox" name="restockable"> All returned units verified Restockable</label>
                                        <label><input type="checkbox" name="refund_ineligibility_acknowledged"> Acknowledge whole-sale Cash Refund ineligibility after approval</label>
                                        <label>Original physical paying shift ID <input type="number" name="paying_shift_id" min="1"></label>
                                        <label><input type="checkbox" name="single_payout_confirmed"> Confirm exactly one cash payout from this drawer</label>
                                        <label><input type="checkbox" name="external_completed"> External original-method settlement verified</label>
                                        <label>External Payment Reference <input name="payment_reference" maxlength="100"></label>
                                        <button class="btn" name="action" value="approve">Approve verified request</button>
                                        <button class="btn btn-danger" name="action" value="reject">Reject without effects</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="panel">
                    <h3><?= $sale_id > 0 ? 'Legacy Reversal History' : 'Recent Legacy Reversals' ?></h3>
                    <div style="overflow-x:auto;margin-top:0.75rem;">
                        <table>
                            <tr>
                                <th>ID</th>
                                <th>Sale</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Requested</th>
                            </tr>
                            <?php foreach ($reversals as $row): ?>
                                <tr>
                                    <td>#<?= (int)$row['reversal_id'] ?></td>
                                    <td><a href="<?= htmlspecialchars(app_url('components/invoice/legacy_reversals.php?sale_id=' . $row['sale_id'])) ?>">#<?= (int)$row['sale_id'] ?></a></td>
                                    <td><?= htmlspecialchars(ucfirst($row['reversal_type'])) ?></td>
                                    <td><span class="status-pill status-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$reversals): ?>
                                <tr><td colspan="5" style="text-align:center;color:var(--muted);">No Legacy Reversal records. No pending transition is required.</td></tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
