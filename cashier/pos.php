<?php
// cashier/pos.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_role(['admin', 'cashier']);

$salesWorkflowService = new SalesWorkflowService($pdo);

$checkout_error = '';
$checkout_notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_cart') {
    csrf_verify();

    $reason = trim($_POST['void_reason'] ?? '');
    if ($reason === '') {
        $checkout_error = 'A reason is required to void the current sale.';
    } else {
        log_activity($pdo, (int)$_SESSION['user_id'], 'Voided sale before final checkout: ' . $reason);
        $checkout_notice = 'Sale voided before checkout and audit log was recorded.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cart']) && ($_POST['action'] ?? '') !== 'void_cart') {
    csrf_verify();

    $cart = json_decode($_POST['cart'], true) ?: [];
    $payment_method = in_array($_POST['payment_method'] ?? '', ['cash', 'card', 'ewallet'], true)
        ? $_POST['payment_method'] : 'cash';
    $paymentDetails = [
        'cash_received' => $_POST['cash_received'] ?? 0,
        'payment_reference' => $_POST['payment_reference'] ?? '',
    ];

    try {
        $result = $salesWorkflowService->checkout($cart, (int)$_SESSION['user_id'], $payment_method, $paymentDetails);
        header('Location: ' . app_url('invoice/receipt.php?sale_id=' . $result['sale_id']));
        exit;
    } catch (RuntimeException $e) {
        $checkout_error = $e->getMessage();
    } catch (PDOException $e) {
        $checkout_error = 'Checkout failed due to a database error. Please try again.';
    }
}

$products = $salesWorkflowService->getActiveProducts();

