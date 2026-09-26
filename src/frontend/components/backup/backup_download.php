<?php
// Private temporary download for an encrypted Database Backup (#69).
//
// The artifact is never reachable as a static storage URL: this endpoint is the
// only way to obtain it, it re-checks the session and the backup capability on
// every request, and the token is bound to the administrator that created the
// backup. The file is removed only after the response has been delivered.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';

require_any_capability([\App\Authorization\RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP]);

$token = (string)($_GET['token'] ?? '');
$service = new App\Services\DatabaseBackupService($pdo, role_capability_policy());
validate_current_session($pdo);

try {
    // Binding the reference to the requester happens inside the shared
    // workflow, which compares the artifact's requested_by with this session.
    $download = $service->resolveDownload($token, (int)$_SESSION['user_id'], (string)current_role());
} catch (DomainException $exception) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $exception->getMessage();
    exit;
}

$path = (string)$download['path'];
$size = (int)$download['size'];

// Stream first, clean up afterwards: cleanup must never delete a file that is
// still being delivered.
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . rawurlencode((string)$download['filename']) . '"');
header('Content-Length: ' . $size);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$handle = fopen($path, 'rb');
if ($handle === false) {
    http_response_code(404);
    echo 'That backup download is no longer available.';
    exit;
}
try {
    while (!feof($handle)) {
        $chunk = fread($handle, 262144);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        flush();
    }
} finally {
    fclose($handle);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    $service->discardAfterDelivery($path);
    // Bounded cleanup for anything an interrupted request left behind.
    $service->cleanupStrandedArtifacts();
}
