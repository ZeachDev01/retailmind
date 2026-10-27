<?php
// components/inventory_management/products.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/ProductService.php';
header('Cache-Control: no-store');
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
    if (in_array($type, ['success', 'warning'], true)) $_SESSION['inventory_confirmed_draft'] = $_POST['inventory_draft_id'] ?? null;
    header('Location: products.php?' . $type . '=' . urlencode($message));
    exit;
}

// Aligned product photo Operator Alert sentences (ticket #56): one plain
// sentence states the size and type limit, retryable photo failures redirect
// as the yellow Attention warning kind.
const PRODUCT_PHOTO_LIMIT_MESSAGE = 'Choose a product photo that is a JPEG, PNG, GIF, or WebP image no larger than 5MB, then try again.';
const PRODUCT_PHOTO_UPLOAD_MESSAGE = 'The product photo could not be uploaded. Choose another file and try again.';
const PRODUCT_PHOTO_SAVE_MESSAGE = 'The product photo could not be saved. Try again. Tell your Administrator if this keeps happening.';

function handle_product_photo_upload(?array $file): ?string
{
    if (empty($file) || empty($file['name'])) {
        return null;
    }
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(PRODUCT_PHOTO_UPLOAD_MESSAGE);
    }
    if (($file['size'] ?? 0) > $maxSize) {
        throw new RuntimeException(PRODUCT_PHOTO_LIMIT_MESSAGE);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowedMimes[$mime])) {
        throw new RuntimeException(PRODUCT_PHOTO_LIMIT_MESSAGE);
    }

    $ext = $allowedMimes[$mime];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $uploadDir = __DIR__ . '/../../../backend/storage/images';
    $uploadPath = $uploadDir . '/' . $filename;

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new RuntimeException(PRODUCT_PHOTO_SAVE_MESSAGE);
    }

    return 'storage/images/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);

    if ($action === 'update_catalog_details') {
        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

        if (current_role() !== 'inventory_manager') {
            http_response_code(403);
            $msg = 'Only the active Inventory Manager workspace can edit product catalog details.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'error' => $msg]);
                exit;
            }
            redirect_products('error', $msg);
        }

        csrf_verify();

        try {
            $productId = (int)($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new RuntimeException('Invalid product selected.');
            }

            $updateData = [
                'product_name' => $_POST['product_name'] ?? '',
                'brand' => $_POST['brand'] ?? '',
                'category_id' => $_POST['category_id'] ?? '',
            ];
            if (isset($_POST['variant_label'])) {
                $updateData['variant_label'] = $_POST['variant_label'];
            }
            if (isset($_POST['selling_price'])) {
                $updateData['selling_price'] = $_POST['selling_price'];
            }
            if (isset($_POST['cost_price'])) {
                $updateData['cost_price'] = $_POST['cost_price'];
            }
            if (isset($_POST['status'])) {
                $updateData['status'] = $_POST['status'];
            }
            if (isset($_POST['units_per_package'])) {
                $updateData['units_per_package'] = $_POST['units_per_package'];
            }
            if (isset($_POST['minimum_order_quantity'])) {
                $updateData['minimum_order_quantity'] = $_POST['minimum_order_quantity'];
            }
            if (isset($_POST['reorder_level'])) {
                $updateData['reorder_level'] = $_POST['reorder_level'];
            }
            if (isset($_POST['safety_stock'])) {
                $updateData['safety_stock'] = $_POST['safety_stock'];
            }
            if (isset($_POST['warning_lead_time_days'])) {
                $updateData['warning_lead_time_days'] = trim((string)$_POST['warning_lead_time_days']) === '' ? null : $_POST['warning_lead_time_days'];
            }
            if (array_key_exists('pack_size', $_POST)) {
                $updateData['pack_size'] = trim((string)$_POST['pack_size']) === '' ? null : $_POST['pack_size'];
            }
            if (array_key_exists('pack_barcode', $_POST)) {
                $updateData['pack_barcode'] = trim((string)$_POST['pack_barcode']) === '' ? null : $_POST['pack_barcode'];
            }
            if (array_key_exists('pack_price', $_POST)) {
                $updateData['pack_price'] = trim((string)$_POST['pack_price']) === '' ? null : $_POST['pack_price'];
            }
            $uploadedPhoto = handle_product_photo_upload($_FILES['product_image'] ?? null);
            if ($uploadedPhoto !== null) {
                $updateData['product_image'] = $uploadedPhoto;
            }

            $updated = $productService->updateProductCatalogDetails($productId, $updateData, (int)$_SESSION['user_id']);
            $warning = $updated['warning'] ?? null;

            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'success' => true,
                    'product' => $updated,
                    'warning' => $warning,
                ]);
                exit;
            }

            if ($warning) {
                redirect_products('warning', $warning);
            }
            redirect_products('success', 'Product catalog details updated successfully.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $alert = \App\Support\OperatorAlert::message($e, 'The product details could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.');
            if ($isAjax) {
                http_response_code(422);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'error' => $alert]);
                exit;
            }
            if (in_array($e->getMessage(), [PRODUCT_PHOTO_LIMIT_MESSAGE, PRODUCT_PHOTO_UPLOAD_MESSAGE, PRODUCT_PHOTO_SAVE_MESSAGE], true)) {
                redirect_products('warning', $alert);
            }
            redirect_products('error', $alert);
        }
    }

    if ($action === 'create_category') {
        csrf_verify();
        try {
            $categoryName = trim((string)($_POST['category_name'] ?? ''));
            $productService->createCategory(['category_name' => $categoryName], (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Added category ' . $categoryName);
            redirect_products('success', 'Category added successfully.');
        } catch (Throwable $e) {
            redirect_products('error', \App\Support\OperatorAlert::message($e, 'The category could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.'));
        }
    }

    if ($action === 'create') {
        csrf_verify();
        try {
            $initialQuantity = filter_var($_POST['initial_stock_quantity'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
            if ($initialQuantity === false) {
                throw new RuntimeException('Starting stock must be a whole number of zero or more.');
            }
            $productImage = handle_product_photo_upload($_FILES['product_image'] ?? null) ?? '';


            $hasPackConfig = !empty($_POST['pack_size']) && (int)$_POST['pack_size'] > 1;
            $startingPacks = null;
            $startingLoose = null;
            if ($hasPackConfig) {
                $startingPacks = isset($_POST['starting_packs']) && trim((string)$_POST['starting_packs']) !== '' ? $_POST['starting_packs'] : null;
                $startingLoose = isset($_POST['starting_loose']) && trim((string)$_POST['starting_loose']) !== '' ? $_POST['starting_loose'] : null;
            }

            $productId = $productService->createProduct([
                'product_image_fingerprint' => !empty($_FILES['product_image']['tmp_name']) ? hash_file('sha256', $_FILES['product_image']['tmp_name']) : '',
                'operation_token' => $_POST['operation_token'] ?? '',
                'barcode' => $_POST['barcode'] ?? '',
                'product_name' => $_POST['product_name'] ?? '',
                'brand' => $_POST['brand'] ?? '',
                'category_id' => $_POST['category_id'] ?? '',
                'cost_price' => $_POST['cost_price'] ?? 0,
                'selling_price' => $_POST['selling_price'] ?? 0,
                'reorder_level' => $_POST['reorder_level'] ?? 10,
                'preferred_supplier' => $_POST['preferred_supplier'] ?? '',
                'supplier_lead_time_days' => trim((string)($_POST['supplier_lead_time_days'] ?? '')) === '' ? 0 : $_POST['supplier_lead_time_days'],
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
                'initial_stock_quantity' => $initialQuantity,
                'warning_lead_time_days' => trim((string)($_POST['warning_lead_time_days'] ?? '')) === '' ? null : $_POST['warning_lead_time_days'],
                'pack_size' => !empty($_POST['pack_size']) ? $_POST['pack_size'] : null,
                'pack_barcode' => !empty($_POST['pack_barcode']) ? $_POST['pack_barcode'] : null,
                'pack_price' => !empty($_POST['pack_price']) ? $_POST['pack_price'] : null,
                'starting_packs' => $startingPacks,
                'starting_loose' => $startingLoose,
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

            $_SESSION['inventory_confirmed_draft'] = $_POST['inventory_draft_id'] ?? null;
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
            $alert = \App\Support\OperatorAlert::message($e, 'The product could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.');
            if (in_array($e->getMessage(), [PRODUCT_PHOTO_LIMIT_MESSAGE, PRODUCT_PHOTO_UPLOAD_MESSAGE, PRODUCT_PHOTO_SAVE_MESSAGE], true)) {
                redirect_products('warning', $alert);
            }
            redirect_products('error', $alert);
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
            redirect_products('error', \App\Support\OperatorAlert::message($e, 'The barcode could not be generated. Try again. Tell your Administrator if this keeps happening.'));
        }
    }

    if ($action === 'adjust') {
        csrf_verify();
        try {
            $productService->adjustStock([
                'operation_token' => $_POST['operation_token'] ?? '',
                'qty_change' => (int)($_POST['qty_change'] ?? 0),
                'product_id' => (int)($_POST['product_id'] ?? 0),
                'adjustment_reason' => trim((string)($_POST['adjustment_reason'] ?? '')),
            ], (int)$_SESSION['user_id']);
            redirect_products('success', 'Emergency inventory adjustment recorded.');
        } catch (Throwable $e) {
            redirect_products('error', \App\Support\OperatorAlert::message($e, 'The stock adjustment could not be saved. Check the quantity and try again. Tell your Administrator if this keeps happening.'));
        }
    }

    if ($action === 'reconcile_sales_pack_stock') {
        csrf_verify();
        try {
            $productId = (int)($_POST['product_id'] ?? 0);
            $sealedPacks = (int)($_POST['sealed_packs'] ?? 0);
            $loosePieces = (int)($_POST['loose_pieces'] ?? 0);
            $reason = trim((string)($_POST['reconciliation_reason'] ?? ''));

            $result = $productService->reconcilePhysicalStock($productId, $sealedPacks, $loosePieces, $reason, (int)$_SESSION['user_id'], (string)($_POST['operation_token'] ?? ''));

            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => true, 'product' => $result]);
                exit;
            }
            redirect_products('success', 'Physical stock reconciled successfully.');
        } catch (Throwable $e) {
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
            if ($isAjax) {
                http_response_code(422);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
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
                App\Store\StoreWriteGate::execute($pdo,$stmt, array_merge([$status], $productIds, $bulkScopeParams));
                log_activity($pdo, (int)$_SESSION['user_id'], 'Bulk product status updated to ' . $status . ' for ' . count($productIds) . ' products');
                redirect_products('success', count($productIds) . ' product(s) updated to ' . $status . '.');
            }

            $categoryId = (int)($_POST['category_id'] ?? 0);
            if ($categoryId <= 0) {
                throw new RuntimeException('Choose a category.');
            }
            $stmt = $pdo->prepare("UPDATE products SET category_id = ? WHERE product_id IN ($placeholders){$bulkScopeSql}");
            App\Store\StoreWriteGate::execute($pdo,$stmt, array_merge([$categoryId], $productIds, $bulkScopeParams));
            log_activity($pdo, (int)$_SESSION['user_id'], 'Bulk product category updated for ' . count($productIds) . ' products');
            redirect_products('success', 'Category updated for ' . count($productIds) . ' product(s).');
        } catch (Throwable $e) {
            redirect_products('error', \App\Support\OperatorAlert::message($e, 'The products could not be updated. Check the selection and try again. Tell your Administrator if this keeps happening.'));
        }
    }
}

$categories = $productService->getCategories();
$products = $productService->getProductsForManagement();
$existingBrands = $pdo->query("SELECT DISTINCT TRIM(brand) AS brand FROM products WHERE brand IS NOT NULL AND TRIM(brand) <> '' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
$knownFoodBrands = [
    "JACK'nJILL", 'Oishi', 'Piattos', 'Nova', 'Chippy', 'Clover', 'Rebisco',
    'Fita', 'SkyFlakes', 'Hansel', 'Nissin', 'Lucky Me!', 'Indomie', 'Maggi',
    'Knorr', 'Del Monte', 'Dole', 'Heinz', 'UFC', 'Mama Sita\'s', 'Ajinomoto',
    'Century', '555', 'Ligo', 'Mega', 'San Marino', 'Argentina', 'CDO',
    'Purefoods', 'Spam', 'Nestle', 'Nescafe', 'Milo', 'Bear Brand', 'Alaska',
    'Alpine', 'Anchor', 'Eden', 'Dari Creme', 'Magnolia', 'Selecta',
    'Gardenia', 'Monde', 'Oreo', 'Cadbury', 'Toblerone', 'KitKat', 'Ferrero',
    'Nutella', 'Kellogg\'s', 'Quaker', 'Pringles', 'Lay\'s', 'Doritos',
    'Cheetos', 'Coca-Cola', 'Pepsi', 'Sprite', 'Fanta', 'Royal', 'Mountain Dew',
    '7UP', 'Tang', 'C2', 'Zesto', 'Dutch Mill', 'Yakult', 'Gatorade',
];
$brandSuggestions = [];
// Prefer the saved spelling when a known brand already exists.
foreach (array_merge($existingBrands, $knownFoodBrands) as $brand) {
    $brandKey = strtolower(trim($brand));
    if (!isset($brandSuggestions[$brandKey])) {
        $brandSuggestions[$brandKey] = trim($brand);
    }
}
natcasesort($brandSuggestions);
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

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products &amp; Stock</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <style>
        /* Keep wizard actions visible while the fields scroll. */
        #add-product-modal {
            padding: 12px;
        }

        #add-product-modal .rm-modal {
            max-height: calc(100dvh - 24px);
        }

        #add-product-form {
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }

        #add-product-modal .rm-modal-header,
        #add-product-modal .rm-modal-actions {
            flex-shrink: 0;
        }

        #add-product-modal .rm-modal-body {
            min-height: 0;
            max-height: none;
            overflow-y: auto;
            overflow-x: hidden;
            overscroll-behavior: contain;
        }

        #add-product-modal .form-group,
        #add-product-modal .detail-grid > * {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        #add-product-modal input:not([type="checkbox"]),
        #add-product-modal select,
        #add-product-modal img,
        #add-product-modal video {
            max-width: 100%;
            box-sizing: border-box;
        }

        #add-product-modal .planning-section-title {
            margin: 0;
            font-size: 1rem;
        }

        #add-product-modal .u-checkbox-label {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 44px;
            cursor: pointer;
        }

        #add-product-modal .u-checkbox-label input[type="checkbox"] {
            flex: 0 0 18px;
            width: 18px;
            height: 18px;
            min-height: 0;
            margin: 0;
            padding: 0;
            accent-color: var(--primary);
        }

        #add-product-modal .btn[hidden] {
            display: none;
        }

        @media (max-width: 640px) {
            #add-product-modal .rm-modal-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
                padding: 12px;
            }

            #add-product-modal .rm-modal-actions .btn {
                min-width: 0;
                min-height: 44px;
                white-space: normal;
            }

            #add-product-modal .wizard-step-indicator {
                font-size: 11px;
            }

            #add-product-modal .form-grid,
            #add-product-modal .detail-grid {
                grid-template-columns: minmax(0, 1fr);
            }
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
            background: var(--surface-soft, #f1f5f9);
            outline: none;
        }

        .product-add-menu-item i {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            color: var(--theme-info-text, #2563eb);
            background: var(--theme-info-surface, #eff6ff);
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
            min-height: 44px;
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
            background: var(--surface-soft, #f1f5f9);
        }

        .product-drawer-tab.is-active {
            color: var(--theme-info-text, #1d4ed8);
            background: var(--theme-info-surface, #eff6ff);
            border-color: var(--border, #bfdbfe);
        }

        .product-drawer-panel[hidden],
        #product-drawer-footer[hidden] {
            display: none;
        }

        .product-edit-intro { margin: 0 0 1.5rem; color: var(--muted); font-size: .85rem; line-height: 1.5; }
        .product-edit-section { padding-bottom: 1.5rem; margin-bottom: 1.5rem; }
        .product-edit-section > legend { margin-bottom: 1rem; font-size: 1rem; }
        #product-edit-form .form-field { display: flex; flex-direction: column; gap: .4rem; min-width: 0; margin-bottom: 1rem; }
        #product-edit-form .form-field > span:first-child,
        #product-edit-form .form-field > label { font-size: .85rem; font-weight: 600; line-height: 1.5; }
        #product-edit-form .form-grid { align-items: end; }
        #product-edit-form small { display: block; color: var(--muted); font-size: .76rem; font-weight: 400; }
        #product-edit-form .form-input { width: 100%; min-width: 0; min-height: 44px; padding: .7rem .8rem; border: 1px solid var(--border); border-radius: 10px; background: var(--card-bg); color: var(--text); font: inherit; font-size: .9rem; }
        #product-edit-form .form-input:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
        #product-edit-form .form-input[readonly] { background: var(--surface-soft); color: var(--muted); }
        #product-edit-form input[type="number"] { font-variant-numeric: tabular-nums; }
        #product-edit-form input[type="file"] { font-size: .8rem; }
        #product-edit-form input::file-selector-button { margin-right: .65rem; padding: .4rem .6rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface-soft); color: var(--text); font: inherit; cursor: pointer; }
        .product-edit-required { color: var(--theme-danger-text, #b91c1c); }
        #product-edit-error, #selling-price-warning { margin-bottom: 1rem; padding: .75rem 1rem; border-radius: 10px; font-size: .85rem; line-height: 1.5; }
        #product-edit-error { color: var(--theme-danger-text, #b91c1c); background: var(--theme-danger-surface, #fef2f2); }
        #selling-price-warning { color: var(--theme-warning-text, #92400e); background: var(--theme-warning-surface, #fef3c7); }
        .product-edit-actions { position: sticky; bottom: 0; z-index: 1; display: flex; justify-content: flex-end; gap: .75rem; margin: 0; padding: 1rem 0; padding-bottom: max(1rem, env(safe-area-inset-bottom)); border-top: 1px solid var(--border); background: var(--card-bg); }
        .product-edit-actions .btn { min-height: 44px; }
        @media (max-width: 480px) {
            #product-edit-form .form-grid { grid-template-columns: minmax(0, 1fr); gap: 0; }
            .product-edit-actions .btn { flex: 1; justify-content: center; }
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
            background: var(--card-bg, #fff);
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
            background: var(--surface-soft, #fbfcfe);
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
            width: 44px;
            height: 44px;
            display: inline-grid;
            place-items: center;
            flex: 0 0 44px;
            padding: 0;
            color: var(--muted);
            background: var(--card-bg, #fff);
            border: 1px solid var(--border);
            border-radius: 6px;
            cursor: pointer;
        }

        .catalog-icon-button:hover,
        .catalog-icon-button.is-active {
            color: var(--theme-info-text, #2563eb);
            background: var(--theme-info-surface, #eff6ff);
            border-color: var(--border, #bfdbfe);
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
            min-height: 44px;
            padding: .65rem .8rem .65rem 2.25rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #f8fafc;
        }

        #stock-adjust-modal .catalog-search {
            flex: none;
            min-width: 0;
        }

        #adjust-product-search {
            min-height: 48px;
            padding-left: 2.5rem;
            border-radius: 10px;
            background: var(--theme-surface, #fff);
        }

        #adjust-product-search:focus {
            outline: 2px solid var(--primary);
            outline-offset: -2px;
            box-shadow: none;
        }

        #adjust-product-search::-webkit-search-cancel-button {
            display: none;
        }

        #adjust-product-status {
            display: block;
            margin-top: .5rem;
            color: var(--muted);
            font-size: .875rem;
            line-height: 1.5;
            overflow-wrap: anywhere;
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
            min-height: 44px;
            padding: .55rem 2rem .55rem .7rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background-color: #fff;
            color: var(--text);
        }

        .catalog-show-field select,
        .catalog-sort-field select {
            appearance: none;
            background-image: none;
            padding-right: .85rem;
        }

        .catalog-show-field,
        .catalog-sort-field {
            flex: 0 1 180px;
        }

        .catalog-filter-button {
            min-height: 44px;
            white-space: nowrap;
        }

        .catalog-toolbar .product-add-menu {
            flex: 0 0 auto;
            margin-left: 0;
        }

        .catalog-toolbar .product-add-trigger {
            min-height: 44px;
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
            min-height: 44px;
            padding: .55rem .7rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--card-bg, #fff);
            color: var(--text);
        }

        .product-pagination-status {
            min-width: 86px;
            color: var(--muted);
            font-size: .82rem;
            text-align: center;
        }

        .products-page .main-content {
            min-width: 0;
        }

        .product-table-shell .data-table {
            width: 100%;
        }

        .catalog-empty {
            padding: clamp(2rem, 6vw, 4rem) 1.5rem;
            text-align: center;
        }

        .catalog-empty > div {
            max-width: 32rem;
            margin: auto;
        }

        .catalog-empty > div > i {
            display: inline-grid;
            width: 3.5rem;
            height: 3.5rem;
            place-items: center;
            border-radius: 14px;
            background: var(--theme-info-surface, #eff6ff);
            color: var(--theme-info-text, #2563eb);
            font-size: 1.05rem;
        }

        .catalog-empty h2 { margin: 1.25rem 0 .5rem; }
        .catalog-empty p { margin: 0 auto 1.5rem; color: var(--muted); line-height: 1.5; }
        .catalog-empty .btn { gap: .5rem; }

        .product-table-shell .data-table-scroll {
            -webkit-overflow-scrolling: touch;
        }

        .product-table-shell .action-cell {
            min-width: 130px;
        }

        .product-table-shell .btn {
            white-space: nowrap;
        }

        .product-table-shell .product-copy strong,
        .product-table-shell .product-copy span {
            max-width: min(34vw, 340px);
        }

        .bulk-bar {
            flex-wrap: wrap;
        }

        .bulk-bar strong {
            min-width: max-content;
        }

        #add-product-modal .rm-modal {
            width: min(880px, calc(100vw - 2rem));
        }

        #add-product-modal .rm-modal-actions {
            flex-wrap: wrap;
        }

        #add-product-modal .u-flex-wrap > input {
            min-width: min(100%, 240px);
        }

        @media (max-width: 1100px) {
            .catalog-toolbar-primary {
                display: grid;
                grid-template-columns: auto minmax(240px, 1fr) minmax(150px, .55fr) minmax(160px, .65fr) auto auto;
                align-items: end;
            }

            .catalog-filter-row {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .catalog-utility-controls {
                grid-column: 1 / -1;
                justify-content: flex-start;
                flex-wrap: wrap;
            }

            .catalog-search,
            .catalog-field,
            .catalog-show-field,
            .catalog-sort-field {
                min-width: 0;
            }

            .catalog-toolbar .product-add-trigger {
                min-width: 92px;
            }

            .product-table-shell .data-table {
                min-width: 780px;
            }

            .product-table-shell .product-copy strong,
            .product-table-shell .product-copy span {
                max-width: min(30vw, 280px);
            }

            .table-pagination {
                justify-content: flex-start;
            }

            .pagination-buttons {
                display: flex;
                flex-wrap: wrap;
                gap: .35rem;
            }
        }

        @media (min-width: 641px) and (max-width: 1100px) {
            .catalog-toolbar-primary {
                grid-template-columns: auto minmax(0, 1fr) minmax(150px, .7fr);
            }

            .catalog-filter-button,
            .catalog-toolbar .product-add-menu {
                grid-column: auto;
            }

            .catalog-sort-field {
                grid-column: 2;
            }

            .catalog-filter-button {
                justify-self: stretch;
            }

            .catalog-filter-button,
            .catalog-toolbar .product-add-trigger {
                width: 100%;
            }

            .product-add-menu-list {
                right: 0;
                left: auto;
            }

            .catalog-filter-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .product-table-shell .data-table {
                min-width: 680px;
            }

            .bulk-bar {
                align-items: stretch;
            }

            .bulk-bar strong {
                width: 100%;
            }

            .bulk-bar .btn {
                flex: 1 1 150px;
            }

            .rm-drawer {
                width: min(520px, 88vw);
            }

            #add-product-modal .rm-modal {
                width: min(760px, calc(100vw - 1.5rem));
            }

            #add-product-modal .rm-modal-body {
                max-height: calc(100vh - 13rem);
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

            .catalog-view-controls > *,
            .catalog-utility-controls > *,
            .catalog-filter-button,
            .catalog-toolbar .product-add-trigger,
            .product-add-menu,
            .product-add-menu-list {
                width: 100%;
            }

            .catalog-view-controls {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                display: grid;
            }

            .catalog-icon-button {
                width: 100%;
            }

            .catalog-utility-controls select,
            .catalog-utility-controls .toolbar-count {
                flex: 1 1 100%;
            }

            .product-table-shell tr {
                grid-template-columns: auto minmax(0, 1fr);
            }

            .product-table-shell td.product-cell,
            .product-table-shell td.stock-cell,
            .product-table-shell td.status-cell,
            .product-table-shell td.action-cell {
                grid-column: 1 / -1;
            }

            .product-table-shell .product-copy strong,
            .product-table-shell .product-copy span {
                max-width: calc(100vw - 8.5rem);
            }

            .product-table-shell .stock-cell,
            .product-table-shell .status-cell {
                padding-left: calc(40px + .7rem);
            }

            .product-table-shell .row-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .table-pagination {
                align-items: stretch;
            }

            .product-table-shell .data-table {
                min-width: 0;
            }

            .table-pagination label,
            .product-pagination-status,
            .pagination-buttons {
                width: 100%;
            }

            .pagination-buttons {
                justify-content: center;
            }

            #add-product-modal .rm-modal {
                width: calc(100vw - 1rem);
                max-height: calc(100vh - 1rem);
                border-radius: 12px;
            }

            #add-product-modal .rm-modal-body {
                max-height: calc(100vh - 12.5rem);
                padding: .9rem;
            }

            #add-product-modal .u-flex-wrap > .btn,
            #add-product-modal .u-flex-wrap > input {
                width: 100%;
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
        @media (max-width: 900px) {
            .product-table-shell { overflow: hidden; }
            .product-table-shell .data-table-scroll { overflow: auto; }
            .product-table-shell .data-table { display: table; min-width: 980px; }
            .product-table-shell thead { display: table-header-group; }
            .product-table-shell tbody { display: table-row-group; }
            .product-table-shell tr { display: table-row; }
            .product-table-shell td,
            .product-table-shell td.mobile-hide { display: table-cell; padding: .8rem 1rem; }
        }
    </style>
<?php require __DIR__ . '/../../../backend/includes/inventory_drafts.php'; ?>
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
                        <button type="button" class="btn btn-quiet btn-icon catalog-filter-button" id="catalog-filter-toggle" aria-expanded="false" aria-controls="catalog-filter-row"><i class="bi bi-funnel" aria-hidden="true"></i>Filter</button>
                        <div class="product-add-menu" id="product-add-menu">
                            <button type="button" class="btn product-add-trigger" id="product-add-trigger" aria-expanded="false" aria-controls="product-add-options" aria-haspopup="menu"><i class="bi bi-plus-lg" aria-hidden="true"></i><span>Add</span></button>
                            <div class="product-add-menu-list" id="product-add-options" role="menu" hidden>
                                <?php if ($canManageInventory): ?><button type="button" class="product-add-menu-item" id="add-product-btn" role="menuitem"><i class="bi bi-box-seam" aria-hidden="true"></i><strong>Add Product</strong></button><button type="button" class="product-add-menu-item" id="add-category-btn" role="menuitem"><i class="bi bi-folder-plus" aria-hidden="true"></i><strong>Add Category</strong></button><?php endif; ?>
                                <a class="product-add-menu-item" role="menuitem" href="<?= htmlspecialchars(app_url('components/inventory_management/print_barcodes.php')) ?>"><i class="bi bi-upc-scan" aria-hidden="true"></i><strong>Barcode Labels</strong></a>
                                <?php if ($canManageInventory): ?><a class="product-add-menu-item" role="menuitem" href="<?= htmlspecialchars(app_url('components/inventory_management/csv_import.php')) ?>"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i><strong>CSV Import</strong></a><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="catalog-filter-row" id="catalog-filter-row" hidden>
                        <label class="catalog-field"><span>Category</span><select id="category-filter" aria-label="Filter by category"><option value="">All Categories</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option><?php endforeach; ?></select></label>
                        <label class="catalog-field"><span>Status</span><select id="status-filter" aria-label="Filter by product status"><option value="">All Statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                        <label class="catalog-field"><span>Price</span><select id="price-filter" aria-label="Filter by selling price"><option value="">Any Price</option><option value="0:50">Under ₱50</option><option value="50:100">₱50 - ₱100</option><option value="100:500">₱100 - ₱500</option><option value="500:">₱500 and above</option></select></label>
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

                <section class="data-table-shell catalog-empty" id="catalog-empty" <?= $totalProducts ? 'hidden' : '' ?> aria-live="polite">
                    <div id="catalog-first-use" <?= $totalProducts ? 'hidden' : '' ?>>
                        <i class="bi bi-box-seam" aria-hidden="true"></i>
                        <h2>No products in the catalog yet</h2>
                        <p>Products appear here with their prices, barcodes, and stock.</p>
                        <?php if ($canManageInventory): ?>
                            <button type="button" class="btn" id="empty-add-product"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Product</button>
                        <?php else: ?>
                            <p>Ask your Inventory Manager to add the first product.</p>
                        <?php endif; ?>
                    </div>
                    <div id="catalog-no-results" hidden>
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <h2>No matching products</h2>
                        <p>Try another search or clear the filters to see the catalog.</p>
                        <button type="button" class="btn btn-quiet" id="empty-clear-filters">Clear filters</button>
                    </div>
                </section>

                <div class="data-table-shell product-table-shell" id="catalog-results" <?= $totalProducts ? '' : 'hidden' ?>>
                    <div class="data-table-scroll">
                        <table class="data-table" id="products-table">
                            <thead>
                                <tr>
                                    <th class="u-col-select"><input type="checkbox" id="select-all" aria-label="Select all visible products"></th>
                                    <th class="sortable" data-sort="name">Product <i class="bi bi-arrow-down-up"></i></th>
                                    <th class="column-cost sortable" data-sort="cost">Cost Price <i class="bi bi-arrow-down-up"></i></th>
                                    <th class="column-price sortable" data-sort="price">Selling Price <i class="bi bi-arrow-down-up"></i></th>
                                    <th class="column-profit sortable" data-sort="profit">Profit <i class="bi bi-arrow-down-up"></i></th>
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
                                    $expiration = (string)($product['next_expiration_date'] ?? '');
                                    $expiredQty = (int)($product['expired_quantity'] ?? 0);
                                    $todayManila = \App\Services\PhilippineTime::today();
                                    $todayTs = strtotime($todayManila);
                                    $leadDays = (isset($product['warning_lead_time_days']) && $product['warning_lead_time_days'] !== null && $product['warning_lead_time_days'] !== '')
                                        ? (int)$product['warning_lead_time_days']
                                        : 30;
                                    $limitTs = strtotime("+{$leadDays} days", $todayTs);
                                    $expiring = $expiration !== '' && strtotime($expiration) !== false && strtotime($expiration) <= $limitTs && strtotime($expiration) >= $todayTs;
                                    $isExpired = $expiredQty > 0;
                                    $status = (string)($product['status'] ?? 'active');
                                    $displayStatus = $status !== 'active' ? 'inactive' : ($stockState === 'out' ? 'out' : ($isExpired ? 'expired' : ($stockState === 'low' ? 'low' : ($expiring ? 'expiring' : 'good'))));
                                    $statusText = $status !== 'active' ? 'Inactive' : ($stockState === 'out' ? 'Out of stock' : ($isExpired ? 'Expired' : ($stockState === 'low' ? 'Low stock' : ($expiring ? 'Expiring soon' : 'Available'))));
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
                                        data-cost="<?= (float)($product['cost_price'] ?? 0) ?>"
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
                                        <td class="column-cost mobile-hide">₱<?= number_format((float)($product['cost_price'] ?? 0), 2) ?></td>
                                        <td class="column-price mobile-hide">₱<?= number_format((float)($product['unit_price'] ?? 0), 2) ?></td>
                                        <td class="column-profit">₱<?= number_format((float)($product['cost_price'] ?? 0) - (float)($product['unit_price'] ?? 0), 2) ?></td>
                                        <td class="stock-cell"><span class="stock-value"><?= number_format($quantity) ?></span><?php if (!empty($product['pack_size']) && (int)$product['pack_size'] > 1): ?><br><small style="color:var(--text-muted);"><?= (int)($product['physical_sealed_packs'] ?? 0) ?> packs, <?= (int)($product['physical_loose_pieces'] ?? 0) ?> loose</small><?php if (!empty($product['expired_sealed_packs']) || !empty($product['expired_loose_pieces'])): ?><br><small style="color:var(--danger, #dc2626);"><?= (int)($product['expired_sealed_packs'] ?? 0) ?> pk, <?= (int)($product['expired_loose_pieces'] ?? 0) ?> loose expired</small><?php endif; ?><?php endif; ?><br><small>Reorder at <?= number_format((int)($product['reorder_level'] ?? 0)) ?></small></td>
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
                    </div>
                    <footer class="table-pagination" aria-label="Product table pagination">
                        <div><span id="page-summary">Showing <?= $totalProducts > 0 ? 1 : 0 ?>–<?= min(10, $totalProducts) ?> of <?= $totalProducts ?></span></div>
                        <label>Rows <select id="page-size" aria-label="Rows per page">
                                <option>10</option>
                                <option>25</option>
                                <option>50</option>
                                <option>100</option>
                            </select></label>
                        <span class="product-pagination-status" id="page-status" aria-live="polite"></span>
                        <nav class="pagination-buttons" id="pagination-buttons" aria-label="Product pages"></nav>
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
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <?php if (current_role() === 'inventory_manager'): ?>
                        <button type="button" class="btn btn-small btn-secondary" id="product-edit-btn" aria-label="Edit product catalog details"><i class="bi bi-pencil" aria-hidden="true"></i> Edit product</button>
                    <?php endif; ?>
                    <button type="button" class="rm-close" data-close-drawer aria-label="Close product details"><i class="bi bi-x-lg"></i></button>
                </div>
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
                    <p>Complete the product details and enter any starting stock.</p>
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
                            <div class="form-group"><label for="product-brand">Brand</label><input name="brand" id="product-brand" maxlength="120" list="product-brand-suggestions" autocomplete="off"><datalist id="product-brand-suggestions"><?php foreach ($brandSuggestions as $brand): ?><option value="<?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?>"></option><?php endforeach; ?></datalist></div>
                            <div class="form-group"><label>Category *</label><select data-search-dropdown name="category_id" id="product-category" required>
                                    <option value="">Select category</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option><?php endforeach; ?>
                                </select><small class="field-error">Select a category.</small></div>
                            <div class="form-group"><label>Parent Product Family</label><select data-search-dropdown name="parent_product_id">
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
                            <div class="form-group full"><label for="create-barcode-input">Individual Item Barcode</label>
                                <div class="u-flex-wrap"><input class="u-input-grow" name="barcode" id="create-barcode-input" maxlength="50" placeholder="Scan, enter, generate, or leave blank"><button type="button" class="btn btn-quiet" id="add-product-scan-btn"><i class="bi bi-camera"></i> Scan</button><button type="button" class="btn btn-warning" id="generate-barcode-btn"><i class="bi bi-upc"></i> Generate</button></div><small class="field-help">RetailMind automatically creates a unique internal barcode when this is blank.</small><small class="field-error">This barcode is already used by another product.</small>
                            </div>
                            <div class="form-group"><label for="create-receiving-barcode">Receiving Package Barcode (optional)</label><input id="create-receiving-barcode" name="case_barcode" maxlength="80" placeholder="Outer carton, case, or box barcode"><small class="field-help">For receiving stock, not checkout. If you receive the same Sales Pack you sell, enter its barcode only under Sales Pack Barcode.</small></div>
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
                            <div class="form-group">
                                <label for="product-base-unit">Base Unit</label>
                                <select id="product-base-unit" data-unit-field="base_unit">
                                    <option value="piece">Piece</option><option value="bottle">Bottle</option><option value="can">Can</option><option value="sachet">Sachet</option>
                                    <option value="stick">Stick</option><option value="pack">Pack</option><option value="bag">Bag</option><option value="box">Box</option>
                                    <option value="jar">Jar</option><option value="tube">Tube</option><option value="roll">Roll</option><option value="pair">Pair</option><option value="pouch">Pouch</option>
                                    <option value="">Other…</option>
                                </select>
                                <div data-unit-custom hidden>
                                    <label for="product-base-unit-custom">Custom base unit</label>
                                    <input name="base_unit" id="product-base-unit-custom" value="piece" placeholder="Enter your unit">
                                    <small class="field-error">Enter a custom base unit.</small>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="product-receiving-unit">Receiving Unit</label>
                                <select id="product-receiving-unit" data-unit-field="receiving_unit">
                                    <option value="package">Package</option><option value="case">Case</option><option value="box">Box</option><option value="carton">Carton</option>
                                    <option value="pack">Pack</option><option value="bundle">Bundle</option><option value="bag">Bag</option><option value="sack">Sack</option>
                                    <option value="tray">Tray</option><option value="crate">Crate</option><option value="ream">Ream</option><option value="pallet">Pallet</option>
                                    <option value="">Other…</option>
                                </select>
                                <div data-unit-custom hidden>
                                    <label for="product-receiving-unit-custom">Custom receiving unit</label>
                                    <input name="receiving_unit" id="product-receiving-unit-custom" value="package" placeholder="Enter your unit">
                                    <small class="field-error">Enter a custom receiving unit.</small>
                                </div>
                            </div>
                            <div class="form-group full" style="border-top:1px solid var(--border);padding-top:0.75rem;margin-top:0.5rem;">
                                <strong style="font-size:0.9rem;display:block;margin-bottom:0.25rem;">Sales Packs — Sold at Checkout (optional)</strong>
                                <small class="field-help" style="display:block;margin-bottom:0.5rem;">Complete all three fields only if you also sell sealed packs of this item. Otherwise, leave them blank.</small>
                            </div>
                            <div class="form-group">
                                <label for="create-pack-size">Items per Sales Pack</label>
                                <input type="number" min="2" step="1" name="pack_size" id="create-pack-size" placeholder="e.g. 12">
                                <small class="field-help">Number of individual items in one sealed pack, e.g. 6 bottles.</small>
                            </div>
                            <div class="form-group">
                                <label for="create-pack-barcode">Sales Pack Barcode</label>
                                <input name="pack_barcode" id="create-pack-barcode" maxlength="50" placeholder="Distinct barcode for pack">
                                <small class="field-help">Scanned at checkout to sell a whole pack. Must differ from the individual item barcode, SKU, and receiving package barcode.</small>
                            </div>
                            <div class="form-group">
                                <label for="create-pack-price">Sales Pack Price (₱)</label>
                                <input type="number" step="0.01" min="0.01" name="pack_price" id="create-pack-price" placeholder="Bundle price">
                                <small class="field-help">Price for one whole pack. Must be greater than zero and cheaper than buying its items individually.</small>
                            </div>
                        </div>
                    </section>

                    <section class="wizard-panel" data-panel="4">
                        <div class="form-grid">
                            <h3 class="full planning-section-title">Starting Stock</h3>
                            <div class="form-group full">
                                <div id="stock-pieces-wrapper">
                                    <label for="initial-stock-quantity">Starting pieces</label>
                                    <input id="initial-stock-quantity" type="number" name="initial_stock_quantity" min="0" max="2147483647" step="1" value="0" required>
                                    <small class="field-help" id="stock-help-pieces">How many items do you have now? Enter 0 if you have none.</small>
                                </div>
                                <div id="stock-sales-packs-wrapper" hidden>
                                    <div class="form-grid">
                                        <div>
                                            <label for="starting-packs-count">Sales Packs</label>
                                            <input id="starting-packs-count" name="starting_packs" type="number" min="0" max="2147483647" step="1" value="0" placeholder="0" disabled>
                                        </div>
                                        <div>
                                            <label for="starting-pack-loose-count">Loose pieces</label>
                                            <input id="starting-pack-loose-count" name="starting_loose" type="number" min="0" max="2147483647" step="1" value="0" placeholder="0" disabled>
                                        </div>
                                    </div>
                                    <small class="field-help" id="stock-sales-pack-calc-preview" aria-live="polite"></small>
                                </div>
                                <small class="field-error">Enter whole numbers of zero or more. Total stock cannot exceed 2,147,483,647 pieces.</small>
                            </div>
                            <div class="form-group full" id="starting-stock-expiration-group" hidden><label for="starting-stock-expiration">Starting Stock Expiration</label><input type="date" id="starting-stock-expiration" name="expiration_date"></div>
                            <h3 class="full planning-section-title">Restocking</h3>
                            <div class="form-group"><label for="units-per-package">Items per Receiving Package</label><input type="number" min="1" id="units-per-package" name="units_per_package" value="1"><small class="field-help">Individual items in one receiving package, e.g. 24 bottles per carton. This may differ from a Sales Pack.</small></div>
                            <div class="form-group"><label>Minimum Order Quantity</label><input type="number" min="1" name="minimum_order_quantity" value="1"></div>
                            <div class="form-group"><label>Reorder Level</label><input type="number" min="0" name="reorder_level" value="10"></div>
                            <div class="form-group"><label>Safety Stock</label><input type="number" min="0" name="safety_stock" value="0"></div>
                            <div class="form-group"><label for="warning-lead-time-days">Expiry Warning Lead Time (Days)</label><input id="warning-lead-time-days" type="number" min="1" step="1" name="warning_lead_time_days" placeholder="Default (30)"></div>
                            <h3 class="full planning-section-title">Supplier</h3>
                            <div class="form-group full"><label for="preferred-supplier">Supplier Name (optional)</label><input id="preferred-supplier" name="preferred_supplier"></div>
                            <div class="form-group full"><label for="supplier-lead-time">Supplier Lead Time (Days, optional)</label><input id="supplier-lead-time" type="number" min="0" name="supplier_lead_time_days"></div>
                        </div>
                    </section>

                    <section class="wizard-panel" data-panel="5">
                        <div class="detail-grid" id="product-review"></div>
                        <div class="u-info-note"><i class="bi bi-info-circle"></i> Starting stock is the number of items you have now. Use Stock Receiving to add future deliveries.</div>
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
                        <div class="form-group">
                            <label for="adjust-product-search">Scan barcode or search products</label>
                            <div class="catalog-search">
                                <i class="bi bi-upc-scan" aria-hidden="true"></i>
                                <input type="search" id="adjust-product-search" list="adjust-product-options" required autocomplete="off" placeholder="Barcode, product name or SKU" aria-describedby="adjust-product-status">
                            </div>
                            <input type="hidden" name="product_id" id="adjust-product-id">
                            <datalist id="adjust-product-options">
                                <?php foreach ($activeProducts as $product): ?><option value="<?= htmlspecialchars($product['sku'] . ' — ' . $product['product_name'] . ' (' . (int)$product['quantity_on_hand'] . ' on hand)', ENT_QUOTES) ?>" data-product-id="<?= (int)$product['product_id'] ?>" data-sku="<?= htmlspecialchars($product['sku'], ENT_QUOTES) ?>" data-barcode="<?= htmlspecialchars($product['barcode'] ?? '', ENT_QUOTES) ?>" label="<?= htmlspecialchars($product['barcode'] ?? '', ENT_QUOTES) ?>"></option><?php endforeach; ?>
                            </datalist>
                            <small id="adjust-product-status" role="status">Scan into this field or type to find a product.</small>
                        </div>
                        <div class="form-group"><label>Quantity Change</label><input type="number" name="qty_change" required placeholder="Use 5 to add or -3 to remove"></div>
                        <div class="form-group"><label>Specific Approved Reason</label><input name="adjustment_reason" minlength="8" required placeholder="Example: Verified physical-count correction"></div>
                    </div>
                    <footer class="rm-modal-actions"><button type="button" class="btn btn-quiet" data-close-modal>Cancel</button><button class="btn btn-danger" type="submit">Apply Adjustment</button></footer>
                </form>
            </section>
        </div>

        <div class="rm-modal-overlay" id="pack-reconcile-modal" aria-hidden="true">
            <section class="rm-modal u-modal-form" role="dialog" aria-modal="true" aria-labelledby="reconcile-title">
                <header class="rm-modal-header">
                    <div>
                        <h2 id="reconcile-title">Physical Sales Pack Reconciliation</h2>
                        <p>Record verified physical counts of sealed packs and loose pieces.</p>
                    </div><button type="button" class="rm-close" data-close-modal><i class="bi bi-x-lg"></i></button>
                </header>
                <form method="POST" id="pack-reconcile-form" data-confirm="This will reconcile batch balances to match verified physical pack counts." data-confirm-title="Confirm pack reconciliation" data-confirm-button="Apply reconciliation">
                    <div class="rm-modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="reconcile_sales_pack_stock">
                        <input type="hidden" name="product_id" id="reconcile-product-id">
                        <div class="form-group"><label>Product</label><input type="text" id="reconcile-product-name" readonly class="form-input"></div>
                        <div class="form-grid">
                            <div class="form-group"><label for="reconcile-sealed-packs">Physical Sealed Packs</label><input type="number" min="0" step="1" name="sealed_packs" id="reconcile-sealed-packs" required value="0"></div>
                            <div class="form-group"><label for="reconcile-loose-pieces">Physical Loose Pieces</label><input type="number" min="0" step="1" name="loose_pieces" id="reconcile-loose-pieces" required value="0"></div>
                        </div>
                        <p id="reconcile-calc-preview" style="font-weight:600;color:var(--primary);margin:0.25rem 0 0.75rem;"></p>
                        <div class="form-group"><label for="reconcile-reason">Specific Approved Reason *</label><input name="reconciliation_reason" id="reconcile-reason" minlength="8" required placeholder="Example: Verified shelf count reconciliation"></div>
                    </div>
                    <footer class="rm-modal-actions"><button type="button" class="btn btn-quiet" data-close-modal>Cancel</button><button class="btn btn-primary" type="submit">Apply Reconciliation</button></footer>
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
                    <div class="form-group"><label>New Category</label><select data-search-dropdown name="category_id" required>
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
            <div class="rm-modal-body"><label class="u-checkbox-row"><input type="checkbox" data-column="price" checked> Selling Price</label><label class="u-checkbox-row"><input type="checkbox" data-column="cost" checked> Cost Price</label><label class="u-checkbox-row"><input type="checkbox" data-column="profit" checked> Profit</label></div>
            <footer class="rm-modal-actions"><button type="button" class="btn" data-close-modal>Done</button></footer>
        </section>
    </div>

    <script src="<?= htmlspecialchars(app_url('assets/vendor/html5-qrcode/html5-qrcode.min.js')) ?>"></script>
    <script>
        const PRODUCT_PHOTO_LIMIT_MESSAGE = <?= json_encode(PRODUCT_PHOTO_LIMIT_MESSAGE) ?>;
        const productData = <?= json_encode(array_values($products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const canDirectAdjust = <?= $canDirectAdjust ? 'true' : 'false' ?>;
        const canEditProduct = <?= current_role() === 'inventory_manager' ? 'true' : 'false' ?>;
        const csrfToken = <?= json_encode(csrf_token()) ?>;
        const categoriesData = <?= json_encode(array_values($categories), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const productsById = Object.fromEntries(productData.map(product => [Number(product.product_id), product]));
        const existingBarcodes = new Set(productData.flatMap(product => [product.barcode, product.sku, product.case_barcode, product.pack_barcode]).map(b => String(b || '').trim().toLowerCase()).filter(Boolean));
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
        const pageStatus = document.getElementById('page-status');
        const paginationButtons = document.getElementById('pagination-buttons');
        let filteredRows = productRows.slice();
        let currentPage = 1;
        let sortKey = 'name';
        let sortDirection = 1;
        const selectedIds = new Set();

        const adjustmentModal = document.getElementById('stock-adjust-modal');
        if (adjustmentModal) {
            const search = document.getElementById('adjust-product-search');
            const selectedProduct = document.getElementById('adjust-product-id');
            const status = document.getElementById('adjust-product-status');
            const options = Array.from(document.getElementById('adjust-product-options').options);
            function searchAdjustmentProducts() {
                const term = search.value.trim().toLowerCase();
                const exact = term ? options.filter(option => [option.value, option.dataset.sku, option.dataset.barcode]
                    .some(code => code.toLowerCase() === term)) : [];
                const matches = exact.length ? exact : options.filter(option =>
                    `${option.value} ${option.dataset.barcode}`.toLowerCase().includes(term));
                selectedProduct.value = term && matches.length === 1 ? matches[0].dataset.productId : '';
                search.setCustomValidity(selectedProduct.value ? '' : 'Choose a matching product from the suggestions.');
                status.textContent = !term ? 'Scan into this field or type to find a product.'
                    : !matches.length ? 'No matching product. Try another barcode, name or SKU.'
                    : matches.length === 1 ? `${matches[0].value} selected.`
                    : `${matches.length} matching products. Choose a suggestion or keep typing.`;
            }
            search.addEventListener('input', searchAdjustmentProducts);
            search.addEventListener('keydown', event => {
                if (event.key !== 'Enter' || event.isComposing) return;
                event.preventDefault();
                searchAdjustmentProducts();
                search.select();
            });
            function showAdjustmentProduct() {
                search.value = options.find(option => option.dataset.productId === selectedProduct.value)?.value || '';
                searchAdjustmentProducts();
            }
            selectedProduct.addEventListener('change', showAdjustmentProduct);
            adjustmentModal.addEventListener('retailmind:overlayopen', () => {
                showAdjustmentProduct();
                search.focus();
                search.select();
            });
        }

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
        });
        document.getElementById('empty-add-product')?.addEventListener('click', () => openModal('add-product-modal'));
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
                    cost: [Number(a.dataset.cost), Number(b.dataset.cost)],
                    profit: [Number(a.dataset.cost) - Number(a.dataset.price), Number(b.dataset.cost) - Number(b.dataset.price)],
                    stock: [Number(a.dataset.stock), Number(b.dataset.stock)]
                } [sortKey] || [a.dataset.name, b.dataset.name];
                return (typeof values[0] === 'number' ? values[0] - values[1] : String(values[0]).localeCompare(String(values[1]))) * sortDirection;
            });
            filteredRows.forEach(row => row.parentElement.appendChild(row));
            if (resetPage) currentPage = 1;
            renderPage();
        }

        function renderPage() {
            const hasResults = filteredRows.length > 0;
            document.getElementById('catalog-results').hidden = !hasResults;
            document.getElementById('catalog-empty').hidden = hasResults;
            document.getElementById('catalog-first-use').hidden = hasResults || productRows.length > 0;
            document.getElementById('catalog-no-results').hidden = hasResults || productRows.length === 0;
            const size = Number(pageSizeInput.value || 10);
            const pages = Math.max(1, Math.ceil(filteredRows.length / size));
            currentPage = Math.min(currentPage, pages);
            const start = (currentPage - 1) * size;
            const visibleSet = new Set(filteredRows.slice(start, start + size));
            productRows.forEach(row => row.hidden = !visibleSet.has(row));
            resultCount.textContent = `${filteredRows.length} result${filteredRows.length === 1 ? '' : 's'}`;
            pageSummary.textContent = filteredRows.length ? `Showing ${start + 1}–${Math.min(start + size, filteredRows.length)} of ${filteredRows.length}` : 'Showing 0 results';
            pageStatus.textContent = `Page ${currentPage} of ${pages}`;
            paginationButtons.innerHTML = '';
            const makeButton = (label, page, ariaLabel, disabled = false, active = false) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = label;
                button.setAttribute('aria-label', ariaLabel);
                button.disabled = disabled;
                button.classList.toggle('active', active);
                if (active) button.setAttribute('aria-current', 'page');
                button.addEventListener('click', () => {
                    currentPage = page;
                    renderPage();
                    document.getElementById('product-table').scrollIntoView({
                        behavior: 'smooth'
                    });
                });
                return button;
            };
            paginationButtons.appendChild(makeButton('«', 1, 'First page', currentPage === 1));
            paginationButtons.appendChild(makeButton('‹', Math.max(1, currentPage - 1), 'Previous page', currentPage === 1));
            let first = Math.max(1, currentPage - 2);
            const last = Math.min(pages, first + 4);
            first = Math.max(1, last - 4);
            for (let page = first; page <= last; page++) {
                paginationButtons.appendChild(makeButton(String(page), page, `Page ${page}`, false, page === currentPage));
            }
            paginationButtons.appendChild(makeButton('›', Math.min(pages, currentPage + 1), 'Next page', currentPage === pages));
            paginationButtons.appendChild(makeButton('»', pages, 'Last page', currentPage === pages));
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
        document.getElementById('empty-clear-filters').addEventListener('click', clearFilters);

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
        document.getElementById('bulk-category')?.addEventListener('click', () => {
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
        document.getElementById('bulk-activate')?.addEventListener('click', () => submitBulkStatus('active'));
        document.getElementById('bulk-deactivate')?.addEventListener('click', () => submitBulkStatus('inactive'));
        document.getElementById('bulk-print')?.addEventListener('click', () => {
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
            if (!url) return '';
            if (/^(https?:\/\/|\/)/i.test(url)) return url;
            return '/' + url.replace(/^\/+/, '');
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
            const editBtn = document.getElementById('product-edit-btn');
            if (editBtn) editBtn.hidden = tabName !== 'info' || !canEditProduct || isEditingProduct;
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

        let currentDrawerProductId = null;
        let isEditingProduct = false;
        let isDrawerDirty = false;

        function renderProductDrawerQuantity(product) {
            const quantity = Number(product.quantity_on_hand || 0);
            const threshold = Math.max(Number(product.reorder_level || 0), Number(product.safety_stock || 0));
            const stockLabel = quantity <= 0 ? 'Out of stock' : quantity <= threshold ? 'Low stock' : 'Available';
            const hasPackConfig = product.pack_size && Number(product.pack_size) > 1;
            let actionsHtml = '';
            if (canDirectAdjust) {
                actionsHtml += `<button type="button" class="btn btn-icon" id="drawer-adjust-stock"><i class="bi bi-sliders" aria-hidden="true"></i>Adjust Stock</button>`;
                if (hasPackConfig) {
                    actionsHtml += `<button type="button" class="btn btn-secondary btn-icon" id="drawer-reconcile-pack"><i class="bi bi-box-seam" aria-hidden="true"></i>Reconcile Stock</button>`;
                }
            }
            const quantityActions = actionsHtml ? `<div class="product-quantity-actions">${actionsHtml}</div>` : '';

            let packGridItems = '';
            if (hasPackConfig) {
                const expSealed = Number(product.expired_sealed_packs || 0);
                const expLoose = Number(product.expired_loose_pieces || 0);
                const expiredNotice = (expSealed > 0 || expLoose > 0)
                    ? `<div class="detail-item full" style="color:var(--danger, #dc2626);"><span>Expired Stock (Non-saleable)</span><strong>${expSealed} pk, ${expLoose} loose (${expSealed * Number(product.pack_size) + expLoose} pcs)</strong></div>`
                    : '';
                packGridItems = `
        <div class="detail-item"><span>Physical Pack Stock</span><strong>${Number(product.physical_sealed_packs || 0)} pk / ${Number(product.physical_loose_pieces || 0)} loose</strong></div>
        <div class="detail-item"><span>Saleable Pack Stock</span><strong>${Number(product.saleable_sealed_packs || 0)} pk / ${Number(product.saleable_loose_pieces || 0)} loose</strong></div>${expiredNotice}`;
            }

            const quantityPanel = document.getElementById('product-panel-quantity');
            if (quantityPanel) {
                quantityPanel.innerHTML = `<div class="detail-grid">
        <div class="detail-item"><span>Current Stock</span><strong>${quantity.toLocaleString()} — ${stockLabel}</strong></div><div class="detail-item"><span>Reorder / Safety</span><strong>${Number(product.reorder_level || 0)} / ${Number(product.safety_stock || 0)}</strong></div>${packGridItems}
        <div class="detail-item"><span>MOQ / Units per Case</span><strong>${Number(product.minimum_order_quantity || 1)} / ${Number(product.units_per_package || 1)}</strong></div><div class="detail-item"><span>Units</span><strong>${escapeHtml(safe(product.base_unit))} / ${escapeHtml(safe(product.receiving_unit))}</strong></div>
        <div class="detail-item"><span>Lead Time</span><strong>${Number(product.supplier_lead_time_days || 0)} day(s)</strong></div><div class="detail-item"><span>Preferred Supplier</span><strong>${escapeHtml(safe(product.preferred_supplier || product.supplier))}</strong></div>
    </div>${quantityActions}`;
                const drawerAdjustButton = document.getElementById('drawer-adjust-stock');
                if (drawerAdjustButton) drawerAdjustButton.addEventListener('click', () => {
                    const productSelect = document.getElementById('adjust-product-id');
                    RetailMindUI.closeOverlay(document.getElementById('product-drawer'));
                    openModal('stock-adjust-modal');
                    if (productSelect) {
                        productSelect.value = String(product.product_id);
                        productSelect.dispatchEvent(new Event('change'));
                    }
                    document.querySelector('#stock-adjust-modal [name="qty_change"]')?.focus();
                });
                const drawerReconcileButton = document.getElementById('drawer-reconcile-pack');
                if (drawerReconcileButton) drawerReconcileButton.addEventListener('click', () => {
                    const form = document.getElementById('pack-reconcile-form');
                    if (form) form.dataset.packSize = String(product.pack_size || 1);
                    const prodIdInput = document.getElementById('reconcile-product-id');
                    const prodNameInput = document.getElementById('reconcile-product-name');
                    const sealedInput = document.getElementById('reconcile-sealed-packs');
                    const looseInput = document.getElementById('reconcile-loose-pieces');
                    if (prodIdInput) prodIdInput.value = String(product.product_id);
                    if (prodNameInput) prodNameInput.value = `${safe(product.sku)} — ${product.product_name}`;
                    if (sealedInput) sealedInput.value = String(Number(product.physical_sealed_packs || 0));
                    if (looseInput) looseInput.value = String(Number(product.physical_loose_pieces || 0));
                    updateReconcilePreview();
                    RetailMindUI.closeOverlay(document.getElementById('product-drawer'));
                    openModal('pack-reconcile-modal');
                    sealedInput?.focus();
                });
            }
            const actionsPanel = document.getElementById('product-panel-actions');
            if (actionsPanel) {
                actionsPanel.innerHTML = `<div class="attention-list">${quantity <= threshold ? '<div class="attention-item"><span class="attention-icon"><i class="bi bi-box-arrow-in-down"></i></span><span class="attention-copy"><strong>Replenishment needed</strong><span>Stock is at or below the planning threshold.</span></span><a class="btn btn-small" href="../report/stock_receiving.php">Receive</a></div>' : '<div class="attention-item"><span class="attention-icon u-stock-ok-icon"><i class="bi bi-check2"></i></span><span class="attention-copy"><strong>Stock level is healthy</strong><span>No immediate replenishment action is required.</span></span></div>'}</div>`;
            }
        }

        function renderProductDrawerReadOnly(product) {
            isEditingProduct = false;
            isDrawerDirty = false;
            document.getElementById('product-drawer-title').textContent = product.product_name + (product.variant_label ? ` — ${product.variant_label}` : '');
            document.getElementById('product-drawer-subtitle').textContent = `${safe(product.sku)} · ${safe(product.category_name, 'Uncategorized')}`;
            const editBtn = document.getElementById('product-edit-btn');
            if (editBtn) editBtn.hidden = !canEditProduct || document.getElementById('product-panel-info').hidden;
            const packItems = product.pack_size && Number(product.pack_size) > 1 ? `
        <div class="detail-item"><span>Items per Sales Pack</span><strong>${Number(product.pack_size)} ${escapeHtml(product.base_unit || 'piece')}(s)</strong></div><div class="detail-item"><span>Sales Pack Barcode</span><strong>${escapeHtml(safe(product.pack_barcode, 'None'))}</strong></div>
        <div class="detail-item"><span>Sales Pack Price</span><strong>${product.pack_price ? formatMoney(product.pack_price) : '—'}</strong></div><div class="detail-item"><span>Sealed / Loose</span><strong>${Number(product.physical_sealed_packs || 0)} pk / ${Number(product.physical_loose_pieces || 0)} pcs</strong></div>` : '';
            document.getElementById('product-panel-info').innerHTML = `<div class="detail-grid">
        <div class="detail-item"><span>Status</span><strong>${escapeHtml(safe(product.status))}</strong></div><div class="detail-item"><span>Category</span><strong>${escapeHtml(safe(product.category_name, 'Uncategorized'))}</strong></div>
        <div class="detail-item"><span>Brand</span><strong>${escapeHtml(safe(product.brand))}</strong></div><div class="detail-item"><span>Variant Label</span><strong>${escapeHtml(safe(product.variant_label))}</strong></div>
        <div class="detail-item"><span>SKU</span><strong>${escapeHtml(safe(product.sku))}</strong></div><div class="detail-item"><span>Individual Item Barcode</span><strong>${escapeHtml(safe(product.barcode, 'Not assigned'))}</strong></div>
        <div class="detail-item"><span>Cost Price</span><strong>${formatMoney(product.cost_price)}</strong></div><div class="detail-item"><span>Selling Price</span><strong>${formatMoney(product.unit_price)}</strong></div>
        <div class="detail-item"><span>Next Expiration</span><strong>${escapeHtml(safe(product.next_expiration_date, '—'))}</strong></div><div class="detail-item"><span>Expiry Warning Lead Time</span><strong>${product.warning_lead_time_days ? escapeHtml(product.warning_lead_time_days) + ' day(s)' : 'Default'}</strong></div>${packItems}<div class="detail-item full"><span>Product Image</span><strong>${safeLink(product.product_image) ? `<a href="${escapeHtml(safeLink(product.product_image))}" target="_blank" rel="noopener">Open product image</a>` : 'No image assigned'}</strong></div>
    </div>`;
            renderProductDrawerQuantity(product);
        }

        function enterProductEditMode() {
            if (!canEditProduct) return;
            const product = productsById[currentDrawerProductId];
            if (!product) return;

            isEditingProduct = true;
            isDrawerDirty = false;
            const editBtn = document.getElementById('product-edit-btn');
            if (editBtn) editBtn.hidden = true;

            setProductDrawerTab('info');
            document.getElementById('product-drawer-title').textContent = `Edit ${product.product_name}`;

            const categoryOptions = categoriesData.map(c =>
                `<option value="${c.category_id}" ${Number(product.category_id) === Number(c.category_id) ? 'selected' : ''}>${escapeHtml(c.category_name)}</option>`
            ).join('');

            const hasParent = Number(product.parent_product_id || 0) > 0;

            document.getElementById('product-panel-info').innerHTML = `
                <form id="product-edit-form" novalidate enctype="multipart/form-data">
                    <div id="product-edit-error" class="alert alert-danger" role="alert" hidden></div>
                    <input type="hidden" name="action" value="update_catalog_details">
                    <input type="hidden" name="product_id" value="${product.product_id}">
                    <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">

                    <p class="product-edit-intro">Update catalog details and stock settings. Required fields are marked *.</p>
                    <fieldset class="settings-fieldset product-edit-section"><legend>Product information</legend>
                        <label class="form-field">
                            <span>Product Name <span class="product-edit-required" aria-hidden="true">*</span></span>
                            <input type="text" name="product_name" id="edit-product-name" required value="${escapeHtml(product.product_name || '')}" class="form-input">
                        </label>
                        ${hasParent ? `
                        <label class="form-field">
                            <span>Variant Label <small>(max 100 characters)</small></span>
                            <input type="text" name="variant_label" id="edit-variant-label" maxlength="100" value="${escapeHtml(product.variant_label || '')}" class="form-input">
                        </label>
                        ` : `
                        <div class="form-field">
                            <span>Variant Label</span>
                            <span>Not a variant product (parent identity preserved)</span>
                        </div>
                        `}
                        <div class="form-grid">
                            <label class="form-field">
                                <span>Brand</span>
                                <input type="text" name="brand" id="edit-brand" list="brand-suggestions-list" value="${escapeHtml(product.brand || '')}" class="form-input">
                            </label>
                            <label class="form-field">
                                <span>Category</span>
                                <select data-search-dropdown name="category_id" id="edit-category-id" class="form-input">
                                <option value="">Uncategorized</option>
                                ${categoryOptions}
                                </select>
                            </label>
                        </div>
                        <div class="form-field">
                            <label for="edit-product-image">Product Image <small>(JPEG, PNG, GIF, WebP, max 5MB)</small></label>
                            ${safeLink(product.product_image) ? `<div>Current: <a href="${escapeHtml(safeLink(product.product_image))}" target="_blank" rel="noopener">Open image</a></div>` : ''}
                            <input type="file" name="product_image" id="edit-product-image" accept="image/jpeg,image/png,image/gif,image/webp" class="form-input">
                        </div>
                    </fieldset>
                    <fieldset class="settings-fieldset product-edit-section"><legend>Pricing & availability</legend>
                        <div class="form-grid">
                            <label class="form-field">
                                <span>Cost Price (₱) <span class="product-edit-required" aria-hidden="true">*</span></span>
                                <input type="number" step="0.01" min="0" max="99999999.99" name="cost_price" id="edit-cost-price" required value="${(Number(product.cost_price) || 0).toFixed(2)}" class="form-input">
                            </label>
                            <label class="form-field">
                                <span>Selling Price (₱) <span class="product-edit-required" aria-hidden="true">*</span></span>
                                <input type="number" step="0.01" min="0.01" max="999999999.99" name="selling_price" id="edit-selling-price" required value="${(Number(product.unit_price) || 0).toFixed(2)}" class="form-input">
                            </label>
                        </div>
                        <div id="selling-price-warning" role="alert" style="display:none;"></div>
                        <label class="form-field" for="edit-status">
                            <span>Status <span class="product-edit-required" aria-hidden="true">*</span></span>
                            <select name="status" id="edit-status" class="form-input">
                            <option value="active" ${product.status === 'active' ? 'selected' : ''}>Active</option>
                            <option value="inactive" ${product.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                            </select>
                        </label>
                    </fieldset>
                    <fieldset class="settings-fieldset product-edit-section"><legend>Stock settings</legend>
                        <div class="form-grid">
                            <label class="form-field" for="edit-units-per-package">
                                <span>Units per Package/Case <span class="product-edit-required" aria-hidden="true">*</span> <small>(${escapeHtml(safe(product.base_unit, 'unit'))}s per ${escapeHtml(safe(product.receiving_unit, 'package'))})</small></span>
                                <input type="number" step="1" min="1" max="2147483647" name="units_per_package" id="edit-units-per-package" required value="${Number(product.units_per_package || 1)}" class="form-input">
                            </label>
                            <label class="form-field" for="edit-moq">
                                <span>Minimum Order Quantity (MOQ) <span class="product-edit-required" aria-hidden="true">*</span> <small>(in ${escapeHtml(safe(product.base_unit, 'unit'))}s)</small></span>
                                <input type="number" step="1" min="1" max="2147483647" name="minimum_order_quantity" id="edit-moq" required value="${Number(product.minimum_order_quantity || 1)}" class="form-input">
                            </label>
                            <label class="form-field" for="edit-reorder-level">
                                <span>Reorder Level <span class="product-edit-required" aria-hidden="true">*</span> <small>(in ${escapeHtml(safe(product.base_unit, 'unit'))}s)</small></span>
                                <input type="number" step="1" min="0" max="2147483647" name="reorder_level" id="edit-reorder-level" required value="${Number(product.reorder_level || 0)}" class="form-input">
                            </label>
                            <label class="form-field" for="edit-safety-stock">
                                <span>Safety Stock <span class="product-edit-required" aria-hidden="true">*</span> <small>(in ${escapeHtml(safe(product.base_unit, 'unit'))}s)</small></span>
                                <input type="number" step="1" min="0" max="2147483647" name="safety_stock" id="edit-safety-stock" required value="${Number(product.safety_stock || 0)}" class="form-input">
                            </label>
                        </div>
                        <label class="form-field" for="edit-warning-lead-time">
                            <span>Expiry Warning Lead Time (Days) <small>(Blank uses default 30 days)</small></span>
                            <input type="number" step="1" min="1" max="2147483647" name="warning_lead_time_days" id="edit-warning-lead-time" value="${product.warning_lead_time_days !== null && product.warning_lead_time_days !== undefined && product.warning_lead_time_days !== '' ? Number(product.warning_lead_time_days) : ''}" placeholder="Default (30)" class="form-input">
                        </label>
                    </fieldset>
                    <fieldset class="settings-fieldset product-edit-section"><legend>Sales Pack Configuration</legend>
                        <div class="form-grid">
                            <label class="form-field" for="edit-pack-size">
                                <span>Items per Sales Pack</span>
                                <input type="number" step="1" min="2" max="2147483647" name="pack_size" id="edit-pack-size" value="${product.pack_size ? Number(product.pack_size) : ''}" placeholder="None" class="form-input" ${Number(product.physical_sealed_packs || 0) > 0 ? 'readonly title="Cannot change pack size while sealed pack inventory exists"' : ''}>
                                ${Number(product.physical_sealed_packs || 0) > 0 ? `<small style="color:var(--warning-text, #b45309);">Cannot change pack size while ${product.physical_sealed_packs} sealed pack(s) exist. Reconcile stock first.</small>` : ''}
                            </label>
                            <label class="form-field" for="edit-pack-price">
                                <span>Sales Pack Price (₱)</span>
                                <input type="number" step="0.01" min="0.01" max="999999999.99" name="pack_price" id="edit-pack-price" value="${product.pack_price ? (Number(product.pack_price) || 0).toFixed(2) : ''}" placeholder="None" class="form-input">
                            </label>
                        </div>
                        <label class="form-field" for="edit-pack-barcode">
                            <span>Sales Pack Barcode</span>
                            <input type="text" name="pack_barcode" id="edit-pack-barcode" maxlength="50" value="${escapeHtml(product.pack_barcode || '')}" placeholder="None" class="form-input">
                        </label>
                    </fieldset>
                    <div class="product-edit-actions">
                        <button type="button" class="btn btn-secondary" id="product-cancel-btn">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="product-save-btn"><i class="bi bi-check-lg" aria-hidden="true"></i> Save changes</button>
                    </div>
                </form>
            `;

            const priceInput = document.getElementById('edit-selling-price');
            const priceWarning = document.getElementById('selling-price-warning');
            const costInput = document.getElementById('edit-cost-price');

            function updatePriceWarning() {
                const costPrice = Number(costInput.value);
                const val = parseFloat(priceInput.value);
                if (!isNaN(val) && costPrice > 0 && val < costPrice) {
                    priceWarning.textContent = `Warning: Selling price (${formatMoney(val)}) is below cost price (${formatMoney(costPrice)})`;
                    priceWarning.style.display = 'block';
                } else {
                    priceWarning.textContent = '';
                    priceWarning.style.display = 'none';
                }
            }
            if (priceInput && priceWarning) {
                priceInput.addEventListener('input', updatePriceWarning);
                costInput.addEventListener('input', updatePriceWarning);
                updatePriceWarning();
            }

            const form = document.getElementById('product-edit-form');
            form.addEventListener('input', () => { isDrawerDirty = true; });
            form.addEventListener('change', () => { isDrawerDirty = true; });

            document.getElementById('product-cancel-btn').addEventListener('click', cancelProductEdit);
            form.addEventListener('submit', saveProductCatalogDetails);
            RetailMindUI.initSearchDropdowns();
        }

        async function cancelProductEdit() {
            if (isDrawerDirty) {
                const discard = await RetailMindUI.confirm({
                    title: 'Discard unsaved changes?',
                    message: 'You have unsaved changes in the product editor. Are you sure you want to discard them?',
                    confirmText: 'Discard changes',
                    danger: true
                });
                if (!discard) return;
            }
            const product = productsById[currentDrawerProductId];
            if (product) renderProductDrawerReadOnly(product);
        }

        async function saveProductCatalogDetails(event) {
            event.preventDefault();
            const product = productsById[currentDrawerProductId];
            if (!product) return;

            const errorBox = document.getElementById('product-edit-error');
            errorBox.hidden = true;

            const nameInput = document.getElementById('edit-product-name');
            const productName = nameInput ? nameInput.value.trim() : '';
            if (!productName) {
                errorBox.textContent = 'Product name is required.';
                errorBox.hidden = false;
                nameInput?.focus();
                return;
            }

            const variantInput = document.getElementById('edit-variant-label');
            if (variantInput) {
                const variantLabel = variantInput.value.trim();
                if (Number(product.parent_product_id || 0) > 0 && !variantLabel) {
                    errorBox.textContent = 'Variant label is required for variant products.';
                    errorBox.hidden = false;
                    variantInput.focus();
                    return;
                }
                if (variantLabel.length > 100) {
                    errorBox.textContent = 'Variant label must not exceed 100 characters.';
                    errorBox.hidden = false;
                    variantInput.focus();
                    return;
                }
            }

            const costInput = document.getElementById('edit-cost-price');
            const costPriceVal = costInput?.value.trim() ? Number(costInput.value) : NaN;
            if (!Number.isFinite(costPriceVal) || costPriceVal < 0 || costPriceVal > 99999999.99) {
                errorBox.textContent = 'Cost price must be between PHP 0 and PHP 99,999,999.99.';
                errorBox.hidden = false;
                costInput?.focus();
                return;
            }

            const priceInput = document.getElementById('edit-selling-price');
            const sellingPriceVal = priceInput ? parseFloat(priceInput.value) : NaN;
            if (!priceInput || isNaN(sellingPriceVal) || sellingPriceVal <= 0 || !isFinite(sellingPriceVal)) {
                errorBox.textContent = 'Selling price must be greater than PHP 0.';
                errorBox.hidden = false;
                priceInput?.focus();
                return;
            }
            if (sellingPriceVal > 999999999.99) {
                errorBox.textContent = 'Selling price is out of range.';
                errorBox.hidden = false;
                priceInput?.focus();
                return;
            }

            const statusInput = document.getElementById('edit-status');
            const statusVal = statusInput ? statusInput.value.trim() : '';
            if (statusVal !== 'active' && statusVal !== 'inactive') {
                errorBox.textContent = "Status must be strictly 'active' or 'inactive'.";
                errorBox.hidden = false;
                statusInput?.focus();
                return;
            }

            const packInput = document.getElementById('edit-units-per-package');
            const packVal = packInput ? packInput.value.trim() : '';
            if (!packInput || !/^\d+$/.test(packVal) || Number(packVal) < 1 || Number(packVal) > 2147483647) {
                errorBox.textContent = 'Units per package must be a whole number of at least 1.';
                errorBox.hidden = false;
                packInput?.focus();
                return;
            }

            const moqInput = document.getElementById('edit-moq');
            const moqVal = moqInput ? moqInput.value.trim() : '';
            if (!moqInput || !/^\d+$/.test(moqVal) || Number(moqVal) < 1 || Number(moqVal) > 2147483647) {
                errorBox.textContent = 'Minimum order quantity must be a whole number of at least 1.';
                errorBox.hidden = false;
                moqInput?.focus();
                return;
            }

            const reorderInput = document.getElementById('edit-reorder-level');
            const reorderVal = reorderInput ? reorderInput.value.trim() : '';
            if (!reorderInput || !/^\d+$/.test(reorderVal) || Number(reorderVal) < 0 || Number(reorderVal) > 2147483647) {
                errorBox.textContent = 'Reorder level must be a whole number of 0 or more.';
                errorBox.hidden = false;
                reorderInput?.focus();
                return;
            }

            const safetyInput = document.getElementById('edit-safety-stock');
            const safetyVal = safetyInput ? safetyInput.value.trim() : '';
            if (!safetyInput || !/^\d+$/.test(safetyVal) || Number(safetyVal) < 0 || Number(safetyVal) > 2147483647) {
                errorBox.textContent = 'Safety stock must be a whole number of 0 or more.';
                errorBox.hidden = false;
                safetyInput?.focus();
                return;
            }

            const leadTimeInput = document.getElementById('edit-warning-lead-time');
            if (leadTimeInput) {
                const leadTimeVal = leadTimeInput.value.trim();
                if (leadTimeVal !== '' && (!/^\d+$/.test(leadTimeVal) || Number(leadTimeVal) < 1 || Number(leadTimeVal) > 2147483647)) {
                    errorBox.textContent = 'Expiry warning lead time must be a whole number of at least 1 day.';
                    errorBox.hidden = false;
                    leadTimeInput.focus();
                    return;
                }
            }

            const imageInput = document.getElementById('edit-product-image');
            if (imageInput && imageInput.files && imageInput.files[0]) {
                const file = imageInput.files[0];
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type) || file.size > 5 * 1024 * 1024) {
                    errorBox.textContent = PRODUCT_PHOTO_LIMIT_MESSAGE;
                    errorBox.hidden = false;
                    return;
                }
            }

            const saveBtn = document.getElementById('product-save-btn');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving...';
            }

            try {
                const formData = new FormData(document.getElementById('product-edit-form'));
                const response = await fetch('products.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });
                const result = await response.json();
                if (result.success && result.product) {
                    await window.RetailMindInventoryDrafts?.discard(document.getElementById("product-edit-form"));
                    Object.assign(product, result.product);
                    updateProductTableRow(product);
                    isDrawerDirty = false;
                    renderProductDrawerReadOnly(product);
                    if (result.warning) {
                        RetailMindUI.toast(result.warning, 'warning');
                    } else {
                        RetailMindUI.toast('Product details updated successfully.', 'success');
                    }
                } else {
                    errorBox.textContent = result.error || 'The product details could not be saved.';
                    errorBox.hidden = false;
                }
            } catch (e) {
                errorBox.textContent = 'A network error occurred while saving. Please try again.';
                errorBox.hidden = false;
            } finally {
                if (saveBtn && isEditingProduct) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="bi bi-check-lg" aria-hidden="true"></i> Save changes';
                }
            }
        }

        function updateProductTableRow(product) {
            const row = document.querySelector(`tr[data-product-id="${product.product_id}"]`);
            if (!row) return;
            row.dataset.name = (product.product_name || '').toLowerCase();
            row.dataset.category = String(product.category_id || 0);
            row.dataset.categoryName = (product.category_name || '').toLowerCase();
            row.dataset.price = String(product.unit_price || 0);
            row.dataset.cost = String(product.cost_price || 0);
            row.dataset.status = product.status;
            row.dataset.search = [product.product_name, product.variant_label || '', product.sku || '', product.barcode || '', product.brand || ''].join(' ').toLowerCase();
            const copy = row.querySelector('.product-copy');
            if (copy) {
                const brandText = product.brand || 'No brand';
                const variantText = product.variant_label ? ` · ${product.variant_label}` : '';
                copy.innerHTML = `<strong>${escapeHtml(product.product_name)}</strong><span>${escapeHtml(brandText)}${escapeHtml(variantText)}</span>`;
            }
            const thumb = row.querySelector('.product-thumb');
            if (thumb) {
                if (product.product_image) {
                    thumb.innerHTML = `<img src="${escapeHtml(product.product_image)}" alt="" loading="lazy" onerror="this.parentElement.innerHTML='<i class=&quot;bi bi-box-seam&quot;></i>'">`;
                } else {
                    thumb.innerHTML = '<i class="bi bi-box-seam"></i>';
                }
            }
            const priceCell = row.querySelector('.column-price');
            const costCell = row.querySelector('.column-cost');
            const profitCell = row.querySelector('.column-profit');
            if (profitCell) profitCell.textContent = formatMoney(Number(product.cost_price || 0) - Number(product.unit_price || 0));
            if (costCell) costCell.textContent = formatMoney(product.cost_price);
            if (priceCell) priceCell.textContent = formatMoney(product.unit_price);
            const stockCell = row.querySelector('.stock-cell');
            if (stockCell) {
                let packBreakdown = '';
                if (product.pack_size && Number(product.pack_size) > 1) {
                    packBreakdown = `<br><small style="color:var(--text-muted);">${Number(product.physical_sealed_packs || 0)} packs, ${Number(product.physical_loose_pieces || 0)} loose</small>`;
                    const expSealed = Number(product.expired_sealed_packs || 0);
                    const expLoose = Number(product.expired_loose_pieces || 0);
                    if (expSealed > 0 || expLoose > 0) {
                        packBreakdown += `<br><small style="color:var(--danger, #dc2626);">${expSealed} pk, ${expLoose} loose expired</small>`;
                    }
                }
                stockCell.innerHTML = `<span class="stock-value">${Number(product.quantity_on_hand || 0).toLocaleString()}</span>${packBreakdown}<br><small>Reorder at ${Number(product.reorder_level || 0).toLocaleString()}</small>`;
            }
            const statusCell = row.querySelector('.status-cell');
            if (statusCell) {
                let badgeClass = 'inactive';
                let badgeText = 'Inactive';
                if (product.status === 'active') {
                    const quantity = Number(product.quantity_on_hand || 0);
                    const threshold = Math.max(Number(product.reorder_level || 0), Number(product.safety_stock || 0));
                    const stockState = quantity <= 0 ? 'out' : (quantity <= threshold ? 'low' : 'available');
                    row.dataset.stockState = stockState;
                    const isExpiring = row.dataset.expiring === '1';
                    if (stockState === 'out') {
                        badgeClass = 'out';
                        badgeText = 'Out of stock';
                    } else if (stockState === 'low') {
                        badgeClass = 'low';
                        badgeText = 'Low stock';
                    } else if (isExpiring) {
                        badgeClass = 'expiring';
                        badgeText = 'Expiring soon';
                    } else {
                        badgeClass = 'good';
                        badgeText = 'Available';
                    }
                }
                statusCell.innerHTML = `<span class="status-badge ${badgeClass}">${escapeHtml(badgeText)}</span>`;
            }
        }

        function updateReconcilePreview() {
            const form = document.getElementById('pack-reconcile-form');
            if (!form) return;
            const packSize = Number(form.dataset.packSize || 1);
            const sealed = Math.max(0, parseInt(document.getElementById('reconcile-sealed-packs')?.value || '0', 10) || 0);
            const loose = Math.max(0, parseInt(document.getElementById('reconcile-loose-pieces')?.value || '0', 10) || 0);
            const total = (sealed * packSize) + loose;
            const preview = document.getElementById('reconcile-calc-preview');
            if (preview) {
                preview.textContent = `Calculated total: ${sealed} pack(s) of ${packSize} + ${loose} loose = ${total.toLocaleString()} Base Unit(s)`;
            }
        }

        document.getElementById('reconcile-sealed-packs')?.addEventListener('input', updateReconcilePreview);
        document.getElementById('reconcile-loose-pieces')?.addEventListener('input', updateReconcilePreview);

        const reconcileForm = document.getElementById('pack-reconcile-form');
        if (reconcileForm) {
            reconcileForm.addEventListener('submit', async event => {
                event.preventDefault();
                const reason = (document.getElementById('reconcile-reason')?.value || '').trim();
                if (reason.length < 8) {
                    RetailMindUI.toast('Enter a specific approved reason of at least 8 characters.', 'error');
                    return;
                }
                const confirmed = await RetailMindUI.confirm({
                    title: 'Confirm pack reconciliation',
                    message: 'This will reconcile batch balances to match verified physical pack counts.',
                    confirmText: 'Apply reconciliation',
                    danger: false
                });
                if (!confirmed) return;

                const submitBtn = reconcileForm.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Applying...';
                }

                try {
                    const formData = new FormData(reconcileForm);
                    const response = await fetch('products.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    });
                    const result = await response.json();
                    if (result.success && result.product) {
                        await RetailMindInventoryDrafts.discard(reconcileForm);
                        const prod = productsById[result.product.product_id] || {};
                        Object.assign(prod, result.product);
                        productsById[result.product.product_id] = prod;
                        updateProductTableRow(prod);
                        if (currentDrawerProductId === prod.product_id) {
                            renderProductDrawerReadOnly(prod);
                        }
                        RetailMindUI.closeOverlay(document.getElementById('pack-reconcile-modal'));
                        RetailMindUI.toast('Physical stock reconciled successfully.', 'success');
                    } else {
                        RetailMindUI.toast(result.error || 'Reconciliation failed.', 'error');
                    }
                } catch (e) {
                    RetailMindUI.toast(e.message || 'Network error during reconciliation.', 'error');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Apply Reconciliation';
                    }
                }
            });
        }

        function openProductDrawer(id) {
            const product = productsById[Number(id)];
            if (!product) return;
            currentDrawerProductId = Number(id);
            renderProductDrawerReadOnly(product);

            const footer = document.getElementById('product-drawer-footer');
            footer.innerHTML = product.barcode ? `<a class="btn btn-warning" target="_blank" href="print_barcodes.php?product_id=${Number(product.product_id)}&quantity=1"><i class="bi bi-printer"></i> Print Barcode</a>` : `<button type="button" class="btn btn-success drawer-generate" data-product-id="${Number(product.product_id)}"><i class="bi bi-upc"></i> Generate Barcode</button>`;
            footer.innerHTML += `<a class="btn btn-quiet" href="../report/stock_receiving.php"><i class="bi bi-box-arrow-in-down"></i> Stock Receiving</a>`;
            const generate = footer.querySelector('.drawer-generate');
            if (generate) generate.addEventListener('click', () => generateBarcode(generate.dataset.productId));
            setProductDrawerTab('info');
            RetailMindUI.openOverlay(document.getElementById('product-drawer'));
        }
        async function handleManageProduct(button) {
            if (isEditingProduct && isDrawerDirty) {
                const discard = await RetailMindUI.confirm({
                    title: 'Discard unsaved changes?',
                    message: 'You have unsaved changes in the product editor. Are you sure you want to discard them?',
                    confirmText: 'Discard and switch',
                    danger: true
                });
                if (!discard) return;
                isDrawerDirty = false;
            }
            openProductDrawer(button.dataset.productId);
        }
        document.getElementById('product-table-body')?.addEventListener('click', event => {
            const button = event.target.closest('.manage-product');
            if (button) handleManageProduct(button);
        });

        const drawerEditBtn = document.getElementById('product-edit-btn');
        if (drawerEditBtn) {
            drawerEditBtn.addEventListener('click', enterProductEditMode);
        }

        const drawerOverlay = document.getElementById('product-drawer');
        if (drawerOverlay) {
            drawerOverlay.querySelector('[data-close-drawer]')?.addEventListener('click', async event => {
                if (isEditingProduct && isDrawerDirty) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    const discard = await RetailMindUI.confirm({
                        title: 'Discard unsaved changes?',
                        message: 'You have unsaved changes in the product editor. Are you sure you want to discard them?',
                        confirmText: 'Discard and close',
                        danger: true
                    });
                    if (!discard) return;
                    isDrawerDirty = false;
                    RetailMindUI.closeOverlay(drawerOverlay);
                }
            }, true);

            drawerOverlay.addEventListener('click', async event => {
                if (event.target === drawerOverlay && isEditingProduct && isDrawerDirty) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    const discard = await RetailMindUI.confirm({
                        title: 'Discard unsaved changes?',
                        message: 'You have unsaved changes in the product editor. Are you sure you want to discard them?',
                        confirmText: 'Discard and close',
                        danger: true
                    });
                    if (!discard) return;
                    isDrawerDirty = false;
                    RetailMindUI.closeOverlay(drawerOverlay);
                }
            }, true);
        }

        window.addEventListener('keydown', async event => {
            if (event.key === 'Escape' && isEditingProduct && isDrawerDirty) {
                const overlay = document.getElementById('product-drawer');
                if (overlay && !overlay.hidden && overlay.classList.contains('open')) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    const discard = await RetailMindUI.confirm({
                        title: 'Discard unsaved changes?',
                        message: 'You have unsaved changes in the product editor. Are you sure you want to discard them?',
                        confirmText: 'Discard and close',
                        danger: true
                    });
                    if (!discard) return;
                    isDrawerDirty = false;
                    RetailMindUI.closeOverlay(overlay);
                }
            }
        }, true);

        window.addEventListener('beforeunload', event => {
            if (isEditingProduct && isDrawerDirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        function generateBarcode(id) {
            document.getElementById('generate-product-id').value = id;
            document.getElementById('generate-barcode-form').submit();
        }
        // Column preferences
        // Reset legacy hidden columns once for the revised comparison table.
        const columnPreferences = JSON.parse(localStorage.getItem('retailmind_product_columns_v2') || '{}');
        document.querySelectorAll('[data-column]').forEach(box => {
            if (columnPreferences[box.dataset.column] === false) box.checked = false;

            function apply() {
                document.querySelectorAll(`.column-${box.dataset.column}`).forEach(cell => cell.hidden = !box.checked);
                columnPreferences[box.dataset.column] = box.checked;
                localStorage.setItem('retailmind_product_columns_v2', JSON.stringify(columnPreferences));
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
            const header = ['Product', 'SKU', 'Barcode', 'Brand', 'Category', 'Cost Price', 'Selling Price', 'Profit', 'Current Stock', 'Next Expiration', 'Status'];
            const lines = [header.map(csvCell).join(',')];
            filteredRows.forEach(row => {
                const product = productsById[Number(row.dataset.productId)];
                lines.push([product.product_name, product.sku, product.barcode, product.brand, product.category_name, product.cost_price, product.unit_price, (Number(product.cost_price || 0) - Number(product.unit_price || 0)).toFixed(2), product.quantity_on_hand, product.next_expiration_date || '—', product.status].map(csvCell).join(','));
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
        const unitSelects = addProductForm.querySelectorAll('[data-unit-field]');

        function syncUnitSelects() {
            unitSelects.forEach(select => {
                const input = addProductForm.elements[select.dataset.unitField];
                const preset = Array.from(select.options).some(option => option.value && option.value === input.value);
                select.value = preset ? input.value : '';
                input.parentElement.hidden = preset;
                input.required = !preset;
            });
        }
        unitSelects.forEach(select => select.addEventListener('change', () => {
            const input = addProductForm.elements[select.dataset.unitField];
            input.value = select.value;
            input.parentElement.hidden = select.value !== '';
            input.required = select.value === '';
            input.closest('.form-group').classList.remove('invalid');
            if (input.required) input.focus();
        }));
        unitSelects.forEach(select => {
            const input = addProductForm.elements[select.dataset.unitField];
            input.addEventListener('input', () => {
                if (input.value.trim()) input.closest('.form-group').classList.remove('invalid');
            });
        });

        function setWizardStep(step) {
            queueMicrotask(() => window.RetailMindInventoryDrafts?.keep(addProductForm));
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
            if (wizardStep === 4) syncStartingStockFields();
            document.querySelector('#add-product-modal .rm-modal-body').scrollTop = 0;
            if (wizardStep === 2) {
                document.getElementById('create-barcode-input').focus({ preventScroll: true });
            }
        }

        function validateStep(step) {
            let valid = true;
            if (step === 4) {
                const quantity = document.getElementById('initial-stock-quantity');
                const invalid = !quantity.validity.valid ||
                    (!startingPacksCount.disabled && (!startingPacksCount.validity.valid || !startingPackLooseCount.validity.valid));
                quantity.closest('.form-group').classList.toggle('invalid', invalid);
                return !invalid;
            }
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
                if (existingProductNames.has(name.value.trim().toLowerCase())) RetailMindUI.toast('A product with the same name already exists. Add a variant label when appropriate.', 'warning');
            }
            if (step === 2) {
                const barcode = document.getElementById('create-barcode-input');
                if (barcode.value.trim() && existingBarcodes.has(barcode.value.trim().toLowerCase())) {
                    barcode.closest('.form-group').classList.add('invalid');
                    valid = false;
                }
            }
            if (step === 3) {
                unitSelects.forEach(select => {
                    const input = addProductForm.elements[select.dataset.unitField];
                    if (!select.value && !input.value.trim()) {
                        input.closest('.form-group').classList.add('invalid');
                        valid = false;
                    }
                });
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
                const packSize = document.getElementById('create-pack-size');
                const packBarcode = document.getElementById('create-pack-barcode');
                const packPrice = document.getElementById('create-pack-price');
                const hasPack = (packSize && packSize.value.trim() !== '') || (packBarcode && packBarcode.value.trim() !== '') || (packPrice && packPrice.value.trim() !== '');
                if (hasPack) {
                    const pSizeVal = parseInt(packSize.value, 10);
                    const pPriceVal = parseFloat(packPrice.value);
                    const sellingVal = parseFloat(selling.value);
                    if (isNaN(pSizeVal) || pSizeVal < 2) {
                        packSize.closest('.form-group').classList.add('invalid');
                        valid = false;
                    }
                    if (!packBarcode.value.trim() || existingBarcodes.has(packBarcode.value.trim().toLowerCase())) {
                        packBarcode.closest('.form-group').classList.add('invalid');
                        valid = false;
                    }
                    if (isNaN(pPriceVal) || pPriceVal <= 0 || (!isNaN(sellingVal) && !isNaN(pSizeVal) && pPriceVal >= (pSizeVal * sellingVal))) {
                        packPrice.closest('.form-group').classList.add('invalid');
                        valid = false;
                    }
                }
            }
            return valid;
        }
        nextButton.addEventListener('click', () => {
            if (validateStep(wizardStep)) setWizardStep(wizardStep + 1);
        });
        backButton.addEventListener('click', () => setWizardStep(wizardStep - 1));
        addProductForm.addEventListener('keydown', event => {
            if (wizardStep === 2 && event.key === 'Enter' && event.target.matches('input')) {
                event.preventDefault();
            }
        });
        addProductForm.addEventListener('submit', event => {
            if (wizardStep !== 5) {
                event.preventDefault();
                return;
            }
            for (let step = 1; step <= 4; step++) {
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

        // Starting stock follows the product's Sales Pack configuration.
        const stockPiecesWrapper = document.getElementById('stock-pieces-wrapper');
        const stockSalesPacksWrapper = document.getElementById('stock-sales-packs-wrapper');
        const initialStockInput = document.getElementById('initial-stock-quantity');
        const startingStockExpGroup = document.getElementById('starting-stock-expiration-group');
        const startingStockExpInput = document.getElementById('starting-stock-expiration');
        const startingPacksCount = document.getElementById('starting-packs-count');
        const startingPackLooseCount = document.getElementById('starting-pack-loose-count');
        const packSizeInput = document.getElementById('create-pack-size');
        const salesPackCalcPreview = document.getElementById('stock-sales-pack-calc-preview');

        function updateStartingStockExpirationVisibility() {
            const currentTotal = Math.max(0, parseInt(initialStockInput?.value, 10) || 0);
            if (startingStockExpGroup) {
                startingStockExpGroup.hidden = currentTotal <= 0;
            }
            if (currentTotal <= 0 && startingStockExpInput) {
                startingStockExpInput.value = '';
            }
        }
        updateStartingStockExpirationVisibility();

        function updateSalesPackStockCalc() {
            const psize = Math.max(1, parseInt(packSizeInput?.value, 10) || 1);
            const pks = Math.max(0, parseInt(startingPacksCount?.value, 10) || 0);
            const loose = Math.max(0, parseInt(startingPackLooseCount?.value, 10) || 0);
            const total = (pks * psize) + loose;
            if (initialStockInput) {
                initialStockInput.value = total;
                if (initialStockInput.validity.valid && startingPacksCount.validity.valid && startingPackLooseCount.validity.valid) {
                    initialStockInput.closest('.form-group').classList.remove('invalid');
                }
            }
            updateStartingStockExpirationVisibility();
            if (salesPackCalcPreview) {
                const base = addProductForm?.elements['base_unit']?.value?.trim() || 'piece';
                salesPackCalcPreview.textContent = `1 Sales Pack = ${psize} ${base}(s). Total starting stock: ${total} ${base}(s).`;
            }
        }

        function syncStartingStockFields() {
            const isPacks = Number(packSizeInput.value) > 1;
            const wasPacks = !stockSalesPacksWrapper.hidden;
            const currentTotal = Math.max(0, parseInt(initialStockInput?.value, 10) || 0);
            stockPiecesWrapper.hidden = isPacks;
            stockSalesPacksWrapper.hidden = !isPacks;
            startingPacksCount.disabled = !isPacks;
            startingPackLooseCount.disabled = !isPacks;
            if (isPacks) {
                if (!wasPacks) {
                    // Existing pieces stay loose; a total cannot prove unopened packs.
                    startingPacksCount.value = '0';
                    startingPackLooseCount.value = currentTotal;
                }
                updateSalesPackStockCalc();
            } else {
                updateStartingStockExpirationVisibility();
            }
        }

        initialStockInput?.addEventListener('input', updateStartingStockExpirationVisibility);
        initialStockInput?.addEventListener('change', updateStartingStockExpirationVisibility);
        startingPacksCount?.addEventListener('input', updateSalesPackStockCalc);
        startingPackLooseCount?.addEventListener('input', updateSalesPackStockCalc);
        packSizeInput?.addEventListener('input', syncStartingStockFields);

        function renderReview() {
            const data = new FormData(addProductForm);
            const categoryText = document.getElementById('product-category').selectedOptions[0]?.text || '—';
            const items = [
                ['Product', data.get('product_name')],
                ['Brand', data.get('brand') || '—'],
                ['Category', categoryText],
                ['Individual Item Barcode', data.get('barcode') || 'Generate automatically'],
                ['Receiving Package Barcode', data.get('case_barcode') || 'None'],
                ['Cost Price', formatMoney(data.get('cost_price'))],
                ['Selling Price', formatMoney(data.get('selling_price'))],
                ['Initial Quantity', `${data.get('initial_stock_quantity')} ${data.get('base_unit') || 'piece'}`],
                ['Reorder / Safety', `${data.get('reorder_level')} / ${data.get('safety_stock')}`],
                ['Supplier', data.get('preferred_supplier') || '—']
            ];
            const initialQty = parseInt(data.get('initial_stock_quantity'), 10) || 0;
            if (data.get('pack_size') && parseInt(data.get('pack_size'), 10) > 1) {
                items.push(['Starting Stock', `${data.get('starting_packs')} Sales Packs + ${data.get('starting_loose')} loose pieces`]);
                items.push(['Items per Sales Pack', `${data.get('pack_size')} ${data.get('base_unit') || 'piece'}(s)`]);
                items.push(['Sales Pack Barcode', data.get('pack_barcode') || '—']);
                items.push(['Sales Pack Price', formatMoney(data.get('pack_price'))]);
            }
            if (initialQty > 0 && data.get('expiration_date')) {
                items.push(['Starting Stock Expiration', data.get('expiration_date')]);
            }
            document.getElementById('product-review').innerHTML = items.map(([label, value]) => `<div class="detail-item"><span>${label}</span><strong>${String(value).replace(/[<>]/g,'')}</strong></div>`).join('');
        }

        const productModalOverlay = document.getElementById('add-product-modal');
        document.getElementById('wizard-reset').addEventListener('click', async event => {
            event.preventDefault();
            if (await RetailMindUI.confirm({title: 'Clear entered data', message: 'Discard this Product draft?', confirmText: 'Discard', danger: true})) {
                RetailMindInventoryDrafts.discard(addProductForm);
                addProductForm.reset();
                syncUnitSelects();
                syncStartingStockFields();
                setWizardStep(1);
            }
        });

        document.addEventListener('inventorydraft:capture', event => {
            if (event.detail.form === addProductForm) event.detail.draft.context.step = wizardStep;
            if (['product-edit-form', 'pack-reconcile-form'].includes(event.detail.form.id)) event.detail.draft.context.productId = currentDrawerProductId;
        });
        document.addEventListener('inventorydraft:prepare', event => {
            const draft = event.detail.draft;
            if (draft.formId === 'add-product-form') {
                RetailMindUI.openOverlay(productModalOverlay);
                setWizardStep(draft.context.step || 1);
            } else if (draft.formId === 'product-edit-form') {
                event.detail.waitUntil(Promise.resolve(openProductDrawer(draft.context.productId)).then(() => enterProductEditMode()));
            } else {
                if (draft.context.productId) openProductDrawer(draft.context.productId);
                const target = Array.from(document.forms).find(form => RetailMindInventoryDrafts.identity(form) === draft.id);
                const overlay = target?.closest('.rm-modal-overlay');
                if (overlay) RetailMindUI.openOverlay(overlay);
            }
        });
        document.addEventListener('inventorydraft:restored', event => {
            if (event.detail.form === addProductForm) {
                syncUnitSelects();
                syncStartingStockFields();
                setWizardStep(event.detail.draft.context.step || 1);
            }
        });

        // Camera scan and internal barcode generation
        const barcodeInput = document.getElementById('create-barcode-input');
        let productKeyboardCode = '';
        let productKeyboardAt = 0;
        let productScanQueue = Promise.resolve();
        function resetProductKeyboardScan() {
            productKeyboardCode = '';
            productKeyboardAt = 0;
        }
        async function findScannedProduct(code) {
            try {
                const response = await fetch(<?= json_encode(app_url('components/barcodeScanner/apiScanner/product_code_lookup.php')) ?> + '?code=' + encodeURIComponent(code), {
                    headers: { Accept: 'application/json' }, cache: 'no-store'
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Product lookup failed. Please try again.');
                if (data.outcome !== 'match') {
                    RetailMindUI.toast(data.outcome === 'ambiguous' ? 'This barcode matches more than one product. Use search.' : 'Product not found. Check the barcode or add the product.', 'error');
                    return;
                }
                if (document.querySelector('.rm-modal-overlay.open, dialog[open]')) return;
                if (!productData.some(product => Number(product.product_id) === Number(data.product.product_id))) {
                    RetailMindUI.toast('This product is not in the current list. Refresh the page and scan again.', 'info');
                    return;
                }
                openProductDrawer(data.product.product_id);
            } catch (error) {
                RetailMindUI.toast(error.message || 'Product lookup failed. Please try again.', 'error');
            }
        }
        // shortcut: Enter-terminated bursts under 80ms are scanner input; verify thresholds on physical hardware.
        let protectedScan = null;
        document.addEventListener('keydown', event => {
            const field = event.target;
            const productForm = field.closest('#add-product-form, #product-edit-form');
            if (!productForm || event.isComposing || event.ctrlKey || event.metaKey || event.altKey) return;
            const barcodeField = ['barcode', 'case_barcode', 'pack_barcode'].includes(field.name);
            const now = performance.now();
            if (event.key === 'Enter') {
                if (barcodeField || (protectedScan?.field === field && protectedScan.count >= 3 && now - protectedScan.at < 80)) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    if (!barcodeField) {
                        field.value = protectedScan.value;
                        field.dispatchEvent(new Event('input', { bubbles: true }));
                        RetailMindUI.toast('Select a barcode field before scanning. Other entered values were kept.', 'info');
                    }
                }
                protectedScan = null;
            } else if (event.key.length === 1 && !barcodeField && field.matches('input, textarea, select')) {
                if (!protectedScan || protectedScan.field !== field || now - protectedScan.at > 80) {
                    protectedScan = { field, value: field.value, count: 0 };
                }
                protectedScan.at = now;
                protectedScan.count++;
            } else protectedScan = null;
        }, true);
        document.addEventListener('keydown', event => {
            const editing = event.target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"]');
            if (event.defaultPrevented || event.isComposing || event.repeat || event.ctrlKey || event.metaKey || event.altKey || document.hidden
                || editing || document.querySelector('dialog[open], .swal2-container, .command-overlay.open')
                || document.querySelector('.rm-modal-overlay.open')) {
                resetProductKeyboardScan();
                return;
            }
            const now = performance.now();
            // shortcut: scanners must send characters within 80ms; tune for slower hardware if needed.
            if (now - productKeyboardAt > 80) resetProductKeyboardScan();
            if (event.key === 'Enter') {
                const code = productKeyboardCode;
                resetProductKeyboardScan();
                if (code.length < 3) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                productScanQueue = productScanQueue.then(() => findScannedProduct(code));
            } else if (event.key.length === 1 && /^[\x21-\x7E]$/.test(event.key)) {
                productKeyboardCode += event.key;
                productKeyboardAt = now;
                if (productKeyboardCode.length > 255) resetProductKeyboardScan();
            } else if (event.key !== 'Shift') resetProductKeyboardScan();
        }, true);
        window.addEventListener('blur', resetProductKeyboardScan);
        document.addEventListener('visibilitychange', resetProductKeyboardScan);
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
                console.error('Camera start failed:', error);
                RetailMindUI.toast('The camera could not start. Allow camera permission for this site, or enter the code manually.', 'error', 'Camera unavailable');
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
        const productImageInput = document.getElementById('product-image-input');
        const imagePreviewContainer = document.getElementById('image-preview-container');
        const imagePreview = document.getElementById('image-preview');
        const clearImageBtn = document.getElementById('clear-image-btn');

        productImageInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) {
                    RetailMindUI.toast(PRODUCT_PHOTO_LIMIT_MESSAGE, 'warning');
                    this.value = '';
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    RetailMindUI.toast(PRODUCT_PHOTO_LIMIT_MESSAGE, 'warning');
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
            productImageInput.value = '';
            imagePreviewContainer.hidden = true;
        });
    </script>
</body>

</html>
