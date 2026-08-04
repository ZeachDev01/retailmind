<?php
// Sidebar/inventory_management/products.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/ProductService.php';
require_role(['admin', 'super_admin', 'inventory_manager']);

$canDirectAdjust = in_array(current_role(), ['admin', 'super_admin'], true);
$productService = new ProductService($pdo);

function product_ids_from_request($value): array
{
    $values = is_array($value) ? $value : explode(',', (string)$value);
    return array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0)));
}

function redirect_products(string $type, string $message): void
{
    header('Location: products.php?' . $type . '=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_category') {
        csrf_verify();
        try {
            $categoryName = trim((string)($_POST['category_name'] ?? ''));
            $productService->createCategory(['category_name' => $categoryName], (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Added category ' . $categoryName);
            redirect_products('success', 'Category added successfully.');
        } catch (Throwable $e) {
            redirect_products('error', $e->getMessage());
        }
    }

    if ($action === 'create') {
        csrf_verify();
        try {
            $productId = $productService->createProduct([
                'barcode' => $_POST['barcode'] ?? '',
                'product_name' => $_POST['product_name'] ?? '',
                'brand' => $_POST['brand'] ?? '',
                'category_id' => $_POST['category_id'] ?? '',
                'cost_price' => $_POST['cost_price'] ?? 0,
                'selling_price' => $_POST['selling_price'] ?? 0,
                'reorder_level' => $_POST['reorder_level'] ?? 10,
                'preferred_supplier' => $_POST['preferred_supplier'] ?? '',
                'supplier_lead_time_days' => $_POST['supplier_lead_time_days'] ?? 7,
                'safety_stock' => $_POST['safety_stock'] ?? 0,
                'minimum_order_quantity' => $_POST['minimum_order_quantity'] ?? 1,
                'units_per_package' => $_POST['units_per_package'] ?? 1,
                'base_unit' => $_POST['base_unit'] ?? 'piece',
                'receiving_unit' => $_POST['receiving_unit'] ?? 'package',
                'case_barcode' => $_POST['case_barcode'] ?? '',
                'parent_product_id' => $_POST['parent_product_id'] ?? 0,
                'variant_label' => $_POST['variant_label'] ?? '',
                'expiration_date' => $_POST['expiration_date'] ?? null,
                'product_image' => $_POST['product_image'] ?? '',
                'status' => $_POST['status'] ?? 'active',
                'initial_stock_quantity' => 0,
            ], (int)$_SESSION['user_id']);

            $newProductStmt = $pdo->prepare(
                "SELECT p.*, i.quantity_on_hand
                 FROM products p
                 JOIN inventory i ON i.product_id = p.product_id
                 WHERE p.product_id = ?"
            );
            $newProductStmt->execute([$productId]);
            $newProduct = $newProductStmt->fetch() ?: [];
            log_activity($pdo, (int)$_SESSION['user_id'], 'Product creation', 'Products', $productId, null, $newProduct);

            $labelQuantity = max(1, min(200, (int)($_POST['label_quantity'] ?? 1)));
            if (!empty($_POST['print_after_create'])) {
                header('Location: print_barcodes.php?product_id=' . $productId . '&quantity=' . $labelQuantity . '&autoprint=1');
                exit;
            }

            redirect_products('success', 'Product added successfully. Barcode: ' . trim((string)($newProduct['barcode'] ?? 'Generated')));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirect_products('error', $e->getMessage());
        }
    }

    if ($action === 'generate_barcode') {
        csrf_verify();
        try {
            $productId = (int)($_POST['product_id'] ?? 0);
            $labelQuantity = max(1, min(200, (int)($_POST['label_quantity'] ?? 1)));
            $productService->assignGeneratedBarcode($productId, (int)$_SESSION['user_id']);
            header('Location: print_barcodes.php?product_id=' . $productId . '&quantity=' . $labelQuantity . '&autoprint=1');
            exit;
        } catch (Throwable $e) {
            redirect_products('error', $e->getMessage());
        }
    }

    if ($action === 'adjust') {
        csrf_verify();
        try {
            if (!$canDirectAdjust) {
                throw new RuntimeException('Only administrators can perform emergency stock adjustments.');
            }
            $productService->adjustStock([
                'qty_change' => (int)($_POST['qty_change'] ?? 0),
                'product_id' => (int)($_POST['product_id'] ?? 0),
                'adjustment_reason' => trim((string)($_POST['adjustment_reason'] ?? '')),
            ], (int)$_SESSION['user_id']);
            redirect_products('success', 'Emergency inventory adjustment recorded.');
        } catch (Throwable $e) {
            redirect_products('error', $e->getMessage());
        }
    }

    if ($action === 'bulk_status' || $action === 'bulk_category') {
        csrf_verify();
        try {
            $productIds = product_ids_from_request($_POST['product_ids'] ?? []);
            if (!$productIds) {
                throw new RuntimeException('Select at least one product.');
            }
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));

            if ($action === 'bulk_status') {
                $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : '';
                if ($status === '') {
                    throw new RuntimeException('Choose a valid product status.');
                }
                $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE product_id IN ($placeholders)");
                $stmt->execute(array_merge([$status], $productIds));
                log_activity($pdo, (int)$_SESSION['user_id'], 'Bulk product status updated to ' . $status . ' for ' . count($productIds) . ' products');
                redirect_products('success', count($productIds) . ' product(s) updated to ' . $status . '.');
            }

            $categoryId = (int)($_POST['category_id'] ?? 0);
            if ($categoryId <= 0) {
                throw new RuntimeException('Choose a category.');
            }
            $stmt = $pdo->prepare("UPDATE products SET category_id = ? WHERE product_id IN ($placeholders)");
            $stmt->execute(array_merge([$categoryId], $productIds));
            log_activity($pdo, (int)$_SESSION['user_id'], 'Bulk product category updated for ' . count($productIds) . ' products');
            redirect_products('success', 'Category updated for ' . count($productIds) . ' product(s).');
        } catch (Throwable $e) {
            redirect_products('error', $e->getMessage());
        }
    }
}

