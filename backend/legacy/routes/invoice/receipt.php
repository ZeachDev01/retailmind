<?php
// invoice/receipt.php - CRUD interface for receipts
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role(['admin', 'super_admin', 'inventory_manager', 'cashier']);

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'view'], true)) {
    $action = 'list';
}
$sale_id = (int)($_GET['sale_id'] ?? $_GET['id'] ?? $_GET['receipt_id'] ?? 0);
$search_query = $_GET['search'] ?? '';
$search_field = $_GET['field'] ?? 'all';
$can_manage_all = in_array(current_role(), ['admin', 'super_admin', 'inventory_manager'], true);

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

function receipt_fetch_sale(PDO $pdo, int $sale_id): ?array {
    $saleColumns = receipt_table_columns($pdo, 'sales');
    $cashReceivedSql = isset($saleColumns['cash_received']) ? 's.cash_received' : 'NULL';
    $changeDueSql = isset($saleColumns['change_due']) ? 's.change_due' : 'NULL';
    $paymentReferenceSql = isset($saleColumns['payment_reference']) ? 's.payment_reference' : 'NULL';
    $discountSql = isset($saleColumns['discount_amount']) ? 's.discount_amount' : '0.00';

    $saleStmt = $pdo->prepare(
        "SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date,
                {$cashReceivedSql} AS cash_received,
                {$changeDueSql} AS change_due,
                {$paymentReferenceSql} AS payment_reference,
                {$discountSql} AS discount_amount,
                u.full_name AS cashier_name
         FROM sales s
         JOIN users u ON s.cashier_id = u.user_id
         WHERE s.sale_id = ?"
    );
    $saleStmt->execute([$sale_id]);
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

function receipt_render_details(array $sale, array $items): void {
    $store = receipt_store_info();
    $itemSubtotal = array_reduce($items, fn($total, $item) => $total + (float)$item['subtotal'], 0.0);
    $quantityTotal = array_reduce($items, fn($total, $item) => $total + (int)$item['quantity'], 0);
    $discount = max((float)($sale['discount_amount'] ?? 0), $itemSubtotal - (float)$sale['total_amount']);
    $verificationCode = receipt_verification_code($sale);
    $receiptUrl = app_url('invoice/receipt.php?sale_id=' . (int)$sale['sale_id']);
    ?>
    <div class="receipt-container">
        <div class="receipt-actions no-print">
            <button type="button" class="btn btn-secondary" onclick="printReceiptSection(this)">Print</button>
            <button type="button" class="btn" onclick="exportReceiptPdf(this)">PDF</button>
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

// Fetch receipts for list view
if ($action === 'list' || $action === 'view') {
    $query = "SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date,
              u.full_name AS cashier_name, COUNT(si.sale_item_id) as item_count,
              COALESCE(reversal_summary.pending_count, 0) AS pending_reversals,
              COALESCE(reversal_summary.approved_count, 0) AS approved_reversals
              FROM sales s
              JOIN users u ON s.cashier_id = u.user_id
              LEFT JOIN sale_items si ON s.sale_id = si.sale_id
              LEFT JOIN (
                SELECT sale_id,
                       SUM(status = 'pending') AS pending_count,
                       SUM(status = 'approved') AS approved_count
                FROM sale_reversals
                GROUP BY sale_id
              ) reversal_summary ON reversal_summary.sale_id = s.sale_id";
    
    $params = [];
    $where_conditions = [];
    
    if (!$can_manage_all) {
        $where_conditions[] = "s.cashier_id = ?";
        $params[] = $_SESSION['user_id'];
    }
    
    if (!empty($search_query)) {
        if ($search_field === 'all') {
            $where_conditions[] = "(s.sale_id LIKE ? OR s.sale_date LIKE ? OR u.full_name LIKE ?)";
            $search_term = "%$search_query%";
            $params = array_merge($params, [$search_term, $search_term, $search_term]);
        } elseif ($search_field === 'id') {
            $where_conditions[] = "s.sale_id LIKE ?";
            $params[] = "%$search_query%";
        } elseif ($search_field === 'date') {
            $where_conditions[] = "s.sale_date LIKE ?";
            $params[] = "%$search_query%";
        } elseif ($search_field === 'cashier') {
            $where_conditions[] = "u.full_name LIKE ?";
            $params[] = "%$search_query%";
        }
    }
    
    if (!empty($where_conditions)) {
        $query .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    $query .= " GROUP BY s.sale_id ORDER BY s.sale_date DESC, s.sale_id DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $receipts = $stmt->fetchAll();
}

// Handle AJAX view request
if ($action === 'view' && isset($_GET['ajax']) && $sale_id > 0) {
    $saleStmt = $pdo->prepare(
        "SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date, u.full_name AS cashier_name
         FROM sales s
         JOIN users u ON s.cashier_id = u.user_id
         WHERE s.sale_id = ?"
    );
    $saleStmt->execute([$sale_id]);
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

    $sale = receipt_fetch_sale($pdo, $sale_id);
    receipt_render_details($sale, receipt_fetch_items($pdo, $sale_id));
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
                    <div style="color:var(--muted);">Official sales invoice</div>
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
    $selected_sale = receipt_fetch_sale($pdo, $sale_id);
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
<title>Receipt Management</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
<style>
    .receipts-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 2rem 1rem;
    }
    
    .search-section {
        display: flex;
        gap: 1rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }
    
    .search-group {
        display: flex;
        gap: 0.5rem;
        flex: 1;
        min-width: 250px;
    }
    
    .search-group input {
        flex: 1;
        padding: 0.75rem;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 0.95rem;
    }
    
    .search-group select {
        padding: 0.75rem;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 0.95rem;
    }
    
    .receipts-table {
        width: 100%;
        border-collapse: collapse;
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    }
    
    .receipts-table thead {
        background: #f9fafb;
        border-bottom: 1px solid var(--border);
    }
    
    .receipts-table th {
        padding: 1rem;
        text-align: left;
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--muted);
        text-transform: uppercase;
    }
    
    .receipts-table td {
        padding: 1rem;
        border-bottom: 1px solid var(--border);
    }
    
    .receipts-table tr:last-child td {
        border-bottom: none;
    }
    
    .receipts-table tbody tr:hover {
        background: #fafbfc;
    }
    
    .receipt-id {
        font-weight: 600;
        color: var(--primary);
    }
    
    .actions-cell {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    .btn-small {
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }
    
    .btn-view {
        background: #3b82f6;
        color: white;
    }
    
    .btn-view:hover {
        background: #2563eb;
    }
    
    .btn-edit {
        background: #10b981;
        color: white;
    }
    
    .btn-edit:hover {
        background: #059669;
    }
    
    .btn-delete {
        background: #ef4444;
        color: white;
    }
    
    .btn-delete:hover {
        background: #dc2626;
    }
    
    .empty-state {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 3rem 2rem;
        text-align: center;
        box-shadow: 0 14px 35px rgba(15, 23, 42, 0.08);
    }
    
    .empty-state h2 {
        font-size: 1.85rem;
        margin-bottom: 0.75rem;
    }
    
    .empty-state p {
        color: var(--muted);
        font-size: 1rem;
        line-height: 1.75;
        max-width: 560px;
        margin: 0 auto 1.5rem;
    }
    
    .alert {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }
    
    .modal.active {
        display: flex !important;
    }
    
    .modal-content {
        background: white;
        border-radius: 12px;
        max-width: 900px;
        max-height: 90vh;
        overflow-y: auto;
        width: 90%;
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1.5rem;
        border-bottom: 1px solid var(--border);
    }
    
    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0.5rem;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--muted);
        transition: color 0.2s ease;
    }
    
    .modal-close:hover {
        color: var(--text-primary);
    }
    
    .receipt-container {
        padding: 1.5rem;
    }
    
    .receipt-card {
        background: white;
    }
    
    .receipt-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
        padding-bottom: 1rem;
        border-bottom: 1px dashed var(--border);
    }
    
    .receipt-brand h1 {
        font-size: 1.5rem;
        margin-bottom: 0.2rem;
    }
    
    .receipt-meta {
        color: var(--muted);
        font-size: 0.92rem;
        line-height: 1.6;
        text-align: right;
    }
    
    .receipt-summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
        margin: 1rem 0 1.25rem;
    }
    
    .receipt-summary div {
        background: #f9fafb;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 0.9rem;
    }
    
    .receipt-summary span {
        display: block;
        color: var(--muted);
        font-size: 0.8rem;
        margin-bottom: 0.25rem;
    }
    
    .receipt-summary strong {
        font-size: 1rem;
    }
    
    .receipt-table {
        width: 100%;
        border-collapse: collapse;
        margin: 1rem 0;
    }
    
    .receipt-table thead {
        border-bottom: 2px solid var(--border);
    }
    
    .receipt-table th {
        text-align: left;
        padding: 0.75rem;
        font-weight: 600;
    }
    
    .receipt-table td {
        padding: 0.75rem;
        border-bottom: 1px solid var(--border);
    }
    
    .receipt-table td:last-child,
    .receipt-table th:last-child {
        text-align: right;
    }
    
    .receipt-total {
        margin-top: 1rem;
        display: flex;
        justify-content: flex-end;
        font-size: 1.15rem;
        font-weight: 700;
    }

    .receipt-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .receipt-store-line,
    .receipt-brand div {
        color: var(--muted);
        font-size: 0.9rem;
        line-height: 1.55;
    }

    .receipt-bottom {
        display: grid;
        grid-template-columns: minmax(220px, 0.8fr) minmax(260px, 1fr);
        gap: 1rem;
        align-items: start;
        margin-top: 1rem;
    }

    .receipt-verification {
        border: 1px dashed var(--border);
        border-radius: 8px;
        padding: 1rem;
        text-align: center;
        word-break: break-word;
    }

    .verification-box {
        border: 2px solid #111827;
        display: inline-grid;
        place-items: center;
        min-width: 132px;
        min-height: 132px;
        padding: 0.75rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        line-height: 1.4;
        color: #111827;
        background:
            linear-gradient(90deg, transparent 48%, rgba(17, 24, 39, 0.08) 49%, rgba(17, 24, 39, 0.08) 51%, transparent 52%),
            linear-gradient(0deg, transparent 48%, rgba(17, 24, 39, 0.08) 49%, rgba(17, 24, 39, 0.08) 51%, transparent 52%),
            #fff;
    }

    .receipt-url {
        margin-top: 0.45rem;
        color: var(--muted);
        font-size: 0.78rem;
    }

    .receipt-totals {
        display: grid;
        gap: 0.45rem;
        margin-left: auto;
        width: min(360px, 100%);
    }

    .receipt-totals div {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        border-bottom: 1px solid var(--border);
        padding-bottom: 0.45rem;
    }

    .receipt-totals div:nth-child(3) {
        font-size: 1.1rem;
        font-weight: 800;
        color: #111827;
    }

    .receipt-totals span {
        color: var(--muted);
    }

    @media print {
        body {
            background: #fff;
        }

        body * {
            visibility: hidden;
        }

        .receipt-print-target,
        .receipt-print-target * {
            visibility: visible;
        }

        .receipt-print-target {
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            padding: 0;
        }

        .no-print {
            display: none !important;
        }
    }
    
    @media (max-width: 768px) {
        .search-section {
            flex-direction: column;
        }
        
        .search-group {
            min-width: auto;
        }
        
        .receipt-summary {
            grid-template-columns: 1fr;
        }
        
        .receipt-meta {
            text-align: left;
        }

        .receipt-bottom {
            grid-template-columns: 1fr;
        }
    }
