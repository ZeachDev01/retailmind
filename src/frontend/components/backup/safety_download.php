<?php
// Safety copies are retained, never exposed through shared requester tokens.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);

$path = \App\Backup\RecoveryStore::path('latest-safety.sql');
$handle = is_file($path) ? fopen($path, 'rb') : false;
if ($handle === false) {
    http_response_code(404);
    exit('No safety backup is available.');
}
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="retailmind-safety.sql"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
fpassthru($handle);
fclose($handle);
