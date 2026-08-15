<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pairing.php';
require_once __DIR__ . '/../../app/Services/FiscalPeriodGuardService.php';

function barcode_apply_cors(): void {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return;
    }
    $serverOrigin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $allowed = array_filter(array_map('trim', explode(',', (string)env('BARCODE_ALLOWED_ORIGINS', ''))));
    if ($origin === $serverOrigin || in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }
}
barcode_apply_cors();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true) ?: [];
$barcode = trim((string)($input['barcode'] ?? ''));
$action = trim((string)($input['action'] ?? 'search'));
if ($barcode === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Barcode data is required.']);
    exit;
}

$pairing = null;
$pairingCode = trim((string)($input['pairing_code'] ?? ''));
$accessToken = trim((string)($input['access_token'] ?? ''));
if ($pairingCode !== '' || $accessToken !== '') {
    $pairing = pairing_validate_access($pdo, $pairingCode, $accessToken);
}

if (!is_logged_in() && !$pairing) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication or a valid scanner pairing is required.']);
    exit;
}

function find_product_by_code(PDO $pdo, string $code): ?array {
    $stmt = $pdo->prepare(
        "SELECT p.product_id, p.sku, p.barcode, p.product_name, p.unit_price,
                COALESCE(p.reorder_level, 0) AS reorder_level,
                COALESCE(p.safety_stock, 0) AS safety_stock,
                COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand
         FROM products p LEFT JOIN inventory i ON p.product_id = i.product_id
         WHERE p.status = 'active' AND (p.barcode = ? OR p.sku = ?) LIMIT 1"
    );
    $stmt->execute([$code, $code]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

try {
    $product = find_product_by_code($pdo, $barcode);
    if ($action === 'search') {
        if (!$product) {
            if ($pairing) {
                pairing_log_scan($pdo, (int)$pairing['pairing_id'], $barcode, ['status' => 'not_found']);
            }
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Product not found.', 'barcode' => $barcode]);
            exit;
        }
        if ($pairing) {
            pairing_log_scan($pdo, (int)$pairing['pairing_id'], $barcode, [
                'status' => 'found',
                'product_id' => $product['product_id'],
                'product_name' => $product['product_name'],
                'unit_price' => $product['unit_price'],
                'quantity_on_hand' => $product['quantity_on_hand'],
            ]);
        }
        echo json_encode(['success' => true, 'message' => 'Product found.', 'product' => $product]);
        exit;
    }

    if ($action === 'update_stock') {
        require_role(['admin', 'inventory_manager']);
        csrf_verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null));
        if (!$product) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Product not found.']);
            exit;
        }
        $newQuantity = max(0, (int)($input['quantity'] ?? 0));
        $guard = new FiscalPeriodGuardService($pdo);
        $guard->assertOpenNow('inventory_adjustments', 'inventory adjustment');
        $guard->assertOpenNow('stock_movements', 'stock movement');
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE inventory SET quantity_on_hand = ? WHERE product_id = ?')->execute([$newQuantity, $product['product_id']]);
        $pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, reason, moved_by) VALUES (?, ?, 'adjustment', ?)")
            ->execute([$product['product_id'], $newQuantity - (int)$product['quantity_on_hand'], (int)$_SESSION['user_id']]);
        $pdo->commit();
        log_activity($pdo, (int)$_SESSION['user_id'], 'Barcode stock update', 'Inventory', (int)$product['product_id'], ['quantity' => $product['quantity_on_hand']], ['quantity' => $newQuantity]);
        echo json_encode(['success' => true, 'message' => 'Stock updated.', 'new_quantity' => $newQuantity]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log((string)$exception);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The barcode request could not be completed.']);
}
