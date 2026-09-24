<?php
// components/inventory_management/inventory_counts.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/InventoryCountService.php';
require_once __DIR__ . '/../../../backend/app/Services/StockIssueService.php';
require_role(['admin', 'inventory_manager']);

$countService = new InventoryCountService($pdo);
$stockIssueService = new StockIssueService($pdo);
$message = '';
$error = '';

// Correction entry point: ?correct=<adjustment_id> pre-fills a separate
// inventory-count transaction linked to an approved stock-issue report. The
// original report is never reopened or rewritten (ticket #31).
$correctReport = null;
$correctId = (int)($_GET['correct'] ?? 0);
if ($correctId > 0) {
    try {
        $correctReport = $stockIssueService->getReport($correctId);
        if ($correctReport === null) {
            throw new RuntimeException('Stock issue report not found.');
        }
        if ($correctReport['status'] !== 'approved') {
            throw new RuntimeException('Only approved stock issue reports can be corrected through a separate inventory count.');
        }
    } catch (Throwable $e) {
        $correctReport = null;
        $error = $e->getMessage();
    }
}

// The linked-correction pre-fill is a mutation affordance, so it is shown only
// to viewers holding MUTATE_INVENTORY (Inventory Managers). Administrators get
// a read-only notice instead: their oversight access never offers a submit path.
$correctionFormActive = $correctReport !== null
    && has_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
    csrf_verify();
    $action = $_POST['action'] ?? 'record';

    try {
        if ($action === 'record') {
            $countId = $countService->recordCount($_POST, (int)$_SESSION['user_id']);
            $message = "Inventory count #{$countId} recorded and sent for approval.";
            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                'Inventory count recorded',
                'Inventory Counts',
                $countId,
                null,
                [
                    'product_id' => (int)($_POST['product_id'] ?? 0),
                    'physical_quantity' => (int)($_POST['physical_quantity'] ?? 0),
                    'status' => 'pending',
                ]
            );
        } elseif ($action === 'approve') {
            $countId = (int)($_POST['count_id'] ?? 0);
            $countService->approveCount($countId, (int)$_SESSION['user_id']);
            $message = "Inventory count #{$countId} approved and stock reconciled.";
        } elseif ($action === 'reject') {
            $countId = (int)($_POST['count_id'] ?? 0);
            $countService->rejectCount($countId, (int)$_SESSION['user_id']);
            $message = "Inventory count #{$countId} rejected.";
            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                'Inventory count rejected',
                'Inventory Counts',
                $countId,
                ['status' => 'pending'],
                ['status' => 'rejected']
            );
        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$products = $countService->getActiveProducts();
