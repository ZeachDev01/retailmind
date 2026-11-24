<?php
// components/invoice/sales.php - receipt history and customer reprint workflows
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Receipts\ReceiptTableService;

require_capability(RoleCapabilityPolicy::VIEW_SALES_HISTORY);

$action = $_GET['action'] ?? (isset($_GET['reprint']) ? 'reprint' : (isset($_GET['thermal']) ? 'thermal' : 'list'));
if (!in_array($action, ['list', 'view', 'data', 'reprint', 'thermal', 'refund_conditions'], true)) {
    $action = 'list';
}
require __DIR__ . '/../../../backend/app/Views/sale_receipt_support.php';

// Operational return history is loaded separately from the immutable printed receipt.
if ($action === 'refund_conditions') {
    header('Cache-Control: no-store');
    $conditionService = new \App\Services\RefundReturnConditionService($pdo);
    $actorId = (int)$_SESSION['user_id'];
    $actorRole = (string)current_role();
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf_token($_POST['csrf_token'] ?? '');
            $saved = $conditionService->correctSubmission($actorId, $actorRole, $_POST);
            $sale_id = (int)$saved['sale_id'];
            $conditionConfirmation = $saved['confirmation'];
        }
        if (!(new \App\Receipts\ReceiptEngine($pdo))->fetchSale($sale_id, $storeId)) throw new DomainException('Receipt not found in the authorized Store.');
        $refundQuery = $pdo->prepare('SELECT refund_id FROM cash_refunds WHERE sale_id=?' . ($actorRole==='cashier'?' AND cashier_id=?':'') . ' ORDER BY refund_id');
        $refundQuery->execute($actorRole==='cashier'?[$sale_id,$actorId]:[$sale_id]);
        $refundIds = $refundQuery->fetchAll(PDO::FETCH_COLUMN);
        if (!$refundIds) echo '<p>No Cash Refund details are available in this Workspace.</p>';
        $returnedRefunds = [];
        foreach ($refundIds as $refundId) $returnedRefunds[(int)$refundId] = $conditionService->details($actorId, $actorRole, (int)$refundId);
        if (isset($conditionConfirmation)) echo '<p role="status">' . htmlspecialchars($conditionConfirmation) . '</p>';
        foreach ($returnedRefunds as $refundId => $conditionLines) {
            echo '<h3>Cash Refund #' . (int)$refundId . '</h3>';
            require __DIR__ . '/../../../backend/app/Views/refund_return_conditions.php';
        }
        if ($refundIds) echo '<p><a href="' . htmlspecialchars(app_url('components/cashier/refund_corrections.php')) . '">View linked Refund Correction Reviews</a></p>';
    } catch (DomainException | InvalidArgumentException $e) {
        http_response_code($_SERVER['REQUEST_METHOD']==='POST'?422:403);
        echo '<p role="alert">' . htmlspecialchars($e->getMessage()) . '</p>';
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<p role="alert">' . htmlspecialchars(\App\Support\OperatorAlert::message($e, 'The condition could not be saved. Reopen the refund details and try again.')) . '</p>';
    }
    exit;
}

if ($action === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    $request = $_GET;
    if (!$canViewAllReceipts) {
        unset($request['cashier_id']);
    }
    $cashierScope = $canViewAllReceipts ? null : (int)$_SESSION['user_id'];

    try {
        echo json_encode((new ReceiptTableService($pdo))->fetch($request, $cashierScope), JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        echo json_encode([
            'draw' => max(0, (int)($_GET['draw'] ?? 0)),
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => 'From must be on or before To.',
        ]);
    } catch (Throwable $exception) {
        error_log('Receipt table request failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode([
            'draw' => max(0, (int)($_GET['draw'] ?? 0)),
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => \App\Support\OperatorAlert::message($exception, 'Unable to load receipts. Please retry.'),
        ]);
    }
    exit;
}

$receiptCashiers = [];
if ($canViewAllReceipts) {
    $cashierStatement = $pdo->query(
        "SELECT DISTINCT u.user_id, u.display_name
         FROM users u
         JOIN sales s ON s.cashier_id = u.user_id
         WHERE 1 = " . (int)$storeId . " OR EXISTS (
             SELECT 1 FROM sale_items scope_si
             JOIN products scope_p ON scope_p.product_id = scope_si.product_id
             WHERE scope_si.sale_id = s.sale_id AND 1 = " . (int)$storeId . "
         )
         ORDER BY u.display_name, u.user_id"
    );
    $receiptCashiers = $cashierStatement->fetchAll(PDO::FETCH_ASSOC);
}

