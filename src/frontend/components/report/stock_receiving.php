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
    $formValues = ['lines' => [['product_id' => (string)$prefillProductId]]];
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
        if (isset($formValues['lines']) && is_array($formValues['lines'])) {
            $formValues['lines'] = array_values($formValues['lines']);
        }
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
        <?php if ($message): ?>
            <div class="message success" role="status"><?= htmlspecialchars($message) ?></div>
            <p>Stock is updated. <a href="../inventory_management/replenishment_queue.php">Review updated recommendations</a> using current stock and existing forecast outputs, or <a href="receiving_history.php">view receiving history</a>.</p>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><span role="alert"><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <div class="receiving-form" data-step="1" <?= !$canReceive ? 'data-readonly="true"' : '' ?>>
            <h2 id="receiving_step_title" class="receiving-step-title" tabindex="-1">1. Select products</h2>
            <?php if (!$canReceive): ?><p>Switch to an authorized Inventory Manager workspace to record stock.</p><?php endif; ?>
            <?php if (!$products): ?><p role="status">No active products are available. Ask your Administrator to add or activate a product before receiving stock.</p><?php endif; ?>
            <noscript><p role="alert">Enable JavaScript to select products, review quantities, and save this receiving entry.</p></noscript>
            <fieldset class="receiving-fieldset-reset" <?= !$canReceive || !$products ? 'disabled' : '' ?>>
            <div class="form-section" id="receiving_selection">
                <div class="receiving-panel-heading"><div><h2>Product catalog</h2><p>Find what arrived</p></div><div class="receiving-catalog-actions"><button type="button" class="btn btn-secondary" id="receiving_help_open" aria-haspopup="dialog">How to use</button><span class="receiving-count"><?= count($products) ?> products</span></div></div>
                <div class="receiving-search-bar form-group">
                    <label for="product_search_scan">Scan barcode or search by name or SKU</label>
                    <div class="receiving-search-group">
                        <input type="search" id="product_search_scan" autocomplete="off" aria-describedby="scan_status product_search_status" placeholder="Scan barcode or search by name, SKU or barcode">
                        <button type="button" class="btn btn-secondary" id="add_scanned_item">+ Add item</button>
                    </div>
                    <small id="scan_status" role="status" aria-live="polite">Scan barcode or search by name or SKU, or choose a product manually below.</small>
                    <small id="product_search_status" role="status"></small>
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
            </fieldset>
            <form method="POST" id="receiving_form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="receiving_nonce" value="<?= htmlspecialchars($receivingNonce) ?>">
                <input type="hidden" name="entry_mode" value="shopping_trip">
                <div class="delivery-editor">
                <div class="receiving-panel-heading"><div><h2 id="delivery_title">Delivery lines</h2><p>Enter quantities exactly as received.</p></div><div class="receiving-delivery-actions"><span id="delivery_count" class="receiving-count">0 products</span><button type="button" class="btn btn-primary" id="receiving_next">Add quantity</button></div></div>
                <div id="delivery_empty_state" class="delivery-empty-state">
                    <i class="bi bi-box-seam" aria-hidden="true"></i>
                    <p>No products added yet. Scan a barcode or click + Add to Delivery from the catalog below.</p>
                </div>
                <fieldset class="receiving-fieldset-reset" <?= !$canReceive ? 'disabled' : '' ?>>
                <div id="receiving_lines"></div>
                </fieldset>
                <template id="receiving_line_template">
                <section class="receiving-line" aria-label="Product line 1" draggable="true">
                    <div class="receiving-line-heading playlist-row-header">
                        <div class="playlist-row-main">
                            <span class="playlist-drag-handle" title="Drag to reorder" aria-hidden="true">
                                <i class="bi bi-grip-vertical"></i>
                            </span>
                            <div class="playlist-row-identity">
                                <div class="playlist-row-title">
                                    <span class="line-number">1</span>.
                                    <strong class="receiving-product-name playlist-product-name"></strong>
                                    <span class="receiving-product-sku playlist-product-sku"></span>
                                </div>
                                <div class="receiving-product-pack playlist-pack-info"></div>
                            </div>
                        </div>
                        <div class="playlist-row-actions">
                            <button type="button" class="btn btn-quiet move-line-up" aria-label="Move line 1 up">
                                <i class="bi bi-arrow-up" aria-hidden="true"></i> Move up
                            </button>
                            <button type="button" class="btn btn-quiet move-line-down" aria-label="Move line 1 down">
                                <i class="bi bi-arrow-down" aria-hidden="true"></i> Move down
                            </button>
                            <button type="button" class="btn btn-quiet remove-receiving-line" aria-label="Remove product">
                                <i class="bi bi-trash" aria-hidden="true"></i> Remove
                            </button>
                        </div>
                    </div>
                    <input type="hidden" name="lines[0][product_id]" id="product_id" data-field="product_id" value="">

                    <div class="form-section receiving-quantity">
                        <div class="form-grid">
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
                        </div>
                        <small class="receiving-quantity" id="package_conversion" data-field="package_conversion" role="status">Select a product to view conversion.</small>
                    </div>

                    <details class="receiving-quantity receiving-extras">
                        <summary>Cost, batch, expiry & notes</summary>
                        <div class="form-section">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="cost_price">Purchase cost per base unit (optional)</label>
                                    <input type="text" inputmode="decimal" name="cost_price" id="cost_price" data-field="cost_price" aria-describedby="cost_hint" placeholder="Leave blank if unknown">
                                    <small id="cost_hint" data-field="cost_hint">Cost for one base unit, even when receiving cases. Blank preserves the current product cost.</small>
                                </div>
                                <div class="form-group">
                                    <label for="batch_number">Batch/Lot Number</label>
                                    <input type="text" name="batch_number" id="batch_number" data-field="batch_number" maxlength="100" placeholder="Batch #">
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
                </template>
                <p id="entry_status" role="alert"></p>
                <section id="receiving_review" hidden aria-label="Review shopping entry"></section>
                </div>
                <section class="receiving-summary" aria-label="Delivery summary">
                <div class="receiving-panel-heading"><h2>Delivery summary</h2><span id="summary_status" class="receiving-count">Draft</span></div>
                <div class="receiving-summary-body">
                    <div class="receiving-accepted"><strong id="summary_products">0</strong><span>Products added</span><small>In current delivery list</small></div>
                    <dl class="receiving-totals"><div><dt>Accepted units</dt><dd id="summary_accepted">0</dd></div><div><dt>Received units</dt><dd id="summary_received">0</dd></div><div><dt>Damaged units</dt><dd id="summary_damaged">0</dd></div><div><dt>Known accepted cost</dt><dd id="summary_cost">₱0.00</dd></div></dl>
                    <p class="receiving-summary-note">Damaged units are recorded separately. They never increase available stock. Blank purchase costs remain unknown.</p>
                <fieldset class="receiving-fieldset-reset" <?= !$canReceive ? 'disabled' : '' ?>>
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
                </fieldset>

                <div class="u-flex-wrap receiving-actions">
                    <button type="button" class="btn btn-secondary" id="receiving_return_products" hidden>Return to products</button>
                    <button type="button" class="btn btn-secondary" id="receiving_back" hidden>Back</button>
                    <button type="submit" class="btn-submit" <?= !$canReceive ? 'disabled' : '' ?> hidden>Save shopping entry & update inventory</button>
                </div>
                <small>Review is required before stock changes.</small>
                </div>
                </section>
                <input type="hidden" name="receiving_complete" value="1">
            </form>
        </div>
    </main>
