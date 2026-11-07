<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/ProductService.php';

use App\Services\RefundCorrectionReviewService;

require_role(['admin', 'cashier', 'inventory_manager']);
$role = (string)current_role();
$actorId = (int)$_SESSION['user_id'];
$service = new RefundCorrectionReviewService($pdo);
$error = '';
$remote = is_remote_request();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    try {
        if ($remote) throw new DomainException('Refund corrections are unavailable during remote monitoring.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'approve') {
            $cashAmount = filter_var($_POST['cash_amount'] ?? '', FILTER_VALIDATE_FLOAT);
            $productId = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT);
            $type = (string)($_POST['correction_type'] ?? '');
            if ($type !== 'stock_only' && ($cashAmount === false || !is_finite($cashAmount) || $cashAmount == 0 || round($cashAmount, 2) !== $cashAmount)) {
                throw new DomainException('Enter the signed cash correction in pesos and centavos.');
            }
            if ($type !== 'cash_drawer' && (!$productId || !$quantity)) {
                throw new DomainException('Choose the Product and a nonzero signed stock quantity.');
            }
            $service->createReview(
                $actorId,
                $role,
                (int)($_POST['refund_id'] ?? 0),
                $type,
                (int)($_POST['cashier_id'] ?? 0) ?: null,
                (int)($_POST['inventory_manager_id'] ?? 0) ?: null,
                (string)($_POST['explanation'] ?? ''),
                ['cash_amount' => $cashAmount ?: 0, 'product_id' => $productId ?: 0, 'quantity' => $quantity ?: 0]
            );
        } else {
            $id = (int)($_POST['review_id'] ?? 0);
            $review = $service->getReview($id);
            if (!$review) throw new DomainException('Refund correction review not found.');
            $proposal = json_decode((string)$review['proposed_correction'], true) ?: [];
            if ($action === 'cash' && isset($proposal['cash_amount'])) {
                $service->executeCashCorrection($id, $actorId, $role, (float)$proposal['cash_amount'], (string)($_POST['note'] ?? ''));
            } elseif ($action === 'stock' && isset($proposal['product_id'], $proposal['quantity'])) {
                $service->executeStockCorrection($id, $actorId, $role, (int)$proposal['product_id'], (int)$proposal['quantity'], (string)($_POST['note'] ?? ''));
            } else {
                throw new DomainException('This review needs a complete approved correction before execution.');
            }
        }
        $_SESSION['_flash_success'] = 'Refund correction recorded. Original sale and refund remain unchanged.';
        header('Location: ' . app_url('components/cashier/refund_corrections.php'));
        exit;
    } catch (DomainException | InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The correction could not be saved. Refresh the review before trying again.');
    }
}

$reviews = match ($role) {
    'admin' => $service->listReviews(),
    'cashier' => $service->getPendingReviewsForCashier($actorId),
    'inventory_manager' => $service->getPendingReviewsForInventoryManager($actorId),
};

$staff = [];
if ($role === 'admin') {
    try {
        $staff = $pdo->query("SELECT u.user_id, u.display_name, r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.status='active' UNION SELECT u.user_id,u.display_name,r.role_name FROM users u JOIN user_roles ur ON ur.user_id=u.user_id JOIN roles r ON r.role_id=ur.role_id WHERE u.status='active'")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $staff = [];
    }
}

