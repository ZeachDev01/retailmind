<?php
// cashier/stock_issues.php — Report Stock Issue (tickets #29 + #30).
//
// Cashiers with an active shift report damaged / missing / expired / other
// stock discrepancies as pending reports. Submission never changes inventory.
// Product selection uses barcode scan or searchable lookup (no full dropdown).
// Own report history is viewable without an active shift. Own pending reports
// can be edited/cancelled; own returned reports can be edited/resubmitted.
// Approved and rejected reports are permanently locked.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/StockIssueService.php';
require_role(['cashier']);

$stockIssueService = new StockIssueService($pdo, null, (string)current_role());
$message = '';
$error = '';

$openShift = $stockIssueService->getOpenShift((int)$_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    try {
        $correctionAction = $_POST['correction_action'] ?? 'submit';
        $cashierId = (int)$_SESSION['user_id'];
        if ($correctionAction === 'edit') {
            $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);
            $stockIssueService->editReport($adjustmentId, $cashierId, [
                'category' => $_POST['category'] ?? '',
                'quantity' => $_POST['quantity'] ?? 0,
                'form' => $_POST['form'] ?? 'piece',
                'explanation' => $_POST['explanation'] ?? '',
            ]);
            $message = "Report #{$adjustmentId} updated.";
            log_activity($pdo, $cashierId, 'Stock issue edited', 'Inventory Adjustments', $adjustmentId, ['status' => 'pending/returned'], ['status' => 'pending/returned']);
        } elseif ($correctionAction === 'cancel') {
            $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);
            $stockIssueService->cancelReport($adjustmentId, $cashierId);
            $message = "Report #{$adjustmentId} cancelled.";
            log_activity($pdo, $cashierId, 'Stock issue cancelled', 'Inventory Adjustments', $adjustmentId, ['status' => 'pending'], ['status' => 'cancelled']);
        } elseif ($correctionAction === 'resubmit') {
            $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);
            $stockIssueService->resubmitReport($adjustmentId, $cashierId);
            $message = "Report #{$adjustmentId} resubmitted for review.";
            log_activity($pdo, $cashierId, 'Stock issue resubmitted', 'Inventory Adjustments', $adjustmentId, ['status' => 'returned'], ['status' => 'pending']);
        } else {
            if (!$stockIssueService->getOpenShift($cashierId)) {
                throw new RuntimeException('An active cashier shift is required to submit a stock issue report.');
            }
            $adjustmentId = $stockIssueService->submitReport([
                'product_id' => $_POST['product_id'] ?? 0,
                'category' => $_POST['category'] ?? '',
                'quantity' => $_POST['quantity'] ?? 0,
                'form' => $_POST['form'] ?? 'piece',
                'explanation' => $_POST['explanation'] ?? '',
            ], $cashierId);

            $message = "Report #{$adjustmentId} submitted for review.";
            log_activity(
                $pdo,
                $cashierId,
                'Stock issue reported',
                'Inventory Adjustments',
                (int)$adjustmentId,
                null,
                ['adjustment_id' => (int)$adjustmentId, 'status' => 'pending']
            );
        }
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The stock report could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.');
    }
}

$history = $stockIssueService->getReportsByCashier((int)$_SESSION['user_id']);
$productsApiUrl = app_url('components/barcodeScanner/apiScanner/products.php');