</div>
<dialog class="receiving-help-dialog" id="receiving_help_dialog" aria-labelledby="receiving_help_title">
    <div class="receiving-panel-heading"><h2 id="receiving_help_title">How to receive stock</h2><button type="button" class="btn btn-secondary" id="receiving_help_close">Close</button></div>
    <ol>
        <li><strong>Select products:</strong> scan a barcode, search by name or SKU, or choose a category and add products from the catalog.</li>
        <li><strong>Enter quantities:</strong> continue to quantities, choose units or packages, then enter received and damaged units. Add cost, batch, expiry, or notes when needed.</li>
        <li><strong>Review and save:</strong> check the delivery summary and receipt details, review the stock changes, then save the entry.</li>
    </ol>
</dialog>

<script id="receiving_products_data" type="application/json"><?= json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script>
const productsCatalog = JSON.parse(document.getElementById('receiving_products_data').textContent);
const canReceive = <?= json_encode((bool)$canReceive) ?>;
const productMap = new Map(productsCatalog.map(p => [String(p.product_id), p]));
const receivingHelpDialog = document.getElementById('receiving_help_dialog');
document.getElementById('receiving_help_open').addEventListener('click', () => receivingHelpDialog.showModal());
document.getElementById('receiving_help_close').addEventListener('click', () => receivingHelpDialog.close());
receivingHelpDialog.addEventListener('click', event => { if (event.target === receivingHelpDialog) receivingHelpDialog.close(); });

