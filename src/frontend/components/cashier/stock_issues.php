<?php
// cashier/stock_issues.php — Report Stock Issue (ticket #29).
//
// Cashiers with an active shift report damaged / missing / expired / other
// stock discrepancies as pending reports. Submission never changes inventory.
// Product selection uses barcode scan or searchable lookup (no full dropdown).
// Own report history is viewable without an active shift.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/StockIssueService.php';
require_role(['cashier']);

$stockIssueService = new StockIssueService($pdo);
$message = '';
$error = '';

$openShift = $stockIssueService->getOpenShift((int)$_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    try {
        if (!$stockIssueService->getOpenShift((int)$_SESSION['user_id'])) {
            throw new RuntimeException('An active cashier shift is required to submit a stock issue report.');
        }
        $adjustmentId = $stockIssueService->submitReport([
            'product_id' => $_POST['product_id'] ?? 0,
            'category' => $_POST['category'] ?? '',
            'quantity' => $_POST['quantity'] ?? 0,
            'explanation' => $_POST['explanation'] ?? '',
        ], (int)$_SESSION['user_id']);

        $message = "Stock issue report #{$adjustmentId} submitted for review. Inventory is unchanged until an Inventory Manager approves it.";
        log_activity(
            $pdo,
            (int)$_SESSION['user_id'],
            'Stock issue reported',
            'Inventory Adjustments',
            (int)$adjustmentId,
            null,
            ['adjustment_id' => (int)$adjustmentId, 'status' => 'pending']
        );
    } catch (Exception $e) {
        $error = 'Could not submit the report: ' . $e->getMessage();
    }
}

