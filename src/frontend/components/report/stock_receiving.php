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
        $entry = [
            'supplier' => $_POST['supplier'] ?? '',
            'invoice_number' => $_POST['invoice_number'] ?? '',
        ];
        if (isset($_POST['entry_mode']) || array_key_exists('lines', $_POST)) {
            $lines = $_POST['lines'] ?? [];
            if (!is_array($lines)) throw new RuntimeException('Product lines must be a list of receiving details.');
        } else {
            $lines = [[
            'product_id' => $_POST['product_id'] ?? 0,
            'quantity_mode' => $_POST['quantity_mode'] ?? 'units',
            'received_qty' => $_POST['received_qty'] ?? 0,
            'received_packages' => $_POST['received_packages'] ?? 0,
            'damaged_qty' => $_POST['damaged_qty'] ?? 0,
            'cost_price' => $_POST['cost_price'] ?? '',
            'supplier' => $_POST['supplier'] ?? '',
            'invoice_number' => $_POST['invoice_number'] ?? '',
            'batch_number' => $_POST['batch_number'] ?? '',
            'expiration_date' => $_POST['expiration_date'] ?? '',
            'discrepancy_type' => $_POST['discrepancy_type'] ?? 'none',
            'discrepancy_qty' => $_POST['discrepancy_qty'] ?? 0,
            'discrepancy_notes' => $_POST['discrepancy_notes'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            ]];
        }
        $results = $receivingService->receiveShoppingTrip($lines, $entry, (int)$_SESSION['user_id']);
        unset($_SESSION['receiving_nonces'][$nonce]);

        $summaries = [];
        foreach ($results as $result) {
            $summary = "{$result['product_name']}: {$result['accepted_qty']} accepted units";
            if ($result['damaged_qty'] > 0) $summary .= ", {$result['damaged_qty']} damaged units excluded";
            $summaries[] = $summary;
        }
        $message = 'Shopping entry saved. ' . implode('; ', $summaries)
            . ". Recorded by {$results[0]['recorded_by']} at {$results[0]['received_at']}.";
        try {
            log_activity($pdo, $_SESSION['user_id'], 'Received shopping entry: ' . implode('; ', $summaries));
        } catch (Throwable $e) {
            error_log('Receiving activity log failed: ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        $formValues = $_POST;
        $error = \App\Support\OperatorAlert::message($e, 'The receiving record could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.');
    }
}

$receivingNonce = bin2hex(random_bytes(24));
$_SESSION['receiving_nonces'][$receivingNonce] = true;
$_SESSION['receiving_nonces'] = array_slice($_SESSION['receiving_nonces'], -20, null, true);

$products = $receivingService->getActiveProducts();
?>
<!DOCTYPE html>
<html lang="en">
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
        <nav class="u-flex-wrap mb-3" aria-label="Stock replenishment">
            <a class="btn btn-secondary" href="../inventory_management/inventory_overview.php">Check stock</a>
            <a class="btn btn-secondary" href="../inventory_management/replenishment_queue.php">Review recommendations</a>
            <a class="btn btn-secondary" href="receiving_history.php">Receiving history</a>
        </nav>

        <?php if ($message): ?>
            <div class="message success" role="status"><?= htmlspecialchars($message) ?></div>
            <p>Stock is updated. <a href="../inventory_management/replenishment_queue.php">Review updated recommendations</a> using current stock and existing forecast outputs, or <a href="receiving_history.php">view receiving history</a>.</p>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><span role="alert"><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <div class="receiving-form" data-step="1">
            <h2 id="receiving_step_title" tabindex="-1">1. Select products</h2>
            <ol class="receiving-steps" aria-label="Receiving progress"><li aria-current="step">1. Select products</li><li>2. Enter quantities</li><li>3. Review</li></ol>
            <p>Select what was bought, enter actual quantities, then review and save once. Shopping store and receipt details are optional.</p>
            <?php if (!$canReceive): ?><p>Switch to an authorized Inventory Manager workspace to record stock.</p><?php endif; ?>
            <fieldset <?= !$canReceive ? 'disabled' : '' ?>>
            <div class="form-section" id="receiving_selection">
                <h3>Find products</h3>
                <div class="form-group"><label for="product_search">Search products by name or SKU</label><input type="search" id="product_search" autocomplete="off" placeholder="Search active products"></div>
                <div class="form-group">
                    <label for="product_code_scan">Barcode or SKU</label>
                    <input type="text" id="product_code_scan" autocomplete="off" inputmode="text" placeholder="Scan or type a code, then press Enter">
                    <small id="scan_target" role="status">Scanning product line 1. Focus another line to scan into it.</small>
                    <small id="scan_status" role="status" aria-live="polite">You can also choose a product manually below.</small>
                </div>
            </div>
            <form method="POST" id="receiving_form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="receiving_nonce" value="<?= htmlspecialchars($receivingNonce) ?>">
                <input type="hidden" name="entry_mode" value="shopping_trip">
                <div id="receiving_lines">
                <section class="receiving-line" aria-label="Product line 1">
                    <div class="receiving-line-heading"><h4>Product line <span class="line-number">1</span></h4>
                        <button type="button" class="btn btn-quiet remove-receiving-line">Remove product</button></div>

                <div class="form-section">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="product_id" class="required">Product</label>
                            <select name="product_id" id="product_id" data-field="product_id" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['product_id'] ?>" data-cost="<?= htmlspecialchars($p['cost_price'] ?? '0') ?>" data-units-per-package="<?= (int)($p['units_per_package'] ?? 1) ?>" data-base-unit="<?= htmlspecialchars($p['base_unit'] ?? 'piece') ?>" data-receiving-unit="<?= htmlspecialchars($p['receiving_unit'] ?? 'package') ?>">
                                        <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['sku']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group receiving-quantity">
                            <label for="quantity_mode">Receiving quantity type</label>
                            <select name="quantity_mode" id="quantity_mode" data-field="quantity_mode"><option value="units">Base units</option><option value="packages">Packages / cases</option></select>
                        </div>
                        <div class="form-group receiving-quantity" id="units_qty_group" data-field="units_qty_group">
                            <label for="received_qty">Base units received</label>
                            <input type="text" inputmode="numeric" pattern="[0-9]+" name="received_qty" id="received_qty" data-field="received_qty" value="">
                        </div>
                        <div class="form-group receiving-quantity" id="packages_qty_group" data-field="packages_qty_group" style="display:none">
                            <label for="received_packages">Packages received</label>
                            <input type="text" inputmode="numeric" pattern="[0-9]+" name="received_packages" id="received_packages" data-field="received_packages" value="">
                        </div>
                        <div class="form-group receiving-quantity">
                            <label for="damaged_qty">Damaged base units on delivery</label>
                            <input type="text" inputmode="numeric" pattern="[0-9]+" name="damaged_qty" id="damaged_qty" data-field="damaged_qty" value="0">
                        </div>
                        <div class="form-group receiving-quantity">
                            <label for="cost_price">Purchase cost per base unit (optional)</label>
                            <input type="text" inputmode="decimal" name="cost_price" id="cost_price" data-field="cost_price" placeholder="Leave blank if unknown">
                            <small>Cost for one base unit, even when receiving cases. Blank preserves the current product cost.</small>
                        </div>
                    </div>
                    <small class="receiving-quantity" id="package_conversion" data-field="package_conversion" role="status">Select a product to view conversion.</small>
                </div>

                <div class="form-section receiving-quantity">
                    <h4>Optional batch & expiry</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="batch_number">Batch/Lot Number</label>
                            <input type="text" name="batch_number" id="batch_number" data-field="batch_number" placeholder="Batch #">
                        </div>
                        <div class="form-group">
                            <label for="expiration_date">Expiration Date</label>
                            <input type="date" name="expiration_date" id="expiration_date" data-field="expiration_date">
                        </div>
                    </div>
                </div>

                <div class="form-section receiving-quantity">
                    <div class="form-group"><label for="discrepancy_notes">Damage details (optional)</label><textarea name="discrepancy_notes" id="discrepancy_notes" data-field="discrepancy_notes"></textarea></div>
                </div>

                <div class="form-section receiving-quantity">
                    <div class="form-group">
                        <label for="notes">Notes / Comments</label>
                        <textarea name="notes" id="notes" data-field="notes" placeholder="Any additional notes about this receipt..."></textarea>
                    </div>
                </div>

                </section>
                </div>
                <button type="button" class="btn btn-quiet" id="add_receiving_line">Add another product</button>
                <p id="entry_status" role="alert"></p>
                <div class="form-section receiving-quantity">
                    <h3>Optional shopping details</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="supplier">Shopping store (optional)</label>
                            <input type="text" name="supplier" id="supplier" maxlength="150" placeholder="Where the goods were bought">
                        </div>
                        <div class="form-group">
                            <label for="invoice_number">Receipt / invoice reference (optional)</label>
                            <input type="text" name="invoice_number" id="invoice_number" maxlength="100" placeholder="Receipt reference">
                        </div>
                    </div>
                </div>

                <section id="receiving_review" hidden aria-label="Review shopping entry"></section>
                <div class="u-flex-wrap receiving-actions">
                    <button type="button" class="btn btn-secondary" id="receiving_back" hidden>Back</button>
                    <button type="button" class="btn btn-primary" id="receiving_next">Continue to quantities</button>
                    <button type="submit" class="btn-submit" hidden>Save shopping entry & update inventory</button>
                </div>
            </form>
            </fieldset>
        </div>
    </main>
</div>

<script>
const productCodeScan = document.getElementById('product_code_scan');
const scanStatus = document.getElementById('scan_status');
let activeScanLookup = 0;
let scanLookupController = null;
let activeLine = document.querySelector('.receiving-line');
function receivingField(id) {
    return activeLine?.querySelector('[data-field="' + id + '"]') || null;
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
        if (Array.from(lineContainer.children).some(row => row.querySelector('[data-field="product_id"]').value === String(product.product_id))) {
            scanStatus.textContent = 'This product is already in the shopping entry. Edit its existing line or choose another product.';
            return;
        }
        if (!activeLine || receivingField('product_id').value) addReceivingLine();
        const matchedCase = result.matched_code_kind === 'case_barcode';
        receivingField('product_id').value = String(product.product_id);
        activeLine.dataset.productId = String(product.product_id);
        receivingField('quantity_mode').value = matchedCase ? 'packages' : 'units';
        updateQuantityMode();
        const matchedBy = result.matched_code_kind === 'sku'
            ? 'SKU'
            : (matchedCase ? 'case barcode' : 'unit barcode');
        scanStatus.textContent = `${product.product_name} (${product.sku}) selected. Matched by ${matchedBy}. Enter actual delivered ${matchedCase ? 'packages / cases' : 'Base units'}.`;
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

function selectedProductOption() {
    const select=receivingField('product_id'); return select.options[select.selectedIndex];
}
function updatePackageConversion() {
    const option=selectedProductOption(); const units=Number(option?.dataset.unitsPerPackage||0); const receiving=option?.dataset.receivingUnit||'package'; const base=option?.dataset.baseUnit||'piece';
    const packages = Number(receivingField('received_packages').value || 0);
    const damaged = Number(receivingField('damaged_qty').value || 0);
    const packageMode = receivingField('quantity_mode').value === 'packages';
    const total = packageMode ? packages * units : Number(receivingField('received_qty').value || 0);
    receivingField('package_conversion').textContent=`1 ${receiving} = ${units} ${base}(s). ${packageMode ? `${packages} × ${units} = ` : ''}${total} units received; ${damaged} damaged units; ${Math.max(0, total - damaged)} accepted units.`;
}
function updateQuantityMode() {
    const packages=receivingField('quantity_mode').value==='packages';
    receivingField('units_qty_group').style.display=packages?'none':'flex';
    receivingField('packages_qty_group').style.display=packages?'flex':'none';
    receivingField('received_qty').required=!packages;
    receivingField('received_packages').required=packages;
    updatePackageConversion();
}
let step = 1;
const form = document.getElementById('receiving_form');
const lineContainer = document.getElementById('receiving_lines');
const lineTemplate = activeLine.cloneNode(true);
for (const label of lineTemplate.querySelectorAll('label[for]')) label.dataset.labelFor = label.htmlFor;
function renumberLines() {
    for (const [index, row] of Array.from(lineContainer.children).entries()) {
        row.querySelector('.line-number').textContent = index + 1;
        row.setAttribute('aria-label', 'Product line ' + (index + 1));
        for (const field of row.querySelectorAll('[data-field]')) {
            field.id = index === 0 ? field.dataset.field : field.dataset.field + '_' + index;
            if (field.hasAttribute('name')) field.name = `lines[${index}][${field.dataset.field}]`;
        }
        for (const label of row.querySelectorAll('label[for]')) {
            const name = label.dataset.labelFor || label.htmlFor;
            label.dataset.labelFor = name;
            label.htmlFor = index === 0 ? name : name + '_' + index;
        }
    }
    if (activeLine) activateLine(activeLine);
    else document.getElementById('scan_target').textContent = 'Add a product line or scan to start one.';
}
function activateLine(row) {
    if (activeLine !== row) {
        activeScanLookup++;
        if (scanLookupController) scanLookupController.abort();
    }
    activeLine = row;
    document.getElementById('scan_target').textContent = 'Scanning product line ' + row.querySelector('.line-number').textContent
        + '. Focus another line to scan into it.';
}
function restoreField(field, value) {
    if (!field || typeof value !== 'string') return;
    if (field.tagName === 'SELECT' && !Array.from(field.options).some(option => option.value === value)) field.add(new Option(value, value));
    field.value = value;
    if (field.type === 'date' && value !== '' && field.value !== value) {
        field.type = 'text';
        field.placeholder = 'YYYY-MM-DD';
        field.value = value;
    }
}
function connectLine(row) {
    row.addEventListener('focusin', () => activateLine(row));
    row.querySelector('.remove-receiving-line').addEventListener('click', () => {
        activeScanLookup++;
        if (scanLookupController) scanLookupController.abort();
        row.remove();
        document.getElementById('entry_status').textContent = '';
        activeLine = lineContainer.lastElementChild;
        renumberLines();
        (activeLine?.querySelector('[data-field="product_id"]') || document.getElementById('add_receiving_line')).focus();
    });
    row.querySelector('[data-field="product_id"]').addEventListener('change', () => {
        activateLine(row);
        activeScanLookup++;
        if (scanLookupController) scanLookupController.abort();
        const duplicate = Array.from(lineContainer.children).some(other => other !== row
            && other.querySelector('[data-field="product_id"]').value === receivingField('product_id').value);
        if (duplicate) receivingField('product_id').value = row.dataset.productId || '';
        else row.dataset.productId = receivingField('product_id').value;
        updatePackageConversion();
        document.getElementById('entry_status').textContent = duplicate ? 'Duplicate product: remove this line or choose another product.' : '';
        scanStatus.textContent = 'Product selected manually. Enter actual bought quantities.';
    });
    row.querySelector('[data-field="quantity_mode"]').addEventListener('change', () => { activateLine(row); updateQuantityMode(); });
    for (const name of ['received_qty', 'received_packages', 'damaged_qty']) {
        row.querySelector('[data-field="' + name + '"]').addEventListener('input', () => {
            activateLine(row);
            updatePackageConversion();
        });
    }
}
function addReceivingLine(values = {}) {
    const row = lineTemplate.cloneNode(true);
    lineContainer.appendChild(row);
    connectLine(row);
    activateLine(row);
    renumberLines();
    for (const [name, value] of Object.entries(values)) restoreField(receivingField(name), value);
    row.dataset.productId = receivingField('product_id').value;
    updateQuantityMode();
    showStep(1, false);
    receivingField('product_id').focus();
}
function showStep(next, focus = true) {
    step = next;
    document.querySelector('.receiving-form').dataset.step = String(step);
    const titles = ['1. Select products', '2. Enter quantities', '3. Review'];
    document.getElementById('receiving_step_title').textContent = titles[step - 1];
    document.querySelectorAll('.receiving-steps li').forEach((item, index) => {
        if (index === step - 1) item.setAttribute('aria-current', 'step');
        else item.removeAttribute('aria-current');
    });
    document.getElementById('receiving_selection').hidden = step !== 1;
    lineContainer.hidden = step === 3;
    document.getElementById('add_receiving_line').hidden = step !== 1;
    document.getElementById('receiving_review').hidden = step !== 3;
    document.getElementById('receiving_back').hidden = step === 1;
    document.getElementById('receiving_next').hidden = step === 3;
    document.getElementById('receiving_next').textContent = step === 1 ? 'Continue to quantities' : 'Review shopping entry';
    form.querySelector('.btn-submit').hidden = step !== 3;
    if (focus) document.getElementById('receiving_step_title').focus();
}
function validateProducts() {
    const products = Array.from(lineContainer.children).map(row => row.querySelector('[data-field="product_id"]').value);
    const valid = products.length > 0 && products.every(Boolean) && new Set(products).size === products.length;
    document.getElementById('entry_status').textContent = valid ? '' : 'Select at least one product. Each line must have a different product.';
    return valid;
}
function validateQuantities() {
    for (const [index, row] of Array.from(lineContainer.children).entries()) {
        const field = name => row.querySelector('[data-field="' + name + '"]');
        const packages = field('quantity_mode').value === 'packages';
        const quantity = field(packages ? 'received_packages' : 'received_qty');
        const pack = packages ? Number(field('product_id').selectedOptions[0]?.dataset.unitsPerPackage) : 1;
        const total = Number(quantity.value) * pack;
        const damage = field('damaged_qty');
        let invalid = null;
        let message = '';
        if (!/^\d+$/.test(quantity.value) || Number(quantity.value) <= 0 || !Number.isInteger(pack) || pack <= 0 || total > 2147483647) {
            invalid = quantity;
            message = 'Enter a positive whole-number bought quantity within range and use a valid saved pack size.';
        } else if (!/^\d+$/.test(damage.value) || Number(damage.value) > total) {
            invalid = damage;
            message = 'Damaged units must be a whole number from zero to the received units.';
        } else {
            invalid = Array.from(row.querySelectorAll('input, select, textarea')).find(input => !input.checkValidity());
            message = invalid?.validationMessage || '';
            const cost = field('cost_price');
            if (!invalid && cost.value.trim() !== '' && !/^\d{1,8}(?:\.\d{1,2})?$/.test(cost.value.trim())) {
                invalid = cost;
                message = 'Enter a non-negative purchase cost with at most two decimal places, or leave it blank.';
            }
        }
        if (invalid) {
            showStep(2, false);
            document.getElementById('entry_status').textContent = 'Line ' + (index + 1) + ': ' + message;
            invalid.focus();
            return false;
        }
    }
    for (const name of ['supplier', 'invoice_number']) {
        const input = form.elements.namedItem(name);
        if (!input.checkValidity()) { showStep(2, false); input.reportValidity(); return false; }
    }
    document.getElementById('entry_status').textContent = '';
    return true;
}
function renderReview() {
    const review = document.getElementById('receiving_review');
    review.replaceChildren();
    const shared = document.createElement('p');
    shared.textContent = 'Shopping store: ' + (form.elements.supplier.value || 'Not supplied')
        + ' · Receipt reference: ' + (form.elements.invoice_number.value || 'Not supplied');
    review.append(shared);
    for (const [index, row] of Array.from(lineContainer.children).entries()) {
        const field = name => row.querySelector('[data-field="' + name + '"]');
        const section = document.createElement('section');
        section.className = 'receiving-line';
        const title = document.createElement('h3');
        title.textContent = field('product_id').selectedOptions[0].textContent.trim();
        const mode = document.createElement('p');
        mode.textContent = field('quantity_mode').selectedOptions[0].textContent + ' · ' + field('package_conversion').textContent;
        const details = document.createElement('p');
        details.textContent = 'Purchase cost per base unit: ' + (field('cost_price').value || 'Unknown; current cost preserved')
            + ' · Batch: ' + (field('batch_number').value || 'Not supplied')
            + ' · Expiry: ' + (field('expiration_date').value || 'Not supplied');
        const notes = document.createElement('p');
        notes.textContent = [field('discrepancy_notes').value, field('notes').value].filter(Boolean).join(' · ');
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'btn btn-secondary';
        edit.textContent = 'Edit line ' + (index + 1);
        edit.addEventListener('click', () => { showStep(2, false); field('quantity_mode').focus(); });
        section.append(title, mode, details, notes, edit);
        review.append(section);
    }
}
document.getElementById('receiving_back').addEventListener('click', () => showStep(step - 1));
document.getElementById('receiving_next').addEventListener('click', () => {
    if (!validateProducts()) return;
    if (step === 1) { showStep(2); return; }
    if (validateQuantities()) { renderReview(); showStep(3); }
});
document.getElementById('product_search').addEventListener('input', event => {
    const query = event.target.value.trim().toLowerCase();
    for (const select of lineContainer.querySelectorAll('[data-field="product_id"]')) {
        for (const option of select.options) option.hidden = Boolean(option.value) && !option.textContent.toLowerCase().includes(query);
    }
});
connectLine(activeLine);
renumberLines();
document.getElementById('add_receiving_line').addEventListener('click', () => addReceivingLine());
const retainedValues = <?= json_encode($formValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
for (const name of ['supplier', 'invoice_number']) restoreField(form.elements.namedItem(name), retainedValues[name]);
if (Array.isArray(retainedValues.lines)) {
    lineContainer.replaceChildren();
    activeLine = null;
    for (const values of retainedValues.lines) addReceivingLine(values);
} else {
    for (const [name, value] of Object.entries(retainedValues)) restoreField(receivingField(name), value);
    updateQuantityMode();
}
let saving = false;
form.addEventListener('submit', event => {
    if (saving) { event.preventDefault(); return; }
    if (step !== 3) { event.preventDefault(); document.getElementById('receiving_next').click(); return; }
    const products = Array.from(lineContainer.children).map(row => row.querySelector('[data-field="product_id"]').value);
    if (products.length === 0 || new Set(products).size !== products.length) {
        event.preventDefault();
        document.getElementById('entry_status').textContent = products.length === 0
            ? 'Add at least one product before saving.' : 'Duplicate product: remove the duplicate line or choose another product.';
        return;
    }
    if (!validateQuantities()) { event.preventDefault(); return; }
    saving = true;
    form.querySelector('.btn-submit').disabled = true;
    form.querySelector('.btn-submit').textContent = 'Saving receipt…';
});
if (activeLine) updateQuantityMode();
showStep(Object.keys(retainedValues).length ? 2 : 1);
</script>
</body>
</html>
