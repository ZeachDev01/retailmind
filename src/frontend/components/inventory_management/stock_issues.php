<?php
// components/inventory_management/stock_issues.php — Stock Issue Reviews (tickets #29 + #30).
//
// Inventory Managers review pending cashier stock-issue reports. Rejection
// requires a reason and never changes inventory. Approval atomically re-checks
// available stock, refuses insufficient stock, deducts the full verified
// quantity, and records a linked stock movement. A pending report may be
// decided without clarification; original report details stay
// locked after submission. Managers can also record confirmed issues directly. Administrators hold no mutation
// authority here: this page is inventory_manager-only.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/StockIssueService.php';
header('Cache-Control: no-store');
require_role(['inventory_manager']);

$stockIssueService = new StockIssueService($pdo, null, (string)current_role());
$message = '';
$error = '';

require __DIR__ . '/../../../backend/includes/stock_issue_conversation_actions.php';

$returnView = in_array($_GET['return_view'] ?? '', ['pending', 'decisions'], true) ? $_GET['return_view'] : 'pending';
$returnPage = max(1, (int)($_GET['return_page'] ?? 1));
$returnLength = in_array((int)($_GET['return_length'] ?? 15), [10, 15, 25, 50, 100], true) ? (int)($_GET['return_length'] ?? 15) : 15;
$sortableColumns = ['report', 'product', 'type', 'quantity', 'status', 'date'];
$sort = in_array($_GET['sort'] ?? '', $sortableColumns, true) ? $_GET['sort'] : 'date';
$requestedDirection = in_array($_GET['direction'] ?? '', ['asc', 'desc'], true) ? $_GET['direction'] : null;
$sortParams = $requestedDirection === null ? ['sort' => $sort] : ['sort' => $sort, 'direction' => $requestedDirection];
$returnParams = ['return_view' => $returnView, 'return_page' => $returnPage, 'return_length' => $returnLength] + $sortParams;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['conversation_action'])) {
    require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';
    $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);

    try {
        if ($action === 'record') {
            $adjustmentId = $stockIssueService->recordConfirmedIssue($_POST, (int)$_SESSION['user_id']);
            if (isset($_POST['inventory_draft_id'])) $_SESSION['inventory_confirmed_draft'] = (string)$_POST['inventory_draft_id'];
            $_SESSION['stock_issue_message'] = "Stock Issue #{$adjustmentId} recorded. Confirmed quantity deducted.";
            header('Location: ' . app_url('components/inventory_management/stock_issues.php?detail=' . $adjustmentId), true, 303);
            exit;
        } elseif ($action === 'approve') {
            $batchAllocations = isset($_POST['batches']) && is_array($_POST['batches']) ? $_POST['batches'] : null;
            $verifiedQuantity = $_POST['verified_quantity'] ?? '';
            if ($batchAllocations !== null) {
                $verifiedQuantity = 0;
                foreach ($batchAllocations as $quantity) {
                    if (!is_string($quantity) || !preg_match('/^\d*$/D', trim($quantity))) {
                        throw new RuntimeException('Batch deductions must be whole numbers of zero or more.');
                    }
                    $verifiedQuantity += (int)$quantity;
                }
            }
            $stockIssueService->approveReport($adjustmentId, (int)$_SESSION['user_id'], trim((string)($_POST['review_notes'] ?? '')), $batchAllocations, (string)($_POST['operation_token'] ?? ''), $verifiedQuantity);
            $message = "Report #{$adjustmentId} approved. Inventory deducted and a linked stock movement recorded.";
            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                'Stock issue approved',
                'Inventory Adjustments',
                $adjustmentId,
                ['status' => 'pending'],
                ['status' => 'approved']
            );
        } elseif ($action === 'reject') {
            $stockIssueService->rejectReport($adjustmentId, (int)$_SESSION['user_id'], trim((string)($_POST['review_notes'] ?? '')), (string)($_POST['operation_token'] ?? ''));
            $message = "Report #{$adjustmentId} rejected. Inventory unchanged.";
            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                'Stock issue rejected',
                'Inventory Adjustments',
                $adjustmentId,
                ['status' => 'pending'],
                ['status' => 'rejected']
            );
        } else {
            throw new RuntimeException('Invalid review action.');
        }
        $_SESSION['stock_issue_message'] = $message;
        header('Location: ' . app_url('components/inventory_management/stock_issues.php?' . http_build_query(['detail' => $adjustmentId] + $returnParams)), true, 303);
        exit;
    } catch (Throwable $e) {
        if ($action !== 'record') $conversationDetailId = $adjustmentId;
        $error = \App\Support\OperatorAlert::message($e, 'The review could not be saved. Refresh the page and try again. Tell your Administrator if this keeps happening.');
    }
}

