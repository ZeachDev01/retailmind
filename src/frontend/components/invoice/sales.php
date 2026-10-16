<?php
// components/invoice/sales.php - receipt history and customer reprint workflows
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Receipts\ReceiptTableService;

require_capability(RoleCapabilityPolicy::VIEW_SALES_HISTORY);

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'view', 'data'], true)) {
    $action = 'list';
}
require __DIR__ . '/../../../backend/app/Views/sale_receipt_support.php';

if ($action === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    $request = $_GET;
    if (!$can_manage_all) {
        unset($request['cashier_id']);
    }
    $cashierScope = $can_manage_all ? null : (int)$_SESSION['user_id'];

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
if ($can_manage_all) {
    $cashierStatement = $pdo->query(
        "SELECT DISTINCT u.user_id, u.full_name
         FROM users u
         JOIN sales s ON s.cashier_id = u.user_id
         WHERE u.branch_id = " . (int)$storeId . " OR EXISTS (
             SELECT 1 FROM sale_items scope_si
             JOIN products scope_p ON scope_p.product_id = scope_si.product_id
             WHERE scope_si.sale_id = s.sale_id AND scope_p.branch_id = " . (int)$storeId . "
         )
         ORDER BY u.full_name, u.user_id"
    );
    $receiptCashiers = $cashierStatement->fetchAll(PDO::FETCH_ASSOC);
}

// Handle AJAX view request
if ($action === 'view' && isset($_GET['ajax']) && $sale_id > 0) {
    $sale = receipt_fetch_sale($pdo, $sale_id, $storeId);

    if (!$sale) {
        http_response_code(404);
        echo '<div class="receipt-container"><p>Receipt not found</p></div>';
        exit;
    }

    if (!$can_manage_all && (int)$sale['cashier_id'] !== (int)$_SESSION['user_id']) {
        http_response_code(403);
        echo '<div class="receipt-container"><p>Access denied</p></div>';
        exit;
    }

    receipt_render_details($sale, receipt_fetch_items($pdo, $sale_id), $canStartSale, false, true);
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
</head>
<body class="receipts-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
            <?php if ($selected_error): ?>
                <div class="alert tag-warning" role="alert"><?= htmlspecialchars($selected_error) ?></div>
            <?php endif; ?>

            <?php if ($canStartSale): ?>
                <a id="receiptNewSale" href="<?= htmlspecialchars(app_url('components/cashier/pos.php?new_sale=1')) ?>" class="btn"><i class="bi bi-plus-lg" aria-hidden="true"></i> New Sale</a>
            <?php endif; ?>

            <section class="receipt-history-panel" aria-label="Search receipt history">
            <div class="receipt-filter-heading">
                <h2><i class="bi bi-funnel" aria-hidden="true"></i> Filter receipts</h2>
                <span>Dates in Philippine time &middot; Asia/Manila</span>
            </div>
            <div class="receipt-filters" role="group" aria-label="Receipt filters">
                <label>
                    <span>From date</span>
                    <input type="date" id="receiptDateFrom" aria-describedby="receiptTableStatusMessage">
                </label>
                <label>
                    <span>To date</span>
                    <input type="date" id="receiptDateTo" aria-describedby="receiptTableStatusMessage">
                </label>
                <label><span>Cash Refund status</span><select id="receiptRefundStatus"><option value="">All Cash Refunds</option><option value="none">No Cash Refund</option><option value="partial">Partially refunded</option><option value="full">Fully refunded</option></select></label>
                <?php if ($can_manage_all): ?>
                    <label>
                        <span>Cashier</span>
                        <select id="receiptCashier">
                            <option value="">All cashiers</option>
                            <?php foreach ($receiptCashiers as $cashier): ?>
                                <option value="<?= (int)$cashier['user_id'] ?>"><?= htmlspecialchars($cashier['full_name']) ?></option>
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
            <p class="receipt-history-note"><i class="bi bi-shield-check" aria-hidden="true"></i> Completed receipts are saved permanently. Use the authorized Cash Refund workflow to make corrections.</p>
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
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        order: [[1, 'desc'], [0, 'desc']],
        stateSave: true,
        stateDuration: -1,
        stateLoadParams: function(settings, data) {
            data.length = 10;
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
                    placeholder: 'Receipt number or payment method...'
                }
            }, document.getElementById('receiptNewSale')].filter(Boolean),
            bottom: ['info', {pageLength: {menu: [10, 25, 50, 100]}}, {paging: {numbers: 5}}],
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
    drawerTabs.innerHTML = '<button type="button" id="receiptPaperTab" role="tab" aria-controls="receiptContent">Receipt</button>' +
        '<button type="button" id="receiptOtherTab" role="tab" aria-controls="receiptOtherDetails" disabled>Other Details</button>';
    const drawerSummary = document.createElement('dl');
    drawerSummary.className = 'receipt-drawer-summary';
    otherPanel.append(drawerSummary);
    receiptPanel.before(drawerTabs);
    receiptPanel.after(otherPanel);
    const tabs = Array.from(drawerTabs.querySelectorAll('[role="tab"]'));
    const panels = [receiptPanel, otherPanel];
    function selectDrawerTab(index) {
        tabs.forEach(function(tab, position) {
            tab.setAttribute('aria-selected', String(position === index));
            tab.tabIndex = position === index ? 0 : -1;
            panels[position].hidden = position !== index;
        });
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