function updateExtrasDisclosure(row) {
    const details = row?.querySelector('.receiving-extras');
    if (!details) return;
    const hasValue = Array.from(details.querySelectorAll('input, textarea')).some(el => el.value.trim() !== '');
    if (hasValue || details.querySelector('[aria-invalid="true"]')) {
        details.open = true;
    }
}
const productSearchScan = document.getElementById('product_search_scan');
const addScannedItem = document.getElementById('add_scanned_item');
const scanStatus = document.getElementById('scan_status');
const productCodeScan = productSearchScan;
const productSearch = productSearchScan;
let activeScanLookup = 0;
let scanLookupController = null;
let scanInputTimer = null;
let lastScanInputAt = 0;
const lineContainer = document.getElementById('receiving_lines');
const lineTemplate = document.getElementById('receiving_line_template');
let activeLine = null;
let draggedRow = null;

for (const label of lineTemplate.content.querySelectorAll('label[for]')) {
    label.dataset.labelFor = label.htmlFor;
}


async function identifyReceivingProduct(code) {
    code = (code || '').trim();
    if (!code) {
        scanStatus.textContent = 'Scan or type a barcode or SKU first.';
        return;
    }
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
            scanStatus.textContent = `${product.product_name} is already added to delivery lines. Edit its existing line or choose another product.`;
            return;
        }
        if (!productMap.has(String(product.product_id))) {
            productMap.set(String(product.product_id), product);
        }
        const matchedCase = result.matched_code_kind === 'case_barcode';
        const line = addReceivingLine({
            product_id: product.product_id,
            quantity_mode: matchedCase ? 'packages' : 'units'
        });
        if (!line) return;
        RetailMindUI.toast(`${product.product_name} added to delivery lines.`, 'success');
        productSearchScan.value = '';
        const matchedBy = result.matched_code_kind === 'sku'
            ? 'SKU'
            : (matchedCase ? 'case barcode' : 'unit barcode');
        scanStatus.textContent = `${product.product_name} (${product.sku}) selected. Matched by ${matchedBy}. Enter actual delivered ${matchedCase ? 'packages / cases' : 'Base units'}.`;
    } catch (error) {
        if (error.name === 'AbortError' || lookupId !== activeScanLookup) return;
        scanStatus.textContent = 'The product code could not be checked right now. Try again or choose the product manually.';
    } finally {
        if (scanLookupController === controller) scanLookupController = null;
    }
}

productSearchScan.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' || event.ctrlKey || event.metaKey || event.altKey || event.isComposing) return;
    event.preventDefault();
    if (event.repeat) return;
    const code = this.value.trim();
    this.value = '';
    if (code !== '') identifyReceivingProduct(code);
});

addScannedItem?.addEventListener('click', function () {
    const code = productSearchScan.value.trim();
    if (code !== '') identifyReceivingProduct(code);
    else scanStatus.textContent = 'Scan or type a barcode or SKU first.';
});

// Keyboard-wedge scanners send a rapid burst plus Enter without moving focus.
let keyboardReceivingCode = '';
let keyboardReceivingCodeAt = 0;
let keyboardReceivingQueue = Promise.resolve();
function resetKeyboardReceivingCode() {
    keyboardReceivingCode = '';
    keyboardReceivingCodeAt = 0;
}
document.addEventListener('keydown', event => {
    const editing = event.target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"]');
    const dialogOpen = document.querySelector('dialog[open], .rm-modal-overlay.open, .command-overlay.open, .swal2-container');
    if (!canReceive || event.defaultPrevented || event.isComposing || event.repeat || event.ctrlKey || event.metaKey || event.altKey
        || document.hidden || editing || dialogOpen) {
        resetKeyboardReceivingCode();
        return;
    }
    const now = performance.now();
    // shortcut: scanners must send characters within 80ms; tune for slower hardware if needed.
    if (now - keyboardReceivingCodeAt > 80) resetKeyboardReceivingCode();
    if (event.key === 'Enter') {
        const code = keyboardReceivingCode;
        resetKeyboardReceivingCode();
        if (code.length < 3) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        keyboardReceivingQueue = keyboardReceivingQueue.then(() => identifyReceivingProduct(code));
    } else if (event.key.length === 1 && /^[\x21-\x7E]$/.test(event.key)) {
        keyboardReceivingCode += event.key;
        keyboardReceivingCodeAt = now;
        if (keyboardReceivingCode.length > 255) resetKeyboardReceivingCode();
    } else if (event.key !== 'Shift') resetKeyboardReceivingCode();
}, true);
window.addEventListener('blur', resetKeyboardReceivingCode);
document.addEventListener('visibilitychange', resetKeyboardReceivingCode);

