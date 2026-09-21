<?php
// components/invoice/sales.php - sales transactions and reversal workflows
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/SaleReversalService.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\ReceiptTableService;

require_capability(RoleCapabilityPolicy::VIEW_SALES_HISTORY);

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'view', 'data'], true)) {
    $action = 'list';
}
$sale_id = (int)($_GET['sale_id'] ?? $_GET['id'] ?? $_GET['receipt_id'] ?? 0);
$checkoutCompleted = ($_GET['checkout'] ?? '') === 'complete' && $sale_id > 0;
$storeId = store_scope_id($pdo);
$can_manage_all = has_capability(RoleCapabilityPolicy::VIEW_STORE_REPORTS);
$canStartSale = current_role() !== 'inventory_manager';
$activeTab = ($_GET['tab'] ?? 'transactions') === 'reversals' ? 'reversals' : 'transactions';

function receipt_store_info(): array {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    $settings = get_store_settings(App\Core\Database::connection());
    $contactParts = array_filter([$settings['store_phone'] ?? '', $settings['store_email'] ?? '']);
    return $cached = [
        'name' => $settings['store_name'] ?: 'Shalom Store',
        'tagline' => 'Official sales receipt',
        'address' => $settings['store_address'] ?: 'Address not configured',
        'contact' => $contactParts ? implode(' / ', $contactParts) : 'Contact not configured',
        'tin' => $settings['business_identifier'] ?: 'Business ID not configured',
        'currency_symbol' => $settings['currency_symbol'] ?: '₱',
        'footer' => $settings['receipt_footer'] ?: 'Thank you for shopping with us.',
    ];
}

function receipt_table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }
    $cache[$table] = $columns;
    return $columns;
}

function receipt_money($value): string {
    $store = receipt_store_info();
    return htmlspecialchars((string)$store['currency_symbol']) . number_format((float)$value, 2);
}

function receipt_verification_code(array $sale): string {
    $raw = implode('|', [
        $sale['sale_id'] ?? '',
        $sale['sale_date'] ?? '',
        number_format((float)($sale['total_amount'] ?? 0), 2, '.', ''),
    ]);
    return implode('-', str_split(substr(strtoupper(hash('sha256', $raw)), 0, 12), 4));
}

function receipt_fetch_sale(PDO $pdo, int $sale_id, int $storeId): ?array {
    $saleColumns = receipt_table_columns($pdo, 'sales');
    $cashReceivedSql = isset($saleColumns['cash_received']) ? 's.cash_received' : 'NULL';
    $changeDueSql = isset($saleColumns['change_due']) ? 's.change_due' : 'NULL';
    $paymentReferenceSql = isset($saleColumns['payment_reference']) ? 's.payment_reference' : 'NULL';
    $discountSql = isset($saleColumns['discount_amount']) ? 's.discount_amount' : '0.00';
    $promotionNameSql = isset($saleColumns['promotion_name']) ? 's.promotion_name' : 'NULL';
    $discountReasonSql = isset($saleColumns['discount_reason']) ? 's.discount_reason' : 'NULL';

    $saleStmt = $pdo->prepare(
        "SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date,
                {$cashReceivedSql} AS cash_received,
                {$changeDueSql} AS change_due,
                {$paymentReferenceSql} AS payment_reference,
                {$discountSql} AS discount_amount,
                {$promotionNameSql} AS promotion_name,
                {$discountReasonSql} AS discount_reason,
                u.full_name AS cashier_name
         FROM sales s
         JOIN users u ON s.cashier_id = u.user_id
         WHERE s.sale_id = ? AND (
             u.branch_id = ? OR EXISTS (
                 SELECT 1 FROM sale_items scope_si
                 JOIN products scope_p ON scope_p.product_id = scope_si.product_id
                 WHERE scope_si.sale_id = s.sale_id AND scope_p.branch_id = ?
             )
         )"
    );
    $saleStmt->execute([$sale_id, $storeId, $storeId]);
    $sale = $saleStmt->fetch();

    return $sale ?: null;
}

