<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\OperationalDataResetService;

require_capability(RoleCapabilityPolicy::RESET_OPERATIONAL_DATA);
$service = new OperationalDataResetService($pdo, role_capability_policy(), env('ML_MODEL_DIRECTORY'));
$message = $_SESSION['reset_data_message'] ?? '';
unset($_SESSION['reset_data_message']);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $nonce = $_SESSION['reset_data_nonce'] ?? '';
        unset($_SESSION['reset_data_nonce']);
        if (!is_string($_POST['reset_nonce'] ?? null) || $nonce === '' || !hash_equals($nonce, $_POST['reset_nonce'])) {
            throw new DomainException('This reset form has expired. Reload the page and try again.');
        }
        if (($_POST['action'] ?? '') === 'delete_product') {
            $_SESSION['reset_data_message'] = $service->deleteProduct((int)$_SESSION['user_id'], (string)current_role(),
                (int)($_POST['product_id'] ?? 0), (string)($_POST['current_password'] ?? ''), (string)($_POST['confirmation'] ?? ''));
            unset($_SESSION['forecast_import_preview'], $_SESSION['checkout_quotes'], $_SESSION['completed_checkout_sale']);
            header('Location: ' . app_url('components/administrator/reset_data.php'));
            exit;
        }
        $result = $service->reset((int)$_SESSION['user_id'], (string)current_role(),
            (string)($_POST['current_password'] ?? ''), (string)($_POST['confirmation'] ?? ''));
        $_SESSION['reset_data_backup'] = $result['backup']['token'];
        unset($_SESSION['forecast_import_preview'], $_SESSION['checkout_quotes'], $_SESSION['completed_checkout_sale']);
        $message = 'Products, sales, inventory, and forecasting data were deleted. Other users must sign in again. Add products, stock, and sales history before retraining forecasts.';
        if ($result['warnings']) $message .= ' ' . implode(' ', $result['warnings']);
        $_SESSION['reset_data_message'] = $message;
        header('Location: ' . app_url('components/administrator/reset_data.php'));
        exit;
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Operational reset failed: ' . $exception->getMessage());
        $error = 'The reset could not complete. Database changes were rolled back. Check the application log and backup history before trying again.';
    }
}
$summary = $service->summary();
$blockers = $service->blockers();
$backupToken = $_SESSION['reset_data_backup'] ?? null;
$productStatement = $pdo->prepare('SELECT product_id, product_name, sku, status FROM products WHERE branch_id = ? ORDER BY product_name');
$productStatement->execute([store_scope_id($pdo)]);
$products = $productStatement->fetchAll();
$_SESSION['reset_data_nonce'] = bin2hex(random_bytes(24));
$labels = [
    'products' => 'Products', 'supplier_products' => 'Product supplier links', 'promotions' => 'Product promotions',
    'sales' => 'Completed sales', 'sale_items' => 'Sale line items', 'held_sales' => 'Held sales',
    'cash_refunds' => 'Cash refunds', 'sale_reversals' => 'Sale reversals', 'cashier_shifts' => 'Closed cashier shifts',
    'inventory_units' => 'Units currently in stock', 'stock_movements' => 'Stock movements',
    'inventory_adjustments' => 'Stock adjustments', 'inventory_counts' => 'Stock counts',
    'product_batches' => 'Stock batches', 'stock_receiving' => 'Stock receiving records',
    'purchase_orders' => 'Purchase orders', 'replenishment_requests' => 'Replenishment requests',
    'stock_predictions' => 'Forecast predictions', 'forecast_sales_imports' => 'Imported daily sales totals',
    'forecast_evaluations' => 'Forecast evaluations', 'model_training_runs' => 'Model training history',
];
?>
<!DOCTYPE html>
<html lang="en"><head>
<?php retailmind_theme_head(); ?>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset Data</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reset-data.css')) ?>">
</head><body><div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<main class="main-content reset-page">
<header class="reset-heading"><div><h1>Reset Data</h1><p class="page-subtitle">Manage product removal and reset the Store’s operational records.</p></div><a class="reset-jump" href="#store-reset"><span>Review whole-store reset</span><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3v10M4 9l4 4 4-4"/></svg></a></header>
<?php if ($message): ?><p class="alert tag-success" role="status" data-rm-alert-processed="1"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert tag-warning alert-danger" role="alert" data-rm-alert-processed="1"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<section class="reset-panel reset-product" aria-labelledby="product-title">
    <div class="reset-product-intro">
        <h2 id="product-title">Delete one product</h2><p>Remove a single item without resetting the Store.</p>
        <dl class="reset-outcomes"><div><dt>Unused product</dt><dd>Deleted with its supplier links and product promotions.</dd></div><div><dt>Product with stock or history</dt><dd>Made inactive. Its records are preserved.</dd></div></dl>
        <p class="reset-note">No backup is created for this action.</p>
    </div>
    <form method="post" class="reset-product-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_product">
        <input type="hidden" name="reset_nonce" value="<?= htmlspecialchars($_SESSION['reset_data_nonce']) ?>">
        <div class="form-group"><label for="delete-product">Product</label><select id="delete-product" name="product_id" <?= !$products ? 'aria-describedby="delete-product-empty" ' : '' ?>required><option value="">Choose a product</option><?php foreach ($products as $product): ?><option value="<?= (int)$product['product_id'] ?>"><?= htmlspecialchars($product['product_name'] . ' — ' . $product['sku'] . ' (' . $product['status'] . ')') ?></option><?php endforeach; ?></select></div>
        <?php if (!$products): ?><p id="delete-product-empty" class="reset-note">The product catalog is empty. There are no products to delete.</p><?php endif; ?>
        <div class="reset-fields">
            <div class="form-group"><label for="delete-password">Current password</label><input type="password" id="delete-password" name="current_password" autocomplete="current-password" required></div>
            <div class="form-group"><label for="delete-confirmation">Type <strong>DELETE PRODUCT</strong></label><input type="text" id="delete-confirmation" name="confirmation" pattern="DELETE PRODUCT" title="Type DELETE PRODUCT in uppercase to confirm" maxlength="32" autocomplete="off" spellcheck="false" required></div>
        </div>
        <button type="submit" class="btn reset-delete" <?= !$products ? 'disabled' : '' ?>>Delete selected product</button>
    </form>
