<?php
// components/inventory_management/promotions.php — Promotions management.
//
// Configure promotions for explicit Cashier selection at checkout.
// Enabled promotions become eligible during their scheduled window.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_role(['admin', 'super_admin']);

$message = '';
$error = '';
$invalidField = '';
$validationMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (current_role() === 'super_admin') {
        require_capability(\App\Authorization\RoleCapabilityPolicy::STORE_OPERATIONS);
    }
    csrf_verify();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $name = trim((string)($_POST['promotion_name'] ?? ''));
            $type = in_array($_POST['discount_type'] ?? '', ['percentage', 'fixed'], true) ? $_POST['discount_type'] : 'percentage';
            $value = (float)($_POST['discount_value'] ?? 0);
            $scope = in_array($_POST['scope'] ?? '', ['all', 'product', 'category'], true) ? $_POST['scope'] : 'all';
            $productId = $scope === 'product' ? (int)($_POST['product_id'] ?? 0) : null;
            $categoryId = $scope === 'category' ? (int)($_POST['category_id'] ?? 0) : null;
            $minimum = max(1, (int)($_POST['minimum_quantity'] ?? 1));
            $starts = trim((string)($_POST['starts_at'] ?? ''));
            $ends = trim((string)($_POST['ends_at'] ?? ''));

            if ($name === '' || $value <= 0 || $starts === '' || $ends === '') {
                $invalidField = $name === '' ? 'promotion_name' : ($value <= 0 ? 'discount_value' : ($starts === '' ? 'starts_at' : 'ends_at'));
                $validationMessage = 'Name, value, start, and end dates are required.';
                throw new RuntimeException($validationMessage);
            }
            if ($type === 'percentage' && $value > 100) {
                $invalidField = 'discount_value';
                $validationMessage = 'Percentage cannot exceed 100%.';
                throw new RuntimeException($validationMessage);
            }
            if (strtotime($ends) <= strtotime($starts)) {
                $invalidField = 'ends_at';
                $validationMessage = 'End date must be later than start date.';
                throw new RuntimeException($validationMessage);
            }
            if ($scope === 'product' && !$productId) {
                $invalidField = 'product_id';
                $validationMessage = 'Select a product.';
                throw new RuntimeException($validationMessage);
            }
            if ($scope === 'category' && !$categoryId) {
                $invalidField = 'category_id';
                $validationMessage = 'Select a category.';
                throw new RuntimeException($validationMessage);
            }

            $stmt = $pdo->prepare("INSERT INTO promotions(promotion_name,discount_type,discount_value,scope,product_id,category_id,minimum_quantity,starts_at,ends_at,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,'active',?)");
            App\Store\StoreWriteGate::execute($pdo, $stmt, [$name, $type, $value, $scope, $productId ?: null, $categoryId ?: null, $minimum, date('Y-m-d H:i:s', strtotime($starts)), date('Y-m-d H:i:s', strtotime($ends)), (int)$_SESSION['user_id']]);
            $id = (int)$pdo->lastInsertId();
            (new \App\Store\ScheduledDemandDriverService())->syncPromotionDrivers($pdo, $id, false);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Created promotion', 'Promotions', $id, null, ['name' => $name, 'type' => $type, 'value' => $value, 'scope' => $scope]);
            $message = 'Promotion created.';
        } elseif (in_array($action, ['activate', 'deactivate'], true)) {
            $id = (int)($_POST['promotion_id'] ?? 0);
            $status = $action === 'activate' ? 'active' : 'inactive';
            $stmt = $pdo->prepare('UPDATE promotions SET status=? WHERE promotion_id=?');
            App\Store\StoreWriteGate::execute($pdo, $stmt, [$status, $id]);
            if (!$stmt->rowCount()) {
                throw new RuntimeException('Promotion was not updated.');
            }
            (new \App\Store\ScheduledDemandDriverService())->syncPromotionDrivers($pdo, $id, $action === 'deactivate');
            log_activity($pdo, (int)$_SESSION['user_id'], ucfirst($action) . ' promotion', 'Promotions', $id);
            $message = 'Promotion updated.';
        }
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The promotion could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.');
    }
}

