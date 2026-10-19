<?php
// components/inventory_management/supplier_pullouts.php — Record batch-specific Supplier Pullouts (ticket #224)

require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/SupplierPulloutService.php';

require_role(['admin', 'super_admin', 'inventory_manager']);

$currentRole = (string)current_role();
$canMutate = has_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY) && $currentRole === 'inventory_manager';
$pulloutService = new SupplierPulloutService($pdo, null, $currentRole);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_batches') {
    header('Content-Type: application/json; charset=utf-8');
    $productId = (int)($_GET['product_id'] ?? 0);
    echo json_encode($pulloutService->getEligibleBatches($productId));
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canMutate) {
        http_response_code(403);
        die('Access denied: only active Inventory Managers can record supplier pullouts.');
    }

    verify_csrf_token($_POST['csrf_token'] ?? '');

    try {
        $result = $pulloutService->recordPullout($_POST, (int)$_SESSION['user_id']);
        $message = "Supplier pullout #{$result['pullout_id']} recorded. Deducted {$result['quantity']} units from batch #{$result['batch_id']}. Remaining batch quantity: {$result['remaining_batch_qty']}.";
        log_activity(
            $pdo,
            (int)$_SESSION['user_id'],
            'Recorded supplier pullout',
            'Supplier Pullouts',
            $result['pullout_id'],
            null,
            $result
        );
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The supplier pullout could not be saved. Check input details and try again.');
    }
}

$prefillProductId = (int)($_GET['product_id'] ?? 0);
$prefillBatchId = (int)($_GET['batch_id'] ?? 0);
$prefillBatch = null;

if ($prefillBatchId > 0) {
    $prefillBatch = $pulloutService->getBatchForPullout($prefillBatchId);
    if ($prefillBatch && $prefillProductId <= 0) {
        $prefillProductId = (int)$prefillBatch['product_id'];
    }
}

[$scopeSql, $scopeParams] = store_product_scope('p');
$prodStmt = $pdo->prepare(
    "SELECT p.product_id, p.product_name, p.sku, i.quantity_on_hand
     FROM products p
     LEFT JOIN inventory i ON p.product_id = i.product_id
     WHERE p.status = 'active'{$scopeSql}
     ORDER BY p.product_name ASC"
);
$prodStmt->execute($scopeParams);
$products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

