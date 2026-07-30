<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pairing.php';

function api_apply_cors(): void {
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
api_apply_cors();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$input = json_decode((string)file_get_contents('php://input'), true) ?: [];

function pairing_json_error(string $message, int $code): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

try {
    if ($action === 'join') {
        $code = trim((string)($input['code'] ?? ''));
        $pairing = pairing_find_by_code($pdo, $code);
        if (!$pairing || $pairing['status'] === 'expired') {
            pairing_json_error('That pairing code is invalid or expired.', 404);
        }
        $label = trim((string)($input['device_label'] ?? 'Phone/Tablet'));
        $token = pairing_mark_connected($pdo, (int)$pairing['pairing_id'], $label ?: null);
        echo json_encode([
            'success' => true,
            'message' => 'Connected to laptop session.',
            'device_label' => $label,
            'access_token' => $token,
            'expires_at' => $pairing['expires_at'],
        ]);
        exit;
    }

    require_role(['admin', 'inventory_manager', 'cashier']);

    if ($action === 'create') {
        csrf_verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null));
        $pairing = pairing_create($pdo, (int)$_SESSION['user_id'], 10);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $scannerBase = $scheme . '://' . $host . app_url('barcodeScanner/index.html');
        $apiUrl = $scheme . '://' . $host . app_url('api/barcode.php');
        $scannerUrl = $scannerBase . '?code=' . urlencode($pairing['code']) . '&api=' . urlencode($apiUrl);
        echo json_encode([
            'success' => true,
            'code' => $pairing['code'],
            'pairing_id' => $pairing['pairing_id'],
            'expires_in_minutes' => $pairing['ttl_minutes'],
            'scanner_url' => $scannerUrl,
            'api_url' => $apiUrl,
        ]);
        exit;
    }

    $code = trim((string)($_GET['code'] ?? $input['code'] ?? ''));
    $pairing = pairing_find_by_code($pdo, $code);
    if (!$pairing || !pairing_assert_owner($pairing, (int)$_SESSION['user_id'])) {
        pairing_json_error('Pairing was not found or does not belong to this session.', 404);
    }

    if ($action === 'status') {
        echo json_encode(['success' => true, 'status' => $pairing['status'], 'device_label' => $pairing['device_label']]);
        exit;
    }

    if ($action === 'poll') {
        $after = (int)($_GET['after'] ?? 0);
        $scans = pairing_scans_since($pdo, (int)$pairing['pairing_id'], $after);
        $lastId = $after;
        foreach ($scans as $scan) {
            $lastId = max($lastId, (int)$scan['scan_id']);
        }
        echo json_encode([
            'success' => true,
            'status' => $pairing['status'],
            'device_label' => $pairing['device_label'],
            'scans' => $scans,
            'last_id' => $lastId,
        ]);
        exit;
    }

    pairing_json_error('Unknown action.', 400);
} catch (Throwable $exception) {
    error_log((string)$exception);
    pairing_json_error('The pairing request could not be completed.', 500);
}