$products = $pdo->query("SELECT product_id,sku,product_name,variant_label FROM products WHERE status='active' ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query('SELECT category_id,category_name FROM categories ORDER BY category_name')->fetchAll(PDO::FETCH_ASSOC);
$promotions = $pdo->query("SELECT pr.*,p.product_name,c.category_name,u.display_name created_by_name FROM promotions pr LEFT JOIN products p ON p.product_id=pr.product_id LEFT JOIN categories c ON c.category_id=pr.category_id JOIN users u ON u.user_id=pr.created_by ORDER BY pr.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$draft = $error !== '' && ($_POST['action'] ?? '') === 'create' ? array_filter($_POST, 'is_string') : [];
$promotionValue = static fn(string $key, string $default = ''): string => htmlspecialchars($draft[$key] ?? $default, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Promotions</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/inventory.css')) ?>">
</head>
<body class="promotions-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <h1>Discounts &amp; Promotions</h1>
            <div class="page-heading-actions">
                <a href="<?= htmlspecialchars(theme_url('components/report/predictions.php')) ?>" class="btn btn-secondary"><i class="bi bi-graph-up" aria-hidden="true"></i> Forecasting</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="message success" role="status"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="two-col promotions-workspace">
            <section class="dashboard-section">
                <h2>Create promotion</h2>
                <form method="post" data-unsaved-changes="<?= $draft ? 'dirty' : '' ?>" data-error-field="<?= htmlspecialchars($invalidField) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <?php if ($invalidField !== ''): ?><small class="field-error" id="promotion-field-error" data-field-error><?= htmlspecialchars($validationMessage) ?></small><?php endif; ?>
                    <div class="form-group">
                        <label for="promotion-promotion_name">Name</label>
                        <input id="promotion-promotion_name" name="promotion_name" value="<?= $promotionValue('promotion_name') ?>" autocomplete="off" required>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="promotion-discount_type">Discount type</label>
                            <select id="promotion-discount_type" name="discount_type">
                                <option value="percentage" <?= ($draft['discount_type'] ?? 'percentage') === 'percentage' ? 'selected' : '' ?>>Percentage</option>
                                <option value="fixed" <?= ($draft['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="promotion-discount_value">Value</label>
                            <input type="number" id="promotion-discount_value" name="discount_value" value="<?= $promotionValue('discount_value') ?>" min="0.01" step="0.01" autocomplete="off" required>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="scope">Scope</label>
                            <select name="scope" id="scope">
                                <option value="all" <?= ($draft['scope'] ?? 'all') === 'all' ? 'selected' : '' ?>>Entire cart</option>
                                <option value="product" <?= ($draft['scope'] ?? '') === 'product' ? 'selected' : '' ?>>Product</option>
                                <option value="category" <?= ($draft['scope'] ?? '') === 'category' ? 'selected' : '' ?>>Category</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="promotion-minimum_quantity">Minimum quantity</label>
                            <input type="number" id="promotion-minimum_quantity" name="minimum_quantity" min="1" value="<?= $promotionValue('minimum_quantity', '1') ?>" autocomplete="off">
                        </div>
                    </div>
                    <div class="form-group" id="product-scope" hidden>
                        <label for="promotion-product_id">Product</label>
                        <select data-search-dropdown id="promotion-product_id" name="product_id">
                            <option value="">Select product</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['product_id'] ?>" <?= (string)($draft['product_id'] ?? '') === (string)$p['product_id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['product_name'] . (!empty($p['variant_label']) ? ' - ' . $p['variant_label'] : '') . ' (' . $p['sku'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="category-scope" hidden>
                        <label for="promotion-category_id">Category</label>
                        <select data-search-dropdown id="promotion-category_id" name="category_id">
                            <option value="">Select category</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['category_id'] ?>" <?= (string)($draft['category_id'] ?? '') === (string)$c['category_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="promotion-starts_at">Starts</label>
                            <input type="datetime-local" id="promotion-starts_at" name="starts_at" value="<?= $promotionValue('starts_at', date('Y-m-d\TH:i')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="promotion-ends_at">Ends</label>
                            <input type="datetime-local" id="promotion-ends_at" name="ends_at" value="<?= $promotionValue('ends_at', date('Y-m-d\TH:i', strtotime('+7 days'))) ?>" required>
                        </div>
                    </div>
                    <button type="submit" class="btn">Create promotion</button>
                </form>
            </section>

            <section class="dashboard-section">
                <h2>Promotion schedule</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Discount</th>
                                <th>Scope</th>
                                <th>Window</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $now = time(); foreach ($promotions as $pr):
                                $isEnabled = $pr['status'] === 'active';
                                $starts = strtotime((string)$pr['starts_at']);
                                $ends = strtotime((string)$pr['ends_at']);
                                $inWindow = ($starts <= $now && $now <= $ends);
                                if (!$isEnabled) {
                                    $statusLabel = 'Disabled';
                                    $eligibilityLabel = 'Not eligible';
                                } elseif ($inWindow) {
                                    $statusLabel = 'Enabled';
                                    $eligibilityLabel = 'Currently eligible';
                                } elseif ($now < $starts) {
                                    $statusLabel = 'Enabled';
                                    $eligibilityLabel = 'Upcoming window';
                                } else {
                                    $statusLabel = 'Enabled';
                                    $eligibilityLabel = 'Expired window';
                                }
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($pr['promotion_name']) ?></strong></td>
                                <td>
                                    <?= htmlspecialchars($pr['discount_type'] === 'percentage' ? number_format((float)$pr['discount_value'], 2) . '%' : '₱' . number_format((float)$pr['discount_value'], 2)) ?>
                                    <?php if ((int)$pr['minimum_quantity'] > 1): ?>
                                        <br><small class="u-text-muted">Min qty: <?= (int)$pr['minimum_quantity'] ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($pr['scope'] === 'product' ? ($pr['product_name'] ?? 'Product') : ($pr['scope'] === 'category' ? ($pr['category_name'] ?? 'Category') : 'Entire cart')) ?></td>
                                <td><?= htmlspecialchars($pr['starts_at']) ?><br><small class="u-text-muted">to <?= htmlspecialchars($pr['ends_at']) ?></small></td>
                                <td>
                                    <strong><?= htmlspecialchars($statusLabel) ?></strong><br>
                                    <small class="u-text-muted"><?= htmlspecialchars($eligibilityLabel) ?></small>
                                </td>
                                <td>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="promotion_id" value="<?= $pr['promotion_id'] ?>">
                                        <input type="hidden" name="action" value="<?= $isEnabled ? 'deactivate' : 'activate' ?>">
                                        <button type="submit" class="btn btn-small"><?= $isEnabled ? 'Disable' : 'Enable' ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$promotions): ?>
                                <tr><td colspan="6">No promotions created.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>
<script>
const scope = document.getElementById('scope');
const product = document.getElementById('product-scope');
const category = document.getElementById('category-scope');
function toggle() {
    product.hidden = scope.value !== 'product';
    category.hidden = scope.value !== 'category';
}
scope.addEventListener('change', toggle);
toggle();
</script>
</body>
</html>