$productsApiUrl = app_url('components/barcodeScanner/apiScanner/products.php?scope=stock_issues');
$recordToken = $error !== '' && ($_POST['action'] ?? '') === 'record' ? (string)($_POST['operation_token'] ?? '') : bin2hex(random_bytes(24));
$formatIssueQty = [StockIssueService::class, 'formatIssueQuantity'];
$issueView = in_array($_GET['issue_view'] ?? '', ['record', 'pending', 'decisions'], true) ? $_GET['issue_view'] : 'pending';
$selectedReport = $conversationDetailId > 0 ? $stockIssueService->getReport($conversationDetailId) : null;
if ($conversationDetailId > 0 && !$selectedReport) http_response_code(404);
if ($selectedReport) {
    $issueView = in_array($selectedReport['status'], ['pending', 'returned'], true) ? 'pending' : 'decisions';
}
$viewUrl = static fn(string $view): string => app_url('components/inventory_management/stock_issues.php?' . http_build_query(['issue_view' => $view]));
$pendingCount = $stockIssueService->getPendingCount();
$reviewCount = $stockIssueService->getReviewCount();
$pageSize = in_array((int)($_GET['length'] ?? 15), [10, 15, 25, 50, 100], true) ? (int)($_GET['length'] ?? 15) : 15;
$totalRows = $issueView === 'decisions' ? $reviewCount : $pendingCount;
$totalPages = max(1, (int)ceil($totalRows / $pageSize));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
if ($issueView === 'pending' && $sort === 'status') $sort = 'date';
$direction = $requestedDirection ?? ($sort === 'date' && $issueView === 'decisions' ? 'desc' : 'asc');
$rows = [];
if (!$conversationDetailId && $issueView !== 'record') {
    $rows = $issueView === 'pending'
        ? $stockIssueService->getPendingReports($pageSize, ($page - 1) * $pageSize, $sort, $direction)
        : $stockIssueService->getRecentReviews($pageSize, ($page - 1) * $pageSize, $sort, $direction);
}
$pending = $selectedReport && $issueView === 'pending' ? [$selectedReport] : [];
$reviews = $selectedReport && $issueView === 'decisions' ? [$selectedReport] : [];
if ($pending) $pending[0]['quantity_on_hand'] = $stockIssueService->getAvailableStock((int)$selectedReport['product_id']);
if ($selectedReport && !isset($_GET['return_view'])) $returnView = $issueView;
$backUrl = app_url('components/inventory_management/stock_issues.php?' . http_build_query(['issue_view' => $returnView, 'page' => $returnPage, 'length' => $returnLength] + $sortParams));
$hasNext = $page < $totalPages;
$paginationFilters = ['issue_view' => $issueView, 'sort' => $sort, 'direction' => $direction];
$paginationLabel = $issueView === 'pending' ? 'Pending review pages' : 'Recent decisions pages';
$url = static fn(array $changes): string => '?' . http_build_query(array_replace($paginationFilters + ['page' => $page, 'length' => $pageSize], $changes));
$detailUrl = static fn(int $id): string => app_url('components/inventory_management/stock_issues.php?' . http_build_query([
    'detail' => $id, 'return_view' => $issueView, 'return_page' => $page, 'return_length' => $pageSize,
    'sort' => $sort, 'direction' => $direction,
]));
$issueColumns = [
    'report' => 'Report',
    'product' => 'Product',
    'type' => 'Issue type',
    'quantity' => $issueView === 'pending' ? 'Reported quantity' : 'Quantity deducted',
    'status' => 'Status',
    'date' => $issueView === 'pending' ? 'Reported' : 'Decision date',
    'action' => 'Action',
];
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Issues</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/stock-issues.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/stock-issues.css')) ?>">
<?php require __DIR__ . '/../../../backend/includes/inventory_drafts.php'; ?>
<script>
window.RetailMindInventoryDrafts?.register({
    eligible: form => form.matches('.review-form, #stock-issue-form'),
    submissionKind: (form, action) => ({record:'recordConfirmedIssue', approve:'approveReport', reject:'rejectReport'})[action] || ''
});
</script>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/stock-issue-conversation.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/stock-issue-conversation.css')) ?>">
    <script src="<?= htmlspecialchars(app_url('assets/js/stock-issue-conversation.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/stock-issue-conversation.js')) ?>" defer></script>