// Handle receipt view / reprint request
if (in_array($action, ['view', 'reprint', 'thermal'], true) && $sale_id > 0) {
    if (in_array($action, ['thermal'], true) && function_exists('is_remote_request') && is_remote_request()) {
        http_response_code(403);
        if (isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Thermal receipt printing is restricted to local store premises.',
            ]);
        } else {
            echo '<div class="receipt-container"><p>Thermal receipt printing is restricted to local store premises.</p></div>';
        }
        exit;
    }
    $receiptEngine = new \App\Receipts\ReceiptEngine($pdo);
    if ($receiptEngine->checkConditionalHit($sale_id, $canViewAllReceipts, (int)($_SESSION['user_id'] ?? 0), $storeId)) {
        exit;
    }

    $sale = receipt_fetch_sale($pdo, $sale_id, $storeId);

    if (!$sale) {
        http_response_code(404);
        echo '<div class="receipt-container"><p>Receipt not found</p></div>';
        exit;
    }

    if (!$canViewAllReceipts && (int)$sale['cashier_id'] !== (int)$_SESSION['user_id']) {
        http_response_code(403);
        echo '<div class="receipt-container"><p>Access denied</p></div>';
        exit;
    }

    if (receipt_send_cache_headers($sale)) {
        exit;
    }

    if (isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        receipt_render_details($sale, $sale['receipt_items'] ?? receipt_fetch_items($pdo, $sale_id), $canStartSale, false, true);
        exit;
    }

    $paperWidthMm = $receiptEngine->resolvePaperWidth((int)($_SESSION['user_id'] ?? 0), (string)current_role());
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale Receipt #<?= (int)$sale['sale_id'] ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/sale-receipt.css')) ?>">
    <script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt.js')) ?>" defer></script>
</head>
<body class="receipt-standalone-reprint">
    <?php receipt_render_details($sale, $sale['receipt_items'] ?? receipt_fetch_items($pdo, $sale_id), $canStartSale, false, false); ?>