$productLookup = [];
$productSearch = [];
foreach ($products as $product) {
    $lookupEntry = [
        'product_id' => (int)$product['product_id'],
        'sku' => $product['sku'],
        'barcode' => $product['barcode'],
        'name' => $product['product_name'],
        'price' => (float)$product['unit_price'],
        'quantity_on_hand' => (int)$product['quantity_on_hand'],
        'reorder_level' => (int)($product['reorder_level'] ?? 0),
        'safety_stock' => (int)($product['safety_stock'] ?? 0),
    ];
    $productLookup[$product['sku']] = $lookupEntry;
    if (!empty($product['barcode'])) {
        $productLookup[$product['barcode']] = $lookupEntry;
    }
    $productSearch[] = $lookupEntry;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier.css')) ?>">
</head>
<body class="cashier-page">
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar cashier-topbar">
            <div class="cashier-heading">
                <h1>Checkout</h1>
                <p>Scan items, review the cart, and collect payment.</p>
            </div>
            <div class="cashier-meta" aria-label="Cashier session information">
                <span class="cashier-chip online">Register ready</span>
                <span class="cashier-chip"><i class="bi bi-person" aria-hidden="true"></i><?= htmlspecialchars($_SESSION['full_name']) ?></span>
                <span class="cashier-chip" id="cashier-clock"><i class="bi bi-clock" aria-hidden="true"></i>--:--</span>
            </div>
        </header>

        <div class="cashier-shortcuts" aria-label="Keyboard shortcuts">
            <strong>Shortcuts:</strong>
            <kbd>F2</kbd><span>Scan</span>
            <kbd>F3</kbd><span>Search</span>
            <kbd>F4</kbd><span>Hold</span>
            <kbd>Ctrl + Enter</kbd><span>Checkout</span>
        </div>

        <?php if ($checkout_error): ?>
            <div class="pos-alert error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><?= htmlspecialchars($checkout_error) ?></div>
        <?php endif; ?>
        <?php if ($checkout_notice): ?>
            <div class="pos-alert success" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><?= htmlspecialchars($checkout_notice) ?></div>
        <?php endif; ?>

        <div class="pos-grid">
            <section class="pos-workspace" aria-label="Product entry">
                <div class="pos-panel">
                    <div class="pos-panel-header">
                        <div class="pos-panel-title">
                            <div class="pos-panel-icon"><i class="bi bi-upc-scan" aria-hidden="true"></i></div>
                            <div>
                                <h2>Scan an item</h2>
                                <p>USB scanners can scan directly into the field below.</p>
                            </div>
                        </div>
                        <div class="pos-toolbar">
                            <button type="button" class="btn btn-small btn-secondary" id="start-scanner">
                                <i class="bi bi-camera" aria-hidden="true"></i>Use camera
                            </button>
                            <button type="button" class="btn btn-small btn-danger" id="stop-scanner" disabled>
                                <i class="bi bi-stop-circle" aria-hidden="true"></i>Stop
                            </button>
                        </div>
                    </div>

                    <label class="scan-label" for="sku-input">Barcode or SKU</label>
                    <div class="scan-row">
                        <input id="sku-input" class="pos-input" type="text" placeholder="Scan barcode or enter SKU" autocomplete="off" autofocus inputmode="search">
                        <button type="button" class="btn" id="add-code-btn" title="Add item (Enter)">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>Add item
                        </button>
                    </div>
                    <div id="cart-message" class="cart-message" role="status" aria-live="polite"></div>

                    <div id="recent-scan" class="recent-scan">
                        <div class="recent-scan-icon"><i class="bi bi-upc" aria-hidden="true"></i></div>
                        <div class="recent-scan-copy">
                            <strong>Ready to scan</strong>
                            <span>Your most recently added product will appear here.</span>
                        </div>
                        <div class="recent-scan-price">—</div>
                    </div>

                    <div class="scanner-area" id="scanner-area">
                        <div id="scanner-reader" class="scanner-reader"></div>
                        <div id="scanner-result" class="scanner-status" aria-live="polite">
                            <strong>Camera scanner</strong>
                            <span>Start the camera, then place a barcode inside the frame.</span>
                        </div>
                    </div>
                </div>

                <div class="pos-panel">
                    <div class="pos-panel-header">
                        <div class="pos-panel-title">
                            <div class="pos-panel-icon"><i class="bi bi-search" aria-hidden="true"></i></div>
                            <div>
                                <h2>Find a product</h2>
                                <p>Search by product name, SKU, or barcode when scanning is unavailable.</p>
                            </div>
                        </div>
                        <span class="cashier-chip" id="search-count">0 results</span>
                    </div>
                    <div class="search-bar-wrap">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input id="product-search" class="pos-input" type="search" placeholder="Start typing a product name or code" autocomplete="off" title="Product search (F3)">
                    </div>
                    <div id="search-results" class="search-results" aria-live="polite"></div>
                </div>
            </section>

            <aside class="pos-panel checkout-panel" aria-label="Current sale">
                <div class="pos-panel-header">
                    <div>
                        <div class="cart-heading">
                            <h2>Current sale</h2>
                            <span class="count-badge" id="cart-line-count">0</span>
                        </div>
                        <p class="muted" style="margin-top:0.2rem;font-size:0.82rem;">Review quantities before accepting payment.</p>
                    </div>
                    <button type="button" class="btn btn-small btn-secondary" id="clear-cart-btn" onclick="clearCart()" disabled>
                        <i class="bi bi-trash3" aria-hidden="true"></i>Clear
                    </button>
                </div>

                <div class="cart-table-wrap">
                    <table class="cart-table" aria-label="Cart items">
                        <thead>
                            <tr><th>Item</th><th>Quantity</th><th>Subtotal</th><th><span class="sr-only">Remove</span></th></tr>
                        </thead>
                        <tbody id="cart-body"></tbody>
                    </table>
                </div>

                <div class="checkout-total">
                    <div class="checkout-total-label">
                        <strong>Total due</strong>
                        <span><span id="cart-item-count">0</span> item(s)</span>
                    </div>
                    <div class="checkout-total-value">&#8369;<span id="cart-total">0.00</span></div>
                </div>

                <form method="POST" id="checkout-form" class="payment-section">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="checkout">
                    <input type="hidden" name="cart" id="cart-input">

                    <div class="payment-field">
                        <label class="pos-field-label" for="payment-method">Payment method</label>
                        <select name="payment_method" id="payment-method" class="pos-select">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="ewallet">E-Wallet</option>
                        </select>
                    </div>

                    <div class="payment-grid">
                        <div class="payment-field" id="cash-field">
                            <label class="pos-field-label" for="cash-received">Cash received</label>
                            <input type="number" min="0" step="0.01" name="cash_received" id="cash-received" placeholder="0.00" inputmode="decimal">
                        </div>
                        <div class="payment-field" id="change-field">
                            <label class="pos-field-label" for="change-due">Change due</label>
                            <input type="text" id="change-due" value="0.00" disabled>
                        </div>
                    </div>

                    <div class="cash-quick" id="cash-quick" aria-label="Quick cash amounts">
                        <button type="button" data-tender="exact">Exact</button>
                        <button type="button" data-tender="50">+50</button>
                        <button type="button" data-tender="100">+100</button>
                        <button type="button" data-tender="500">+500</button>
                    </div>

                    <div class="payment-field hidden" id="reference-field">
                        <label class="pos-field-label" for="payment-reference">Payment reference</label>
                        <input type="text" name="payment_reference" id="payment-reference" placeholder="Card approval or wallet reference">
                    </div>

                    <button class="btn btn-block checkout-primary" id="checkout-button" type="button" onclick="checkoutNow()" title="Checkout (Ctrl+Enter)" disabled>
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>Complete checkout
                    </button>
                </form>

                <div class="secondary-sale-actions">
                    <button class="btn btn-secondary" id="hold-sale-btn" type="button" onclick="holdCurrentSale()" title="Hold sale (F4)" disabled>
                        <i class="bi bi-pause-circle" aria-hidden="true"></i>Hold sale
                    </button>
                    <button class="btn btn-danger" id="void-sale-btn" type="button" onclick="voidCurrentSale()" disabled>
                        <i class="bi bi-x-circle" aria-hidden="true"></i>Void sale
                    </button>
                </div>

                <div class="held-sales">
                    <div class="held-sales-header">
                        <h3>Held sales</h3>
                        <span class="count-badge" id="held-count">0</span>
                    </div>
                    <div id="hold-list" class="hold-list"></div>
                </div>

                <form method="POST" id="void-form" hidden>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="void_cart">
                    <input type="hidden" name="void_reason" id="void-reason-input">
                </form>
            </aside>
        </div>
    </main>
</div>

<div id="checkout-modal" class="checkout-modal" role="dialog" aria-modal="true" aria-labelledby="checkout-title">
    <div class="checkout-dialog">
        <div class="checkout-dialog-header">
            <div>
                <h3 id="checkout-title">Confirm checkout</h3>
                <p>Verify the payment details before completing the sale.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeCheckoutConfirm()" aria-label="Close checkout confirmation"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="checkout-dialog-body">
            <div id="checkout-summary" class="checkout-summary-card"></div>
        </div>
        <div class="checkout-dialog-actions">
            <button type="button" class="btn btn-secondary" onclick="closeCheckoutConfirm()">Review cart</button>
            <button type="button" class="btn" onclick="submitConfirmedCheckout()"><i class="bi bi-check2" aria-hidden="true"></i>Confirm payment</button>
        </div>
    </div>
</div>

<div id="void-modal" class="checkout-modal" role="dialog" aria-modal="true" aria-labelledby="void-title">
    <div class="checkout-dialog">
        <div class="checkout-dialog-header">
            <div>
                <h3 id="void-title">Void current sale</h3>
                <p>The reason will be saved in the audit log.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeVoidModal()" aria-label="Close void dialog"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="checkout-dialog-body">
            <label class="pos-field-label" for="void-reason">Reason for voiding</label>
            <textarea class="void-reason" id="void-reason" placeholder="Example: Customer cancelled the purchase"></textarea>
            <div id="void-error" class="cart-message error" role="alert"></div>
        </div>
        <div class="checkout-dialog-actions">
            <button type="button" class="btn btn-secondary" onclick="closeVoidModal()">Keep sale</button>
            <button type="button" class="btn btn-danger" onclick="confirmVoidSale()"><i class="bi bi-trash3" aria-hidden="true"></i>Void sale</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode"></script>
<script>
let cart = {};
let heldSales = [];
let scanCooldown = false;
let scannerActive = false;
let checkoutConfirmed = false;
let messageTimer = null;

const productLookup = <?= json_encode($productLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const productSearch = <?= json_encode($productSearch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const skuInput = document.getElementById('sku-input');
const productSearchInput = document.getElementById('product-search');
const searchResults = document.getElementById('search-results');
const searchCount = document.getElementById('search-count');
const cartMessage = document.getElementById('cart-message');
const scannerResult = document.getElementById('scanner-result');
const scannerArea = document.getElementById('scanner-area');
const recentScan = document.getElementById('recent-scan');
const startBtn = document.getElementById('start-scanner');
const stopBtn = document.getElementById('stop-scanner');
const addCodeBtn = document.getElementById('add-code-btn');
const checkoutForm = document.getElementById('checkout-form');
const cartInput = document.getElementById('cart-input');
const paymentMethod = document.getElementById('payment-method');
const cashReceived = document.getElementById('cash-received');
const changeDue = document.getElementById('change-due');
const referenceField = document.getElementById('reference-field');
const paymentReference = document.getElementById('payment-reference');
const holdList = document.getElementById('hold-list');
const checkoutModal = document.getElementById('checkout-modal');
const checkoutSummary = document.getElementById('checkout-summary');
const voidModal = document.getElementById('void-modal');
const voidReason = document.getElementById('void-reason');
const voidError = document.getElementById('void-error');
const checkoutButton = document.getElementById('checkout-button');
const holdSaleButton = document.getElementById('hold-sale-btn');
const voidSaleButton = document.getElementById('void-sale-btn');
const clearCartButton = document.getElementById('clear-cart-btn');
const cashQuick = document.getElementById('cash-quick');
const barcodeApiUrl = <?= json_encode(app_url('api/barcode.php')) ?>;
const lowStockFallback = 5;
const scannerConfig = { fps: 10, qrbox: { width: 250, height: 180 }, aspectRatio: 1.4 };
const html5QrCode = window.Html5Qrcode ? new Html5Qrcode('scanner-reader') : null;

function money(value) {
    return Number(value || 0).toFixed(2);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));
}

function getCartTotal() {
    return Object.values(cart).reduce((total, item) => total + (Number(item.price) * Number(item.qty)), 0);
}

function getCartItemCount() {
    return Object.values(cart).reduce((total, item) => total + Number(item.qty || 0), 0);
}

function getLowStockThreshold(item) {
    return Math.max(Number(item.reorder_level || 0), Number(item.safety_stock || 0), lowStockFallback);
}

function showCartMessage(message, type = 'info') {
    window.clearTimeout(messageTimer);
    cartMessage.textContent = message;
    cartMessage.className = `cart-message visible ${type}`;
    messageTimer = window.setTimeout(() => {
        cartMessage.className = 'cart-message';
        cartMessage.textContent = '';
    }, 5000);
}

function showRecentScan(product, code, status = 'success') {
    recentScan.classList.toggle('warning', status !== 'success');
    recentScan.innerHTML = product
        ? `<div class="recent-scan-icon"><i class="bi bi-check2" aria-hidden="true"></i></div>
           <div class="recent-scan-copy"><strong>${escapeHtml(product.name)}</strong><span>${escapeHtml(code)} &middot; Stock ${Number(product.quantity_on_hand)}</span></div>
           <div class="recent-scan-price">&#8369;${money(product.price)}</div>`
        : `<div class="recent-scan-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
           <div class="recent-scan-copy"><strong>Product not found</strong><span>${escapeHtml(code)}</span></div>
           <div class="recent-scan-price">—</div>`;
}

async function fetchProductByCode(code) {
    const normalized = String(code || '').trim();
    if (normalized === '') {
        showCartMessage('Enter a SKU or barcode to add.', 'error');
        return null;
    }

    if (productLookup[normalized]) {
        return productLookup[normalized];
    }

    try {
        const response = await fetch(`${barcodeApiUrl}?action=search`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ barcode: normalized })
        });
        const data = await response.json();

        if (data.success && data.product) {
            const entry = {
                product_id: Number(data.product.product_id),
                sku: data.product.sku,
                barcode: data.product.barcode,
                name: data.product.product_name,
                price: Number(data.product.unit_price),
                quantity_on_hand: Number(data.product.quantity_on_hand),
                reorder_level: Number(data.product.reorder_level || 0),
                safety_stock: Number(data.product.safety_stock || 0)
            };

            productLookup[entry.sku] = entry;
            if (entry.barcode) {
                productLookup[entry.barcode] = entry;
            }
            return entry;
        }

        showRecentScan(null, normalized, 'warning');
        showCartMessage(`No product found for ${normalized}. Try product search.`, 'error');
        return null;
    } catch (error) {
        showCartMessage('Product lookup failed. Check the network or login session.', 'error');
        return null;
    }
}

