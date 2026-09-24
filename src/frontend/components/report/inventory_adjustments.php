<?php
// report/inventory_adjustments.php
// Legacy entry point kept for old bookmarks. Cashiers are routed to the
// shift-gated Report Stock Issue flow (tickets #29 + #30), and Administrators
// are routed to the read-only Stock Issue Oversight page (ticket #31).
// No stock-issue mutations happen on this page anymore — direct URLs and
// crafted requests alike end in a redirect, never a write.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
if (is_logged_in() && current_role() === 'cashier') {
    header('Location: ' . app_url('components/cashier/stock_issues.php'));
    exit;
}
require_role(['admin']);
header('Location: ' . app_url('components/administrator/stock_issues.php'));
exit;
