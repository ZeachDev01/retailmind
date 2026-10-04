<?php

header('Cache-Control: private, no-store, max-age=0');
header('Vary: Cookie');
require_once __DIR__ . '/../../../backend/includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit;
}

$userId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId <= 0) {
    http_response_code(404);
    exit;
}
$lifecycle = new \App\Services\UserLifecycleService($pdo, role_capability_policy(), new \App\Store\StoreScope($pdo));
try {
    $target = $lifecycle->get($userId);
} catch (InvalidArgumentException) {
    http_response_code(404);
    exit;
}
if ((bool)($_SESSION['is_recovery_account'] ?? false)
    || !$lifecycle->canViewPicture((int)$_SESSION['user_id'], (string)current_role(), $target)) {
    http_response_code(403);
    exit;
}

$filename = $target['profile_image'];
$image = profile_image_storage()->resolve(is_string($filename) ? $filename : null);
if ($image === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $image['mime']);
header('Content-Length: ' . (string)filesize($image['path']));
header('Content-Disposition: inline; filename="profile-image"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
readfile($image['path']);