async function addCodeFromInput() {
    const code = skuInput.value.trim();
    const product = await fetchProductByCode(code);
    if (!product) {
        skuInput.select();
        return;
    }

    addToCart(product.product_id, product.name, product.price, product.quantity_on_hand, product.reorder_level, product.safety_stock, product.sku, product.barcode);
    showRecentScan(product, code);
    skuInput.value = '';
    skuInput.focus();
    scannerResult.innerHTML = `<strong>Item added</strong><span>${escapeHtml(product.name)}</span>`;
}

function addToCart(id, name, price, stock = Infinity, reorderLevel = 0, safetyStock = 0, sku = '', barcode = '') {
    const productId = Number(id);
    const currentQty = cart[productId]?.qty || 0;
    const availableStock = Number(stock);
    if (currentQty + 1 > availableStock) {
        showCartMessage(`Cannot add more. Only ${availableStock} in stock.`, 'error');
        return;
    }

    cart[productId] = {
        name,
        price: Number(price),
        qty: currentQty + 1,
        stock: Number.isFinite(availableStock) ? availableStock : undefined,
        reorder_level: Number(reorderLevel || 0),
        safety_stock: Number(safetyStock || 0),
        sku,
        barcode
    };
    renderCart();

    const remaining = availableStock - cart[productId].qty;
    if (Number.isFinite(availableStock) && remaining <= getLowStockThreshold(cart[productId])) {
        showCartMessage(`${name} added. Low-stock warning: ${remaining} will remain.`, 'info');
    } else {
        showCartMessage(`${name} added to the cart.`, 'success');
    }
}

