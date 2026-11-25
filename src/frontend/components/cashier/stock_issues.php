<?php
// cashier/stock_issues.php — Report Stock Issue (tickets #29 + #30).
//
// Cashiers with an active shift report damaged / missing / expired / other
// stock discrepancies as pending reports. Submission never changes inventory.
// Product selection uses barcode scan or searchable lookup (no full dropdown).
// Own history is available without an active shift; old messages are read-only.
// Submitted details are locked; pending reports may be cancelled.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/StockIssueService.php';
header('Cache-Control: no-store');
require_role(['cashier']);

$stockIssueService = new StockIssueService($pdo, null, (string)current_role());
$message = '';
$error = '';

require __DIR__ . '/../../../backend/includes/stock_issue_conversation_actions.php';

$canRecordInInventory = in_array('inventory_manager', current_workspace_roles(), true);
$cashierId = (int)$_SESSION['user_id'];
$openShift = $canRecordInInventory ? null : $stockIssueService->getOpenShift($cashierId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['conversation_action'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    try {
        $correctionAction = $_POST['correction_action'] ?? 'submit';
        if ($correctionAction === 'cancel') {
            $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);
            $stockIssueService->cancelReport($adjustmentId, $cashierId);
            $message = "Report #{$adjustmentId} cancelled.";
            log_activity($pdo, $cashierId, 'Stock issue cancelled', 'Inventory Adjustments', $adjustmentId, ['status' => 'pending'], ['status' => 'cancelled']);
        } elseif ($correctionAction === 'submit') {
            if ($canRecordInInventory) {
                http_response_code(403);
                throw new RuntimeException('Open Inventory Stock Issues to record a confirmed issue.');
            }
            if (!$openShift) {
                throw new RuntimeException('An active cashier shift is required to submit a stock issue report.');
            }
            $adjustmentId = $stockIssueService->submitReport([
                'product_id' => $_POST['product_id'] ?? 0,
                'category' => $_POST['category'] ?? '',
                'quantity' => $_POST['quantity'] ?? 0,
                'form' => $_POST['form'] ?? 'piece',
                'explanation' => $_POST['explanation'] ?? '',
            ], $cashierId);

            $message = "Report #{$adjustmentId} submitted for review.";
            log_activity(
                $pdo,
                $cashierId,
                'Stock issue reported',
                'Inventory Adjustments',
                (int)$adjustmentId,
                null,
                ['adjustment_id' => (int)$adjustmentId, 'status' => 'pending']
            );
        } else {
            throw new RuntimeException('Submitted report details are locked. The Inventory Manager can confirm a different quantity.');
        }
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The stock report could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.');
    }
}

$productsApiUrl = app_url('components/barcodeScanner/apiScanner/products.php?scope=stock_issues');
$pageTitle = $canRecordInInventory ? 'Stock Issues' : 'Report Stock Issue';

$statusLabels = [
    'all' => 'All',
    'pending' => 'Awaiting manager',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled',
];
$status = in_array($_GET['status'] ?? '', array_keys($statusLabels), true) ? $_GET['status'] : 'all';
$length = (int)($_GET['length'] ?? 15);
$pageSize = in_array($length, [10, 15, 25, 50, 100], true) ? $length : 15;
$counts = $stockIssueService->getCashierReportCounts($cashierId);
$totalRows = $counts[$status];
$totalPages = max(1, (int)ceil($totalRows / $pageSize));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$hasNext = $page < $totalPages;
$selectedReport = $conversationDetailId > 0 ? ($stockIssueService->getReportsByCashier($cashierId, 1, $conversationDetailId)[0] ?? null) : null;
$rows = $selectedReport ? [] : $stockIssueService->getReportsByCashier($cashierId, $pageSize, null, ($page - 1) * $pageSize, $status);
$paginationFilters = ['status' => $status];
$paginationLabel = 'Report history pages';
$paginationAction = '#report-history';
$url = static fn(array $changes): string => '?' . http_build_query(array_replace($paginationFilters + ['page' => $page, 'length' => $pageSize], $changes)) . '#report-history';
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/stock-issues.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/stock-issues.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier-pages.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/cashier-pages.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/stock-issue-conversation.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/stock-issue-conversation.css')) ?>">
    <script src="<?= htmlspecialchars(app_url('assets/js/stock-issue-conversation.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/stock-issue-conversation.js')) ?>" defer></script>
</head>

