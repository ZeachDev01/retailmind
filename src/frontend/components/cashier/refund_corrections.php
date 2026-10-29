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
            if ($type !== 'cash_drawer' && (!$productId || !$quantity)) throw new DomainException('Choose the Product and a nonzero signed stock quantity.');
            $service->createReview($actorId, $role, (int)($_POST['refund_id'] ?? 0), $type,
                (int)($_POST['cashier_id'] ?? 0) ?: null, (int)($_POST['inventory_manager_id'] ?? 0) ?: null,
                (string)($_POST['explanation'] ?? ''), ['cash_amount' => $cashAmount ?: 0, 'product_id' => $productId ?: 0, 'quantity' => $quantity ?: 0]);
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
$staff = $role === 'admin' ? $pdo->query("SELECT u.user_id, u.full_name, r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.status='active' UNION SELECT u.user_id,u.full_name,r.role_name FROM users u JOIN user_roles ur ON ur.user_id=u.user_id JOIN roles r ON r.role_id=ur.role_id WHERE u.status='active'")->fetchAll(PDO::FETCH_ASSOC) : [];
$products = $role === 'admin' ? $pdo->query('SELECT product_id, product_name FROM products ORDER BY product_name')->fetchAll(PDO::FETCH_ASSOC) : [];
function correction_e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Refund corrections</title>
    <link rel="stylesheet" href="<?= correction_e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= correction_e(app_url('assets/css/cashier.css')) ?>">
    <link rel="stylesheet" href="<?= correction_e(app_url('assets/css/refund-corrections.css')) ?>">
</head>
<body class="cashier-page refund-corrections-page"><div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<main class="main-content">
    <header class="correction-heading"><h1>Refund corrections</h1></header>
    <?php if ($error !== ''): ?><p class="alert alert-danger" role="alert"><?= correction_e($error) ?></p><?php endif; ?>
    <?php if ($role === 'admin' && !$remote): ?>
    <section class="pos-panel" aria-labelledby="approve-correction-title">
        <h2 id="approve-correction-title">Approve a correction</h2>
        <form method="post" class="correction-form">
            <input type="hidden" name="csrf_token" value="<?= correction_e(generate_csrf_token()) ?>"><input type="hidden" name="action" value="approve">
            <div class="correction-fields">
                <label>Refund ID<input class="pos-input" name="refund_id" type="number" min="1" required></label>
                <label>Correction type<select class="pos-input" name="correction_type"><option value="cash_drawer">Cash drawer</option><option value="stock_only">Stock only</option><option value="combined">Cash drawer and stock</option></select></label>
            </div>
            <fieldset><legend>Cash drawer</legend><div class="correction-fields">
                <label>Cashier<select class="pos-input" name="cashier_id"><option value="">No cash task</option><?php foreach ($staff as $person): if ($person['role_name'] !== 'cashier') continue; ?><option value="<?= (int)$person['user_id'] ?>"><?= correction_e($person['full_name']) ?></option><?php endforeach; ?></select></label>
                <label>Amount (PHP; + add / − remove)<input class="pos-input" name="cash_amount" type="number" step="0.01"></label>
            </div></fieldset>
            <fieldset><legend>Stock</legend><div class="correction-fields correction-stock-fields">
                <label>Inventory Manager<select class="pos-input" name="inventory_manager_id"><option value="">No stock task</option><?php foreach ($staff as $person): if ($person['role_name'] !== 'inventory_manager') continue; ?><option value="<?= (int)$person['user_id'] ?>"><?= correction_e($person['full_name']) ?></option><?php endforeach; ?></select></label>
                <label>Product<select class="pos-input" name="product_id"><option value="">No stock task</option><?php foreach ($products as $product): ?><option value="<?= (int)$product['product_id'] ?>"><?= correction_e($product['product_name']) ?></option><?php endforeach; ?></select></label>
                <label>Units (+ add / − remove)<input class="pos-input" name="quantity" type="number" step="1"></label>
            </div></fieldset>
            <label>Reason and approved changes<textarea class="pos-input" name="explanation" required minlength="8" maxlength="2000" rows="3"></textarea></label>
            <div class="correction-actions">
                <label class="correction-confirm"><input type="checkbox" required> I approve these exact changes and assigned staff.</label>
                <button class="btn btn-primary" type="submit">Approve correction</button>
            </div>
        </form>
    </section>
    <?php endif; ?>
    <section class="pos-panel" aria-labelledby="correction-tasks-title"><h2 id="correction-tasks-title"><?= $role === 'admin' ? 'Correction history' : 'Your pending corrections' ?></h2>
    <?php if (!$reviews): ?><p class="correction-empty">No <?= $role === 'admin' ? '' : 'pending ' ?>corrections.</p><?php endif; ?>
    <?php foreach ($reviews as $review): $proposal = json_decode((string)$review['proposed_correction'], true) ?: []; ?>
        <details class="correction-review"><summary><span>Review #<?= (int)$review['review_id'] ?> · Refund #<?= (int)$review['refund_id'] ?></span><span class="correction-status"><?= correction_e($review['status']) ?></span></summary>
            <div class="correction-review-body">
            <p><?= correction_e($review['explanation']) ?></p>
            <dl class="correction-record">
                <div><dt>Approved</dt><dd>User #<?= (int)$review['reviewed_by'] ?> · <?= correction_e($review['reviewed_at']) ?></dd></div>
                <div><dt>Cash drawer</dt><dd><?= correction_e(str_replace('_', ' ', $review['cash_status'])) ?> · PHP <?= correction_e($proposal['cash_amount'] ?? 'Not recorded') ?> · Cashier #<?= (int)$review['responsible_cashier_id'] ?></dd></div>
                <div><dt>Stock</dt><dd><?= correction_e(str_replace('_', ' ', $review['stock_status'])) ?> · Product #<?= (int)($proposal['product_id'] ?? 0) ?> · <?= correction_e($proposal['quantity'] ?? 'Not recorded') ?> units · Inventory Manager #<?= (int)$review['responsible_inventory_manager_id'] ?></dd></div>
            </dl>
            <?php if (!$remote && in_array($role, ['cashier', 'inventory_manager'], true)): ?>
            <form method="post" class="correction-form"><input type="hidden" name="csrf_token" value="<?= correction_e(generate_csrf_token()) ?>"><input type="hidden" name="review_id" value="<?= (int)$review['review_id'] ?>"><input type="hidden" name="action" value="<?= $role === 'cashier' ? 'cash' : 'stock' ?>">
                <label>Evidence and reason<textarea class="pos-input" name="note" required minlength="8" maxlength="255" rows="2"></textarea></label>
                <div class="correction-actions">
                    <label class="correction-confirm"><input type="checkbox" required> I confirm the exact approved <?= $role === 'cashier' ? 'money movement occurred in my open drawer' : 'stock change was physically checked' ?>.</label>
                    <button class="btn btn-primary" type="submit">Record <?= $role === 'cashier' ? 'drawer' : 'stock' ?> correction</button>
                </div>
            </form>
            <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>
    </section>
</main></div></body></html>