$categories = $productService->getCategories();
$products = $productService->getProductsForManagement();
$activeProducts = $productService->getActiveProducts();
$variantParents = $products;
$totalProducts = count($products);
$lowCount = 0;
$outCount = 0;
$withoutBarcodeCount = 0;
foreach ($products as $product) {
    $quantity = (int)($product['quantity_on_hand'] ?? 0);
    $threshold = max((int)($product['reorder_level'] ?? 0), (int)($product['safety_stock'] ?? 0));
    if ($quantity <= 0) {
        $outCount++;
    } elseif ($quantity <= $threshold) {
        $lowCount++;
    }
    if (trim((string)($product['barcode'] ?? '')) === '') {
        $withoutBarcodeCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products &amp; Stock</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Products &amp; Stock</h1>
                <p class="page-subtitle">Search, filter, organize, and maintain the store's complete product catalog.</p>
            </div>
            <div class="page-heading-actions">
                <button type="button" class="btn btn-success btn-icon" id="add-product-btn"><i class="bi bi-plus-lg"></i>Add Product</button>
                <a class="btn btn-quiet btn-icon" href="<?= htmlspecialchars(app_url('report/stock_receiving.php')) ?>"><i class="bi bi-box-arrow-in-down"></i>Receive Stock</a>
                <a class="btn btn-warning btn-icon" href="<?= htmlspecialchars(app_url('Sidebar/inventory_management/print_barcodes.php')) ?>"><i class="bi bi-upc-scan"></i>Barcode Labels</a>
            </div>
        </header>

        <div class="card-grid" style="margin-bottom:1rem;">
            <a class="stat-card-link" href="#product-table" data-quick-filter="all"><article class="stat-card with-icon"><span class="stat-icon"><i class="bi bi-box-seam"></i></span><div class="value"><?= number_format($totalProducts) ?></div><div class="label">Total Products</div><div class="hint">All active and inactive catalog entries</div></article></a>
            <a class="stat-card-link" href="#product-table" data-quick-filter="low"><article class="stat-card with-icon <?= $lowCount ? 'warning' : 'success' ?>"><span class="stat-icon"><i class="bi bi-exclamation-triangle"></i></span><div class="value"><?= number_format($lowCount) ?></div><div class="label">Low Stock</div><div class="hint">At or below reorder threshold</div></article></a>
            <a class="stat-card-link" href="#product-table" data-quick-filter="out"><article class="stat-card with-icon <?= $outCount ? 'warning' : 'success' ?>"><span class="stat-icon"><i class="bi bi-x-octagon"></i></span><div class="value"><?= number_format($outCount) ?></div><div class="label">Out of Stock</div><div class="hint">Unavailable for checkout</div></article></a>
            <a class="stat-card-link" href="#product-table" data-quick-filter="no-barcode"><article class="stat-card with-icon <?= $withoutBarcodeCount ? 'warning' : 'success' ?>"><span class="stat-icon"><i class="bi bi-upc"></i></span><div class="value"><?= number_format($withoutBarcodeCount) ?></div><div class="label">Without Barcode</div><div class="hint">Need an internal printable barcode</div></article></a>
        </div>

        <section class="dashboard-section" id="product-table">
            <div class="section-header">
                <div>
                    <h3>Product Catalog</h3>
                    <p class="section-description">Select a row for complete planning, supplier, expiration, and barcode details.</p>
                </div>
                <div class="page-heading-actions">
                    <?php if ($canDirectAdjust): ?><button type="button" class="btn btn-quiet btn-icon" id="stock-adjust-btn"><i class="bi bi-sliders"></i>Emergency Adjustment</button><?php endif; ?>
                    <button type="button" class="btn btn-quiet btn-icon" id="add-category-btn"><i class="bi bi-folder-plus"></i>Add Category</button>
                </div>
            </div>

            <div class="table-toolbar" aria-label="Product filters">
                <label class="toolbar-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="product-search" placeholder="Search name, SKU, barcode, or brand" autocomplete="off">
                </label>
                <select id="category-filter" aria-label="Filter by category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int)$category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="stock-filter" aria-label="Filter by stock status">
                    <option value="">All stock levels</option>
                    <option value="available">Available</option>
                    <option value="low">Low stock</option>
                    <option value="out">Out of stock</option>
                    <option value="no-barcode">Without barcode</option>
                    <option value="expiring">Expiring soon</option>
                </select>
                <select id="status-filter" aria-label="Filter by product status">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <button type="button" class="btn btn-quiet btn-icon" id="column-button"><i class="bi bi-layout-three-columns"></i>Columns</button>
                <button type="button" class="btn btn-quiet btn-icon" id="save-view-button"><i class="bi bi-bookmark"></i>Save View</button>
                <select id="saved-view" aria-label="Saved filter view"><option value="">Saved views</option></select>
                <button type="button" class="btn btn-quiet btn-icon" id="clear-filters"><i class="bi bi-x-circle"></i>Clear</button>
                <button type="button" class="btn btn-quiet btn-icon" id="export-products"><i class="bi bi-download"></i>Export</button>
                <span class="toolbar-count" id="result-count"><?= number_format($totalProducts) ?> results</span>
            </div>

            <div class="bulk-bar" id="bulk-bar">
                <strong><span id="selected-count">0</span> selected</strong>
                <button class="btn btn-small btn-warning" type="button" id="bulk-print"><i class="bi bi-printer"></i> Print barcodes</button>
                <button class="btn btn-small btn-quiet" type="button" id="bulk-category"><i class="bi bi-folder"></i> Change category</button>
                <button class="btn btn-small btn-quiet" type="button" id="bulk-activate">Activate</button>
                <button class="btn btn-small btn-danger" type="button" id="bulk-deactivate">Deactivate</button>
                <button class="btn btn-small btn-quiet" type="button" id="clear-selection">Clear</button>
            </div>

            <div class="data-table-shell product-table-shell">
                <div class="data-table-scroll">
                    <table class="data-table" id="products-table">
                        <thead>
                            <tr>
                                <th style="width:44px"><input type="checkbox" id="select-all" aria-label="Select all visible products"></th>
                                <th class="sortable" data-sort="name">Product <i class="bi bi-arrow-down-up"></i></th>
                                <th class="column-barcode sortable" data-sort="barcode">SKU / Barcode <i class="bi bi-arrow-down-up"></i></th>
                                <th class="column-category sortable" data-sort="category">Category <i class="bi bi-arrow-down-up"></i></th>
                                <th class="column-price sortable" data-sort="price">Selling Price <i class="bi bi-arrow-down-up"></i></th>
                                <th class="sortable" data-sort="stock">Current Stock <i class="bi bi-arrow-down-up"></i></th>
                                <th>Status</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="product-table-body">
                        <?php foreach ($products as $product):
                            $quantity = (int)($product['quantity_on_hand'] ?? 0);
                            $threshold = max((int)($product['reorder_level'] ?? 0), (int)($product['safety_stock'] ?? 0));
                            $stockState = $quantity <= 0 ? 'out' : ($quantity <= $threshold ? 'low' : 'available');
                            $barcode = trim((string)($product['barcode'] ?? ''));
                            $expiration = (string)($product['expiration_date'] ?? '');
                            $expiring = $expiration !== '' && strtotime($expiration) !== false && strtotime($expiration) <= strtotime('+30 days') && strtotime($expiration) >= strtotime('today');
                            $status = (string)($product['status'] ?? 'active');
                            $displayStatus = $status !== 'active' ? 'inactive' : ($stockState === 'out' ? 'out' : ($stockState === 'low' ? 'low' : ($expiring ? 'expiring' : 'good')));
                            $statusText = $status !== 'active' ? 'Inactive' : ($stockState === 'out' ? 'Out of stock' : ($stockState === 'low' ? 'Low stock' : ($expiring ? 'Expiring soon' : 'Available')));
                        ?>
                            <tr data-product-id="<?= (int)$product['product_id'] ?>"
                                data-name="<?= htmlspecialchars(strtolower((string)$product['product_name']), ENT_QUOTES) ?>"
                                data-search="<?= htmlspecialchars(strtolower(implode(' ', [(string)$product['product_name'], (string)($product['variant_label'] ?? ''), (string)($product['sku'] ?? ''), $barcode, (string)($product['brand'] ?? '')])), ENT_QUOTES) ?>"
                                data-category="<?= (int)($product['category_id'] ?? 0) ?>"
                                data-category-name="<?= htmlspecialchars(strtolower((string)($product['category_name'] ?? '')), ENT_QUOTES) ?>"
                                data-stock="<?= $quantity ?>"
                                data-stock-state="<?= $stockState ?>"
                                data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>"
                                data-barcode="<?= htmlspecialchars(strtolower($barcode), ENT_QUOTES) ?>"
                                data-price="<?= (float)($product['unit_price'] ?? 0) ?>"
                                data-expiring="<?= $expiring ? '1' : '0' ?>">
                                <td><input type="checkbox" class="row-select" value="<?= (int)$product['product_id'] ?>" aria-label="Select <?= htmlspecialchars($product['product_name']) ?>"></td>
                                <td class="product-cell">
                                    <div class="product-name-line">
                                        <span class="product-thumb">
                                            <?php if (!empty($product['product_image'])): ?><img src="<?= htmlspecialchars($product['product_image']) ?>" alt="" loading="lazy" onerror="this.parentElement.innerHTML='<i class=&quot;bi bi-box-seam&quot;></i>'"><?php else: ?><i class="bi bi-box-seam"></i><?php endif; ?>
                                        </span>
                                        <span class="product-copy"><strong><?= htmlspecialchars($product['product_name']) ?></strong><span><?= htmlspecialchars($product['brand'] ?: 'No brand') ?><?= !empty($product['variant_label']) ? ' · ' . htmlspecialchars($product['variant_label']) : '' ?></span></span>
                                    </div>
                                </td>
                                <td class="column-barcode mobile-hide"><strong><?= htmlspecialchars($product['sku'] ?? '-') ?></strong><br><small><?= $barcode !== '' ? htmlspecialchars($barcode) : '<span class="tag-warning">Not assigned</span>' ?></small></td>
                                <td class="column-category mobile-hide"><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></td>
                                <td class="column-price mobile-hide">₱<?= number_format((float)($product['unit_price'] ?? 0), 2) ?></td>
                                <td class="stock-cell"><span class="stock-value"><?= number_format($quantity) ?></span><br><small>Reorder at <?= number_format((int)($product['reorder_level'] ?? 0)) ?></small></td>
                                <td class="status-cell"><span class="status-badge <?= $displayStatus ?>"><?= htmlspecialchars($statusText) ?></span></td>
                                <td class="action-cell">
                                    <div class="row-actions">
                                        <button type="button" class="icon-button view-product" data-product-id="<?= (int)$product['product_id'] ?>" title="View product details" aria-label="View <?= htmlspecialchars($product['product_name']) ?> details"><i class="bi bi-eye"></i></button>
                                        <?php if ($barcode !== ''): ?><a class="icon-button" href="print_barcodes.php?product_id=<?= (int)$product['product_id'] ?>&quantity=1" target="_blank" title="Print barcode"><i class="bi bi-printer"></i></a><?php else: ?><button type="button" class="icon-button generate-one" data-product-id="<?= (int)$product['product_id'] ?>" title="Generate barcode"><i class="bi bi-upc"></i></button><?php endif; ?>
                                        <button type="button" class="icon-button more-product" data-product-id="<?= (int)$product['product_id'] ?>" title="More actions"><i class="bi bi-three-dots"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="empty-state" id="product-empty" hidden><div><i class="bi bi-search"></i><strong>No products match these filters</strong><span>Clear the filters or add a new product.</span><br><button type="button" class="btn btn-small btn-quiet" id="empty-clear" style="margin-top:.8rem">Clear filters</button></div></div>
                </div>
                <footer class="table-pagination">
                    <div><span id="page-summary">Showing 1–<?= min(25, $totalProducts) ?> of <?= $totalProducts ?></span></div>
                    <label>Rows <select id="page-size" aria-label="Rows per page"><option>25</option><option>50</option><option>100</option></select></label>
                    <div class="pagination-buttons" id="pagination-buttons"></div>
                </footer>
            </div>
        </section>
    </main>
</div>

<div class="rm-drawer-overlay" id="product-drawer" aria-hidden="true">
    <aside class="rm-drawer" role="dialog" aria-modal="true" aria-labelledby="product-drawer-title">
        <header class="rm-drawer-header"><div><h2 id="product-drawer-title">Product details</h2><p id="product-drawer-subtitle"></p></div><button type="button" class="rm-close" data-close-drawer aria-label="Close product details"><i class="bi bi-x-lg"></i></button></header>
        <div class="rm-drawer-body" id="product-drawer-body"></div>
        <footer class="rm-drawer-footer" id="product-drawer-footer"></footer>
    </aside>
</div>

<div class="rm-modal-overlay" id="add-product-modal" aria-hidden="true">
    <section class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="add-product-title" style="width:min(760px,100%)">
        <header class="rm-modal-header"><div><h2 id="add-product-title">Add Product</h2><p>Complete the guided steps. Stock begins at zero and must be received through the approved workflow.</p></div><button type="button" class="rm-close" data-close-modal aria-label="Close add product"><i class="bi bi-x-lg"></i></button></header>
        <form method="POST" id="add-product-form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="rm-modal-body">
                <div class="wizard-progress" aria-label="Product creation steps">
                    <span class="wizard-step-indicator active" data-step="1">Basic</span><span class="wizard-step-indicator" data-step="2">Barcode</span><span class="wizard-step-indicator" data-step="3">Pricing</span><span class="wizard-step-indicator" data-step="4">Planning</span><span class="wizard-step-indicator" data-step="5">Review</span>
                </div>

                <section class="wizard-panel active" data-panel="1">
                    <div class="form-grid">
                        <div class="form-group full"><label>Product Name *</label><input name="product_name" id="product-name" required maxlength="180" autocomplete="off"><small class="field-error">Enter a product name.</small></div>
                        <div class="form-group"><label>Brand</label><input name="brand" maxlength="120"></div>
                        <div class="form-group"><label>Category *</label><select name="category_id" id="product-category" required><option value="">Select category</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option><?php endforeach; ?></select><small class="field-error">Select a category.</small></div>
                        <div class="form-group"><label>Parent Product Family</label><select name="parent_product_id"><option value="">Standalone product</option><?php foreach ($variantParents as $parent): ?><option value="<?= (int)$parent['product_id'] ?>"><?= htmlspecialchars($parent['product_name'] . ' (' . $parent['sku'] . ')') ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Variant Label</label><input name="variant_label" maxlength="100" placeholder="Example: 200 mL, Red, Large"></div>
                        <div class="form-group full"><label>Product Image</label><input name="product_image" placeholder="Optional image URL or path"></div>
                    </div>
                </section>

                <section class="wizard-panel" data-panel="2">
                    <div id="add-product-scanner-section" hidden style="margin-bottom:1rem"><div id="add-product-scanner-reader" style="max-width:420px;margin:auto"></div><p class="section-description" id="add-product-scanner-result">Point the camera at a barcode.</p></div>
                    <div class="form-grid">
                        <div class="form-group full"><label>Barcode</label><div style="display:flex;gap:.5rem;flex-wrap:wrap"><input name="barcode" id="create-barcode-input" maxlength="50" placeholder="Scan, enter, generate, or leave blank" style="flex:1;min-width:220px"><button type="button" class="btn btn-quiet" id="add-product-scan-btn"><i class="bi bi-camera"></i> Scan</button><button type="button" class="btn btn-warning" id="generate-barcode-btn"><i class="bi bi-upc"></i> Generate</button></div><small class="field-help">RetailMind automatically creates a unique internal barcode when this is blank.</small><small class="field-error">This barcode is already used by another product.</small></div>
                        <div class="form-group"><label>Case / Package Barcode</label><input name="case_barcode" maxlength="80" placeholder="Optional outer-case barcode"></div>
                        <div class="form-group"><label>Status</label><select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                    </div>
                </section>

                <section class="wizard-panel" data-panel="3">
                    <div class="form-grid">
                        <div class="form-group"><label>Cost Price *</label><input type="number" step="0.01" min="0" name="cost_price" id="cost-price" required><small class="field-error">Enter a valid cost price.</small></div>
                        <div class="form-group"><label>Selling Price *</label><input type="number" step="0.01" min="0" name="selling_price" id="selling-price" required><small class="field-error">Selling price must not be lower than cost.</small></div>
                        <div class="form-group"><label>Base Unit</label><input name="base_unit" value="piece" placeholder="piece, bottle, pack"></div>
                        <div class="form-group"><label>Receiving Unit</label><input name="receiving_unit" value="package" placeholder="case, box, carton"></div>
                    </div>
                </section>

                <section class="wizard-panel" data-panel="4">
                    <div class="form-grid">
                        <div class="form-group"><label>Reorder Level</label><input type="number" min="0" name="reorder_level" value="10"></div>
                        <div class="form-group"><label>Safety Stock</label><input type="number" min="0" name="safety_stock" value="0"></div>
                        <div class="form-group"><label>Minimum Order Quantity</label><input type="number" min="1" name="minimum_order_quantity" value="1"></div>
                        <div class="form-group"><label>Units per Package/Case</label><input type="number" min="1" name="units_per_package" value="1"></div>
                        <div class="form-group"><label>Preferred Supplier</label><input name="preferred_supplier" placeholder="Supplier name"></div>
                        <div class="form-group"><label>Supplier Lead Time (Days)</label><input type="number" min="0" name="supplier_lead_time_days" value="7"></div>
                        <div class="form-group full"><label>Expiration Date</label><input type="date" name="expiration_date"></div>
                    </div>
                </section>

                <section class="wizard-panel" data-panel="5">
                    <div class="detail-grid" id="product-review"></div>
                    <div style="margin-top:1rem;padding:.85rem;border-radius:11px;background:#eff6ff;color:#1e3a8a"><i class="bi bi-info-circle"></i> The product will start at zero stock. Use Stock Receiving after creation.</div>
                    <div class="form-grid" style="margin-top:1rem">
                        <label class="form-group full" style="display:flex;align-items:center;gap:.55rem"><input type="checkbox" name="print_after_create" value="1" checked style="width:auto">Open printable barcode labels after saving</label>
                        <div class="form-group"><label>Number of labels</label><input type="number" name="label_quantity" value="1" min="1" max="200"></div>
                    </div>
                </section>
            </div>
            <footer class="rm-modal-actions"><button type="button" class="btn btn-quiet" data-close-modal>Cancel</button><button type="button" class="btn btn-quiet" id="wizard-back" hidden>Back</button><button type="button" class="btn" id="wizard-next">Next</button><button type="submit" class="btn btn-success" id="wizard-save" hidden><i class="bi bi-check2"></i> Save Product</button></footer>
        </form>
    </section>
</div>

<div class="rm-modal-overlay" id="add-category-modal" aria-hidden="true">
    <section class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="category-title" style="width:min(440px,100%)">
        <header class="rm-modal-header"><div><h2 id="category-title">Add Category</h2><p>Create a clear category for organizing and filtering products.</p></div><button type="button" class="rm-close" data-close-modal><i class="bi bi-x-lg"></i></button></header>
        <form method="POST"><div class="rm-modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="create_category"><div class="form-group"><label>Category Name</label><input type="text" name="category_name" required maxlength="100" placeholder="Example: Beverages"></div></div><footer class="rm-modal-actions"><button type="button" class="btn btn-quiet" data-close-modal>Cancel</button><button class="btn btn-success" type="submit">Save Category</button></footer></form>
    </section>
</div>

<?php if ($canDirectAdjust): ?>
<div class="rm-modal-overlay" id="stock-adjust-modal" aria-hidden="true">
    <section class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="adjust-title" style="width:min(520px,100%)">
        <header class="rm-modal-header"><div><h2 id="adjust-title">Emergency Stock Adjustment</h2><p>Use only after a verified count. The reason is recorded in the audit log.</p></div><button type="button" class="rm-close" data-close-modal><i class="bi bi-x-lg"></i></button></header>
        <form method="POST" data-confirm="This will directly change the current stock quantity and create an audit record." data-confirm-title="Confirm emergency adjustment" data-confirm-button="Apply adjustment" data-confirm-danger="1"><div class="rm-modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="adjust"><div class="form-group"><label>Product</label><select name="product_id" id="adjust-product-select" required><option value="">Select product</option><?php foreach ($activeProducts as $product): ?><option value="<?= (int)$product['product_id'] ?>"><?= htmlspecialchars($product['sku'] . ' — ' . $product['product_name']) ?> (<?= (int)$product['quantity_on_hand'] ?> on hand)</option><?php endforeach; ?></select></div><div class="form-group"><label>Quantity Change</label><input type="number" name="qty_change" required placeholder="Use 5 to add or -3 to remove"></div><div class="form-group"><label>Specific Approved Reason</label><input name="adjustment_reason" minlength="8" required placeholder="Example: Verified physical-count correction"></div></div><footer class="rm-modal-actions"><button type="button" class="btn btn-quiet" data-close-modal>Cancel</button><button class="btn btn-danger" type="submit">Apply Adjustment</button></footer></form>
    </section>
</div>
<?php endif; ?>

<div class="rm-modal-overlay" id="bulk-category-modal" aria-hidden="true">
    <section class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-category-title" style="width:min(440px,100%)"><header class="rm-modal-header"><div><h2 id="bulk-category-title">Change Category</h2><p>Apply one category to every selected product.</p></div><button type="button" class="rm-close" data-close-modal><i class="bi bi-x-lg"></i></button></header><form method="POST" id="bulk-category-form"><div class="rm-modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="bulk_category"><div id="bulk-category-ids"></div><div class="form-group"><label>New Category</label><select name="category_id" required><option value="">Select category</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option><?php endforeach; ?></select></div></div><footer class="rm-modal-actions"><button type="button" class="btn btn-quiet" data-close-modal>Cancel</button><button class="btn" type="submit">Update Category</button></footer></form></section>
</div>

<form method="POST" id="bulk-status-form" hidden><?= csrf_field() ?><input type="hidden" name="action" value="bulk_status"><input type="hidden" name="status" id="bulk-status-value"><div id="bulk-status-ids"></div></form>
<form method="POST" id="generate-barcode-form" target="_blank" hidden><?= csrf_field() ?><input type="hidden" name="action" value="generate_barcode"><input type="hidden" name="product_id" id="generate-product-id"><input type="hidden" name="label_quantity" value="1"></form>

<div class="rm-modal-overlay" id="column-modal" aria-hidden="true">
    <section class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="column-title" style="width:min(420px,100%)"><header class="rm-modal-header"><div><h2 id="column-title">Visible Columns</h2><p>Choose which optional columns appear in the table.</p></div><button type="button" class="rm-close" data-close-modal><i class="bi bi-x-lg"></i></button></header><div class="rm-modal-body"><label style="display:flex;gap:.6rem;margin-bottom:.7rem"><input type="checkbox" data-column="barcode" checked> SKU / Barcode</label><label style="display:flex;gap:.6rem;margin-bottom:.7rem"><input type="checkbox" data-column="category" checked> Category</label><label style="display:flex;gap:.6rem"><input type="checkbox" data-column="price" checked> Selling Price</label></div><footer class="rm-modal-actions"><button type="button" class="btn" data-close-modal>Done</button></footer></section>
</div>

<script src="https://unpkg.com/html5-qrcode"></script>
<script>
const productData = <?= json_encode(array_values($products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const productsById = Object.fromEntries(productData.map(product => [Number(product.product_id), product]));
const existingBarcodes = new Set(productData.map(product => String(product.barcode || '').trim().toLowerCase()).filter(Boolean));
const existingProductNames = new Set(productData.map(product => String(product.product_name || '').trim().toLowerCase()).filter(Boolean));
const productRows = Array.from(document.querySelectorAll('#product-table-body tr'));
const searchInput = document.getElementById('product-search');
const categoryFilter = document.getElementById('category-filter');
const stockFilter = document.getElementById('stock-filter');
const statusFilter = document.getElementById('status-filter');
const resultCount = document.getElementById('result-count');
const pageSummary = document.getElementById('page-summary');
const pageSizeInput = document.getElementById('page-size');
const paginationButtons = document.getElementById('pagination-buttons');
const emptyState = document.getElementById('product-empty');
const table = document.getElementById('products-table');
let filteredRows = productRows.slice();
let currentPage = 1;
let sortKey = 'name';
let sortDirection = 1;
const selectedIds = new Set();

function openModal(id) { RetailMindUI.openOverlay(document.getElementById(id)); }
function closeModal(node) { RetailMindUI.closeOverlay(node.closest('.rm-modal-overlay, .rm-drawer-overlay')); }
document.querySelectorAll('[data-close-modal], [data-close-drawer]').forEach(button => button.addEventListener('click', () => closeModal(button)));
document.querySelectorAll('.rm-modal-overlay, .rm-drawer-overlay').forEach(overlay => overlay.addEventListener('click', event => { if (event.target === overlay) RetailMindUI.closeOverlay(overlay); }));

document.getElementById('add-product-btn').addEventListener('click', () => openModal('add-product-modal'));
document.getElementById('add-category-btn').addEventListener('click', () => openModal('add-category-modal'));
const stockAdjustButton = document.getElementById('stock-adjust-btn');
if (stockAdjustButton) stockAdjustButton.addEventListener('click', () => openModal('stock-adjust-modal'));
document.getElementById('column-button').addEventListener('click', () => openModal('column-modal'));

function getFilters() {
    return { q: searchInput.value.trim().toLowerCase(), category: categoryFilter.value, stock: stockFilter.value, status: statusFilter.value };
}
function applyFilters(resetPage = true) {
    const filters = getFilters();
    filteredRows = productRows.filter(row => {
        const searchMatch = !filters.q || row.dataset.search.includes(filters.q);
        const categoryMatch = !filters.category || row.dataset.category === filters.category;
        const statusMatch = !filters.status || row.dataset.status === filters.status;
        let stockMatch = true;
        if (filters.stock === 'available') stockMatch = row.dataset.stockState === 'available';
        if (filters.stock === 'low') stockMatch = row.dataset.stockState === 'low';
        if (filters.stock === 'out') stockMatch = row.dataset.stockState === 'out';
        if (filters.stock === 'no-barcode') stockMatch = !row.dataset.barcode;
        if (filters.stock === 'expiring') stockMatch = row.dataset.expiring === '1';
        return searchMatch && categoryMatch && statusMatch && stockMatch;
    });
    filteredRows.sort((a, b) => {
        const values = {
            name: [a.dataset.name, b.dataset.name], barcode: [a.dataset.barcode, b.dataset.barcode],
            category: [a.dataset.categoryName, b.dataset.categoryName], price: [Number(a.dataset.price), Number(b.dataset.price)], stock: [Number(a.dataset.stock), Number(b.dataset.stock)]
        }[sortKey] || [a.dataset.name, b.dataset.name];
        return (typeof values[0] === 'number' ? values[0] - values[1] : String(values[0]).localeCompare(String(values[1]))) * sortDirection;
    });
    filteredRows.forEach(row => row.parentElement.appendChild(row));
    if (resetPage) currentPage = 1;
    renderPage();
}
function renderPage() {
    const size = Number(pageSizeInput.value || 25);
    const pages = Math.max(1, Math.ceil(filteredRows.length / size));
    currentPage = Math.min(currentPage, pages);
    const start = (currentPage - 1) * size;
    const visibleSet = new Set(filteredRows.slice(start, start + size));
    productRows.forEach(row => row.hidden = !visibleSet.has(row));
    table.hidden = filteredRows.length === 0;
    emptyState.hidden = filteredRows.length !== 0;
    resultCount.textContent = `${filteredRows.length} result${filteredRows.length === 1 ? '' : 's'}`;
    pageSummary.textContent = filteredRows.length ? `Showing ${start + 1}–${Math.min(start + size, filteredRows.length)} of ${filteredRows.length}` : 'Showing 0 results';
    paginationButtons.innerHTML = '';
    const makeButton = (label, page, disabled = false, active = false) => {
        const button = document.createElement('button'); button.type = 'button'; button.textContent = label; button.disabled = disabled; button.classList.toggle('active', active); button.addEventListener('click', () => { currentPage = page; renderPage(); document.getElementById('product-table').scrollIntoView({behavior:'smooth'}); }); return button;
    };
    paginationButtons.appendChild(makeButton('‹', Math.max(1, currentPage - 1), currentPage === 1));
    const first = Math.max(1, currentPage - 2); const last = Math.min(pages, first + 4);
    for (let page = first; page <= last; page++) paginationButtons.appendChild(makeButton(String(page), page, false, page === currentPage));
    paginationButtons.appendChild(makeButton('›', Math.min(pages, currentPage + 1), currentPage === pages));
    syncSelectAll();
}
[searchInput, categoryFilter, stockFilter, statusFilter].forEach(input => input.addEventListener(input.tagName === 'INPUT' ? 'input' : 'change', () => applyFilters()));
pageSizeInput.addEventListener('change', () => applyFilters());
document.getElementById('clear-filters').addEventListener('click', clearFilters);
document.getElementById('empty-clear').addEventListener('click', clearFilters);
function clearFilters() { searchInput.value = ''; categoryFilter.value = ''; stockFilter.value = ''; statusFilter.value = ''; applyFilters(); }
document.querySelectorAll('[data-quick-filter]').forEach(card => card.addEventListener('click', () => { const filter = card.dataset.quickFilter; clearFilters(); if (filter !== 'all') stockFilter.value = filter; applyFilters(); }));
document.querySelectorAll('th.sortable').forEach(header => header.addEventListener('click', () => { if (sortKey === header.dataset.sort) sortDirection *= -1; else { sortKey = header.dataset.sort; sortDirection = 1; } applyFilters(false); }));

function selectedValues() { return Array.from(selectedIds); }
function updateBulkBar() { document.getElementById('selected-count').textContent = selectedIds.size; document.getElementById('bulk-bar').classList.toggle('show', selectedIds.size > 0); }
function syncSelectAll() { const visible = filteredRows.filter(row => !row.hidden); const allSelected = visible.length > 0 && visible.every(row => selectedIds.has(Number(row.dataset.productId))); document.getElementById('select-all').checked = allSelected; }
document.querySelectorAll('.row-select').forEach(box => box.addEventListener('change', () => { const id = Number(box.value); box.checked ? selectedIds.add(id) : selectedIds.delete(id); updateBulkBar(); syncSelectAll(); }));
document.getElementById('select-all').addEventListener('change', event => { filteredRows.filter(row => !row.hidden).forEach(row => { const id = Number(row.dataset.productId); const box = row.querySelector('.row-select'); box.checked = event.target.checked; event.target.checked ? selectedIds.add(id) : selectedIds.delete(id); }); updateBulkBar(); });
document.getElementById('clear-selection').addEventListener('click', () => { selectedIds.clear(); document.querySelectorAll('.row-select').forEach(box => box.checked = false); updateBulkBar(); syncSelectAll(); });
function appendSelectedInputs(container) { container.innerHTML = ''; selectedValues().forEach(id => { const input = document.createElement('input'); input.type = 'hidden'; input.name = 'product_ids[]'; input.value = id; container.appendChild(input); }); }
document.getElementById('bulk-category').addEventListener('click', () => { appendSelectedInputs(document.getElementById('bulk-category-ids')); openModal('bulk-category-modal'); });
async function submitBulkStatus(status) { const ok = await RetailMindUI.confirm({ title: `${status === 'active' ? 'Activate' : 'Deactivate'} selected products`, message: `This will update ${selectedIds.size} selected product(s).`, confirmText: status === 'active' ? 'Activate' : 'Deactivate', danger: status !== 'active' }); if (!ok) return; appendSelectedInputs(document.getElementById('bulk-status-ids')); document.getElementById('bulk-status-value').value = status; document.getElementById('bulk-status-form').submit(); }
document.getElementById('bulk-activate').addEventListener('click', () => submitBulkStatus('active'));
document.getElementById('bulk-deactivate').addEventListener('click', () => submitBulkStatus('inactive'));
document.getElementById('bulk-print').addEventListener('click', () => { const ids = selectedValues(); if (!ids.length) return; window.open(`print_barcodes.php?product_ids=${encodeURIComponent(ids.join(','))}&quantity=1`, '_blank'); });

function escapeHtml(value) { return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char])); }
function safeLink(value) { const url = String(value || '').trim(); return /^(https?:\/\/|\/)/i.test(url) ? url : ''; }
function formatMoney(value) { return `₱${Number(value || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`; }
function safe(value, fallback = '—') { const text = String(value ?? '').trim(); return text || fallback; }
function openProductDrawer(id) {
    const product = productsById[Number(id)]; if (!product) return;
    document.getElementById('product-drawer-title').textContent = product.product_name + (product.variant_label ? ` — ${product.variant_label}` : '');
    document.getElementById('product-drawer-subtitle').textContent = `${escapeHtml(safe(product.sku))} · ${safe(product.category_name, 'Uncategorized')}`;
    const quantity = Number(product.quantity_on_hand || 0); const threshold = Math.max(Number(product.reorder_level || 0), Number(product.safety_stock || 0));
    const stockLabel = quantity <= 0 ? 'Out of stock' : quantity <= threshold ? 'Low stock' : 'Available';
    document.getElementById('product-drawer-body').innerHTML = `<div class="detail-grid">
        <div class="detail-item"><span>Current Stock</span><strong>${quantity.toLocaleString()} — ${stockLabel}</strong></div><div class="detail-item"><span>Status</span><strong>${escapeHtml(safe(product.status))}</strong></div>
        <div class="detail-item"><span>SKU</span><strong>${escapeHtml(safe(product.sku))}</strong></div><div class="detail-item"><span>Barcode</span><strong>${escapeHtml(safe(product.barcode, 'Not assigned'))}</strong></div>
        <div class="detail-item"><span>Cost Price</span><strong>${formatMoney(product.cost_price)}</strong></div><div class="detail-item"><span>Selling Price</span><strong>${formatMoney(product.unit_price)}</strong></div>
        <div class="detail-item"><span>Reorder / Safety</span><strong>${Number(product.reorder_level || 0)} / ${Number(product.safety_stock || 0)}</strong></div><div class="detail-item"><span>MOQ / Units per Case</span><strong>${Number(product.minimum_order_quantity || 1)} / ${Number(product.units_per_package || 1)}</strong></div>
        <div class="detail-item"><span>Lead Time</span><strong>${Number(product.supplier_lead_time_days || 0)} day(s)</strong></div><div class="detail-item"><span>Preferred Supplier</span><strong>${escapeHtml(safe(product.preferred_supplier || product.supplier))}</strong></div>
        <div class="detail-item"><span>Expiration</span><strong>${escapeHtml(safe(product.expiration_date))}</strong></div><div class="detail-item"><span>Units</span><strong>${escapeHtml(safe(product.base_unit))} / ${escapeHtml(safe(product.receiving_unit))}</strong></div>
        <div class="detail-item full"><span>Product Image</span><strong>${safeLink(product.product_image) ? `<a href="${escapeHtml(safeLink(product.product_image))}" target="_blank" rel="noopener">Open product image</a>` : 'No image assigned'}</strong></div>
    </div><div class="drawer-section"><h3>Recommended actions</h3><div class="attention-list">${quantity <= threshold ? '<div class="attention-item"><span class="attention-icon"><i class="bi bi-box-arrow-in-down"></i></span><span class="attention-copy"><strong>Replenishment needed</strong><span>Stock is at or below the planning threshold.</span></span><a class="btn btn-small" href="../report/stock_receiving.php">Receive</a></div>' : '<div class="attention-item"><span class="attention-icon" style="background:#dcfce7;color:#166534"><i class="bi bi-check2"></i></span><span class="attention-copy"><strong>Stock level is healthy</strong><span>No immediate replenishment action is required.</span></span></div>'}</div></div>`;
    const footer = document.getElementById('product-drawer-footer');
    footer.innerHTML = product.barcode ? `<a class="btn btn-warning" target="_blank" href="print_barcodes.php?product_id=${Number(product.product_id)}&quantity=1"><i class="bi bi-printer"></i> Print Barcode</a>` : `<button type="button" class="btn btn-success drawer-generate" data-product-id="${Number(product.product_id)}"><i class="bi bi-upc"></i> Generate Barcode</button>`;
    footer.innerHTML += `<a class="btn btn-quiet" href="../report/stock_receiving.php"><i class="bi bi-box-arrow-in-down"></i> Stock Receiving</a>`;
    const generate = footer.querySelector('.drawer-generate'); if (generate) generate.addEventListener('click', () => generateBarcode(generate.dataset.productId));
    RetailMindUI.openOverlay(document.getElementById('product-drawer'));
}
document.querySelectorAll('.view-product, .more-product').forEach(button => button.addEventListener('click', () => openProductDrawer(button.dataset.productId)));
function generateBarcode(id) { document.getElementById('generate-product-id').value = id; document.getElementById('generate-barcode-form').submit(); }
document.querySelectorAll('.generate-one').forEach(button => button.addEventListener('click', () => generateBarcode(button.dataset.productId)));

// Column preferences
const columnPreferences = JSON.parse(localStorage.getItem('retailmind_product_columns') || '{}');
document.querySelectorAll('[data-column]').forEach(box => { if (columnPreferences[box.dataset.column] === false) box.checked = false; function apply() { document.querySelectorAll(`.column-${box.dataset.column}`).forEach(cell => cell.hidden = !box.checked); columnPreferences[box.dataset.column] = box.checked; localStorage.setItem('retailmind_product_columns', JSON.stringify(columnPreferences)); } box.addEventListener('change', apply); apply(); });

// Saved view
const savedViewSelect = document.getElementById('saved-view');
function loadSavedViews() { const views = JSON.parse(localStorage.getItem('retailmind_product_views') || '[]'); savedViewSelect.innerHTML = '<option value="">Saved views</option>' + views.map((view,index) => `<option value="${index}">${escapeHtml(view.name)}</option>`).join(''); return views; }
loadSavedViews();
document.getElementById('save-view-button').addEventListener('click', () => { const views = loadSavedViews(); const filters = getFilters(); const nameParts = [filters.q ? `Search: ${filters.q}` : '', categoryFilter.selectedOptions[0]?.text !== 'All categories' ? categoryFilter.selectedOptions[0].text : '', stockFilter.selectedOptions[0]?.text !== 'All stock levels' ? stockFilter.selectedOptions[0].text : '', statusFilter.selectedOptions[0]?.text !== 'All statuses' ? statusFilter.selectedOptions[0].text : ''].filter(Boolean); views.push({name: nameParts.join(' · ') || `View ${views.length + 1}`, filters}); localStorage.setItem('retailmind_product_views', JSON.stringify(views.slice(-8))); loadSavedViews(); RetailMindUI.toast('The current filters were saved on this device.', 'success', 'View saved'); });
savedViewSelect.addEventListener('change', () => { if (savedViewSelect.value === '') return; const selectedViewIndex = Number(savedViewSelect.value); const views = JSON.parse(localStorage.getItem('retailmind_product_views') || '[]'); const view = views[selectedViewIndex]; if (!view) return; searchInput.value = view.filters.q || ''; categoryFilter.value = view.filters.category || ''; stockFilter.value = view.filters.stock || ''; statusFilter.value = view.filters.status || ''; applyFilters(); });

// Export filtered products
function csvCell(value) { return `"${String(value ?? '').replace(/"/g, '""')}"`; }
document.getElementById('export-products').addEventListener('click', () => { const header = ['Product','SKU','Barcode','Brand','Category','Selling Price','Current Stock','Status']; const lines = [header.map(csvCell).join(',')]; filteredRows.forEach(row => { const product = productsById[Number(row.dataset.productId)]; lines.push([product.product_name, product.sku, product.barcode, product.brand, product.category_name, product.unit_price, product.quantity_on_hand, product.status].map(csvCell).join(',')); }); const blob = new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8'}); const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = `retailmind-products-${new Date().toISOString().slice(0,10)}.csv`; link.click(); URL.revokeObjectURL(link.href); });

// Product wizard and validation
let wizardStep = 1;
const addProductForm = document.getElementById('add-product-form');
const nextButton = document.getElementById('wizard-next');
const backButton = document.getElementById('wizard-back');
const saveButton = document.getElementById('wizard-save');
function setWizardStep(step) { wizardStep = Math.max(1, Math.min(5, step)); document.querySelectorAll('.wizard-panel').forEach(panel => panel.classList.toggle('active', Number(panel.dataset.panel) === wizardStep)); document.querySelectorAll('.wizard-step-indicator').forEach((indicator,index) => { indicator.classList.toggle('active', index + 1 === wizardStep); indicator.classList.toggle('complete', index + 1 < wizardStep); }); backButton.hidden = wizardStep === 1; nextButton.hidden = wizardStep === 5; saveButton.hidden = wizardStep !== 5; if (wizardStep === 5) renderReview(); }
function validateStep(step) { let valid = true; const panel = document.querySelector(`.wizard-panel[data-panel="${step}"]`); panel.querySelectorAll('.form-group').forEach(group => group.classList.remove('invalid')); if (step === 1) { const name = document.getElementById('product-name'); const category = document.getElementById('product-category'); if (!name.value.trim()) { name.closest('.form-group').classList.add('invalid'); valid = false; } if (!category.value) { category.closest('.form-group').classList.add('invalid'); valid = false; } if (existingProductNames.has(name.value.trim().toLowerCase())) RetailMindUI.toast('A product with the same name already exists. Add a variant label when appropriate.', 'warning', 'Possible duplicate'); } if (step === 2) { const barcode = document.getElementById('create-barcode-input'); if (barcode.value.trim() && existingBarcodes.has(barcode.value.trim().toLowerCase())) { barcode.closest('.form-group').classList.add('invalid'); valid = false; } } if (step === 3) { const cost = document.getElementById('cost-price'); const selling = document.getElementById('selling-price'); if (cost.value === '' || Number(cost.value) < 0) { cost.closest('.form-group').classList.add('invalid'); valid = false; } if (selling.value === '' || Number(selling.value) < Number(cost.value)) { selling.closest('.form-group').classList.add('invalid'); valid = false; } } return valid; }
nextButton.addEventListener('click', () => { if (validateStep(wizardStep)) setWizardStep(wizardStep + 1); });
backButton.addEventListener('click', () => setWizardStep(wizardStep - 1));
addProductForm.addEventListener('submit', event => { for (let step = 1; step <= 3; step++) { if (!validateStep(step)) { event.preventDefault(); setWizardStep(step); RetailMindUI.toast('Review the highlighted fields before saving.', 'error'); return; } } saveButton.disabled = true; saveButton.innerHTML = '<i class="bi bi-arrow-repeat"></i> Saving...'; });
function renderReview() { const data = new FormData(addProductForm); const categoryText = document.getElementById('product-category').selectedOptions[0]?.text || '—'; const items = [['Product', data.get('product_name')], ['Brand', data.get('brand') || '—'], ['Category', categoryText], ['Barcode', data.get('barcode') || 'Generate automatically'], ['Cost Price', formatMoney(data.get('cost_price'))], ['Selling Price', formatMoney(data.get('selling_price'))], ['Reorder / Safety', `${data.get('reorder_level')} / ${data.get('safety_stock')}`], ['Supplier', data.get('preferred_supplier') || '—']]; document.getElementById('product-review').innerHTML = items.map(([label,value]) => `<div class="detail-item"><span>${label}</span><strong>${String(value).replace(/[<>]/g,'')}</strong></div>`).join(''); }

// Camera scan and internal barcode generation
const barcodeInput = document.getElementById('create-barcode-input');
let productScanner = null;
document.getElementById('generate-barcode-btn').addEventListener('click', () => { const values = new Uint32Array(1); if (window.crypto?.getRandomValues) window.crypto.getRandomValues(values); else values[0] = Math.floor(Math.random()*99999999); const now = new Date(); barcodeInput.value = `RM${String(now.getFullYear()).slice(-2)}${String(now.getMonth()+1).padStart(2,'0')}${String(now.getDate()).padStart(2,'0')}${String(values[0] % 100000000).padStart(8,'0')}`; barcodeInput.focus(); });
document.getElementById('add-product-scan-btn').addEventListener('click', async () => { const section = document.getElementById('add-product-scanner-section'); if (!window.Html5Qrcode) { RetailMindUI.toast('The camera scanner library is unavailable.', 'error'); return; } section.hidden = false; if (!productScanner) productScanner = new Html5Qrcode('add-product-scanner-reader'); try { await productScanner.start({facingMode:'environment'}, {fps:10, qrbox:{width:250,height:160}}, async decoded => { barcodeInput.value = decoded; document.getElementById('add-product-scanner-result').textContent = `Detected: ${decoded}`; if (navigator.vibrate) navigator.vibrate(120); await productScanner.stop(); section.hidden = true; }, () => {}); } catch (error) { RetailMindUI.toast(String(error), 'error', 'Camera unavailable'); section.hidden = true; } });

const initialParams = new URLSearchParams(window.location.search);
if (initialParams.get('q')) searchInput.value = initialParams.get('q');
if (initialParams.get('category')) categoryFilter.value = initialParams.get('category');
if (initialParams.get('stock')) stockFilter.value = initialParams.get('stock');
if (initialParams.get('status')) statusFilter.value = initialParams.get('status');
applyFilters(false);
updateBulkBar();
</script>
</body>
</html>