function updateCartQty(id, newQty) {
    const item = cart[id];
    if (!item) {
        return;
    }

    const qty = Number(newQty);
    if (!Number.isInteger(qty) || qty <= 0) {
        removeFromCart(id);
        return;
    }

    if (item.stock !== undefined && qty > item.stock) {
        cart[id].qty = item.stock;
        showCartMessage(`Only ${item.stock} units of ${item.name} are available.`, 'error');
    } else {
        cart[id].qty = qty;
    }
    renderCart();
}

function changeCartQty(id, delta) {
    const item = cart[id];
    if (item) {
        updateCartQty(id, item.qty + delta);
    }
}

function removeFromCart(id) {
    if (!cart[id]) {
        return;
    }
    const itemName = cart[id].name;
    delete cart[id];
    renderCart();
    showCartMessage(`${itemName} removed from the cart.`, 'info');
}

function clearCart() {
    if (Object.keys(cart).length === 0) {
        return;
    }
    if (!confirm('Clear all items from the current sale?')) {
        return;
    }
    cart = {};
    cashReceived.value = '';
    paymentReference.value = '';
    renderCart();
    showCartMessage('Cart cleared.', 'info');
    skuInput.focus();
}

function renderCart() {
    const cartBody = document.getElementById('cart-body');
    let rows = '';

    if (Object.keys(cart).length === 0) {
        rows = '<tr><td colspan="4" class="cart-empty-cell"><i class="bi bi-cart3" aria-hidden="true"></i>Scan or search for a product to begin.</td></tr>';
    } else {
        for (const id in cart) {
            const item = cart[id];
            const subtotal = item.price * item.qty;
            const remaining = item.stock !== undefined ? item.stock - item.qty : null;
            const threshold = getLowStockThreshold(item);
            const stockNote = item.stock !== undefined ? `Stock ${item.stock}` : 'Stock unavailable';
            const warning = remaining !== null && remaining <= threshold
                ? `<span class="stock-warning">Only ${remaining} left after sale</span>`
                : '';

            rows += `<tr>
                <td><strong class="cart-item-name" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</strong><span class="cart-item-meta">${escapeHtml(item.sku || item.barcode || '')} &middot; ${stockNote}</span>${warning}</td>
                <td><div class="cart-actions"><button type="button" class="qty-btn" onclick="changeCartQty(${Number(id)}, -1)" aria-label="Decrease ${escapeHtml(item.name)} quantity">−</button><input class="qty-field" type="number" min="1" value="${Number(item.qty)}" onchange="updateCartQty(${Number(id)}, Number(this.value))" aria-label="${escapeHtml(item.name)} quantity"><button type="button" class="qty-btn" onclick="changeCartQty(${Number(id)}, 1)" aria-label="Increase ${escapeHtml(item.name)} quantity">+</button></div></td>
                <td><strong>&#8369;${money(subtotal)}</strong></td>
                <td><button type="button" class="remove-item-btn" onclick="removeFromCart(${Number(id)})" aria-label="Remove ${escapeHtml(item.name)}"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
            </tr>`;
        }
    }

    const lineCount = Object.keys(cart).length;
    const itemCount = getCartItemCount();
    const hasItems = lineCount > 0;
    cartBody.innerHTML = rows;
    document.getElementById('cart-total').textContent = money(getCartTotal());
    document.getElementById('cart-item-count').textContent = itemCount;
    document.getElementById('cart-line-count').textContent = lineCount;
    checkoutButton.disabled = !hasItems;
    holdSaleButton.disabled = !hasItems;
    voidSaleButton.disabled = !hasItems;
    clearCartButton.disabled = !hasItems;
    updatePaymentFields();
    persistCart();
}

