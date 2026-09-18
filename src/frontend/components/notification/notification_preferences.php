<?php
// Backward-compatible route for bookmarks created before Preferences moved into the profile menu.
require_once __DIR__ . '/../../../backend/includes/auth.php';

if (!is_logged_in()) {
    header('Location: ' . app_url('?login=1'));
    exit;
}

$query = $_SERVER['QUERY_STRING'] ?? '';
$destination = app_url('components/auth/preferences.php');
if ($query !== '') {
    $destination .= '?' . $query;
}

$statusCode = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 307 : 302;
header('Location: ' . $destination, true, $statusCode);
exit;
