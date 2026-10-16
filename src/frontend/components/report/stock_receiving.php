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
$prefillProductId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
if ($prefillProductId > 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $formValues = ['product_id' => (string)$prefillProductId];
}

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
            if (($_POST['receiving_complete'] ?? '') !== '1') {
                throw new RuntimeException('The shopping entry was not fully received. Nothing was saved. Split it into smaller entries and try again.');
            }
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

$categories = [];
foreach ($products as $p) {
    $catId = (int)($p['category_id'] ?? 0);
    $catName = trim((string)($p['category_name'] ?? ''));
    if ($catId <= 0 || $catName === '') {
        continue;
    }
    if (!isset($categories[$catId])) {
        $categories[$catId] = [
            'id' => $catId,
            'name' => $catName,
            'count' => 0,
        ];
    }
    $categories[$catId]['count']++;
}
uasort($categories, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

$categoryIcon = static function (string $categoryName): string {
    $name = strtolower($categoryName);
    return match (true) {
        str_contains($name, 'beverage'), str_contains($name, 'drink') => 'bi-cup-straw',
        str_contains($name, 'snack'), str_contains($name, 'biscuit') => 'bi-cookie',
        str_contains($name, 'personal'), str_contains($name, 'health') => 'bi-heart-pulse',
        str_contains($name, 'school'), str_contains($name, 'office') => 'bi-pencil',
        str_contains($name, 'electronic') => 'bi-battery-charging',
        str_contains($name, 'baking'), str_contains($name, 'grocery') => 'bi-basket2',
        str_contains($name, 'canned'), str_contains($name, 'condiment') => 'bi-box2-heart',
        default => 'bi-bag',
    };
};
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Receiving</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/stock-receiving.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/stock-receiving.css')) ?>">
</head>
<body class="stock-receiving-page">
<a class="receiving-skip-link" href="#receiving_main">Skip to Stock Receiving</a>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="receiving_main" tabindex="-1">
        <div class="topbar">
            <div><h1>Stock Receiving</h1><p>A clear path from delivered goods to available stock.</p></div>
            <a class="btn btn-secondary" href="receiving_history.php"><i class="bi bi-clock-history" aria-hidden="true"></i> Receiving history</a>
        </div>
        <?php if ($message): ?>
            <div class="message success" role="status"><?= htmlspecialchars($message) ?></div>
            <p>Stock is updated. <a href="../inventory_management/replenishment_queue.php">Review updated recommendations</a> using current stock and existing forecast outputs, or <a href="receiving_history.php">view receiving history</a>.</p>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><span role="alert"><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <div class="receiving-form" data-step="1">
            <div class="receiving-progress">
                <ol class="receiving-steps" aria-label="Receiving progress">
                    <li aria-current="step"><span class="step-number">1</span><span>Select products<small>Find what arrived</small></span></li>
                    <li><span class="step-number">2</span><span>Enter quantities<small>Count received goods</small></span></li>
                    <li><span class="step-number">3</span><span>Review & save<small>Confirm stock changes</small></span></li>
                </ol>
                <span class="receiving-draft">Unsaved delivery</span>
            </div>
            <h2 id="receiving_step_title" class="receiving-step-title" tabindex="-1">1. Select products</h2>
            <?php if (!$canReceive): ?><p>Switch to an authorized Inventory Manager workspace to record stock.</p><?php endif; ?>
            <?php if (!$products): ?><p role="status">No active products are available. Ask your Administrator to add or activate a product before receiving stock.</p><?php endif; ?>
            <noscript><p role="alert">Enable JavaScript to select products, review quantities, and save this receiving entry.</p></noscript>
            <fieldset <?= !$canReceive || !$products ? 'disabled' : '' ?>>
            <div class="form-section" id="receiving_selection">
                <div class="receiving-panel-heading"><div><h2>Product catalog</h2><p>Find what arrived</p></div><span class="receiving-count"><?= count($products) ?> products</span></div>
                <div class="receiving-picker">
                <div class="form-group"><label for="product_search">Search products by name or SKU</label><input type="search" id="product_search" autocomplete="off" aria-describedby="product_search_status" placeholder="Search active products"><small id="product_search_status" role="status"></small></div>
                <div class="form-group">
                    <label for="product_code_scan">Barcode or SKU</label>
                    <input type="text" id="product_code_scan" autocomplete="off" inputmode="text" aria-describedby="scan_target scan_status" placeholder="Scan or type a code, then press Enter">
                    <small id="scan_target" role="status">Scanning product line 1. Focus another line to scan into it.</small>
                    <small id="scan_status" role="status" aria-live="polite">You can also choose a product manually below.</small>
                </div>
                </div>
                <div class="receiving-category-filters" id="receiving_category_filters" role="group" aria-label="Product categories">
                    <button type="button" class="receiving-category-tile active" aria-pressed="true" data-category="all">
                        <span class="receiving-category-icon"><i class="bi bi-grid" aria-hidden="true"></i></span>
                        <strong>All</strong>
                        <small><?= count($products) ?> products</small>
                    </button>
                    <?php foreach ($categories as $catId => $cat): ?>
                        <button type="button" class="receiving-category-tile" aria-pressed="false" data-category="<?= (int)$catId ?>">
                            <span class="receiving-category-icon"><i class="bi <?= htmlspecialchars($categoryIcon($cat['name'])) ?>" aria-hidden="true"></i></span>
                            <strong><?= htmlspecialchars($cat['name']) ?></strong>
                            <small><?= (int)$cat['count'] ?> product<?= (int)$cat['count'] === 1 ? '' : 's' ?></small>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div id="receiving_catalog" class="receiving-catalog" aria-label="Available products"></div>
            </div>
            <form method="POST" id="receiving_form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="receiving_nonce" value="<?= htmlspecialchars($receivingNonce) ?>">
                <input type="hidden" name="entry_mode" value="shopping_trip">
                <div class="delivery-editor">
                <div class="receiving-panel-heading"><div><h2 id="delivery_title">Delivery lines</h2><p>Enter quantities exactly as received.</p></div><span id="delivery_count" class="receiving-count">0 products</span></div>
                <div id="receiving_lines">
                <section class="receiving-line" aria-label="Product line 1">
                    <div class="receiving-line-heading"><div><h3>Product line <span class="line-number">1</span></h3><span class="receiving-product-name"></span></div>
                        <button type="button" class="btn btn-quiet remove-receiving-line">Remove product</button></div>

                <div class="form-section">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="product_id" class="required">Product</label>
                            <select name="product_id" id="product_id" data-field="product_id" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['product_id'] ?>" data-name="<?= htmlspecialchars($p['product_name']) ?>" data-sku="<?= htmlspecialchars($p['sku']) ?>" data-cost="<?= htmlspecialchars($p['cost_price'] ?? '0') ?>" data-units-per-package="<?= (int)($p['units_per_package'] ?? 1) ?>" data-base-unit="<?= htmlspecialchars($p['base_unit'] ?? 'piece') ?>" data-receiving-unit="<?= htmlspecialchars($p['receiving_unit'] ?? 'package') ?>" data-category-id="<?= (int)($p['category_id'] ?? 0) ?>" data-category-name="<?= htmlspecialchars($p['category_name'] ?? '') ?>" data-barcode="<?= htmlspecialchars($p['barcode'] ?? '') ?>" data-variant-label="<?= htmlspecialchars($p['variant_label'] ?? '') ?>" data-product-image="<?= htmlspecialchars($p['product_image'] ?? '') ?>" data-quantity-on-hand="<?= (int)($p['quantity_on_hand'] ?? 0) ?>" data-reorder-level="<?= (int)($p['reorder_level'] ?? 0) ?>" data-safety-stock="<?= (int)($p['safety_stock'] ?? 0) ?>">
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
                            <input type="text" inputmode="decimal" name="cost_price" id="cost_price" data-field="cost_price" aria-describedby="cost_hint" placeholder="Leave blank if unknown">
                            <small id="cost_hint" data-field="cost_hint">Cost for one base unit, even when receiving cases. Blank preserves the current product cost.</small>
                        </div>
                    </div>
                    <small class="receiving-quantity" id="package_conversion" data-field="package_conversion" role="status">Select a product to view conversion.</small>
                </div>

                <details class="receiving-quantity receiving-extras" open>
                    <summary>Cost, batch, expiry & notes</summary>
                <div class="form-section">
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
                </details>

                </section>
                </div>
                <button type="button" class="btn btn-quiet" id="add_receiving_line">Add another product</button>
                <p id="entry_status" role="alert"></p>
                <section id="receiving_review" hidden aria-label="Review shopping entry"></section>
                </div>
                <section class="receiving-summary" aria-label="Delivery summary">
                <div class="receiving-panel-heading"><h2>Delivery summary</h2><span id="summary_status" class="receiving-count">Draft</span></div>
                <div class="receiving-summary-body">
                    <div class="receiving-accepted"><strong id="summary_products">0</strong><span>Products added</span><small>In current delivery list</small></div>
                    <dl class="receiving-totals"><div><dt>Accepted units</dt><dd id="summary_accepted">0</dd></div><div><dt>Received units</dt><dd id="summary_received">0</dd></div><div><dt>Damaged units</dt><dd id="summary_damaged">0</dd></div><div><dt>Known accepted cost</dt><dd id="summary_cost">₱0.00</dd></div></dl>
                    <p class="receiving-summary-note">Damaged units are recorded separately. They never increase available stock. Blank purchase costs remain unknown.</p>
                <div class="form-section receiving-quantity">
                    <h3>Receipt details</h3>
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

                <div class="u-flex-wrap receiving-actions">
                    <button type="button" class="btn btn-secondary" id="receiving_back" hidden>Back</button>
                    <button type="button" class="btn btn-primary" id="receiving_next">Continue to quantities</button>
                    <button type="submit" class="btn-submit" hidden>Save shopping entry & update inventory</button>
                </div>
                <small>Review is required before stock changes.</small>
                </div>
                </section>
                <input type="hidden" name="receiving_complete" value="1">
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
        const select = receivingField('product_id');
        if (!Array.from(select.options).some(option => option.value === String(product.product_id))) {
            const option = productOptions.find(option => option.value === String(product.product_id));
            if (!option) {
                scanStatus.textContent = 'This product is unavailable for receiving. Choose an active product manually.';
                return;
            }
            select.add(option.cloneNode(true));
        }
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
    refreshDelivery();
}
function updateQuantityMode() {
    const packages=receivingField('quantity_mode').value==='packages';
    receivingField('units_qty_group').style.display=packages?'none':'flex';
    receivingField('packages_qty_group').style.display=packages?'flex':'none';
    receivingField('received_qty').required=!packages;
    receivingField('received_packages').required=packages;
    receivingField('received_qty').disabled=packages;
    receivingField('received_packages').disabled=!packages;
    for (const name of ['received_qty', 'received_packages']) {
        activeLine.querySelector('label[for="' + receivingField(name).id + '"]').classList.toggle('required', receivingField(name).required);
    }
    updatePackageConversion();
}
let step = 1;
const form = document.getElementById('receiving_form');
const lineContainer = document.getElementById('receiving_lines');
const lineTemplate = activeLine.cloneNode(true);
const productOptions = Array.from(receivingField('product_id').options, option => option.cloneNode(true));
const productSearchText = productOptions.map(option => [
    option.textContent,
    option.dataset.sku,
    option.dataset.barcode,
    option.dataset.variantLabel
].filter(Boolean).join(' ').toLowerCase());
lineTemplate.querySelector('[data-field="product_id"]').replaceChildren(productOptions[0].cloneNode(true));
for (const label of lineTemplate.querySelectorAll('label[for]')) label.dataset.labelFor = label.htmlFor;
function refreshDelivery() {
    let products = 0, received = 0, damaged = 0, accepted = 0, cost = 0;
    for (const row of lineContainer.children) {
        const field = name => row.querySelector('[data-field="' + name + '"]');
        const option = field('product_id').selectedOptions[0];
        row.querySelector('.receiving-product-name').textContent = option?.value ? option.textContent.trim() : 'Choose a product below';
        if (!option?.value) continue;
        products++;
        const packages = field('quantity_mode').value === 'packages';
        const quantity = field(packages ? 'received_packages' : 'received_qty').value;
        const total = /^\d+$/.test(quantity) ? Number(quantity) * (packages ? Number(option.dataset.unitsPerPackage) : 1) : 0;
        const damage = /^\d+$/.test(field('damaged_qty').value) ? Number(field('damaged_qty').value) : 0;
        const usable = Math.max(0, total - damage);
        received += total;
        damaged += damage;
        accepted += usable;
        const price = field('cost_price').value.trim();
        if (/^\d{1,8}(?:\.\d{1,2})?$/.test(price)) cost += Math.round(Number(price) * 100) * usable;
    }
    for (const [name, value] of Object.entries({products, received, damaged, accepted})) document.getElementById('summary_' + name).textContent = value;
    document.getElementById('summary_cost').textContent = '₱' + (cost / 100).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('delivery_count').textContent = products + (products === 1 ? ' product' : ' products');
    renderCatalog();
}
let selectedCategory = 'all';
document.getElementById('receiving_category_filters')?.addEventListener('click', event => {
    const tile = event.target.closest('.receiving-category-tile');
    if (!tile) return;
    document.querySelectorAll('.receiving-category-tile').forEach(el => {
        el.classList.remove('active');
        el.setAttribute('aria-pressed', 'false');
    });
    tile.classList.add('active');
    tile.setAttribute('aria-pressed', 'true');
    selectedCategory = tile.dataset.category || 'all';
    renderCatalog();
});

