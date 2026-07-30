<?php
// cashier/stock_receiving.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';
require_role(['admin', 'cashier']);

$receivingService = new ReceivingService($pdo);

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    try {
        $result = $receivingService->receiveStock([
            'product_id' => $_POST['product_id'] ?? 0,
            'received_qty' => $_POST['received_qty'] ?? 0,
            'damaged_qty' => $_POST['damaged_qty'] ?? 0,
            'cost_price' => $_POST['cost_price'] ?? 0,
            'supplier' => $_POST['supplier'] ?? '',
            'po_number' => $_POST['po_number'] ?? '',
            'invoice_number' => $_POST['invoice_number'] ?? '',
            'batch_number' => $_POST['batch_number'] ?? '',
            'expiration_date' => $_POST['expiration_date'] ?? '',
            'discrepancy_type' => $_POST['discrepancy_type'] ?? 'none',
            'discrepancy_qty' => $_POST['discrepancy_qty'] ?? 0,
            'discrepancy_notes' => $_POST['discrepancy_notes'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'replenishment_request_id' => $_POST['replenishment_request_id'] ?? 0,
        ], $_SESSION['user_id']);

        $message = "Stock received successfully (ID: {$result['receiving_id']}). Inventory updated with {$result['accepted_qty']} accepted units.";
        if ($result['damaged_qty'] > 0) {
            $message .= " {$result['damaged_qty']} damaged units were reported.";
        }
        if ($result['remaining_qty'] !== null) {
            $message .= " Remaining request quantity: {$result['remaining_qty']}.";
        }
        $productId = $_POST['product_id'] ?? 0;
        $receivedQty = $_POST['received_qty'] ?? 0;
        log_activity($pdo, $_SESSION['user_id'], 'Received stock: Product #' . $productId . ', Qty: ' . $receivedQty);
    } catch (Exception $e) {
        $error = "Error recording receipt: " . $e->getMessage();
    }
}

