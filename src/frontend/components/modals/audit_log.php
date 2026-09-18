<?php
// Backward-compatible route for bookmarks created before Audit Logs became a dedicated page.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_any_capability([
    \App\Authorization\RoleCapabilityPolicy::VIEW_STORE_AUDIT,
    \App\Authorization\RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT,
]);

$query = $_SERVER['QUERY_STRING'] ?? '';
$destination = app_url('components/system_administrator/audit_logs.php');
if ($query !== '') {
    $destination .= '?' . $query;
}

$statusCode = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 307 : 302;
header('Location: ' . $destination, true, $statusCode);
exit;
