<?php
// cashier/pos.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../../../backend/app/Services/CashierShiftService.php';

use App\Services\CashierShiftService;

// Issue #86: the active Cashier workspace is the authoritative POS context.
// An Administrator who also holds the Cashier role must switch workspaces;
// the admin workspace can never bypass Cashier controls.
require_role(['cashier']);

$salesWorkflowService = new SalesWorkflowService($pdo);
$shiftService = new CashierShiftService($pdo);
$cashierId = (int)$_SESSION['user_id'];
require_capability(\App\Authorization\RoleCapabilityPolicy::OPERATE_POINT_OF_SALE);
// Ticket #89: checkout is attributed to the workspace that is active now, so
// the service judges the same context this page did.
$actorRole = (string)current_role();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['recover_checkout'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        $saved = $salesWorkflowService->recoverAttempt((string)$_GET['recover_checkout'], $cashierId, $actorRole);
        echo json_encode(['sale' => $saved, 'receipt_url' => $saved === null ? null : app_url('components/invoice/sales.php?tab=transactions&sale_id=' . $saved['sale_id'] . '&checkout=complete')], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(409);
        echo json_encode(['message' => 'Checkout recovery is unavailable. Retry recovery before collecting another payment.']);
        error_log('Checkout recovery failed: ' . (string)$e);
    }
    exit;
}

$checkout_error = '';
$checkout_notice = '';
$lock_error = '';

// Ticket #90: locking and unlocking are a break, never an ending. Neither one
// touches the shift's status, opening float, or closing figures — only
// cashier_shifts.locked_at — so a Cashier who walks away mid-shift comes back to
// the same shift on the same Register. Logging out does the same, because
// logout destroys the session and nothing else.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['lock_register', 'unlock_register'], true)) {
    csrf_verify();
    try {
        if (($_POST['action'] ?? '') === 'lock_register') {
            $shiftService->lockRegister($cashierId, $actorRole);
        } else {
            $shiftService->unlockRegister($cashierId, $actorRole, (string)($_POST['pos_unlock_password'] ?? ''));
        }
        // Post/Redirect/Get: reloading a lock or unlock must not repeat it.
        header('Location: ' . app_url('components/cashier/pos.php'));
        exit;
    } catch (Throwable $e) {
        $lock_error = \App\Support\OperatorAlert::message($e, 'The register could not be locked or unlocked. Try again and tell your Administrator if it keeps happening.');
    }
}

// The open shift and its lock are resolved after the POST above, so a lock or
// unlock in this request is what this page reflects. Issue #86: only the Cashier
// workspace reaches this page, so the open shift gate always applies — including
// to Administrators selling as Cashiers.
$openShift = $shiftService->getOpenShift($cashierId);
$posShiftOpen = $openShift !== null;
$posRegisterLocked = $shiftService->isRegisterLocked($cashierId);