</body>
</html>
    <?php
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt History</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/datatables/datatables.min.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/invoices.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/invoices.css')) ?>">
<?php require __DIR__ . '/../../../backend/app/Views/sale_receipt_checkout_cleanup.php'; ?>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/sale-receipt.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/sale-receipt.css')) ?>">
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt-dialog.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_url('assets/js/refund-return-conditions.js')) ?>" defer></script>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/refunds.css')) ?>">
</head>
<body class="receipts-page">
<a class="skip-link" href="#receipts-main">Skip to main content</a>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="receipts-main">
            <header class="receipt-page-heading">
                <div>
                    <h1>Receipt history</h1>
                    <p class="receipt-page-description">Find and reopen completed sales</p>
                </div>
            </header>
            <?php if ($selected_error): ?>
                <div class="alert tag-warning" role="alert"><?= htmlspecialchars($selected_error) ?></div>
            <?php endif; ?>

            <section class="receipt-history-panel" aria-label="Search receipt history">
            <div class="receipt-filter-heading">
                <h2>Filter receipts</h2>
                <div class="receipt-filter-meta">
                    <span>Dates in Philippine time &middot; Asia/Manila</span>
                    <details class="receipt-policy-help">
                        <summary class="receipt-policy-help-button" aria-label="Receipt policy" aria-describedby="receiptPolicyTooltip"><i class="bi bi-shield-check" aria-hidden="true"></i></summary>
                        <span id="receiptPolicyTooltip" class="receipt-policy-tooltip" role="tooltip">Completed receipts are saved permanently. Use the authorized Cash Refund workflow to make corrections.</span>
                    </details>
                </div>
            </div>
            <div class="receipt-filters" role="group" aria-label="Receipt filters">
                <label>
                    <span>From date</span>
                    <input type="date" id="receiptDateFrom" autocomplete="off" aria-describedby="receiptTableStatusMessage">
                </label>
                <label>
                    <span>To date</span>
                    <input type="date" id="receiptDateTo" autocomplete="off" aria-describedby="receiptTableStatusMessage">
                </label>
                <label><span>Cash Refund status</span><select id="receiptRefundStatus"><option value="">All Cash Refunds</option><option value="none">No Cash Refund</option><option value="partial">Partially refunded</option><option value="full">Fully refunded</option></select></label>
                <?php if ($canViewAllReceipts): ?>
                    <label>
                        <span>Cashier</span>
                        <select id="receiptCashier">
                            <option value="">All cashiers</option>
                            <?php foreach ($receiptCashiers as $cashier): ?>
                                <option value="<?= (int)$cashier['user_id'] ?>"><?= htmlspecialchars($cashier['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <button type="button" class="btn btn-quiet" id="clearReceiptFilters"><i class="bi bi-x-circle" aria-hidden="true"></i> Clear filters</button>
            </div>

            <div id="receiptTableStatus" class="receipt-table-status" role="status" aria-live="polite">
                <span id="receiptTableStatusMessage"></span>
                <button type="button" class="btn btn-quiet" id="retryReceiptTable" hidden>Retry</button>
            </div>
            <div id="receiptEmptyState" class="empty-state receipt-empty-state" hidden>
                <i class="bi bi-receipt receipt-empty-icon" aria-hidden="true"></i>
                <h2>No receipts have been created yet.</h2>
                <p>Complete a sale to create the first receipt.</p>
                <?php if ($canStartSale): ?>
                    <div class="empty-actions">
                        <a class="btn" href="<?= htmlspecialchars(app_url('components/cashier/pos.php?new_sale=1')) ?>">Start a Sale</a>
                    </div>
                <?php endif; ?>
            </div>
            <noscript><p class="receipt-table-status">Enable JavaScript to search receipt history and open saved receipts.</p></noscript>
            <div id="receipt-table-title" class="table-wrap receipt-table-wrap" tabindex="0" role="region" aria-label="Receipt history table and pagination">
                <table id="receiptsTable" class="receipts-table display" aria-label="Receipt History" data-no-smart-table>
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <th>Date</th>
                            <th>Cashier</th>
                            <th>Items</th>
                            <th>Original paid</th>
                            <th>Payment</th>
                            <th>Cashier Shift</th>
                            <th>Cash Refunds</th><th>Refunded</th><th>Remaining net</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <footer class="rm-table-footer" id="receipt-table-footer">
                <span id="receipt-page-summary" role="status"></span>
                <nav id="receiptPagination" class="receipt-pagination" aria-label="Receipt history pages"></nav>
            </footer>
            </section>
    </main>
</div>

<?php $receiptHistoryDrawer = true; require __DIR__ . '/../../../backend/app/Views/sale_receipt_dialog.php'; ?>

<script src="<?= htmlspecialchars(app_url('assets/vendor/datatables/datatables.min.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('receiptsTable')) {
        return;
    }

    const dateFrom = document.getElementById('receiptDateFrom');
    const dateTo = document.getElementById('receiptDateTo');
    const refundStatus = document.getElementById('receiptRefundStatus');
    const cashier = document.getElementById('receiptCashier');
    const clearFilters = document.getElementById('clearReceiptFilters');
    const status = document.getElementById('receiptTableStatus');
    const statusMessage = document.getElementById('receiptTableStatusMessage');
    const retryButton = document.getElementById('retryReceiptTable');
    const emptyState = document.getElementById('receiptEmptyState');
    const tableWrap = document.querySelector('.receipt-table-wrap');
    const stateKey = 'retailmind.receipts.' + <?= json_encode((int)$_SESSION['user_id']) ?> + '.' + window.location.pathname;
    let appliedFilters = {date_from: '', date_to: '', refund_status: '', cashier_id: ''};
    let loadFailed = false;

    function updateStatus(message, isError, canRetry) {
        statusMessage.textContent = message;
        status.classList.toggle('is-error', Boolean(isError));
        retryButton.hidden = !canRetry;
    }

    if (typeof DataTable === 'undefined') {
        updateStatus('Receipt history could not start. Reload the page to try again.', true, true);
        tableWrap.hidden = true;
        retryButton.textContent = 'Reload page';
        retryButton.addEventListener('click', function() { window.location.reload(); });
        return;
    }
    const textRenderer = DataTable.render.text();
    const pagination = document.getElementById('receiptPagination');

    function validDates() {
        const reversed = dateFrom.value && dateTo.value && dateFrom.value > dateTo.value;
        dateTo.setCustomValidity(reversed ? 'From must be on or before To.' : '');
        const valid = dateFrom.validity.valid && dateTo.validity.valid;
        dateFrom.setAttribute('aria-invalid', String(!dateFrom.validity.valid));
        dateTo.setAttribute('aria-invalid', String(!dateTo.validity.valid));
        if (!valid) updateStatus(reversed ? 'From must be on or before To. Previous results are unchanged.' : 'Enter a valid date. Previous results are unchanged.', true, false);
        return valid;
    }

    function currentFilters() {
        if (!validDates()) return appliedFilters;
        appliedFilters = {
            date_from: dateFrom.value,
            date_to: dateTo.value,
            refund_status: refundStatus.value,
            cashier_id: cashier ? cashier.value : ''
        };
        return appliedFilters;
    }

    function restoreFilters(savedState) {
        const filters = savedState && savedState.receiptFilters ? savedState.receiptFilters : {};
        dateFrom.value = filters.date_from || '';
        dateTo.value = filters.date_to || '';
        refundStatus.value = filters.refund_status || '';
        if (cashier) cashier.value = filters.cashier_id || '';
    }

    const table = new DataTable('#receiptsTable', {
        serverSide: true,
        autoWidth: false,
        columnDefs: [{targets: [6, 7, 8, 9], visible: false}],
        processing: true,
        pageLength: 15,
        lengthMenu: [10, 15, 25, 50, 100],
        order: [[1, 'desc'], [0, 'desc']],
        stateSave: true,
        stateDuration: -1,
        stateLoadParams: function(settings, data) {
            data.length = 15;
            data.start = 0;
            if (data.columns) data.columns.forEach(function(column, index) {
                column.visible = ![6, 7, 8, 9].includes(index);
            });
        },
        stateSaveCallback: function(settings, data) {
            data.receiptFilters = currentFilters();
            try {
                sessionStorage.setItem(stateKey, JSON.stringify(data));
            } catch (error) { /* Receipt lookup still works when browser storage is unavailable. */ }
        },
        stateLoadCallback: function() {
            try {
                const savedState = JSON.parse(sessionStorage.getItem(stateKey) || 'null');
                restoreFilters(savedState);
                return savedState;
            } catch (error) {
                return null;
            }
        },
        ajax: {
            url: '<?= htmlspecialchars(app_url('components/invoice/sales.php?action=data')) ?>',
            data: function(request) {
                Object.assign(request, currentFilters());
            },
            error: function() {
                loadFailed = true;
                updateStatus('Unable to load receipts. Check your connection and retry.', true, true);
                emptyState.hidden = true;
                tableWrap.hidden = false;
            }
        },
        columns: [
            {
                data: 'sale_id',
                render: function(value, type) {
                    return type === 'display' ? '<strong class="receipt-id">#' + Number(value) + '</strong>' : Number(value);
                }
            },
            { data: 'sale_date', render: function(value, type, row) { return type === 'display' ? textRenderer.display(row.sale_date_display) : value; } },
            { data: 'cashier_name', render: textRenderer },
            { data: 'item_count' },
            {
                data: 'total_amount',
                render: function(value, type) {
                    const amount = Number(value);
                    return type === 'display' ? '<strong>₱' + amount.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</strong>' : amount;
                }
            },
            {
                data: 'payment_method',
                render: function(value, type) {
                    const payment = String(value || '');
                    return type === 'display' ? textRenderer.display(payment.toUpperCase()) : payment;
                }
            },
            {
                data: 'attribution_label',
                render: function(value, type) {
                    const label = String(value || '');
                    return type === 'display'
                        ? '<span class="' + (label.indexOf('Legacy') === 0 ? 'receipt-tag-neutral' : '') + '">' + textRenderer.display(label) + '</span>'
                        : label;
                }
            },
            { data: 'refund_status', orderable: false, render: textRenderer },
            { data: 'refunded_amount', render: function(value, type) { return type === 'display' ? '₱' + Number(value).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : value; } },
            { data: 'remaining_net_amount', render: function(value, type) { return type === 'display' ? '₱' + Number(value).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : value; } },
            {
                data: 'sale_id',
                orderable: false,
                searchable: false,
                render: function(value, type) {
                    if (type !== 'display') return value;
                    const saleId = Number(value);
                    return '<div class="actions-cell">' +
                        '<button type="button" class="btn-small btn-view" data-receipt-details="' + saleId + '"><i class="bi bi-eye" aria-hidden="true"></i> Full details</button></div>';
                }
            }
        ],
        layout: {
            topStart: null,
            topEnd: [{
                search: {
                    placeholder: 'Receipt number or payment method'
                }
            }],
            bottomStart: null,
            bottomEnd: null
        },
        language: {
            processing: 'Loading receipts…',
            emptyTable: 'No receipts have been created yet.',
            zeroRecords: 'No receipts match the current search and filters.',
            search: 'Search:'
        }
    });

    const drawer = document.getElementById('receiptModal');
    const receiptPanel = drawer.querySelector('#receiptContent');
    const otherPanel = document.createElement('section');
    otherPanel.id = 'receiptOtherDetails';
    const drawerTabs = document.createElement('div');
    drawerTabs.className = 'receipt-drawer-tabs';
    drawerTabs.setAttribute('role', 'tablist');
    drawerTabs.setAttribute('aria-label', 'Receipt details');
    drawerTabs.innerHTML = '<button type="button" id="receiptPaperTab" role="tab" aria-controls="receiptContent" aria-selected="true" tabindex="0">Receipt</button>' +
        '<button type="button" id="receiptOtherTab" role="tab" aria-controls="receiptOtherDetails" aria-selected="false" tabindex="-1" disabled>Other Details</button>';
    const drawerSummary = document.createElement('dl');
    drawerSummary.className = 'receipt-drawer-summary';
    const refundConditions = document.createElement('section');
    refundConditions.className = 'refund-condition-details';
    otherPanel.append(drawerSummary, refundConditions);
    let conditionSaleId = null;
    async function loadRefundConditions(saleId) {
        conditionSaleId = saleId;
        refundConditions.textContent = 'Loading Cash Refunds…';
        try {
            const response = await fetch('?action=refund_conditions&sale_id=' + encodeURIComponent(saleId));
            const html = await response.text();
            if (conditionSaleId !== saleId) return;
            if (!response.ok) throw new Error('Unable to load returned items. Reopen the receipt details.');
            refundConditions.innerHTML = html;
            window.initRefundConditions(refundConditions);
        } catch (error) { if (conditionSaleId === saleId) refundConditions.textContent = error.message; }
    }
    refundConditions.addEventListener('submit', async function(event) {
        const form = event.target.closest('.refund-condition-form');
        if (!form) return;
        event.preventDefault();
        const saleId = conditionSaleId;
        const submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        const previousAlert = refundConditions.querySelector('[role="alert"]');
        previousAlert?.remove();
        try {
            const response = await fetch('?action=refund_conditions', {method: 'POST', body: new URLSearchParams(new FormData(form))});
            const html = await response.text();
            if (conditionSaleId !== saleId) return;
            if (response.ok) {
                refundConditions.innerHTML = html;
                window.initRefundConditions(refundConditions);
                const status = refundConditions.querySelector('[role="status"]');
                if (status) { status.tabIndex = -1; status.focus(); }
            } else {
                const alert = document.createElement('div');
                alert.innerHTML = html;
                refundConditions.prepend(alert);
                window.initRefundConditions(refundConditions);
            }
        } catch (error) {
            if (conditionSaleId === saleId) {
                const alert = document.createElement('p');
                alert.setAttribute('role', 'alert');
                alert.textContent = 'Save could not be confirmed. Reopen the receipt details before trying again.';
                refundConditions.prepend(alert);
                window.initRefundConditions(refundConditions);
            }
        }
    });
    receiptPanel.before(drawerTabs);
    receiptPanel.after(otherPanel);
    const tabs = Array.from(drawerTabs.querySelectorAll('[role="tab"]'));
    const panels = [receiptPanel, otherPanel];
    let currentReceiptTabIndex = 0;
    function selectDrawerTab(index) {
        const isForward = index >= currentReceiptTabIndex;
        tabs.forEach(function(tab, position) {
            tab.setAttribute('aria-selected', String(position === index));
            tab.tabIndex = position === index ? 0 : -1;
            const panel = panels[position];
            if (position === index) {
                panel.hidden = false;
                panel.classList.remove('anim-panel-in-right', 'anim-panel-in-left');
                panel.classList.add(isForward ? 'anim-panel-in-right' : 'anim-panel-in-left');
            } else {
                panel.hidden = true;
                panel.classList.remove('anim-panel-in-right', 'anim-panel-in-left');
            }
        });
        currentReceiptTabIndex = index;
    }
    tabs.forEach(function(tab, index) {
        panels[index].setAttribute('role', 'tabpanel');
        panels[index].setAttribute('aria-labelledby', tab.id);
        panels[index].tabIndex = 0;
        tab.addEventListener('click', function() { selectDrawerTab(index); });
        tab.addEventListener('keydown', function(event) {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : 1 - index;
            if (tabs[next].disabled) return;
            selectDrawerTab(next);
            tabs[next].focus();
        });
    });
    selectDrawerTab(0);
    document.getElementById('receiptsTable').addEventListener('click', function(event) {
        const button = event.target.closest('[data-receipt-details]');
        if (!button) return;
        const receipt = table.row(button.closest('tr')).data();
        if (!receipt) return;
        drawerSummary.replaceChildren();
        loadRefundConditions(receipt.sale_id);
        const money = function(value) {
            return '₱' + Number(value).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        };
        Object.entries({
            'Cashier shift': receipt.attribution_label,
            'Cash Refunds': receipt.refund_status,
            'Refunded': money(receipt.refunded_amount),
            'Remaining net': money(receipt.remaining_net_amount)
        }).forEach(function(entry) {
            const field = document.createElement('div');
            const label = document.createElement('dt');
            const value = document.createElement('dd');
            label.textContent = entry[0];
            value.textContent = entry[1];
            field.append(label, value);
            drawerSummary.append(field);
        });
        tabs[1].disabled = false;
        selectDrawerTab(0);
        window.viewReceipt(receipt.sale_id, button);
    });

    table.on('preXhr', function() {
        loadFailed = false;
        if (validDates()) updateStatus('Loading receipts…', false, false);
    });
    table.on('xhr', function(event, settings, json) {
        if (json && json.error) {
            loadFailed = true;
            updateStatus('Unable to load receipts. Please retry.', true, true);
        }
    });
    table.on('draw', function() {
        if (loadFailed) {
            emptyState.hidden = true;
            tableWrap.hidden = false;
            return;
        }
        if (!validDates()) return;
        const info = table.page.info();
        emptyState.hidden = info.recordsTotal !== 0;
        tableWrap.hidden = info.recordsTotal === 0;
        if (info.recordsTotal === 0) {
            updateStatus('No receipt history is available yet.', false, false);
        } else if (info.recordsDisplay === 0) {
            updateStatus('No receipts match the current search and filters.', false, false);
        } else {
            updateStatus(info.recordsDisplay + ' permitted receipt' + (info.recordsDisplay === 1 ? '' : 's') + ' found.', false, false);
        }
    });

    RetailMindUI.tablePageSize(document.getElementById('receipt-table-footer'), 15, size => table.page.len(size).draw());
    let restorePageFocus = false;
    function renderPagination() {
        const info = table.page.info();
        const totalPages = Math.max(info.pages, 1);
        document.getElementById('receipt-page-summary').textContent = `Showing ${info.recordsDisplay ? info.start + 1 : 0} to ${info.end} of ${info.recordsDisplay} entries`;
        RetailMindUI.renderPageButtons(pagination, info.page + 1, totalPages, page => {
            restorePageFocus = true;
            table.page(page - 1).draw('page');
        });
        if (restorePageFocus) {
            pagination.querySelector('[aria-current="page"]')?.focus();
            restorePageFocus = false;
        }
    }
    table.on('draw', renderPagination);
    renderPagination();

    retryButton.addEventListener('click', function() {
        if (validDates()) table.ajax.reload(null, false);
    });
    [dateFrom, dateTo, refundStatus, cashier].filter(Boolean).forEach(function(control) {
        control.addEventListener('change', function() {
            if (validDates()) table.ajax.reload(null, true);
        });
    });
    clearFilters.addEventListener('click', function() {
        dateFrom.value = '';
        dateTo.value = '';
        refundStatus.value = '';
        if (cashier) cashier.value = '';
        validDates();
        table.search('');
        table.order([[1, 'desc'], [0, 'desc']]);
        table.page.len(10);
        table.ajax.reload(null, true);
    });
});
</script>
</body>
</html>
