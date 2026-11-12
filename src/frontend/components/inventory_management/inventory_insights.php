<?php
// Legacy Insights URL now opens the consolidated, read-only Stock Risk tab.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_role(['admin', 'super_admin', 'inventory_manager']);
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
header('Location: ' . app_url('components/inventory_management/inventory_overview.php?tab=risk'));
exit;
