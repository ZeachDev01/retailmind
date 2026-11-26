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
            $countId = $countService->saveCount($_POST, (int)$_SESSION['user_id']);
            if (isset($_POST['inventory_draft_id'])) $_SESSION['inventory_confirmed_draft'] = (string)$_POST['inventory_draft_id'];
            $_SESSION['inventory_count_message'] = "Inventory count #{$countId} saved. Stock and batches updated.";
            header('Location: ' . app_url('components/inventory_management/inventory_counts.php?count_view=recent'), true, 303);
            exit;
        } else {
            throw new RuntimeException('Open the pending count and record a fresh count. Separate approval is no longer available.');
        }
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The inventory count could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.');
    }
}

$products = $countService->getActiveProducts();
$message = (string)($_SESSION['inventory_count_message'] ?? '');
unset($_SESSION['inventory_count_message']);
$recheckId = max(0, (int)($_GET['recount'] ?? $_POST['recheck_count_id'] ?? 0));
$recheckCount = $recheckId > 0 ? $countService->getCountForRecount($recheckId) : null;
if ($recheckId > 0 && !$recheckCount) $error = 'This pending count is no longer available. Check Recent Counts.';
$selectedProductId = (int)($_POST['product_id'] ?? $recheckCount['product_id'] ?? ($correctionFormActive ? $correctReport['product_id'] : 0));
$countView = $canMutateInventory && ($_GET['count_view'] ?? 'new') !== 'recent' ? 'new' : 'recent';
if ($error !== '' && $canMutateInventory) $countView = 'new';
$search = trim((string)($_GET['q'] ?? ''));
$sort = in_array($_GET['sort'] ?? '', ['date', 'product', 'system', 'counted', 'difference', 'staff', 'status'], true) ? $_GET['sort'] : 'date';
$direction = ($_GET['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$pageSize = in_array((int)($_GET['length'] ?? 15), [10, 15, 25, 50, 100], true) ? (int)($_GET['length'] ?? 15) : 15;
$totalRows = $countService->countHistory($search);
$totalPages = max(1, (int)ceil($totalRows / $pageSize));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$counts = $countService->getRecentCounts($pageSize, ($page - 1) * $pageSize, $search, $sort, $direction);
$rows = $counts;
$showCountActions = $canMutateInventory && count(array_filter($counts, static fn(array $count): bool => $count['status'] === 'pending')) > 0;
$hasNext = $page < $totalPages;
$paginationFilters = ['count_view' => 'recent', 'q' => $search, 'sort' => $sort, 'direction' => $direction];
$paginationLabel = 'Inventory count pages';
$url = static fn(array $changes): string => '?' . http_build_query(array_replace($paginationFilters + ['page' => $page, 'length' => $pageSize], $changes));
$sortUrl = static fn(string $column): string => $url(['sort' => $column, 'direction' => $sort === $column && $direction === 'asc' ? 'desc' : 'asc', 'page' => 1]);
$pendingTotal = $countService->getPendingCountTotal();
$productCodeLookupUrl = app_url('components/barcodeScanner/apiScanner/product_code_lookup.php');
$recordToken = $error !== '' && is_string($_POST['operation_token'] ?? null) ? $_POST['operation_token'] : bin2hex(random_bytes(24));
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Counts</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/inventory.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory-counts.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/inventory-counts.css')) ?>">
<?php require __DIR__ . '/../../../backend/includes/inventory_drafts.php'; ?>
</head>

<body class="inventory-counts-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <div class="inventory-page-heading">
                <h1>Inventory Counts</h1>
                <div class="inventory-recovery-slot" data-inventory-recovery-slot></div>
            </div>
            <?php if ($message): ?>
                <div class="alert tag-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <nav class="count-tabs" role="tablist" aria-label="Inventory count sections">
                <?php if ($canMutateInventory): ?><button type="button" role="tab" id="tab-new-count" class="count-tab" aria-selected="<?= $countView === 'new' ? 'true' : 'false' ?>" aria-controls="panel-new-count" tabindex="<?= $countView === 'new' ? '0' : '-1' ?>">New Count</button><?php endif; ?>
                <button type="button" role="tab" id="tab-recent-counts" class="count-tab" aria-selected="<?= $countView === 'recent' ? 'true' : 'false' ?>" aria-controls="panel-recent-counts" tabindex="<?= $countView === 'recent' ? '0' : '-1' ?>">Recent Counts</button>
            </nav>
            <div class="count-layout">
                <div class="count-panel" role="tabpanel" id="panel-new-count" aria-label="New Count" <?= $countView !== 'new' ? 'hidden' : '' ?>>
                    <div class="count-panel-heading"><h2>New stock count</h2></div>
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
                        <?php if ($recheckCount): ?><p class="alert">Recount for pending count #<?= $recheckId ?>. Enter fresh quantities; saving replaces the pending count and keeps its history.</p><?php endif; ?>
                        <form method="POST" class="count-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="action" value="record">
                            <input type="hidden" name="operation_token" value="<?= htmlspecialchars($recordToken) ?>">
                            <input type="hidden" name="stock_version" id="stock_version" value="">
                            <?php if ($recheckCount): ?><input type="hidden" name="recheck_count_id" value="<?= $recheckId ?>"><?php endif; ?>
                            <?php if ($correctionFormActive): ?>
                                <input type="hidden" name="related_adjustment_id" value="<?= (int)$correctReport['adjustment_id'] ?>">
                            <?php endif; ?>
                    <?php else: ?>
                        <div class="count-form">
                            <p class="section-description">Read-only access. Inventory Managers save stock counts.</p>
                    <?php endif; ?>

                        <div class="form-group count-product-field">
                            <label for="product_id">Product</label>
                            <div class="count-product-control">
                                <input type="text" id="product_search" hidden
                                    placeholder="Scan barcode, search name or SKU" autocomplete="off"
                                    autocapitalize="off" autocorrect="off" spellcheck="false"
                                    role="combobox" aria-autocomplete="list" aria-expanded="false"
                                    aria-controls="product_results" aria-describedby="product_search_status scan_status" disabled>
                                <div id="product_popup" class="count-product-results" hidden>
                                    <div id="product_results" role="listbox" aria-label="Products" tabindex="-1" hidden></div>
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
                                        <?= $selectedProductId === (int)$product['product_id'] ? 'selected' : '' ?>>
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

                        <p id="count_entry_placeholder" class="count-entry-placeholder">Search for a product to compare system stock with your physical count.</p>
                        <div class="count-entry-layout" id="count_entry_layout" hidden>
                        <section class="count-entry-main" aria-label="Physical stock">
                        <div id="batch_reconciliation_section" class="form-group" hidden>
                            <div id="batch_mismatch_alert" class="alert-error" style="margin-bottom: 12px; padding: 10px; border-radius: 4px;" hidden></div>
                            <div id="unbatched_stock_alert" class="alert" style="margin-bottom: 12px; padding: 10px; border-radius: 4px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba;" hidden></div>

                            <label>Count by batch</label>
                            <p class="section-description">Count each batch. Leave unknown expiration dates blank.</p>
                            <div class="table-wrap" style="margin-bottom: 12px;">
                                <table id="batch_counts_table" data-no-smart-table data-no-ghost-rows>
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

                        <div class="form-group" id="single_quantity_group">
                            <label for="physical_quantity">Counted quantity</label>
                            <input type="number" name="physical_quantity" id="physical_quantity" min="0" required <?= $canMutateInventory ? '' : 'readonly' ?>>
                            <button type="button" id="use_system_quantity" class="btn btn-small btn-secondary" disabled>Use system quantity</button>
                        </div>

                        </section>
                        <aside class="count-review" aria-label="Count summary">
                        <h3>Count summary</h3>
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
                            <button type="submit" class="btn btn-block" id="save_count" disabled>Save Count</button>
                        </aside>
                        </div>
                        </form>
                        <?php else: ?>
                        </aside>
                        </div>
                        </div>
                        <?php endif; ?>
                </div>

                <div class="count-panel" role="tabpanel" id="panel-recent-counts" aria-labelledby="tab-recent-counts" <?= $countView !== 'recent' ? 'hidden' : '' ?>>
                    <div class="count-history-toolbar">
                    <div class="count-panel-heading">
                        <h2>Count history</h2>
                        <span class="count-history-total"><?= number_format($totalRows) ?> <?= $totalRows === 1 ? 'count' : 'counts' ?></span>
                    </div>
                    <form method="GET" class="count-history-search" role="search">
                        <input type="hidden" name="count_view" value="recent">
                        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                        <input type="hidden" name="direction" value="<?= htmlspecialchars($direction) ?>">
                        <input type="hidden" name="length" value="<?= $pageSize ?>">
                        <div class="count-search-field">
                        <label for="count_history_search" class="sr-only">Search count history</label>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg>
                        <input type="search" name="q" id="count_history_search" placeholder="Search product or SKU" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <button type="submit" class="btn btn-secondary count-search-submit">Search</button>
                        <?php if ($search !== ''): ?><a class="count-clear-search" href="<?= htmlspecialchars($url(['q' => '', 'page' => 1])) ?>">Clear</a><?php endif; ?>
                    </form>
                    </div>
                    <?php if ($pendingTotal > 0): ?><p class="muted"><?= $pendingTotal ?> older pending count(s) need a fresh count before stock can be updated.</p><?php endif; ?>
                    <div class="table-wrap count-history-table-wrap">
                        <table class="count-history-table" data-no-smart-table data-no-ghost-rows>
                            <thead>
                                <tr>
                                    <?php foreach (['date' => 'Date & Time', 'product' => 'Product', 'system' => 'System', 'counted' => 'Counted', 'difference' => 'Difference'] as $column => $label): ?>
                                        <th scope="col" class="<?= in_array($column, ['system', 'counted', 'difference'], true) ? 'count-number' : '' ?>" aria-sort="<?= $sort === $column ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= htmlspecialchars($sortUrl($column)) ?>"><?= $label ?><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="<?= $sort === $column ? ($direction === 'asc' ? 'm7 14 5-5 5 5' : 'm7 10 5 5 5-5') : 'm8 9 4-4 4 4M8 15l4 4 4-4' ?>"/></svg></a></th>
                                    <?php endforeach; ?>
                                    <th scope="col">Reason</th>
                                    <?php foreach (['staff' => 'Counted By', 'status' => 'Status'] as $column => $label): ?>
                                        <th scope="col" aria-sort="<?= $sort === $column ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= htmlspecialchars($sortUrl($column)) ?>"><?= $label ?><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="<?= $sort === $column ? ($direction === 'asc' ? 'm7 14 5-5 5 5' : 'm7 10 5 5 5-5') : 'm8 9 4-4 4 4M8 15l4 4 4-4' ?>"/></svg></a></th>
                                    <?php endforeach; ?>
                                    <?php if ($showCountActions): ?><th scope="col">Action</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($counts): ?>
                                    <?php foreach ($counts as $count): ?>
                                        <?php $difference = (int)$count['difference_qty']; $countDate = explode(' · ', format_display_datetime($count['counted_at']), 2); ?>
                                        <tr>
                                            <td class="count-date"><span><?= htmlspecialchars($countDate[0]) ?></span><?php if (isset($countDate[1])): ?><small><?= htmlspecialchars($countDate[1]) ?></small><?php endif; ?></td>
                                            <td class="count-product-cell">
                                                <strong><?= htmlspecialchars($count['product_name']) ?></strong>
                                                <div class="count-product-meta"><span><?= htmlspecialchars($count['sku']) ?></span>
                                                <?php if (!empty($count['batch_count'])): ?>
                                                    <button type="button" class="count-batch-link btn-view-batches" data-count-id="<?= (int)$count['count_id'] ?>" aria-label="View <?= (int)$count['batch_count'] ?> batches for count #<?= (int)$count['count_id'] ?>"><?= (int)$count['batch_count'] ?> batch<?= (int)$count['batch_count'] === 1 ? '' : 'es' ?></button>
                                                <?php endif; ?>
                                                </div>
                                            </td>
                                            <?php $hasCountPack = !empty($count['product_pack_size']) && (int)$count['product_pack_size'] > 1; ?>
                                            <td class="count-number">
                                                <?= (int)$count['system_quantity'] ?>
                                                <?php if ($hasCountPack && array_key_exists('system_sealed_quantity', $count)): ?>
                                                    <br><small class="muted"><?= (int)$count['system_sealed_quantity'] ?> pk, <?= (int)$count['system_loose_quantity'] ?> loose</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="count-number count-actual">
                                                <?= (int)$count['physical_quantity'] ?>
                                                <?php if ($hasCountPack && array_key_exists('physical_sealed_quantity', $count)): ?>
                                                    <br><small class="muted"><?= (int)$count['physical_sealed_quantity'] ?> pk, <?= (int)$count['physical_loose_quantity'] ?> loose</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="count-number">
                                                <strong class="<?= $difference < 0 ? 'diff-negative' : ($difference > 0 ? 'diff-positive' : '') ?>">
                                                    <?= $difference > 0 ? '+' . $difference : $difference ?>
                                                </strong>
                                                <small class="count-difference-note"><?= $difference < 0 ? 'short' : ($difference > 0 ? 'extra' : 'matched') ?></small>
                                            </td>
                                            <td class="count-reason">
                                                <?= htmlspecialchars($count['discrepancy_reason']) ?>
                                                <?php if (!empty($count['related_adjustment_id'])): ?>
                                                    <br><small class="muted">Correction of stock issue report #<?= (int)$count['related_adjustment_id'] ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="count-staff"><?= htmlspecialchars($count['counted_by_name']) ?></td>
                                            <td>
                                                <span class="status-pill status-<?= htmlspecialchars($count['status']) ?>">
                                                    <?= htmlspecialchars(['approved' => 'Saved', 'pending' => 'Needs recount', 'rejected' => 'Closed'][$count['status']] ?? $count['status']) ?>
                                                </span>
                                            </td>
                                            <?php if ($showCountActions): ?><td>
                                                <?php if ($count['status'] === 'pending' && $canMutateInventory): ?>
                                                    <a class="btn btn-small btn-secondary" href="?recount=<?= (int)$count['count_id'] ?>">Count again</a>
                                                <?php else: ?>
                                                    <span class="muted">-</span>
                                                <?php endif; ?>
                                            </td><?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td class="count-history-empty" colspan="<?= $showCountActions ? 9 : 8 ?>"><strong><?= $search !== '' ? 'No counts match your search.' : 'No inventory counts recorded yet.' ?></strong><span><?= $search !== '' ? 'Try another product name or SKU.' : 'Saved stock counts will appear here.' ?></span></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php require __DIR__ . '/../../../backend/app/Views/table_pagination.php'; ?>
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
        const countTabs = Array.from(document.querySelectorAll('.count-tabs > [role="tab"]'));
        function selectCountTab(tab, focus = false) {
            countTabs.forEach(button => {
                const selected = button === tab;
                button.setAttribute('aria-selected', String(selected));
                button.tabIndex = selected ? 0 : -1;
                document.getElementById(button.getAttribute('aria-controls')).hidden = !selected;
            });
            const location = new URL(window.location.href);
            location.searchParams.set('count_view', tab.id === 'tab-new-count' ? 'new' : 'recent');
            window.history.replaceState(null, '', location);
            if (focus) tab.focus();
        }
        countTabs.forEach((tab, index) => {
            tab.addEventListener('click', () => selectCountTab(tab));
            tab.addEventListener('keydown', event => {
                let next;
                if (event.key === 'ArrowRight') next = (index + 1) % countTabs.length;
                if (event.key === 'ArrowLeft') next = (index + countTabs.length - 1) % countTabs.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = countTabs.length - 1;
                if (next === undefined) return;
                event.preventDefault();
                selectCountTab(countTabs[next], true);
            });
        });
        const productSelect = document.getElementById('product_id');
        const physicalInput = document.getElementById('physical_quantity');
        const useSystemQuantity = document.getElementById('use_system_quantity');
        const systemQty = document.getElementById('systemQty');
        const physicalQty = document.getElementById('physicalQty');
        const differenceQty = document.getElementById('differenceQty');
        let latestCoverageRequest = 0;

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
            if (topLevelSealedInput.value !== '' && topLevelLooseInput.value !== '') {
                physicalInput.value = (s * pSize) + l;
            } else physicalInput.value = '';
            updatePreview();
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
        let countInputMode = '';
        if (productSelect.value) fetchBatchCoverage(productSelect.value);

        window.RetailMindInventoryDrafts?.register({
            eligible: form => form.matches('.count-form, .review-form, .inline-form'),
            submissionKind: (form, action) => ({record:'saveCount'})[action] || '',
            async prepare(draft) {
                if (draft.id !== 'record:') return;
                const value = name => draft.values.find(item => item.name === name)?.value;
                const productId = value('product_id') || '';
                if (!Array.from(productSelect.options).some(option => option.value === productId)) throw new Error('The selected Product is no longer available. Your draft is still kept.');
                productSelect.value = productId;
                syncProductSearch();
                if (!await fetchBatchCoverage(productSelect.value)) throw new Error('Current batch coverage is unavailable. Your draft is still kept.');
                for (const item of draft.values.filter(item => /^batches\[\d+\]\[batch_id\]$/.test(item.name))) {
                    if (document.getElementsByName(item.name)[0]?.value !== item.value) throw new Error('Batch availability has changed. Your draft is still kept; review current stock before resuming.');
                }
                const keys = [...new Set(draft.values.map(item => item.name.match(/^batches\[(extra_\d+)\]/)?.[1]).filter(Boolean))];
                for (const key of keys) {
                    extraBatchIndex = Number(key.slice(6)) - 1;
                    addExtraBatchBtn.click();
                }
            }
        });
        function fetchBatchCoverage(productId) {
            const requestId = ++latestCoverageRequest;
            activeCoverage = null;
            document.getElementById('stock_version')?.setAttribute('value', '');
            const saveButton = document.getElementById('save_count');
            if (saveButton) saveButton.disabled = true;
            if (!productId || productId <= 0) {
                if (batchSection) batchSection.hidden = true;
                document.getElementById('count_entry_layout').hidden = true;
                document.getElementById('count_entry_placeholder').hidden = false;
                activeCoverage = null;
                return;
            }
            return fetch('inventory_counts.php?action=batch_coverage&product_id=' + encodeURIComponent(productId), {
                headers: { Accept: 'application/json' }
            })
                .then(res => res.json())
                .then(data => {
                    if (requestId !== latestCoverageRequest || String(productSelect.value) !== String(productId)) return;
                    if (!data.success || !data.coverage) throw new Error('Batch coverage unavailable');
                    activeCoverage = data.coverage;
                    renderBatchSection(data.coverage);
                    const revealEntry = document.getElementById('count_entry_layout').hidden;
                    document.getElementById('count_entry_layout').hidden = false;
                    document.getElementById('count_entry_placeholder').hidden = true;
                    document.getElementById('stock_version').value = data.coverage.stock_version;
                    productSelect.selectedOptions[0].dataset.systemQty = data.coverage.on_hand;
                    if (saveButton) saveButton.disabled = false;
                    updatePreview();
                    if (physicalInput.disabled || revealEntry) focusActualCount();
                    return data.coverage;
                })
                .catch(() => {
                    if (requestId !== latestCoverageRequest || String(productSelect.value) !== String(productId)) return;
                    activeCoverage = null;
                    document.getElementById('scan_status').textContent = 'Current stock could not be loaded. Select the product again to retry.';
                });
        }

        function focusActualCount() {
            if (document.getElementById('panel-new-count').hidden) return;
            const input = document.querySelector('#batch_reconciliation_section .batch-count-input:not([disabled])')
                || (!topLevelSealedInput.disabled ? topLevelSealedInput : physicalInput);
            input.focus();
        }

        function syncCountInputs() {
            if (!activeCoverage) return;
            const hasBatchInputs = batchSection.querySelector('.batch-count-input') !== null;
            const hasPacks = activeCoverage.has_sales_pack && !hasBatchInputs;
            const mode = hasBatchInputs ? 'batch' : (hasPacks ? 'pack' : 'piece');
            if (mode !== countInputMode && (mode !== 'piece' || countInputMode !== '')) {
                physicalInput.value = '';
                topLevelSealedInput.value = '';
                topLevelLooseInput.value = '';
            }
            countInputMode = mode;
            document.getElementById('single_quantity_group').hidden = hasBatchInputs || hasPacks;
            physicalInput.disabled = hasBatchInputs || hasPacks;
            physicalInput.required = !physicalInput.disabled;
            topLevelPackWrap.style.display = hasPacks ? 'grid' : 'none';
            [topLevelSealedInput, topLevelLooseInput].forEach(input => {
                input.disabled = !hasPacks;
                input.required = hasPacks;
            });
        }

        document.querySelector('form.count-form')?.addEventListener('submit', event => {
            if (!activeCoverage || !document.getElementById('stock_version').value) {
                event.preventDefault();
                return;
            }
            if (!window.confirm('Save this count? System: ' + systemQty.textContent + '. Actual: ' + physicalQty.textContent + '. Difference: ' + differenceQty.textContent + '. Stock and batches will be updated.')) event.preventDefault();
        });

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
                                           name="batches[${idx}][physical_sealed_quantity]" inputmode="numeric" aria-label="Packs counted, batch ${escapeHtml(bNum)}"
                                           min="0" style="width: 65px;" data-pack-size="${coverage.pack_size}"
                                           data-system-qty="${b.sealed_quantity ?? 0}"
                                           required placeholder="Count" <?= $canMutateInventory ? '' : 'readonly' ?>>
                                </div>
                                <div>
                                    <label style="font-size:10px; display:block;">Loose</label>
                                    <input type="number" class="batch-count-input batch-count-loose"
                                           name="batches[${idx}][physical_loose_quantity]" inputmode="numeric" aria-label="Loose pieces counted, batch ${escapeHtml(bNum)}"
                                           min="0" style="width: 65px;"
                                           data-system-qty="${b.loose_quantity ?? 0}"
                                           required placeholder="Count" <?= $canMutateInventory ? '' : 'readonly' ?>>
                                </div>
                                <input type="hidden" class="batch-base-qty" name="batches[${idx}][physical_quantity]" value="${b.remaining_quantity}">
                            </div>
                        `;
                    } else {
                        countedCell = `
                            <input type="hidden" name="batches[${idx}][batch_id]" value="${b.batch_id}">
                            <input type="number" class="batch-count-input batch-count-loose" name="batches[${idx}][physical_quantity]" inputmode="numeric" aria-label="Counted quantity, batch ${escapeHtml(bNum)}"
                                   min="0" style="width: 80px;" data-system-qty="${b.remaining_quantity}"
                                   required placeholder="Count" <?= $canMutateInventory ? '' : 'readonly' ?>>
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
            syncCountInputs();
            if (coverage.batches.length > 0 || coverage.has_sales_pack) physicalInput.value = '';
            updatePreview();
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
            const complete = Array.from(batchSection.querySelectorAll('.batch-count-input')).every(input => input.value !== '');
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
            physicalInput.value = hasAny && complete ? sum : '';
            updatePreview();
        }

        if (addExtraBatchBtn) {
            addExtraBatchBtn.addEventListener('click', function () {
                if (!activeCoverage) return;
                if (!batchSection.querySelector('.batch-count-input') && physicalInput.value !== ''
                    && !window.confirm('Switch to counting by batch? Enter the actual quantities in the found batch instead.')) return;
                const key = 'extra_' + (++extraBatchIndex);
                const div = document.createElement('div');
                div.className = 'extra-batch-row';
                div.style.cssText = 'border:1px dashed #ccc; padding:10px; margin-bottom:8px; border-radius:4px; background:rgba(0,0,0,0.02);';
                let batchFields = '';
                if (activeCoverage && activeCoverage.has_sales_pack) {
                    batchFields = `
                        <div class="form-group" style="margin-bottom:4px;">
                            <label style="font-size:12px;">Sealed Packs (x ${activeCoverage.pack_size})</label>
                            <input type="number" class="batch-count-input batch-count-sealed" name="batches[${key}][physical_sealed_quantity]" inputmode="numeric" aria-label="Found batch sealed packs" min="0" required placeholder="Count" data-pack-size="${activeCoverage.pack_size}" style="width:100%;">
                        </div>
                        <div class="form-group" style="margin-bottom:4px;">
                            <label style="font-size:12px;">Loose Pieces</label>
                            <input type="number" class="batch-count-input batch-count-loose" name="batches[${key}][physical_loose_quantity]" inputmode="numeric" aria-label="Found batch loose pieces" min="0" required placeholder="Count" style="width:100%;">
                        </div>
                        <input type="hidden" class="batch-base-qty" name="batches[${key}][physical_quantity]" inputmode="numeric" aria-label="Found batch counted quantity" value="0">
                    `;
                } else {
                    batchFields = `
                        <div class="form-group" style="margin-bottom:4px; grid-column: 1 / -1;">
                            <label style="font-size:12px;">Counted Quantity *</label>
                            <input type="number" class="batch-count-input batch-count-loose" name="batches[${key}][physical_quantity]" inputmode="numeric" aria-label="Found batch counted quantity" min="1" required style="width:100%;">
                        </div>
                    `;
                }
                div.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <strong>Found batch</strong>
                        <button type="button" class="btn btn-small btn-secondary remove-batch-btn" style="padding:1px 6px;">&times; Remove</button>
                    </div>
                    <div class="count-found-fields">
                        ${batchFields}
                        <div class="form-group" style="margin-bottom:4px;">
                            <label style="font-size:12px;">Expiration date (optional)</label>
                            <input type="date" name="batches[${key}][expiration_date]" aria-label="Found batch expiration date" style="width:100%;">
                        </div>
                        <div class="form-group" style="margin-bottom:4px;">
                            <label style="font-size:12px;">Batch number / label (optional)</label>
                            <input type="text" name="batches[${key}][batch_number]" autocomplete="off" aria-label="Found batch number or label" placeholder="e.g. FOUND-01" style="width:100%;">
                        </div>
                        <div class="form-group" style="margin-bottom:4px; grid-column: 1 / -1;">
                            <label style="font-size:12px;">Notes (optional)</label>
                            <input type="text" name="batches[${key}][notes]" autocomplete="off" aria-label="Found batch notes" placeholder="e.g. Unbatched shelf stock" style="width:100%;">
                        </div>
                    </div>
                `;
                div.querySelector('.remove-batch-btn').addEventListener('click', function () {
                    div.remove();
                    syncCountInputs();
                    recomputeTotalFromBatches();
                });
                extraBatchesList.appendChild(div);
                attachBatchInputListeners();
                syncCountInputs();
                recomputeTotalFromBatches();
            });
        }

        // View batches in recent counts
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-view-batches');
            if (!btn) return;
            const countId = btn.dataset.countId;
            if (!countId || !batchDetailModal) return;
            batchDetailTitle.textContent = 'Batches for Count #' + countId;
            batchDetailBody.innerHTML = '<p class="muted">Loading batch details…</p>';
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
                        setScanStatus('Matched by ' + (kindLabels[body.matched_code_kind] || 'code') + '. Enter the actual count.', 'ok');
                        if (!physicalInput.disabled) physicalInput.focus();
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