</section>
<?php if ($backupToken): ?><section class="reset-panel reset-backup" aria-labelledby="backup-title"><div><h2 id="backup-title">Your pre-reset backup</h2><p>Keep this SQL backup. A Super Administrator can restore it; the forecasting model must then be retrained.</p></div><a class="btn" href="<?= htmlspecialchars(app_url('components/backup/backup_download.php?token=' . rawurlencode($backupToken))) ?>" download>Download backup before reset</a></section><?php endif; ?>
<section id="store-reset" class="reset-store" aria-labelledby="store-title">
    <header class="reset-section-heading"><h2 id="store-title">Reset the whole Store</h2><p>Review what will be removed before confirming. This empties the product catalog and removes the trained forecasting model.</p></header>
    <div class="reset-layout">
        <div class="reset-panel reset-review">
            <h3>What will be removed</h3><p class="reset-note">Current totals across products, sales, inventory, and forecasting.</p>
            <div class="reset-records">
                <?php foreach ($labels as $key => $label): ?>
                <?php if (in_array($key, ['products', 'sales', 'inventory_units', 'stock_predictions'], true)): ?><h4><?= htmlspecialchars(['products' => 'Product catalog', 'sales' => 'Sales & cashier activity', 'inventory_units' => 'Inventory & purchasing', 'stock_predictions' => 'Forecasting'][$key]) ?></h4><?php endif; ?>
                <div class="reset-record"><span><?= htmlspecialchars($label) ?></span><strong><?= number_format($summary[$key]) ?></strong></div>
                <?php endforeach; ?>
            </div>
            <div class="reset-preserved"><h3>What stays</h3><p>Categories, suppliers, store-wide and category promotions, users, roles, registers, settings, Fiscal Periods, and Protected Audit Records.</p></div>
        </div>
        <div class="reset-panel reset-confirm">
            <h3>Before you reset</h3>
            <ul class="reset-preflight">
                <li><strong>A backup is created first</strong><span>A SQL backup is required. If it fails, the reset cannot proceed.</span></li>
                <li><strong>Other users are signed out</strong><span>Old carts and previews can no longer be submitted.</span></li>
                <li><strong>Forecasting needs a fresh start</strong><span>Add products, stock, and sales history before retraining.</span></li>
            </ul>
            <p class="reset-note">The reset runs as one database transaction. Sales and receipt details, refunds, reversals, and the operational records listed here are deleted.</p>
            <div class="reset-confirmation">
                <h3>Confirm reset</h3>
                <?php if ($blockers): ?><div class="alert tag-warning" role="alert" data-rm-alert-processed="1"><strong>Reset unavailable</strong><ul><?php foreach ($blockers as $blocker): ?><li><?= htmlspecialchars($blocker) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <p>Enter your current password and the exact confirmation phrase to delete the Store’s operational data.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="reset_nonce" value="<?= htmlspecialchars($_SESSION['reset_data_nonce']) ?>">
                    <div class="form-group"><label for="reset-password">Current password</label><input type="password" id="reset-password" name="current_password" autocomplete="current-password" required></div>
                    <div class="form-group"><label for="reset-confirmation">Type <strong>RESET ALL DATA</strong></label><input type="text" id="reset-confirmation" name="confirmation" autocomplete="off" pattern="RESET ALL DATA" title="Type RESET ALL DATA in uppercase to confirm" maxlength="32" spellcheck="false" required></div>
                    <button type="submit" class="btn btn-danger" <?= $blockers ? 'disabled' : '' ?>>Reset all operational data</button>
                </form>
            </div>
        </div>
    </div>
</section>
</main></div></body></html>