<body class="cashier-stock-issues-page">
    <a class="skip-link" href="#stock-issues-main">Skip to main content</a>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content" id="stock-issues-main">
            <div class="topbar stock-issues-topbar">
                <div class="stock-issues-header-info">
                    <div class="stock-issues-title-row">
                        <h1><i class="bi bi-clipboard2-x" aria-hidden="true"></i> <?= $pageTitle ?></h1>
                        <?php if ($openShift): ?>
                            <span class="stock-shift-badge stock-shift-badge--active" title="Open shift #<?= (int)$openShift['shift_id'] ?>">
                                <span class="stock-shift-dot" aria-hidden="true"></span> Shift #<?= (int)$openShift['shift_id'] ?> Active
                            </span>
                        <?php elseif (!$canRecordInInventory): ?>
                            <span class="stock-shift-badge stock-shift-badge--inactive">
                                <i class="bi bi-exclamation-circle" aria-hidden="true"></i> Shift Required
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$canRecordInInventory): ?>
                        <p class="page-subtitle">Record damaged, missing, or expired stock for manager review.</p>
                    <?php endif; ?>
                </div>
                <div class="stock-issues-topbar-actions">
                    <?php if ($canRecordInInventory): ?>
                        <form method="post" data-rm-backup-bypass="true" action="<?= htmlspecialchars(app_url('components/auth/workspace.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="workspace" value="inventory_manager">
                            <input type="hidden" name="destination" value="record_stock_issue">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-box-seam" aria-hidden="true"></i> Open Inventory Stock Issues
                            </button>
                        </form>
                    <?php endif; ?>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i> Return to POS
                    </a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message success" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php foreach ([[$message, 'success'], [$error, 'error']] as [$notice, $noticeType]): ?>
                <?php if ($notice !== ''): ?>
                    <script>document.addEventListener('DOMContentLoaded', () => RetailMindUI.toast(<?= json_encode($notice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($noticeType) ?>, undefined, 4300));</script>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (!$canRecordInInventory && !$openShift): ?>
                <div class="workflow-notice stock-shift-notice" role="note" data-rm-alert-processed="1">
                    <div class="stock-shift-notice-icon" aria-hidden="true">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                    <div class="stock-shift-notice-content">
                        <h2>Shift Required</h2>
                        <p>Start a shift to submit new reports. Existing reports remain viewable below.</p>
                    </div>
                    <a class="btn" href="<?= htmlspecialchars(app_url('components/cashier/shifts.php')) ?>">
                        <i class="bi bi-clock-history" aria-hidden="true"></i> Open Shift
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!$canRecordInInventory): ?>
                <?php $directStockIssue = false; $stockIssueCanRecord = (bool)$openShift; require __DIR__ . '/../../../backend/app/Views/stock_issue_form.php'; ?>
            <?php endif; ?>

            <section class="history-section u-mt-2 stock-history-workspace" id="report-history">
                <div class="stock-history-header">
                    <h2 id="report-history-heading"><i class="bi bi-journal-text" aria-hidden="true"></i> Report History</h2>
                    <?php if ($selectedReport): ?>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars($url([])) ?>">Back to list</a>
                    <?php endif; ?>
                </div>

                <?php if (!$selectedReport): ?>
                    <nav class="tabs stock-filter-tabs" aria-label="Filter report status">
                        <?php foreach ($statusLabels as $key => $label): ?>
                            <a class="stock-tab-pill<?= $status === $key ? ' active' : '' ?>" href="<?= htmlspecialchars($url(['status' => $key, 'page' => 1])) ?>" <?= $status === $key ? 'aria-current="page"' : '' ?>>
                                <?= htmlspecialchars($label) ?> <span class="stock-tab-count"><?= $counts[$key] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <?php if (!$rows): ?>
                        <div id="report-filter-status" class="stock-empty-filter" role="status">
                            <div class="stock-empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></div>
                            <p><?= $status === 'all' ? 'No stock issue reports yet.' : 'No reports in this status.' ?></p>
                        </div>
                    <?php else: ?>
                        <div class="u-table-scroll history-table-panel" tabindex="0" role="region" aria-labelledby="report-history-heading">
                            <table class="history-table issue-list-table" data-no-ghost-rows>
                                <thead>
                                    <tr>
                                        <th scope="col">Report</th>
                                        <th scope="col">Product</th>
                                        <th scope="col">Issue type</th>
                                        <th scope="col">Quantity</th>
                                        <th scope="col">Status</th>
                                        <th scope="col" aria-sort="descending">Reported</th>
                                        <th scope="col">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <?php $reportId = (int)$row['adjustment_id']; ?>
                                        <tr id="report-<?= $reportId ?>">
                                            <td>#<?= $reportId ?></td>
                                            <td><strong><?= htmlspecialchars($row['product_name']) ?></strong><small class="muted issue-list-sku"><?= htmlspecialchars($row['sku']) ?></small></td>
                                            <td><span class="stock-type-tag stock-type-<?= htmlspecialchars($row['adjustment_type']) ?>"><?= htmlspecialchars(ucfirst($row['adjustment_type'])) ?></span></td>
                                            <td class="cell-qty"><?= htmlspecialchars(StockIssueService::formatIssueQuantity($row)) ?></td>
                                            <td><span class="stock-status-badge status-badge-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars(StockIssueService::statusLabel($row['status'])) ?></span></td>
                                            <td><time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $row['reported_at'])) ?>"><?= htmlspecialchars(format_display_datetime($row['reported_at'])) ?></time></td>
                                            <td><a class="btn btn-secondary btn-small" href="<?= htmlspecialchars($url(['detail' => $reportId])) ?>" aria-label="View details for report #<?= $reportId ?>">View details</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php require __DIR__ . '/../../../backend/app/Views/table_pagination.php'; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?php $adj = $selectedReport; ?>
                    <div id="report-<?= (int)$adj['adjustment_id'] ?>" class="adjustment-card stock-report-card status-<?= htmlspecialchars($adj['status']) ?>">
                        <div class="stock-report-top">
                            <div class="stock-report-primary">
                                <div class="stock-report-title-row">
                                    <span class="adjustment-product"><?= htmlspecialchars($adj['product_name']) ?></span>
                                    <span class="stock-sku-badge"><i class="bi bi-upc" aria-hidden="true"></i> <?= htmlspecialchars($adj['sku']) ?></span>
                                </div>
                                <div class="stock-type-tag stock-type-<?= htmlspecialchars($adj['adjustment_type']) ?>">
                                    <?php
                                    $typeIcons = [
                                        'damaged' => 'bi-exclamation-octagon',
                                        'missing' => 'bi-search',
                                        'expired' => 'bi-calendar-x',
                                        'other' => 'bi-question-circle',
                                    ];
                                    $iconClass = $typeIcons[$adj['adjustment_type']] ?? 'bi-tag';
                                    ?>
                                    <i class="bi <?= $iconClass ?>" aria-hidden="true"></i>
                                    <?= htmlspecialchars(ucfirst($adj['adjustment_type'])) ?>
                                </div>
                            </div>
                            <div class="stock-report-aside">
                                <div class="stock-qty-chip">
                                    <span class="stock-qty-num"><?= htmlspecialchars(StockIssueService::formatIssueQuantity($adj)) ?></span>
                                </div>
                                <?php if ($adj['status'] === 'approved'): ?>
                                    <small>Reported: <?= (int)($adj['reported_qty'] ?? abs((int)$adj['adjustment_qty'])) ?> <?= ($adj['form'] ?? 'piece') === 'pack' ? 'packs' : 'pieces' ?></small>
                                <?php endif; ?>
                                <span class="stock-status-badge status-badge-<?= htmlspecialchars($adj['status']) ?>">
                                    <?php
                                    $statusIcons = [
                                        'pending' => 'bi-clock-fill',
                                        'returned' => 'bi-arrow-return-left',
                                        'approved' => 'bi-check-circle-fill',
                                        'rejected' => 'bi-x-circle-fill',
                                        'cancelled' => 'bi-slash-circle',
                                    ];
                                    $sIcon = $statusIcons[$adj['status']] ?? 'bi-circle';
                                    ?>
                                    <i class="bi <?= $sIcon ?>" aria-hidden="true"></i>
                                    <?= htmlspecialchars(StockIssueService::statusLabel($adj['status'])) ?>
                                </span>
                            </div>
                        </div>
                        <div class="stock-report-meta-row">
                            <span class="meta-item"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= htmlspecialchars(format_display_datetime($adj['reported_at'])) ?></span>
                            <span class="meta-item"><i class="bi bi-hash" aria-hidden="true"></i> Report #<?= (int)$adj['adjustment_id'] ?></span>
                            <?php if (!empty($adj['shift_id'])): ?>
                                <span class="meta-item"><i class="bi bi-person-badge" aria-hidden="true"></i> Shift #<?= (int)$adj['shift_id'] ?></span>
                            <?php endif; ?>
                            <?php if ($adj['status'] !== 'pending' && !empty($adj['approved_at'])): ?>
                                <span class="meta-item meta-reviewer"><i class="bi bi-person-check" aria-hidden="true"></i> Reviewed by <?= htmlspecialchars($adj['reviewer_name'] ?? 'Manager') ?> at <?= htmlspecialchars(format_display_datetime($adj['approved_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($adj['reason']): ?>
                            <div class="stock-report-reason">
                                <p class="stock-reason-text"><?= htmlspecialchars($adj['reason']) ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($adj['review_notes']) && in_array($adj['status'], ['approved', 'rejected'], true)): ?>
                            <div class="stock-reviewer-note <?= $adj['status'] === 'returned' ? 'is-returned-note' : '' ?>">
                                <span class="stock-reviewer-label"><i class="bi bi-reply-fill" aria-hidden="true"></i> Reviewer note:</span>
                                <p class="stock-reviewer-text"><?= htmlspecialchars($adj['review_notes']) ?></p>
                            </div>
                        <?php endif; ?>
                        <?php $conversationReport = $adj; require __DIR__ . '/../../../backend/app/Views/stock_issue_conversation.php'; ?>
                        <?php if ($adj['status'] === 'pending'): ?>
                            <form method="POST" class="stock-cancel-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="adjustment_id" value="<?= (int)$adj['adjustment_id'] ?>">
                                <button type="submit" name="correction_action" value="cancel" class="btn btn-secondary" onclick="return confirm('Cancel this report?')">Cancel report</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <?php if (!$canRecordInInventory): ?>
        <script src="<?= htmlspecialchars(app_url('assets/js/stock-issue-form.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/stock-issue-form.js')) ?>"></script>
    <?php endif; ?>
</body>

</html>
