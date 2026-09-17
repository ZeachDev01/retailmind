<?php

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
if ($userId !== (int)$_SESSION['user_id'] && !in_array(current_role(), ['admin', 'super_admin'], true)) {
    http_response_code(403);
    exit;
}

$stmt = $pdo->prepare('SELECT profile_image FROM users WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$filename = $stmt->fetchColumn();
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