// Ticket #90: the lock screen replaces the point of sale, so nothing below may
// act on a request that arrives while the Register is locked. Checkout already
// refuses at the service seam; voiding a cart also writes a Protected Audit
// Record, so it must be refused here or a locked till could be made to author
// one on somebody else's behalf.
if ($posRegisterLocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // The one message a locked Register produces, in the page's own refusal
    // channel — the same channel the void and checkout refusals below use.
    $checkout_error = CashierShiftService::LOCKED_MESSAGE;
    if (($_POST['action'] ?? '') === 'review_quote') {
        csrf_verify();
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        http_response_code(409);
        echo json_encode(['message' => $checkout_error], JSON_THROW_ON_ERROR);
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_cart') {
    csrf_verify();

    $reason = trim($_POST['void_reason'] ?? '');
    if ($reason === '') {
        $checkout_error = 'A reason is required to void the current sale.';
    } else {
        try {
            App\Store\StoreWriteGate::begin($pdo);
            $shiftService->lockOpenShift($cashierId, true);
            log_activity($pdo, $cashierId, 'Voided sale before final checkout: ' . $reason);
            $pdo->commit();
            $checkout_notice = 'Sale voided before checkout and audit log was recorded.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $checkout_error = \App\Support\OperatorAlert::message($e, 'The current sale could not be voided.');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['cart'])
    && ($_POST['action'] ?? '') !== 'void_cart'
    && !$posRegisterLocked) {
    csrf_verify();

    $cart = json_decode($_POST['cart'], true) ?: [];
    $payment_method = in_array($_POST['payment_method'] ?? '', ['cash', 'card', 'ewallet'], true)
        ? $_POST['payment_method'] : 'cash';
    $paymentDetails = [
        'checkout_attempt' => (string)($_POST['checkout_attempt'] ?? ''),
        'cash_received' => $_POST['cash_received'] ?? 0,
        'payment_reference' => $_POST['payment_reference'] ?? '',
        'payment_method' => $payment_method,
        'payment_verified' => ($_POST['payment_verified'] ?? '') === '1',
        'discount_type' => $_POST['discount_type'] ?? 'none',
        'discount_value' => $_POST['discount_value'] ?? 0,
        'discount_reason' => $_POST['discount_reason'] ?? '',
        'discount_approver_username' => $_POST['discount_approver_username'] ?? '',
        'discount_approver_password' => $_POST['discount_approver_password'] ?? '',
        'reviewed_quote' => $_SESSION['checkout_quotes'][(string)($_POST['quote_token'] ?? '')]['state_hash'] ?? '',
        // Ticket #91: the cart the Cashier resumed, if any. The service settles it
        // against this Cashier and against the shift it already resolved for the
        // sale, so a value tampered with here completes nothing.
        'held_sale_id' => (int)($_POST['held_sale_id'] ?? 0),
    ];

    try {
        if (($_POST['action'] ?? '') === 'review_quote') {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            $quote = $salesWorkflowService->reviewQuote($cart, $cashierId, $actorRole, $paymentDetails);
            $token = bin2hex(random_bytes(24));
            $_SESSION['checkout_quotes'] = array_slice($_SESSION['checkout_quotes'] ?? [], -19, null, true);
            $_SESSION['checkout_quotes'][$token] = ['state_hash' => $quote['state_hash']];
            unset($quote['state_hash'], $quote['discount']['approval_state']);
            echo json_encode(['quote' => $quote, 'token' => $token], JSON_THROW_ON_ERROR);
            exit;
        }
        // Ticket #89: the active workspace is passed explicitly so the service,
        // not the account's stored role, decides who may sell.
        $result = $salesWorkflowService->checkout($cart, $cashierId, $actorRole, $payment_method, $paymentDetails);
        header('Location: ' . app_url('components/invoice/sales.php?tab=transactions&sale_id=' . $result['sale_id'] . '&checkout=complete'));
        exit;
    } catch (Throwable $e) {
        $checkout_error = \App\Support\OperatorAlert::message($e, 'The sale could not finish. Please try again. Tell your Administrator if this keeps happening.');
        if (($_POST['action'] ?? '') === 'review_quote') {
            http_response_code(409);
            echo json_encode(['message' => $checkout_error], JSON_THROW_ON_ERROR);
            exit;
        }
    }
}

// Ticket #91: the fixed set of reasons a held sale can be discarded for. The
// dialog offers exactly these, and the service accepts exactly these, so the
// page cannot drift into offering a reason the audit record would not
// understand. Read straight off the service the way this page already reads
// CashierShiftService::LOCKED_MESSAGE — no second instance is built to read it.
$heldSaleDiscardReasons = \App\Services\HeldSaleService::DISCARD_REASONS;

[$quickProductScope, $quickProductParams] = store_product_scope('p');
$quickProductStmt = $pdo->prepare(
    "SELECT p.product_id, p.sku, p.barcode, p.product_name, p.variant_label, p.unit_price,
            p.product_image, p.category_id, c.category_name,
            COALESCE(p.reorder_level, 0) AS reorder_level,
            COALESCE(p.safety_stock, 0) AS safety_stock,
            COALESCE(p.quantity_sold, 0) AS quantity_sold,
            COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand
     FROM products p
     LEFT JOIN inventory i ON i.product_id = p.product_id
     LEFT JOIN categories c ON c.category_id = p.category_id
     WHERE p.status = 'active'{$quickProductScope}
     ORDER BY COALESCE(p.quantity_sold, 0) DESC, p.product_name
     LIMIT 120"
);
$quickProductStmt->execute($quickProductParams);
$quickProducts = array_map(static function (array $product): array {
    $image = trim((string)($product['product_image'] ?? ''));
    if ($image !== '' && !preg_match('#^(?:https?://|data:image/|/)#i', $image)) {
        $image = strpos($image, 'storage/images/') === 0
            ? rtrim(dirname(app_base_url()), '/') . '/backend/' . $image
            : app_url($image);
    }

    return [
        'product_id' => (int)$product['product_id'],
        'name' => (string)$product['product_name'] . (!empty($product['variant_label']) ? ' - ' . $product['variant_label'] : ''),
        'sku' => (string)($product['sku'] ?? ''),
        'barcode' => (string)($product['barcode'] ?? ''),
        'price' => (float)$product['unit_price'],
        'quantity_on_hand' => (int)$product['quantity_on_hand'],
        'reorder_level' => (int)$product['reorder_level'],
        'safety_stock' => (int)$product['safety_stock'],
        'quantity_sold' => (int)$product['quantity_sold'],
        'category_id' => (int)($product['category_id'] ?? 0),
        'category_name' => (string)($product['category_name'] ?: 'Uncategorized'),
        'product_image' => $image,
    ];
}, $quickProductStmt->fetchAll(PDO::FETCH_ASSOC));

$quickCategories = [];
foreach ($quickProducts as $product) {
    $categoryId = $product['category_id'];
    if (!isset($quickCategories[$categoryId])) {
        $quickCategories[$categoryId] = ['name' => $product['category_name'], 'count' => 0];
    }
    $quickCategories[$categoryId]['count']++;
}
uasort($quickCategories, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
$quickCategoryIcon = static function (string $categoryName): string {
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
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier.css')) ?>">
</head>
<body class="cashier-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <?php if ($posRegisterLocked): ?>
            <?php // Ticket #90: a locked Register shows a lock screen instead of the point of sale. The sidebar stays, so the Cashier can still log out — logging out leaves the shift open. ?>
            <section class="pos-lock" aria-labelledby="pos-lock-title">
                <div class="pos-lock-card">
                    <div class="pos-lock-icon"><i class="bi bi-lock-fill" aria-hidden="true"></i></div>
                    <h2 id="pos-lock-title">Register locked</h2>
                    <p class="pos-lock-lede">
                        <strong><?= htmlspecialchars((string)($openShift['register_name'] ?? 'Unassigned')) ?></strong>
                        &middot; Shift #<?= (int)$openShift['shift_id'] ?>
                        &middot; locked <?= htmlspecialchars(format_display_datetime((string)$openShift['locked_at'])) ?>
                    </p>
                    <?php if ($lock_error !== ''): ?>
                        <div class="pos-lock-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><?= htmlspecialchars($lock_error) ?></div>
                    <?php endif; ?>
                    <?php if ($checkout_error !== ''): ?>
                        <div class="pos-alert error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><?= htmlspecialchars($checkout_error) ?></div>
                    <?php endif; ?>
                    <form method="post" class="pos-lock-form" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unlock_register">
                        <label class="pos-field-label" for="pos-unlock-password">Your account password</label>
                        <input class="pos-input" type="password" id="pos-unlock-password" name="pos_unlock_password" autocomplete="current-password" required autofocus>
                        <p class="pos-lock-note">Your Cashier Shift is still open on this Register. No sale can be recorded until you unlock it.</p>
                        <button class="btn btn-block" type="submit"><i class="bi bi-unlock" aria-hidden="true"></i>Unlock and resume</button>
                    </form>
                    <p class="pos-lock-note">Logging out keeps the shift open too, so you can unlock and resume this same shift whenever you are back.</p>
                </div>
            </section>
        <?php else: ?>
        <?php if ($checkout_error): ?>
            <div class="pos-alert error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><?= htmlspecialchars($checkout_error) ?></div>
        <?php endif; ?>
        <?php // A refused lock renders here, on the unlocked page it left us on: the Cashier clicked "Lock register" and must be told why, not shown a POS that silently ignored them. ?>
        <?php if ($lock_error !== ''): ?>
            <div class="pos-alert error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><?= htmlspecialchars($lock_error) ?></div>
        <?php endif; ?>
        <?php if ($checkout_notice): ?>
            <div class="pos-alert success" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><?= htmlspecialchars($checkout_notice) ?></div>
        <?php endif; ?>
        <?php if ($posShiftOpen): ?>
            <div class="pos-register-bar">
                <p class="pos-register-identity">
                    <i class="bi bi-upc-scan" aria-hidden="true"></i>
                    <span>Register <strong><?= htmlspecialchars((string)($openShift['register_name'] ?? 'Unassigned')) ?></strong></span>
                    <span class="pos-register-shift">Shift #<?= (int)$openShift['shift_id'] ?></span>
                </p>
                <form method="post" data-confirm="Lock this register for a break? Your Cashier Shift stays open and your Register stays yours. Nobody else can use it until you unlock it with your password." data-confirm-title="Lock register" data-confirm-button="Lock register">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="lock_register">
                    <button class="btn btn-secondary" type="submit"><i class="bi bi-lock" aria-hidden="true"></i>Lock register</button>
                </form>
            </div>
        <?php endif; ?>
        <?php if (!$posShiftOpen): ?>
            <div class="pos-alert error" role="alert"><i class="bi bi-clock-history" aria-hidden="true"></i>Open a Cashier Shift before checkout. <a href="<?= htmlspecialchars(app_url('components/cashier/shifts.php')) ?>">Open shift</a></div>
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

                    <div class="scanner-area" id="scanner-area">
                        <div id="scanner-reader" class="scanner-reader"></div>
                        <div id="scanner-result" class="scanner-status" aria-live="polite">
                            <strong>Camera scanner</strong>
                            <span>Start the camera, then place a barcode inside the frame.</span>
                        </div>
                    </div>
                </div>

                <section class="quick-add-section" aria-labelledby="quick-add-title">
                    <div class="quick-add-header">
                        <div class="quick-add-title-row">
                            <h2 id="quick-add-title">Quick Add Products</h2>
                            <span id="quick-product-count" class="quick-product-count"></span>
                        </div>
                        <div class="quick-add-toolbar">
                            <div class="quick-product-search">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <label class="sr-only" for="quick-product-search">Search product by name or SKU</label>
                                <input id="quick-product-search" type="search" placeholder="Search product by name or SKU" autocomplete="off">
                            </div>
                            <button type="button" class="quick-grid-toggle" id="quick-grid-toggle" title="Toggle compact product grid" aria-label="Toggle compact product grid" aria-pressed="false">
                                <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="quick-category-filters" id="quick-category-filters" aria-label="Product categories">
                        <button type="button" class="quick-category-filter active" data-quick-category="all">
                            <span class="quick-category-icon"><i class="bi bi-grid" aria-hidden="true"></i></span>
                            <strong>All</strong>
                            <small><?= count($quickProducts) ?> items</small>
                        </button>
                        <?php foreach ($quickCategories as $categoryId => $category): ?>
                            <button type="button" class="quick-category-filter" data-quick-category="<?= (int)$categoryId ?>">
                                <span class="quick-category-icon"><i class="bi <?= htmlspecialchars($quickCategoryIcon($category['name'])) ?>" aria-hidden="true"></i></span>
                                <strong><?= htmlspecialchars($category['name']) ?></strong>
                                <small><?= (int)$category['count'] ?> item<?= (int)$category['count'] === 1 ? '' : 's' ?></small>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div id="quick-product-grid" class="quick-product-grid" aria-live="polite"></div>
                </section>

            </section>

            <aside class="pos-panel checkout-panel" aria-label="Current sale">
                <div class="pos-panel-header">
                    <div>
                        <div class="cart-heading">
                            <h2>Current sale</h2>
                            <span class="count-badge" id="cart-line-count">0</span>
                        </div>
                        <p class="muted u-pos-hint">Review quantities before accepting payment.</p>
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

                <div class="payment-breakdown" aria-label="Payment summary">
                    <div><span>Subtotal</span><strong>&#8369;<span id="summary-subtotal">0.00</span></strong></div>
                    <div><span>Discount</span><strong>-&#8369;<span id="summary-discount">0.00</span></strong></div>
                    <div><span>Change</span><strong>&#8369;<span id="summary-change">0.00</span></strong></div>
                </div>

                <form method="POST" id="checkout-form" class="payment-section">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="checkout">
                    <input type="hidden" name="checkout_attempt" id="checkout-attempt-input">
                    <input type="hidden" name="quote_token" id="quote-token-input">
                    <input type="hidden" name="payment_verified" value="0">
                    <input type="hidden" name="cart" id="cart-input">
                    <input type="hidden" name="held_sale_id" id="held-sale-id-input" value="">

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
                            <input type="text" name="cash_received" id="cash-received" placeholder="Enter after quote review" inputmode="decimal" autocomplete="off" readonly>
                        </div>
                        <div class="payment-field" id="change-field">
                            <label class="pos-field-label" for="change-due">Change due</label>
                            <input type="text" id="change-due" value="0.00" readonly aria-readonly="true">
                        </div>
                    </div>

                    <div class="cash-quick" id="cash-quick" aria-label="Quick cash amounts">
                        <span class="muted">Review the final quote to enter cash and see change.</span>
                    </div>

                    <div class="payment-field hidden" id="reference-field">
                        <label class="pos-field-label" for="payment-reference">Payment reference</label>
                        <input type="text" name="payment_reference" id="payment-reference" placeholder="Enter after quote review" readonly>
                    </div>

                    <details class="payment-field" id="discount-panel">
                        <summary class="pos-field-label">Discount or promotion</summary><p class="muted u-mt-05">Review the final server quote before collecting payment. Only the best eligible discount applies.</p>
                        <div class="payment-grid u-mt-075">
                            <div><label class="pos-field-label" for="discount-type">Discount type</label><select name="discount_type" id="discount-type" class="pos-select"><option value="none">No discount</option><option value="percentage">Percentage</option><option value="fixed">Fixed amount</option></select></div>
                            <div><label class="pos-field-label" for="discount-value">Value</label><input type="number" min="0" step="0.01" name="discount_value" id="discount-value" value="0"></div>
                        </div>
                        <div class="payment-field"><label class="pos-field-label" for="discount-reason">Reason</label><input type="text" name="discount_reason" id="discount-reason" placeholder="Promotion, customer eligibility, or approved adjustment"></div>
                        <div class="payment-grid hidden" id="supervisor-fields">
                            <div><label class="pos-field-label" for="discount-approver-username">Administrator username</label><input type="text" name="discount_approver_username" id="discount-approver-username" autocomplete="off"></div>
                            <div><label class="pos-field-label" for="discount-approver-password">Administrator password</label><input type="password" name="discount_approver_password" id="discount-approver-password" autocomplete="new-password"></div>
                        </div>
                        <small id="discount-summary" class="muted">No discount applied.</small>
                    </details>

                    <button class="btn btn-block checkout-primary" id="checkout-button" type="button" onclick="checkoutNow()" title="Checkout (Ctrl+Enter)" disabled>
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>Review final quote
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
                <h3 id="checkout-title">Review final quote before payment</h3>
                <p>Verify the payment details before completing the sale.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeCheckoutConfirm()" aria-label="Close checkout confirmation"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="checkout-dialog-body">
            <div id="checkout-summary" class="checkout-summary-card"></div>
        </div>
        <div class="checkout-dialog-actions">
            <button type="button" class="btn btn-secondary" onclick="closeCheckoutConfirm()">Review cart</button>
            <button type="button" class="btn" id="confirm-checkout-button" onclick="submitConfirmedCheckout()"><i class="bi bi-check2" aria-hidden="true"></i>Confirm payment</button>
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

<div id="discard-modal" class="checkout-modal" role="dialog" aria-modal="true" aria-labelledby="discard-title">
    <div class="checkout-dialog">
        <div class="checkout-dialog-header">
            <div>
                <h3 id="discard-title">Discard held sale</h3>
                <p>The reason is recorded in the audit log. Discarding moves no cash.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeDiscardModal()" aria-label="Close discard dialog"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="checkout-dialog-body">
            <label class="pos-field-label" for="discard-reason">Reason for discarding</label>
            <select id="discard-reason" class="pos-select">
                <?php foreach ($heldSaleDiscardReasons as $discardReasonKey => $discardReasonLabel): ?>
                    <option value="<?= htmlspecialchars($discardReasonKey) ?>"><?= htmlspecialchars($discardReasonLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="pos-field-label" for="discard-note">Note (required when the reason is Other)</label>
            <textarea class="void-reason" id="discard-note" placeholder="Example: Customer collected their items another way"></textarea>
            <div id="discard-error" class="cart-message error" role="alert"></div>
        </div>
        <div class="checkout-dialog-actions">
            <button type="button" class="btn btn-secondary" onclick="closeDiscardModal()">Keep held sale</button>
            <button type="button" class="btn btn-danger" onclick="confirmDiscardHeldSale()"><i class="bi bi-trash3" aria-hidden="true"></i>Discard held sale</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= app_url('assets/js/checkout-attempt.js') ?>"></script>
<script src="<?= app_url('assets/js/checkout-quote.js') ?>"></script>
<script>
let cart = {};
let heldSales = [];
// Ticket #91: the held sale this cart came from, if any. Checkout sends it so
// the sale settles that held sale on the shift it was held under; the service
// settles it against the Cashier and the shift, so this is a hint, not a claim.
let resumedHeldSaleId = 0;
let scanCooldown = false;
let scannerActive = false;
let checkoutConfirmed = false;
let checkoutSubmitting = false;
let messageTimer = null;
let lastScannedCode = '';
let lastScannedAt = 0;
let posAudioContext = null;

const quickProducts = <?= json_encode($quickProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const quickProductById = new Map(quickProducts.map(product => [Number(product.product_id), product]));
const productLookup = {};
quickProducts.forEach(product => {
    if (product.sku) productLookup[product.sku] = product;
    if (product.barcode) productLookup[product.barcode] = product;
});
const skuInput = document.getElementById('sku-input');
const cartMessage = document.getElementById('cart-message');
const scannerResult = document.getElementById('scanner-result');
const scannerArea = document.getElementById('scanner-area');
const recentScan = document.getElementById('recent-scan');
const startBtn = document.getElementById('start-scanner');
const stopBtn = document.getElementById('stop-scanner');
const addCodeBtn = document.getElementById('add-code-btn');
const checkoutForm = document.getElementById('checkout-form');
const cartInput = document.getElementById('cart-input');
const resumedHeldSaleInput = document.getElementById('held-sale-id-input');
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
const discardModal = document.getElementById('discard-modal');
const discardReason = document.getElementById('discard-reason');
const discardNote = document.getElementById('discard-note');
const discardError = document.getElementById('discard-error');
let discardingHeldSaleId = 0;
const checkoutButton = document.getElementById('checkout-button');
const confirmCheckoutButton = document.getElementById('confirm-checkout-button');
const holdSaleButton = document.getElementById('hold-sale-btn');
const voidSaleButton = document.getElementById('void-sale-btn');
const clearCartButton = document.getElementById('clear-cart-btn');
const cashQuick = document.getElementById('cash-quick');
const discountType = document.getElementById('discount-type');
const discountValue = document.getElementById('discount-value');
const discountReason = document.getElementById('discount-reason');
const discountSummary = document.getElementById('discount-summary');
const supervisorFields = document.getElementById('supervisor-fields');
const fullscreenToggle = document.getElementById('pos-fullscreen-toggle');
const cashierClock = document.getElementById('cashier-clock');
const quickProductSearch = document.getElementById('quick-product-search');
const quickCategoryFilters = document.getElementById('quick-category-filters');
const quickProductGrid = document.getElementById('quick-product-grid');
const quickProductCount = document.getElementById('quick-product-count');
const quickGridToggle = document.getElementById('quick-grid-toggle');
const findProductUrl = <?= json_encode(app_url('components/cashier/findProduct.php')) ?>;
const barcodeApiUrl = <?= json_encode(app_url('components/barcodeScanner/apiScanner/barcode.php')) ?>;
const heldSalesApiUrl = <?= json_encode(app_url('components/barcodeScanner/apiScanner/held_sales.php')) ?>;
const csrfToken = <?= json_encode(generate_csrf_token()) ?>;
const posShiftOpen = <?= $posShiftOpen ? 'true' : 'false' ?>;
const lowStockFallback = 5;
function getSupportedBarcodeFormats() {
    const formats = window.Html5QrcodeSupportedFormats;
    if (!formats) return undefined;
    return [
        formats.QR_CODE,
        formats.CODE_128,
        formats.CODE_39,
        formats.CODE_93,
        formats.EAN_13,
        formats.EAN_8,
        formats.UPC_A,
        formats.UPC_E,
        formats.ITF,
        formats.CODABAR,
    ].filter(format => typeof format === 'number');
}

const scannerConfig = {
    fps: 10,
    qrbox: { width: 280, height: 180 },
    aspectRatio: 1.4,
    disableFlip: true,
    formatsToSupport: getSupportedBarcodeFormats(),
    useBarCodeDetectorIfSupported: true,
    experimentalFeatures: { useBarCodeDetectorIfSupported: true },
};
const html5QrCode = window.Html5Qrcode ? new Html5Qrcode('scanner-reader', {
    formatsToSupport: getSupportedBarcodeFormats(),
    useBarCodeDetectorIfSupported: true,
}) : null;

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

function setTextIfPresent(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value;
    }
}

function quickProductMedia(product) {
    if (product.product_image) {
        return `<img src="${escapeHtml(product.product_image)}" alt="" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><i class="bi ${quickProductIcon(product)}" aria-hidden="true" hidden></i>`;
    }
    return `<i class="bi ${quickProductIcon(product)}" aria-hidden="true"></i>`;
}

function quickProductIcon(product) {
    const category = String(product.category_name || '').toLowerCase();
    if (category.includes('beverage') || category.includes('drink')) return 'bi-cup-straw';
    if (category.includes('snack') || category.includes('biscuit')) return 'bi-cookie';
    if (category.includes('personal') || category.includes('health')) return 'bi-heart-pulse';
    if (category.includes('school') || category.includes('office')) return 'bi-pencil';
    if (category.includes('electronic')) return 'bi-battery-charging';
    if (category.includes('baking') || category.includes('grocery')) return 'bi-basket2';
    return 'bi-bag';
}

function quickProductStockState(product) {
    const stock = Number(product.quantity_on_hand || 0);
    const threshold = Math.max(Number(product.reorder_level || 0), Number(product.safety_stock || 0), lowStockFallback);
    return stock <= 0 ? 'out' : (stock <= threshold ? 'low' : 'available');
}

function addQuickProduct(productId) {
    const product = quickProductById.get(Number(productId));
    if (!product || Number(product.quantity_on_hand) <= 0) {
        return;
    }
    addToCart(product.product_id, product.name, product.price, product.quantity_on_hand, product.reorder_level, product.safety_stock, product.sku, product.barcode);
}

function updateQuickProductAvailability() {
    document.querySelectorAll('.quick-product-card[data-product-id]').forEach(card => {
        const product = quickProductById.get(Number(card.dataset.productId));
        if (!product) return;
        const stock = Number(product.quantity_on_hand);
        const quantity = Number(cart[product.product_id]?.qty || 0);
        const remaining = stock - quantity;
        const action = card.querySelector('.quick-product-action');
        card.classList.toggle('selected', quantity > 0);
        if (stock <= 0) {
            action.innerHTML = '<button type="button" class="quick-product-add" disabled>Out of stock</button>';
        } else if (quantity > 0) {
            action.innerHTML = `<div class="quick-product-stepper" aria-label="${escapeHtml(product.name)} quantity">
                <button type="button" data-quick-decrease="${Number(product.product_id)}" aria-label="Decrease ${escapeHtml(product.name)}"><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
                <strong>${quantity}</strong>
                <button type="button" data-quick-add="${Number(product.product_id)}" aria-label="Add another ${escapeHtml(product.name)}" ${remaining <= 0 ? 'disabled' : ''}><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
            </div>`;
        } else {
            action.innerHTML = `<button type="button" class="quick-product-add" data-quick-add="${Number(product.product_id)}"><i class="bi bi-plus-lg" aria-hidden="true"></i>Add to Cart</button>`;
        }
    });
}

function renderQuickProducts() {
    const term = quickProductSearch.value.trim().toLowerCase();
    const activeCategory = quickCategoryFilters.querySelector('.active')?.dataset.quickCategory || 'all';
    const products = quickProducts.filter(product => {
        const categoryMatches = activeCategory === 'all' || Number(product.category_id) === Number(activeCategory);
        const searchText = `${product.name} ${product.sku} ${product.barcode}`.toLowerCase();
        return categoryMatches && (term === '' || searchText.includes(term));
    });

    quickProductCount.textContent = `${products.length} product${products.length === 1 ? '' : 's'}`;
    if (!products.length) {
        quickProductGrid.innerHTML = '<div class="quick-products-empty"><i class="bi bi-search" aria-hidden="true"></i><span>No matching products found.</span></div>';
        return;
    }

    quickProductGrid.innerHTML = products.map(product => {
        const stock = Number(product.quantity_on_hand || 0);
        const stockState = quickProductStockState(product);
        const stockLabel = stockState === 'out' ? 'Out of stock' : `${stock} in stock`;
        const warning = stockState === 'low' ? '<span class="quick-stock-warning"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>Low stock</span>' : '';
        return `<article class="quick-product-card ${stockState === 'out' ? 'out-of-stock' : ''}" data-product-id="${Number(product.product_id)}">
            <div class="quick-product-image">${quickProductMedia(product)}${warning}</div>
            <div class="quick-product-details">
                <strong class="quick-product-name" title="${escapeHtml(product.name)}">${escapeHtml(product.name)}</strong>
                <span class="quick-product-sku">${escapeHtml(product.category_name)} &middot; ${escapeHtml(product.sku || product.barcode || 'No SKU')}</span>
                <div class="quick-product-meta">
                    <strong>&#8369;${money(product.price)}</strong>
                    <span class="quick-product-stock ${stockState}"><i class="bi bi-circle-fill" aria-hidden="true"></i>${stockLabel}</span>
                </div>
                <div class="quick-product-action"></div>
            </div>
        </article>`;
    }).join('');
    updateQuickProductAvailability();
}

function getCartTotal() {
    return Object.values(cart).reduce((total, item) => total + (Number(item.price) * Number(item.qty)), 0);
}

function getDiscountAmount() {
    const gross = getCartTotal();
    const value = Math.max(0, Number(discountValue?.value || 0));
    if (discountType?.value === 'percentage') {
        return Math.min(gross, gross * Math.min(value, 100) / 100);
    }
    if (discountType?.value === 'fixed') {
        return Math.min(gross, value);
    }
    return 0;
}

function getNetTotal() {
    return Math.max(0, getCartTotal() - getDiscountAmount());
}

function getCartItemCount() {
    return Object.values(cart).reduce((total, item) => total + Number(item.qty || 0), 0);
}

function getLowStockThreshold(item) {
    return Math.max(Number(item.reorder_level || 0), Number(item.safety_stock || 0), lowStockFallback);
}

function showCartMessage(message, type = 'info') {
    window.clearTimeout(messageTimer);
    const kind = ['error', 'warning', 'success', 'info'].includes(type) ? type : 'info';
    const clean = (window.RetailMindUI && typeof window.RetailMindUI.sanitizeAlert === 'function')
        ? window.RetailMindUI.sanitizeAlert(String(message == null ? '' : message), kind)
        : { message: String(message == null ? '' : message), tech: '' };
    cartMessage.textContent = clean.tech ? clean.message + '\n' + clean.tech : clean.message;
    cartMessage.className = `cart-message visible ${type}`;
    messageTimer = window.setTimeout(() => {
        cartMessage.className = 'cart-message';
        cartMessage.textContent = '';
    }, 5000);
}

function playScanTone(success = true) {
    try {
        posAudioContext = posAudioContext || new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = posAudioContext.createOscillator();
        const gain = posAudioContext.createGain();
        oscillator.type = success ? 'sine' : 'square';
        oscillator.frequency.value = success ? 880 : 220;
        gain.gain.setValueAtTime(0.0001, posAudioContext.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.08, posAudioContext.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, posAudioContext.currentTime + (success ? 0.12 : 0.28));
        oscillator.connect(gain); gain.connect(posAudioContext.destination);
        oscillator.start(); oscillator.stop(posAudioContext.currentTime + (success ? 0.13 : 0.3));
    } catch (error) {}
}

function pulseRecentScan() {
    if (!recentScan) {
        return;
    }
    recentScan.classList.remove('scan-pulse');
    void recentScan.offsetWidth;
    recentScan.classList.add('scan-pulse');
}

function showRecentScan(product, code, status = 'success') {
    playScanTone(status === 'success');
    if (!recentScan) {
        return;
    }
    recentScan.classList.toggle('warning', status !== 'success');
    pulseRecentScan();
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

async function clearCart() {
    if (Object.keys(cart).length === 0) {
        return;
    }
    if (!await RetailMindUI.confirm({title:'Clear current sale',message:'Remove every item from the cart?',confirmText:'Clear cart',danger:true})) return;
    cart = {};
    resetPaymentState();
    renderCart();
    showCartMessage('Cart cleared.', 'info');
    skuInput.focus();
}

function resetPaymentState() {
    paymentMethod.value = 'cash';
    cashReceived.value = '';
    paymentReference.value = '';
    discountType.value = 'none';
    discountValue.value = '0';
    discountReason.value = '';
    // Ticket #91: a cart that has been emptied or replaced is no longer a
    // resumed held sale, so the next checkout must not claim to complete one.
    // resumeHeldSale() sets this again once the cart is in place.
    resumedHeldSaleId = 0;
    resumedHeldSaleInput.value = '';
    const approverUsername = document.getElementById('discount-approver-username');
    const approverPassword = document.getElementById('discount-approver-password');
    if (approverUsername) approverUsername.value = '';
    if (approverPassword) approverPassword.value = '';
    updatePaymentFields();
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
    const gross = getCartTotal();
    const discount = getDiscountAmount();
    const net = getNetTotal();
    const hasItems = lineCount > 0;
    cartBody.innerHTML = rows;
    document.getElementById('cart-total').textContent = money(net);
    document.getElementById('cart-item-count').textContent = itemCount;
    document.getElementById('cart-line-count').textContent = lineCount;
    setTextIfPresent('snapshot-lines', lineCount);
    setTextIfPresent('snapshot-items', itemCount);
    setTextIfPresent('snapshot-discount', money(discount));
    setTextIfPresent('snapshot-total', money(net));
    document.getElementById('summary-subtotal').textContent = money(gross);
    document.getElementById('summary-discount').textContent = money(discount);
    checkoutButton.disabled = !hasItems || !posShiftOpen;
    holdSaleButton.disabled = !hasItems || !posShiftOpen;
    voidSaleButton.disabled = !hasItems;
    clearCartButton.disabled = !hasItems;
    updatePaymentFields();
    persistCart();
    updateQuickProductAvailability();
}

function updatePaymentFields() {
    const total = getNetTotal();
    const gross = getCartTotal();
    const discount = getDiscountAmount();
    const isCash = paymentMethod.value === 'cash';

    document.getElementById('cash-field').classList.toggle('hidden', !isCash);
    document.getElementById('change-field').classList.toggle('hidden', !isCash);
    referenceField.classList.toggle('hidden', isCash);
    cashQuick.classList.toggle('hidden', !isCash);
    paymentReference.required = !isCash;
    cashReceived.required = isCash;

    const receivedValue = Number(cashReceived.value);
    const received = Number.isFinite(receivedValue) && receivedValue >= 0 ? receivedValue : 0;
    changeDue.value = money(Math.max(0, received - total));
    document.getElementById('summary-subtotal').textContent = money(gross);
    document.getElementById('summary-discount').textContent = money(discount);
    document.getElementById('summary-change').textContent = money(Math.max(0, received - total));
    setTextIfPresent('snapshot-discount', money(discount));
    setTextIfPresent('snapshot-total', money(total));
    if (discountSummary) {
        discountSummary.textContent = discount > 0 ? `Gross ₱${money(gross)} · Discount ₱${money(discount)} · Net ₱${money(total)}` : 'No discount applied.';
        supervisorFields.classList.toggle('hidden', !(discount > gross * 0.10));
    }
}


function setQuickTender(value) {
    const total = getNetTotal();
    if (total <= 0) {
        showCartMessage('Add items before entering payment.', 'error');
        return;
    }
    cashReceived.value = money(value === 'exact' ? total : total + Number(value));
    updatePaymentFields();
}

function validateCheckout(requirePayment = true) {
    const payload = Object.entries(cart).map(([product_id, item]) => ({ product_id: Number(product_id), qty: Number(item.qty) }));
    if (payload.length === 0) {
        showCartMessage('Cart is empty. Add items before checkout.', 'error');
        skuInput.focus();
        return false;
    }

    const total = checkoutQuote.isCurrent() ? checkoutQuote.reviewed.total : getNetTotal();
    if (requirePayment && paymentMethod.value === 'cash') {
        const received = Number(cashReceived.value);
        if (!Number.isFinite(received) || received < 0) {
            showCartMessage('Enter a valid cash amount.', 'error');
            cashReceived.focus();
            return false;
        }
        if (received < total) {
            showCartMessage('Cash received is less than the total due.', 'error');
            cashReceived.focus();
            return false;
        }
    }
    if (requirePayment && paymentMethod.value !== 'cash' && paymentReference.value.trim() === '') {
        showCartMessage('Enter the card or e-wallet payment reference.', 'error');
        paymentReference.focus();
        return false;
    }

    if (!posShiftOpen) { showCartMessage('Open a Cashier Shift before checkout.', 'error'); return false; }
    if (getDiscountAmount() > 0 && discountReason.value.trim() === '') { showCartMessage('Enter a discount reason.', 'error'); discountReason.focus(); return false; }
    cartInput.value = JSON.stringify(payload);
    return true;
}

async function checkoutNow() {
    if (checkoutSubmitting || !checkoutAttempt.ready) return;
    if (!validateCheckout(false)) {
        return;
    }

    let quote;
    checkoutButton.disabled = true;
    try {
        quote = await checkoutQuote.review();
    } catch (error) {
        showCartMessage(error.message, 'error');
        return;
    } finally {
        checkoutButton.disabled = false;
    }
    const methodLabel = paymentMethod.options[paymentMethod.selectedIndex].text;
    const paymentLine = paymentMethod.value === 'cash'
        ? `<label>Cash received <input id="review-cash" type="number" min="0" step="0.01" value="${escapeHtml(cashReceived.value)}" oninput="updateReviewedPayment()"></label><p>Change: ₱<span id="review-change">0.00</span></p>`
        : `<p>Complete and verify the ${escapeHtml(methodLabel)} payment externally for ₱${money(quote.total)}.</p><label>Payment Reference <input id="review-reference" value="${escapeHtml(paymentReference.value)}" oninput="updateReviewedPayment()"></label><label><input type="checkbox" id="review-verified"> I verified this payment externally.</label>`;

    checkoutSummary.innerHTML = `
        ${quote.sale.items.map(item => `<p>${escapeHtml(item.product_name)} · ${item.quantity} × ₱${money(item.unit_price)} = ₱${money(item.subtotal)}</p>`).join('')}
        <p><strong>Eligible promotions:</strong> ${quote.eligible_promotions.map(p => escapeHtml(p.promotion_name)).join(', ') || 'None'}</p>
        <p><strong>Selected discount:</strong> ${escapeHtml(quote.discount.promotion_name || quote.discount.discount_reason || 'None')} · ₱${money(quote.discount.discount_amount)}</p>
        <p><strong>Gross:</strong> ₱${money(quote.sale.total)} · <strong>Total due:</strong> ₱${money(quote.total)}</p>
        <p><strong>Payment method:</strong> ${escapeHtml(methodLabel)}</p>
        ${paymentLine}
    `;
    checkoutModal.classList.add('open');
    updateReviewedPayment();
    checkoutModal.querySelector('.btn:last-child').focus();
}

function updateReviewedPayment() {
    const cash = document.getElementById('review-cash');
    const reference = document.getElementById('review-reference');
    if (cash) {
        cashReceived.value = cash.value;
        document.getElementById('review-change').textContent = money(Math.max(0, Number(cash.value) - checkoutQuote.reviewed.total));
    }
    if (reference) paymentReference.value = reference.value;
}

function closeCheckoutConfirm() {
    if (checkoutSubmitting) return;
    checkoutModal.classList.remove('open');
    checkoutConfirmed = false;
    checkoutButton.focus();
}

async function submitConfirmedCheckout() {
    if (checkoutSubmitting) return;
    if (!checkoutAttempt.ready) {
        showCartMessage('Recover the previous checkout before collecting another payment.', 'error');
        return;
    }
    if (!validateCheckout()) {
        closeCheckoutConfirm();
        return;
    }
    if (!checkoutQuote.isCurrent()) {
        showCartMessage('The cart or discount changed. Review the final quote again.', 'error');
        closeCheckoutConfirm();
        return;
    }
    if (paymentMethod.value !== 'cash' && !document.getElementById('review-verified')?.checked) {
        showCartMessage('Verify the external payment before recording it.', 'error');
        return;
    }
    checkoutSubmitting = true;
    checkoutForm.elements.payment_verified.value = paymentMethod.value === 'cash' || document.getElementById('review-verified')?.checked ? '1' : '0';
    checkoutConfirmed = true;
    confirmCheckoutButton.disabled = true;
    confirmCheckoutButton.innerHTML = '<i class="bi bi-hourglass-split" aria-hidden="true"></i>Processing...';
    try {
        await checkoutAttempt.save(cart);
        checkoutForm.submit();
    } catch (error) {
        checkoutSubmitting = false;
        checkoutConfirmed = false;
        confirmCheckoutButton.disabled = false;
        confirmCheckoutButton.textContent = 'Confirm payment';
        showCartMessage('Checkout could not be saved for recovery. Reload before collecting another payment. Tell your Administrator if this keeps happening.', 'error');
    }
}

async function apiHeldSale(action, payload = {}) {
    const heldSaleFallback = 'The held sale could not be completed. Check your connection and try again. Tell your Administrator if this keeps happening.';
    let response;
    try {
        response = await fetch(heldSalesApiUrl, {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({action,...payload})});
    } catch (networkError) {
        console.error('Held sale request failed:', networkError);
        throw new Error(heldSaleFallback);
    }
    let data;
    try {
        data = await response.json();
    } catch (parseError) {
        console.error('Held sale response could not be read:', parseError);
        throw new Error(heldSaleFallback);
    }
    if (!response.ok || !data.success) throw new Error(data.message || heldSaleFallback);
    return data;
}

async function loadHeldSales() {
    try {
        const response = await fetch(heldSalesApiUrl, {headers:{'Accept':'application/json'}});
        const data = await response.json();
        heldSales = data.success ? (data.held_sales || []) : [];
    } catch (error) { heldSales = []; }
    renderHeldSales();
}

async function holdCurrentSale() {
    if (Object.keys(cart).length === 0) { showCartMessage('Cart is empty. Add items before holding a sale.', 'error'); return; }
    if (!posShiftOpen) { showCartMessage('Open a Cashier Shift before holding a sale.', 'error'); return; }
    if (resumedHeldSaleId) { showCartMessage('This sale is already held. Complete checkout or discard it from Held sales before holding another sale.', 'error'); return; }
    try {
        const data = await apiHeldSale('hold', {cart});
        heldSales = data.held_sales || [];
        cart = {};
        resetPaymentState();
        renderCart(); renderHeldSales(); showCartMessage(`Sale ${data.reference_no} held on the server.`, 'success'); skuInput.focus();
    } catch (error) { showCartMessage(error.message, 'error'); }
}

async function resumeHeldSale(id) {
    if (Object.keys(cart).length > 0 && !await RetailMindUI.confirm({title:'Resume held sale',message:'Replace the current cart with this held sale?',confirmText:'Resume sale'})) return;
    try {
        const data = await apiHeldSale('resume', {id});
        cart = data.cart || {}; heldSales = data.held_sales || [];
        resetPaymentState();
        // Ticket #91: the cart is back on the till, so the next checkout is the
        // settlement of this held sale rather than an ordinary new sale.
        resumedHeldSaleId = Number(data.id || id) || 0;
        resumedHeldSaleInput.value = String(resumedHeldSaleId);
        renderCart(); renderHeldSales(); showCartMessage('Held sale resumed. Complete the checkout to finish it.', 'success'); skuInput.focus();
    } catch (error) { showCartMessage(error.message, 'error'); }
}

// Ticket #91: a held sale cannot be dropped from the list. It has to be either
// paid for — which completes it — or discarded with a reason, which is recorded.
// The note is only demanded for the one reason that genuinely needs words, and
// the service refuses an empty one there, so this is a courtesy rather than the
// rule: posting around it would simply be rejected.
function openDiscardModal(id) {
    discardingHeldSaleId = Number(id) || 0;
    discardReason.value = 'customer_cancelled';
    discardNote.value = '';
    discardError.textContent = '';
    discardError.className = 'cart-message error';
    discardModal.classList.add('open');
    setTimeout(() => discardReason.focus(), 50);
}

function closeDiscardModal() {
    discardModal.classList.remove('open');
    discardingHeldSaleId = 0;
    holdSaleButton.focus();
}

async function confirmDiscardHeldSale() {
    const reason = discardReason.value;
    const note = discardNote.value.trim();
    if (reason === 'other' && note === '') {
        discardError.textContent = 'Add a note explaining why this held sale is being discarded.';
        discardError.className = 'cart-message visible error';
        discardNote.focus();
        return;
    }
    if (discardingHeldSaleId === 0) { closeDiscardModal(); return; }
    try {
        const data = await apiHeldSale('discard', {id: discardingHeldSaleId, discard_reason: reason, discard_note: note});
        heldSales = data.held_sales || [];
        // Discarding the cart currently on the till leaves nothing to complete.
        if (resumedHeldSaleId === discardingHeldSaleId) {
            resumedHeldSaleId = 0;
            resumedHeldSaleInput.value = '';
            persistCart();
        }
        closeDiscardModal();
        renderHeldSales();
        showCartMessage('Held sale discarded. The reason is in the audit log.', 'success');
    } catch (error) {
        discardError.textContent = error.message;
        discardError.className = 'cart-message visible error';
    }
}

function renderHeldSales() {
    document.getElementById('held-count').textContent = heldSales.length;
    if (heldSales.length === 0) { holdList.innerHTML = '<div class="search-empty u-empty-min-72">No held sales.</div>'; return; }
    holdList.innerHTML = heldSales.map(held => {
        const id = Number(held.id);
        // A resumed cart is already on the till but is not finished: it still has
        // to be paid for, and it still holds this shift open. Saying so is the
        // difference between "held" and "done".
        const state = held.status === 'resumed' ? ' &middot; on the till' : '';
        return `<div class="hold-item"><div><strong>${escapeHtml(held.reference_no || held.created_at)}</strong><br><small>${Number(held.item_count || 0)} item(s) &middot; &#8369;${money(held.total_amount)}${state}</small></div><div class="pos-toolbar"><button type="button" class="btn btn-small" onclick="resumeHeldSale(${id})">Resume</button><button type="button" class="btn btn-small btn-secondary" onclick="openDiscardModal(${id})">Discard</button></div></div>`;
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
    resetPaymentState();
    persistCart();
    document.getElementById('void-reason-input').value = reason;
    document.getElementById('void-form').submit();
}

function persistCart() {
    try {
        sessionStorage.setItem('pos_cart', JSON.stringify({cart, heldSaleId: resumedHeldSaleId}));
    } catch (error) {}
}

function restoreState() {
    try {
        const storedCart = sessionStorage.getItem('pos_cart');
        if (storedCart) {
            const saved = JSON.parse(storedCart);
            // Older sessions stored the cart directly, without held-sale metadata.
            const savedCart = saved?.cart ?? saved;
            if (savedCart && typeof savedCart === 'object' && !Array.isArray(savedCart)) {
                cart = savedCart;
                const heldSaleId = Number(saved.heldSaleId);
                resumedHeldSaleId = Number.isSafeInteger(heldSaleId) && heldSaleId > 0 ? heldSaleId : 0;
            }
        }
    } catch (error) { cart = {}; resumedHeldSaleId = 0; }
    // Keep the cart and its identity together across reloads and failed checkout.
    // Checkout still validates ownership, unresolved status, and the owning shift.
    resumedHeldSaleInput.value = resumedHeldSaleId ? String(resumedHeldSaleId) : '';
    renderCart();
    loadHeldSales();
}

function onScanSuccess(decodedText) {
    if (scanCooldown) {
        return;
    }
    scanCooldown = true;
    setTimeout(() => { scanCooldown = false; }, 700);

    const code = String(decodedText).trim();
    const now = Date.now();
    if (code === lastScannedCode && now - lastScannedAt < 1200) {
        return;
    }
    lastScannedCode = code;
    lastScannedAt = now;
    skuInput.value = code;
    scannerResult.innerHTML = `<strong>Barcode detected</strong><span>${escapeHtml(code)}</span>`;
    if (navigator.vibrate) {
        navigator.vibrate(100);
    }
    addCodeFromInput();
}

function showCameraStartFailure(hint, detail) {
    startBtn.disabled = false;
    stopBtn.disabled = true;
    scannerResult.innerHTML = '<strong>Camera could not start</strong><span>' + escapeHtml(hint) + '</span>';
    if (detail !== undefined) {
        console.error('Camera start failed:', detail);
    }
    if (window.RetailMindUI && typeof window.RetailMindUI.toast === 'function') {
        const easy = 'The camera could not start. Check your camera permission, or enter the code manually to continue. Tell your Administrator if this keeps happening.';
        const tech = window.RetailMindUI.isDebug() && detail !== undefined ? '\n' + String(detail) : '';
        window.RetailMindUI.toast(easy + tech, 'error');
    }
}

function startScanner() {
    scannerArea.classList.add('open');
    if (!html5QrCode) {
        showCameraStartFailure('The scanner library could not be loaded. Enter the code manually instead.', 'Html5Qrcode library is unavailable');
        return;
    }
    const localHost = ['localhost', '127.0.0.1', '::1'].includes(location.hostname);
    if (!window.isSecureContext && !localHost) {
        showCameraStartFailure('Open this page over HTTPS, or enter the code manually.', 'Camera requires a secure context (HTTPS or localhost)');
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showCameraStartFailure('This browser does not allow camera scanning. Enter the code manually.', 'navigator.mediaDevices.getUserMedia is unavailable');
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
            showCameraStartFailure('Check camera permission, or type the code manually.', error);
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
    if (!cashierClock) {
        return;
    }
    const now = new Date();
    cashierClock.innerHTML = `<i class="bi bi-clock" aria-hidden="true"></i>${now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}

startBtn.addEventListener('click', startScanner);
stopBtn.addEventListener('click', stopScanner);
addCodeBtn.addEventListener('click', addCodeFromInput);
paymentMethod.addEventListener('change', updatePaymentFields);
cashReceived.addEventListener('input', updatePaymentFields);
discountType.addEventListener('change', updatePaymentFields);
discountValue.addEventListener('input', updatePaymentFields);
cashQuick.addEventListener('click', event => {
    const button = event.target.closest('[data-tender]');
    if (button) {
        setQuickTender(button.dataset.tender);
    }
});
quickProductSearch.addEventListener('input', renderQuickProducts);
quickCategoryFilters.addEventListener('click', event => {
    const button = event.target.closest('[data-quick-category]');
    if (!button) return;
    quickCategoryFilters.querySelectorAll('[data-quick-category]').forEach(item => item.classList.toggle('active', item === button));
    renderQuickProducts();
});
quickProductGrid.addEventListener('click', event => {
    const addButton = event.target.closest('[data-quick-add]');
    if (addButton && !addButton.disabled) {
        addQuickProduct(addButton.dataset.quickAdd);
        return;
    }
    const decreaseButton = event.target.closest('[data-quick-decrease]');
    if (decreaseButton) {
        changeCartQty(Number(decreaseButton.dataset.quickDecrease), -1);
    }
});
quickGridToggle.addEventListener('click', () => {
    const compact = quickProductGrid.classList.toggle('compact');
    quickGridToggle.setAttribute('aria-pressed', compact ? 'true' : 'false');
});
document.querySelectorAll('[data-pos-focus]').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelectorAll('[data-pos-focus]').forEach(item => item.classList.toggle('active', item === button));
        const target = button.dataset.posFocus;
        if (target === 'scan') {
            skuInput.focus();
            skuInput.select();
        } else if (target === 'find') {
            window.location.href = findProductUrl;
        } else if (target === 'cash') {
            paymentMethod.value = 'cash';
            updatePaymentFields();
            cashReceived.focus();
        } else if (target === 'discount') {
            document.getElementById('discount-panel').open = true;
            discountType.focus();
        } else if (target === 'hold') {
            holdCurrentSale();
        }
    });
});
function setFullscreenMode(enabled) {
    document.body.classList.toggle('pos-fullscreen', enabled);
    localStorage.setItem('retailmind_pos_fullscreen', enabled ? '1' : '0');
    if (fullscreenToggle) {
        fullscreenToggle.querySelector('i').className = `bi ${enabled ? 'bi-fullscreen-exit' : 'bi-arrows-fullscreen'}`;
        fullscreenToggle.querySelector('span').textContent = enabled ? 'Exit full screen' : 'Full screen';
    }
    setTimeout(() => skuInput.focus(), 50);
}
if (fullscreenToggle) {
    fullscreenToggle.addEventListener('click', () => setFullscreenMode(!document.body.classList.contains('pos-fullscreen')));
    setFullscreenMode(localStorage.getItem('retailmind_pos_fullscreen') === '1');
}

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

[checkoutModal, voidModal, discardModal].forEach(modal => {
    modal.addEventListener('mousedown', event => {
        if (event.target === modal) {
            if (modal === checkoutModal) { closeCheckoutConfirm(); }
            else if (modal === voidModal) { closeVoidModal(); }
            else { closeDiscardModal(); }
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
        window.location.href = findProductUrl;
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

renderQuickProducts();
restoreState();
if (cashierClock) {
    updateClock();
    setInterval(updateClock, 30000);
}
const checkoutAttempt = new CheckoutAttempt(checkoutForm, <?= json_encode((string)$cashierId) ?>, {
    recoverUrl: <?= json_encode(app_url('components/cashier/pos.php')) ?>,
    restore(savedCart) { cart = savedCart; resumedHeldSaleId = Number(resumedHeldSaleInput.value) || null; renderCart(); },
    message(text) { showCartMessage(text, 'error'); }
});
const checkoutQuote = new CheckoutQuote(checkoutForm);
checkoutAttempt.recover();
setTimeout(() => skuInput.focus(), 100);
</script>
        <?php // Ticket #90: the point of sale and its scripts are withheld entirely while the Register is locked, so a locked till has no cart, no scanner, and no checkout to drive. ?>
        <?php endif; ?>
</body>
</html>
