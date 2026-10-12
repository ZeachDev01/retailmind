<?php
// Read-only exact product code lookup for the barcode scan fields.
// Ticket #79: authenticated, read-only, no mutation and no pairing-token path.
header('Content-Type: application/json');

require_once __DIR__ . '/../../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../../backend/app/Services/ProductCodeLookupService.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sign in to look up products.']);
    exit;
}

validate_current_session($pdo);
if (array_intersect(current_workspace_roles(), ['admin', 'inventory_manager']) === []) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied: your role does not have permission to look up products.']);
    exit;
}

App\Core\Session::closeWrite();

// Identification only: this endpoint never writes, never exposes an update
// operation, and never accepts a paired-phone token as authorization.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'error' => 'Product code lookup is read-only.']);
    exit;
}

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Enter or scan a product code.']);
    exit;
}

try {
    $lookup = new ProductCodeLookupService($pdo);
    echo json_encode(['success' => true] + $lookup->lookup($code));
} catch (Throwable $exception) {
    error_log((string)$exception);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'The product code could not be checked right now. Try again.',
    ]);
}