$counts = $countService->getRecentCounts();
$pendingTotal = $countService->getPendingCountTotal();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Counts</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css')) ?>">
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div>
                    <h1>Inventory Counts</h1>
                    <p class="page-subtitle">Record physical counts and reconcile approved differences.</p>
                </div>
                <span class="badge-role"><?= (int)$pendingTotal ?> Pending Approval</span>
            </div>

            <?php if ($message): ?>
                <div class="alert tag-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="count-layout">
                <div class="count-panel">
                    <h3>New Physical Count</h3>
                    <p class="section-description">The system quantity is captured when you submit the count.</p>

                    <?php if ($correctionFormActive): ?>
                        <div class="message success">
                            Correcting approved stock issue report #<?= (int)$correctReport['adjustment_id'] ?>
                            (<?= htmlspecialchars($correctReport['product_name']) ?>, <?= htmlspecialchars($correctReport['adjustment_type']) ?>).
                            The original report stays immutable; this count is a separate linked record.
                            <a href="<?= htmlspecialchars(app_url('components/inventory_management/inventory_counts.php')) ?>">Clear correction link</a>
                        </div>
                    <?php elseif ($correctReport !== null): ?>
                        <div class="message">
                            Stock issue report #<?= (int)$correctReport['adjustment_id'] ?> is approved and immutable.
                            Corrections are recorded only through a separate Inventory Manager inventory count; Administrator access to this page is read-only.
                            <a href="<?= htmlspecialchars(app_url('components/administrator/stock_issues.php?detail=' . (int)$correctReport['adjustment_id'])) ?>">Back to oversight</a>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="count-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="record">
                        <?php if ($correctionFormActive): ?>
                            <input type="hidden" name="related_adjustment_id" value="<?= (int)$correctReport['adjustment_id'] ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="product_id">Product</label>
                            <select name="product_id" id="product_id" required>
                                <option value="">Select product</option>
                                <?php foreach ($products as $product): ?>
                                    <option
                                        value="<?= (int)$product['product_id'] ?>"
                                        data-system-qty="<?= (int)$product['quantity_on_hand'] ?>"
                                        <?= $correctionFormActive && (int)$correctReport['product_id'] === (int)$product['product_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($product['sku'] . ' - ' . $product['product_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="physical_quantity">Physically Counted Quantity</label>
                            <input type="number" name="physical_quantity" id="physical_quantity" min="0" required>
                        </div>

                        <div class="quantity-preview" aria-live="polite">
                            <div>
                                <span>System</span>
                                <strong id="systemQty">-</strong>
                            </div>
                            <div>
                                <span>Physical</span>
                                <strong id="physicalQty">-</strong>
                            </div>
                            <div>
                                <span>Difference</span>
                                <strong id="differenceQty">-</strong>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="discrepancy_reason">Reason for Discrepancy</label>
                            <textarea
                                name="discrepancy_reason"
                                id="discrepancy_reason"
                                required
                                placeholder="<?= $correctionFormActive
                                    ? htmlspecialchars('Correction for approved stock issue report #' . (int)$correctReport['adjustment_id'] . ': describe what the recount found.')
                                    : 'Damaged, missing, expired, incorrect receiving, counting correction, or other details.' ?>"></textarea>
                        </div>

                        <button type="submit" class="btn btn-block">Record Count</button>
                    </form>
                </div>

                <div class="count-panel">
                    <div class="section-header">
                        <div>
                            <h3>Recent Counts</h3>
                            <p class="section-description">Approved counts update live inventory and add an adjustment movement.</p>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Product</th>
                                    <th>System</th>
                                    <th>Counted</th>
                                    <th>Difference</th>
                                    <th>Reason</th>
                                    <th>Counted By</th>
                                    <th>Approved By</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($counts): ?>
                                    <?php foreach ($counts as $count): ?>
                                        <?php $difference = (int)$count['difference_qty']; ?>
                                        <tr>
                                            <td><?= htmlspecialchars(format_display_datetime($count['counted_at'])) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($count['sku']) ?></strong>
                                                <br><small class="muted"><?= htmlspecialchars($count['product_name']) ?></small>
                                            </td>
                                            <td><?= (int)$count['system_quantity'] ?></td>
                                            <td><?= (int)$count['physical_quantity'] ?></td>
                                            <td>
                                                <strong class="<?= $difference < 0 ? 'diff-negative' : ($difference > 0 ? 'diff-positive' : '') ?>">
                                                    <?= $difference > 0 ? '+' . $difference : $difference ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($count['discrepancy_reason']) ?>
                                                <?php if (!empty($count['related_adjustment_id'])): ?>
                                                    <br><small class="muted">Correction of stock issue report #<?= (int)$count['related_adjustment_id'] ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($count['counted_by_name']) ?></td>
                                            <td>
                                                <?= htmlspecialchars($count['approved_by_name'] ?? '-') ?>
                                                <?php if (!empty($count['approved_at'])): ?>
                                                    <br><small class="muted"><?= htmlspecialchars(format_display_datetime($count['approved_at'])) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-pill status-<?= htmlspecialchars($count['status']) ?>">
                                                    <?= htmlspecialchars($count['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($count['status'] === 'pending'): ?>
                                                    <div class="count-actions">
                                                        <form method="POST" class="inline-form">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="approve">
                                                            <input type="hidden" name="count_id" value="<?= (int)$count['count_id'] ?>">
                                                            <button type="submit" class="btn btn-small">Approve</button>
                                                        </form>
                                                        <form method="POST" class="inline-form">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="reject">
                                                            <input type="hidden" name="count_id" value="<?= (int)$count['count_id'] ?>">
                                                            <button type="submit" class="btn btn-small btn-secondary">Reject</button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td class="u-empty-cell-padded" colspan="10">No inventory counts recorded yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const productSelect = document.getElementById('product_id');
        const physicalInput = document.getElementById('physical_quantity');
        const systemQty = document.getElementById('systemQty');
        const physicalQty = document.getElementById('physicalQty');
        const differenceQty = document.getElementById('differenceQty');

        function updatePreview() {
            const option = productSelect.options[productSelect.selectedIndex];
            const hasProduct = option && option.dataset.systemQty !== undefined;
            const system = hasProduct ? parseInt(option.dataset.systemQty, 10) : null;
            const physical = physicalInput.value === '' ? null : parseInt(physicalInput.value, 10);

            systemQty.textContent = system === null || Number.isNaN(system) ? '-' : system;
            physicalQty.textContent = physical === null || Number.isNaN(physical) ? '-' : physical;
            differenceQty.classList.remove('diff-negative', 'diff-positive');

            if (system === null || physical === null || Number.isNaN(system) || Number.isNaN(physical)) {
                differenceQty.textContent = '-';
                return;
            }

            const difference = physical - system;
            differenceQty.textContent = difference > 0 ? '+' + difference : difference;
            if (difference < 0) {
                differenceQty.classList.add('diff-negative');
            } else if (difference > 0) {
                differenceQty.classList.add('diff-positive');
            }
        }

        productSelect.addEventListener('change', updatePreview);
        physicalInput.addEventListener('input', updatePreview);
        updatePreview();
    </script>
</body>

</html>