$counts = [
    'all' => count($history),
    'pending' => 0,
    'returned' => 0,
    'approved' => 0,
    'rejected' => 0,
    'cancelled' => 0,
];
foreach ($history as $hItem) {
    $st = $hItem['status'] ?? '';
    if (isset($counts[$st])) {
        $counts[$st]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Stock Issue</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier-pages.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/cashier-pages.css')) ?>">
</head>

<body class="cashier-stock-issues-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <div class="topbar stock-issues-topbar">
                <div class="stock-issues-header-info">
                    <div class="stock-issues-title-row">
                        <h1><i class="bi bi-clipboard2-x" aria-hidden="true"></i> Report Stock Issue</h1>
                        <?php if ($openShift): ?>
                            <span class="stock-shift-badge stock-shift-badge--active" title="Open shift #<?= (int)$openShift['shift_id'] ?>">
                                <span class="stock-shift-dot" aria-hidden="true"></span> Shift #<?= (int)$openShift['shift_id'] ?> Active
                            </span>
                        <?php else: ?>
                            <span class="stock-shift-badge stock-shift-badge--inactive">
                                <i class="bi bi-exclamation-circle" aria-hidden="true"></i> Shift Required
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="page-subtitle">Record damaged, missing, or expired stock for manager review.</p>
                </div>
                <div class="stock-issues-topbar-actions">
                    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i> Return to POS
                    </a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message success" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php foreach ([[$message, 'success'], [$error, 'error']] as [$notice, $noticeType]): ?>
                <?php if ($notice !== ''): ?>
                    <script>document.addEventListener('DOMContentLoaded', () => RetailMindUI.toast(<?= json_encode($notice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($noticeType) ?>, undefined, 4300));</script>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (!$openShift): ?>
                <div class="workflow-notice stock-shift-notice" role="note" data-rm-alert-processed="1">
                    <div class="stock-shift-notice-icon" aria-hidden="true">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                    <div class="stock-shift-notice-content">
                        <h2>Shift Required</h2>
                        <p>Start a shift to submit new reports. Existing reports remain viewable below.</p>
                    </div>
                    <a class="btn" href="<?= htmlspecialchars(app_url('components/cashier/shifts.php')) ?>">
                        <i class="bi bi-clock-history" aria-hidden="true"></i> Open Shift
                    </a>
                </div>
            <?php endif; ?>

            <div class="report-form stock-issue-card">
                <div class="stock-card-header">
                    <h2>New Report</h2>
                </div>
                <form method="POST" id="stock-issue-form" <?= $openShift ? '' : 'aria-disabled="true"' ?>>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="product_id" id="product_id" value="">

                    <div class="form-section stock-step-section">
                        <div class="stock-step-header">
                            <h3>Product</h3>
                        </div>
                        <div class="form-grid stock-inputs-grid">
                            <div class="form-group">
                                <label for="barcode-input"><i class="bi bi-upc-scan" aria-hidden="true"></i> Barcode</label>
                                <div class="input-with-icon">
                                    <i class="bi bi-upc-scan input-icon" aria-hidden="true"></i>
                                    <input type="text" id="barcode-input" placeholder="Scan or enter barcode" autocomplete="off" <?= $openShift ? '' : 'disabled' ?>>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="product-search"><i class="bi bi-search" aria-hidden="true"></i> Search</label>
                                <div class="input-with-icon">
                                    <i class="bi bi-search input-icon" aria-hidden="true"></i>
                                    <input type="search" id="product-search" list="stock-issue-products" placeholder="Name, barcode, or SKU" autocomplete="off" <?= $openShift ? '' : 'disabled' ?>>
                                </div>
                                <datalist id="stock-issue-products"></datalist>
                            </div>
                        </div>
                        <div id="product-results" class="search-results" aria-live="polite"></div>
                        <div id="selected-product" class="adjustment-card selected-product-box" role="status" hidden>
                            <div class="selected-product-main">
                                <div class="selected-product-avatar" aria-hidden="true">
                                    <i class="bi bi-box-seam-fill"></i>
                                </div>
                                <div class="selected-product-meta">
                                    <div class="adjustment-product" id="selected-product-name"></div>
                                    <div class="adjustment-sku-row">
                                        <span class="adjustment-sku-chip"><i class="bi bi-upc" aria-hidden="true"></i> <span id="selected-product-code"></span></span>
                                    </div>
                                </div>
                                <div class="selected-product-actions">
                                    <div class="stock-level-chip" id="selected-product-stock"></div>
                                    <button type="button" class="btn btn-secondary" id="clear-product">
                                        <i class="bi bi-x-circle" aria-hidden="true"></i> Change
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section stock-step-section">
                        <div class="stock-step-header">
                            <h3>Issue Details</h3>
                        </div>
                        <div class="form-grid stock-details-grid">
                            <div class="form-group">
                                <label for="category" class="required">Issue type</label>
                                <select name="category" id="category" required <?= $openShift ? '' : 'disabled' ?>>
                                    <option value="">Select type</option>
                                    <option value="damaged">Damaged</option>
                                    <option value="missing">Missing / Lost</option>
                                    <option value="expired">Expired</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="form" class="required">Stock form</label>
                                <select name="form" id="form" required <?= $openShift ? '' : 'disabled' ?>>
                                    <option value="piece">Loose pieces</option>
                                    <option value="pack" id="form-option-pack" disabled>Sealed packs</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="quantity" class="required">Quantity</label>
                                <input type="number" name="quantity" id="quantity" min="1" step="1" placeholder="1" required <?= $openShift ? '' : 'disabled' ?>>
                            </div>
                        </div>
                        <div class="form-group stock-explanation-group">
                            <label for="explanation" class="required">Reason</label>
                            <textarea name="explanation" id="explanation" placeholder="Reason for discrepancy..." required <?= $openShift ? '' : 'disabled' ?>></textarea>
                        </div>
                    </div>

                    <div class="stock-form-footer">
                        <button type="submit" class="btn-submit stock-submit-btn" <?= $openShift ? '' : 'disabled' ?>>
                            <i class="bi bi-send-fill" aria-hidden="true"></i> Submit Report
                        </button>
                    </div>
                </form>
            </div>

            <?php if (!empty($history)): ?>
                <div class="history-section u-mt-2 stock-history-workspace">
                    <div class="stock-history-header">
                        <h2><i class="bi bi-journal-text" aria-hidden="true"></i> Report History</h2>
                    </div>

                    <div class="tabs stock-filter-tabs" role="group" aria-label="Filter report status">
                        <button type="button" class="tab-link stock-tab-pill active" aria-pressed="true" onclick="filterByStatus('all', event)">
                            All <span class="stock-tab-count"><?= (int)$counts['all'] ?></span>
                        </button>
                        <button type="button" class="tab-link stock-tab-pill" aria-pressed="false" onclick="filterByStatus('pending', event)">
                            Pending <span class="stock-tab-count"><?= (int)$counts['pending'] ?></span>
                        </button>
                        <button type="button" class="tab-link stock-tab-pill" aria-pressed="false" onclick="filterByStatus('returned', event)">
                            Returned <span class="stock-tab-count"><?= (int)$counts['returned'] ?></span>
                        </button>
                        <button type="button" class="tab-link stock-tab-pill" aria-pressed="false" onclick="filterByStatus('approved', event)">
                            Approved <span class="stock-tab-count"><?= (int)$counts['approved'] ?></span>
                        </button>
                        <button type="button" class="tab-link stock-tab-pill" aria-pressed="false" onclick="filterByStatus('rejected', event)">
                            Rejected <span class="stock-tab-count"><?= (int)$counts['rejected'] ?></span>
                        </button>
                        <button type="button" class="tab-link stock-tab-pill" aria-pressed="false" onclick="filterByStatus('cancelled', event)">
                            Cancelled <span class="stock-tab-count"><?= (int)$counts['cancelled'] ?></span>
                        </button>
                    </div>

                    <div id="report-filter-status" class="stock-empty-filter" role="status" hidden>
                        <div class="stock-empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></div>
                        <p>No reports in this status.</p>
                    </div>
                    <div id="adjustments-list" class="stock-cards-list">
                        <?php foreach ($history as $adj): ?>
                            <div class="adjustment-card stock-report-card status-<?= htmlspecialchars($adj['status']) ?>" data-status="<?= htmlspecialchars($adj['status']) ?>">
                                <div class="stock-report-top">
                                    <div class="stock-report-primary">
                                        <div class="stock-report-title-row">
                                            <span class="adjustment-product"><?= htmlspecialchars($adj['product_name']) ?></span>
                                            <span class="stock-sku-badge"><i class="bi bi-upc" aria-hidden="true"></i> <?= htmlspecialchars($adj['sku']) ?></span>
                                        </div>
                                        <div class="stock-type-tag stock-type-<?= htmlspecialchars($adj['adjustment_type']) ?>">
                                            <?php
                                            $typeIcons = [
                                                'damaged' => 'bi-exclamation-octagon',
                                                'missing' => 'bi-search',
                                                'expired' => 'bi-calendar-x',
                                                'other' => 'bi-question-circle',
                                            ];
                                            $iconClass = $typeIcons[$adj['adjustment_type']] ?? 'bi-tag';
                                            ?>
                                            <i class="bi <?= $iconClass ?>" aria-hidden="true"></i>
                                            <?= htmlspecialchars(ucfirst($adj['adjustment_type'])) ?>
                                        </div>
                                    </div>
                                    <div class="stock-report-aside">
                                        <div class="stock-qty-chip">
                                            <span class="stock-qty-num"><?= htmlspecialchars(StockIssueService::formatIssueQuantity($adj)) ?></span>
                                        </div>
                                        <span class="stock-status-badge status-badge-<?= htmlspecialchars($adj['status']) ?>">
                                            <?php
                                            $statusIcons = [
                                                'pending' => 'bi-clock-fill',
                                                'returned' => 'bi-arrow-return-left',
                                                'approved' => 'bi-check-circle-fill',
                                                'rejected' => 'bi-x-circle-fill',
                                                'cancelled' => 'bi-slash-circle',
                                            ];
                                            $sIcon = $statusIcons[$adj['status']] ?? 'bi-circle';
                                            ?>
                                            <i class="bi <?= $sIcon ?>" aria-hidden="true"></i>
                                            <?= htmlspecialchars(ucfirst($adj['status'])) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="stock-report-meta-row">
                                    <span class="meta-item"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= htmlspecialchars(format_display_datetime($adj['reported_at'])) ?></span>
                                    <span class="meta-item"><i class="bi bi-hash" aria-hidden="true"></i> Report #<?= (int)$adj['adjustment_id'] ?></span>
                                    <span class="meta-item"><i class="bi bi-person-badge" aria-hidden="true"></i> Shift #<?= (int)$adj['shift_id'] ?></span>
                                    <?php if ($adj['status'] !== 'pending' && !empty($adj['approved_at'])): ?>
                                        <span class="meta-item meta-reviewer"><i class="bi bi-person-check" aria-hidden="true"></i> Reviewed by <?= htmlspecialchars($adj['reviewer_name'] ?? 'Manager') ?> at <?= htmlspecialchars(format_display_datetime($adj['approved_at'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($adj['reason']): ?>
                                    <div class="stock-report-reason">
                                        <p class="stock-reason-text"><?= htmlspecialchars($adj['reason']) ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($adj['review_notes'])): ?>
                                    <div class="stock-reviewer-note <?= $adj['status'] === 'returned' ? 'is-returned-note' : '' ?>">
                                        <span class="stock-reviewer-label"><i class="bi bi-reply-fill" aria-hidden="true"></i> Reviewer note:</span>
                                        <p class="stock-reviewer-text"><?= htmlspecialchars($adj['review_notes']) ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if (in_array($adj['status'], ['pending', 'returned'], true)): ?>
                                    <details class="stock-edit-details" <?= $adj['status'] === 'returned' ? 'open' : '' ?>>
                                        <summary class="stock-edit-summary">
                                            <span><i class="bi bi-pencil-square" aria-hidden="true"></i> <?= $adj['status'] === 'returned' ? 'Edit & Resubmit' : 'Edit Report' ?></span>
                                            <span class="stock-edit-badge"><?= $adj['status'] === 'returned' ? 'Action Required' : 'Editable' ?></span>
                                        </summary>
                                        <form method="POST" class="review-form stock-inline-edit-form">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                            <input type="hidden" name="adjustment_id" value="<?= (int)$adj['adjustment_id'] ?>">
                                            <div class="form-grid stock-edit-grid">
                                                <div class="form-group">
                                                    <label for="edit-category-<?= (int)$adj['adjustment_id'] ?>">Issue type</label>
                                                    <select name="category" id="edit-category-<?= (int)$adj['adjustment_id'] ?>">
                                                        <?php foreach (['damaged' => 'Damaged', 'missing' => 'Missing / Lost', 'expired' => 'Expired', 'other' => 'Other'] as $value => $label): ?>
                                                            <option value="<?= $value ?>" <?= $adj['adjustment_type'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label for="edit-form-<?= (int)$adj['adjustment_id'] ?>">Stock form</label>
                                                    <select name="form" id="edit-form-<?= (int)$adj['adjustment_id'] ?>">
                                                        <option value="piece" <?= ($adj['form'] ?? 'piece') === 'piece' ? 'selected' : '' ?>>Loose pieces</option>
                                                        <?php if (!empty($adj['pack_size']) && (int)$adj['pack_size'] > 1): ?>
                                                            <option value="pack" <?= ($adj['form'] ?? '') === 'pack' ? 'selected' : '' ?>>Sealed packs (Pack of <?= (int)$adj['pack_size'] ?>)</option>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label for="edit-qty-<?= (int)$adj['adjustment_id'] ?>">Quantity</label>
                                                    <input type="number" name="quantity" id="edit-qty-<?= (int)$adj['adjustment_id'] ?>" min="1" step="1" value="<?= (int)($adj['reported_qty'] ?: abs((int)$adj['adjustment_qty'])) ?>" required>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit-expl-<?= (int)$adj['adjustment_id'] ?>">Reason</label>
                                                <textarea name="explanation" id="edit-expl-<?= (int)$adj['adjustment_id'] ?>" required><?= htmlspecialchars($adj['reason'] ?? '') ?></textarea>
                                            </div>
                                            <div class="review-actions stock-edit-actions">
                                                <button type="submit" name="correction_action" value="edit" class="btn-submit">
                                                    <i class="bi bi-check-lg" aria-hidden="true"></i> Save Changes
                                                </button>
                                                <?php if ($adj['status'] === 'pending'): ?>
                                                    <button type="submit" name="correction_action" value="cancel" class="btn btn-secondary" onclick="return confirm('Cancel this report?')">
                                                        <i class="bi bi-trash" aria-hidden="true"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($adj['status'] === 'returned'): ?>
                                                    <button type="submit" name="correction_action" value="resubmit" class="btn-submit btn-resubmit">
                                                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Resubmit for Review
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        const productsApiUrl = <?= json_encode($productsApiUrl) ?>;
        const barcodeInput = document.getElementById('barcode-input');
        const searchInput = document.getElementById('product-search');
        const resultsBox = document.getElementById('product-results');
        const productIdField = document.getElementById('product_id');
        const selectedCard = document.getElementById('selected-product');
        let searchTimer = null;
        const productSuggestions = document.getElementById('stock-issue-products');
        let matchingProducts = [];

        function updateFormSelect(packSize) {
            const formSelect = document.getElementById('form');
            const packOpt = document.getElementById('form-option-pack');
            if (!formSelect || !packOpt) return;
            if (packSize && packSize > 1) {
                packOpt.disabled = false;
                packOpt.textContent = 'Sealed packs (Pack of ' + packSize + ')';
            } else {
                packOpt.disabled = true;
                packOpt.textContent = 'Sealed packs';
                formSelect.value = 'piece';
            }
        }

        function selectProduct(product, scannedCode = '') {
            RetailMindUI.closeSearchDropdown(searchInput);
            productIdField.value = product.product_id;
            document.getElementById('selected-product-name').textContent = product.name || product.product_name;
            document.getElementById('selected-product-code').textContent = product.sku || product.barcode || '';
            document.getElementById('selected-product-stock').textContent = 'Stock ' + (product.quantity_on_hand ?? '?');
            selectedCard.hidden = false;
            resultsBox.innerHTML = '';
            const pSize = Number(product.pack_size || 0);
            updateFormSelect(pSize);
            const formSelect = document.getElementById('form');
            if (formSelect && scannedCode && product.pack_barcode && scannedCode === product.pack_barcode && pSize > 1) {
                formSelect.value = 'pack';
            } else if (formSelect) {
                formSelect.value = 'piece';
            }
            selectedCard.scrollIntoView({
                block: 'nearest'
            });
        }

        document.getElementById('clear-product').addEventListener('click', () => {
            productIdField.value = '';
            selectedCard.hidden = true;
            updateFormSelect(0);
        });

        function renderResults(products, emptyMessage) {
            matchingProducts = products;
            productSuggestions.replaceChildren();
            productSuggestions.dataset.emptyMessage = emptyMessage;
            products.forEach(product => {
                const option = document.createElement('option');
                option.value = (product.sku || product.barcode || 'No code') + ' — ' + product.name + ' (Stock ' + Number(product.quantity_on_hand || 0) + ')';
                option.label = [option.value, product.barcode, product.case_barcode, product.pack_barcode].filter(Boolean).join(' ');
                productSuggestions.append(option);
            });
            resultsBox.textContent = products.length ? '' : emptyMessage;
            RetailMindUI.showSearchDropdown(searchInput);
        }

        searchInput.addEventListener('change', () => {
            const index = Array.from(productSuggestions.options).findIndex(option => option.value === searchInput.value);
            if (index < 0) return;
            clearTimeout(searchTimer);
            selectProduct(matchingProducts[index]);
        });

        let lookupAbortController = null;
        async function lookup(url, emptyMessage, selectMatch = false, scannedCode = '') {
            if (lookupAbortController) lookupAbortController.abort();
            lookupAbortController = new AbortController();
            try {
                const response = await fetch(url, { signal: lookupAbortController.signal });
                const data = await response.json();
                const products = response.ok && data.success ? (data.products || []) : [];
                if (selectMatch && products.length === 1) selectProduct(products[0], scannedCode);
                else renderResults(products, products.length > 1 ? 'More than one product matches. Select the correct product.' : emptyMessage);
            } catch (error) {
                if (error && error.name === 'AbortError') return;
                renderResults([], 'Unable to load products.');
            }
        }

        function scanStockIssueCode(code) {
            if (barcodeInput.disabled || !code) return;
            clearTimeout(searchTimer);
            searchInput.value = '';
            productIdField.value = '';
            selectedCard.hidden = true;
            return lookup(productsApiUrl + '?code=' + encodeURIComponent(code) + '&limit=5', 'No active product with available stock matches that barcode.', true, code);
        }
        barcodeInput.addEventListener('keydown', event => {
            if (event.key !== 'Enter' || event.ctrlKey || event.metaKey || event.altKey || event.isComposing) return;
            event.preventDefault();
            if (event.repeat) return;
            const code = barcodeInput.value.trim();
            barcodeInput.value = '';
            scanStockIssueCode(code);
        });

        let stockIssueBarcode = '';
        let stockIssueBarcodeAt = 0;
        function resetStockIssueBarcode() {
            stockIssueBarcode = '';
            stockIssueBarcodeAt = 0;
        }
        document.addEventListener('keydown', event => {
            if (barcodeInput.disabled || event.defaultPrevented || event.isComposing || event.repeat
                || event.ctrlKey || event.metaKey || event.altKey || document.hidden
                || event.target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"]')
                || document.querySelector('dialog[open], [aria-modal="true"].open, .rm-modal-overlay.open, .command-overlay.open, .swal2-container')) {
                resetStockIssueBarcode();
                return;
            }
            const now = performance.now();
            // shortcut: scanners must send characters within 80ms; tune for slower hardware if needed.
            if (now - stockIssueBarcodeAt > 80) resetStockIssueBarcode();
            if (event.key === 'Enter') {
                const code = stockIssueBarcode;
                resetStockIssueBarcode();
                if (code.length < 3) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                scanStockIssueCode(code);
            } else if (event.key.length === 1 && /^[\x21-\x7E]$/.test(event.key)) {
                stockIssueBarcode += event.key;
                stockIssueBarcodeAt = now;
                if (stockIssueBarcode.length > 255) resetStockIssueBarcode();
            } else if (event.key !== 'Shift') resetStockIssueBarcode();
        }, true);
        window.addEventListener('blur', resetStockIssueBarcode);
        document.addEventListener('visibilitychange', resetStockIssueBarcode);

        searchInput.addEventListener('input', event => {
            if (event.detail === 'search-dropdown') return;
            clearTimeout(searchTimer);
            if (lookupAbortController) lookupAbortController.abort();
            productSuggestions.replaceChildren();
            productIdField.value = '';
            selectedCard.hidden = true;
            searchTimer = setTimeout(() => {
                const term = searchInput.value.trim();
                if (term === '') {
                    resultsBox.innerHTML = '';
                    return;
                }
                lookup(productsApiUrl + '?q=' + encodeURIComponent(term) + '&limit=12', 'No matching active product was found.');
            }, 250);
        });

        document.getElementById('stock-issue-form').addEventListener('submit', event => {
            if (productIdField.value === '') {
                event.preventDefault();
                RetailMindUI.toast('Select a product by barcode scan or search first.', 'error');
            }
        });

        function filterByStatus(status, event) {
            document.querySelectorAll('.tab-link').forEach(btn => {
                btn.classList.remove('active');
                btn.setAttribute('aria-pressed', 'false');
            });
            if (event && event.target) {
                const activeBtn = event.target.closest('.tab-link') || event.target;
                activeBtn.classList.add('active');
                activeBtn.setAttribute('aria-pressed', 'true');
            }
            const cards = [...document.querySelectorAll('[data-status]')];
            cards.forEach(card => {
                card.hidden = status !== 'all' && card.getAttribute('data-status') !== status;
            });
            document.getElementById('report-filter-status').hidden = cards.some(card => !card.hidden);
        }
    </script>
</body>

</html>