function updatePaymentFields() {
    const total = getCartTotal();
    const isCash = paymentMethod.value === 'cash';

    document.getElementById('cash-field').classList.toggle('hidden', !isCash);
    document.getElementById('change-field').classList.toggle('hidden', !isCash);
    referenceField.classList.toggle('hidden', isCash);
    cashQuick.classList.toggle('hidden', !isCash);
    paymentReference.required = !isCash;
    cashReceived.required = isCash;

    const received = Number(cashReceived.value || 0);
    changeDue.value = money(Math.max(0, received - total));
}

function setQuickTender(value) {
    const total = getCartTotal();
    if (total <= 0) {
        showCartMessage('Add items before entering payment.', 'error');
        return;
    }
    cashReceived.value = money(value === 'exact' ? total : total + Number(value));
    updatePaymentFields();
}

function validateCheckout() {
    const payload = Object.entries(cart).map(([product_id, item]) => ({ product_id: Number(product_id), qty: Number(item.qty) }));
    if (payload.length === 0) {
        showCartMessage('Cart is empty. Add items before checkout.', 'error');
        skuInput.focus();
        return false;
    }

    const total = getCartTotal();
    if (paymentMethod.value === 'cash' && Number(cashReceived.value || 0) < total) {
        showCartMessage('Cash received is less than the total due.', 'error');
        cashReceived.focus();
        return false;
    }
    if (paymentMethod.value !== 'cash' && paymentReference.value.trim() === '') {
        showCartMessage('Enter the card or e-wallet payment reference.', 'error');
        paymentReference.focus();
        return false;
    }

    cartInput.value = JSON.stringify(payload);
    return true;
}

