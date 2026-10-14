<?php
// components/invoice/sales.php - receipt history and customer reprint workflows
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Receipts\ReceiptTableService;
use App\Receipts\ReceiptDetailsService;

require_capability(RoleCapabilityPolicy::VIEW_SALES_HISTORY);

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'view', 'data'], true)) {
    $action = 'list';
}
$sale_id = (int)($_GET['sale_id'] ?? $_GET['id'] ?? $_GET['receipt_id'] ?? 0);
$checkoutCompleted = ($_GET['checkout'] ?? '') === 'complete' && $sale_id > 0;
$completedAttempt = null;
if ($checkoutCompleted) {
    $attemptQuery = $pdo->prepare('SELECT attempt_id FROM checkout_attempts WHERE cashier_id = ? AND sale_id = ?');
    $attemptQuery->execute([(int)$_SESSION['user_id'], $sale_id]);
    $completedAttempt = $attemptQuery->fetchColumn() ?: null;
}
$checkoutCompleted = $checkoutCompleted && $completedAttempt !== null;
$announcePayment = $checkoutCompleted && (int)($_SESSION['completed_checkout_sale'] ?? 0) === $sale_id;
if ($announcePayment) unset($_SESSION['completed_checkout_sale']);
$storeId = store_scope_id($pdo);
$can_manage_all = has_capability(RoleCapabilityPolicy::VIEW_STORE_REPORTS);
$canStartSale = has_capability(RoleCapabilityPolicy::OPERATE_POINT_OF_SALE);

function receipt_store_info(): array {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    return $cached = (new ReceiptDetailsService(App\Core\Database::connection()))->storeInfo();
}

function receipt_money($value, ?array $store = null): string {
    $store = $store ?? receipt_store_info();
    return htmlspecialchars((string)$store['currency_symbol']) . number_format((float)$value, 2);
}

function receipt_verification_code(array $sale): string {
    return $sale['verification_code'] ?? ReceiptDetailsService::verificationCode($sale);
}

function receipt_fetch_sale(PDO $pdo, int $sale_id, int $storeId): ?array {
    return (new ReceiptDetailsService($pdo))->fetchSale($sale_id, $storeId);
}

function receipt_fetch_items(PDO $pdo, int $sale_id): array {
    return (new ReceiptDetailsService($pdo))->fetchItems($sale_id);
}

function receipt_render_details(array $sale, array $items, bool $canStartSale, bool $announcePayment = false): void {
    $paperWidthMm = (new \App\Receipts\ReceiptPaperService(\App\Core\Database::connection()))
        ->currentWidth((int)($_SESSION['user_id'] ?? 0), (string)current_role());
    $store = $sale['receipt_store'] ?? receipt_store_info();
    $receiptUrl = app_url($sale['verification_url'] ?? 'components/invoice/sales.php?tab=transactions&sale_id=' . (int)$sale['sale_id']);
    ?>
    <div class="receipt-container">
        <?php if ($announcePayment): ?>
        <div class="checkout-complete-banner no-print" role="status">
            <div class="checkout-complete-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
            <div class="checkout-complete-copy"><strong>Payment completed</strong><span>Receipt #<?= (int)$sale['sale_id'] ?> was saved successfully.</span></div>
            <?php if ($sale['change_due'] !== null): ?><div class="checkout-complete-change"><span>Change due</span><strong><?= receipt_money($sale['change_due'], $store) ?></strong></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="receipt-actions no-print">
            <?php if ($canStartSale): ?>
                <a href="<?= htmlspecialchars(app_url('components/cashier/pos.php?new_sale=1')) ?>" class="btn"><i class="bi bi-plus-lg"></i> New Sale</a>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" onclick="printReceiptSection(this)"><i class="bi bi-printer"></i> Print Receipt</button>
        </div>
        <?php \App\Receipts\SaleReceiptPresentation::render($sale, $items, $store, $receiptUrl, $paperWidthMm); ?>
    </div>
    <?php
}

