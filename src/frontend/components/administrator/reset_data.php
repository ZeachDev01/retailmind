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
            (string)($_POST['current_password'] ?? ''), (string)($_POST['confirmation'] ?? ''), (string)($_POST['backup_passphrase'] ?? ''));
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
    'products' => 'Products', 'promotions' => 'Product promotions',
    'sales' => 'Completed sales', 'sale_items' => 'Sale line items', 'held_sales' => 'Held sales',
    'cash_refunds' => 'Cash refunds', 'sale_reversals' => 'Sale reversals', 'cashier_shifts' => 'Closed cashier shifts',
    'inventory_units' => 'Units currently in stock', 'stock_movements' => 'Stock movements',
    'inventory_adjustments' => 'Stock adjustments', 'inventory_counts' => 'Stock counts',
    'product_batches' => 'Stock batches', 'stock_receiving' => 'Stock receiving records',

    'stock_predictions' => 'Forecast predictions', 'forecast_sales_imports' => 'Imported daily sales totals',
    'forecast_evaluations' => 'Forecast evaluations', 'model_training_runs' => 'Model training history',
];
$recordGroups = [
    'products' => ['Product catalog', 'products', 'promotions'],
    'sales' => ['Sales & cashier activity', 'sales', 'sale_items', 'held_sales', 'cash_refunds', 'sale_reversals', 'cashier_shifts'],
    'inventory_units' => ['Inventory & receiving', 'inventory_units', 'stock_movements', 'inventory_adjustments', 'inventory_counts', 'product_batches', 'stock_receiving'],
    'stock_predictions' => ['Forecasting', 'stock_predictions', 'forecast_sales_imports', 'forecast_evaluations', 'model_training_runs'],
];
?>
<!DOCTYPE html>
<html lang="en"><head>
<?php retailmind_theme_head(); ?>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset Data</title>
<link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reset-data.css')) ?>">
</head><body><div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<main class="main-content reset-page">
<header class="reset-heading"><h1>Reset Data</h1></header>
<?php if ($message): ?><p class="alert tag-success" role="status" data-rm-alert-processed="1"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert tag-warning alert-danger" role="alert" data-rm-alert-processed="1"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<section class="reset-panel reset-product" aria-labelledby="product-title">
    <div class="reset-product-intro">
        <h2 id="product-title">Delete one product</h2>
        <ul class="reset-outcomes"><li><strong>No stock or history:</strong> permanently deleted with its product promotions.</li><li><strong>Has stock or history:</strong> made inactive; stock and records kept.</li></ul>
        <p id="delete-product-warning" class="reset-note reset-product-warning"><strong>No automatic backup or recovery from this page.</strong></p>
    </div>
    <form method="post" class="reset-product-form" aria-labelledby="product-title" aria-describedby="delete-product-warning">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_product">
        <input type="hidden" name="reset_nonce" value="<?= htmlspecialchars($_SESSION['reset_data_nonce']) ?>">
        <div class="form-group"><label for="delete-product">Product</label><select data-search-dropdown id="delete-product" name="product_id" <?= !$products ? 'aria-describedby="delete-product-empty" ' : '' ?>required><option value="">Choose a product</option><?php foreach ($products as $product): ?><option value="<?= (int)$product['product_id'] ?>"><?= htmlspecialchars($product['product_name'] . ' — ' . $product['sku'] . ' (' . $product['status'] . ')') ?></option><?php endforeach; ?></select></div>
        <?php if (!$products): ?><p id="delete-product-empty" class="reset-note">No products to delete.</p><?php endif; ?>
        <div class="reset-fields">
            <div class="form-group"><label for="delete-password">Current password</label><input type="password" id="delete-password" name="current_password" autocomplete="current-password" required></div>
            <div class="form-group"><label for="delete-confirmation">Type <strong>DELETE PRODUCT</strong> to confirm</label><input type="text" id="delete-confirmation" name="confirmation" pattern="DELETE PRODUCT" title="Type DELETE PRODUCT in uppercase to confirm" maxlength="32" autocomplete="off" spellcheck="false" required></div>
        </div>
        <button type="submit" class="btn btn-danger reset-delete" <?= !$products ? 'disabled' : '' ?>>Delete selected product</button>
    </form>
