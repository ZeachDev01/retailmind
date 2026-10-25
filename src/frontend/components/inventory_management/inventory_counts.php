<?php
// components/inventory_management/inventory_counts.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/InventoryCountService.php';
require_once __DIR__ . '/../../../backend/app/Services/StockIssueService.php';
header('Cache-Control: no-store');
require_role(['admin', 'inventory_manager']);

$countService = new InventoryCountService($pdo, (string)current_role());
$stockIssueService = new StockIssueService($pdo, null, (string)current_role());
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
        $error = \App\Support\OperatorAlert::message($e, 'The linked report could not be loaded. Refresh the page and try again. Tell your Administrator if this keeps happening.');
    }
}

// Count mutation affordances belong only to Inventory Managers. Administrators
// retain read-only oversight and product lookup without record/decision controls.
$canMutateInventory = has_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
$correctionFormActive = $correctReport !== null
    && $canMutateInventory;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'batch_coverage') {
        header('Content-Type: application/json');
        try {
            $productId = (int)($_GET['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new RuntimeException('Product is required.');
            }
            $coverage = $countService->getProductBatchCoverage($productId);
            echo json_encode(['success' => true, 'coverage' => $coverage]);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    if ($_GET['action'] === 'count_batches') {
        header('Content-Type: application/json');
        try {
            $countId = (int)($_GET['count_id'] ?? 0);
            if ($countId <= 0) {
                throw new RuntimeException('Count is required.');
            }
            $batches = $countService->getCountBatches($countId);
            echo json_encode(['success' => true, 'batches' => $batches]);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

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
            $countService->approveCount($countId, (int)$_SESSION['user_id'], (string)($_POST['operation_token'] ?? ''));
            $message = "Inventory count #{$countId} approved and stock reconciled.";
        } elseif ($action === 'reject') {
            $countId = (int)($_POST['count_id'] ?? 0);
            $countService->rejectCount($countId, (int)$_SESSION['user_id'], (string)($_POST['operation_token'] ?? ''));
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
        $error = \App\Support\OperatorAlert::message($e, 'The inventory count could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.');
    }
}

$products = $countService->getActiveProducts();
$counts = $countService->getRecentCounts();
$pendingTotal = $countService->getPendingCountTotal();
$productCodeLookupUrl = app_url('components/barcodeScanner/apiScanner/product_code_lookup.php');
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Counts</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/inventory.css')) ?>">
<?php require __DIR__ . '/../../../backend/includes/inventory_drafts.php'; ?>
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <div class="topbar">
                <div>
                    <h1>Inventory Counts</h1>
                </div>
                <span class="badge-role"><?= (int)$pendingTotal ?> pending approval</span>
            </div>

            <?php if ($message): ?>
                <div class="alert tag-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="count-layout">
                <div class="count-panel">
                    <h3>New count</h3>
                    <p class="section-description">System stock is captured on submission. Stock changes only after approval.</p>

                    <?php if ($correctionFormActive): ?>
                        <div class="message success">
                            Correcting approved stock issue report #<?= (int)$correctReport['adjustment_id'] ?>
                            (<?= htmlspecialchars($correctReport['product_name']) ?>, <?= htmlspecialchars($correctReport['adjustment_type']) ?>).
                            This count creates a linked record. The original report stays unchanged.
                            <a href="<?= htmlspecialchars(app_url('components/inventory_management/inventory_counts.php')) ?>">Clear correction link</a>
                        </div>
                    <?php elseif ($correctReport !== null): ?>
                        <div class="message">
                            Stock issue report #<?= (int)$correctReport['adjustment_id'] ?> is approved and cannot be edited.
                            An Inventory Manager can submit a linked count to correct it. Administrator access is read-only.
                            <a href="<?= htmlspecialchars(app_url('components/administrator/stock_issues.php?detail=' . (int)$correctReport['adjustment_id'])) ?>">Back to oversight</a>
                        </div>
                    <?php endif; ?>

                    <?php if ($canMutateInventory): ?>
                        <form method="POST" class="count-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="action" value="record">
                            <?php if ($correctionFormActive): ?>
                                <input type="hidden" name="related_adjustment_id" value="<?= (int)$correctReport['adjustment_id'] ?>">
                            <?php endif; ?>
                    <?php else: ?>
                        <div class="count-form">
                            <p class="section-description">Read-only access. Inventory Managers record, approve, or reject counts.</p>
                    <?php endif; ?>

                        <div class="form-group">
                            <label for="product_id">Product</label>
                            <div class="count-product-control">
                                <input type="text" id="product_search" hidden
                                    placeholder="Scan barcode, search name or SKU" autocomplete="off"
                                    autocapitalize="off" autocorrect="off" spellcheck="false"
                                    role="combobox" aria-autocomplete="list" aria-expanded="false"
                                    aria-controls="product_results" aria-describedby="product_search_status scan_status" disabled>
                                <div id="product_popup" class="count-product-results" hidden>
                                    <div id="product_results" role="listbox" aria-label="Products" hidden></div>
                                    <button type="button" id="product_show_all" class="btn btn-secondary" hidden>Show all matches</button>
                                </div>
                            </div>
                            <p id="product_search_status" class="muted" role="status" aria-live="polite"></p>
                            <select name="product_id" id="product_id" aria-label="Selected product" required <?= $canMutateInventory ? '' : 'disabled' ?>>
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
                            <p class="scan-status" id="scan_status" role="status" aria-live="polite"></p>
                            <div class="scan-match" id="scan_match" hidden>
                                <strong id="scan_match_name"></strong>
                                <small class="muted" id="scan_match_detail"></small>
                            </div>
                        </div>

                        <div id="batch_reconciliation_section" class="form-group" hidden>
                            <div id="batch_mismatch_alert" class="alert-error" style="margin-bottom: 12px; padding: 10px; border-radius: 4px;" hidden></div>
                            <div id="unbatched_stock_alert" class="alert" style="margin-bottom: 12px; padding: 10px; border-radius: 4px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba;" hidden></div>

                            <label>Count by batch</label>
                            <p class="section-description">Count each batch. Leave unknown expiration dates blank.</p>
                            <div class="table-wrap" style="margin-bottom: 12px;">
                                <table id="batch_counts_table">
                                    <thead>
                                        <tr>
                                            <th>Batch</th>
                                            <th>Expiration</th>
                                            <th>Status</th>
                                            <th>System</th>
                                            <th>Counted</th>
                                        </tr>
                                    </thead>
                                    <tbody id="batch_counts_tbody"></tbody>
                                </table>
                            </div>

                            <div id="extra_batches_container" style="margin-bottom: 12px;">
                                <div id="extra_batches_list"></div>
                                <?php if ($canMutateInventory): ?>
                                    <button type="button" id="add_extra_batch_btn" class="btn btn-small btn-secondary">+ Add found batch</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div id="top_level_pack_counts" style="display:none; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label for="physical_sealed_quantity">Sealed Packs</label>
                                <input type="number" name="physical_sealed_quantity" id="physical_sealed_quantity" min="0" disabled <?= $canMutateInventory ? '' : 'readonly' ?>>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label for="physical_loose_quantity">Loose Pieces</label>
                                <input type="number" name="physical_loose_quantity" id="physical_loose_quantity" min="0" disabled <?= $canMutateInventory ? '' : 'readonly' ?>>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="physical_quantity">Counted quantity</label>
                            <input type="number" name="physical_quantity" id="physical_quantity" min="0" required <?= $canMutateInventory ? '' : 'readonly' ?>>
                            <button type="button" id="use_system_quantity" class="btn btn-small btn-secondary" disabled>Use system quantity</button>
                        </div>

                        <div class="quantity-preview" aria-live="polite">
                            <div>
                                <span>System</span>
                                <strong id="systemQty">-</strong>
                            </div>
                            <div>
                                <span>Counted</span>
                                <strong id="physicalQty">-</strong>
                            </div>
                            <div>
                                <span>Difference</span>
                                <strong id="differenceQty">-</strong>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="discrepancy_reason">Count reason</label>
                            <textarea
                                name="discrepancy_reason"
                                id="discrepancy_reason"
                                required
                                <?= $canMutateInventory ? '' : 'readonly' ?>
                                placeholder="<?= $correctionFormActive
                                    ? htmlspecialchars('Describe the recount for stock issue #' . (int)$correctReport['adjustment_id'] . '.')
                                    : 'Explain the count or difference.' ?>"></textarea>
                        </div>

                        <?php if ($canMutateInventory): ?>
                            <button type="submit" class="btn btn-block">Record Count</button>
                        </form>
                        <?php else: ?>
                        </div>
                        <?php endif; ?>
                </div>

                <div class="count-panel">
                    <div class="section-header">
                        <div>
                            <h3>Recent counts</h3>
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
                                            <?php $hasCountPack = !empty($count['product_pack_size']) && (int)$count['product_pack_size'] > 1; ?>
                                            <td>
                                                <?= (int)$count['system_quantity'] ?>
                                                <?php if ($hasCountPack && array_key_exists('system_sealed_quantity', $count)): ?>
                                                    <br><small class="muted"><?= (int)$count['system_sealed_quantity'] ?> pk, <?= (int)$count['system_loose_quantity'] ?> loose</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= (int)$count['physical_quantity'] ?>
                                                <?php if ($hasCountPack && array_key_exists('physical_sealed_quantity', $count)): ?>
                                                    <br><small class="muted"><?= (int)$count['physical_sealed_quantity'] ?> pk, <?= (int)$count['physical_loose_quantity'] ?> loose</small>
                                                <?php endif; ?>
                                                <?php if (!empty($count['batch_count'])): ?>
                                                    <br><button type="button" class="btn btn-small btn-secondary btn-view-batches" data-count-id="<?= (int)$count['count_id'] ?>" style="margin-top: 4px; padding: 2px 6px; font-size: 11px;">
                                                        <?= (int)$count['batch_count'] ?> batch<?= (int)$count['batch_count'] === 1 ? '' : 'es' ?>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
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
                                                <?php if ($count['status'] === 'pending' && $canMutateInventory): ?>
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

            <dialog id="batch_detail_modal" style="padding: 20px; border-radius: 8px; border: 1px solid #ccc; max-width: 650px; width: 90%;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h3 id="batch_detail_title" style="margin: 0;">Count Batches</h3>
                    <button type="button" class="btn btn-small btn-secondary" onclick="document.getElementById('batch_detail_modal').close()">Close</button>
                </div>
                <div id="batch_detail_body"></div>
            </dialog>
        </main>
    </div>

    <script>
        const productSelect = document.getElementById('product_id');
        const physicalInput = document.getElementById('physical_quantity');
        const useSystemQuantity = document.getElementById('use_system_quantity');
        const systemQty = document.getElementById('systemQty');
        const physicalQty = document.getElementById('physicalQty');
        const differenceQty = document.getElementById('differenceQty');

        function updatePreview() {
            const option = productSelect.options[productSelect.selectedIndex];
            const hasProduct = option && option.dataset.systemQty !== undefined;
            const system = hasProduct ? parseInt(option.dataset.systemQty, 10) : null;
            const physical = physicalInput.value === '' ? null : parseInt(physicalInput.value, 10);
            const canUseSystemQuantity = system !== null && !Number.isNaN(system) && system >= 0;
            useSystemQuantity.disabled = physicalInput.readOnly || !canUseSystemQuantity;
            useSystemQuantity.textContent = canUseSystemQuantity ? 'Use system quantity (' + system + ')' : 'Use system quantity';

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

        productSelect.addEventListener('change', function () {
            updatePreview();
            fetchBatchCoverage(productSelect.value);
        });
        physicalInput.addEventListener('input', updatePreview);
        const topLevelPackWrap = document.getElementById('top_level_pack_counts');
        const topLevelSealedInput = document.getElementById('physical_sealed_quantity');
        const topLevelLooseInput = document.getElementById('physical_loose_quantity');

        function recomputeTopLevelPackCount() {
            if (!activeCoverage || !activeCoverage.has_sales_pack || !topLevelSealedInput || !topLevelLooseInput || topLevelSealedInput.disabled) return;
            const s = parseInt(topLevelSealedInput.value, 10) || 0;
            const l = parseInt(topLevelLooseInput.value, 10) || 0;
            const pSize = parseInt(activeCoverage.pack_size, 10) || 1;
            if (topLevelSealedInput.value !== '' || topLevelLooseInput.value !== '') {
                physicalInput.value = (s * pSize) + l;
                updatePreview();
            }
        }
        if (topLevelSealedInput) topLevelSealedInput.addEventListener('input', recomputeTopLevelPackCount);
        if (topLevelLooseInput) topLevelLooseInput.addEventListener('input', recomputeTopLevelPackCount);

        useSystemQuantity.addEventListener('click', function () {
            if (activeCoverage && activeCoverage.batches && activeCoverage.batches.length > 0) {
                const inputs = batchSection.querySelectorAll('tbody .batch-count-input');
                inputs.forEach(function (inp) {
                    if (inp.dataset.systemQty !== undefined) {
                        inp.value = inp.dataset.systemQty;
                    }
                });
                recomputeTotalFromBatches();
            } else {
                const sysTotal = parseInt(productSelect.selectedOptions[0].dataset.systemQty, 10) || 0;
                if (activeCoverage && activeCoverage.has_sales_pack && topLevelSealedInput && !topLevelSealedInput.disabled) {
                    topLevelSealedInput.value = activeCoverage.batch_sealed_sum || 0;
                    topLevelLooseInput.value = activeCoverage.batch_loose_sum || sysTotal;
                }
                physicalInput.value = sysTotal;
                physicalInput.dispatchEvent(new Event('input', { bubbles: true }));
                physicalInput.focus();
            }
        });
        updatePreview();
        if (productSelect.value) {
            fetchBatchCoverage(productSelect.value);
        }

        const batchSection = document.getElementById('batch_reconciliation_section');
        const batchMismatchAlert = document.getElementById('batch_mismatch_alert');
        const unbatchedAlert = document.getElementById('unbatched_stock_alert');
        const batchTbody = document.getElementById('batch_counts_tbody');
        const extraBatchesList = document.getElementById('extra_batches_list');
        const addExtraBatchBtn = document.getElementById('add_extra_batch_btn');
        const batchDetailModal = document.getElementById('batch_detail_modal');
        const batchDetailTitle = document.getElementById('batch_detail_title');
        const batchDetailBody = document.getElementById('batch_detail_body');
        let extraBatchIndex = 0;
        let activeCoverage = null;

        document.addEventListener('inventorydraft:prepare', event => {
            if (event.detail.draft.id !== 'record:') return;
            event.detail.waitUntil((async () => {
                const value = name => event.detail.draft.values.find(item => item.name === name)?.value;
                const productId = value('product_id') || '';
                if (!Array.from(productSelect.options).some(option => option.value === productId)) throw new Error('The selected Product is no longer available. Your draft is still kept.');
                productSelect.value = productId;
                syncProductSearch();
                await fetchBatchCoverage(productSelect.value);
                for (const item of event.detail.draft.values.filter(item => /^batches\[\d+\]\[batch_id\]$/.test(item.name))) {
                    if (document.getElementsByName(item.name)[0]?.value !== item.value) throw new Error('Batch availability has changed. Your draft is still kept; review current stock before resuming.');
                }
                const keys = [...new Set(event.detail.draft.values.map(item => item.name.match(/^batches\[(extra_\d+)\]/)?.[1]).filter(Boolean))];
                for (const key of keys) {
                    extraBatchIndex = Number(key.slice(6)) - 1;
                    addExtraBatchBtn.click();
                }
            })());
        });
        function fetchBatchCoverage(productId) {
            if (!productId || productId <= 0) {
                if (batchSection) batchSection.hidden = true;
                activeCoverage = null;
                return;
            }
            return fetch('inventory_counts.php?action=batch_coverage&product_id=' + encodeURIComponent(productId), {
                headers: { Accept: 'application/json' }
            })
                .then(res => res.json())
                .then(data => {
                    if (!data.success || !data.coverage) return;
                    activeCoverage = data.coverage;
                    renderBatchSection(data.coverage);
                })
                .catch(() => {});
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, function (m) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
            });
        }

        function renderBatchSection(coverage) {
            if (!batchSection) return;
            batchSection.hidden = false;
            extraBatchesList.replaceChildren();

            if (coverage.has_mismatch) {
                batchMismatchAlert.hidden = false;
                const diff = Math.abs(coverage.on_hand - coverage.batch_sum);
                const desc = coverage.on_hand > coverage.batch_sum
                    ? 'Unbatched stock: ' + diff
                    : 'Batches exceed on-hand by ' + diff;
                batchMismatchAlert.textContent = 'Stock mismatch: ' + coverage.on_hand + ' on hand, ' + coverage.batch_sum + ' in batches. ' + desc + '. Count each batch to reconcile.';
            } else {
                batchMismatchAlert.hidden = true;
            }
            unbatchedAlert.hidden = true;

            const hasNoBatches = !coverage.batches || coverage.batches.length === 0;
            if (topLevelPackWrap && topLevelSealedInput && topLevelLooseInput) {
                if (coverage.has_sales_pack && hasNoBatches) {
                    topLevelPackWrap.style.display = 'grid';
                    topLevelSealedInput.disabled = false;
                    topLevelLooseInput.disabled = false;
                } else {
                    topLevelPackWrap.style.display = 'none';
                    topLevelSealedInput.disabled = true;
                    topLevelLooseInput.disabled = true;
                    topLevelSealedInput.value = '';
                    topLevelLooseInput.value = '';
                }
            }

            batchTbody.replaceChildren();
            if (hasNoBatches) {
                const tr = document.createElement('tr');
                tr.innerHTML = '<td colspan="5" class="muted" style="text-align:center; padding:12px;">No active batches. Use "Add found batch" for unbatched stock.</td>';
                batchTbody.appendChild(tr);
            } else {
                coverage.batches.forEach(function (b, idx) {
                    const tr = document.createElement('tr');
                    const bNum = b.batch_number ? b.batch_number : (b.receiving_id ? 'Received batch #' + b.batch_id : 'Opening stock');
                    const bExp = b.expiration_date ? b.expiration_date : 'Not recorded';
                    const statusBadge = b.is_expired
                        ? '<span class="status-pill status-rejected" style="font-size:11px;">Expired</span>'
                        : '<span class="status-pill status-approved" style="font-size:11px;">Active</span>';

                    let countedCell = '';
                    let systemCell = `${b.remaining_quantity}`;
                    if (coverage.has_sales_pack) {
                        systemCell += ` <small class="muted" style="display:block;">${b.sealed_quantity || 0} pk, ${b.loose_quantity || 0} loose</small>`;
                        countedCell = `
                            <input type="hidden" name="batches[${idx}][batch_id]" value="${b.batch_id}">
                            <div style="display:flex; gap:6px; align-items:center;">
                                <div>
                                    <label style="font-size:10px; display:block;">Packs</label>
                                    <input type="number" class="batch-count-input batch-count-sealed"
                                           name="batches[${idx}][physical_sealed_quantity]"
                                           min="0" style="width: 65px;" data-pack-size="${coverage.pack_size}"
                                           data-system-qty="${b.sealed_quantity ?? 0}"
                                           value="${b.sealed_quantity ?? 0}" <?= $canMutateInventory ? '' : 'readonly' ?>>
                                </div>
                                <div>
                                    <label style="font-size:10px; display:block;">Loose</label>
                                    <input type="number" class="batch-count-input batch-count-loose"
                                           name="batches[${idx}][physical_loose_quantity]"
                                           min="0" style="width: 65px;"
                                           data-system-qty="${b.loose_quantity ?? 0}"
                                           value="${b.loose_quantity ?? 0}" <?= $canMutateInventory ? '' : 'readonly' ?>>
                                </div>
                                <input type="hidden" class="batch-base-qty" name="batches[${idx}][physical_quantity]" value="${b.remaining_quantity}">
                            </div>
                        `;
                    } else {
                        countedCell = `
                            <input type="hidden" name="batches[${idx}][batch_id]" value="${b.batch_id}">
                            <input type="number" class="batch-count-input batch-count-loose" name="batches[${idx}][physical_quantity]"
                                   min="0" style="width: 80px;" data-system-qty="${b.remaining_quantity}"
                                   placeholder="0" <?= $canMutateInventory ? '' : 'readonly' ?>>
                        `;
                    }

                    tr.innerHTML = `
                        <td><strong>${escapeHtml(bNum)}</strong></td>
                        <td>${escapeHtml(bExp)}</td>
                        <td>${statusBadge}</td>
                        <td>${systemCell}</td>
                        <td>${countedCell}</td>
                    `;
                    batchTbody.appendChild(tr);
                });
            }

            attachBatchInputListeners();
        }

        function attachBatchInputListeners() {
            if (!batchSection) return;
            const inputs = batchSection.querySelectorAll('.batch-count-input');
            inputs.forEach(function (inp) {
                inp.removeEventListener('input', recomputeTotalFromBatches);
                inp.addEventListener('input', recomputeTotalFromBatches);
            });
        }

        function recomputeTotalFromBatches() {
            if (!batchSection) return;
            const rows = batchSection.querySelectorAll('tbody tr, .extra-batch-row');
            if (rows.length === 0) return;
            let sum = 0;
            let hasAny = false;
            rows.forEach(function (row) {
                const sealedInp = row.querySelector('.batch-count-sealed');
                const looseInp = row.querySelector('.batch-count-loose');
                const baseInp = row.querySelector('.batch-base-qty');
                const plainInp = row.querySelector('.batch-count-input:not(.batch-count-sealed):not(.batch-count-loose)');
                if (sealedInp || looseInp) {
                    const sealed = parseInt(sealedInp?.value, 10) || 0;
                    const loose = parseInt(looseInp?.value, 10) || 0;
                    const packSize = parseInt(sealedInp?.dataset.packSize, 10) || (activeCoverage?.pack_size || 1);
                    const rowTotal = (sealed * packSize) + loose;
                    if (sealedInp?.value !== '' || looseInp?.value !== '') {
                        hasAny = true;
                    }
                    if (baseInp) baseInp.value = rowTotal;
                    sum += rowTotal;
                } else if (plainInp) {
                    if (plainInp.value !== '') {
                        hasAny = true;
                        sum += parseInt(plainInp.value, 10) || 0;
                    }
                }
            });
            if (hasAny) {
                physicalInput.value = sum;
                updatePreview();
            }
        }

        if (addExtraBatchBtn) {
            addExtraBatchBtn.addEventListener('click', function () {
                const key = 'extra_' + (++extraBatchIndex);
                const div = document.createElement('div');
                div.className = 'extra-batch-row';
                div.style.cssText = 'border:1px dashed #ccc; padding:10px; margin-bottom:8px; border-radius:4px; background:rgba(0,0,0,0.02);';
                let batchFields = '';
                if (activeCoverage && activeCoverage.has_sales_pack) {
                    batchFields = `
                        <div class="form-group" style="margin-bottom:4px;">
                            <label style="font-size:12px;">Sealed Packs (x ${activeCoverage.pack_size})</label>
                            <input type="number" class="batch-count-input batch-count-sealed" name="batches[${key}][physical_sealed_quantity]" min="0" value="0" data-pack-size="${activeCoverage.pack_size}" style="width:100%;">
                        </div>
                        <div class="form-group" style="margin-bottom:4px;">
                            <label style="font-size:12px;">Loose Pieces</label>
                            <input type="number" class="batch-count-input batch-count-loose" name="batches[${key}][physical_loose_quantity]" min="0" value="0" style="width:100%;">
                        </div>
                        <input type="hidden" class="batch-base-qty" name="batches[${key}][physical_quantity]" value="0">
                    `;
                } else {
                    batchFields = `
                        <div class="form-group" style="margin-bottom:4px; grid-column: span 2;">
                            <label style="font-size:12px;">Counted Quantity *</label>
                            <input type="number" class="batch-count-input batch-count-loose" name="batches[${key}][physical_quantity]" min="1" required style="width:100%;">
                        </div>
                    `;
                }
                div.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <strong>Found batch</strong>
                        <button type="button" class="btn btn-small btn-secondary remove-batch-btn" style="padding:1px 6px;">&times; Remove</button>
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                        ${batchFields}
                        <div class="form-group" style="margin-bottom:4px;">
                            <label style="font-size:12px;">Expiration date (optional)</label>
                            <input type="date" name="batches[${key}][expiration_date]" style="width:100%;">
                        </div>
                        <div class="form-group" style="margin-bottom:4px;">
                            <label style="font-size:12px;">Batch number / label (optional)</label>
                            <input type="text" name="batches[${key}][batch_number]" placeholder="e.g. FOUND-01" style="width:100%;">
                        </div>
                        <div class="form-group" style="margin-bottom:4px; grid-column: span 2;">
                            <label style="font-size:12px;">Notes (optional)</label>
                            <input type="text" name="batches[${key}][notes]" placeholder="e.g. Unbatched shelf stock" style="width:100%;">
                        </div>
                    </div>
                `;
                div.querySelector('.remove-batch-btn').addEventListener('click', function () {
                    div.remove();
                    recomputeTotalFromBatches();
                });
                extraBatchesList.appendChild(div);
                attachBatchInputListeners();
            });
        }

        // View batches in recent counts
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-view-batches');
            if (!btn) return;
            const countId = btn.dataset.countId;
            if (!countId || !batchDetailModal) return;
            batchDetailTitle.textContent = 'Batches for Count #' + countId;
            batchDetailBody.innerHTML = '<p class="muted">Loading batch details...</p>';
            batchDetailModal.showModal();
            fetch('inventory_counts.php?action=count_batches&count_id=' + encodeURIComponent(countId), {
                headers: { Accept: 'application/json' }
            })
                .then(res => res.json())
                .then(data => {
                    if (!data.success || !data.batches || data.batches.length === 0) {
                        batchDetailBody.innerHTML = '<p class="muted">No batch breakdown recorded.</p>';
                        return;
                    }
                    let html = '<div class="table-wrap"><table><thead><tr><th>Batch</th><th>Expiration</th><th>System</th><th>Counted</th><th>Diff</th><th>Notes</th></tr></thead><tbody>';
                    data.batches.forEach(function (b) {
                        const bNum = b.batch_number ? b.batch_number : (b.original_batch_number ? b.original_batch_number : (b.batch_id ? 'Batch #' + b.batch_id : 'Added / Found'));
                        const bExp = b.expiration_date ? b.expiration_date : (b.original_expiration_date ? b.original_expiration_date : 'Not recorded');
                        const diff = b.difference_qty > 0 ? '+' + b.difference_qty : b.difference_qty;
                        let sysStr = `${b.system_quantity}`;
                        let countedStr = `${b.physical_quantity}`;
                        const hasPack = Number(b.product_pack_size || 0) > 1 || Number(b.system_sealed_quantity || 0) > 0 || Number(b.physical_sealed_quantity || 0) > 0;
                        if (hasPack) {
                            sysStr += ` <small class="muted">(${b.system_sealed_quantity ?? 0} pk, ${b.system_loose_quantity ?? 0} loose)</small>`;
                            countedStr += ` <small class="muted">(${b.physical_sealed_quantity ?? 0} pk, ${b.physical_loose_quantity ?? 0} loose)</small>`;
                        }
                        html += `<tr>
                            <td><strong>${escapeHtml(bNum)}</strong></td>
                            <td>${escapeHtml(bExp)}</td>
                            <td>${sysStr}</td>
                            <td>${countedStr}</td>
                            <td>${diff}</td>
                            <td>${escapeHtml(b.notes || '-')}</td>
                        </tr>`;
                    });
                    html += '</tbody></table></div>';
                    batchDetailBody.innerHTML = html;
                })
                .catch(() => {
                    batchDetailBody.innerHTML = '<p class="alert-error">Failed to load batch details.</p>';
                });
        });

        const productSearch = document.getElementById('product_search');
        const productResults = document.getElementById('product_results');
        const productPopup = document.getElementById('product_popup');
        const productShowAll = document.getElementById('product_show_all');
        const productSearchStatus = document.getElementById('product_search_status');
        let activeProductIndex = -1;
        let latestLookupRequestId = 0;

        function closeProductResults() {
            productResults.hidden = true;
            productPopup.hidden = true;
            productSearch.setAttribute('aria-expanded', 'false');
            productSearch.removeAttribute('aria-activedescendant');
            activeProductIndex = -1;
        }

        function syncProductSearch() {
            const option = productSelect.selectedOptions[0];
            productSearch.value = option && option.value ? option.textContent.trim() : '';
            productSearch.setCustomValidity(productSelect.value ? '' : 'Choose a product from the matching results.');
            productSearchStatus.textContent = '';
            closeProductResults();
        }

        function showProductResults(showAll) {
            const query = productSearch.value.trim().toLowerCase();
            productResults.replaceChildren();
            if (query === '') {
                productSearchStatus.textContent = '';
                closeProductResults();
                return;
            }
            activeProductIndex = -1;
            productSearch.removeAttribute('aria-activedescendant');
            const matches = Array.from(productSelect.options).filter(function (option) {
                return option.value && option.textContent.toLowerCase().includes(query);
            });
            for (const option of showAll === true ? matches : matches.slice(0, 5)) {
                const result = document.createElement('div');
                result.id = 'product_result_' + option.value;
                result.setAttribute('role', 'option');
                result.setAttribute('aria-selected', 'false');
                result.dataset.value = option.value;
                result.textContent = option.textContent.trim();
                productResults.appendChild(result);
            }
            const count = matches.length;
            if (count === 0) {
                const empty = document.createElement('div');
                empty.className = 'muted';
                empty.textContent = 'No products found';
                productResults.appendChild(empty);
            }
            productShowAll.hidden = showAll === true || count <= 5;
            productShowAll.textContent = 'Show all ' + count + ' matches';
            productSearchStatus.textContent = count > 5 && showAll !== true
                ? 'Showing 5 of ' + count + ' matches.' : count
                ? count + (count === 1 ? ' matching product.' : ' matching products.') : 'No products found';
            productResults.hidden = false;
            productPopup.hidden = false;
            productSearch.setAttribute('aria-expanded', 'true');
        }

        function chooseProductResult(result) {
            ++latestLookupRequestId;
            if (!confirmProductSelection(Number(result.dataset.value), result.textContent)) {
                syncProductSearch();
                return;
            }
            productSelect.value = result.dataset.value;
            productSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }

        productSearch.addEventListener('input', function () {
            ++latestLookupRequestId;
            productSearch.setCustomValidity('Choose a product from the matching results.');
            setScanStatus('');
            scanMatch.hidden = true;
            showProductResults();
        });
        productSearch.addEventListener('blur', function (event) {
            if (!productPopup.contains(event.relatedTarget)) closeProductResults();
        });
        productPopup.addEventListener('mousedown', function (event) { event.preventDefault(); });
        productShowAll.addEventListener('blur', function (event) {
            if (event.relatedTarget !== productSearch) closeProductResults();
        });
        productShowAll.addEventListener('click', function () {
            productSearch.focus();
            showProductResults(true);
        });
        productResults.addEventListener('click', function (event) {
            const result = event.target.closest('[role="option"]');
            if (result) {
                chooseProductResult(result);
            }
        });
        productSearch.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeProductResults();
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                const result = productResults.querySelector('[aria-selected="true"]');
                if (!productResults.hidden && result) {
                    chooseProductResult(result);
                } else {
                    const code = productSearch.value.trim();
                    if (code) startLookup(code);
                    else setScanStatus('Scan a barcode or search by SKU or product name.', 'error');
                }
                return;
            }
            if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
                return;
            }
            event.preventDefault();
            if (productResults.hidden) {
                showProductResults();
            }
            const results = productResults.querySelectorAll('[role="option"]');
            if (!results.length) {
                return;
            }
            activeProductIndex = activeProductIndex < 0
                ? (event.key === 'ArrowDown' ? 0 : results.length - 1)
                : (activeProductIndex + (event.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length;
            results.forEach(function (result, index) {
                result.setAttribute('aria-selected', String(index === activeProductIndex));
            });
            const active = results[activeProductIndex];
            productSearch.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({ block: 'nearest' });
        });
        productSelect.addEventListener('change', syncProductSearch);
        productSelect.required = false;
        productSelect.hidden = true;
        productSearch.disabled = false;
        productSearch.required = !productSelect.disabled;
        productSearch.hidden = false;
        document.querySelector('label[for="product_id"]').htmlFor = 'product_search';
        syncProductSearch();

        // --- Barcode / SKU scan (ticket #79) ---------------------------------
        // Identification only: a scan selects the product and moves focus to
        // the counted total. It never writes a quantity, never increments one,
        // and never submits the form.
        const scanStatus = document.getElementById('scan_status');
        const scanMatch = document.getElementById('scan_match');
        const scanMatchName = document.getElementById('scan_match_name');
        const scanMatchDetail = document.getElementById('scan_match_detail');
        const productCodeLookupUrl = <?= json_encode($productCodeLookupUrl) ?>;
        const correctionProductId = <?= $correctionFormActive
            ? json_encode((int)$correctReport['product_id'])
            : 'null' ?>;
        const kindLabels = {
            sku: 'SKU',
            unit_barcode: 'unit barcode',
            case_barcode: 'case barcode'
        };

        function setScanStatus(message, tone) {
            scanStatus.textContent = message || '';
            scanStatus.classList.remove('scan-status--ok', 'scan-status--error');
            if (tone === 'ok') {
                scanStatus.classList.add('scan-status--ok');
            } else if (tone === 'error') {
                scanStatus.classList.add('scan-status--error');
            }
        }

        function showScanMatch(product, matchedKind) {
            scanMatchName.textContent = product.sku + ' - ' + product.product_name;
            scanMatchDetail.textContent =
                (kindLabels[matchedKind] ? kindLabels[matchedKind] + ' matched. ' : '') +
                'System quantity ' + product.quantity_on_hand + '.';
            scanMatch.hidden = false;
        }

        function applyProductSelection(product) {
            let option = productSelect.querySelector('option[value="' + product.product_id + '"]');
            if (!option) {
                option = document.createElement('option');
                option.value = String(product.product_id);
                option.textContent = product.sku + ' - ' + product.product_name;
                productSelect.appendChild(option);
            }
            option.dataset.systemQty = String(product.quantity_on_hand);
            productSelect.value = option.value;
            productSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function confirmProductSelection(productId, productName) {
            if (correctionProductId !== null && productId !== correctionProductId) {
                setScanStatus('This count is linked to an approved Stock Issue. Choose that report\'s product.', 'error');
                return false;
            }
            const reasonInput = document.getElementById('discrepancy_reason');
            const hasEnteredWork = physicalInput.value !== '' || reasonInput.value.trim() !== '';
            if (correctionProductId === null && Number(productSelect.value) > 0
                && productId !== Number(productSelect.value) && hasEnteredWork
                && !window.confirm('Replace the current product with ' + productName + '? Your entered count details will stay on the form.')) {
                setScanStatus('Product unchanged. Your entered count details were preserved.', 'error');
                return false;
            }
            return true;
        }

        function runProductLookup(code) {
            const requestId = ++latestLookupRequestId;
            fetch(productCodeLookupUrl + '?code=' + encodeURIComponent(code), {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.json().then(
                        function (body) { return { ok: response.ok, body: body }; },
                        function () { return { ok: false, body: null }; }
                    );
                })
                .then(function (result) {
                    if (requestId !== latestLookupRequestId) {
                        return;
                    }
                    const body = result.body || {};
                    if (!result.ok || body.success === false) {
                        setScanStatus(
                            body.error || 'The code could not be checked. Try again or choose the product from the list.',
                            'error'
                        );
                        return;
                    }
                    if (body.outcome === 'match' && body.product) {
                        if (!confirmProductSelection(Number(body.product.product_id), body.product.product_name)) {
                            syncProductSearch();
                            return;
                        }

                        applyProductSelection(body.product);
                        showScanMatch(body.product, body.matched_code_kind);
                        setScanStatus('Matched by ' + (kindLabels[body.matched_code_kind] || 'code') + '. Enter the counted total.', 'ok');
                        physicalInput.focus();
                        updatePreview();
                        return;
                    }
                    if (body.outcome === 'ambiguous') {
                        setScanStatus(
                            '"' + body.code + '" matches more than one product. Nothing was changed - check the label or choose the product from the list.',
                            'error'
                        );
                        return;
                    }
                    setScanStatus(
                        'No product matches "' + body.code + '". Check the label or choose the product from the list.',
                        'error'
                    );
                })
                .catch(function () {
                    if (requestId !== latestLookupRequestId) {
                        return;
                    }
                    setScanStatus('The code could not be checked. Check your connection and try again.', 'error');
                });
        }

        function startLookup(code) {
            setScanStatus('Checking "' + code + '"...');
            runProductLookup(code);
        }

        // A keyboard wedge types into whatever holds focus. When focus sits on
        // the counted total, a repeated scan would otherwise rewrite it, so an
        // Enter-terminated burst of keystrokes is treated as a scan: the total
        // is restored to what the operator entered and the code is looked up.
        const SCAN_BURST_MIN_KEYS = 2;
        const SCAN_BURST_IDLE_MS = 600;
        const SCAN_BURST_MAX_SPAN_MS = 1500;
        const SCAN_BURST_ENTER_MS = 300;
        let burstKeys = [];
        let burstValue = '';

        physicalInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                const now = Date.now();
                const last = burstKeys[burstKeys.length - 1];
                const code = burstKeys.map(function (entry) { return entry.key; }).join('');
                // A counted total is only ever digits, so a buffer holding any
                // other character is a code however short or slow it arrived.
                const notACountedTotal = code !== '' && /[^\d]/.test(code);
                const isScanBurst = notACountedTotal
                    || (burstKeys.length >= SCAN_BURST_MIN_KEYS
                        && last !== undefined
                        && (now - last.t) <= SCAN_BURST_ENTER_MS
                        && (last.t - burstKeys[0].t) <= SCAN_BURST_MAX_SPAN_MS);
                if (!isScanBurst) {
                    burstKeys = [];
                    return;
                }
                burstKeys = [];
                event.preventDefault();
                event.stopPropagation();
                physicalInput.value = burstValue;
                physicalInput.dispatchEvent(new Event('input', { bubbles: true }));
                startLookup(code);
                return;
            }

            if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) {
                burstKeys = [];
                return;
            }

            const typedAt = Date.now();
            if (burstKeys.length === 0 || (typedAt - burstKeys[burstKeys.length - 1].t) > SCAN_BURST_IDLE_MS) {
                burstKeys = [];
                burstValue = physicalInput.value;
            }
            burstKeys.push({ t: typedAt, key: event.key });
        });
        productSearch.focus();
        productSearch.select();
    </script>
</body>

</html>