function checkoutNow() {
    if (!validateCheckout()) {
        return;
    }

    const itemCount = getCartItemCount();
    const methodLabel = paymentMethod.options[paymentMethod.selectedIndex].text;
    const paymentLine = paymentMethod.value === 'cash'
        ? `<p><strong>Cash:</strong> &#8369;${money(cashReceived.value)} &nbsp; <strong>Change:</strong> &#8369;${changeDue.value}</p>`
        : `<p><strong>Reference:</strong> ${escapeHtml(paymentReference.value.trim())}</p>`;

    checkoutSummary.innerHTML = `
        <p><strong>${itemCount}</strong> item(s) totaling <strong>&#8369;${money(getCartTotal())}</strong></p>
        <p><strong>Payment method:</strong> ${escapeHtml(methodLabel)}</p>
        ${paymentLine}
    `;
    checkoutModal.classList.add('open');
    checkoutModal.querySelector('.btn:last-child').focus();
}

function closeCheckoutConfirm() {
    checkoutModal.classList.remove('open');
    checkoutConfirmed = false;
    checkoutButton.focus();
}

function submitConfirmedCheckout() {
    if (!validateCheckout()) {
        closeCheckoutConfirm();
        return;
    }
    checkoutConfirmed = true;
    checkoutForm.submit();
}

function holdCurrentSale() {
    if (Object.keys(cart).length === 0) {
        showCartMessage('Cart is empty. Add items before holding a sale.', 'error');
        return;
    }

    const held = {
        id: Date.now(),
        created_at: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
        cart: JSON.parse(JSON.stringify(cart))
    };
    heldSales.unshift(held);
    cart = {};
    cashReceived.value = '';
    paymentReference.value = '';
    persistHeldSales();
    renderCart();
    renderHeldSales();
    showCartMessage('Sale held. Resume it from the Held Sales section.', 'success');
    skuInput.focus();
}

function resumeHeldSale(id) {
    if (Object.keys(cart).length > 0 && !confirm('Replace the current cart with this held sale?')) {
        return;
    }

    const held = heldSales.find(item => Number(item.id) === Number(id));
    if (!held) {
        return;
    }
    cart = held.cart || {};
    heldSales = heldSales.filter(item => Number(item.id) !== Number(id));
    persistHeldSales();
    renderCart();
    renderHeldSales();
    showCartMessage('Held sale resumed.', 'success');
    skuInput.focus();
}

function removeHeldSale(id) {
    if (!confirm('Remove this held sale?')) {
        return;
    }
    heldSales = heldSales.filter(item => Number(item.id) !== Number(id));
    persistHeldSales();
    renderHeldSales();
}

