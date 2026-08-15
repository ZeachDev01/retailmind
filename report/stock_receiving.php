<?php
// cashier/stock_receiving.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';
require_role(['admin', 'super_admin', 'cashier']);

$receivingService = new ReceivingService($pdo);

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    try {
        $result = $receivingService->receiveStock([
            'product_id' => $_POST['product_id'] ?? 0,
            'quantity_mode' => $_POST['quantity_mode'] ?? 'units',
            'received_qty' => $_POST['received_qty'] ?? 0,
            'received_packages' => $_POST['received_packages'] ?? 0,
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
            'purchase_order_item_id' => $_POST['purchase_order_item_id'] ?? 0,
            'emergency_reason' => $_POST['emergency_reason'] ?? '',
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

$po_items = $receivingService->getReceivablePurchaseOrderItems();
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
    <?php include __DIR__ . '/../modules/sidebar.php'; ?>
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

        <?php if (!empty($po_items)): ?>
            <div class="pending-requests">
                <h4>Approved Purchase Orders</h4>
                <p>Receive against a purchase-order line to keep ordered and received quantities synchronized.</p>
                <?php foreach ($po_items as $item): ?>
                    <div class="request-item">
                        <div class="info"><strong><?= htmlspecialchars($item['po_number']) ?> — <?= htmlspecialchars($item['product_name']) ?></strong><br>
                            <span class="sku"><?= htmlspecialchars($item['sku']) ?> · <?= htmlspecialchars($item['supplier_name'] ?? 'Supplier not assigned') ?></span>
                            <div class="progress">Received <?= (int)$item['received_qty'] ?> · Remaining <?= (int)$item['remaining_qty'] ?> <?= htmlspecialchars($item['base_unit'] ?? 'piece') ?>(s)</div>
                        </div>
                        <button type="button" class="request-item btn-link" onclick='populatePO(<?= json_encode([
                            "item_id"=>(int)$item["purchase_order_item_id"],"request_id"=>(int)($item["replenishment_request_id"]??0),
                            "product_id"=>(int)$item["product_id"],"remaining"=>(int)$item["remaining_qty"],"cost"=>(float)$item["unit_cost"],
                            "po_number"=>$item["po_number"],"supplier"=>$item["supplier_name"]??"","units_per_package"=>(int)$item["units_per_package"]
                        ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Receive PO</button>
                    </div>
                <?php endforeach; ?>
            </div>
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
                <input type="hidden" id="purchase_order_item_id" name="purchase_order_item_id" value="">

                <div class="form-section">
                    <h4>Product Information</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="product_id" class="required">Product</label>
                            <select name="product_id" id="product_id" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['product_id'] ?>" data-cost="<?= htmlspecialchars($p['cost_price'] ?? '0') ?>" data-units-per-package="<?= (int)($p['units_per_package'] ?? 1) ?>" data-base-unit="<?= htmlspecialchars($p['base_unit'] ?? 'piece') ?>" data-receiving-unit="<?= htmlspecialchars($p['receiving_unit'] ?? 'package') ?>">
                                        <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['sku']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="quantity_mode">Receiving quantity type</label>
                            <select name="quantity_mode" id="quantity_mode"><option value="units">Base units</option><option value="packages">Packages / cases</option></select>
                        </div>
                        <div class="form-group" id="units_qty_group">
                            <label for="received_qty">Base units received</label>
                            <input type="number" name="received_qty" id="received_qty" min="0" value="0">
                        </div>
                        <div class="form-group" id="packages_qty_group" style="display:none">
                            <label for="received_packages">Packages received</label>
                            <input type="number" name="received_packages" id="received_packages" min="0" value="0">
                            <small id="package_conversion">Select a product to view conversion.</small>
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

                <?php if (in_array(current_role(), ['admin','super_admin'], true)): ?>
                <div class="form-section"><h4>Emergency receiving</h4><div class="form-group"><label for="emergency_reason">Reason when no approved request or PO is selected</label><input type="text" name="emergency_reason" id="emergency_reason" placeholder="Required only for administrator emergency receiving"></div></div>
                <?php endif; ?>

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
                                <td><?= $h['received_qty'] ?><?php if((int)($h['received_packages']??0)>0): ?><br><small><?= (int)$h['received_packages'] ?> package(s) × <?= (int)$h['units_per_package_used'] ?></small><?php endif; ?></td>
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
function resetReferences() {
    document.getElementById('replenishment_request_id').value = '';
    document.getElementById('purchase_order_item_id').value = '';
}
function populateForm(productId, qty, requestId) {
    resetReferences();
    document.getElementById('product_id').value = productId;
    document.getElementById('quantity_mode').value = 'units';
    document.getElementById('received_qty').value = qty;
    document.getElementById('replenishment_request_id').value = requestId;
    updateQuantityMode(); fillProductCost();
    document.getElementById('received_qty').focus();
    window.scrollTo(0, document.querySelector('.receiving-form').offsetTop - 100);
}
function populatePO(item) {
    resetReferences();
    document.getElementById('purchase_order_item_id').value = item.item_id;
    document.getElementById('replenishment_request_id').value = item.request_id || '';
    document.getElementById('product_id').value = item.product_id;
    document.getElementById('quantity_mode').value = 'units';
    document.getElementById('received_qty').value = item.remaining;
    document.getElementById('cost_price').value = Number(item.cost || 0).toFixed(2);
    document.getElementById('po_number').value = item.po_number || '';
    document.getElementById('supplier').value = item.supplier || '';
    updateQuantityMode(); updatePackageConversion();
    window.scrollTo(0, document.querySelector('.receiving-form').offsetTop - 100);
}
function selectedProductOption() {
    const select=document.getElementById('product_id'); return select.options[select.selectedIndex];
}
function fillProductCost() {
    const option=selectedProductOption();
    if (option && option.dataset.cost && !document.getElementById('cost_price').value) document.getElementById('cost_price').value=Number(option.dataset.cost).toFixed(2);
    updatePackageConversion();
}
function updatePackageConversion() {
    const option=selectedProductOption(); const units=Number(option?.dataset.unitsPerPackage||1); const receiving=option?.dataset.receivingUnit||'package'; const base=option?.dataset.baseUnit||'piece';
    document.getElementById('package_conversion').textContent=`1 ${receiving} = ${units} ${base}(s)`;
}
function updateQuantityMode() {
    const packages=document.getElementById('quantity_mode').value==='packages';
    document.getElementById('units_qty_group').style.display=packages?'none':'flex';
    document.getElementById('packages_qty_group').style.display=packages?'flex':'none';
    document.getElementById('received_qty').required=!packages;
    document.getElementById('received_packages').required=packages;
    updatePackageConversion();
}
document.getElementById('product_id').addEventListener('change', ()=>{resetReferences();fillProductCost();});
document.getElementById('quantity_mode').addEventListener('change', updateQuantityMode);
document.getElementById('damaged_qty').addEventListener('input', function () {
    const damagedQty=Number(this.value||0); if(damagedQty>0&&document.getElementById('discrepancy_type').value==='none'){document.getElementById('discrepancy_type').value='damaged';document.getElementById('discrepancy_qty').value=damagedQty;}
});
updateQuantityMode();
</script>
</body>
</html>