function updatePackageConversion(row = activeLine) {
    if (!row) return;
    const field = name => row.querySelector('[data-field="' + name + '"]');
    const prodId = field('product_id')?.value;
    const prod = productMap.get(String(prodId));
    if (!prod) return;
    const units = Number(prod.units_per_package || 1);
    const receiving = prod.receiving_unit || 'package';
    const base = prod.base_unit || 'piece';
    const packages = Number(field('received_packages')?.value || 0);
    const damaged = Number(field('damaged_qty')?.value || 0);
    const packageMode = field('quantity_mode')?.value === 'packages';
    const total = packageMode ? packages * units : Number(field('received_qty')?.value || 0);
    const convEl = field('package_conversion');
    if (convEl) {
        convEl.textContent = `1 ${receiving} = ${units} ${base}(s). ${packageMode ? `${packages} × ${units} = ` : ''}${total} units received; ${damaged} damaged units; ${Math.max(0, total - damaged)} accepted units.`;
    }
    refreshDelivery();
}

function updateQuantityMode(row = activeLine) {
    if (!row) return;
    const field = name => row.querySelector('[data-field="' + name + '"]');
    const packages = field('quantity_mode')?.value === 'packages';
    const unitsGroup = field('units_qty_group');
    const packagesGroup = field('packages_qty_group');
    if (unitsGroup) unitsGroup.style.display = packages ? 'none' : 'flex';
    if (packagesGroup) packagesGroup.style.display = packages ? 'flex' : 'none';
    const receivedQty = field('received_qty');
    const receivedPackages = field('received_packages');
    if (receivedQty) {
        receivedQty.required = !packages;
        receivedQty.disabled = packages;
    }
    if (receivedPackages) {
        receivedPackages.required = packages;
        receivedPackages.disabled = !packages;
    }
    for (const name of ['received_qty', 'received_packages']) {
        const input = field(name);
        if (input) {
            const label = row.querySelector('label[for="' + input.id + '"]');
            if (label) label.classList.toggle('required', input.required);
        }
    }
    updatePackageConversion(row);
}

let step = 1;
const form = document.getElementById('receiving_form');

