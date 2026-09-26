<?php
// Lightweight shared Database Backup status for active sessions (#69).
//
// Read-only and never locked, so browsing, status checks, and reconnecting
// sessions keep working while a capture holds the write pause. It reports
// operational state only: no key material, no file paths, no record contents.
require_once __DIR__ . '/../../../backend/includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['paused' => false, 'state' => 'idle', 'message' => '']);
    exit;
}

$service = new App\Services\DatabaseBackupService($pdo, role_capability_policy());
$status = $service->status();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'paused' => (bool)($status['paused'] ?? false),
    'state' => (string)($status['state'] ?? 'idle'),
    'message' => (string)($status['message'] ?? ''),
    'started_at' => $status['started_at'] ?? null,
]);
