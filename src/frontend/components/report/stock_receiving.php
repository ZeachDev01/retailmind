<?php
// cashier/stock_receiving.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/ReceivingService.php';
require_role(['admin', 'super_admin', 'inventory_manager']);

$receivingService = new ReceivingService($pdo);

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
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
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The receiving record could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.');
    }
}

$po_items = $receivingService->getReceivablePurchaseOrderItems();
$pending_requests = $receivingService->getPendingReplenishmentRequests();
$po_request_ids = array_fill_keys(array_map(
    'intval',
    array_filter(array_column($po_items, 'replenishment_request_id'))
), true);
$scan_pending_requests = array_values(array_filter(
    $pending_requests,
    static fn(array $request): bool => !isset($po_request_ids[(int)$request['request_id']])
));
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
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
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
            <div class="form-section">
                <h4>Scan Product</h4>
                <div class="form-group">
                    <label for="product_code_scan">Unit barcode or SKU</label>
                    <input type="text" id="product_code_scan" autocomplete="off" inputmode="text" placeholder="Scan or type a code, then press Enter">
                    <small id="scan_status" role="status" aria-live="polite">You can also choose a product manually below.</small>
                </div>
                <div id="scan_document_review" class="pending-requests" hidden aria-live="polite"></div>
            </div>
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
const productCodeScan = document.getElementById('product_code_scan');
const scanStatus = document.getElementById('scan_status');
const scanDocumentReview = document.getElementById('scan_document_review');
const receivableDocuments = <?= json_encode(array_merge(
    array_map(static fn(array $item): array => [
        'type' => 'purchase_order',
        'product_id' => (int)$item['product_id'],
        'purchase_order_item_id' => (int)$item['purchase_order_item_id'],
        'replenishment_request_id' => (int)($item['replenishment_request_id'] ?? 0),
        'label' => (string)$item['po_number'],
        'supplier' => (string)($item['supplier_name'] ?? ''),
        'cost' => (float)$item['unit_cost'],
        'remaining_qty' => (int)$item['remaining_qty'],
        'unit' => (string)($item['base_unit'] ?? 'unit'),
    ], $po_items),
    array_map(static fn(array $request): array => [
        'type' => 'replenishment_request',
        'product_id' => (int)$request['product_id'],
        'replenishment_request_id' => (int)$request['request_id'],
        'label' => 'Request #' . (int)$request['request_id'],
        'supplier' => '',
        'cost' => 0,
        'remaining_qty' => (int)$request['remaining_qty'],
        'unit' => 'unit',
    ], $scan_pending_requests)
), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let activeScanLookup = 0;
let scanLookupController = null;

function hideScannedDocumentReview() {
    scanDocumentReview.replaceChildren();
    scanDocumentReview.hidden = true;
}

function chooseScannedDocument(documentOption, button) {
    applyReceivingDocument(documentOption, false);
    for (const choice of scanDocumentReview.querySelectorAll('.scan-document-choice')) {
        choice.setAttribute('aria-pressed', choice === button ? 'true' : 'false');
        choice.textContent = choice === button ? 'Selected' : 'Choose this document';
    }
    scanStatus.textContent = `${documentOption.label} selected. Enter the delivered Base units and review every delivery detail before submitting.`;
    document.getElementById('received_qty').focus();
}

function showScannedDocumentReview(productId) {
    const eligible = receivableDocuments.filter((documentOption) => documentOption.product_id === Number(productId));
    scanDocumentReview.replaceChildren();
    scanDocumentReview.hidden = false;

    const heading = document.createElement('h4');
    heading.textContent = 'Choose the receiving document';
    scanDocumentReview.appendChild(heading);

    if (eligible.length === 0) {
        const message = document.createElement('p');
        message.textContent = 'No eligible document is available. An Inventory Manager cannot record a routine receipt for this product; choose a product manually or arrange an approved request or purchase order.';
        scanDocumentReview.appendChild(message);
        return;
    }

    const guidance = document.createElement('p');
    guidance.textContent = 'Review the remaining quantity, then explicitly choose the document. Delivered units stay empty until you enter them.';
    scanDocumentReview.appendChild(guidance);
    for (const documentOption of eligible) {
        const row = document.createElement('div');
        row.className = 'request-item';
        const info = document.createElement('div');
        info.className = 'info';
        const title = document.createElement('strong');
        title.textContent = documentOption.type === 'purchase_order'
            ? `Purchase order ${documentOption.label}`
            : documentOption.label;
        const progress = document.createElement('div');
        progress.className = 'progress';
        progress.textContent = `Remaining ${documentOption.remaining_qty} ${documentOption.unit}(s)`;
        info.append(title, document.createElement('br'), progress);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'request-item btn-link scan-document-choice';
        button.textContent = 'Choose this document';
        button.setAttribute('aria-pressed', 'false');
        button.addEventListener('click', () => chooseScannedDocument(documentOption, button));
        row.append(info, button);
        scanDocumentReview.appendChild(row);
    }
}

async function identifyReceivingProduct(code) {
    const lookupId = ++activeScanLookup;
    if (scanLookupController) scanLookupController.abort();
    const controller = new AbortController();
    scanLookupController = controller;
    scanStatus.textContent = 'Checking product code…';
    try {
        const response = await fetch('../barcodeScanner/apiScanner/product_code_lookup.php?code=' + encodeURIComponent(code), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: controller.signal
        });
        const result = await response.json();
        if (lookupId !== activeScanLookup) return;
        if (!response.ok || result.success === false) {
            throw new Error(result.error || 'The product code could not be checked.');
        }
        if (result.outcome === 'unknown') {
            scanStatus.textContent = 'No product matches that code. Try again or choose the product manually.';
            return;
        }
        if (result.outcome === 'ambiguous') {
            scanStatus.textContent = 'That code matches more than one product. Choose the product manually.';
            return;
        }
        if (result.matched_code_kind === 'case_barcode') {
            scanStatus.textContent = 'Stock Receiving accepts a unit barcode or SKU for Base units. Choose the product manually for package receiving.';
            return;
        }

        const product = result.product;
        const productSelect = document.getElementById('product_id');
        productSelect.value = String(product.product_id);
        resetReferences();
        fillProductCost();
        document.getElementById('quantity_mode').value = 'units';
        document.getElementById('received_qty').value = '0';
        document.getElementById('received_packages').value = '0';
        updateQuantityMode();
        showScannedDocumentReview(product.product_id);
        scanStatus.textContent = `${product.product_name} (${product.sku}) selected. Matched by ${result.matched_code_kind === 'sku' ? 'SKU' : 'unit barcode'}. Review and choose an eligible document, then enter delivered units.`;
    } catch (error) {
        if (error.name === 'AbortError' || lookupId !== activeScanLookup) return;
        scanStatus.textContent = error.message || 'The product code could not be checked. Choose the product manually.';
    } finally {
        if (scanLookupController === controller) scanLookupController = null;
    }
}

