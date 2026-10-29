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
            $message = "Report #{$adjustmentId} updated. It keeps its current state until reviewed or resubmitted.";
            log_activity($pdo, $cashierId, 'Stock issue edited', 'Inventory Adjustments', $adjustmentId, ['status' => 'pending/returned'], ['status' => 'pending/returned']);
        } elseif ($correctionAction === 'cancel') {
            $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);
            $stockIssueService->cancelReport($adjustmentId, $cashierId);
            $message = "Report #{$adjustmentId} cancelled.";
            log_activity($pdo, $cashierId, 'Stock issue cancelled', 'Inventory Adjustments', $adjustmentId, ['status' => 'pending'], ['status' => 'cancelled']);
        } elseif ($correctionAction === 'resubmit') {
            $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);
            $stockIssueService->resubmitReport($adjustmentId, $cashierId);
            $message = "Report #{$adjustmentId} resubmitted for review. Inventory Managers were notified.";
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

            $message = "Stock issue report #{$adjustmentId} submitted for review. Inventory is unchanged until an Inventory Manager approves it.";
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
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Stock Issue</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier-pages.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/cashier-pages.css')) ?>">
</head>

<body class="cashier-stock-issues-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <?php if ($message): ?>
                <div class="message success" role="status"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!$openShift): ?>
                <div class="workflow-notice" role="note" data-rm-alert-processed="1">
                    <div>
                        <h2>Cashier Shift required</h2>
                        <p>You do not have an active cashier shift. Open a shift before reporting stock issues.</p>
                    </div>
                    <a class="btn" href="<?= htmlspecialchars(app_url('components/cashier/shifts.php')) ?>">Go to Cashier Shift</a>
                </div>
            <?php endif; ?>

            <div class="report-form">
                <h2>New Stock Issue Report</h2>
                <form method="POST" id="stock-issue-form" <?= $openShift ? '' : 'aria-disabled="true"' ?>>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="product_id" id="product_id" value="">

                    <div class="form-section">
                        <h3>1. Identify the product</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="barcode-input">Barcode scan</label>
                                <input type="text" id="barcode-input" placeholder="Scan or type a barcode, then press Enter" autocomplete="off" <?= $openShift ? '' : 'disabled' ?>>
                            </div>
                            <div class="form-group">
                                <label for="product-search">Search products</label>
                                <input type="search" id="product-search" list="stock-issue-products" placeholder="Type at least one letter or number" autocomplete="off" <?= $openShift ? '' : 'disabled' ?>>
                                <datalist id="stock-issue-products"></datalist>
                            </div>
                        </div>
                        <div id="product-results" class="search-results" aria-live="polite"></div>
                        <div id="selected-product" class="adjustment-card" role="status" hidden>
                            <div class="adjustment-header">
                                <div>
                                    <div class="adjustment-product" id="selected-product-name"></div>
                                    <div class="adjustment-sku" id="selected-product-code"></div>
                                </div>
                                <div class="selected-product-actions">
                                    <div class="adjustment-qty" id="selected-product-stock"></div>
                                    <button type="button" class="btn btn-secondary" id="clear-product">Change product</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>2. Describe the issue</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="category" class="required">Issue type</label>
                                <select name="category" id="category" required <?= $openShift ? '' : 'disabled' ?>>
                                    <option value="">-- Select Type --</option>
                                    <option value="damaged">Damaged</option>
                                    <option value="missing">Missing / Lost</option>
                                    <option value="expired">Expired</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="form" class="required">Stock form</label>
                                <select name="form" id="form" required <?= $openShift ? '' : 'disabled' ?>>
                                    <option value="piece">Loose Piece(s)</option>
                                    <option value="pack" id="form-option-pack" disabled>Sealed Pack(s)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="quantity" class="required">Quantity affected</label>
                                <input type="number" name="quantity" id="quantity" min="1" step="1" required <?= $openShift ? '' : 'disabled' ?>>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="explanation" class="required">Explanation</label>
                            <textarea name="explanation" id="explanation" placeholder="Describe what happened, when discovered, any relevant details..." required <?= $openShift ? '' : 'disabled' ?>></textarea>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit" <?= $openShift ? '' : 'disabled' ?>>Submit Report</button>
                </form>
            </div>

            <?php if (!empty($history)): ?>
                <div class="history-section u-mt-2">
                    <h2>My Report History</h2>

                    <div class="tabs" role="group" aria-label="Filter report status">
                        <button type="button" class="tab-link active" aria-pressed="true" onclick="filterByStatus('all', event)">All</button>
                        <button type="button" class="tab-link" aria-pressed="false" onclick="filterByStatus('pending', event)">Pending</button>
                        <button type="button" class="tab-link" aria-pressed="false" onclick="filterByStatus('returned', event)">Returned</button>
                        <button type="button" class="tab-link" aria-pressed="false" onclick="filterByStatus('approved', event)">Approved</button>
                        <button type="button" class="tab-link" aria-pressed="false" onclick="filterByStatus('rejected', event)">Rejected</button>
                        <button type="button" class="tab-link" aria-pressed="false" onclick="filterByStatus('cancelled', event)">Cancelled</button>
                    </div>

                    <p id="report-filter-status" role="status" hidden>No reports match this status. Choose All to see your reports.</p>
                    <div id="adjustments-list">
                        <?php foreach ($history as $adj): ?>
                            <div class="adjustment-card <?= htmlspecialchars($adj['status']) ?>" data-status="<?= htmlspecialchars($adj['status']) ?>">
                                <div class="adjustment-header">
                                    <div>
                                        <div class="adjustment-product"><?= htmlspecialchars($adj['product_name']) ?></div>
                                        <div class="adjustment-sku"><?= htmlspecialchars($adj['sku']) ?></div>
                                        <span class="adjustment-type <?= htmlspecialchars($adj['adjustment_type']) ?>">
                                            <?= htmlspecialchars(ucfirst($adj['adjustment_type'])) ?>
                                        </span>
                                    </div>
                                    <div class="u-text-right">
                                        <div class="adjustment-qty"><?= htmlspecialchars(StockIssueService::formatIssueQuantity($adj)) ?></div>
                                        <span class="adjustment-status <?= htmlspecialchars($adj['status']) ?>">
                                            <?= htmlspecialchars(ucfirst($adj['status'])) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="adjustment-details">
                                    <strong>Reported:</strong> <?= htmlspecialchars($adj['reported_at']) ?> |
                                    <strong>Report ID:</strong> #<?= (int)$adj['adjustment_id'] ?> |
                                    <strong>Shift:</strong> #<?= (int)$adj['shift_id'] ?>
                                    <?php if ($adj['status'] !== 'pending'): ?>
                                        <br><strong>Reviewed by:</strong> <?= htmlspecialchars($adj['reviewer_name'] ?? 'Unknown') ?> at <?= htmlspecialchars(format_display_datetime($adj['approved_at'])) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($adj['reason']): ?>
                                    <div class="adjustment-reason">
                                        <strong>Details:</strong> <?= htmlspecialchars($adj['reason']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($adj['review_notes'])): ?>
                                    <div class="adjustment-reason">
                                        <strong>Reviewer note:</strong> <?= htmlspecialchars($adj['review_notes']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (in_array($adj['status'], ['pending', 'returned'], true)): ?>
                                    <form method="POST" class="review-form">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                        <input type="hidden" name="adjustment_id" value="<?= (int)$adj['adjustment_id'] ?>">
                                        <div class="form-grid">
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
                                                    <option value="piece" <?= ($adj['form'] ?? 'piece') === 'piece' ? 'selected' : '' ?>>Loose Piece(s)</option>
                                                    <?php if (!empty($adj['pack_size']) && (int)$adj['pack_size'] > 1): ?>
                                                        <option value="pack" <?= ($adj['form'] ?? '') === 'pack' ? 'selected' : '' ?>>Sealed Pack(s) (Pack of <?= (int)$adj['pack_size'] ?>)</option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="edit-qty-<?= (int)$adj['adjustment_id'] ?>">Quantity</label>
                                                <input type="number" name="quantity" id="edit-qty-<?= (int)$adj['adjustment_id'] ?>" min="1" step="1" value="<?= (int)($adj['reported_qty'] ?: abs((int)$adj['adjustment_qty'])) ?>" required>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label for="edit-expl-<?= (int)$adj['adjustment_id'] ?>">Explanation</label>
                                            <textarea name="explanation" id="edit-expl-<?= (int)$adj['adjustment_id'] ?>" required><?= htmlspecialchars($adj['reason'] ?? '') ?></textarea>
                                        </div>
                                        <div class="review-actions">
                                            <button type="submit" name="correction_action" value="edit" class="btn-submit">Save Changes</button>
                                            <?php if ($adj['status'] === 'pending'): ?>
                                                <button type="submit" name="correction_action" value="cancel" class="btn btn-secondary" onclick="return confirm('Cancel this pending report?')">Cancel Report</button>
                                            <?php endif; ?>
                                            <?php if ($adj['status'] === 'returned'): ?>
                                                <button type="submit" name="correction_action" value="resubmit" class="btn-submit">Resubmit for Review</button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
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
                packOpt.textContent = 'Sealed Pack(s) (Pack of ' + packSize + ')';
            } else {
                packOpt.disabled = true;
                packOpt.textContent = 'Sealed Pack(s) (N/A)';
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
                event.target.classList.add('active');
                event.target.setAttribute('aria-pressed', 'true');
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
