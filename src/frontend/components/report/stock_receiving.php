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
$canReceive = has_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
$formValues = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
    verify_csrf_token($_POST['csrf_token'] ?? '');

    try {
        $nonce = $_POST['receiving_nonce'] ?? '';
        if (!is_string($nonce) || !isset($_SESSION['receiving_nonces'][$nonce])) {
            throw new RuntimeException('This receiving form has already been saved or expired. Review a new entry before saving.');
        }
        $result = $receivingService->receiveStock([
            'product_id' => $_POST['product_id'] ?? 0,
            'quantity_mode' => $_POST['quantity_mode'] ?? 'units',
            'received_qty' => $_POST['received_qty'] ?? 0,
            'received_packages' => $_POST['received_packages'] ?? 0,
            'damaged_qty' => $_POST['damaged_qty'] ?? 0,
            'cost_price' => $_POST['cost_price'] ?? '',
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
        ], $_SESSION['user_id']);
        unset($_SESSION['receiving_nonces'][$nonce]);

        $message = "{$result['product_name']}: stock received successfully (ID: {$result['receiving_id']}). Inventory updated with {$result['accepted_qty']} accepted units.";
        if ($result['damaged_qty'] > 0) {
            $message .= " {$result['damaged_qty']} damaged units were reported.";
        }
        if ($result['remaining_qty'] !== null) {
            $message .= " Remaining request quantity: {$result['remaining_qty']}.";
        }
        $message .= " Recorded by {$result['recorded_by']} at {$result['received_at']}.";
        $productId = $_POST['product_id'] ?? 0;
        try {
            log_activity($pdo, $_SESSION['user_id'], 'Received stock: Product #' . $productId . ', Accepted units: ' . $result['accepted_qty']);
        } catch (Throwable $e) {
            error_log('Receiving activity log failed: ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        $formValues = array_filter($_POST, 'is_string');
        $error = \App\Support\OperatorAlert::message($e, 'The receiving record could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.');
    }
}

$receivingNonce = bin2hex(random_bytes(24));
$_SESSION['receiving_nonces'][$receivingNonce] = true;
$_SESSION['receiving_nonces'] = array_slice($_SESSION['receiving_nonces'], -20, null, true);

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
// Throwaway #176 rendering only; existing authentication and read-only loading stay above.
if (getenv('RECEIVING_PROTOTYPE') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['variant'])) {
    require __DIR__ . '/stock_receiving.prototype.php';
    exit;
}
?>
<!DOCTYPE html>
<html>
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Receiving</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <h1>Stock Receiving</h1>
        </div>

        <?php if ($message): ?>
            <div class="message success" role="status"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><span role="alert"><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <details>
            <summary>Optional: receive against an existing approved document</summary>
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
                <h4>Pending Replenishment Requests</h4>
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
                        <button type="button" class="request-item btn-link" onclick="populateForm(<?= (int)$req['product_id'] ?>, <?= (int)$req['remaining_qty'] ?>, <?= (int)$req['request_id'] ?>)">
                            Receive Now
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </details>

        <div class="receiving-form">
            <h3>Record Stock Receipt</h3>
            <p>Record goods bought for the Store. No supplier, request, purchase order, or approval is required.</p>
            <?php if (!$canReceive): ?><p>Switch to an authorized Inventory Manager workspace to record stock.</p><?php endif; ?>
            <fieldset <?= !$canReceive ? 'disabled' : '' ?>>
            <div class="form-section">
                <h4>Scan Product</h4>
                <div class="form-group">
                    <label for="product_code_scan">Barcode or SKU</label>
                    <input type="text" id="product_code_scan" autocomplete="off" inputmode="text" placeholder="Scan or type a code, then press Enter">
                    <small id="scan_status" role="status" aria-live="polite">You can also choose a product manually below.</small>
                </div>
                <div id="scan_document_review" class="pending-requests" hidden aria-live="polite"></div>
            </div>
            <form method="POST" id="receiving_form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="receiving_nonce" value="<?= htmlspecialchars($receivingNonce) ?>">
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
                            <input type="text" inputmode="numeric" pattern="[0-9]+" name="received_qty" id="received_qty" value="0">
                        </div>
                        <div class="form-group" id="packages_qty_group" style="display:none">
                            <label for="received_packages">Packages received</label>
                            <input type="text" inputmode="numeric" pattern="[0-9]+" name="received_packages" id="received_packages" value="0">
                            <small id="package_conversion">Select a product to view conversion.</small>
                        </div>
                        <div class="form-group">
                            <label for="damaged_qty">Damaged base units on delivery</label>
                            <input type="text" inputmode="numeric" pattern="[0-9]+" name="damaged_qty" id="damaged_qty" value="0">
                        </div>
                        <div class="form-group">
                            <label for="cost_price">Purchase cost per base unit (optional)</label>
                            <input type="text" inputmode="decimal" name="cost_price" id="cost_price" placeholder="Leave blank if unknown">
                            <small>Cost for one base unit, even when receiving cases. Blank preserves the current product cost.</small>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>Optional supplier & receipt details</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="supplier">Supplier / shopping store (optional)</label>
                            <input type="text" name="supplier" id="supplier" maxlength="150" placeholder="Where the goods were bought">
                        </div>
                        <div class="form-group">
                            <label for="po_number">PO Number</label>
                            <input type="text" name="po_number" id="po_number" placeholder="Purchase order #">
                        </div>
                        <div class="form-group">
                            <label for="invoice_number">Receipt / invoice reference (optional)</label>
                            <input type="text" name="invoice_number" id="invoice_number" maxlength="100" placeholder="Receipt reference">
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
                            <input type="text" inputmode="numeric" pattern="[0-9]+" name="discrepancy_qty" id="discrepancy_qty" value="0">
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
            </fieldset>
        </div>

        <?php if (!empty($history)): ?>
            <div class="history-section">
                <h3>Recent Receipt History (My Receipts)</h3>
                <div class="u-table-scroll" tabindex="0" role="region" aria-label="Recent receiving history">
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
                            <th>Receipt reference</th>
                            <th>Recorded by</th>
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
                                <td><?= htmlspecialchars($h['invoice_number'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($h['full_name']) ?></td>
                                <td><?= htmlspecialchars($h['received_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
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
        'po_number' => (string)$item['po_number'],
        'choice_label' => (string)$item['po_number'] . ' · Line #' . (int)$item['purchase_order_item_id'],
        'supplier' => (string)($item['supplier_name'] ?? ''),
        'cost' => (float)$item['unit_cost'],
        'remaining_qty' => (int)$item['remaining_qty'],
        'unit' => (string)($item['base_unit'] ?? 'unit'),
    ], $po_items),
    array_map(static fn(array $request): array => [
        'type' => 'replenishment_request',
        'product_id' => (int)$request['product_id'],
        'replenishment_request_id' => (int)$request['request_id'],
        'choice_label' => 'Request #' . (int)$request['request_id'],
        'supplier' => '',
        'cost' => 0,
        'remaining_qty' => (int)$request['remaining_qty'],
        'unit' => 'unit',
    ], $scan_pending_requests)
), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let activeScanLookup = 0;
let scanLookupController = null;
let costPriceWasEdited = false;

function hideScannedDocumentReview() {
    scanDocumentReview.replaceChildren();
    scanDocumentReview.hidden = true;
}

function chooseScannedDocument(documentOption, button) {
    const quantityMode = document.getElementById('quantity_mode').value;
    applyReceivingDocument(documentOption, false, quantityMode);
    for (const choice of scanDocumentReview.querySelectorAll('.scan-document-choice')) {
        choice.setAttribute('aria-pressed', choice === button ? 'true' : 'false');
        choice.textContent = choice === button ? 'Selected' : 'Choose this document';
    }
    const packages = quantityMode === 'packages';
    scanStatus.textContent = `${documentOption.choice_label} selected. Enter the delivered ${packages ? 'packages / cases' : 'Base units'} and review every delivery detail before submitting.`;
    document.getElementById(packages ? 'received_packages' : 'received_qty').focus();
}

function showScannedDocumentReview(productId) {
    const eligible = receivableDocuments.filter((documentOption) => documentOption.product_id === Number(productId));
    scanDocumentReview.replaceChildren();
    scanDocumentReview.hidden = false;

    const heading = document.createElement('h4');
    heading.textContent = 'Receiving document (optional)';
    scanDocumentReview.appendChild(heading);

    if (eligible.length === 0) {
        const message = document.createElement('p');
        message.textContent = 'Receiving documents are optional. Enter the actual bought quantity to receive this product directly.';
        scanDocumentReview.appendChild(message);
        return;
    }

    const guidance = document.createElement('p');
    guidance.textContent = 'Receive directly, or choose a document to update its received quantity. Enter actual delivered quantities yourself.';
    scanDocumentReview.appendChild(guidance);
    for (const documentOption of eligible) {
        const row = document.createElement('div');
        row.className = 'request-item';
        const info = document.createElement('div');
        info.className = 'info';
        const title = document.createElement('strong');
        title.textContent = documentOption.type === 'purchase_order'
            ? `Purchase order ${documentOption.choice_label}`
            : documentOption.choice_label;
        const progress = document.createElement('div');
        progress.className = 'progress';
        progress.textContent = `Remaining ${documentOption.remaining_qty} ${documentOption.unit}(s)`;
        info.append(title, document.createElement('br'), progress);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'request-item btn-link scan-document-choice';
        button.textContent = 'Choose this document';
        button.setAttribute('aria-label', `Choose ${documentOption.choice_label}`);
        button.setAttribute('aria-pressed', 'false');
        button.addEventListener('click', () => chooseScannedDocument(documentOption, button));
        row.append(info, button);
        scanDocumentReview.appendChild(row);
    }
}

function selectedReceivingDocument() {
    const purchaseOrderItemId = Number(document.getElementById('purchase_order_item_id').value || 0);
    if (purchaseOrderItemId > 0) {
        return receivableDocuments.find((documentOption) =>
            Number(documentOption.purchase_order_item_id || 0) === purchaseOrderItemId
        ) || null;
    }

    const requestId = Number(document.getElementById('replenishment_request_id').value || 0);
    if (requestId > 0) {
        return receivableDocuments.find((documentOption) =>
            documentOption.type === 'replenishment_request'
                && Number(documentOption.replenishment_request_id || 0) === requestId
        ) || null;
    }

    return null;
}

function hasEnteredReceivingDetails() {
    if (costPriceWasEdited || document.getElementById('quantity_mode').value !== 'units') {
        return true;
    }

    const numberFields = ['received_qty', 'received_packages', 'damaged_qty', 'discrepancy_qty'];
    if (numberFields.some((id) => Number(document.getElementById(id).value || 0) !== 0)) {
        return true;
    }

    const textFields = [
        'supplier', 'po_number', 'invoice_number', 'batch_number', 'expiration_date',
        'discrepancy_notes', 'notes'
    ];
    if (textFields.some((id) => {
        const field = document.getElementById(id);
        return field && field.value.trim() !== '';
    })) {
        return true;
    }

    return document.getElementById('discrepancy_type').value !== 'none';
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
            scanStatus.textContent = result.error || 'The product code could not be checked right now. Try again or choose the product manually.';
            return;
        }
        if (result.outcome === 'unknown') {
            scanStatus.textContent = 'No product matches that code. Try again or choose the product manually.';
            return;
        }
        if (result.outcome === 'ambiguous') {
            scanStatus.textContent = 'That code matches more than one product. Choose the product manually.';
            return;
        }
        const product = result.product;
        const matchedCase = result.matched_code_kind === 'case_barcode';
        const quantityMode = matchedCase ? 'packages' : 'units';
        const productSelect = document.getElementById('product_id');
        const selectedProductId = Number(productSelect.value || 0);
        const linkedDocument = selectedReceivingDocument();
        if (linkedDocument) {
            const documentName = linkedDocument.type === 'purchase_order'
                ? 'purchase-order line'
                : 'replenishment request';
            if (Number(product.product_id) !== Number(linkedDocument.product_id)) {
                scanStatus.textContent = `This receipt is linked to a ${documentName}. Scan that document's product or continue with the selected product.`;
                productCodeScan.value = '';
                return;
            }

            document.getElementById('quantity_mode').value = quantityMode;
            updateQuantityMode();
            scanStatus.textContent = `${product.product_name} (${product.sku}) is already linked to the selected ${documentName}. Entered delivery quantities and details were preserved.`;
            productCodeScan.value = '';
            document.getElementById(matchedCase ? 'received_packages' : 'received_qty').focus();
            return;
        }

        const hasEnteredDetails = selectedProductId > 0 && hasEnteredReceivingDetails();
        if (hasEnteredDetails
            && Number(product.product_id) !== selectedProductId
            && !window.confirm(`Replace the current product with ${product.product_name}? Your entered receiving details will stay on the form.`)) {
            scanStatus.textContent = 'Product unchanged. Your entered receiving details were preserved.';
            productCodeScan.value = '';
            return;
        }

        productSelect.value = String(product.product_id);
        resetReferences();
        updatePackageConversion();
        document.getElementById('quantity_mode').value = quantityMode;
        if (!hasEnteredDetails) {
            document.getElementById('received_qty').value = '0';
            document.getElementById('received_packages').value = '0';
        }
        updateQuantityMode();
        showScannedDocumentReview(product.product_id);
        const matchedBy = result.matched_code_kind === 'sku'
            ? 'SKU'
            : (matchedCase ? 'case barcode' : 'unit barcode');
        scanStatus.textContent = `${product.product_name} (${product.sku}) selected. Matched by ${matchedBy}. Enter actual delivered ${matchedCase ? 'packages / cases' : 'Base units'}. Linking a document is optional.`;
        productCodeScan.value = '';
    } catch (error) {
        if (error.name === 'AbortError' || lookupId !== activeScanLookup) return;
        scanStatus.textContent = 'The product code could not be checked right now. Try again or choose the product manually.';
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
function applyReceivingDocument(documentOption, copyRemainingQuantity, quantityMode = 'units') {
    resetReferences();
    document.getElementById('product_id').value = documentOption.product_id;
    document.getElementById('quantity_mode').value = quantityMode;
    if (copyRemainingQuantity) {
        document.getElementById('received_qty').value = documentOption.remaining_qty;
        document.getElementById('received_packages').value = '0';
    }
    if (documentOption.type === 'purchase_order') {
        document.getElementById('purchase_order_item_id').value = documentOption.purchase_order_item_id;
        document.getElementById('replenishment_request_id').value = documentOption.replenishment_request_id || '';
        document.getElementById('cost_price').value = Number(documentOption.cost || 0).toFixed(2);
        document.getElementById('po_number').value = documentOption.po_number || '';
        document.getElementById('supplier').value = documentOption.supplier || '';
    } else {
        document.getElementById('replenishment_request_id').value = documentOption.replenishment_request_id;
        document.getElementById('po_number').value = '';
        document.getElementById('supplier').value = '';
        document.getElementById('cost_price').value = '';
        costPriceWasEdited = false;
        updatePackageConversion();
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
    updatePackageConversion();
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
        po_number: item.po_number,
        supplier: item.supplier
    }, true);
    window.scrollTo(0, document.querySelector('.receiving-form').offsetTop - 100);
}
function selectedProductOption() {
    const select=document.getElementById('product_id'); return select.options[select.selectedIndex];
}
function updatePackageConversion() {
    const option=selectedProductOption(); const units=Number(option?.dataset.unitsPerPackage||1); const receiving=option?.dataset.receivingUnit||'package'; const base=option?.dataset.baseUnit||'piece';
    const packages = Number(document.getElementById('received_packages').value || 0);
    const damaged = Number(document.getElementById('damaged_qty').value || 0);
    document.getElementById('package_conversion').textContent=`1 ${receiving} = ${units} ${base}(s). ${packages} × ${units} = ${packages * units} units received; ${Math.max(0, packages * units - damaged)} accepted units.`;
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
    updatePackageConversion();
    if (event.isTrusted) {
        activeScanLookup++;
        if (scanLookupController) scanLookupController.abort();
        hideScannedDocumentReview();
        scanStatus.textContent = 'Product selected manually. Enter actual bought quantities. No receiving document is required.';
    }
});
document.getElementById('quantity_mode').addEventListener('change', updateQuantityMode);
document.getElementById('received_packages').addEventListener('input', updatePackageConversion);
document.getElementById('cost_price').addEventListener('input', () => {
    costPriceWasEdited = true;
});
document.getElementById('damaged_qty').addEventListener('input', function () {
    const damagedQty=Number(this.value||0); if(damagedQty>0&&document.getElementById('discrepancy_type').value==='none'){document.getElementById('discrepancy_type').value='damaged';document.getElementById('discrepancy_qty').value=damagedQty;}
    updatePackageConversion();
});
const form = document.getElementById('receiving_form');
const retainedValues = <?= json_encode($formValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
for (const [name, value] of Object.entries(retainedValues)) {
    if (['csrf_token', 'receiving_nonce'].includes(name)) continue;
    const field = form.elements.namedItem(name);
    if (!field) continue;
    if (field.tagName === 'SELECT' && !Array.from(field.options).some(option => option.value === value)) {
        field.add(new Option(value, value));
    }
    field.value = value;
    if (field.type === 'date' && value !== '' && field.value !== value) {
        field.type = 'text';
        field.placeholder = 'YYYY-MM-DD';
        field.value = value;
    }
}
let saving = false;
form.addEventListener('submit', event => {
    if (saving) { event.preventDefault(); return; }
    saving = true;
    form.querySelector('.btn-submit').disabled = true;
    form.querySelector('.btn-submit').textContent = 'Saving receipt…';
});
updateQuantityMode();
</script>
</body>
</html>