function getStockState(quantityOnHand, reorderLevel, safetyStock) {
    const stock = Number(quantityOnHand || 0);
    const reorder = Number(reorderLevel || 0);
    const safety = Number(safetyStock || 0);
    const threshold = Math.max(reorder, safety);
    const isBelowThreshold = threshold > 0 && stock <= threshold;
    if (stock <= 0) return { state: 'out', label: 'Out of stock', isLow: isBelowThreshold || true };
    if (isBelowThreshold) {
        return { state: 'low', label: 'Low stock', isLow: true };
    }
    return { state: 'available', label: `${stock} in stock`, isLow: false };
}

function getCategoryIcon(categoryName) {
    const name = String(categoryName || '').toLowerCase();
    if (name.includes('beverage') || name.includes('drink')) return 'bi-cup-straw';
    if (name.includes('snack') || name.includes('biscuit')) return 'bi-cookie';
    if (name.includes('personal') || name.includes('health')) return 'bi-heart-pulse';
    if (name.includes('school') || name.includes('office')) return 'bi-pencil';
    if (name.includes('electronic')) return 'bi-battery-charging';
    if (name.includes('baking') || name.includes('grocery')) return 'bi-basket2';
    if (name.includes('canned') || name.includes('condiment')) return 'bi-box2-heart';
    return 'bi-bag';
}