productCodeScan.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    const code = this.value.trim();
    if (code !== '') identifyReceivingProduct(code);
});

function resetReferences() {
    document.getElementById('replenishment_request_id').value = '';
    document.getElementById('purchase_order_item_id').value = '';
}
function applyReceivingDocument(documentOption, copyRemainingQuantity) {
    resetReferences();
    document.getElementById('product_id').value = documentOption.product_id;
    document.getElementById('quantity_mode').value = 'units';
    document.getElementById('received_qty').value = copyRemainingQuantity ? documentOption.remaining_qty : 0;
    if (documentOption.type === 'purchase_order') {
        document.getElementById('purchase_order_item_id').value = documentOption.purchase_order_item_id;
        document.getElementById('replenishment_request_id').value = documentOption.replenishment_request_id || '';
        document.getElementById('cost_price').value = Number(documentOption.cost || 0).toFixed(2);
        document.getElementById('po_number').value = documentOption.label || '';
        document.getElementById('supplier').value = documentOption.supplier || '';
    } else {
        document.getElementById('replenishment_request_id').value = documentOption.replenishment_request_id;
    }
    updateQuantityMode();
    updatePackageConversion();
}
function populateForm(productId, qty, requestId) {
    applyReceivingDocument({
        type: 'replenishment_request',
        product_id: productId,
        replenishment_request_id: requestId,
        remaining_qty: qty
    }, true);
    fillProductCost();
    document.getElementById('received_qty').focus();
    window.scrollTo(0, document.querySelector('.receiving-form').offsetTop - 100);
}
function populatePO(item) {
    applyReceivingDocument({
        type: 'purchase_order',
        product_id: item.product_id,
        purchase_order_item_id: item.item_id,
        replenishment_request_id: item.request_id,
        remaining_qty: item.remaining,
        cost: item.cost,
        label: item.po_number,
        supplier: item.supplier
    }, true);
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
document.getElementById('product_id').addEventListener('change', (event)=>{
    resetReferences();
    fillProductCost();
    if (event.isTrusted) {
        activeScanLookup++;
        if (scanLookupController) scanLookupController.abort();
        hideScannedDocumentReview();
        scanStatus.textContent = 'Product selected manually. Review an eligible document above or choose one from the lists on this page.';
    }
});
document.getElementById('quantity_mode').addEventListener('change', updateQuantityMode);
document.getElementById('damaged_qty').addEventListener('input', function () {
    const damagedQty=Number(this.value||0); if(damagedQty>0&&document.getElementById('discrepancy_type').value==='none'){document.getElementById('discrepancy_type').value='damaged';document.getElementById('discrepancy_qty').value=damagedQty;}
});
updateQuantityMode();
</script>
</body>
</html>
