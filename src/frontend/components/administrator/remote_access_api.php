<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/app/Services/CloudflareTunnelService.php';

require_role(['admin', 'super_admin']);

use App\Services\CloudflareTunnelService;

try {
    $service = new CloudflareTunnelService();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $status = $service->getStatus();
        echo json_encode(['success' => true] + $status, JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'POST') {
        // Read raw JSON body or fall back to standard POST parameters
        $raw = (string)file_get_contents('php://input', false, null, 0, 4096);
        $input = !empty($raw) ? json_decode($raw, true) : null;
        if (!is_array($input)) {
            $input = $_POST;
        }

        $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? ($input['csrf_token'] ?? ($_POST['csrf_token'] ?? ''));

        if (!csrf_token_is_valid($submittedToken)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Security check failed. Please refresh and try again.',
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $action = trim((string)($input['action'] ?? ($_POST['action'] ?? '')));

        if ($action === 'start') {
            $result = $service->start();
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'stop') {
            $result = $service->stop();
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'status') {
            $result = $service->getStatus();
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'save_token') {
            $token = trim((string)($input['token'] ?? ($_POST['token'] ?? '')));
            $hostname = trim((string)($input['hostname'] ?? ($_POST['hostname'] ?? '')));
            if ($token === '') {
                $service->clearToken();
            } else {
                $service->setToken($token, $hostname !== '' ? $hostname : null);
            }
            $result = $service->getStatus();
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'clear_token') {
            $service->clearToken();
            $result = $service->getStatus();
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Unsupported action.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred while managing the remote access tunnel.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