$pending_requests = $receivingService->getPendingReplenishmentRequests();
$products = $receivingService->getActiveProducts();
$history = $receivingService->getReceivingHistory($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Receiving</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <style>
        .receiving-form {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .form-section {
            margin-bottom: 1.5rem;
        }
        .form-section h4 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #eee;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.75rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.9rem;
            font-family: inherit;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }
        .required::after {
            content: " *";
            color: #dc3545;
        }
        .btn-submit {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            width: fit-content;
        }
        .btn-submit:hover {
            background: #218838;
        }
        .message {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .pending-requests {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .pending-requests h4 {
            margin-top: 0;
        }
        .request-item {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .request-item .info {
            flex: 1;
        }
        .request-item .sku {
            font-family: monospace;
            color: #666;
            font-size: 0.85rem;
        }
        .request-item .progress {
            color: #555;
            font-size: 0.85rem;
            margin-top: 0.2rem;
        }
        .request-item .btn-link {
            background: #007bff;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            text-decoration: none;
        }
        .request-item .btn-link:hover {
            background: #0056b3;
        }
        .history-section {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .history-table thead {
            background: #f5f5f5;
        }
        .history-table th,
        .history-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .history-table th {
            font-weight: 600;
        }
        .history-table tr:hover {
            background: #f9f9f9;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>Stock Receiving</h1>
        </div>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($pending_requests)): ?>
            <div class="pending-requests">
                <h4>📦 Pending Replenishment Requests</h4>
                <p>These approved requests are waiting for stock to be received:</p>
                <?php foreach ($pending_requests as $req): ?>
                    <div class="request-item">
                        <div class="info">
                            <strong><?= htmlspecialchars($req['product_name']) ?></strong> - Qty: <?= $req['request_qty'] ?> units<br>
                            <span class="sku"><?= htmlspecialchars($req['sku']) ?></span>
                            <div class="progress">
                                Received: <?= (int)$req['received_to_date'] ?> |
                                Remaining: <?= (int)$req['remaining_qty'] ?>
                            </div>
                        </div>
                        <button class="request-item btn-link" onclick="populateForm(<?= (int)$req['product_id'] ?>, <?= (int)$req['remaining_qty'] ?>, <?= (int)$req['request_id'] ?>)">
                            Receive Now
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="receiving-form">
            <h3>Record Stock Receipt</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" id="replenishment_request_id" name="replenishment_request_id" value="">

                <div class="form-section">
                    <h4>Product Information</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="product_id" class="required">Product</label>
                            <select name="product_id" id="product_id" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['product_id'] ?>" data-cost="<?= htmlspecialchars($p['cost_price'] ?? '0') ?>">
                                        <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['sku']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="received_qty" class="required">Quantity Received</label>
                            <input type="number" name="received_qty" id="received_qty" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="damaged_qty">Damaged on Delivery</label>
                            <input type="number" name="damaged_qty" id="damaged_qty" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label for="cost_price">Purchase Cost / Unit</label>
                            <input type="number" name="cost_price" id="cost_price" min="0" step="0.01" placeholder="0.00">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>Supplier & Document Information</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="supplier">Supplier</label>
                            <input type="text" name="supplier" id="supplier" placeholder="Supplier name">
                        </div>
                        <div class="form-group">
                            <label for="po_number">PO Number</label>
                            <input type="text" name="po_number" id="po_number" placeholder="Purchase order #">
                        </div>
                        <div class="form-group">
                            <label for="invoice_number">Invoice Number</label>
                            <input type="text" name="invoice_number" id="invoice_number" placeholder="Invoice #">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>Product Details</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="batch_number">Batch/Lot Number</label>
                            <input type="text" name="batch_number" id="batch_number" placeholder="Batch #">
                        </div>
                        <div class="form-group">
                            <label for="expiration_date">Expiration Date</label>
                            <input type="date" name="expiration_date" id="expiration_date">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>Delivery Discrepancy</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="discrepancy_type">Discrepancy Type</label>
                            <select name="discrepancy_type" id="discrepancy_type">
                                <option value="none">None</option>
                                <option value="short">Short Delivery</option>
                                <option value="over">Over Delivery</option>
                                <option value="damaged">Damaged Items</option>
                                <option value="documentation">Document Mismatch</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="discrepancy_qty">Discrepancy Quantity</label>
                            <input type="number" name="discrepancy_qty" id="discrepancy_qty" min="0" value="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="discrepancy_notes">Discrepancy Details</label>
                        <textarea name="discrepancy_notes" id="discrepancy_notes" placeholder="Shortage, overage, damage condition, or document mismatch details..."></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-group">
                        <label for="notes">Notes / Comments</label>
                        <textarea name="notes" id="notes" placeholder="Any additional notes about this receipt..."></textarea>
                    </div>
                </div>

                <button type="submit" class="btn-submit">Record Receipt & Update Inventory</button>
            </form>
        </div>

        <?php if (!empty($history)): ?>
            <div class="history-section">
                <h3>Recent Receipt History (My Receipts)</h3>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Receipt ID</th>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Delivered</th>
                            <th>Accepted</th>
                            <th>Damaged</th>
                            <th>Batch / Expiry</th>
                            <th>Cost</th>
                            <th>Supplier</th>
                            <th>PO #</th>
                            <th>Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td>#<?= $h['receiving_id'] ?></td>
                                <td><?= htmlspecialchars($h['product_name']) ?></td>
                                <td><code><?= htmlspecialchars($h['sku']) ?></code></td>
                                <td><?= $h['received_qty'] ?></td>
                                <td><?= $h['accepted_qty'] ?></td>
                                <td><?= $h['damaged_qty'] ?></td>
                                <td>
                                    <?= htmlspecialchars($h['batch_number'] ?? '-') ?><br>
                                    <small><?= htmlspecialchars($h['expiration_date'] ?? '-') ?></small>
                                </td>
                                <td><?= $h['cost_price'] !== null ? number_format((float)$h['cost_price'], 2) : '-' ?></td>
                                <td><?= htmlspecialchars($h['supplier'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($h['po_number'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($h['received_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function populateForm(productId, qty, requestId) {
    document.getElementById('product_id').value = productId;
    document.getElementById('received_qty').value = qty;
    document.getElementById('replenishment_request_id').value = requestId;
    fillProductCost();
    document.getElementById('received_qty').focus();
    window.scrollTo(0, document.querySelector('.receiving-form').offsetTop - 100);
}

function fillProductCost() {
    const select = document.getElementById('product_id');
    const option = select.options[select.selectedIndex];
    if (option && option.dataset.cost && !document.getElementById('cost_price').value) {
        document.getElementById('cost_price').value = Number(option.dataset.cost).toFixed(2);
    }
}

document.getElementById('product_id').addEventListener('change', fillProductCost);
document.getElementById('damaged_qty').addEventListener('input', function () {
    const damagedQty = Number(this.value || 0);
    if (damagedQty > 0 && document.getElementById('discrepancy_type').value === 'none') {
        document.getElementById('discrepancy_type').value = 'damaged';
        document.getElementById('discrepancy_qty').value = damagedQty;
    }
});
</script>
</body>
</html>
