<?php
// Read-only exact product code lookup for the barcode scan fields.
// Ticket #79: authenticated, read-only, no mutation and no pairing-token path.
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../../backend/app/Services/ProductCodeLookupService.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sign in to look up products.']);
    exit;
}

validate_current_session($pdo);
$context = $_GET['context'] ?? '';
$addProduct = $context === 'add_product';
$canLookup = $addProduct
    ? has_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY)
    : array_intersect(current_workspace_roles(), ['admin', 'inventory_manager']) !== [];
if (!$canLookup) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied: your role does not have permission to look up products.']);
    exit;
}

$lookupUserId = $_SESSION['user_id'];
App\Core\Session::closeWrite();

// Identification only: this endpoint never writes, never exposes an update
// operation, and never accepts a paired-phone token as authorization.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'error' => 'Product code lookup is read-only.']);
    exit;
}

$code = is_string($_GET['code'] ?? null) ? trim($_GET['code']) : '';
if ($code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Enter or scan a product code.']);
    exit;
}

$barcodeKind = $_GET['barcode_type'] ?? '';
if ($addProduct && !in_array($barcodeKind, [
    ProductCodeLookupService::KIND_UNIT_BARCODE,
    ProductCodeLookupService::KIND_PACK_BARCODE,
    ProductCodeLookupService::KIND_CASE_BARCODE,
], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Select the barcode type to check.']);
    exit;
}

try {
    $lookup = new ProductCodeLookupService($pdo);
    $result = $addProduct ? $lookup->lookupForAddProduct($code, $barcodeKind) : $lookup->lookup($code, $context === 'receiving');
    if ($addProduct && $result['product'] !== null) {
        $product = $result['product'];
        $result['product'] = [
            'product_id' => $product['product_id'],
            'product_name' => $product['product_name'],
            'status' => $product['status'],
            'manage_url' => app_url('components/inventory_management/products.php?manage_product=' . $product['product_id']),
        ];
    }
    if ($addProduct && $barcodeKind === ProductCodeLookupService::KIND_UNIT_BARCODE && $result['outcome'] === 'unknown') {
        require_once __DIR__ . '/../../../../backend/app/Services/OpenFoodFactsCatalog.php';
        if (OpenFoodFactsCatalog::supports($code)) {
            $result['catalog'] = OpenFoodFactsCatalog::lookup($code, $openFoodFactsRequest ?? null);
            $photoUrl = $result['catalog']['product']['photo_url'] ?? null;
            if ($photoUrl !== null) {
                App\Core\Session::start();
                if ($lookupUserId === ($_SESSION['user_id'] ?? null)) {
                    $photos = $_SESSION['open_food_facts_photos'] ?? [];
                    unset($photos[$code]);
                    $photos[$code] = ['url' => $photoUrl, 'source' => $result['catalog']['source']['product_url']];
                    $_SESSION['open_food_facts_photos'] = array_slice($photos, -20, null, true);
                }
                App\Core\Session::closeWrite();
            }
        }
    }
    echo json_encode(['success' => true] + $result, JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $exception) {
    error_log((string)$exception);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'The product code could not be checked right now. Try again.',
    ]);
}
