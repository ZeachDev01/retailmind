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
<!DOCTYPE html><html lang="en"><head><?php retailmind_theme_head(); ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset Data</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"></head>
<body><div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
    <div class="topbar"><div><h1>Reset Data</h1><p class="page-subtitle">Clear products, sales, inventory, and forecasting data for the whole store.</p></div></div>
    <?php if ($message): ?><p class="alert tag-success" role="status"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="alert tag-warning" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <section class="dashboard-section"><h2>Delete one product</h2>
        <p>Select a single product. Unused products are deleted with their supplier links and product promotions. Products with stock or history become inactive, preserving their records. This action does not reset the store or create a backup.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_product">
            <input type="hidden" name="reset_nonce" value="<?= htmlspecialchars($_SESSION['reset_data_nonce']) ?>">
            <div class="form-group"><label for="delete-product">Product</label><select id="delete-product" name="product_id" required><option value="">Choose a product</option><?php foreach ($products as $product): ?><option value="<?= (int)$product['product_id'] ?>"><?= htmlspecialchars($product['product_name'] . ' — ' . $product['sku'] . ' (' . $product['status'] . ')') ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="delete-password">Current password</label><input type="password" id="delete-password" name="current_password" autocomplete="current-password" required></div>
            <div class="form-group"><label for="delete-confirmation">Type DELETE PRODUCT</label><input id="delete-confirmation" name="confirmation" pattern="DELETE PRODUCT" autocomplete="off" required></div>
            <button type="submit" class="btn btn-danger" <?= !$products ? 'disabled' : '' ?>>Delete selected product</button>
        </form>
    </section>
    <?php if ($backupToken): ?><section class="dashboard-section"><h2>Backup before reset</h2><p>Download and keep this SQL backup. A Super Administrator can restore it if needed; the forecasting model must be retrained after restoration.</p><a class="btn" href="<?= htmlspecialchars(app_url('components/backup/backup_download.php?token=' . rawurlencode($backupToken))) ?>">Download backup before reset</a></section><?php endif; ?>
    <section class="dashboard-section"><h2>What will be reset</h2>
        <p>This removes all products, their supplier links and product promotions, inventory records, sales and receipt details, refunds and reversals, held sales, closed cashier shifts, stock movements, counts, batches, receiving and purchasing activity, imported forecast history, predictions, evaluations, and training history. The product catalog becomes empty and the trained model is removed.</p>
        <p>Categories, suppliers, store-wide and category promotions, users, roles, registers, settings, fiscal periods, and protected audit records are preserved. Other users are signed out to prevent old carts and previews from being submitted.</p>
        <p>A SQL backup is created automatically before deletion. The reset runs as one database transaction and cannot proceed if the backup fails.</p>
        <div class="table-wrap"><table><thead><tr><th scope="col">Data</th><th scope="col">Current total</th></tr></thead><tbody><?php foreach ($labels as $key => $label): ?><tr><td><?= htmlspecialchars($label) ?></td><td><?= number_format($summary[$key]) ?></td></tr><?php endforeach; ?></tbody></table></div>
    </section>
    <section class="dashboard-section"><h2>Confirm reset</h2>
        <?php foreach ($blockers as $blocker): ?><p class="alert tag-warning"><?= htmlspecialchars($blocker) ?></p><?php endforeach; ?>
        <p>This deletes products and operational records. Enter your current password and type <strong>RESET ALL DATA</strong> to proceed.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="reset_nonce" value="<?= htmlspecialchars($_SESSION['reset_data_nonce']) ?>">
            <div class="form-group"><label for="reset-password">Current password</label><input type="password" id="reset-password" name="current_password" autocomplete="current-password" required></div>
            <div class="form-group"><label for="reset-confirmation">Type RESET ALL DATA</label><input type="text" id="reset-confirmation" name="confirmation" autocomplete="off" pattern="RESET ALL DATA" required></div>
            <button type="submit" class="btn btn-danger" <?= $blockers ? 'disabled' : '' ?>>Reset products, sales, stock, and forecasting data</button>
        </form>
    </section>
</main></div></body></html>