function refreshDelivery() {
    let products = 0, received = 0, damaged = 0, accepted = 0, cost = 0;
    for (const row of lineContainer.children) {
        const field = name => row.querySelector('[data-field="' + name + '"]');
        const prodId = field('product_id')?.value;
        const prod = productMap.get(String(prodId));
        if (!prodId || !prod) continue;
        products++;
        const packages = field('quantity_mode').value === 'packages';
        const quantity = field(packages ? 'received_packages' : 'received_qty').value;
        const unitsPerPkg = Number(prod.units_per_package || 1);
        const total = /^\d+$/.test(quantity) ? Number(quantity) * (packages ? unitsPerPkg : 1) : 0;
        const damage = /^\d+$/.test(field('damaged_qty').value) ? Number(field('damaged_qty').value) : 0;
        const usable = Math.max(0, total - damage);
        received += total;
        damaged += damage;
        accepted += usable;
        const price = field('cost_price').value.trim();
        if (/^\d{1,8}(?:\.\d{1,2})?$/.test(price)) cost += Math.round(Number(price) * 100) * usable;
    }
    for (const [name, value] of Object.entries({products, received, damaged, accepted})) {
        document.getElementById('summary_' + name).textContent = value;
    }
    document.getElementById('summary_cost').textContent = '₱' + (cost / 100).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('delivery_count').textContent = products + (products === 1 ? ' product' : ' products');
    const emptyState = document.getElementById('delivery_empty_state');
    if (emptyState) {
        emptyState.hidden = products > 0 || step === 3;
    }
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
    const query = productSearchScan.value.trim().toLowerCase();
    const selected = new Set(Array.from(lineContainer.children, row => row.querySelector('[data-field="product_id"]').value));
    const matches = productsCatalog.filter(p => {
        const matchesCategory = selectedCategory === 'all' || String(p.category_id || '') === selectedCategory;
        const matchesQuery = !query || [
            p.product_name,
            p.sku,
            p.barcode,
            p.case_barcode,
            p.variant_label
        ].filter(Boolean).join(' ').toLowerCase().includes(query);
        return matchesCategory && matchesQuery;
    });
    const catalog = document.getElementById('receiving_catalog');
    catalog.replaceChildren();

    for (const p of matches.slice(0, 24)) {
        const isAdded = selected.has(String(p.product_id));
        const stockInfo = getStockState(p.quantity_on_hand, p.reorder_level, p.safety_stock);
        const iconClass = getCategoryIcon(p.category_name);

        const card = document.createElement('article');
        card.className = 'receiving-card' + (stockInfo.state === 'out' ? ' out-of-stock' : '');
        card.dataset.productId = String(p.product_id);

        const media = document.createElement('div');
        media.className = 'receiving-card-media';
        if (p.product_image) {
            const img = document.createElement('img');
            img.src = p.product_image;
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
        name.title = p.product_name;
        name.textContent = p.product_name;

        const meta = document.createElement('span');
        meta.className = 'receiving-card-meta';
        const catLabel = p.category_name || 'General';
        meta.textContent = catLabel + ' · ' + (p.sku || 'No SKU');

        const costStock = document.createElement('div');
        costStock.className = 'receiving-card-cost-stock';

        const cost = document.createElement('span');
        cost.className = 'receiving-card-cost';
        const rawCost = p.cost_price;
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
        const unitsPerPkg = Number(p.units_per_package || 1);
        const baseUnit = p.base_unit || 'piece';
        const baseLabel = unitsPerPkg === 1 ? baseUnit : (baseUnit.endsWith('s') ? baseUnit : baseUnit + 's');
        const packSmall = document.createElement('small');
        packSmall.textContent = '1 ' + (p.receiving_unit || 'package') + ' = ' + unitsPerPkg + ' ' + baseLabel;
        pack.appendChild(packSmall);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-secondary receiving-card-add';
        button.textContent = isAdded ? 'Added' : '+ Add to Delivery';
        button.setAttribute('aria-label', 'Add ' + p.product_name);
        button.disabled = isAdded;
        button.addEventListener('click', () => {
            addReceivingLine({product_id: p.product_id});
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

    const matchesCount = matches.length;
    const countStatus = query
        ? (matchesCount ? matchesCount + ' matching ' + (matchesCount === 1 ? 'product.' : 'products.') : 'No matching products. Try another name or SKU, or scan a code.')
        : '';
    const statusEl = document.getElementById('product_search_status');
    if (statusEl) statusEl.textContent = countStatus;
}

function renumberLines() {
    clearReceivingErrors();
    const rows = Array.from(lineContainer.children);
    const total = rows.length;
    for (const [index, row] of rows.entries()) {
        const lineNum = index + 1;
        const numEl = row.querySelector('.line-number');
        if (numEl) numEl.textContent = lineNum;
        row.setAttribute('aria-label', 'Product line ' + lineNum);

        const upBtn = row.querySelector('.move-line-up');
        if (upBtn) {
            upBtn.disabled = index === 0 || !canReceive;
            upBtn.setAttribute('aria-label', `Move line ${lineNum} up`);
        }
        const downBtn = row.querySelector('.move-line-down');
        if (downBtn) {
            downBtn.disabled = index === total - 1 || !canReceive;
            downBtn.setAttribute('aria-label', `Move line ${lineNum} down`);
        }
        const removeBtn = row.querySelector('.remove-receiving-line');
        if (removeBtn && !canReceive) {
            removeBtn.disabled = true;
        }

        for (const field of row.querySelectorAll('[data-field]')) {
            field.id = index === 0 ? field.dataset.field : field.dataset.field + '_' + index;
            if (field.hasAttribute('name')) {
                field.name = `lines[${index}][${field.dataset.field}]`;
            }
        }
        for (const label of row.querySelectorAll('label[for]')) {
            const name = label.dataset.labelFor || label.htmlFor.replace(/_\d+$/, '');
            label.dataset.labelFor = name;
            label.htmlFor = index === 0 ? name : name + '_' + index;
        }
        const costHint = row.querySelector('[data-field="cost_hint"]');
        const costPrice = row.querySelector('[data-field="cost_price"]');
        if (costPrice && costHint) costPrice.setAttribute('aria-describedby', costHint.id);
    }
    if (activeLine && !activeLine.isConnected) {
        activeLine = lineContainer.lastElementChild || null;
    }
    refreshDelivery();
}

function activateLine(row) {
    activeLine = row;
}

function restoreField(field, value) {
    if (!field || typeof value !== 'string') return;
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

function isLineTouched(row) {
    const field = name => row.querySelector(`[data-field="${name}"]`);
    return Boolean(
        field('received_qty')?.value.trim()
        || field('received_packages')?.value.trim()
        || (field('damaged_qty')?.value.trim() && field('damaged_qty')?.value.trim() !== '0')
        || field('cost_price')?.value.trim()
        || field('batch_number')?.value.trim()
        || field('expiration_date')?.value.trim()
        || field('discrepancy_notes')?.value.trim()
        || field('notes')?.value.trim()
    );
}

function connectLine(row) {
    row.addEventListener('focusin', () => activateLine(row));
    row.addEventListener('input', clearReceivingErrors, {capture: true});
    row.addEventListener('change', clearReceivingErrors, {capture: true});
    row.querySelector('[data-field="cost_price"]')?.addEventListener('input', refreshDelivery);

    row.querySelector('.move-line-up')?.addEventListener('click', () => {
        const prev = row.previousElementSibling;
        if (prev) {
            lineContainer.insertBefore(row, prev);
            renumberLines();
            const upBtn = row.querySelector('.move-line-up');
            if (upBtn && !upBtn.disabled) upBtn.focus();
            else row.querySelector('.move-line-down')?.focus();
        }
    });

    row.querySelector('.move-line-down')?.addEventListener('click', () => {
        const next = row.nextElementSibling;
        if (next) {
            lineContainer.insertBefore(next, row);
            renumberLines();
            const downBtn = row.querySelector('.move-line-down');
            if (downBtn && !downBtn.disabled) downBtn.focus();
            else row.querySelector('.move-line-up')?.focus();
        }
    });

    row.querySelector('.remove-receiving-line')?.addEventListener('click', () => {
        if (isLineTouched(row)) {
            if (!window.confirm('Remove this product and its entered receiving details?')) return;
        }
        activeScanLookup++;
        if (scanLookupController) scanLookupController.abort();
        row.remove();
        document.getElementById('entry_status').textContent = '';
        activeLine = lineContainer.lastElementChild || null;
        renumberLines();
        if (lineContainer.children.length === 0) {
            if (step !== 1) showStep(1);
            productSearchScan.focus();
        } else if (activeLine) {
            const btn = activeLine.querySelector('.remove-receiving-line');
            if (btn) btn.focus();
        }
    });

    row.querySelector('[data-field="quantity_mode"]')?.addEventListener('change', () => {
        activateLine(row);
        updateQuantityMode(row);
    });

    for (const name of ['received_qty', 'received_packages', 'damaged_qty']) {
        row.querySelector('[data-field="' + name + '"]')?.addEventListener('input', () => {
            activateLine(row);
            updatePackageConversion(row);
        });
    }

    for (const name of ['cost_price', 'batch_number', 'expiration_date', 'discrepancy_notes', 'notes']) {
        const input = row.querySelector(`[data-field="${name}"]`);
        if (input) {
            input.addEventListener('input', () => updateExtrasDisclosure(row));
            input.addEventListener('change', () => updateExtrasDisclosure(row));
        }
    }

    row.addEventListener('dragstart', event => {
        if (step !== 1 || !canReceive || event.target.closest('input, textarea, select')) {
            event.preventDefault();
            return;
        }
        draggedRow = row;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.dataset.productId);
        row.classList.add('is-dragging');
    });
    row.addEventListener('dragend', () => {
        draggedRow = null;
        for (const child of lineContainer.children) {
            child.classList.remove('is-dragging', 'drag-over-above', 'drag-over-below');
        }
    });
    row.addEventListener('dragover', event => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        if (!draggedRow || draggedRow === row) return;
        const rect = row.getBoundingClientRect();
        const midY = rect.top + rect.height / 2;
        if (event.clientY < midY) {
            row.classList.add('drag-over-above');
            row.classList.remove('drag-over-below');
        } else {
            row.classList.add('drag-over-below');
            row.classList.remove('drag-over-above');
        }
    });
    row.addEventListener('dragleave', () => {
        row.classList.remove('drag-over-above', 'drag-over-below');
    });
    row.addEventListener('drop', event => {
        event.preventDefault();
        if (!draggedRow || draggedRow === row) return;
        const isAbove = row.classList.contains('drag-over-above');
        row.classList.remove('drag-over-above', 'drag-over-below');
        if (isAbove) {
            lineContainer.insertBefore(draggedRow, row);
        } else {
            lineContainer.insertBefore(draggedRow, row.nextElementSibling);
        }
        renumberLines();
    });
}

function addReceivingLine(values = {}) {
    const productId = String(values.product_id || values.productId || '');
    if (!productId) return null;
    const product = productMap.get(productId);
    if (!product) return null;

    if (Array.from(lineContainer.children).some(r => r.querySelector('[data-field="product_id"]').value === productId)) {
        return null;
    }

    const row = lineTemplate.content.firstElementChild.cloneNode(true);
    row.dataset.productId = productId;
    row.querySelector('[data-field="product_id"]').value = productId;

    const unitsPerPkg = Number(product.units_per_package || 1);
    const receivingUnit = product.receiving_unit || 'package';
    const baseUnit = product.base_unit || 'piece';
    const baseLabel = unitsPerPkg === 1 ? baseUnit : (baseUnit.endsWith('s') ? baseUnit : baseUnit + 's');
    const packText = `1 ${receivingUnit} = ${unitsPerPkg} ${baseLabel}`;

    row.querySelector('.receiving-product-name').textContent = product.product_name;
    row.querySelector('.receiving-product-sku').textContent = product.sku ? `(${product.sku})` : '';
    row.querySelector('.receiving-product-pack').textContent = packText;
    row.querySelector('.remove-receiving-line').setAttribute('aria-label', `Remove ${product.product_name}`);

    lineContainer.appendChild(row);
    connectLine(row);
    activateLine(row);
    renumberLines();

    for (const [name, value] of Object.entries(values)) {
        if (name !== 'product_id' && value !== undefined && value !== null) {
            restoreField(row.querySelector('[data-field="' + name + '"]'), String(value));
        }
    }
    updateQuantityMode(row);
    updateExtrasDisclosure(row);
    if (!canReceive) {
        row.setAttribute('draggable', 'false');
        row.querySelectorAll('input, select, textarea, button').forEach(el => el.disabled = true);
    }
    refreshDelivery();
    return row;
}

function showStep(next, focus = true) {
    step = next;
    document.querySelector('.receiving-form').dataset.step = String(step);
    const titles = ['1. Select products', '2. Enter quantities', '3. Review'];
    document.getElementById('receiving_step_title').textContent = titles[step - 1];
    document.getElementById('receiving_selection').hidden = step !== 1;
    lineContainer.hidden = step === 3;
    const emptyState = document.getElementById('delivery_empty_state');
    if (emptyState) emptyState.hidden = lineContainer.children.length > 0 || step === 3;
    document.getElementById('receiving_review').hidden = step !== 3;
    document.querySelector('.receiving-summary').hidden = step === 1;
    document.getElementById('delivery_title').textContent = step === 3 ? 'Check your delivery' : 'Delivery lines';
    document.getElementById('summary_status').textContent = step === 3 ? 'Review' : 'Draft';
    document.getElementById('receiving_back').hidden = step === 1;
    document.getElementById('receiving_return_products').hidden = step !== 2;
    document.getElementById('receiving_next').hidden = step === 3;
    document.getElementById('receiving_next').textContent = step === 1 ? 'Add quantity' : 'Review shopping entry';
    form.querySelector('.btn-submit').hidden = step !== 3;
    if (focus) document.getElementById('receiving_step_title').focus();
}

function validateProducts() {
    clearReceivingErrors();
    const rows = Array.from(lineContainer.children);
    if (rows.length === 0) {
        document.getElementById('entry_status').textContent = 'Add at least one product before continuing.';
        productSearchScan.focus();
        return false;
    }
    const products = rows.map(row => row.querySelector('[data-field="product_id"]')?.value).filter(Boolean);
    const valid = products.length === rows.length && new Set(products).size === products.length;
    document.getElementById('entry_status').textContent = valid ? '' : 'Add at least one product before continuing.';
    if (!valid) {
        productSearchScan.focus();
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
    const details = field.closest('details');
    if (details) {
        details.open = true;
    }
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
    if (!canReceive) return true;
    for (const [index, row] of Array.from(lineContainer.children).entries()) {
        const field = name => row.querySelector('[data-field="' + name + '"]');
        const prodId = field('product_id')?.value;
        const prod = productMap.get(String(prodId));
        const packages = field('quantity_mode').value === 'packages';
        const quantity = field(packages ? 'received_packages' : 'received_qty');
        const pack = packages ? Number(prod?.units_per_package || 1) : 1;
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
        const prodId = field('product_id')?.value;
        const prod = productMap.get(String(prodId));
        const section = document.createElement('section');
        section.className = 'receiving-line';
        const title = document.createElement('h3');
        title.textContent = prod ? `${prod.product_name} (${prod.sku})` : 'Product ' + (index + 1);
        const mode = document.createElement('p');
        const qtyModeSelect = field('quantity_mode');
        const modeText = qtyModeSelect.options[qtyModeSelect.selectedIndex]?.textContent || 'Units';
        mode.textContent = modeText + ' · ' + field('package_conversion').textContent;
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
        edit.addEventListener('click', () => {
            showStep(2, false);
            row.scrollIntoView({behavior: 'smooth', block: 'center'});
            const target = field('quantity_mode') || row.querySelector('input:not([type="hidden"]), select');
            target?.focus();
        });
        section.append(title, mode, details, notes, edit);
        review.append(section);
    }
}

document.getElementById('receiving_back').addEventListener('click', () => showStep(step - 1));
document.getElementById('receiving_return_products').addEventListener('click', () => showStep(1));
document.getElementById('receiving_next').addEventListener('click', () => {
    if (!validateProducts()) return;
    if (step === 1) { showStep(2); return; }
    if (validateQuantities()) { renderReview(); showStep(3); }
});

productSearchScan.addEventListener('input', () => {
    renderCatalog();
    const now = performance.now();
    const isScannerBurst = now - lastScanInputAt < 80 && productSearchScan.value.trim().length >= 6;
    lastScanInputAt = now;
    clearTimeout(scanInputTimer);
    if (isScannerBurst) {
        scanInputTimer = setTimeout(() => identifyReceivingProduct(productSearchScan.value), 120);
    }
});

renderCatalog();
renumberLines();

const receivingDraftKey = 'receiving-draft:' + <?= json_encode((string)$_SESSION['user_id']) ?>;
let retainedValues = <?= json_encode($formValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
try {
    if (<?= json_encode($message !== '') ?>) sessionStorage.removeItem(receivingDraftKey);
    else if (canReceive && <?= json_encode($_SERVER['REQUEST_METHOD'] === 'GET') ?>) {
        const draft = JSON.parse(sessionStorage.getItem(receivingDraftKey));
        const lineFields = new Set(Array.from(lineTemplate.content.querySelectorAll('input[data-field], select[data-field], textarea[data-field]'), field => field.dataset.field));
        if (draft && Array.isArray(draft.lines) && draft.lines.every(line => line && typeof line === 'object'
            && Object.entries(line).every(([name, value]) => lineFields.has(name) && typeof value === 'string'))) {
            const requestedLines = retainedValues.lines || [];
            retainedValues = draft;
            for (const line of requestedLines) {
                if (!draft.lines.some(saved => String(saved.product_id) === String(line.product_id))) draft.lines.push(line);
            }
        }
    }
} catch (error) {
    // Browser storage may be unavailable or contain an invalid draft.
}
for (const name of ['supplier', 'invoice_number']) restoreField(form.elements.namedItem(name), retainedValues[name]);
const linesToRestore = Array.isArray(retainedValues.lines)
    ? retainedValues.lines
    : (retainedValues.lines && typeof retainedValues.lines === 'object' ? Object.values(retainedValues.lines) : []);
if (linesToRestore.length > 0) {
    for (const values of linesToRestore) addReceivingLine(values);
} else if (retainedValues.product_id) {
    addReceivingLine(retainedValues);
}

let saving = false;
form.addEventListener('submit', event => {
    if (saving) { event.preventDefault(); return; }
    if (!canReceive) { event.preventDefault(); return; }
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

const hasQuantities = linesToRestore.some(line =>
    (line.received_qty !== undefined && line.received_qty !== '') ||
    (line.received_packages !== undefined && line.received_packages !== '')
);
showStep(hasQuantities ? 2 : 1);

function saveReceivingDraft() {
    if (!canReceive) return;
    const draft = {
        supplier: form.elements.supplier.value,
        invoice_number: form.elements.invoice_number.value,
        lines: Array.from(lineContainer.children, row => Object.fromEntries(
            Array.from(row.querySelectorAll('input[data-field], select[data-field], textarea[data-field]'),
                field => [field.dataset.field, field.value])
        ))
    };
    try {
        if (draft.lines.length || draft.supplier || draft.invoice_number) {
            sessionStorage.setItem(receivingDraftKey, JSON.stringify(draft));
        } else sessionStorage.removeItem(receivingDraftKey);
    } catch (error) {
        document.getElementById('entry_status').textContent = 'Draft could not be kept in this browser. Keep this page open until the delivery is saved.';
    }
}
form.addEventListener('input', saveReceivingDraft);
form.addEventListener('change', saveReceivingDraft);
new MutationObserver(saveReceivingDraft).observe(lineContainer, {childList: true});
saveReceivingDraft();
</script>
<?php if ($message): ?><script>RetailMindUI.toast(<?= json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, 'success');</script><?php endif; ?>
<?php if ($error): ?><script>RetailMindUI.toast(<?= json_encode($error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, 'error');</script><?php endif; ?>
</body>
</html>