function receipt_fetch_items(PDO $pdo, int $sale_id): array {
    $itemsStmt = $pdo->prepare(
        "SELECT si.quantity, si.unit_price, si.subtotal, p.sku, p.product_name
         FROM sale_items si
         JOIN products p ON si.product_id = p.product_id
         WHERE si.sale_id = ?
         ORDER BY si.sale_item_id"
    );
    $itemsStmt->execute([$sale_id]);
    return $itemsStmt->fetchAll();
}

function receipt_render_details(array $sale, array $items, bool $canStartSale): void {
    $store = receipt_store_info();
    $itemSubtotal = array_reduce($items, fn($total, $item) => $total + (float)$item['subtotal'], 0.0);
    $quantityTotal = array_reduce($items, fn($total, $item) => $total + (int)$item['quantity'], 0);
    $discount = max((float)($sale['discount_amount'] ?? 0), $itemSubtotal - (float)$sale['total_amount']);
    $verificationCode = receipt_verification_code($sale);
    $receiptUrl = app_url('components/invoice/sales.php?tab=transactions&sale_id=' . (int)$sale['sale_id']);
    ?>
    <div class="receipt-container">
        <div class="checkout-complete-banner no-print">
            <div class="checkout-complete-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
            <div class="checkout-complete-copy"><strong>Payment completed</strong><span>Receipt #<?= (int)$sale['sale_id'] ?> was saved successfully.</span></div>
            <div class="checkout-complete-change"><span>Change due</span><strong><?= $sale['change_due'] !== null ? receipt_money($sale['change_due']) : '-' ?></strong></div>
        </div>
        <div class="receipt-actions no-print">
            <?php if ($canStartSale): ?>
                <a href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>" class="btn"><i class="bi bi-plus-lg"></i> New Sale</a>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" onclick="printReceiptSection(this)"><i class="bi bi-printer"></i> Print Receipt</button>
            <a href="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=transactions')) ?>" class="btn btn-secondary"><i class="bi bi-receipt"></i> Receipt History</a>
        </div>
        <div class="receipt-card receipt-print-area">
            <div class="receipt-header">
                <div class="receipt-brand">
                    <h1><?= htmlspecialchars($store['name']) ?></h1>
                    <div class="receipt-store-line"><?= htmlspecialchars($store['tagline']) ?></div>
                    <div><?= htmlspecialchars($store['address']) ?></div>
                    <div><?= htmlspecialchars($store['contact']) ?></div>
                    <div><?= htmlspecialchars($store['tin']) ?></div>
                </div>
                <div class="receipt-meta">
                    <div><strong>Transaction #<?= (int)$sale['sale_id'] ?></strong></div>
                    <div>Receipt #<?= (int)$sale['sale_id'] ?></div>
                    <div>Date/Time: <?= htmlspecialchars($sale['sale_date']) ?></div>
                    <div>Cashier: <?= htmlspecialchars($sale['cashier_name']) ?></div>
                </div>
            </div>

            <div class="receipt-summary">
                <div><span>Payment Method</span><strong><?= htmlspecialchars(strtoupper($sale['payment_method'])) ?></strong></div>
                <div><span>Total Quantity</span><strong><?= (int)$quantityTotal ?></strong></div>
                <div><span>Total Amount</span><strong><?= receipt_money($sale['total_amount']) ?></strong></div>
            </div>

            <table class="receipt-table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['sku']) ?></td>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td><?= (int)$item['quantity'] ?></td>
                        <td><?= receipt_money($item['unit_price']) ?></td>
                        <td><?= receipt_money($item['subtotal']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="receipt-bottom">
                <div class="receipt-verification">
                    <div class="verification-box" aria-label="Receipt verification code"><?= htmlspecialchars($verificationCode) ?></div>
                    <div class="receipt-store-line">Verification Code</div>
                    <div class="receipt-url"><?= htmlspecialchars($receiptUrl) ?></div>
                </div>
                <div class="receipt-totals">
                    <div><span>Subtotal</span><strong><?= receipt_money($itemSubtotal) ?></strong></div>
                    <div><span>Discount</span><strong><?= receipt_money($discount) ?></strong></div>
                    <?php if (!empty($sale['promotion_name'])): ?><div><span>Promotion</span><strong><?= htmlspecialchars($sale['promotion_name']) ?></strong></div><?php elseif (!empty($sale['discount_reason'])): ?><div><span>Discount reason</span><strong><?= htmlspecialchars($sale['discount_reason']) ?></strong></div><?php endif; ?>
                    <div><span>Total Amount</span><strong><?= receipt_money($sale['total_amount']) ?></strong></div>
                    <div><span>Cash Received</span><strong><?= $sale['cash_received'] !== null ? receipt_money($sale['cash_received']) : '-' ?></strong></div>
                    <div><span>Change</span><strong><?= $sale['change_due'] !== null ? receipt_money($sale['change_due']) : '-' ?></strong></div>
                    <?php if (!empty($sale['payment_reference'])): ?>
                    <div><span>Payment Reference</span><strong><?= htmlspecialchars($sale['payment_reference']) ?></strong></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="receipt-store-line" style="text-align:center;margin-top:1rem"><?= htmlspecialchars($store['footer']) ?></div>
        </div>
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
            'error' => 'Unable to load receipts. Please retry.',
        ]);
    }
    exit;
}