$history = $stockIssueService->getReportsByCashier((int)$_SESSION['user_id']);
$productsApiUrl = app_url('components/barcodeScanner/apiScanner/products.php');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Stock Issue</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <h1>Report Stock Issue</h1>
            </div>

            <?php if ($message): ?>
                <div class="message success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!$openShift): ?>
                <div class="message warning">
                    You do not have an active cashier shift. Open a shift before reporting stock issues.
                    <a href="<?= htmlspecialchars(app_url('components/cashier/shifts.php')) ?>">Go to Cashier Shift</a>
                </div>
            <?php endif; ?>

            <div class="report-form">
                <h3>New Stock Issue Report</h3>
                <?php if ($openShift): ?>
                    <p>Reporting from shift #<?= (int)$openShift['shift_id'] ?>. Reported units stay sellable until review.</p>
                <?php endif; ?>
                <form method="POST" id="stock-issue-form" <?= $openShift ? '' : 'aria-disabled="true"' ?>>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="product_id" id="product_id" value="">

                    <div class="form-section">
                        <h4>1. Identify the product</h4>
                        <p>Scan the barcode or search by name, SKU, or barcode. Only active, sellable products can be reported.</p>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="barcode-input">Barcode scan</label>
                                <input type="text" id="barcode-input" placeholder="Scan or type a barcode, then press Enter" autocomplete="off" <?= $openShift ? '' : 'disabled' ?>>
                            </div>
                            <div class="form-group">
                                <label for="product-search">Search products</label>
                                <input type="search" id="product-search" placeholder="Type at least one letter or number" autocomplete="off" <?= $openShift ? '' : 'disabled' ?>>
                            </div>
                        </div>
                        <div id="product-results" class="search-results" aria-live="polite"></div>
                        <div id="selected-product" class="adjustment-card" hidden>
                            <div class="adjustment-header">
                                <div>
                                    <div class="adjustment-product" id="selected-product-name"></div>
                                    <div class="adjustment-sku" id="selected-product-code"></div>
                                </div>
                                <div class="u-text-right">
                                    <div class="adjustment-qty" id="selected-product-stock"></div>
                                    <button type="button" class="btn-link" id="clear-product">Change</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4>2. Describe the issue</h4>
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
                                <div class="type-info" id="type-info"></div>
                            </div>
                            <div class="form-group">
                                <label for="quantity" class="required">Quantity affected</label>
                                <input type="number" name="quantity" id="quantity" min="1" step="1" required placeholder="Units affected" <?= $openShift ? '' : 'disabled' ?>>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="explanation" class="required">Explanation</label>
                            <textarea name="explanation" id="explanation" placeholder="Describe what happened, when discovered, any relevant details..." required <?= $openShift ? '' : 'disabled' ?>></textarea>
                            <small id="explanation-hint">An explanation is always required. "Other" needs at least <?= StockIssueService::OTHER_MIN_EXPLANATION ?> characters.</small>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit" <?= $openShift ? '' : 'disabled' ?>>Submit Report</button>
                </form>
            </div>

            <?php if (!empty($history)): ?>
                <div class="history-section u-mt-2">
                    <h3>My Report History</h3>

                    <div class="tabs">
                        <button type="button" class="tab-link active" onclick="filterByStatus('all', event)">All</button>
                        <button type="button" class="tab-link" onclick="filterByStatus('pending', event)">Pending</button>
                        <button type="button" class="tab-link" onclick="filterByStatus('approved', event)">Approved</button>
                        <button type="button" class="tab-link" onclick="filterByStatus('rejected', event)">Rejected</button>
                    </div>

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
                                        <div class="adjustment-qty">-<?= abs((int)$adj['adjustment_qty']) ?></div>
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
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const productsApiUrl = <?= json_encode($productsApiUrl) ?>;
        const barcodeInput = document.getElementById('barcode-input');
        const searchInput = document.getElementById('product-search');
        const resultsBox = document.getElementById('product-results');
        const productIdField = document.getElementById('product_id');
        const selectedCard = document.getElementById('selected-product');
        const categorySelect = document.getElementById('category');
        const typeInfo = document.getElementById('type-info');
        let searchTimer = null;

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char]));
        }

        function selectProduct(product) {
            productIdField.value = product.product_id;
            document.getElementById('selected-product-name').textContent = product.name || product.product_name;
            document.getElementById('selected-product-code').textContent = product.sku || product.barcode || '';
            document.getElementById('selected-product-stock').textContent = 'Stock ' + (product.quantity_on_hand ?? '?');
            selectedCard.hidden = false;
            resultsBox.innerHTML = '';
            selectedCard.scrollIntoView({
                block: 'nearest'
            });
        }

        document.getElementById('clear-product').addEventListener('click', () => {
            productIdField.value = '';
            selectedCard.hidden = true;
        });

        function renderResults(products, emptyMessage) {
            if (!products.length) {
                resultsBox.innerHTML = '<div class="search-empty">' + escapeHtml(emptyMessage) + '</div>';
                return;
            }
            resultsBox.innerHTML = products.map((product, index) => {
                const code = product.sku || product.barcode || 'No code';
                return '<article class="search-result" data-index="' + index + '">' +
                    '<span><strong>' + escapeHtml(product.name) + '</strong>' +
                    '<span class="search-result-meta"><span>' + escapeHtml(code) + '</span>' +
                    '<span>Stock ' + Number(product.quantity_on_hand || 0) + '</span></span></span>' +
                    '<button type="button" class="btn btn-small" data-index="' + index + '">Select</button></article>';
            }).join('');
            resultsBox.querySelectorAll('button[data-index]').forEach(button => {
                button.addEventListener('click', () => selectProduct(products[Number(button.dataset.index)]));
            });
        }

        async function lookup(url, emptyMessage) {
            try {
                const response = await fetch(url);
                const data = await response.json();
                renderResults(data.success ? (data.products || []) : [], emptyMessage);
            } catch (error) {
                renderResults([], 'Unable to load products.');
            }
        }

        barcodeInput.addEventListener('keydown', event => {
            if (event.key !== 'Enter') {
                return;
            }
            event.preventDefault();
            const code = barcodeInput.value.trim();
            if (code === '') {
                return;
            }
            lookup(productsApiUrl + '?code=' + encodeURIComponent(code) + '&limit=5', 'No active product matches that barcode.');
        });

        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                const term = searchInput.value.trim();
                if (term === '') {
                    resultsBox.innerHTML = '';
                    return;
                }
                lookup(productsApiUrl + '?q=' + encodeURIComponent(term) + '&limit=12', 'No matching active product was found.');
            }, 250);
        });

        function updateTypeInfo() {
            const info = {
                damaged: 'Item physically damaged or unusable',
                missing: 'Item lost or cannot be located in inventory',
                expired: 'Item past expiration date and cannot be sold',
                other: 'Other inventory discrepancy (meaningful explanation required)'
            };
            typeInfo.textContent = info[categorySelect.value] || '';
        }
        categorySelect.addEventListener('change', updateTypeInfo);

        document.getElementById('stock-issue-form').addEventListener('submit', event => {
            if (productIdField.value === '') {
                event.preventDefault();
                alert('Select a product by barcode scan or search first.');
            }
        });

        function filterByStatus(status, event) {
            document.querySelectorAll('.tab-link').forEach(btn => btn.classList.remove('active'));
            if (event && event.target) {
                event.target.classList.add('active');
            }
            document.querySelectorAll('[data-status]').forEach(card => {
                card.style.display = (status === 'all' || card.getAttribute('data-status') === status) ? 'block' : 'none';
            });
        }
    </script>
</body>

</html>