</style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../../../modules/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>Receipt Management</h1>
            <span class="badge-role"><?= ucfirst(htmlspecialchars(current_role())) ?></span>
        </div>
        <div class="receipts-container">
            <?php if ($selected_error): ?>
                <div class="alert alert-success"><?= htmlspecialchars($selected_error) ?></div>
            <?php endif; ?>

            <?php if ($selected_sale): ?>
                <?php receipt_render_details($selected_sale, $selected_items); ?>
            <?php endif; ?>

            <div class="alert alert-success">Completed receipts are locked. Use reversals for cancellations, returns, refunds, and exchanges.</div>
            
            <div class="search-section">
                <div class="search-group">
                    <input type="text" id="search-input" placeholder="Search..." value="<?= htmlspecialchars($search_query) ?>">
                    <select id="search-field">
                        <option value="all" <?= $search_field === 'all' ? 'selected' : '' ?>>All Fields</option>
                        <option value="id" <?= $search_field === 'id' ? 'selected' : '' ?>>Receipt #</option>
                        <option value="date" <?= $search_field === 'date' ? 'selected' : '' ?>>Date</option>
                        <option value="cashier" <?= $search_field === 'cashier' ? 'selected' : '' ?>>Cashier</option>
                    </select>
                    <button class="btn btn-primary" onclick="performSearch()">🔍 Search</button>
                </div>
                <a href="<?= htmlspecialchars(app_url('cashier/pos.php')) ?>" class="btn">➕ New Sale</a>
            </div>
            
            <?php if (empty($receipts)): ?>
                <div class="empty-state">
                    <h2>No receipts found</h2>
                    <p><?= $search_query ? 'Try adjusting your search criteria.' : 'No receipts available yet. Run a sale to generate your first receipt.' ?></p>
                    <div class="empty-actions">
                        <a class="btn" href="<?= htmlspecialchars(app_url('cashier/pos.php')) ?>">Start a Sale</a>
                    </div>
                </div>
            <?php else: ?>
                <table class="receipts-table">
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
                    <tbody>
                        <?php foreach ($receipts as $receipt): ?>
                        <tr>
                            <td class="receipt-id">#<?= $receipt['sale_id'] ?></td>
                            <td><?= htmlspecialchars($receipt['sale_date']) ?></td>
                            <td><?= htmlspecialchars($receipt['cashier_name']) ?></td>
                            <td><?= (int)$receipt['item_count'] ?></td>
                            <td><strong>₱<?= number_format($receipt['total_amount'], 2) ?></strong></td>
                            <td><?= htmlspecialchars(strtoupper($receipt['payment_method'])) ?></td>
                            <td>
                                <?php if ((int)$receipt['pending_reversals'] > 0): ?>
                                    <span class="tag-warning"><?= (int)$receipt['pending_reversals'] ?> pending</span>
                                <?php endif; ?>
                                <?php if ((int)$receipt['approved_reversals'] > 0): ?>
                                    <span class="tag-success"><?= (int)$receipt['approved_reversals'] ?> approved</span>
                                <?php endif; ?>
                                <?php if ((int)$receipt['pending_reversals'] === 0 && (int)$receipt['approved_reversals'] === 0): ?>
                                    <span style="color:var(--muted);">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions-cell">
                                    <button class="btn-small btn-view" onclick="viewReceipt(<?= $receipt['sale_id'] ?>)">👁️ View</button>
                                    <a href="<?= htmlspecialchars(app_url('invoice/reversals.php?sale_id=' . $receipt['sale_id'])) ?>" class="btn-small btn-edit">Reverse</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                <button type="button" class="modal-close" title="Close" onclick="window.location.href='<?= htmlspecialchars(app_url('invoice/receipt.php')) ?>'">&times;</button>
            </div>
            <div class="receipt-container">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    
                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Receipt #</label>
                        <input type="text" value="<?= $sale['sale_id'] ?>" disabled style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; background: #f9fafb;">
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Date</label>
                        <input type="text" value="<?= htmlspecialchars($sale['sale_date']) ?>" disabled style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; background: #f9fafb;">
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Cashier</label>
                        <input type="text" value="<?= htmlspecialchars($sale['cashier_name']) ?>" disabled style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; background: #f9fafb;">
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Payment Method</label>
                        <select name="payment_method" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px;">
                            <option value="CASH" <?= $sale['payment_method'] === 'CASH' ? 'selected' : '' ?>>Cash</option>
                            <option value="CARD" <?= $sale['payment_method'] === 'CARD' ? 'selected' : '' ?>>Card</option>
                            <option value="CHECK" <?= $sale['payment_method'] === 'CHECK' ? 'selected' : '' ?>>Check</option>
                            <option value="ONLINE" <?= $sale['payment_method'] === 'ONLINE' ? 'selected' : '' ?>>Online</option>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Total Amount</label>
                        <input type="text" value="₱<?= number_format($sale['total_amount'], 2) ?>" disabled style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 8px; background: #f9fafb;">
                    </div>
                    
                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                        <a href="<?= htmlspecialchars(app_url('invoice/receipt.php')) ?>" class="btn">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script>
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

function performSearch() {
    const search = document.getElementById('search-input').value;
    const field = document.getElementById('search-field').value;
    const url = new URL(window.location);
    url.searchParams.set('search', search);
    url.searchParams.set('field', field);
    window.location = url.toString();
}

function viewReceipt(saleId) {
    const url = '<?= htmlspecialchars(app_url('invoice/receipt.php')) ?>?action=view&sale_id=' + saleId + '&ajax=1';
    
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
            alert('Failed to load receipt. Please try again.');
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

function legacyDeleteReceiptDisabled(saleId) {
    if (!confirm('Are you sure you want to delete this receipt? This action cannot be undone.')) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= htmlspecialchars(app_url('invoice/receipt.php?action=delete&sale_id=')) ?>' + saleId;
    form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">';
    document.body.appendChild(form);
    form.submit();
}

document.getElementById('search-input').addEventListener('keypress', (e) => {
    if (e.key === 'Enter') performSearch();
});
</script>
</body>
</html>