$products = [];
try {
    $products = $pdo->query('SELECT product_id, product_name FROM products ORDER BY product_name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $products = [];
}

$productMap = [];
foreach ($products as $p) {
    $productMap[(int)$p['product_id']] = (string)$p['product_name'];
}

$userMap = [];
try {
    $uRows = $pdo->query('SELECT user_id, display_name FROM users')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($uRows as $u) {
        $userMap[(int)$u['user_id']] = (string)$u['display_name'];
    }
} catch (Throwable) {}

function correction_e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund corrections</title>
    <link rel="stylesheet" href="<?= correction_e(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= correction_e(theme_url('assets/css/cashier.css')) ?>">
    <link rel="stylesheet" href="<?= correction_e(theme_url('assets/css/refund-corrections.css')) ?>">
</head>
<body class="cashier-page refund-corrections-page">
<a class="skip-link" href="#refund-corrections-main">Skip to main content</a>
<div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<main class="main-content" id="refund-corrections-main">
    <header class="correction-header">
        <div class="correction-title-row">
            <h1 class="correction-title">Refund corrections</h1>
            <div class="correction-badges">
                <span class="role-pill role-<?= correction_e($role) ?>">
                    <span class="role-dot"></span>
                    <?= match($role) {
                        'admin' => 'Administrator',
                        'cashier' => 'Cashier',
                        'inventory_manager' => 'Inventory Manager',
                        default => correction_e(ucwords(str_replace('_', ' ', $role)))
                    } ?>
                </span>
                <?php if ($remote): ?>
                    <span class="status-chip chip-warning">Remote (Read-only)</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="correction-header-stats">
            <div class="stat-card">
                <span class="stat-label"><?= $role === 'admin' ? 'Total' : 'Pending' ?></span>
                <span class="stat-value"><?= count($reviews) ?></span>
            </div>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <div class="correction-alert correction-alert-danger" role="alert">
            <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
            </svg>
            <div><?= correction_e($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($role === 'admin' && !$remote): ?>
    <section class="pos-panel" aria-labelledby="approve-correction-title">
        <div class="panel-header-block">
            <h2 id="approve-correction-title">Approve a correction</h2>
        </div>

        <form method="post" class="correction-form" id="create-correction-form">
            <input type="hidden" name="csrf_token" value="<?= correction_e(generate_csrf_token()) ?>">
            <input type="hidden" name="action" value="approve">

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="refund_id">Refund ID</label>
                    <div class="input-with-affix">
                        <span class="input-affix">#</span>
                        <input id="refund_id" class="pos-input" name="refund_id" type="number" min="1" placeholder="1042" autocomplete="off" inputmode="numeric" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="correction_type">Correction type</label>
                    <select id="correction_type" class="pos-input" name="correction_type">
                        <option value="cash_drawer">Cash drawer</option>
                        <option value="stock_only">Stock only</option>
                        <option value="combined" selected>Cash drawer and stock</option>
                    </select>
                </div>
            </div>

            <fieldset id="fieldset-cash" class="correction-fieldset">
                <legend class="fieldset-legend">Cash drawer</legend>
                <div class="fieldset-grid-cash">
                    <div class="form-group">
                        <label for="cashier_id">Cashier</label>
                        <select id="cashier_id" class="pos-input" name="cashier_id">
                            <option value="">No cash task</option>
                            <?php foreach ($staff as $person): if ($person['role_name'] !== 'cashier') continue; ?>
                                <option value="<?= (int)$person['user_id'] ?>"><?= correction_e($person['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="cash_amount">Amount (PHP)</label>
                        <div class="input-with-affix">
                            <span class="input-affix">₱</span>
                            <input id="cash_amount" class="pos-input" name="cash_amount" type="number" step="0.01" placeholder="0.00" autocomplete="off" inputmode="decimal">
                        </div>
                    </div>
                </div>
            </fieldset>

            <fieldset id="fieldset-stock" class="correction-fieldset">
                <legend class="fieldset-legend">Stock</legend>
                <div class="fieldset-grid-stock">
                    <div class="form-group">
                        <label for="inventory_manager_id">Inventory Manager</label>
                        <select id="inventory_manager_id" class="pos-input" name="inventory_manager_id">
                            <option value="">No stock task</option>
                            <?php foreach ($staff as $person): if ($person['role_name'] !== 'inventory_manager') continue; ?>
                                <option value="<?= (int)$person['user_id'] ?>"><?= correction_e($person['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="product_id">Product</label>
                        <select id="product_id" class="pos-input" name="product_id">
                            <option value="">No stock task</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= (int)$product['product_id'] ?>"><?= correction_e($product['product_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="quantity">Units</label>
                        <input id="quantity" class="pos-input" name="quantity" type="number" step="1" placeholder="0" autocomplete="off" inputmode="numeric">
                    </div>
                </div>
            </fieldset>

            <div class="form-group">
                <label for="explanation">Reason and approved changes</label>
                <textarea id="explanation" class="pos-input" name="explanation" required minlength="8" maxlength="2000" rows="3"></textarea>
            </div>

            <label class="correction-confirm-box" for="approve-confirm-check">
                <input id="approve-confirm-check" type="checkbox" required>
                <span class="confirmation-label">I approve these exact changes and assigned staff.</span>
            </label>

            <div class="form-actions-row">
                <button class="btn-primary-action" type="submit">Approve correction</button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <section class="pos-panel correction-panel" aria-labelledby="correction-tasks-title">
        <div class="panel-header-block">
            <h2 id="correction-tasks-title"><?= $role === 'admin' ? 'Correction history' : 'Pending corrections' ?></h2>
            <span class="stat-card" style="padding: 0.35rem 0.75rem;">
                <span class="stat-label">Records</span>
                <span class="stat-value" style="font-size: 1.15rem;"><?= count($reviews) ?></span>
            </span>
        </div>

        <?php if (!$reviews): ?>
            <!-- THE DISTILLED EMPTY STATE -->
            <div class="correction-empty-state" id="correction-empty-state">
                <div class="empty-graphic-wrap">
                    <svg class="empty-illustration" viewBox="0 0 160 140" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <ellipse cx="80" cy="118" rx="54" ry="12" class="empty-shadow" />
                        <circle cx="80" cy="65" r="50" class="empty-ring" stroke-dasharray="4 4" />
                        <circle cx="80" cy="65" r="40" class="empty-ring-inner" />
                        
                        <path d="M28 38 L32 42 L28 46 L24 42 Z" class="empty-sparkle" />
                        <path d="M132 34 L135 37 L132 40 L129 37 Z" class="empty-sparkle" />
                        <circle cx="26" cy="85" r="3" class="empty-dot" />
                        <circle cx="136" cy="75" r="2.5" class="empty-dot" />
                        
                        <g class="empty-card-group">
                            <rect x="48" y="24" width="64" height="82" rx="8" class="empty-sheet" />
                            <rect x="56" y="36" width="34" height="5" rx="2.5" class="empty-line-strong" />
                            <rect x="56" y="47" width="48" height="3" rx="1.5" class="empty-line-light" />
                            <rect x="56" y="55" width="40" height="3" rx="1.5" class="empty-line-light" />
                            <rect x="56" y="63" width="48" height="3" rx="1.5" class="empty-line-light" />
                            <line x1="56" y1="73" x2="104" y2="73" class="empty-divider" stroke-dasharray="3 2" />
                            <rect x="56" y="80" width="22" height="4" rx="2" class="empty-line-strong" />
                            <rect x="88" y="80" width="16" height="4" rx="2" class="empty-line-currency" />
                        </g>
                        
                        <g class="empty-shield-group">
                            <circle cx="106" cy="94" r="17" class="empty-shield-bg" />
                            <circle cx="106" cy="94" r="14" class="empty-shield-ring" />
                            <path d="M100 94.5 L104 98.5 L113 89.5" class="empty-check" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.6" />
                        </g>
                    </svg>
                </div>
                <div class="empty-content">
                    <h3 class="empty-title">
                        <?= match($role) {
                            'cashier' => 'No pending drawer corrections',
                            'inventory_manager' => 'No pending stock corrections',
                            default => 'No corrections recorded'
                        } ?>
                    </h3>
                    <p class="empty-text">
                        <?= match($role) {
                            'cashier' => 'All drawer tasks for your register are complete.',
                            'inventory_manager' => 'All physical inventory tasks are complete.',
                            default => 'No post-refund corrections have been recorded.'
                        } ?>
                    </p>
                    <?php if ($role === 'admin' && !$remote): ?>
                        <div class="empty-footer">
                            <a href="#approve-correction-title" class="btn btn-secondary btn-sm empty-action-btn">Approve a correction</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="correction-table-wrap">
                <table class="correction-table">
                    <thead>
                        <tr>
                            <th>Review</th>
                            <th>Refund</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Tasks</th>
                            <th>Reviewed By</th>
                            <th>Reviewed At</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reviews as $review): 
                            $proposal = json_decode((string)$review['proposed_correction'], true) ?: [];
                            $isOutstanding = ($review['status'] === RefundCorrectionReviewService::STATUS_REVIEWED_OUTSTANDING);
                            $isCorrected = ($review['status'] === RefundCorrectionReviewService::STATUS_CORRECTED);
                            $badgeClass = $isCorrected ? 'badge-completed' : ($isOutstanding ? 'badge-outstanding' : 'badge-other');
                            $typeLabel = match($review['correction_type']) {
                                'cash_drawer' => 'Cash drawer',
                                'stock_only' => 'Stock only',
                                'combined' => 'Combined',
                                default => ucfirst(str_replace('_', ' ', $review['correction_type']))
                            };
                            $reviewerName = $userMap[(int)$review['reviewed_by']] ?? ('User #' . (int)$review['reviewed_by']);
                        ?>
                            <tr>
                                <td><span class="table-id-tag">#<?= (int)$review['review_id'] ?></span></td>
                                <td>#<?= (int)$review['refund_id'] ?></td>
                                <td><span class="type-pill"><?= correction_e($typeLabel) ?></span></td>
                                <td>
                                    <span class="status-badge-modern <?= $badgeClass ?>">
                                        <span class="badge-dot"></span>
                                        <?= correction_e($review['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="task-summary-cell">
                                        <?php if ($review['cash_status'] !== 'not_required'): ?>
                                            <span class="task-mini-item">
                                                <span class="task-dot dot-<?= correction_e($review['cash_status']) ?>"></span>
                                                Cash: <strong><?= (float)($proposal['cash_amount'] ?? 0) >= 0 ? '+₱' : '-₱' ?><?= number_format(abs((float)($proposal['cash_amount'] ?? 0)), 2) ?></strong>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($review['stock_status'] !== 'not_required'): ?>
                                            <span class="task-mini-item">
                                                <span class="task-dot dot-<?= correction_e($review['stock_status']) ?>"></span>
                                                Stock: <strong><?= (int)($proposal['quantity'] ?? 0) >= 0 ? '+' : '' ?><?= (int)($proposal['quantity'] ?? 0) ?></strong>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= correction_e($reviewerName) ?></td>
                                <td><span class="table-time"><?= correction_e($review['reviewed_at']) ?></span></td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn-table-action correction-open-drawer" data-review='<?= htmlspecialchars(json_encode($review), ENT_QUOTES, 'UTF-8') ?>'>
                                        <?= (!$remote && in_array($role, ['cashier', 'inventory_manager'], true)) ? 'Review' : 'Details' ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <dialog id="correction-details-drawer" class="correction-details-drawer" aria-labelledby="correction-drawer-title">
            <div class="drawer-header">
                <h2 id="correction-drawer-title">Review Details</h2>
                <button type="button" class="drawer-close" id="correction-drawer-close" aria-label="Close">&times;</button>
            </div>
            
            <div class="drawer-body">
                <div class="drawer-overview-card" id="drawer-overview">
                    <div class="drawer-meta-item">
                        <span class="meta-kicker">Status</span>
                        <span class="meta-val" id="drawer-status-val">—</span>
                    </div>
                    <div class="drawer-meta-item">
                        <span class="meta-kicker">Approved by</span>
                        <span class="meta-val" id="drawer-reviewer-val">—</span>
                    </div>
                </div>

                <div class="drawer-quote-card">
                    <h4>Explanation</h4>
                    <p id="correction-drawer-explanation">—</p>
                </div>

                <!-- Cash Task Card -->
                <div class="drawer-task-card" id="drawer-cash-card">
                    <div class="drawer-task-card-header">
                        <span>Cash drawer</span>
                        <span id="drawer-cash-status-badge" class="status-badge-modern">—</span>
                    </div>
                    <div class="drawer-task-card-body">
                        <div class="task-prop-row">
                            <dt>Cashier</dt>
                            <dd id="drawer-cashier-val">—</dd>
                        </div>
                        <div class="task-prop-row">
                            <dt>Amount</dt>
                            <dd id="drawer-cash-amount-val">—</dd>
                        </div>
                    </div>
                </div>

                <!-- Stock Task Card -->
                <div class="drawer-task-card" id="drawer-stock-card">
                    <div class="drawer-task-card-header">
                        <span>Stock</span>
                        <span id="drawer-stock-status-badge" class="status-badge-modern">—</span>
                    </div>
                    <div class="drawer-task-card-body">
                        <div class="task-prop-row">
                            <dt>Inventory Manager</dt>
                            <dd id="drawer-inv-val">—</dd>
                        </div>
                        <div class="task-prop-row">
                            <dt>Product</dt>
                            <dd id="drawer-product-val">—</dd>
                        </div>
                        <div class="task-prop-row">
                            <dt>Quantity</dt>
                            <dd id="drawer-quantity-val">—</dd>
                        </div>
                    </div>
                </div>

                <?php if (!$remote && in_array($role, ['cashier', 'inventory_manager'], true)): ?>
                <div class="drawer-action-card" id="drawer-action-section">
                    <h3>Execute task</h3>
                    <form method="post" class="correction-form" id="correction-drawer-form">
                        <input type="hidden" name="csrf_token" value="<?= correction_e(generate_csrf_token()) ?>">
                        <input type="hidden" name="review_id" id="correction-drawer-review-id" value="">
                        <input type="hidden" name="action" value="<?= $role === 'cashier' ? 'cash' : 'stock' ?>">
                        
                        <div class="form-group">
                            <label for="drawer-task-note">Evidence and reason</label>
                            <textarea id="drawer-task-note" class="pos-input" name="note" required minlength="8" maxlength="255" rows="2"></textarea>
                        </div>

                        <label class="correction-confirm-box" for="record-task-check">
                            <input id="record-task-check" type="checkbox" required>
                            <span class="confirmation-label">I confirm the exact approved <?= $role === 'cashier' ? 'money movement occurred in my open drawer' : 'stock change was physically checked' ?>.</span>
                        </label>

                        <button class="btn-primary-action" type="submit" style="width: 100%;">
                            Record <?= $role === 'cashier' ? 'drawer' : 'stock' ?> correction
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <div class="drawer-footer">
                <button type="button" class="btn btn-secondary" id="correction-drawer-dismiss">Close</button>
            </div>
        </dialog>
    </section>
</main>
</div>

<script>
window.refundContext = {
    users: <?= json_encode($userMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    products: <?= json_encode($productMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    currentRole: <?= json_encode($role) ?>
};

(() => {
    // Dynamic fieldset switcher
    const typeSelect = document.getElementById('correction_type');
    const cashFieldset = document.getElementById('fieldset-cash');
    const stockFieldset = document.getElementById('fieldset-stock');

    if (typeSelect && cashFieldset && stockFieldset) {
        const updateFieldsets = () => {
            const val = typeSelect.value;
            if (val === 'cash_drawer') {
                cashFieldset.classList.remove('fieldset-muted');
                stockFieldset.classList.add('fieldset-muted');
                stockFieldset.querySelectorAll('input, select').forEach(el => el.disabled = true);
                cashFieldset.querySelectorAll('input, select').forEach(el => el.disabled = false);
            } else if (val === 'stock_only') {
                cashFieldset.classList.add('fieldset-muted');
                stockFieldset.classList.remove('fieldset-muted');
                cashFieldset.querySelectorAll('input, select').forEach(el => el.disabled = true);
                stockFieldset.querySelectorAll('input, select').forEach(el => el.disabled = false);
            } else {
                cashFieldset.classList.remove('fieldset-muted');
                stockFieldset.classList.remove('fieldset-muted');
                cashFieldset.querySelectorAll('input, select').forEach(el => el.disabled = false);
                stockFieldset.querySelectorAll('input, select').forEach(el => el.disabled = false);
            }
        };
        typeSelect.addEventListener('change', updateFieldsets);
        updateFieldsets();
    }

    // Drawer Modal Controller
    const drawer = document.getElementById('correction-details-drawer');
    if (!drawer) return;

    const titleEl = document.getElementById('correction-drawer-title');
    const statusValEl = document.getElementById('drawer-status-val');
    const reviewerValEl = document.getElementById('drawer-reviewer-val');
    const expEl = document.getElementById('correction-drawer-explanation');
    const reviewIdInput = document.getElementById('correction-drawer-review-id');
    const actionSection = document.getElementById('drawer-action-section');

    const cashCard = document.getElementById('drawer-cash-card');
    const cashStatusBadge = document.getElementById('drawer-cash-status-badge');
    const cashierValEl = document.getElementById('drawer-cashier-val');
    const cashAmountValEl = document.getElementById('drawer-cash-amount-val');

    const stockCard = document.getElementById('drawer-stock-card');
    const stockStatusBadge = document.getElementById('drawer-stock-status-badge');
    const invValEl = document.getElementById('drawer-inv-val');
    const productValEl = document.getElementById('drawer-product-val');
    const quantityValEl = document.getElementById('drawer-quantity-val');

    const closeBtn = document.getElementById('correction-drawer-close');
    const dismissBtn = document.getElementById('correction-drawer-dismiss');

    const formatCurrency = amount => {
        const num = parseFloat(amount) || 0;
        const sign = num >= 0 ? '+' : '−';
        const formatted = Math.abs(num).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return { text: `${sign}₱${formatted}`, isPositive: num >= 0 };
    };

    const openDrawer = review => {
        if (!review) return;
        const users = window.refundContext?.users || {};
        const products = window.refundContext?.products || {};

        if (titleEl) titleEl.textContent = `Review #${review.review_id} · Refund #${review.refund_id}`;
        if (reviewIdInput) reviewIdInput.value = review.review_id;
        if (expEl) expEl.textContent = review.explanation || '';

        if (statusValEl) statusValEl.textContent = review.status || 'Unknown';
        if (reviewerValEl) {
            const reviewerName = users[review.reviewed_by] || `User #${review.reviewed_by}`;
            reviewerValEl.textContent = `${reviewerName} · ${review.reviewed_at || ''}`;
        }

        const proposal = (typeof review.proposed_correction === 'string'
            ? JSON.parse(review.proposed_correction || '{}')
            : review.proposed_correction) || {};

        if (cashCard) {
            if (review.cash_status === 'not_required') {
                cashCard.style.display = 'none';
            } else {
                cashCard.style.display = '';
                if (cashStatusBadge) {
                    cashStatusBadge.textContent = review.cash_status || '';
                    cashStatusBadge.className = `status-badge-modern ${review.cash_status === 'completed' ? 'badge-completed' : 'badge-outstanding'}`;
                }
                if (cashierValEl) {
                    cashierValEl.textContent = users[review.responsible_cashier_id] || `Cashier #${review.responsible_cashier_id}`;
                }
                if (cashAmountValEl) {
                    const curr = formatCurrency(proposal.cash_amount);
                    cashAmountValEl.textContent = curr.text;
                    cashAmountValEl.className = curr.isPositive ? 'amount-positive' : 'amount-negative';
                }
            }
        }

        if (stockCard) {
            if (review.stock_status === 'not_required') {
                stockCard.style.display = 'none';
            } else {
                stockCard.style.display = '';
                if (stockStatusBadge) {
                    stockStatusBadge.textContent = review.stock_status || '';
                    stockStatusBadge.className = `status-badge-modern ${review.stock_status === 'completed' ? 'badge-completed' : 'badge-outstanding'}`;
                }
                if (invValEl) {
                    invValEl.textContent = users[review.responsible_inventory_manager_id] || `Manager #${review.responsible_inventory_manager_id}`;
                }
                if (productValEl) {
                    const pName = products[proposal.product_id] || `Product #${proposal.product_id}`;
                    productValEl.textContent = `${pName} (#${proposal.product_id})`;
                }
                if (quantityValEl) {
                    const q = parseInt(proposal.quantity, 10) || 0;
                    quantityValEl.textContent = `${q > 0 ? '+' : ''}${q}`;
                    quantityValEl.className = q >= 0 ? 'amount-positive' : 'amount-negative';
                }
            }
        }

        if (actionSection) {
            const role = window.refundContext?.currentRole;
            const needsAction = (role === 'cashier' && review.cash_status === 'pending') ||
                                (role === 'inventory_manager' && review.stock_status === 'pending');
            actionSection.style.display = needsAction ? '' : 'none';
        }

        drawer.showModal();
    };

    document.querySelectorAll('.correction-open-drawer').forEach(btn => {
        btn.addEventListener('click', () => {
            const raw = btn.getAttribute('data-review');
            if (raw) {
                try { openDrawer(JSON.parse(raw)); } catch (_) {}
            }
        });
    });

    const closeDrawer = () => drawer.close();
    closeBtn?.addEventListener('click', closeDrawer);
    dismissBtn?.addEventListener('click', closeDrawer);
    drawer.addEventListener('click', e => {
        if (e.target === drawer) closeDrawer();
    });
})();
</script>
</body>
</html>
