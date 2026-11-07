<?php
// Named HTML fragments for the GCash Admin Overview. GET only.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/gcash_admin.php';

use App\GCash\AdminOverviewReport;
use App\Services\GCashTransactionService;

require_role(['admin', 'super_admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$service = new GCashTransactionService($pdo, require __DIR__ . '/../../../backend/config/gcash.php');
$report = (new AdminOverviewReport($service))->forRequest($_GET, (string)current_role(), (int)($_SESSION['user_id'] ?? 0));

$overview = $report->overview;
$filterError = $report->filterError;
$page = $report->page;
$canMutate = current_role() === 'admin' && !(function_exists('is_remote_request') && is_remote_request());

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/../../../backend/app/Views/gcash/filter_error.php';
if ($filterError !== null) {
    exit;
}

require __DIR__ . '/../../../backend/app/Views/gcash/tab_badges.php';
require __DIR__ . '/../../../backend/app/Views/gcash/session_status.php';
require __DIR__ . '/../../../backend/app/Views/gcash/export_link.php';
require __DIR__ . '/../../../backend/app/Views/gcash/balances.php';
require __DIR__ . '/../../../backend/app/Views/gcash/outstanding_reviews.php';
require __DIR__ . '/../../../backend/app/Views/gcash/activity_metrics.php';
require __DIR__ . '/../../../backend/app/Views/gcash/transactions_summary.php';
require __DIR__ . '/../../../backend/app/Views/gcash/transactions_type_pills.php';
require __DIR__ . '/../../../backend/app/Views/gcash/transactions_body.php';
require __DIR__ . '/../../../backend/app/Views/gcash/transactions_pagination.php';
require __DIR__ . '/../../../backend/app/Views/gcash/reconciliation_panel.php';
require __DIR__ . '/../../../backend/app/Views/gcash/unresolved_panel.php';