$reversalService = new SaleReversalService($pdo);
$reversalCanApprove = has_capability(RoleCapabilityPolicy::MANAGE_SALE_REVERSALS);
$reversalMessage = '';
$reversalError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activeTab = 'reversals';
    csrf_verify();
    $postAction = $_POST['action'] ?? '';

    try {
        if ($postAction === 'request') {
            $requestedSale = $reversalService->getSaleWithItems((int)$_POST['sale_id']);
            if (!$requestedSale) {
                throw new RuntimeException('Sale not found.');
            }
            if (!$reversalCanApprove && (int)$requestedSale['cashier_id'] !== (int)$_SESSION['user_id']) {
                throw new RuntimeException('You can only request reversals for your own sales.');
            }

            $reversalId = $reversalService->requestReversal(
                (int)$_POST['sale_id'],
                $_POST['reversal_type'] ?? '',
                $_POST['reason'] ?? '',
                $_POST['items'] ?? [],
                (int)$_SESSION['user_id'],
                $_POST['settlement_method'] ?? 'none',
                (float)($_POST['refund_amount'] ?? 0),
                $_POST['exchange_details'] ?? ''
            );
            $reversalMessage = "Reversal request #{$reversalId} was submitted for supervisor approval.";
            $sale_id = (int)$_POST['sale_id'];
        } elseif ($postAction === 'approve' && $reversalCanApprove) {
            $reversalService->approveReversal((int)$_POST['reversal_id'], (int)$_SESSION['user_id']);
            $reversalMessage = 'Reversal approved and returned stock was restored.';
        } elseif ($postAction === 'reject' && $reversalCanApprove) {
            $reversalService->rejectReversal((int)$_POST['reversal_id'], (int)$_SESSION['user_id'], $_POST['rejection_reason'] ?? '');
            $reversalMessage = 'Reversal request rejected.';
        }
    } catch (RuntimeException $exception) {
        $reversalError = $exception->getMessage();
    } catch (PDOException $exception) {
        $reversalError = 'The reversal could not be saved because of a database error.';
    }
}

$reversalSale = $activeTab === 'reversals' && $sale_id > 0
    ? $reversalService->getSaleWithItems($sale_id)
    : null;
