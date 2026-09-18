<?php
// components/inventory_management/products.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/ProductService.php';
require_role(['admin', 'super_admin', 'inventory_manager']);

$canManageInventory = has_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
$canDirectAdjust = $canManageInventory;
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

    require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);

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
            // Handle image upload
            $productImage = '';
            if (!empty($_FILES['product_image']['name'])) {
                $file = $_FILES['product_image'];
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $maxSize = 5 * 1024 * 1024; // 5MB

                // Validate file
                if (!in_array($file['type'], $allowedTypes, true)) {
                    throw new RuntimeException('Only image files (JPEG, PNG, GIF, WebP) are allowed.');
                }
                if ($file['size'] > $maxSize) {
                    throw new RuntimeException('Image file must not exceed 5MB.');
                }
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Image upload failed: ' . $file['error']);
                }

                // Generate unique filename
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = bin2hex(random_bytes(16)) . '.' . $ext;
                $uploadDir = __DIR__ . '/../../../backend/storage/images';
                $uploadPath = $uploadDir . '/' . $filename;

                // Create directory if it doesn't exist
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Move uploaded file
                if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    throw new RuntimeException('Failed to save the uploaded image.');
                }

                $productImage = 'storage/images/' . $filename;
            }

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
                'product_image' => $productImage,
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
            [$bulkScopeSql, $bulkScopeParams] = store_product_scope('products');

            if ($action === 'bulk_status') {
                $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : '';
                if ($status === '') {
                    throw new RuntimeException('Choose a valid product status.');
                }
                $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE product_id IN ($placeholders){$bulkScopeSql}");
                $stmt->execute(array_merge([$status], $productIds, $bulkScopeParams));
                log_activity($pdo, (int)$_SESSION['user_id'], 'Bulk product status updated to ' . $status . ' for ' . count($productIds) . ' products');
                redirect_products('success', count($productIds) . ' product(s) updated to ' . $status . '.');
            }

            $categoryId = (int)($_POST['category_id'] ?? 0);
            if ($categoryId <= 0) {
                throw new RuntimeException('Choose a category.');
            }
            $stmt = $pdo->prepare("UPDATE products SET category_id = ? WHERE product_id IN ($placeholders){$bulkScopeSql}");
            $stmt->execute(array_merge([$categoryId], $productIds, $bulkScopeParams));
            log_activity($pdo, (int)$_SESSION['user_id'], 'Bulk product category updated for ' . count($productIds) . ' products');
            redirect_products('success', 'Category updated for ' . count($productIds) . ' product(s).');
        } catch (Throwable $e) {
            redirect_products('error', $e->getMessage());
        }
    }
}