</head>

<body class="stock-issues-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <div class="topbar<?= $conversationDetailId > 0 ? ' issue-detail-topbar' : '' ?>">
                <div>
                    <h1>Stock Issues</h1>
                </div>
                <?php if ($conversationDetailId > 0): ?>
                    <a class="btn btn-quiet" href="<?= htmlspecialchars($backUrl) ?>">Back to list</a>
                <?php endif; ?>
            </div>

            <?php if ($message): ?>
                <div class="message success" role="status"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($conversationDetailId <= 0): ?>
            <nav class="stock-kpi-grid" aria-label="Stock Issue views">
                <a href="<?= htmlspecialchars($viewUrl('record')) ?>" class="stock-kpi-card" <?= $issueView === 'record' ? 'aria-current="page"' : '' ?>>
                    <span class="stock-kpi-label">Record Stock Issue</span>
                </a>
                <a href="<?= htmlspecialchars($viewUrl('pending')) ?>" class="stock-kpi-card" <?= $issueView === 'pending' ? 'aria-current="page"' : '' ?>>
                    <svg class="stock-kpi-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <span class="stock-kpi-label">Pending review</span>
                    <span class="stock-kpi-value"><?= (int)$pendingCount ?></span>
                </a>
                <a href="<?= htmlspecialchars($viewUrl('decisions')) ?>" class="stock-kpi-card" <?= $issueView === 'decisions' ? 'aria-current="page"' : '' ?>>
                    <svg class="stock-kpi-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3H5a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-4"/><rect x="9" y="1" width="6" height="4" rx="1"/><path d="m8 13 3 3 5-6"/></svg>
                    <span class="stock-kpi-label">Recent decisions</span>
                    <span class="stock-kpi-value"><?= (int)$reviewCount ?></span>
                </a>
            </nav>
            <?php endif; ?>

            <?php if ($conversationDetailId > 0 && !$selectedReport): ?>
                <div class="issue-empty"><h2>Report not found</h2><p>This Stock Issue is unavailable. Return to the list to choose another report.</p></div>
            <?php elseif ($issueView === 'record'): ?>
                <div data-inventory-recovery-slot></div>
                <?php $directStockIssue = true; $stockIssueCanRecord = true; require __DIR__ . '/../../../backend/app/Views/stock_issue_form.php'; ?>
            <?php elseif (!$selectedReport): ?>
                <section class="history-section" id="<?= $issueView === 'pending' ? 'pending-section' : 'reviews-section' ?>">
                    <h2><?= $issueView === 'pending' ? 'Pending review' : 'Recent decisions' ?> <span class="issue-count"><?= (int)$totalRows ?></span></h2>
                    <?php if (!$rows): ?>
                        <div class="issue-empty">
                            <svg class="issue-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3H5a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-4"/><rect x="9" y="1" width="6" height="4" rx="1"/><path d="m8 13 3 3 5-6"/></svg>
                            <h3><?= $issueView === 'pending' ? 'No reports waiting for review' : 'No recent decisions' ?></h3>
                            <p><?= $issueView === 'pending' ? 'New Cashier reports will appear here.' : 'Stock Issue decisions will appear here.' ?></p>
                        </div>
                    <?php else: ?>
                        <div class="u-table-scroll history-table-panel" tabindex="0" role="region" aria-label="<?= $issueView === 'pending' ? 'Pending Stock Issues' : 'Stock Issue decisions' ?>">
                            <table class="history-table issue-list-table" data-no-ghost-rows>
                                <thead><tr>
                                    <?php foreach ($issueColumns as $key => $label): ?>
                                        <?php
                                        $activeSort = $sort === $key;
                                        $sortIcon = $activeSort ? ($direction === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') : 'bi-arrow-down-up';
                                        $nextDirection = $activeSort && $direction === 'asc' ? 'desc' : 'asc';
                                        ?>
                                        <th scope="col"<?= $activeSort ? ' aria-sort="' . ($direction === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                            <?php if (in_array($key, $sortableColumns, true) && ($key !== 'status' || $issueView === 'decisions')): ?>
                                                <a class="issue-sort-link" href="<?= htmlspecialchars($url(['sort' => $key, 'direction' => $nextDirection, 'page' => 1])) ?>">
                                                    <?= htmlspecialchars($label) ?><i class="bi <?= $sortIcon ?>" aria-hidden="true"></i>
                                                </a>
                                            <?php else: ?>
                                                <?= htmlspecialchars($label) ?>
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <?php $rowDate = $issueView === 'pending' ? $row['reported_at'] : ($row['approved_at'] ?? $row['reported_at']); ?>
                                        <tr>
                                            <td>#<?= (int)$row['adjustment_id'] ?></td>
                                            <td><strong><?= htmlspecialchars($row['product_name']) ?></strong><small class="muted issue-list-sku"><?= htmlspecialchars($row['sku']) ?></small></td>
                                            <td><span class="category-tag <?= htmlspecialchars($row['adjustment_type']) ?>"><?= htmlspecialchars(ucfirst($row['adjustment_type'])) ?></span></td>
                                            <td class="cell-qty"><?= $issueView === 'pending' || $row['status'] === 'approved' ? htmlspecialchars($formatIssueQty($row)) : '0 pieces' ?></td>
                                            <td><span class="adjustment-status <?= htmlspecialchars($row['status']) ?>"><?= in_array($row['status'], ['pending', 'returned'], true) ? 'Pending review' : htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                                            <td><time datetime="<?= htmlspecialchars($rowDate) ?>"><?= htmlspecialchars(format_display_datetime($rowDate)) ?></time></td>
                                            <td><a class="btn btn-secondary btn-small" href="<?= htmlspecialchars($detailUrl((int)$row['adjustment_id'])) ?>" aria-label="View details for report #<?= (int)$row['adjustment_id'] ?>">View details</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php require __DIR__ . '/../../../backend/app/Views/table_pagination.php'; ?>
                    <?php endif; ?>
                </section>
            <?php elseif ($issueView === 'pending'): ?>
            <div class="history-section" id="pending-section">
                <h2>Review report #<?= (int)$selectedReport['adjustment_id'] ?></h2>
                <?php if (empty($pending)): ?>
                    <div class="issue-empty">
                        <svg class="issue-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M9 5H6a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3" />
                            <rect x="9" y="2" width="6" height="5" rx="1" />
                            <path d="m8 14 3 3 5-6" />
                        </svg>
                        <h3>No reports waiting for review</h3>
                        <p>New Cashier reports will appear here.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending as $report): ?>
                        <?php
                        $productBatches = $stockIssueService->getProductBatches((int)$report['product_id']);
                        $reportForm = $report['form'] ?? 'piece';
                        $repQty = (int)($report['reported_qty'] ?? 0) ?: abs((int)$report['adjustment_qty']);
                        $formatDeduction = static fn(int|float $qty): string => $qty . ($reportForm === 'pack'
                            ? ($qty === 1 ? ' pack' : ' packs')
                            : ($qty === 1 ? ' piece' : ' pieces'));
                        $hasPackConfig = !empty($report['pack_size']) || (!empty($report['product_pack_size']) && (int)$report['product_pack_size'] > 1);
                        $availSealedSum = 0;
                        $availLooseSum = 0;
                        foreach ($productBatches as $pbItem) {
                            $availSealedSum += (int)($pbItem['sealed_quantity'] ?? 0);
                            $availLooseSum += (int)($pbItem['loose_quantity'] ?? 0);
                        }
                        $short = (int)$report['quantity_on_hand'] < abs((int)$report['adjustment_qty']);
                        if (!$short && !empty($productBatches)) {
                            if ($reportForm === 'pack' && $availSealedSum < $repQty) {
                                $short = true;
                            } elseif ($reportForm === 'piece' && $hasPackConfig && $availLooseSum < $repQty) {
                                $short = true;
                            }
                        }
                        ?>
                        <article class="adjustment-card pending issue-review" id="report-<?= (int)$report['adjustment_id'] ?>">
                            <div class="issue-review-summary">
                                <div class="issue-review-product">
                                    <h3 class="issue-review-title"><?= htmlspecialchars($report['product_name']) ?></h3>
                                    <span class="issue-review-meta"><?= htmlspecialchars($report['sku']) ?></span>
                                </div>
                                <span class="category-tag <?= htmlspecialchars($report['adjustment_type']) ?>"><?= htmlspecialchars(ucfirst($report['adjustment_type'])) ?></span>
                                <time class="adjustment-time" datetime="<?= htmlspecialchars($report['reported_at']) ?>"><?= htmlspecialchars(format_display_datetime($report['reported_at'])) ?></time>
                            </div>
                            <div class="issue-review-body">
                            <!-- Metrics Comparison Grid -->
                            <div class="detail-hero-grid">
                                <div class="detail-metric-card">
                                    <span class="detail-metric-label">Reported Quantity</span>
                                    <span class="detail-metric-value cell-qty"><?= htmlspecialchars($formatIssueQty($report)) ?></span>
                                </div>
                                <div class="detail-metric-card">
                                    <span class="detail-metric-label">Available in Stock</span>
                                    <span class="detail-metric-value"><?= (int)$report['quantity_on_hand'] ?> pcs</span>
                                    <?php if ($hasPackConfig && !empty($productBatches)): ?>
                                        <small class="muted"><?= $availSealedSum ?> sealed pk, <?= $availLooseSum ?> loose pcs</small>
                                    <?php endif; ?>
                                    <?php if ($short): ?>
                                        <div class="issue-warning">Reported quantity exceeds available stock</div>

                                    <?php endif; ?>
                                </div>
                                <div class="detail-metric-card">
                                    <span class="detail-metric-label">Reported By</span>
                                    <span class="detail-metric-value"><?= htmlspecialchars($report['cashier_name']) ?></span>
                                    <small class="muted">Cashier Shift #<?= (int)$report['shift_id'] ?></small>
                                </div>
                            </div>

                            <!-- Cashier note -->
                            <?php if (!empty($report['reason'])): ?>
                                <div class="detail-reason-box">
                                    <div class="reason-box-header">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                        <span>Cashier note</span>
                                    </div>
                                    <p class="reason-box-text"><?= htmlspecialchars($report['reason']) ?></p>
                                </div>
                            <?php endif; ?>

                            <?php $conversationReport = $report; require __DIR__ . '/../../../backend/app/Views/stock_issue_conversation.php'; ?>

                            </div>
                            <form method="POST" class="review-form" aria-labelledby="review-heading-<?= (int)$report['adjustment_id'] ?>" data-reported-quantity="<?= $repQty ?>" data-stock-form="<?= htmlspecialchars($reportForm) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="adjustment_id" value="<?= (int)$report['adjustment_id'] ?>">
                                <h3 class="issue-review-title" id="review-heading-<?= (int)$report['adjustment_id'] ?>">Review decision</h3>
                                <?php
                                $deductionQty = 0;
                                if (!empty($productBatches)):
                                    $fefoAllocations = $stockIssueService->recommendBatchAllocation((int)$report['product_id'], $repQty, $reportForm);
                                ?>
                                    <section class="issue-batches" aria-labelledby="batch-heading-<?= (int)$report['adjustment_id'] ?>">
                                        <h4 id="batch-heading-<?= (int)$report['adjustment_id'] ?>">Batch deductions (FEFO)</h4>
                                        <p>Earliest expiry first. Enter how many units to deduct from each batch.</p>
                                        <div class="table-wrap" role="region" aria-labelledby="batch-heading-<?= (int)$report['adjustment_id'] ?>" tabindex="0">
                                            <table data-no-ghost-rows>
                                                <thead>
                                                    <tr>
                                                        <th scope="col">Batch</th>
                                                        <th scope="col">Expiry date</th>
                                                        <th scope="col">Available</th>
                                                        <th scope="col">Deduct (<?= $reportForm === 'pack' ? 'Packs' : 'Pieces' ?>)</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($productBatches as $pb): ?>
                                                        <?php
                                                        $maxDeduct = $reportForm === 'pack'
                                                            ? (int)($pb['sealed_quantity'] ?? 0)
                                                            : ($hasPackConfig ? (int)($pb['loose_quantity'] ?? 0) : (int)$pb['remaining_quantity']);
                                                        $batchQty = (int)($error ? ($_POST['batches'][$pb['batch_id']] ?? $fefoAllocations[$pb['batch_id']] ?? 0) : ($fefoAllocations[$pb['batch_id']] ?? 0));
                                                        $deductionQty += $batchQty;
                                                        ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($pb['batch_number'] ?? ('#' . $pb['batch_id'])) ?></td>
                                                            <td><?= htmlspecialchars($pb['expiration_date'] ?? 'Not recorded') ?></td>
                                                            <td>
                                                                <?= (int)$pb['remaining_quantity'] ?> pcs
                                                                <?php if ($hasPackConfig): ?>
                                                                    <br><small class="muted"><?= (int)($pb['sealed_quantity'] ?? 0) ?> pk, <?= (int)($pb['loose_quantity'] ?? 0) ?> loose</small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <input type="number" inputmode="numeric"
                                                                       name="batches[<?= (int)$pb['batch_id'] ?>]"
                                                                       value="<?= $batchQty ?>"
                                                                       min="0"
                                                                       max="<?= $maxDeduct ?>"
                                                                       aria-label="<?= htmlspecialchars('Deduct ' . ($reportForm === 'pack' ? 'packs' : 'pieces') . ' from batch ' . ($pb['batch_number'] ?? ('#' . $pb['batch_id']))) ?>"
                                                                       class="form-control">
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                <?php else: ?>
                                    <?php $deductionQty = $error ? (int)($_POST['verified_quantity'] ?? $repQty) : $repQty; ?>
                                    <div class="form-group review-quantity-field">
                                        <label for="deduction-quantity-<?= (int)$report['adjustment_id'] ?>">Quantity to deduct (<?= $reportForm === 'pack' ? 'packs' : 'pieces' ?>)</label>
                                        <input type="number" inputmode="numeric" name="verified_quantity" id="deduction-quantity-<?= (int)$report['adjustment_id'] ?>" value="<?= $deductionQty ?>" min="1" required>
                                    </div>
                                <?php endif; ?>

                                <div class="form-group">
                                    <label for="review-notes-<?= (int)$report['adjustment_id'] ?>">Review reason</label>
                                    <textarea name="review_notes" id="review-notes-<?= (int)$report['adjustment_id'] ?>" rows="2" aria-describedby="review-reason-help-<?= (int)$report['adjustment_id'] ?>"><?= $error ? htmlspecialchars((string)($_POST['review_notes'] ?? '')) : '' ?></textarea>
                                    <small id="review-reason-help-<?= (int)$report['adjustment_id'] ?>">Required to reject or change quantity.</small>
                                </div>
                                <div class="review-footer">
                                    <p class="review-consequence">Approval permanently deducts stock.</p>
                                    <div class="review-actions">
                                        <button type="submit" name="action" value="approve" class="btn btn-action-approve" <?= $deductionQty <= 0 ? 'disabled' : '' ?>>
                                            Approve &amp; deduct <?= $formatDeduction($deductionQty) ?>
                                        </button>
                                        <button type="submit" name="action" value="reject" class="btn btn-quiet" formnovalidate>Reject report</button>
                                    </div>
                                </div>
                            </form>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php else: ?>
                <div class="history-section" id="reviews-section">
                        <h2>Report #<?= (int)$selectedReport['adjustment_id'] ?></h2>
                        <?php if (empty($reviews)): ?>
                            <div class="issue-empty">
                                <svg class="issue-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3H5a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-4"/><rect x="9" y="1" width="6" height="4" rx="1"/><path d="m8 13 3 3 5-6"/></svg>
                                <h3>No recent decisions</h3>
                                <p>Stock Issue decisions will appear here.</p>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($reviews as $review): ?>
                            <div class="adjustment-card <?= htmlspecialchars($review['status']) ?>">
                                <div class="adjustment-card-topbar">
                                    <div class="adjustment-badges">
                                        <span class="report-id-badge">Report #<?= (int)$review['adjustment_id'] ?></span>
                                        <span class="category-tag <?= htmlspecialchars($review['adjustment_type']) ?>">
                                            <?= htmlspecialchars(ucfirst($review['adjustment_type'])) ?>
                                        </span>
                                        <span class="adjustment-status <?= htmlspecialchars($review['status']) ?>">
                                            <?= htmlspecialchars(ucfirst($review['status'])) ?>
                                        </span>
                                    </div>
                                    <div class="adjustment-time">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        <span><?= htmlspecialchars(format_display_datetime($review['reported_at'])) ?></span>
                                    </div>
                                </div>

                                <div class="adjustment-product-info">
                                    <div class="adjustment-product-name"><?= htmlspecialchars($review['product_name']) ?></div>
                                    <span class="sku-code">SKU: <?= htmlspecialchars($review['sku']) ?></span>
                                </div>

                                <div class="detail-hero-grid">
                                    <div class="detail-metric-card">
                                        <span class="detail-metric-label"><?= $review['status'] === 'approved' ? 'Quantity Deducted' : 'Reported Quantity' ?></span>
                                        <span class="detail-metric-value cell-qty"><?= htmlspecialchars($formatIssueQty($review)) ?></span>
                                        <?php if ($review['status'] === 'approved'): ?>
                                            <small>Reported: <?= (int)($review['reported_qty'] ?? abs((int)$review['adjustment_qty'])) ?> <?= ($review['form'] ?? 'piece') === 'pack' ? 'packs' : 'pieces' ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="detail-metric-card">
                                        <span class="detail-metric-label">Recorded By</span>
                                        <span class="detail-metric-value"><?= htmlspecialchars($review['cashier_name']) ?></span>
                                    </div>
                                    <div class="detail-metric-card">
                                        <span class="detail-metric-label">Reviewed By</span>
                                        <span class="detail-metric-value"><?= htmlspecialchars($review['reviewer_name'] ?? 'Unknown') ?></span>
                                        <small class="muted"><?= htmlspecialchars(format_display_datetime($review['approved_at'])) ?></small>
                                    </div>
                                </div>

                                <?php if ($review['status'] === 'approved'): ?>
                                    <?php $reviewBatches = $stockIssueService->getReportBatches((int)$review['adjustment_id']); ?>
                                    <?php if (!empty($reviewBatches)): ?>
                                        <div class="detail-reason-box">
                                            <div class="reason-box-header">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                                <span>Deducted Batches</span>
                                            </div>
                                            <ul class="u-mt-1" style="margin-left: 1.25rem; font-size: .875rem;">
                                                <?php foreach ($reviewBatches as $rb): ?>
                                                    <li>Batch <?= htmlspecialchars($rb['batch_number'] ?? ('#' . $rb['batch_id'])) ?><?= !empty($rb['expiration_date']) ? ' (Exp: ' . htmlspecialchars($rb['expiration_date']) . ')' : '' ?>: <?= ($rb['form'] ?? 'piece') === 'pack' && (int)($rb['reported_qty'] ?? 0) > 0 ? '-' . (int)$rb['reported_qty'] . ' pack(s) (' . (int)$rb['quantity'] . ' pcs)' : '-' . (int)$rb['quantity'] . ' pc(s)' ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                    <div class="action-hints u-mt-1">
                                        <span>Approved reports are locked. Use an inventory count to correct stock.</span>
                                        <div class="u-mt-1">
                                            <a class="btn btn-secondary btn-small" href="<?= htmlspecialchars(app_url('components/inventory_management/inventory_counts.php?correct=' . (int)$review['adjustment_id'])) ?>">Correct via inventory count</a>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($review['reason'])): ?>
                                    <section class="detail-reason-box"><h3 class="reason-box-header">Report reason</h3><p class="reason-box-text"><?= htmlspecialchars($review['reason']) ?></p></section>
                                <?php endif; ?>

                                <?php if (!empty($review['review_notes'])): ?>
                                    <div class="detail-reason-box detail-reason-box--decision">
                                        <div class="reason-box-header">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                            <span>Reviewer Note</span>
                                        </div>
                                        <p class="reason-box-text"><?= htmlspecialchars($review['review_notes']) ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php $conversationReport = $review; require __DIR__ . '/../../../backend/app/Views/stock_issue_conversation.php'; ?>
                            </div>
                        <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <?php if ($issueView === 'record'): ?><script src="<?= htmlspecialchars(app_url('assets/js/stock-issue-form.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/stock-issue-form.js')) ?>"></script><?php endif; ?>
    <script>
    document.querySelectorAll('.review-form').forEach(form => {
        const quantity = form.elements.verified_quantity;
        const notes = form.elements.review_notes;
        const batches = Array.from(form.querySelectorAll('input[name^="batches["]'));
        const approve = form.querySelector('[value="approve"]');
        const deductionTotal = () => quantity
            ? quantity.valueAsNumber
            : batches.reduce((sum, field) => sum + (field.valueAsNumber || 0), 0);
        const refresh = () => {
            const value = deductionTotal();
            const unit = form.dataset.stockForm === 'pack' ? 'pack' : 'piece';
            const valid = Number.isSafeInteger(value) && value > 0;
            approve.textContent = valid ? `Approve & deduct ${value} ${unit}${value === 1 ? '' : 's'}` : 'Approve & deduct stock';
            approve.disabled = !valid;
            notes.required = value !== Number(form.dataset.reportedQuantity);
        };
        form.addEventListener('input', refresh);
        form.addEventListener('click', event => {
            const button = event.target.closest('button[type="submit"]');
            if (!button) return;
            notes.required = button.value !== 'approve' || deductionTotal() !== Number(form.dataset.reportedQuantity);
        });
        form.addEventListener('submit', event => {
            const approving = event.submitter?.value === 'approve';
            notes.required = !approving || deductionTotal() !== Number(form.dataset.reportedQuantity);
            if (!(approving ? form.reportValidity() : notes.reportValidity())) event.preventDefault();
        });
        refresh();
    });
    </script>
</body>

</html>