if ($reversalSale && !$can_manage_all && (int)$reversalSale['cashier_id'] !== (int)$_SESSION['user_id']) {
    $reversalError = 'You can only view reversals for your own sales.';
    $reversalSale = null;
}
$reversals = $activeTab === 'reversals'
    ? ($sale_id > 0 && $reversalSale
        ? $reversalService->getReversals($sale_id)
        : ($can_manage_all ? $reversalService->getReversals() : []))
    : [];
$pendingReversals = array_values(array_filter($reversals, static fn(array $row): bool => $row['status'] === 'pending'));

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
    $saleStmt = $pdo->prepare(
        "SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date, u.full_name AS cashier_name
         FROM sales s
         JOIN users u ON s.cashier_id = u.user_id
         WHERE s.sale_id = ? AND (
             u.branch_id = ? OR EXISTS (
                 SELECT 1 FROM sale_items scope_si
                 JOIN products scope_p ON scope_p.product_id = scope_si.product_id
                 WHERE scope_si.sale_id = s.sale_id AND scope_p.branch_id = ?
             )
         )"
    );
    $saleStmt->execute([$sale_id, $storeId, $storeId]);
    $sale = $saleStmt->fetch();

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

    $sale = receipt_fetch_sale($pdo, $sale_id, $storeId);
    receipt_render_details($sale, receipt_fetch_items($pdo, $sale_id), $canStartSale);
    exit;

    $itemsStmt = $pdo->prepare(
        "SELECT si.quantity, si.unit_price, si.subtotal, p.sku, p.product_name
         FROM sale_items si
         JOIN products p ON si.product_id = p.product_id
         WHERE si.sale_id = ?
         ORDER BY si.sale_item_id"
    );
    $itemsStmt->execute([$sale_id]);
    $items = $itemsStmt->fetchAll();

    ?>
    <div class="receipt-container">
        <div class="receipt-card">
            <div class="receipt-header">
                <div class="receipt-brand">
                    <h1>Inventory System Receipt</h1>
                    <div class="u-text-muted">Official sales invoice</div>
                </div>
                <div class="receipt-meta">
                    <div><strong>Receipt #<?= $sale_id ?></strong></div>
                    <div>Date: <?= htmlspecialchars($sale['sale_date']) ?></div>
                    <div>Cashier: <?= htmlspecialchars($sale['cashier_name']) ?></div>
                </div>
            </div>

            <div class="receipt-summary">
                <div><span>Payment Method</span><strong><?= htmlspecialchars(strtoupper($sale['payment_method'])) ?></strong></div>
                <div><span>Items</span><strong><?= count($items) ?></strong></div>
                <div><span>Total</span><strong>₱<?= number_format($sale['total_amount'], 2) ?></strong></div>
            </div>

            <table class="receipt-table">
                <thead>
                    <tr><th>SKU</th><th>Product</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['sku']) ?></td>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td><?= (int)$item['quantity'] ?></td>
                        <td>₱<?= number_format($item['unit_price'], 2) ?></td>
                        <td>₱<?= number_format($item['subtotal'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="receipt-total">Grand Total: ₱<?= number_format($sale['total_amount'], 2) ?></div>
        </div>
    </div>
    <?php
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
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/v/dt/dt-3.0.4/datatables.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/invoices.css')) ?>">
<?php if ($checkoutCompleted): ?>
<script>sessionStorage.removeItem('pos_cart');</script>
<?php endif; ?>
</head>
<body class="receipts-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <div class="main-content">
        <div class="receipts-container">
            <nav class="sales-tabs" aria-label="Sales views">
                <a class="sales-tab<?= $activeTab === 'transactions' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=transactions')) ?>">
                    <i class="bi bi-receipt" aria-hidden="true"></i> Sales Transactions
                </a>
                <a class="sales-tab<?= $activeTab === 'reversals' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=reversals')) ?>">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Sales Reversals
                </a>
            </nav>

            <?php if ($activeTab === 'transactions'): ?>
            <?php if ($selected_error): ?>
                <div class="alert alert-success"><?= htmlspecialchars($selected_error) ?></div>
            <?php endif; ?>

            <?php if ($selected_sale): ?>
                <?php receipt_render_details($selected_sale, $selected_items, $canStartSale); ?>
            <?php endif; ?>

            <div class="section-header receipt-table-heading">
                    <div>
                        <h2 id="receipt-table-title">Receipt history</h2>
                        <p class="section-description">Search by receipt number or payment method, then narrow the permitted history with the filters.</p>
                    </div>
                    <div class="receipt-heading-actions">
                        <?php if ($canStartSale): ?>
                            <a href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>" class="btn"><i class="bi bi-plus-lg" aria-hidden="true"></i> New Sale</a>
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
                    <label>
                        <span>Reversal status</span>
                        <select id="receiptReversalStatus">
                            <option value="">All statuses</option>
                            <option value="none">No reversal</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </label>
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
                            <a class="btn" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>">Start a Sale</a>
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
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Reversals</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            <?php else: ?>
                <?php if ($reversalMessage): ?><div class="alert tag-success"><?= htmlspecialchars($reversalMessage) ?></div><?php endif; ?>
                <?php if ($reversalError): ?><div class="alert tag-warning"><?= htmlspecialchars($reversalError) ?></div><?php endif; ?>

                <div class="section-header reversal-heading">
                    <div>
                        <h2>Sales reversals</h2>
                        <p class="section-description">Corrections create separate approved records; completed sales stay unchanged.</p>
                    </div>
                    <form method="GET" class="actions-row reversal-search">
                        <input type="hidden" name="tab" value="reversals">
                        <label for="reversalSaleId">Receipt / Sale ID</label>
                        <input type="number" min="1" name="sale_id" id="reversalSaleId" value="<?= $sale_id ?: '' ?>" placeholder="Receipt number">
                        <button class="btn" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Load</button>
                    </form>
                </div>

                <div class="grid-two sales-reversal-grid">
                    <div>
                        <?php if ($reversalSale): ?>
                            <div class="panel">
                                <div class="section-header">
                                    <div>
                                        <h3>Sale #<?= (int)$reversalSale['sale_id'] ?></h3>
                                        <p class="section-description"><?= htmlspecialchars($reversalSale['sale_date']) ?> by <?= htmlspecialchars($reversalSale['cashier_name']) ?>, <?= htmlspecialchars(strtoupper($reversalSale['payment_method'])) ?>, &#8369;<?= number_format((float)$reversalSale['total_amount'], 2) ?></p>
                                    </div>
                                    <?php if (!empty($reversalSale['approved_full_reversal'])): ?><span class="status-pill status-approved">Cancelled</span><?php endif; ?>
                                </div>

                                <form method="POST" action="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=reversals')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request">
                                    <input type="hidden" name="sale_id" value="<?= (int)$reversalSale['sale_id'] ?>">
                                    <div class="form-group">
                                        <label for="reversal_type">Correction Type</label>
                                        <select name="reversal_type" id="reversal_type">
                                            <option value="return">Product Return</option><option value="refund">Refund</option><option value="exchange">Exchange</option><option value="cancel">Cancel Entire Transaction</option>
                                        </select>
                                    </div>
                                    <div class="u-table-scroll-spaced">
                                        <table>
                                            <tr><th>Product</th><th>Sold</th><th>Reversed</th><th>Return Qty</th><th>Unit Price</th></tr>
                                            <?php foreach ($reversalSale['items'] as $item): $remaining = (int)$item['quantity'] - (int)$item['reversed_qty']; ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($item['sku'] . ' - ' . $item['product_name']) ?></td><td><?= (int)$item['quantity'] ?></td><td><?= (int)$item['reversed_qty'] ?></td>
                                                    <td><input class="reversal-quantity" type="number" name="items[<?= (int)$item['sale_item_id'] ?>]" min="0" max="<?= max(0, $remaining) ?>" value="0" <?= $remaining <= 0 ? 'disabled' : '' ?>></td>
                                                    <td>&#8369;<?= number_format((float)$item['unit_price'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </div>
                                    <div class="form-group"><label for="settlement_method">Refund / Exchange Handling</label><select name="settlement_method" id="settlement_method"><option value="none">No money movement</option><option value="cash">Cash Refund</option><option value="card">Card Refund</option><option value="ewallet">E-Wallet Refund</option><option value="exchange">Exchange / Store Credit</option></select></div>
                                    <div class="form-group"><label for="refund_amount">Refund Amount</label><input type="number" step="0.01" min="0" name="refund_amount" id="refund_amount" value="0.00"></div>
                                    <div class="form-group"><label for="exchange_details">Exchange Details</label><textarea name="exchange_details" id="exchange_details" placeholder="Replacement item, store credit reference, or exchange notes"></textarea></div>
                                    <div class="form-group"><label for="reason">Reason</label><textarea name="reason" id="reason" required placeholder="Reason required for audit and supervisor approval"></textarea></div>
                                    <button class="btn" type="submit">Submit for Supervisor Approval</button>
                                </form>
                            </div>
                        <?php elseif ($sale_id > 0): ?>
                            <div class="panel">Sale #<?= $sale_id ?> was not found.</div>
                        <?php else: ?>
                            <div class="empty-state reversal-empty"><i class="bi bi-receipt"></i><strong>Load a sale to request a reversal</strong><span>Enter a receipt number above to review eligible items.</span></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if ($reversalCanApprove && $pendingReversals): ?>
                            <div class="panel">
                                <h3>Pending Supervisor Approval</h3>
                                <p class="section-description">Approving restores returned stock and records the audit entry.</p>
                                <?php foreach ($pendingReversals as $row): ?>
                                    <div class="u-section-divider">
                                        <strong>#<?= (int)$row['reversal_id'] ?> <?= htmlspecialchars(strtoupper($row['reversal_type'])) ?></strong>
                                        <p class="muted">Sale #<?= (int)$row['sale_id'] ?> requested by <?= htmlspecialchars($row['requested_by_name'] ?? 'Unknown') ?></p><p><?= htmlspecialchars($row['reason']) ?></p>
                                        <div class="actions-row u-mt-075">
                                            <form method="POST" action="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=reversals')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="reversal_id" value="<?= (int)$row['reversal_id'] ?>"><button class="btn btn-small" type="submit">Approve</button></form>
                                            <form method="POST" action="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=reversals')) ?>" class="actions-row u-m-0"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="reversal_id" value="<?= (int)$row['reversal_id'] ?>"><input class="u-field-basic" type="text" name="rejection_reason" placeholder="Rejection reason" required><button class="btn btn-small btn-danger" type="submit">Reject</button></form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="panel">
                            <h3><?= $sale_id > 0 ? 'Sale Reversal History' : 'Recent Reversals' ?></h3>
                            <div class="u-table-scroll u-mt-075"><table><tr><th>ID</th><th>Sale</th><th>Type</th><th>Status</th><th>Requested</th></tr>
                                <?php foreach ($reversals as $row): ?><tr><td>#<?= (int)$row['reversal_id'] ?></td><td><a href="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=reversals&sale_id=' . $row['sale_id'])) ?>">#<?= (int)$row['sale_id'] ?></a></td><td><?= htmlspecialchars(ucfirst($row['reversal_type'])) ?></td><td><span class="status-pill status-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td><td><?= htmlspecialchars(format_display_datetime($row['created_at'])) ?></td></tr><?php endforeach; ?>
                                <?php if (!$reversals): ?><tr><td class="u-empty-cell" colspan="5">No reversal records yet.</td></tr><?php endif; ?>
                            </table></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Receipt Modal -->
<div id="receiptModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Receipt Details</h2>
            <button type="button" class="modal-close" title="Close">&times;</button>
        </div>
        <div id="receiptContent"></div>
    </div>
</div>

<!-- Legacy edit modal is disabled; completed receipts must use reversal records. -->
<?php if (false && $action === 'update' && $sale_id > 0): ?>
    <?php
    $saleStmt = $pdo->prepare(
        "SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date, u.full_name AS cashier_name
         FROM sales s
         JOIN users u ON s.cashier_id = u.user_id
         WHERE s.sale_id = ?"
    );
    $saleStmt->execute([$sale_id]);
    $sale = $saleStmt->fetch();

    if ($sale && ($can_manage_all || (int)$sale['cashier_id'] === (int)$_SESSION['user_id'])): ?>
    <div id="editModal" class="modal active">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Receipt #<?= $sale_id ?></h2>
                <button type="button" class="modal-close" title="Close" onclick="window.location.href='<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=transactions')) ?>'">&times;</button>
            </div>
            <div class="receipt-container">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                    <div class="u-mb-1">
                        <label class="u-block-label">Receipt #</label>
                        <input type="text" value="<?= $sale['sale_id'] ?>" disabled style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; background: #f9fafb;">
                    </div>

                    <div class="u-mb-1">
                        <label class="u-block-label">Date</label>
                        <input type="text" value="<?= htmlspecialchars($sale['sale_date']) ?>" disabled style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; background: #f9fafb;">
                    </div>

                    <div class="u-mb-1">
                        <label class="u-block-label">Cashier</label>
                        <input type="text" value="<?= htmlspecialchars($sale['cashier_name']) ?>" disabled style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; background: #f9fafb;">
                    </div>

                    <div class="u-mb-1">
                        <label class="u-block-label">Payment Method</label>
                        <select class="u-field-select" name="payment_method">
                            <option value="CASH" <?= $sale['payment_method'] === 'CASH' ? 'selected' : '' ?>>Cash</option>
                            <option value="CARD" <?= $sale['payment_method'] === 'CARD' ? 'selected' : '' ?>>Card</option>
                            <option value="CHECK" <?= $sale['payment_method'] === 'CHECK' ? 'selected' : '' ?>>Check</option>
                            <option value="ONLINE" <?= $sale['payment_method'] === 'ONLINE' ? 'selected' : '' ?>>Online</option>
                        </select>
                    </div>

                    <div class="u-mb-1">
                        <label class="u-block-label">Total Amount</label>
                        <input type="text" value="₱<?= number_format($sale['total_amount'], 2) ?>" disabled style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; background: #f9fafb;">
                    </div>

                    <div class="u-flex-wrap">
                        <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                        <a href="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=transactions')) ?>" class="btn">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script src="https://cdn.datatables.net/v/dt/dt-3.0.4/datatables.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('receiptsTable')) {
        return;
    }

    if (window.Swal && typeof window.Swal.fire === 'function') {
        window.Swal.fire({
            icon: 'info',
            title: 'Completed receipts are locked',
            text: 'Use reversals for cancellations, returns, refunds, and exchanges.',
            confirmButtonText: 'Understood',
            customClass: {confirmButton: 'btn rm-swal-confirm'}
        });
    }

    if (typeof DataTable === 'undefined') {
        return;
    }

    const dateFrom = document.getElementById('receiptDateFrom');
    const dateTo = document.getElementById('receiptDateTo');
    const reversalStatus = document.getElementById('receiptReversalStatus');
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
            reversal_status: reversalStatus.value,
            cashier_id: cashier ? cashier.value : ''
        };
    }

    function restoreFilters(savedState) {
        const filters = savedState && savedState.receiptFilters ? savedState.receiptFilters : {};
        dateFrom.value = filters.date_from || '';
        dateTo.value = filters.date_to || '';
        reversalStatus.value = filters.reversal_status || '';
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
            { data: 'sale_date' },
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
                data: null,
                orderable: false,
                searchable: false,
                render: function(row, type) {
                    if (type !== 'display') return Number(row.reversal_count || 0);
                    const badges = [];
                    if (Number(row.pending_reversals) > 0) badges.push('<span class="tag-warning">' + Number(row.pending_reversals) + ' pending</span>');
                    if (Number(row.approved_reversals) > 0) badges.push('<span class="tag-success">' + Number(row.approved_reversals) + ' approved</span>');
                    if (Number(row.rejected_reversals) > 0) badges.push('<span class="receipt-tag-neutral">' + Number(row.rejected_reversals) + ' rejected</span>');
                    return badges.length ? badges.join(' ') : '<span class="u-text-muted">None</span>';
                }
            },
            {
                data: 'sale_id',
                orderable: false,
                searchable: false,
                render: function(value, type) {
                    if (type !== 'display') return value;
                    const saleId = Number(value);
                    return '<div class="actions-cell">' +
                        '<button type="button" class="btn-small btn-view" onclick="viewReceipt(' + saleId + ')"><i class="bi bi-eye" aria-hidden="true"></i> View</button>' +
                        '<a href="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=reversals&sale_id=')) ?>' + saleId + '" class="btn-small btn-edit">Reverse</a>' +
                        '</div>';
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
    [dateFrom, dateTo, reversalStatus, cashier].filter(Boolean).forEach(function(control) {
        control.addEventListener('change', function() {
            table.ajax.reload(null, true);
        });
    });
    clearFilters.addEventListener('click', function() {
        dateFrom.value = '';
        dateTo.value = '';
        reversalStatus.value = '';
        if (cashier) cashier.value = '';
        table.search('');
        table.order([[1, 'desc'], [0, 'desc']]);
        table.page.len(25);
        table.ajax.reload(null, true);
    });
});