function renderCatalog() {
    const query = document.getElementById('product_search').value.trim().toLowerCase();
    const selected = new Set(Array.from(lineContainer.children, row => row.querySelector('[data-field="product_id"]').value));
    const matches = productOptions.filter((option, index) => {
        if (!option.value) return false;
        const matchesCategory = selectedCategory === 'all' || (option.dataset.categoryId || '') === selectedCategory;
        const matchesQuery = !query || productSearchText[index].includes(query);
        return matchesCategory && matchesQuery;
    });
    const catalog = document.getElementById('receiving_catalog');
    catalog.replaceChildren();

    for (const option of matches.slice(0, 24)) {
        const isAdded = selected.has(option.value);
        const stockInfo = getStockState(option.dataset.quantityOnHand, option.dataset.reorderLevel, option.dataset.safetyStock);
        const iconClass = getCategoryIcon(option.dataset.categoryName);

        const card = document.createElement('article');
        card.className = 'receiving-card' + (stockInfo.state === 'out' ? ' out-of-stock' : '');
        card.dataset.productId = option.value;

        const media = document.createElement('div');
        media.className = 'receiving-card-media';
        if (option.dataset.productImage) {
            const img = document.createElement('img');
            img.src = option.dataset.productImage;
            img.alt = '';
            img.loading = 'lazy';
            const fallback = document.createElement('i');
            fallback.className = 'bi ' + iconClass;
            fallback.setAttribute('aria-hidden', 'true');
            fallback.hidden = true;
            img.onerror = () => { img.hidden = true; fallback.hidden = false; };
            media.append(img, fallback);
        } else {
            const icon = document.createElement('i');
            icon.className = 'bi ' + iconClass;
            icon.setAttribute('aria-hidden', 'true');
            media.appendChild(icon);
        }

        if (stockInfo.isLow) {
            const badge = document.createElement('span');
            badge.className = 'receiving-card-badge low-stock';
            const badgeIcon = document.createElement('i');
            badgeIcon.className = 'bi bi-exclamation-triangle-fill';
            badgeIcon.setAttribute('aria-hidden', 'true');
            badge.append(badgeIcon, document.createTextNode(' Low stock'));
            media.appendChild(badge);
        }

        const body = document.createElement('div');
        body.className = 'receiving-card-body';

        const name = document.createElement('strong');
        name.className = 'receiving-card-name';
        name.title = option.dataset.name;
        name.textContent = option.dataset.name;

        const meta = document.createElement('span');
        meta.className = 'receiving-card-meta';
        const catLabel = option.dataset.categoryName || 'General';
        meta.textContent = catLabel + ' · ' + (option.dataset.sku || 'No SKU');

        const costStock = document.createElement('div');
        costStock.className = 'receiving-card-cost-stock';

        const cost = document.createElement('span');
        cost.className = 'receiving-card-cost';
        const rawCost = option.dataset.cost;
        cost.textContent = rawCost !== '' && rawCost !== null && !isNaN(Number(rawCost)) && Number(rawCost) > 0
            ? '₱' + Number(rawCost).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})
            : 'Unknown cost';

        const stock = document.createElement('span');
        stock.className = 'receiving-card-stock ' + stockInfo.state;
        const stockIcon = document.createElement('i');
        stockIcon.className = 'bi bi-circle-fill';
        stockIcon.setAttribute('aria-hidden', 'true');
        stock.append(stockIcon, document.createTextNode(' ' + stockInfo.label));

        costStock.append(cost, stock);

        const pack = document.createElement('div');
        pack.className = 'receiving-card-pack';
        const unitsPerPkg = Number(option.dataset.unitsPerPackage || 1);
        const baseUnit = option.dataset.baseUnit || 'piece';
        const baseLabel = unitsPerPkg === 1 ? baseUnit : (baseUnit.endsWith('s') ? baseUnit : baseUnit + 's');
        const packSmall = document.createElement('small');
        packSmall.textContent = '1 ' + (option.dataset.receivingUnit || 'package') + ' = ' + unitsPerPkg + ' ' + baseLabel;
        pack.appendChild(packSmall);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-secondary receiving-card-add';
        button.textContent = isAdded ? 'Added' : '+ Add to Delivery';
        button.setAttribute('aria-label', 'Add ' + option.dataset.name);
        button.disabled = isAdded;
        button.addEventListener('click', () => {
            const empty = Array.from(lineContainer.children).find(row => !row.querySelector('[data-field="product_id"]').value);
            if (empty) activateLine(empty);
            else addReceivingLine();
            const select = receivingField('product_id');
            if (!Array.from(select.options).some(item => item.value === option.value)) select.add(option.cloneNode(true));
            select.value = option.value;
            select.dispatchEvent(new Event('change', {bubbles: true}));
            showStep(1, false);
        });

        body.append(name, meta, costStock, pack, button);
        card.append(media, body);
        catalog.append(card);
    }

    const hint = document.createElement('p');
    hint.className = 'receiving-catalog-hint';
    hint.textContent = matches.length ? 'Showing ' + Math.min(24, matches.length) + ' of ' + matches.length + ' products. Search to narrow the list.' : 'No matching products. Try another name or SKU.';
    catalog.append(hint);
}
function populateProductOptions(select) {
    const value = select.value;
    const query = document.getElementById('product_search').value.trim().toLowerCase();
    const options = productOptions.filter((option, index) => !option.value || option.value === value || productSearchText[index].includes(query));
    select.replaceChildren(...options.map(option => option.cloneNode(true)));
    select.value = value;
    const matches = productOptions.filter((option, index) => option.value && productSearchText[index].includes(query)).length;
    document.getElementById('product_search_status').textContent = query
        ? (matches ? matches + ' matching ' + (matches === 1 ? 'product.' : 'products.') : 'No matching products. Try another name or SKU, or scan a code.')
        : '';
}
function renumberLines() {
    clearReceivingErrors();
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
        row.querySelector('[data-field="cost_price"]').setAttribute('aria-describedby', row.querySelector('[data-field="cost_hint"]').id);
    }
    if (activeLine) activateLine(activeLine);
    else document.getElementById('scan_target').textContent = 'Add a product line or scan to start one.';
    refreshDelivery();
}
function activateLine(row) {
    if (activeLine !== row) {
        activeScanLookup++;
        if (scanLookupController) scanLookupController.abort();
        if (activeLine?.isConnected) {
            const select = receivingField('product_id');
            const selected = select.selectedOptions[0];
            select.replaceChildren(productOptions[0].cloneNode(true), ...(selected?.value ? [selected.cloneNode(true)] : []));
            select.value = selected?.value || '';
        }
        populateProductOptions(row.querySelector('[data-field="product_id"]'));
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
    row.addEventListener('input', clearReceivingErrors, {capture: true});
    row.addEventListener('change', clearReceivingErrors, {capture: true});
    row.querySelector('[data-field="cost_price"]').addEventListener('input', refreshDelivery);
    row.querySelector('.remove-receiving-line').addEventListener('click', () => {
        const populated = Array.from(row.querySelectorAll('input, textarea')).some(field => field.value !== field.defaultValue)
            || Boolean(row.querySelector('[data-field="product_id"]').value);
        if (populated && !window.confirm('Remove this product and its entered receiving details?')) return;
        activeScanLookup++;
        if (scanLookupController) scanLookupController.abort();
        row.remove();
        document.getElementById('entry_status').textContent = '';
        activeLine = null;
        if (lineContainer.lastElementChild) activateLine(lineContainer.lastElementChild);
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
    document.getElementById('delivery_title').textContent = step === 3 ? 'Check your delivery' : 'Delivery lines';
    document.getElementById('summary_status').textContent = step === 3 ? 'Review' : 'Draft';
    document.getElementById('receiving_back').hidden = step === 1;
    document.getElementById('receiving_next').hidden = step === 3;
    document.getElementById('receiving_next').textContent = step === 1 ? 'Continue to quantities' : 'Review shopping entry';
    form.querySelector('.btn-submit').hidden = step !== 3;
    if (focus) document.getElementById('receiving_step_title').focus();
}
function validateProducts() {
    clearReceivingErrors();
    const products = Array.from(lineContainer.children).map(row => row.querySelector('[data-field="product_id"]').value);
    const valid = products.length > 0 && products.every(Boolean) && new Set(products).size === products.length;
    document.getElementById('entry_status').textContent = valid ? '' : 'Select at least one product. Each line must have a different product.';
    if (!valid) {
        const invalid = Array.from(lineContainer.querySelectorAll('[data-field="product_id"]')).find(field => !field.value);
        if (invalid) receivingError(invalid, 'Select a product for this line.');
        else if (!products.length) document.getElementById('add_receiving_line').focus();
    }
    return valid;
}
function clearReceivingErrors() {
    for (const field of form.querySelectorAll('[aria-invalid="true"]')) {
        field.removeAttribute('aria-invalid');
        const errorId = field.id + '_error';
        const describedBy = (field.getAttribute('aria-describedby') || '').split(' ').filter(id => id && id !== errorId);
        if (describedBy.length) field.setAttribute('aria-describedby', describedBy.join(' '));
        else field.removeAttribute('aria-describedby');
    }
    for (const error of form.querySelectorAll('[data-receiving-error]')) error.remove();
    document.getElementById('entry_status').textContent = '';
}
function receivingError(field, message) {
    const error = document.createElement('small');
    error.id = field.id + '_error';
    error.dataset.receivingError = '';
    error.textContent = message;
    field.after(error);
    field.setAttribute('aria-invalid', 'true');
    field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
    field.focus();
}
function validateQuantities() {
    clearReceivingErrors();
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
            receivingError(invalid, message);
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
document.getElementById('product_search').addEventListener('input', () => {
    if (activeLine) populateProductOptions(receivingField('product_id'));
    renderCatalog();
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
    if (activeLine && retainedValues.product_id) activeLine.dataset.productId = String(retainedValues.product_id);
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
showStep((retainedValues.lines && retainedValues.lines.length) || (retainedValues.received_qty || retainedValues.received_packages) || Boolean(retainedValues.product_id) ? 2 : 1);
</script>
</body>
</html>