$pulloutHistory = $pulloutService->getPullouts(['limit' => 50]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Pullouts - Inventory Control</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/supplier_pullouts.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/supplier_pullouts.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main id="supplier-pullouts" class="main-content">
        <div class="mb-6 flex gap-2 border-0 border-b border-solid border-[var(--border)]" role="tablist" aria-label="Supplier pullouts">
            <button type="button" id="pullout-record-tab" role="tab" aria-controls="pullout-record-panel" aria-selected="true" tabindex="0"
                    class="pullout-tab cursor-pointer border-0 border-b-2 border-solid border-blue-600 bg-transparent px-4 py-3 text-sm font-semibold text-[var(--theme-info-text,#2563eb)] hover:bg-[var(--surface-soft)]">
                Record Pullout
            </button>
            <button type="button" id="pullout-history-tab" role="tab" aria-controls="pullout-history-panel" aria-selected="false" tabindex="-1"
                    class="pullout-tab cursor-pointer border-0 border-b-2 border-solid border-transparent bg-transparent px-4 py-3 text-sm font-semibold text-[var(--muted)] hover:bg-[var(--surface-soft)]">
                Pullout History
            </button>
        </div>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div id="pullout-record-panel" role="tabpanel" aria-labelledby="pullout-record-tab" tabindex="0" class="min-w-0">
        <?php if (!$canMutate): ?>
            <div class="alert alert-info u-mb-3">
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                <strong>Read-Only Oversight:</strong> Only an authorized active Inventory Manager workspace may record stock pullouts. Administrators retain complete oversight over pullout records and movement history.
            </div>
        <?php else: ?>
            <section class="card">
                <header class="card-header">
                    <h2>Record Supplier Pullout</h2>
                </header>
                <div class="card-body">
                    <form method="POST" id="pullout-form" class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">

                        <div class="min-w-0">
                            <label for="product_id"><strong>Product *</strong></label>
                            <select name="product_id" id="product_id" class="form-control w-full min-w-0 rounded-lg border border-solid border-[var(--border)] px-3 py-2 focus:border-blue-600 focus:[box-shadow:var(--focus-ring)]" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= (int)$p['product_id'] ?>" <?= (int)$p['product_id'] === $prefillProductId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['product_name']) ?> (SKU: <?= htmlspecialchars($p['sku']) ?>, On Hand: <?= (int)($p['quantity_on_hand'] ?? 0) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="min-w-0">
                            <label for="batch_id"><strong>Batch *</strong></label>
                            <select name="batch_id" id="batch_id" aria-describedby="batch-help" class="form-control w-full min-w-0 rounded-lg border border-solid border-[var(--border)] px-3 py-2 focus:border-blue-600 focus:[box-shadow:var(--focus-ring)]" required>
                                <option value="">-- Select Batch --</option>
                                <?php if ($prefillBatch): ?>
                                    <option value="<?= (int)$prefillBatch['batch_id'] ?>"
                                            data-remaining="<?= (int)$prefillBatch['remaining_quantity'] ?>"
                                            data-supplier="<?= htmlspecialchars($prefillBatch['supplier'] ?? '') ?>"
                                            selected>
                                        <?= htmlspecialchars($prefillBatch['batch_number'] ?? ('#' . $prefillBatch['batch_id'])) ?>
                                        (Remaining: <?= (int)$prefillBatch['remaining_quantity'] ?>,
                                        Exp: <?= htmlspecialchars($prefillBatch['expiration_date'] ?? 'None') ?>)
                                    </option>
                                <?php endif; ?>
                            </select>
                            <small id="batch-help" class="form-text text-muted">Eligible and expired batches with remaining stock.</small>
                        </div>

                        <div class="min-w-0">
                            <label for="quantity"><strong>Pullout Quantity (Base Units) *</strong></label>
                            <input type="number"
                                   name="quantity"
                                   id="quantity"
                                   class="form-control w-full min-w-0 rounded-lg border border-solid border-[var(--border)] px-3 py-2 focus:border-blue-600 focus:[box-shadow:var(--focus-ring)]"
                                   min="1"
                                   step="1"
                                   max="<?= $prefillBatch ? (int)$prefillBatch['remaining_quantity'] : '' ?>"
                                   placeholder="e.g. 10"
                                   required>
                        </div>

                        <div class="min-w-0">
                            <label for="reason"><strong>Reason *</strong></label>
                            <input type="text"
                                   name="reason"
                                   id="reason"
                                   class="form-control w-full min-w-0 rounded-lg border border-solid border-[var(--border)] px-3 py-2 focus:border-blue-600 focus:[box-shadow:var(--focus-ring)]"
                                   placeholder="e.g. Expired stock, Damaged in transit, Supplier recall"
                                   required>
                        </div>

                        <div class="min-w-0">
                            <label for="supplier"><strong>Supplier (Optional)</strong></label>
                            <input type="text"
                                   name="supplier"
                                   id="supplier"
                                   class="form-control w-full min-w-0 rounded-lg border border-solid border-[var(--border)] px-3 py-2 focus:border-blue-600 focus:[box-shadow:var(--focus-ring)]"
                                   value="<?= htmlspecialchars($prefillBatch['supplier'] ?? '') ?>"
                                   placeholder="Supplier name or code">
                        </div>

                        <div class="min-w-0 md:col-span-2">
                            <label for="notes">Notes (Optional)</label>
                            <textarea name="notes" id="notes" class="form-control w-full min-w-0 rounded-lg border border-solid border-[var(--border)] px-3 py-2 focus:border-blue-600 focus:[box-shadow:var(--focus-ring)]" rows="2" placeholder="Reference numbers, pickup staff details, or replacement expectations..."></textarea>
                        </div>

                        <div class="form-actions md:col-span-2">
                            <button type="submit" class="btn btn-primary" id="btn-submit-pullout">
                                <i class="bi bi-arrow-return-left" aria-hidden="true"></i> Record Supplier Pullout
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        </div>

        <section id="pullout-history-panel" class="card min-w-0" role="tabpanel" aria-labelledby="pullout-history-tab" tabindex="0" hidden>
            <header class="card-header">
                <h2>Supplier Pullout History</h2>
            </header>
            <div class="card-body">
                <div class="table-wrap max-w-full overflow-x-auto" role="region" aria-label="Supplier pullout history table" tabindex="0">
                    <table class="table">
                        <thead class="[&_th]:bg-[var(--surface-soft)]">
                            <tr>
                                <th scope="col">Pullout #</th>
                                <th scope="col">Date &amp; Time</th>
                                <th scope="col">Product</th>
                                <th scope="col">Batch #</th>
                                <th scope="col">Expiry</th>
                                <th scope="col" class="text-right [font-variant-numeric:tabular-nums]">Quantity</th>
                                <th scope="col">Supplier</th>
                                <th scope="col">Reason</th>
                                <th scope="col">Recorded By</th>
                                <th scope="col">Notes</th>
                                <th scope="col">Replacement</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pulloutHistory)): ?>
                                <tr><td class="u-empty-cell" colspan="12">No supplier pullouts recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($pulloutHistory as $row): ?>
                                    <tr class="hover:bg-[var(--surface-soft)]">
                                        <td>#<?= (int)$row['pullout_id'] ?></td>
                                        <td><?= htmlspecialchars($row['pulled_at']) ?></td>
                                        <td><strong><?= htmlspecialchars($row['product_name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($row['sku']) ?></small></td>
                                        <td><?= htmlspecialchars($row['batch_number'] ?? ('#' . $row['batch_id'])) ?></td>
                                        <td><?= htmlspecialchars($row['expiration_date'] ?? 'Not recorded') ?></td>
                                        <td class="text-right [font-variant-numeric:tabular-nums]"><strong class="text-danger">-<?= number_format((int)$row['quantity']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['supplier'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($row['reason']) ?></td>
                                        <td><?= htmlspecialchars($row['pulled_by_name']) ?></td>
                                        <td><?= htmlspecialchars($row['notes'] ?? '—') ?></td>
                                        <td>
                                            <?php
                                            $totalReplaced = (int)($row['total_replaced_qty'] ?? 0);
                                            $repCount = (int)($row['replacement_count'] ?? 0);
                                            ?>
                                            <?php if ($totalReplaced > 0): ?>
                                                <span class="badge badge-success">Replaced: <?= number_format($totalReplaced) ?> units</span>
                                                <?php if ($repCount > 1): ?>
                                                    <small class="text-muted d-block">(<?= $repCount ?> receipts)</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Pending replacement</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($canMutate): ?>
                                                <?php
                                                $receivingUrl = function_exists('app_url')
                                                    ? app_url('components/report/stock_receiving.php?pullout_id=' . (int)$row['pullout_id'] . '&product_id=' . (int)$row['product_id'])
                                                    : '../report/stock_receiving.php?pullout_id=' . (int)$row['pullout_id'] . '&product_id=' . (int)$row['product_id'];
                                                ?>
                                                <a href="<?= htmlspecialchars($receivingUrl) ?>" class="btn btn-small btn-secondary" title="Receive replacement goods for this pullout">
                                                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Receive Replacement
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const productSelect = document.getElementById('product_id');
    const batchSelect = document.getElementById('batch_id');
    const supplierInput = document.getElementById('supplier');
    const quantityInput = document.getElementById('quantity');

    if (!productSelect || !batchSelect) return;

    function loadBatchesForProduct(productId, preselectedBatchId) {
        if (!productId) {
            batchSelect.innerHTML = '<option value="">-- Select Product First --</option>';
            return;
        }

        fetch('supplier_pullouts.php?action=get_batches&product_id=' + encodeURIComponent(productId))
            .then(res => res.json())
            .then(batches => {
                batchSelect.innerHTML = '<option value="">-- Select Batch --</option>';
                if (!batches || batches.length === 0) {
                    batchSelect.innerHTML = '<option value="">No eligible batches with remaining stock</option>';
                    return;
                }
                batches.forEach(b => {
                    const opt = document.createElement('option');
                    opt.value = b.batch_id;
                    opt.dataset.remaining = b.remaining_quantity;
                    opt.dataset.supplier = b.supplier || '';
                    const expLabel = b.expiration_date ? ('Exp: ' + b.expiration_date) : 'No date';
                    opt.textContent = (b.batch_number || ('#' + b.batch_id)) + ' (Remaining: ' + b.remaining_quantity + ', ' + expLabel + ')';
                    if (preselectedBatchId && String(b.batch_id) === String(preselectedBatchId)) {
                        opt.selected = true;
                    }
                    batchSelect.appendChild(opt);
                });
                updateBatchDetails();
            })
            .catch(() => {
                batchSelect.innerHTML = '<option value="">Error loading batches</option>';
            });
    }

    function updateBatchDetails() {
        const selectedOpt = batchSelect.options[batchSelect.selectedIndex];
        if (selectedOpt && selectedOpt.dataset.remaining) {
            const maxRemaining = parseInt(selectedOpt.dataset.remaining, 10);
            quantityInput.max = maxRemaining;
            if (supplierInput && !supplierInput.value && selectedOpt.dataset.supplier) {
                supplierInput.value = selectedOpt.dataset.supplier;
            }
        }
    }

    productSelect.addEventListener('change', function () {
        loadBatchesForProduct(this.value, null);
    });

    batchSelect.addEventListener('change', updateBatchDetails);

    // Initial load if product is selected
    if (productSelect.value) {
        loadBatchesForProduct(productSelect.value, <?= json_encode($prefillBatchId > 0 ? $prefillBatchId : null) ?>);
    }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabs = Array.from(document.querySelectorAll('.pullout-tab'));
    const activeClasses = ['border-blue-600', 'text-[var(--theme-info-text,#2563eb)]'];
    const inactiveClasses = ['border-transparent', 'text-[var(--muted)]'];

    function activateTab(selectedTab) {
        tabs.forEach(tab => {
            const active = tab === selectedTab;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            activeClasses.forEach(name => tab.classList.toggle(name, active));
            inactiveClasses.forEach(name => tab.classList.toggle(name, !active));
            document.getElementById(tab.getAttribute('aria-controls')).hidden = !active;
        });
    }

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activateTab(tab));
        tab.addEventListener('keydown', event => {
            let nextIndex;
            if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
            else if (event.key === 'Home') nextIndex = 0;
            else if (event.key === 'End') nextIndex = tabs.length - 1;
            else return;
            event.preventDefault();
            activateTab(tabs[nextIndex]);
            tabs[nextIndex].focus();
        });
    });
});
</script>
</body>
</html>