function printReceiptSection(trigger) {
    const currentTarget = trigger
        ? trigger.closest('.receipt-container')?.querySelector('.receipt-print-area')
        : document.querySelector('.receipt-print-area');
    if (!currentTarget) return;

    document.querySelectorAll('.receipt-print-target').forEach(el => el.classList.remove('receipt-print-target'));
    currentTarget.classList.add('receipt-print-target');
    window.print();
    setTimeout(() => currentTarget.classList.remove('receipt-print-target'), 500);
}

function exportReceiptPdf(trigger) {
    printReceiptSection(trigger);
}

function viewReceipt(saleId) {
    const url = '<?= htmlspecialchars(app_url('components/invoice/sales.php')) ?>?action=view&sale_id=' + saleId + '&ajax=1';

    fetch(url)
        .then(r => {
            if (!r.ok) throw new Error('Network response was not ok: ' + r.status);
            return r.text();
        })
        .then(html => {
            document.getElementById('receiptContent').innerHTML = html;
            document.getElementById('receiptModal').classList.add('active');
        })
        .catch(error => {
            console.error('Error fetching receipt:', error);
            RetailMindUI.toast('Failed to load receipt. Please try again.', 'error');
        });
}

function closeReceiptModal() {
    document.getElementById('receiptModal').classList.remove('active');
}

// Close modal when clicking outside the modal-content
document.addEventListener('click', function(event) {
    const modal = document.getElementById('receiptModal');
    const modalContent = document.querySelector('#receiptModal .modal-content');
    if (event.target === modal) {
        closeReceiptModal();
    }
});

// Close button handler
document.addEventListener('click', function(event) {
    if (event.target.classList && event.target.classList.contains('modal-close')) {
        event.preventDefault();
        event.stopPropagation();
        closeReceiptModal();
    }
});

async function legacyDeleteReceiptDisabled(saleId) {
    const approved = await RetailMindUI.confirm({title:'Delete receipt',message:'This action cannot be undone.',confirmText:'Delete receipt',danger:true});
    if (!approved) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= htmlspecialchars(app_url('components/invoice/sales.php?action=delete&sale_id=')) ?>' + saleId;
    form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">';
    document.body.appendChild(form);
    form.submit();
}

</script>
</body>
</html>