if ($action === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    $request = $_GET;
    if (!$can_manage_all) {
        unset($request['cashier_id']);
    }
    $cashierScope = $can_manage_all ? null : (int)$_SESSION['user_id'];

    try {
        echo json_encode((new ReceiptTableService($pdo))->fetch($request, $cashierScope), JSON_THROW_ON_ERROR);
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

    receipt_render_details($sale, receipt_fetch_items($pdo, $sale_id), $canStartSale);
    exit;
}

$selected_sale = null;
$selected_items = [];
$selected_error = '';
if ($sale_id > 0) {
    $selected_sale = receipt_fetch_sale($pdo, $sale_id, $storeId);
    if (!$selected_sale) {
        $selected_error = 'Receipt not found.';
    } elseif (!$can_manage_all && (int)$selected_sale['cashier_id'] !== (int)$_SESSION['user_id']) {
        $selected_error = 'Access denied.';
        $selected_sale = null;
    } else {
        $selected_items = receipt_fetch_items($pdo, $sale_id);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt History</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/v/dt/dt-3.0.4/datatables.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/invoices.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/invoices.css')) ?>">
<?php if ($checkoutCompleted): ?>
<script>
try {
    const key = <?= json_encode('retailmind.checkout.' . (int)$_SESSION['user_id']) ?>;
    const pending = JSON.parse(localStorage.getItem(key) || 'null');
    const matchesAttempt = pending && pending.id === <?= json_encode($completedAttempt) ?>;
    if (matchesAttempt || (!pending && <?= json_encode($announcePayment) ?>)) {
        sessionStorage.removeItem('pos_cart');
        sessionStorage.removeItem('retailmind.cart-workspace');
        if (matchesAttempt) localStorage.removeItem(key);
    }
} catch (error) { /* Returning to POS retries recovery if browser storage is unavailable. */ }
</script>
<?php endif; ?>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/sale-receipt.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/sale-receipt.css')) ?>">
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt-dialog.js')) ?>" defer></script>
</head>
<body class="receipts-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading"><h1>Receipt History</h1></header>
        <div class="receipts-container">
            <?php if ($selected_error): ?>
                <div class="alert tag-warning" role="alert"><?= htmlspecialchars($selected_error) ?></div>
            <?php endif; ?>

            <div class="section-header receipt-table-heading">
                <div>
                    <h2 id="receipt-table-title" tabindex="-1">Receipt History</h2>
                    <p class="section-description">Customer lookup and reprint within your permitted history. Dates use Philippine time (Asia/Manila). Completed receipts are immutable; new corrections use the authorized Cash Refund workflow.</p>
                </div>
                <div class="receipt-heading-actions">
                    <?php if ($canStartSale): ?>
                        <a href="<?= htmlspecialchars(app_url('components/cashier/pos.php?new_sale=1')) ?>" class="btn"><i class="bi bi-plus-lg" aria-hidden="true"></i> New Sale</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="receipt-filters" aria-label="Receipt filters">
                <label>
                    <span>From</span>
                    <input type="date" id="receiptDateFrom">
                </label>
                <label>
                    <span>To</span>
                    <input type="date" id="receiptDateTo">
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
                <h2>No receipts have been created yet.</h2>
                <p>Complete a sale to create the first receipt.</p>
                <?php if ($canStartSale): ?>
                    <div class="empty-actions">
                        <a class="btn" href="<?= htmlspecialchars(app_url('components/cashier/pos.php?new_sale=1')) ?>">Start a Sale</a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="table-wrap receipt-table-wrap">
                <table id="receiptsTable" class="receipts-table display" data-no-smart-table>
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
        </div>
    </main>
</div>

<?php require __DIR__ . '/../../../backend/app/Views/sale_receipt_dialog.php'; ?>

<script src="https://cdn.datatables.net/v/dt/dt-3.0.4/datatables.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('receiptsTable')) {
        return;
    }

    if (typeof DataTable === 'undefined') {
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
    const stateKey = 'retailmind.receipts.' + window.location.pathname;
    const textRenderer = DataTable.render.text();

    function updateStatus(message, isError, canRetry) {
        statusMessage.textContent = message;
        status.classList.toggle('is-error', Boolean(isError));
        retryButton.hidden = !canRetry;
    }

    function currentFilters() {
        return {
            date_from: dateFrom.value,
            date_to: dateTo.value,
            refund_status: refundStatus.value,
            cashier_id: cashier ? cashier.value : ''
        };
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
        processing: true,
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        order: [[1, 'desc'], [0, 'desc']],
        stateSave: true,
        stateDuration: -1,
        stateSaveCallback: function(settings, data) {
            data.receiptFilters = currentFilters();
            sessionStorage.setItem(stateKey, JSON.stringify(data));
        },
        stateLoadCallback: function() {
            try {
                const savedState = JSON.parse(sessionStorage.getItem(stateKey) || 'null');
                restoreFilters(savedState);
                return savedState;
            } catch (error) {
                sessionStorage.removeItem(stateKey);
                return null;
            }
        },
        ajax: {
            url: '<?= htmlspecialchars(app_url('components/invoice/sales.php?action=data')) ?>',
            data: function(request) {
                Object.assign(request, currentFilters());
            },
            error: function() {
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
                    return type === 'display' ? '<strong>₱' + amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</strong>' : amount;
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
                        ? '<span class="' + (label.indexOf('Legacy') === 0 ? 'receipt-tag-neutral' : '') + '">' + label + '</span>'
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
                    let html = '<div class="actions-cell">' +
                        '<button type="button" class="btn-small btn-view" onclick="viewReceipt(' + saleId + ', this)"><i class="bi bi-eye" aria-hidden="true"></i> View</button>';
                    <?php if (current_role() === 'cashier'): ?>
                    html += '<a class="btn-small" href="' + <?= json_encode(app_url('components/cashier/refunds.php?sale_id=')) ?> + saleId + '">Refund</a>';
                    <?php endif; ?>
                    html += '</div>';
                    return html;
                }
            }
        ],
        layout: {
            topStart: null,
            topEnd: {
                search: {
                    placeholder: 'Receipt number or payment method...'
                }
            },
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

    table.on('preXhr', function() {
        updateStatus('Loading receipts…', false, false);
    });
    table.on('xhr', function(event, settings, json) {
        if (json && json.error) {
            updateStatus('Unable to load receipts. Please retry.', true, true);
        }
    });
    table.on('draw', function() {
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
        table.ajax.reload(null, false);
    });
    [dateFrom, dateTo, refundStatus, cashier].filter(Boolean).forEach(function(control) {
        control.addEventListener('change', function() {
            table.ajax.reload(null, true);
        });
    });
    clearFilters.addEventListener('click', function() {
        dateFrom.value = '';
        dateTo.value = '';
        refundStatus.value = '';
        if (cashier) cashier.value = '';
        table.search('');
        table.order([[1, 'desc'], [0, 'desc']]);
        table.page.len(25);
        table.ajax.reload(null, true);
    });
});
</script>
</body>
</html>