$categories = $productService->getCategories();
$selectedBranchId = selected_inventory_branch_id($pdo);
$branches = $pdo->query("SELECT branch_id, branch_name, branch_code FROM branches WHERE status = 'active' ORDER BY branch_name")->fetchAll();
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
    <style>
        /* Fix scrolling in wizard modal */
        #add-product-modal .rm-modal-body {
            max-height: 65vh;
            overflow-y: auto;
            overflow-x: hidden;
        }

        #add-product-modal .wizard-panel {
            min-height: auto;
        }

        .product-add-menu {
            position: relative;
            margin-left: auto;
        }

        .product-add-trigger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            min-width: 108px;
        }

        .product-add-trigger .bi-chevron-down {
            font-size: .75rem;
            transition: transform .18s ease;
        }

        .product-add-trigger[aria-expanded="true"] .bi-chevron-down {
            transform: rotate(180deg);
        }

        .product-add-menu-list {
            position: absolute;
            z-index: 30;
            top: calc(100% + .5rem);
            right: 0;
            display: grid;
            width: min(260px, calc(100vw - 2rem));
            padding: .4rem;
            background: var(--card-bg, #fff);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 16px 36px rgba(15, 23, 42, .16);
        }

        .product-add-menu-list[hidden] {
            display: none;
        }

        .product-add-menu-item {
            display: grid;
            grid-template-columns: 34px minmax(0, 1fr);
            align-items: center;
            gap: .7rem;
            width: 100%;
            min-height: 48px;
            padding: .55rem .65rem;
            color: var(--text);
            background: transparent;
            border: 0;
            border-radius: 6px;
            text-align: left;
            text-decoration: none;
            cursor: pointer;
        }

        .product-add-menu-item:hover,
        .product-add-menu-item:focus-visible {
            background: #f1f5f9;
            outline: none;
        }

        .product-add-menu-item i {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            color: #2563eb;
            background: #eff6ff;
            border-radius: 6px;
        }

        @media (max-width: 768px) {
            .product-add-menu {
                width: 100%;
                margin-left: 0;
            }

            .product-add-trigger,
            .product-add-menu-list {
                width: 100%;
            }

            .product-add-menu-list {
                right: auto;
                left: 0;
            }
        }

        .product-drawer-tabs {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .35rem;
            padding: .7rem 1rem;
            background: var(--card-bg, #fff);
            border-bottom: 1px solid var(--border);
        }

        .product-drawer-tab {
            min-height: 42px;
            padding: .5rem .65rem;
            color: var(--muted);
            background: transparent;
            border: 1px solid transparent;
            border-radius: 6px;
            font: inherit;
            font-size: .84rem;
            font-weight: 700;
            cursor: pointer;
        }

        .product-drawer-tab:hover {
            color: var(--text);
            background: #f1f5f9;
        }

        .product-drawer-tab.is-active {
            color: #1d4ed8;
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .product-drawer-panel[hidden],
        #product-drawer-footer[hidden] {
            display: none;
        }

        .product-quantity-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .catalog-toolbar {
            margin-bottom: 1rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            overflow: visible;
        }

        .catalog-toolbar-primary,
        .catalog-filter-row {
            display: flex;
            align-items: end;
            gap: .65rem;
            padding: .75rem;
        }

        .catalog-filter-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr)) auto;
            border-top: 1px solid var(--border);
            background: #fbfcfe;
        }

        .catalog-filter-row[hidden] {
            display: none;
        }

        .catalog-view-controls,
        .catalog-utility-controls {
            display: flex;
            gap: .35rem;
        }

        .catalog-icon-button {
            width: 42px;
            height: 42px;
            display: inline-grid;
            place-items: center;
            flex: 0 0 42px;
            padding: 0;
            color: var(--muted);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 6px;
            cursor: pointer;
        }

        .catalog-icon-button:hover,
        .catalog-icon-button.is-active {
            color: #2563eb;
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .catalog-search {
            position: relative;
            flex: 1 1 300px;
            min-width: 220px;
        }

        .catalog-search i {
            position: absolute;
            top: 50%;
            left: .8rem;
            color: var(--muted);
            transform: translateY(-50%);
        }

        .catalog-search input {
            width: 100%;
            min-height: 42px;
            padding: .65rem .8rem .65rem 2.25rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #f8fafc;
        }

        .catalog-field {
            display: grid;
            gap: .3rem;
            min-width: 150px;
        }

        .catalog-field > span {
            color: var(--muted);
            font-size: .68rem;
            font-weight: 700;
        }

        .catalog-field select {
            width: 100%;
            min-height: 42px;
            padding: .55rem 2rem .55rem .7rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background-color: #fff;
            color: var(--text);
        }

        .catalog-show-field,
        .catalog-sort-field {
            flex: 0 1 180px;
        }

        .catalog-filter-button {
            min-height: 42px;
            white-space: nowrap;
        }

        .catalog-toolbar .product-add-menu {
            flex: 0 0 auto;
            margin-left: 0;
        }

        .catalog-toolbar .product-add-trigger {
            min-height: 42px;
            background: #2563eb;
        }

        .catalog-toolbar .product-add-trigger:hover {
            background: #1d4ed8;
        }

        .catalog-utility-controls {
            align-items: end;
            justify-content: flex-end;
        }

        .catalog-utility-controls select {
            min-width: 130px;
            min-height: 42px;
            padding: .55rem .7rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #fff;
            color: var(--text);
        }

        @media (max-width: 1100px) {
            .catalog-toolbar-primary {
                flex-wrap: wrap;
            }

            .catalog-filter-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .catalog-utility-controls {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 640px) {
            .catalog-toolbar-primary,
            .catalog-filter-row {
                display: grid;
                grid-template-columns: 1fr;
                align-items: stretch;
            }

            .catalog-search,
            .catalog-field,
            .catalog-show-field,
            .catalog-sort-field {
                min-width: 0;
                width: 100%;
            }

            .catalog-view-controls,
            .catalog-utility-controls {
                grid-column: auto;
                justify-content: flex-start;
                flex-wrap: wrap;
            }
        }

        @media (max-width: 480px) {
            .product-drawer-tabs {
                padding-inline: .65rem;
            }

            .product-drawer-tab {
                padding-inline: .35rem;
                font-size: .76rem;
            }
        }
    </style>
</head>

<body class="products-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content" id="product-table">
                <div class="catalog-toolbar" aria-label="Product catalog controls">
                    <div class="catalog-toolbar-primary">
                        <div class="catalog-view-controls" aria-label="Table view controls">
                            <button type="button" class="catalog-icon-button is-active" aria-label="List view" title="List view"><i class="bi bi-list-ul" aria-hidden="true"></i></button>
                            <button type="button" class="catalog-icon-button" id="column-button" aria-label="Choose visible columns" title="Choose visible columns"><i class="bi bi-grid" aria-hidden="true"></i></button>
                        </div>
                        <label class="catalog-search">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <input type="search" id="product-search" placeholder="Search products..." autocomplete="off">
                        </label>
                        <label class="catalog-field catalog-show-field"><span>Show</span><select id="stock-filter" aria-label="Show products by stock status">
                            <option value="">All Products</option><option value="available">Available</option><option value="low">Low stock</option><option value="out">Out of stock</option><option value="no-barcode">Without barcode</option><option value="expiring">Expiring soon</option>
                        </select></label>
                        <label class="catalog-field catalog-sort-field"><span>Sort by</span><select id="catalog-sort" aria-label="Sort products">
                            <option value="name:1">Default</option><option value="name:1">Name A-Z</option><option value="name:-1">Name Z-A</option><option value="stock:1">Stock: Low to High</option><option value="stock:-1">Stock: High to Low</option><option value="price:1">Price: Low to High</option><option value="price:-1">Price: High to Low</option>
                        </select></label>
                        <button type="button" class="btn btn-quiet btn-icon catalog-filter-button" id="catalog-filter-toggle" aria-expanded="true" aria-controls="catalog-filter-row"><i class="bi bi-funnel" aria-hidden="true"></i>Filter</button>
                        <div class="product-add-menu" id="product-add-menu">
                            <button type="button" class="btn product-add-trigger" id="product-add-trigger" aria-expanded="false" aria-controls="product-add-options" aria-haspopup="menu"><i class="bi bi-plus-lg" aria-hidden="true"></i><span>Add</span><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
                            <div class="product-add-menu-list" id="product-add-options" role="menu" hidden>
                                <?php if ($canManageInventory): ?><button type="button" class="product-add-menu-item" id="add-product-btn" role="menuitem"><i class="bi bi-box-seam" aria-hidden="true"></i><strong>Add Product</strong></button><button type="button" class="product-add-menu-item" id="add-category-btn" role="menuitem"><i class="bi bi-folder-plus" aria-hidden="true"></i><strong>Add Category</strong></button><?php endif; ?>
                                <a class="product-add-menu-item" role="menuitem" href="<?= htmlspecialchars(app_url('components/inventory_management/print_barcodes.php')) ?>"><i class="bi bi-upc-scan" aria-hidden="true"></i><strong>Barcode Labels</strong></a>
                            </div>
                        </div>
                    </div>
                    <div class="catalog-filter-row" id="catalog-filter-row">
                        <label class="catalog-field"><span>Category</span><select id="category-filter" aria-label="Filter by category"><option value="">All Categories</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option><?php endforeach; ?></select></label>
                        <label class="catalog-field"><span>Status</span><select id="status-filter" aria-label="Filter by product status"><option value="">All Statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                        <label class="catalog-field"><span>Price</span><select id="price-filter" aria-label="Filter by selling price"><option value="">Any Price</option><option value="0:50">Under ₱50</option><option value="50:100">₱50 - ₱100</option><option value="100:500">₱100 - ₱500</option><option value="500:">₱500 and above</option></select></label>
                        <?php if (is_system_admin()): ?><form method="get" class="catalog-field"><span>Store</span><select id="inventory-branch" name="branch_id" onchange="this.form.submit()" aria-label="Filter by store"><option value="">All Stores</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['branch_id'] ?>" <?= $selectedBranchId === (int)$branch['branch_id'] ? 'selected' : '' ?>><?= htmlspecialchars($branch['branch_name']) ?></option><?php endforeach; ?></select></form><?php else: ?><label class="catalog-field"><span>Store</span><select disabled aria-label="Assigned store"><option>Assigned Store</option></select></label><?php endif; ?>
                        <div class="catalog-utility-controls">
                            <button type="button" class="catalog-icon-button" id="save-view-button" aria-label="Save current view" title="Save current view"><i class="bi bi-bookmark" aria-hidden="true"></i></button>
                            <select id="saved-view" aria-label="Saved filter view"><option value="">Saved views</option></select>
                            <button type="button" class="catalog-icon-button" id="clear-filters" aria-label="Clear filters" title="Clear filters"><i class="bi bi-x-circle" aria-hidden="true"></i></button>
                            <button type="button" class="catalog-icon-button" id="export-products" aria-label="Export products" title="Export products"><i class="bi bi-download" aria-hidden="true"></i></button>
                            <span class="toolbar-count" id="result-count"><?= number_format($totalProducts) ?> results</span>
                        </div>
                    </div>
                </div>

                <div class="bulk-bar" id="bulk-bar">
                    <strong><span id="selected-count">0</span> selected</strong>
                    <?php if ($canManageInventory): ?>
                        <button class="btn btn-small btn-warning" type="button" id="bulk-print"><i class="bi bi-printer"></i> Print barcodes</button>
                        <button class="btn btn-small btn-quiet" type="button" id="bulk-category"><i class="bi bi-folder"></i> Change category</button>
                        <button class="btn btn-small btn-quiet" type="button" id="bulk-activate">Activate</button>
                        <button class="btn btn-small btn-danger" type="button" id="bulk-deactivate">Deactivate</button>
                    <?php endif; ?>
                    <button class="btn btn-small btn-quiet" type="button" id="clear-selection">Clear</button>
                </div>

                <div class="data-table-shell product-table-shell">
                    <div class="data-table-scroll">
                        <table class="data-table" id="products-table">
                            <thead>
                                <tr>
                                    <th class="u-col-select"><input type="checkbox" id="select-all" aria-label="Select all visible products"></th>
                                    <th class="sortable" data-sort="name">Product <i class="bi bi-arrow-down-up"></i></th>
                                    <th class="column-barcode sortable" data-sort="barcode">SKU / Barcode <i class="bi bi-arrow-down-up"></i></th>
                                    <th class="column-category sortable" data-sort="category">Category <i class="bi bi-arrow-down-up"></i></th>
                                    <th class="column-price sortable" data-sort="price">Selling Price <i class="bi bi-arrow-down-up"></i></th>
                                    <th class="sortable" data-sort="stock">Current Stock <i class="bi bi-arrow-down-up"></i></th>
                                    <th>Status</th>
                                    <th class="u-text-right">Actions</th>
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
                                                <button type="button" class="btn btn-small btn-quiet btn-icon manage-product" data-product-id="<?= (int)$product['product_id'] ?>" aria-label="Manage <?= htmlspecialchars($product['product_name']) ?>"><i class="bi bi-sliders" aria-hidden="true"></i>Manage</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="empty-state" id="product-empty" hidden>
                            <div><i class="bi bi-search"></i><strong>No products match these filters</strong><span>Clear the filters or add a new product.</span><br><button type="button" class="btn btn-small btn-quiet u-mt-08" id="empty-clear">Clear filters</button></div>
                        </div>
                    </div>
                    <footer class="table-pagination">
                        <div><span id="page-summary">Showing 1–<?= min(25, $totalProducts) ?> of <?= $totalProducts ?></span></div>
                        <label>Rows <select id="page-size" aria-label="Rows per page">
                                <option>25</option>
                                <option>50</option>
                                <option>100</option>
                            </select></label>
                        <div class="pagination-buttons" id="pagination-buttons"></div>
                    </footer>
                </div>
        </main>
    </div>

    <div class="rm-drawer-overlay" id="product-drawer" aria-hidden="true">
        <aside class="rm-drawer" role="dialog" aria-modal="true" aria-labelledby="product-drawer-title">
            <header class="rm-drawer-header">
                <div>
                    <h2 id="product-drawer-title">Product details</h2>
                    <p id="product-drawer-subtitle"></p>
                </div><button type="button" class="rm-close" data-close-drawer aria-label="Close product details"><i class="bi bi-x-lg"></i></button>
            </header>
            <nav class="product-drawer-tabs" role="tablist" aria-label="Product details sections">
                <button type="button" class="product-drawer-tab is-active" id="product-tab-info" role="tab" aria-selected="true" aria-controls="product-panel-info" data-product-tab="info">Info</button>
                <button type="button" class="product-drawer-tab" id="product-tab-quantity" role="tab" aria-selected="false" aria-controls="product-panel-quantity" data-product-tab="quantity" tabindex="-1">Quantity</button>
                <button type="button" class="product-drawer-tab" id="product-tab-actions" role="tab" aria-selected="false" aria-controls="product-panel-actions" data-product-tab="actions" tabindex="-1">Recommended Actions</button>
            </nav>
            <div class="rm-drawer-body" id="product-drawer-body">
                <section class="product-drawer-panel" id="product-panel-info" role="tabpanel" aria-labelledby="product-tab-info" data-product-panel="info"></section>
                <section class="product-drawer-panel" id="product-panel-quantity" role="tabpanel" aria-labelledby="product-tab-quantity" data-product-panel="quantity" hidden></section>
                <section class="product-drawer-panel" id="product-panel-actions" role="tabpanel" aria-labelledby="product-tab-actions" data-product-panel="actions" hidden></section>
            </div>
            <footer class="rm-drawer-footer" id="product-drawer-footer"></footer>
        </aside>
    </div>

    <div class="rm-modal-overlay" id="add-product-modal" aria-hidden="true">
        <section class="rm-modal u-modal-lg" role="dialog" aria-modal="true" aria-labelledby="add-product-title">
            <header class="rm-modal-header">
                <div>
                    <h2 id="add-product-title">Add Product</h2>
                    <p>Complete the guided steps. Stock begins at zero and must be received through the approved workflow.</p>
                </div><button type="button" class="rm-close" data-close-modal aria-label="Close add product"><i class="bi bi-x-lg"></i></button>
            </header>
            <form method="POST" id="add-product-form" novalidate enctype="multipart/form-data">
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
                            <div class="form-group"><label>Category *</label><select name="category_id" id="product-category" required>
                                    <option value="">Select category</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option><?php endforeach; ?>
                                </select><small class="field-error">Select a category.</small></div>
                            <div class="form-group"><label>Parent Product Family</label><select name="parent_product_id">
                                    <option value="">Standalone product</option><?php foreach ($variantParents as $parent): ?><option value="<?= (int)$parent['product_id'] ?>"><?= htmlspecialchars($parent['product_name'] . ' (' . $parent['sku'] . ')') ?></option><?php endforeach; ?>
                                </select></div>
                            <div class="form-group"><label>Variant Label</label><input name="variant_label" maxlength="100" placeholder="Example: 200 mL, Red, Large"></div>
                            <div class="form-group full"><label>Product Image</label>
                                <div class="u-mb-05"><input type="file" name="product_image" id="product-image-input" accept="image/jpeg,image/png,image/gif,image/webp"><small class="field-help">Supported formats: JPEG, PNG, GIF, WebP. Max size: 5MB.</small></div>
                                <div id="image-preview-container" hidden>
                                    <div style="display:flex;flex-direction:column;gap:0.5rem"><img id="image-preview" src="" alt="Product preview" style="max-width:200px;border-radius:4px;border:1px solid #ddd;"><button type="button" class="btn btn-small btn-quiet" id="clear-image-btn">Clear image</button></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="wizard-panel" data-panel="2">
                        <div class="u-mb-1" id="add-product-scanner-section" hidden>
                            <div id="add-product-scanner-reader" style="max-width:420px;margin:auto"></div>
                            <p class="section-description" id="add-product-scanner-result">Point the camera at a barcode.</p>
                        </div>
                        <div class="form-grid">
                            <div class="form-group full"><label>Barcode</label>
                                <div class="u-flex-wrap"><input class="u-input-grow" name="barcode" id="create-barcode-input" maxlength="50" placeholder="Scan, enter, generate, or leave blank"><button type="button" class="btn btn-quiet" id="add-product-scan-btn"><i class="bi bi-camera"></i> Scan</button><button type="button" class="btn btn-warning" id="generate-barcode-btn"><i class="bi bi-upc"></i> Generate</button></div><small class="field-help">RetailMind automatically creates a unique internal barcode when this is blank.</small><small class="field-error">This barcode is already used by another product.</small>
                            </div>
                            <div class="form-group"><label>Case / Package Barcode</label><input name="case_barcode" maxlength="80" placeholder="Optional outer-case barcode"></div>
                            <div class="form-group"><label>Status</label><select name="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select></div>
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
                        <div class="u-info-note"><i class="bi bi-info-circle"></i> The product will start at zero stock. Use Stock Receiving after creation.</div>
                        <div class="form-grid u-mt-1">
                            <label class="form-group full u-checkbox-label"><input class="u-width-auto" type="checkbox" name="print_after_create" value="1" checked>Open printable barcode labels after saving</label>
                            <div class="form-group"><label>Number of labels</label><input type="number" name="label_quantity" value="1" min="1" max="200"></div>
                        </div>
                    </section>
                </div>
                <footer class="rm-modal-actions"><button type="button" class="btn btn-quiet" data-close-modal>Cancel</button><button type="button" class="btn btn-quiet" id="wizard-reset">Reset</button><button type="button" class="btn btn-quiet" id="wizard-back" hidden>Back</button><button type="button" class="btn" id="wizard-next">Next</button><button type="submit" class="btn btn-success" id="wizard-save" hidden><i class="bi bi-check2"></i> Save Product</button></footer>
            </form>
        </section>
    </div>

    <div class="rm-modal-overlay" id="add-category-modal" aria-hidden="true">
        <section class="rm-modal u-modal-md" role="dialog" aria-modal="true" aria-labelledby="category-title">
            <header class="rm-modal-header">
                <div>
                    <h2 id="category-title">Add Category</h2>
                    <p>Create a clear category for organizing and filtering products.</p>
                </div><button type="button" class="rm-close" data-close-modal><i class="bi bi-x-lg"></i></button>
            </header>
            <form method="POST">
                <div class="rm-modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="create_category">
                    <div class="form-group"><label>Category Name</label><input type="text" name="category_name" required maxlength="100" placeholder="Example: Beverages"></div>
                </div>
                <footer class="rm-modal-actions"><button type="button" class="btn btn-quiet" data-close-modal>Cancel</button><button class="btn btn-success" type="submit">Save Category</button></footer>
            </form>
        </section>
    </div>

    <?php if ($canDirectAdjust): ?>
        <div class="rm-modal-overlay" id="stock-adjust-modal" aria-hidden="true">
            <section class="rm-modal u-modal-form" role="dialog" aria-modal="true" aria-labelledby="adjust-title">
                <header class="rm-modal-header">
                    <div>
                        <h2 id="adjust-title">Emergency Stock Adjustment</h2>
                        <p>Use only after a verified count. The reason is recorded in the audit log.</p>
                    </div><button type="button" class="rm-close" data-close-modal><i class="bi bi-x-lg"></i></button>
                </header>
                <form method="POST" data-confirm="This will directly change the current stock quantity and create an audit record." data-confirm-title="Confirm emergency adjustment" data-confirm-button="Apply adjustment" data-confirm-danger="1">
                    <div class="rm-modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="adjust">
                        <div class="form-group"><label>Product</label><select name="product_id" id="adjust-product-select" required>
                                <option value="">Select product</option><?php foreach ($activeProducts as $product): ?><option value="<?= (int)$product['product_id'] ?>"><?= htmlspecialchars($product['sku'] . ' — ' . $product['product_name']) ?> (<?= (int)$product['quantity_on_hand'] ?> on hand)</option><?php endforeach; ?>
                            </select></div>
                        <div class="form-group"><label>Quantity Change</label><input type="number" name="qty_change" required placeholder="Use 5 to add or -3 to remove"></div>
                        <div class="form-group"><label>Specific Approved Reason</label><input name="adjustment_reason" minlength="8" required placeholder="Example: Verified physical-count correction"></div>
                    </div>
                    <footer class="rm-modal-actions"><button type="button" class="btn btn-quiet" data-close-modal>Cancel</button><button class="btn btn-danger" type="submit">Apply Adjustment</button></footer>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <div class="rm-modal-overlay" id="bulk-category-modal" aria-hidden="true">
        <section class="rm-modal u-modal-md" role="dialog" aria-modal="true" aria-labelledby="bulk-category-title">
            <header class="rm-modal-header">
                <div>
                    <h2 id="bulk-category-title">Change Category</h2>
                    <p>Apply one category to every selected product.</p>
                </div><button type="button" class="rm-close" data-close-modal><i class="bi bi-x-lg"></i></button>
            </header>
            <form method="POST" id="bulk-category-form">
                <div class="rm-modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="bulk_category">
                    <div id="bulk-category-ids"></div>
                    <div class="form-group"><label>New Category</label><select name="category_id" required>
                            <option value="">Select category</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option><?php endforeach; ?>
                        </select></div>
                </div>
                <footer class="rm-modal-actions"><button type="button" class="btn btn-quiet" data-close-modal>Cancel</button><button class="btn" type="submit">Update Category</button></footer>
            </form>
        </section>
    </div>

    <form method="POST" id="bulk-status-form" hidden><?= csrf_field() ?><input type="hidden" name="action" value="bulk_status"><input type="hidden" name="status" id="bulk-status-value">
        <div id="bulk-status-ids"></div>
    </form>
    <form method="POST" id="generate-barcode-form" target="_blank" hidden><?= csrf_field() ?><input type="hidden" name="action" value="generate_barcode"><input type="hidden" name="product_id" id="generate-product-id"><input type="hidden" name="label_quantity" value="1"></form>

    <div class="rm-modal-overlay" id="column-modal" aria-hidden="true">
        <section class="rm-modal u-modal-sm" role="dialog" aria-modal="true" aria-labelledby="column-title">
            <header class="rm-modal-header">
                <div>
                    <h2 id="column-title">Visible Columns</h2>
                    <p>Choose which optional columns appear in the table.</p>
                </div><button type="button" class="rm-close" data-close-modal><i class="bi bi-x-lg"></i></button>
            </header>
            <div class="rm-modal-body"><label class="u-checkbox-row"><input type="checkbox" data-column="barcode" checked> SKU / Barcode</label><label class="u-checkbox-row"><input type="checkbox" data-column="category" checked> Category</label><label class="u-checkbox-row"><input type="checkbox" data-column="price" checked> Selling Price</label></div>
            <footer class="rm-modal-actions"><button type="button" class="btn" data-close-modal>Done</button></footer>
        </section>
    </div>

    <script src="https://unpkg.com/html5-qrcode"></script>
    <script>
        const productData = <?= json_encode(array_values($products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const canDirectAdjust = <?= $canDirectAdjust ? 'true' : 'false' ?>;
        const productsById = Object.fromEntries(productData.map(product => [Number(product.product_id), product]));
        const existingBarcodes = new Set(productData.map(product => String(product.barcode || '').trim().toLowerCase()).filter(Boolean));
        const existingProductNames = new Set(productData.map(product => String(product.product_name || '').trim().toLowerCase()).filter(Boolean));
        const productRows = Array.from(document.querySelectorAll('#product-table-body tr'));
        const searchInput = document.getElementById('product-search');
        const categoryFilter = document.getElementById('category-filter');
        const stockFilter = document.getElementById('stock-filter');
        const statusFilter = document.getElementById('status-filter');
        const priceFilter = document.getElementById('price-filter');
        const sortSelect = document.getElementById('catalog-sort');
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

        function openModal(id) {
            RetailMindUI.openOverlay(document.getElementById(id));
        }

        function closeModal(node) {
            RetailMindUI.closeOverlay(node.closest('.rm-modal-overlay, .rm-drawer-overlay'));
        }
        document.querySelectorAll('[data-close-modal], [data-close-drawer]').forEach(button => button.addEventListener('click', () => closeModal(button)));
        document.querySelectorAll('.rm-modal-overlay, .rm-drawer-overlay').forEach(overlay => overlay.addEventListener('click', event => {
            if (event.target === overlay) RetailMindUI.closeOverlay(overlay);
        }));

        const addMenu = document.getElementById('product-add-menu');
        const addMenuTrigger = document.getElementById('product-add-trigger');
        const addMenuOptions = document.getElementById('product-add-options');

        function setAddMenuOpen(isOpen) {
            addMenuOptions.hidden = !isOpen;
            addMenuTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            if (isOpen) addMenuOptions.querySelector('[role="menuitem"]')?.focus();
        }

        addMenuTrigger.addEventListener('click', () => setAddMenuOpen(addMenuOptions.hidden));
        document.addEventListener('click', event => {
            if (!addMenu.contains(event.target)) setAddMenuOpen(false);
        });
        addMenu.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                setAddMenuOpen(false);
                addMenuTrigger.focus();
            }
        });

        const addProductButton = document.getElementById('add-product-btn');
        if (addProductButton) addProductButton.addEventListener('click', () => {
            setAddMenuOpen(false);
            openModal('add-product-modal');
            restoreFormState();
        });
        const addCategoryButton = document.getElementById('add-category-btn');
        if (addCategoryButton) addCategoryButton.addEventListener('click', () => {
            setAddMenuOpen(false);
            openModal('add-category-modal');
        });
        document.getElementById('column-button').addEventListener('click', () => openModal('column-modal'));
        const filterToggle = document.getElementById('catalog-filter-toggle');
        const filterRow = document.getElementById('catalog-filter-row');
        filterToggle.addEventListener('click', () => {
            filterRow.hidden = !filterRow.hidden;
            filterToggle.setAttribute('aria-expanded', filterRow.hidden ? 'false' : 'true');
            filterToggle.classList.toggle('is-active', !filterRow.hidden);
        });

        function getFilters() {
            return {
                q: searchInput.value.trim().toLowerCase(),
                category: categoryFilter.value,
                stock: stockFilter.value,
                status: statusFilter.value,
                price: priceFilter.value
            };
        }

        function applyFilters(resetPage = true) {
            const filters = getFilters();
            filteredRows = productRows.filter(row => {
                const searchMatch = !filters.q || row.dataset.search.includes(filters.q);
                const categoryMatch = !filters.category || row.dataset.category === filters.category;
                const statusMatch = !filters.status || row.dataset.status === filters.status;
                const price = Number(row.dataset.price || 0);
                const [minimumPrice, maximumPrice] = filters.price.split(':');
                const priceMatch = !filters.price || ((!minimumPrice || price >= Number(minimumPrice)) && (!maximumPrice || price <= Number(maximumPrice)));
                let stockMatch = true;
                if (filters.stock === 'available') stockMatch = row.dataset.stockState === 'available';
                if (filters.stock === 'low') stockMatch = row.dataset.stockState === 'low';
                if (filters.stock === 'out') stockMatch = row.dataset.stockState === 'out';
                if (filters.stock === 'no-barcode') stockMatch = !row.dataset.barcode;
                if (filters.stock === 'expiring') stockMatch = row.dataset.expiring === '1';
                return searchMatch && categoryMatch && statusMatch && priceMatch && stockMatch;
            });
            filteredRows.sort((a, b) => {
                const values = {
                    name: [a.dataset.name, b.dataset.name],
                    barcode: [a.dataset.barcode, b.dataset.barcode],
                    category: [a.dataset.categoryName, b.dataset.categoryName],
                    price: [Number(a.dataset.price), Number(b.dataset.price)],
                    stock: [Number(a.dataset.stock), Number(b.dataset.stock)]
                } [sortKey] || [a.dataset.name, b.dataset.name];
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
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = label;
                button.disabled = disabled;
                button.classList.toggle('active', active);
                button.addEventListener('click', () => {
                    currentPage = page;
                    renderPage();
                    document.getElementById('product-table').scrollIntoView({
                        behavior: 'smooth'
                    });
                });
                return button;
            };
            paginationButtons.appendChild(makeButton('‹', Math.max(1, currentPage - 1), currentPage === 1));
            const first = Math.max(1, currentPage - 2);
            const last = Math.min(pages, first + 4);
            for (let page = first; page <= last; page++) paginationButtons.appendChild(makeButton(String(page), page, false, page === currentPage));
            paginationButtons.appendChild(makeButton('›', Math.min(pages, currentPage + 1), currentPage === pages));
            syncSelectAll();
        }
        [searchInput, categoryFilter, stockFilter, statusFilter, priceFilter].forEach(input => input.addEventListener(input.tagName === 'INPUT' ? 'input' : 'change', () => applyFilters()));
        sortSelect.addEventListener('change', () => {
            const [key, direction] = sortSelect.value.split(':');
            sortKey = key;
            sortDirection = Number(direction) || 1;
            applyFilters(false);
        });
        pageSizeInput.addEventListener('change', () => applyFilters());
        document.getElementById('clear-filters').addEventListener('click', clearFilters);
        document.getElementById('empty-clear').addEventListener('click', clearFilters);

        function clearFilters() {
            searchInput.value = '';
            categoryFilter.value = '';
            stockFilter.value = '';
            statusFilter.value = '';
            priceFilter.value = '';
            sortSelect.value = 'name:1';
            sortKey = 'name';
            sortDirection = 1;
            applyFilters();
        }
        document.querySelectorAll('[data-quick-filter]').forEach(card => card.addEventListener('click', () => {
            const filter = card.dataset.quickFilter;
            clearFilters();
            if (filter !== 'all') stockFilter.value = filter;
            applyFilters();
        }));
        document.querySelectorAll('th.sortable').forEach(header => header.addEventListener('click', () => {
            if (sortKey === header.dataset.sort) sortDirection *= -1;
            else {
                sortKey = header.dataset.sort;
                sortDirection = 1;
            }
            applyFilters(false);
        }));

        function selectedValues() {
            return Array.from(selectedIds);
        }

        function updateBulkBar() {
            document.getElementById('selected-count').textContent = selectedIds.size;
            document.getElementById('bulk-bar').classList.toggle('show', selectedIds.size > 0);
        }

        function syncSelectAll() {
            const visible = filteredRows.filter(row => !row.hidden);
            const allSelected = visible.length > 0 && visible.every(row => selectedIds.has(Number(row.dataset.productId)));
            document.getElementById('select-all').checked = allSelected;
        }
        document.querySelectorAll('.row-select').forEach(box => box.addEventListener('change', () => {
            const id = Number(box.value);
            box.checked ? selectedIds.add(id) : selectedIds.delete(id);
            updateBulkBar();
            syncSelectAll();
        }));
        document.getElementById('select-all').addEventListener('change', event => {
            filteredRows.filter(row => !row.hidden).forEach(row => {
                const id = Number(row.dataset.productId);
                const box = row.querySelector('.row-select');
                box.checked = event.target.checked;
                event.target.checked ? selectedIds.add(id) : selectedIds.delete(id);
            });
            updateBulkBar();
        });
        document.getElementById('clear-selection').addEventListener('click', () => {
            selectedIds.clear();
            document.querySelectorAll('.row-select').forEach(box => box.checked = false);
            updateBulkBar();
            syncSelectAll();
        });

        function appendSelectedInputs(container) {
            container.innerHTML = '';
            selectedValues().forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'product_ids[]';
                input.value = id;
                container.appendChild(input);
            });
        }
        document.getElementById('bulk-category').addEventListener('click', () => {
            appendSelectedInputs(document.getElementById('bulk-category-ids'));
            openModal('bulk-category-modal');
        });
        async function submitBulkStatus(status) {
            const ok = await RetailMindUI.confirm({
                title: `${status === 'active' ? 'Activate' : 'Deactivate'} selected products`,
                message: `This will update ${selectedIds.size} selected product(s).`,
                confirmText: status === 'active' ? 'Activate' : 'Deactivate',
                danger: status !== 'active'
            });
            if (!ok) return;
            appendSelectedInputs(document.getElementById('bulk-status-ids'));
            document.getElementById('bulk-status-value').value = status;
            document.getElementById('bulk-status-form').submit();
        }
        document.getElementById('bulk-activate').addEventListener('click', () => submitBulkStatus('active'));
        document.getElementById('bulk-deactivate').addEventListener('click', () => submitBulkStatus('inactive'));
        document.getElementById('bulk-print').addEventListener('click', () => {
            const ids = selectedValues();
            if (!ids.length) return;
            window.open(`print_barcodes.php?product_ids=${encodeURIComponent(ids.join(','))}&quantity=1`, '_blank');
        });

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            } [char]));
        }

        function safeLink(value) {
            const url = String(value || '').trim();
            return /^(https?:\/\/|\/)/i.test(url) ? url : '';
        }

        function formatMoney(value) {
            return `₱${Number(value || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
        }

        function safe(value, fallback = '—') {
            const text = String(value ?? '').trim();
            return text || fallback;
        }

        function setProductDrawerTab(tabName, focusTab = false) {
            document.querySelectorAll('[data-product-tab]').forEach(tab => {
                const isActive = tab.dataset.productTab === tabName;
                tab.classList.toggle('is-active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                tab.tabIndex = isActive ? 0 : -1;
                if (isActive && focusTab) tab.focus();
            });
            document.querySelectorAll('[data-product-panel]').forEach(panel => {
                panel.hidden = panel.dataset.productPanel !== tabName;
            });
            document.getElementById('product-drawer-footer').hidden = tabName !== 'actions';
        }

        document.querySelectorAll('[data-product-tab]').forEach(tab => {
            tab.addEventListener('click', () => setProductDrawerTab(tab.dataset.productTab));
            tab.addEventListener('keydown', event => {
                if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
                event.preventDefault();
                const tabs = Array.from(document.querySelectorAll('[data-product-tab]'));
                const direction = event.key === 'ArrowRight' ? 1 : -1;
                const nextIndex = (tabs.indexOf(tab) + direction + tabs.length) % tabs.length;
                setProductDrawerTab(tabs[nextIndex].dataset.productTab, true);
            });
        });

        function openProductDrawer(id) {
            const product = productsById[Number(id)];
            if (!product) return;
            document.getElementById('product-drawer-title').textContent = product.product_name + (product.variant_label ? ` — ${product.variant_label}` : '');
            document.getElementById('product-drawer-subtitle').textContent = `${safe(product.sku)} · ${safe(product.category_name, 'Uncategorized')}`;
            const quantity = Number(product.quantity_on_hand || 0);
            const threshold = Math.max(Number(product.reorder_level || 0), Number(product.safety_stock || 0));
            const stockLabel = quantity <= 0 ? 'Out of stock' : quantity <= threshold ? 'Low stock' : 'Available';
            const quantityActions = canDirectAdjust ? `<div class="product-quantity-actions"><button type="button" class="btn btn-icon" id="drawer-adjust-stock"><i class="bi bi-sliders" aria-hidden="true"></i>Adjust Stock</button></div>` : '';
            document.getElementById('product-panel-info').innerHTML = `<div class="detail-grid">
        <div class="detail-item"><span>Status</span><strong>${escapeHtml(safe(product.status))}</strong></div><div class="detail-item"><span>Category</span><strong>${escapeHtml(safe(product.category_name, 'Uncategorized'))}</strong></div>
        <div class="detail-item"><span>SKU</span><strong>${escapeHtml(safe(product.sku))}</strong></div><div class="detail-item"><span>Barcode</span><strong>${escapeHtml(safe(product.barcode, 'Not assigned'))}</strong></div>
        <div class="detail-item"><span>Cost Price</span><strong>${formatMoney(product.cost_price)}</strong></div><div class="detail-item"><span>Selling Price</span><strong>${formatMoney(product.unit_price)}</strong></div>
        <div class="detail-item"><span>Expiration</span><strong>${escapeHtml(safe(product.expiration_date))}</strong></div><div class="detail-item full"><span>Product Image</span><strong>${safeLink(product.product_image) ? `<a href="${escapeHtml(safeLink(product.product_image))}" target="_blank" rel="noopener">Open product image</a>` : 'No image assigned'}</strong></div>
    </div>`;
            document.getElementById('product-panel-quantity').innerHTML = `<div class="detail-grid">
        <div class="detail-item"><span>Current Stock</span><strong>${quantity.toLocaleString()} — ${stockLabel}</strong></div><div class="detail-item"><span>Reorder / Safety</span><strong>${Number(product.reorder_level || 0)} / ${Number(product.safety_stock || 0)}</strong></div>
        <div class="detail-item"><span>MOQ / Units per Case</span><strong>${Number(product.minimum_order_quantity || 1)} / ${Number(product.units_per_package || 1)}</strong></div><div class="detail-item"><span>Units</span><strong>${escapeHtml(safe(product.base_unit))} / ${escapeHtml(safe(product.receiving_unit))}</strong></div>
        <div class="detail-item"><span>Lead Time</span><strong>${Number(product.supplier_lead_time_days || 0)} day(s)</strong></div><div class="detail-item"><span>Preferred Supplier</span><strong>${escapeHtml(safe(product.preferred_supplier || product.supplier))}</strong></div>
    </div>${quantityActions}`;
            document.getElementById('product-panel-actions').innerHTML = `<div class="attention-list">${quantity <= threshold ? '<div class="attention-item"><span class="attention-icon"><i class="bi bi-box-arrow-in-down"></i></span><span class="attention-copy"><strong>Replenishment needed</strong><span>Stock is at or below the planning threshold.</span></span><a class="btn btn-small" href="../report/stock_receiving.php">Receive</a></div>' : '<div class="attention-item"><span class="attention-icon u-stock-ok-icon"><i class="bi bi-check2"></i></span><span class="attention-copy"><strong>Stock level is healthy</strong><span>No immediate replenishment action is required.</span></span></div>'}</div>`;
            const footer = document.getElementById('product-drawer-footer');
            footer.innerHTML = product.barcode ? `<a class="btn btn-warning" target="_blank" href="print_barcodes.php?product_id=${Number(product.product_id)}&quantity=1"><i class="bi bi-printer"></i> Print Barcode</a>` : `<button type="button" class="btn btn-success drawer-generate" data-product-id="${Number(product.product_id)}"><i class="bi bi-upc"></i> Generate Barcode</button>`;
            footer.innerHTML += `<a class="btn btn-quiet" href="../report/stock_receiving.php"><i class="bi bi-box-arrow-in-down"></i> Stock Receiving</a>`;
            const generate = footer.querySelector('.drawer-generate');
            if (generate) generate.addEventListener('click', () => generateBarcode(generate.dataset.productId));
            const drawerAdjustButton = document.getElementById('drawer-adjust-stock');
            if (drawerAdjustButton) drawerAdjustButton.addEventListener('click', () => {
                const productSelect = document.getElementById('adjust-product-select');
                if (productSelect) productSelect.value = String(product.product_id);
                RetailMindUI.closeOverlay(document.getElementById('product-drawer'));
                openModal('stock-adjust-modal');
                document.querySelector('#stock-adjust-modal [name="qty_change"]')?.focus();
            });
            setProductDrawerTab('info');
            RetailMindUI.openOverlay(document.getElementById('product-drawer'));
        }
        document.querySelectorAll('.manage-product').forEach(button => button.addEventListener('click', () => openProductDrawer(button.dataset.productId)));

        function generateBarcode(id) {
            document.getElementById('generate-product-id').value = id;
            document.getElementById('generate-barcode-form').submit();
        }
        // Column preferences
        const columnPreferences = JSON.parse(localStorage.getItem('retailmind_product_columns') || '{}');
        document.querySelectorAll('[data-column]').forEach(box => {
            if (columnPreferences[box.dataset.column] === false) box.checked = false;

            function apply() {
                document.querySelectorAll(`.column-${box.dataset.column}`).forEach(cell => cell.hidden = !box.checked);
                columnPreferences[box.dataset.column] = box.checked;
                localStorage.setItem('retailmind_product_columns', JSON.stringify(columnPreferences));
            }
            box.addEventListener('change', apply);
            apply();
        });

        // Saved view
        const savedViewSelect = document.getElementById('saved-view');

        function loadSavedViews() {
            const views = JSON.parse(localStorage.getItem('retailmind_product_views') || '[]');
            savedViewSelect.innerHTML = '<option value="">Saved views</option>' + views.map((view, index) => `<option value="${index}">${escapeHtml(view.name)}</option>`).join('');
            return views;
        }
        loadSavedViews();
        document.getElementById('save-view-button').addEventListener('click', () => {
            const views = loadSavedViews();
            const filters = getFilters();
            const nameParts = [filters.q ? `Search: ${filters.q}` : '', categoryFilter.value ? categoryFilter.selectedOptions[0]?.text : '', stockFilter.value ? stockFilter.selectedOptions[0]?.text : '', statusFilter.value ? statusFilter.selectedOptions[0]?.text : '', priceFilter.value ? priceFilter.selectedOptions[0]?.text : ''].filter(Boolean);
            views.push({
                name: nameParts.join(' · ') || `View ${views.length + 1}`,
                filters,
                sort: sortSelect.value
            });
            localStorage.setItem('retailmind_product_views', JSON.stringify(views.slice(-8)));
            loadSavedViews();
            RetailMindUI.toast('The current filters were saved on this device.', 'success', 'View saved');
        });
        savedViewSelect.addEventListener('change', () => {
            if (savedViewSelect.value === '') return;
            const selectedViewIndex = Number(savedViewSelect.value);
            const views = JSON.parse(localStorage.getItem('retailmind_product_views') || '[]');
            const view = views[selectedViewIndex];
            if (!view) return;
            searchInput.value = view.filters.q || '';
            categoryFilter.value = view.filters.category || '';
            stockFilter.value = view.filters.stock || '';
            statusFilter.value = view.filters.status || '';
            priceFilter.value = view.filters.price || '';
            sortSelect.value = view.sort || 'name:1';
            [sortKey, sortDirection] = sortSelect.value.split(':');
            sortDirection = Number(sortDirection) || 1;
            applyFilters();
        });

        // Export filtered products
        function csvCell(value) {
            return `"${String(value ?? '').replace(/"/g, '""')}"`;
        }
        document.getElementById('export-products').addEventListener('click', () => {
            const header = ['Product', 'SKU', 'Barcode', 'Brand', 'Category', 'Selling Price', 'Current Stock', 'Status'];
            const lines = [header.map(csvCell).join(',')];
            filteredRows.forEach(row => {
                const product = productsById[Number(row.dataset.productId)];
                lines.push([product.product_name, product.sku, product.barcode, product.brand, product.category_name, product.unit_price, product.quantity_on_hand, product.status].map(csvCell).join(','));
            });
            const blob = new Blob([lines.join('\n')], {
                type: 'text/csv;charset=utf-8'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `retailmind-products-${new Date().toISOString().slice(0,10)}.csv`;
            link.click();
            URL.revokeObjectURL(link.href);
        });

        // Product wizard and validation
        let wizardStep = 1;
        const addProductForm = document.getElementById('add-product-form');
        const nextButton = document.getElementById('wizard-next');
        const backButton = document.getElementById('wizard-back');
        const saveButton = document.getElementById('wizard-save');

        function setWizardStep(step) {
            wizardStep = Math.max(1, Math.min(5, step));
            document.querySelectorAll('.wizard-panel').forEach(panel => panel.classList.toggle('active', Number(panel.dataset.panel) === wizardStep));
            document.querySelectorAll('.wizard-step-indicator').forEach((indicator, index) => {
                indicator.classList.toggle('active', index + 1 === wizardStep);
                indicator.classList.toggle('complete', index + 1 < wizardStep);
            });
            backButton.hidden = wizardStep === 1;
            nextButton.hidden = wizardStep === 5;
            saveButton.hidden = wizardStep !== 5;
            if (wizardStep === 5) renderReview();
        }

        function validateStep(step) {
            let valid = true;
            const panel = document.querySelector(`.wizard-panel[data-panel="${step}"]`);
            panel.querySelectorAll('.form-group').forEach(group => group.classList.remove('invalid'));
            if (step === 1) {
                const name = document.getElementById('product-name');
                const category = document.getElementById('product-category');
                if (!name.value.trim()) {
                    name.closest('.form-group').classList.add('invalid');
                    valid = false;
                }
                if (!category.value) {
                    category.closest('.form-group').classList.add('invalid');
                    valid = false;
                }
                if (existingProductNames.has(name.value.trim().toLowerCase())) RetailMindUI.toast('A product with the same name already exists. Add a variant label when appropriate.', 'warning', 'Possible duplicate');
            }
            if (step === 2) {
                const barcode = document.getElementById('create-barcode-input');
                if (barcode.value.trim() && existingBarcodes.has(barcode.value.trim().toLowerCase())) {
                    barcode.closest('.form-group').classList.add('invalid');
                    valid = false;
                }
            }
            if (step === 3) {
                const cost = document.getElementById('cost-price');
                const selling = document.getElementById('selling-price');
                if (cost.value === '' || Number(cost.value) < 0) {
                    cost.closest('.form-group').classList.add('invalid');
                    valid = false;
                }
                if (selling.value === '' || Number(selling.value) < Number(cost.value)) {
                    selling.closest('.form-group').classList.add('invalid');
                    valid = false;
                }
            }
            return valid;
        }
        nextButton.addEventListener('click', () => {
            if (validateStep(wizardStep)) setWizardStep(wizardStep + 1);
        });
        backButton.addEventListener('click', () => setWizardStep(wizardStep - 1));
        addProductForm.addEventListener('submit', event => {
            for (let step = 1; step <= 3; step++) {
                if (!validateStep(step)) {
                    event.preventDefault();
                    setWizardStep(step);
                    RetailMindUI.toast('Review the highlighted fields before saving.', 'error');
                    return;
                }
            }
            saveButton.disabled = true;
            saveButton.innerHTML = '<i class="bi bi-arrow-repeat"></i> Saving...';
        });

        function renderReview() {
            const data = new FormData(addProductForm);
            const categoryText = document.getElementById('product-category').selectedOptions[0]?.text || '—';
            const items = [
                ['Product', data.get('product_name')],
                ['Brand', data.get('brand') || '—'],
                ['Category', categoryText],
                ['Barcode', data.get('barcode') || 'Generate automatically'],
                ['Cost Price', formatMoney(data.get('cost_price'))],
                ['Selling Price', formatMoney(data.get('selling_price'))],
                ['Reorder / Safety', `${data.get('reorder_level')} / ${data.get('safety_stock')}`],
                ['Supplier', data.get('preferred_supplier') || '—']
            ];
            document.getElementById('product-review').innerHTML = items.map(([label, value]) => `<div class="detail-item"><span>${label}</span><strong>${String(value).replace(/[<>]/g,'')}</strong></div>`).join('');
        }

        // Form state preservation for Add Product modal
        const FORM_STATE_KEY = 'retail_mind_add_product_state';
        const FORM_STEP_KEY = 'retail_mind_add_product_step';
        const productModalOverlay = document.getElementById('add-product-modal');

        function saveFormState() {
            const formData = new FormData(addProductForm);
            const state = {};
            formData.forEach((value, key) => {
                if (key !== 'action') {
                    state[key] = value;
                }
            });
            localStorage.setItem(FORM_STATE_KEY, JSON.stringify(state));
            localStorage.setItem(FORM_STEP_KEY, String(wizardStep));
        }

        function restoreFormState() {
            const savedState = localStorage.getItem(FORM_STATE_KEY);
            const savedStep = localStorage.getItem(FORM_STEP_KEY);

            if (savedState) {
                try {
                    const state = JSON.parse(savedState);
                    Object.entries(state).forEach(([name, value]) => {
                        const field = addProductForm.elements[name];
                        if (field) {
                            if (field.type === 'checkbox') {
                                field.checked = value === '1' || value === true;
                            } else if (field.type === 'file') {
                                // File inputs cannot be set programmatically for security reasons
                                // Skip file input restoration
                            } else {
                                field.value = value;
                            }
                        }
                    });

                    // Restore the image preview if it was previously selected
                    const imageInput = document.getElementById('product-image-input');
                    if (imageInput && imageInput.value) {
                        const event = new Event('change', {
                            bubbles: true
                        });
                        imageInput.dispatchEvent(event);
                    }
                } catch (e) {
                    console.error('Failed to restore form state:', e);
                }
            }

            if (savedStep) {
                const step = Math.max(1, Math.min(5, Number(savedStep)));
                setWizardStep(step);
            } else {
                setWizardStep(1);
            }
        }

        function clearFormState() {
            addProductForm.reset();
            localStorage.removeItem(FORM_STATE_KEY);
            localStorage.removeItem(FORM_STEP_KEY);
            wizardStep = 1;
            setWizardStep(1);
            saveButton.disabled = false;
            saveButton.innerHTML = '<i class="bi bi-check2"></i> Save Product';

            // Clear image preview
            const imageInput = document.getElementById('product-image-input');
            const imagePreviewContainer = document.getElementById('image-preview-container');
            if (imageInput) imageInput.value = '';
            if (imagePreviewContainer) imagePreviewContainer.hidden = true;
        }

        // Listen for modal open event
        const originalOpenOverlay = RetailMindUI?.openOverlay;
        if (originalOpenOverlay) {
            RetailMindUI.openOverlay = function(overlay) {
                originalOpenOverlay.call(this, overlay);
                if (overlay && overlay.id === 'add-product-modal') {
                    restoreFormState();
                }
            };
        }

        // Save form state when modal is about to close
        productModalOverlay.addEventListener('click', event => {
            if (event.target === productModalOverlay) {
                saveFormState();
            }
        });

        // Save state when close button is clicked
        document.querySelectorAll('#add-product-modal .rm-close').forEach(button => {
            button.addEventListener('click', saveFormState);
        });

        // Save state when Cancel button is clicked
        document.querySelectorAll('#add-product-modal [data-close-modal]').forEach(button => {
            button.addEventListener('click', saveFormState);
        });

        // Clear state on successful form submission
        const originalFormAction = addProductForm.action;
        addProductForm.addEventListener('submit', event => {
            // Only clear state if form will actually submit (no validation errors)
            if (!event.defaultPrevented) {
                setTimeout(() => {
                    clearFormState();
                }, 100);
            }
        });

        // Reset button functionality
        document.getElementById('wizard-reset').addEventListener('click', async event => {
            event.preventDefault();
            const confirmed = await RetailMindUI.confirm({
                title: 'Clear entered data',
                message: 'Are you sure you want to clear all entered data? This action cannot be undone.',
                confirmText: 'Clear data',
                danger: true
            });
            if (confirmed) {
                clearFormState();
                RetailMindUI.alert('Form reset. All data has been cleared.', 'info', 'Form cleared');
            }
        });

        // Camera scan and internal barcode generation
        const barcodeInput = document.getElementById('create-barcode-input');
        let productScanner = null;
        document.getElementById('generate-barcode-btn').addEventListener('click', () => {
            const values = new Uint32Array(1);
            if (window.crypto?.getRandomValues) window.crypto.getRandomValues(values);
            else values[0] = Math.floor(Math.random() * 99999999);
            const now = new Date();
            barcodeInput.value = `RM${String(now.getFullYear()).slice(-2)}${String(now.getMonth()+1).padStart(2,'0')}${String(now.getDate()).padStart(2,'0')}${String(values[0] % 100000000).padStart(8,'0')}`;
            barcodeInput.focus();
        });
        document.getElementById('add-product-scan-btn').addEventListener('click', async () => {
            const section = document.getElementById('add-product-scanner-section');
            if (!window.Html5Qrcode) {
                RetailMindUI.toast('The camera scanner library is unavailable.', 'error');
                return;
            }
            section.hidden = false;
            if (!productScanner) productScanner = new Html5Qrcode('add-product-scanner-reader');
            try {
                await productScanner.start({
                    facingMode: 'environment'
                }, {
                    fps: 10,
                    qrbox: {
                        width: 250,
                        height: 160
                    }
                }, async decoded => {
                    barcodeInput.value = decoded;
                    document.getElementById('add-product-scanner-result').textContent = `Detected: ${decoded}`;
                    if (navigator.vibrate) navigator.vibrate(120);
                    await productScanner.stop();
                    section.hidden = true;
                }, () => {});
            } catch (error) {
                RetailMindUI.toast(String(error), 'error', 'Camera unavailable');
                section.hidden = true;
            }
        });

        const initialParams = new URLSearchParams(window.location.search);
        if (initialParams.get('q')) searchInput.value = initialParams.get('q');
        if (initialParams.get('category')) categoryFilter.value = initialParams.get('category');
        if (initialParams.get('stock')) stockFilter.value = initialParams.get('stock');
        if (initialParams.get('status')) statusFilter.value = initialParams.get('status');
        applyFilters(false);
        updateBulkBar();

        // Product image preview
        const imageInput = document.getElementById('product-image-input');
        const imagePreviewContainer = document.getElementById('image-preview-container');
        const imagePreview = document.getElementById('image-preview');
        const clearImageBtn = document.getElementById('clear-image-btn');

        imageInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                if (!file.type.startsWith('image/')) {
                    RetailMindUI.toast('Please select a valid image file.', 'warning');
                    this.value = '';
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    RetailMindUI.toast('Image file must not exceed 5MB.', 'warning');
                    this.value = '';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                    imagePreview.src = e.target.result;
                    imagePreviewContainer.hidden = false;
                };
                reader.readAsDataURL(file);
            } else {
                imagePreviewContainer.hidden = true;
            }
        });

        clearImageBtn.addEventListener('click', function(e) {
            e.preventDefault();
            imageInput.value = '';
            imagePreviewContainer.hidden = true;
        });
    </script>
</body>

</html>