</section>
<?php if ($backupToken): ?><section class="reset-panel reset-backup" aria-labelledby="backup-title"><h2 id="backup-title">Pre-reset encrypted backup</h2><a class="btn" href="<?= htmlspecialchars(app_url('components/backup/backup_download.php?token=' . rawurlencode($backupToken))) ?>" download>Download pre-reset backup</a></section><?php endif; ?>
<details id="store-reset" class="reset-panel reset-store" <?= $error && ($_POST['action'] ?? '') !== 'delete_product' ? 'open' : '' ?>>
    <summary class="reset-section-heading"><span><span id="store-title" class="reset-section-title">Reset the whole Store</span><?php if ($blockers): ?><span class="reset-blocked">Reset unavailable. <?= htmlspecialchars(implode(' ', $blockers)) ?></span><?php endif; ?></span><span class="reset-review-link">Review reset <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span></summary>
    <div class="reset-store-body">
    <div class="reset-warning"><strong>Deletes all products, sales, inventory, and forecasting data, including receipts, refunds, reversals, and the trained model.</strong><p>Database recovery requires a Super Administrator to restore a backup.</p></div>
    <div class="reset-layout">
        <div class="reset-review">
            <h3>Records to delete</h3>
            <div class="reset-records">
                <?php foreach ($recordGroups as $primaryKey => $group): ?>
                <details class="reset-record-group"><summary><span><strong><?= htmlspecialchars($group[0]) ?></strong><span class="reset-group-total"><?= htmlspecialchars($labels[$primaryKey]) ?>: <?= number_format($summary[$primaryKey]) ?></span></span><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
                    <?php foreach (array_slice($group, 1) as $key): ?><div class="reset-record"><span><?= htmlspecialchars($labels[$key]) ?></span><strong><?= number_format($summary[$key]) ?></strong></div><?php endforeach; ?>
                </details>
                <?php endforeach; ?>
            </div>
            <div class="reset-preserved"><h3>What stays unchanged</h3><ul><li>Categories and store-wide or category promotions</li><li>Users, roles, registers, and settings</li><li>Fiscal Periods and Protected Audit Records</li></ul></div>
        </div>
        <div class="reset-confirm">
            <h3>Before you reset</h3>
            <ul class="reset-preflight">
                <li>An encrypted backup must succeed before reset. Download it afterward and keep its separate passphrase off-server. Losing every copy makes the package unusable.</li>
                <li>Other users are signed out; old carts and previews become invalid.</li>
                <li>Add products, stock, and sales history before retraining forecasts.</li>
            </ul>
            <div class="reset-confirmation">
                <h3>Confirm reset</h3>
                <?php if ($blockers): ?><div class="alert tag-warning" role="alert" data-rm-alert-processed="1"><strong>Reset unavailable</strong><ul><?php foreach ($blockers as $blocker): ?><li><?= htmlspecialchars($blocker) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="reset_nonce" value="<?= htmlspecialchars($_SESSION['reset_data_nonce']) ?>">
                    <div class="form-group"><label for="reset-password">Current password</label><input type="password" id="reset-password" name="current_password" autocomplete="current-password" required></div>
                    <div class="form-group"><label for="reset-backup-passphrase">New backup passphrase</label><input type="password" id="reset-backup-passphrase" name="backup_passphrase" autocomplete="new-password" minlength="16" maxlength="1024" required></div>
                    <div class="form-group"><label for="reset-confirmation">Type <strong>RESET ALL DATA</strong> to confirm</label><input type="text" id="reset-confirmation" name="confirmation" autocomplete="off" pattern="RESET ALL DATA" title="Type RESET ALL DATA in uppercase to confirm" maxlength="32" spellcheck="false" required></div>
                    <button type="submit" class="btn btn-danger" <?= $blockers ? 'disabled' : '' ?>>Reset all operational data</button>
                </form>
            </div>
        </div>
    </div>
    </div>
</details>
</main></div></body></html>