function renderHeldSales() {
    document.getElementById('held-count').textContent = heldSales.length;
    if (heldSales.length === 0) {
        holdList.innerHTML = '<div class="search-empty" style="min-height:72px;">No held sales.</div>';
        return;
    }

    holdList.innerHTML = heldSales.map(held => {
        const items = Object.values(held.cart || {});
        const qty = items.reduce((sum, item) => sum + Number(item.qty || 0), 0);
        const total = items.reduce((sum, item) => sum + (Number(item.price || 0) * Number(item.qty || 0)), 0);
        return `<div class="hold-item">
            <div><strong>${escapeHtml(held.created_at)}</strong><br><small>${qty} item(s) &middot; &#8369;${money(total)}</small></div>
            <div class="pos-toolbar"><button type="button" class="btn btn-small" onclick="resumeHeldSale(${Number(held.id)})">Resume</button><button type="button" class="btn btn-small btn-secondary" onclick="removeHeldSale(${Number(held.id)})">Remove</button></div>
        </div>`;
    }).join('');
}

function voidCurrentSale() {
    if (Object.keys(cart).length === 0) {
        showCartMessage('Cart is already empty.', 'error');
        return;
    }
    voidReason.value = '';
    voidError.textContent = '';
    voidError.className = 'cart-message error';
    voidModal.classList.add('open');
    setTimeout(() => voidReason.focus(), 50);
}

function closeVoidModal() {
    voidModal.classList.remove('open');
    voidSaleButton.focus();
}

function confirmVoidSale() {
    const reason = voidReason.value.trim();
    if (reason === '') {
        voidError.textContent = 'Enter a reason before voiding the sale.';
        voidError.className = 'cart-message visible error';
        voidReason.focus();
        return;
    }

    cart = {};
    persistCart();
    document.getElementById('void-reason-input').value = reason;
    document.getElementById('void-form').submit();
}

function renderSearchResults() {
    const term = productSearchInput.value.trim().toLowerCase();
    if (term === '') {
        searchCount.textContent = '0 results';
        searchResults.innerHTML = '<div class="search-empty"><div><i class="bi bi-keyboard" aria-hidden="true" style="display:block;font-size:1.4rem;margin-bottom:0.35rem;"></i>Enter at least one letter or number to search.</div></div>';
        return;
    }

    const results = productSearch
        .filter(product => String(product.name || '').toLowerCase().includes(term)
            || String(product.sku || '').toLowerCase().includes(term)
            || String(product.barcode || '').toLowerCase().includes(term))
        .slice(0, 12);

    searchCount.textContent = `${results.length} result${results.length === 1 ? '' : 's'}`;
    if (results.length === 0) {
        searchResults.innerHTML = '<div class="search-empty">No matching active product was found.</div>';
        return;
    }

    searchResults.innerHTML = results.map(product => {
        const threshold = Math.max(Number(product.reorder_level || 0), Number(product.safety_stock || 0), lowStockFallback);
        const low = Number(product.quantity_on_hand) <= threshold;
        return `<button type="button" class="search-result" data-product-id="${Number(product.product_id)}">
            <span><strong class="search-result-name">${escapeHtml(product.name)}</strong><span class="search-result-meta"><span>${escapeHtml(product.sku || product.barcode || 'No code')}</span><span class="search-result-stock ${low ? 'low' : ''}">Stock ${Number(product.quantity_on_hand)}</span></span></span>
            <strong class="search-result-price">&#8369;${money(product.price)}</strong>
        </button>`;
    }).join('');
}

function persistCart() {
    try {
        sessionStorage.setItem('pos_cart', JSON.stringify(cart));
    } catch (error) {}
}

function persistHeldSales() {
    try {
        sessionStorage.setItem('pos_held_sales', JSON.stringify(heldSales));
    } catch (error) {}
}

function restoreState() {
    try {
        const storedCart = sessionStorage.getItem('pos_cart');
        const storedHeld = sessionStorage.getItem('pos_held_sales');
        if (storedCart) {
            const parsedCart = JSON.parse(storedCart);
            if (parsedCart && typeof parsedCart === 'object' && !Array.isArray(parsedCart)) {
                cart = parsedCart;
            }
        }
        if (storedHeld) {
            const parsedHeld = JSON.parse(storedHeld);
            if (Array.isArray(parsedHeld)) {
                heldSales = parsedHeld;
            }
        }
    } catch (error) {
        cart = {};
        heldSales = [];
    }
    renderCart();
    renderHeldSales();
}

