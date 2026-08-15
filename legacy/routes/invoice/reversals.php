<?php
// invoice/reversals.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/SaleReversalService.php';
require_role(['admin', 'super_admin', 'inventory_manager', 'cashier']);

$service = new SaleReversalService($pdo);
$sale_id = (int)($_GET['sale_id'] ?? $_POST['sale_id'] ?? 0);
$can_approve = in_array(current_role(), ['admin', 'super_admin', 'inventory_manager'], true);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = $_POST['action'] ?? '';

    try {
        if ($postAction === 'request') {
            $requestedSale = $service->getSaleWithItems((int)$_POST['sale_id']);
            if (!$requestedSale) {
                throw new RuntimeException('Sale not found.');
            }
            if (!$can_approve && (int)$requestedSale['cashier_id'] !== (int)$_SESSION['user_id']) {
                throw new RuntimeException('You can only request reversals for your own sales.');
            }

            $reversalId = $service->requestReversal(
                (int)$_POST['sale_id'],
                $_POST['reversal_type'] ?? '',
                $_POST['reason'] ?? '',
                $_POST['items'] ?? [],
                (int)$_SESSION['user_id'],
                $_POST['settlement_method'] ?? 'none',
                (float)($_POST['refund_amount'] ?? 0),
                $_POST['exchange_details'] ?? ''
            );
            $message = "Reversal request #{$reversalId} was submitted for supervisor approval.";
            $sale_id = (int)$_POST['sale_id'];
        } elseif ($postAction === 'approve' && $can_approve) {
            $service->approveReversal((int)$_POST['reversal_id'], (int)$_SESSION['user_id']);
            $message = 'Reversal approved and returned stock was restored.';
        } elseif ($postAction === 'reject' && $can_approve) {
            $service->rejectReversal((int)$_POST['reversal_id'], (int)$_SESSION['user_id'], $_POST['rejection_reason'] ?? '');
            $message = 'Reversal request rejected.';
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
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
<title>Sales Reversals</title>
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
    <?php include __DIR__ . '/../../../modules/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <h1>Sales Reversals</h1>
                <p class="page-subtitle">Corrections create separate approved records; completed sales stay unchanged.</p>
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
                <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('invoice/receipt.php')) ?>">Back to Receipts</a>
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

                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="request">
                            <input type="hidden" name="sale_id" value="<?= (int)$sale['sale_id'] ?>">

                            <div class="form-group">
                                <label for="reversal_type">Correction Type</label>
                                <select name="reversal_type" id="reversal_type">
                                    <option value="return">Product Return</option>
                                    <option value="refund">Refund</option>
                                    <option value="exchange">Exchange</option>
                                    <option value="cancel">Cancel Entire Transaction</option>
                                </select>
                            </div>

                            <div style="overflow-x:auto;margin-bottom:1rem;">
                                <table>
                                    <tr>
                                        <th>Product</th>
                                        <th>Sold</th>
                                        <th>Already Reversed</th>
                                        <th>Return Qty</th>
                                        <th>Unit Price</th>
                                    </tr>
                                    <?php foreach ($sale['items'] as $item): ?>
                                        <?php $remaining = (int)$item['quantity'] - (int)$item['reversed_qty']; ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['sku'] . ' - ' . $item['product_name']) ?></td>
                                            <td><?= (int)$item['quantity'] ?></td>
                                            <td><?= (int)$item['reversed_qty'] ?></td>
                                            <td>
                                                <input type="number"
                                                       name="items[<?= (int)$item['sale_item_id'] ?>]"
                                                       min="0"
                                                       max="<?= max(0, $remaining) ?>"
                                                       value="0"
                                                       style="width:5.5rem;padding:0.35rem;border:1px solid var(--border);border-radius:8px;"
                                                       <?= $remaining <= 0 ? 'disabled' : '' ?>>
                                            </td>
                                            <td>&#8369;<?= number_format((float)$item['unit_price'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>

                            <div class="form-group">
                                <label for="settlement_method">Refund / Exchange Handling</label>
                                <select name="settlement_method" id="settlement_method">
                                    <option value="none">No money movement</option>
                                    <option value="cash">Cash Refund</option>
                                    <option value="card">Card Refund</option>
                                    <option value="ewallet">E-Wallet Refund</option>
                                    <option value="exchange">Exchange / Store Credit</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="refund_amount">Refund Amount</label>
                                <input type="number" step="0.01" min="0" name="refund_amount" id="refund_amount" value="0.00">
                            </div>

                            <div class="form-group">
                                <label for="exchange_details">Exchange Details</label>
                                <textarea name="exchange_details" id="exchange_details" placeholder="Replacement item, store credit reference, or exchange notes"></textarea>
                            </div>

                            <div class="form-group">
                                <label for="reason">Reason</label>
                                <textarea name="reason" id="reason" required placeholder="Reason required for audit and supervisor approval"></textarea>
                            </div>

                            <button class="btn" type="submit">Submit for Supervisor Approval</button>
                        </form>
                    </div>
                <?php elseif ($sale_id > 0): ?>
                    <div class="panel">Sale #<?= $sale_id ?> was not found.</div>
                <?php endif; ?>
            </div>

            <div>
                <?php if ($can_approve && $pendingReversals): ?>
                    <div class="panel">
                        <h3>Pending Supervisor Approval</h3>
                        <p class="section-description">Approving restores returned stock and records the audit entry.</p>
                        <?php foreach ($pendingReversals as $row): ?>
                            <div style="border-top:1px solid var(--border);padding-top:0.9rem;margin-top:0.9rem;">
                                <strong>#<?= (int)$row['reversal_id'] ?> <?= htmlspecialchars(strtoupper($row['reversal_type'])) ?></strong>
                                <p class="muted">Sale #<?= (int)$row['sale_id'] ?> requested by <?= htmlspecialchars($row['requested_by_name'] ?? 'Unknown') ?></p>
                                <p><?= htmlspecialchars($row['reason']) ?></p>
                                <div class="actions-row" style="margin-top:0.75rem;">
                                    <form method="POST" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="reversal_id" value="<?= (int)$row['reversal_id'] ?>">
                                        <button class="btn btn-small" type="submit">Approve</button>
                                    </form>
                                    <form method="POST" class="actions-row" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="reversal_id" value="<?= (int)$row['reversal_id'] ?>">
                                        <input type="text" name="rejection_reason" placeholder="Rejection reason" required style="padding:0.45rem;border:1px solid var(--border);border-radius:8px;">
                                        <button class="btn btn-small btn-danger" type="submit">Reject</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="panel">
                    <h3><?= $sale_id > 0 ? 'Sale Reversal History' : 'Recent Reversals' ?></h3>
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
                                    <td><a href="<?= htmlspecialchars(app_url('invoice/reversals.php?sale_id=' . $row['sale_id'])) ?>">#<?= (int)$row['sale_id'] ?></a></td>
                                    <td><?= htmlspecialchars(ucfirst($row['reversal_type'])) ?></td>
                                    <td><span class="status-pill status-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$reversals): ?>
                                <tr><td colspan="5" style="text-align:center;color:var(--muted);">No reversal records yet.</td></tr>
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
