<?php
// Backward-compatible route for bookmarks created before System Health became a dedicated page.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);

$query = $_SERVER['QUERY_STRING'] ?? '';
$destination = app_url('components/system_administrator/system_health.php');
if ($query !== '') {
    $destination .= '?' . $query;
}

$statusCode = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 307 : 302;
header('Location: ' . $destination, true, $statusCode);
exit;