function onScanSuccess(decodedText) {
    if (scanCooldown) {
        return;
    }
    scanCooldown = true;
    setTimeout(() => { scanCooldown = false; }, 700);

    const code = String(decodedText).trim();
    skuInput.value = code;
    scannerResult.innerHTML = `<strong>Barcode detected</strong><span>${escapeHtml(code)}</span>`;
    if (navigator.vibrate) {
        navigator.vibrate(100);
    }
    addCodeFromInput();
}

function startScanner() {
    scannerArea.classList.add('open');
    if (!html5QrCode) {
        scannerResult.innerHTML = '<strong>Camera unavailable</strong><span>The scanner library could not be loaded.</span>';
        return;
    }
    if (scannerActive) {
        return;
    }

    startBtn.disabled = true;
    scannerResult.innerHTML = '<strong>Starting camera</strong><span>Allow camera access when prompted.</span>';
    html5QrCode.start({ facingMode: 'environment' }, scannerConfig, onScanSuccess, () => {})
        .then(() => {
            scannerActive = true;
            stopBtn.disabled = false;
            scannerResult.innerHTML = '<strong>Camera active</strong><span>Place a barcode inside the frame.</span>';
        })
        .catch(error => {
            startBtn.disabled = false;
            stopBtn.disabled = true;
            scannerResult.innerHTML = `<strong>Camera error</strong><span>${escapeHtml(error)}</span>`;
        });
}

function stopScanner() {
    if (!html5QrCode || !scannerActive) {
        scannerArea.classList.remove('open');
        return;
    }
    html5QrCode.stop()
        .then(() => {
            scannerActive = false;
            startBtn.disabled = false;
            stopBtn.disabled = true;
            scannerArea.classList.remove('open');
            scannerResult.innerHTML = '<strong>Camera stopped</strong><span>Use the camera button to scan again.</span>';
            skuInput.focus();
        })
        .catch(() => {
            scannerResult.innerHTML = '<strong>Unable to stop camera</strong><span>Refresh the page if the camera remains active.</span>';
        });
}

function updateClock() {
    const now = new Date();
    document.getElementById('cashier-clock').innerHTML = `<i class="bi bi-clock" aria-hidden="true"></i>${now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}

startBtn.addEventListener('click', startScanner);
stopBtn.addEventListener('click', stopScanner);
addCodeBtn.addEventListener('click', addCodeFromInput);
paymentMethod.addEventListener('change', updatePaymentFields);
cashReceived.addEventListener('input', updatePaymentFields);
productSearchInput.addEventListener('input', renderSearchResults);
cashQuick.addEventListener('click', event => {
    const button = event.target.closest('[data-tender]');
    if (button) {
        setQuickTender(button.dataset.tender);
    }
});
searchResults.addEventListener('click', event => {
    const button = event.target.closest('[data-product-id]');
    if (!button) {
        return;
    }
    const product = productSearch.find(item => Number(item.product_id) === Number(button.dataset.productId));
    if (!product) {
        return;
    }
    addToCart(product.product_id, product.name, product.price, product.quantity_on_hand, product.reorder_level, product.safety_stock, product.sku, product.barcode);
    showRecentScan(product, product.sku || product.barcode || product.name);
    productSearchInput.value = '';
    renderSearchResults();
    skuInput.focus();
});

skuInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') {
        event.preventDefault();
        addCodeFromInput();
    }
});

checkoutForm.addEventListener('submit', event => {
    if (!checkoutConfirmed) {
        event.preventDefault();
        checkoutNow();
    }
});

[checkoutModal, voidModal].forEach(modal => {
    modal.addEventListener('mousedown', event => {
        if (event.target === modal) {
            modal === checkoutModal ? closeCheckoutConfirm() : closeVoidModal();
        }
    });
});

document.addEventListener('keydown', event => {
    if (event.key === 'F2') {
        event.preventDefault();
        skuInput.focus();
        skuInput.select();
        return;
    }
    if (event.key === 'F3') {
        event.preventDefault();
        productSearchInput.focus();
        return;
    }
    if (event.key === 'F4') {
        event.preventDefault();
        holdCurrentSale();
        return;
    }
    if (event.key === 'Escape') {
        if (checkoutModal.classList.contains('open')) {
            closeCheckoutConfirm();
        } else if (voidModal.classList.contains('open')) {
            closeVoidModal();
        }
        return;
    }
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        checkoutNow();
    }
});

restoreState();
renderSearchResults();
updateClock();
setInterval(updateClock, 30000);
setTimeout(() => skuInput.focus(), 100);
</script>
</body>
</html>
