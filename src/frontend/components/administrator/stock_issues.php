<?php
// components/administrator/stock_issues.php — Stock Issue Oversight (ticket #31).
//
// Read-only Administrator oversight of every stock-issue report: filter by
// status, category, product, cashier, approver, and date range; open a report
// detail exposing the complete lifecycle, decision reasons, append-only
// revisions, actors, timestamps, linked stock movement, and any correction
// inventory counts. This page has no mutation seam: POSTs are refused outright,
// and the service layer keeps Administrators off every approve/reject/return/
// edit seam. Approved and rejected reports stay immutable; an erroneous
// approved report is corrected only through a separate Inventory Manager
// inventory-count transaction linked back to this record.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/StockIssueService.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    exit('Read-only oversight: stock issue reports cannot be changed from this page.');
}
require_role(['admin']);

$stockIssueService = new StockIssueService($pdo, null, (string)current_role());
$viewerId = (int)$_SESSION['user_id'];

$categoryLabels = [
    'damaged' => 'Damaged',
    'missing' => 'Missing/Lost',
    'expired' => 'Expired',
    'other' => 'Other',
];
$categoryLabel = static function (string $category) use ($categoryLabels): string {
    return $categoryLabels[$category] ?? ucfirst($category);
};
$formatIssueQty = [StockIssueService::class, 'formatIssueQuantity'];

$filters = [
    'status' => trim((string)($_GET['status'] ?? '')),
    'category' => trim((string)($_GET['category'] ?? '')),
    'product' => trim((string)($_GET['product'] ?? '')),
    'cashier_id' => (int)($_GET['cashier_id'] ?? 0),
    'approver_id' => (int)($_GET['approver_id'] ?? 0),
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
];

$error = '';
$reports = [];
try {
    $reports = $stockIssueService->getOversightReports($filters, $viewerId);
} catch (RuntimeException $exception) {
    $error = \App\Support\OperatorAlert::message($exception, 'Stock issue reports could not be loaded. Refresh the page and try again. Tell your Super Administrator if this keeps happening.');
}

$detail = null;
$detailId = (int)($_GET['detail'] ?? 0);
if ($detailId > 0 && $error === '') {
    try {
        $detail = $stockIssueService->getReportDetail($detailId, $viewerId);
        if ($detail === null) {
            $error = "Stock issue report #{$detailId} was not found.";
        }
    } catch (RuntimeException $exception) {
        $error = \App\Support\OperatorAlert::message($exception, 'The report details could not be loaded. Refresh the page and try again. Tell your Super Administrator if this keeps happening.');
    }
}

$appliedFilters = array_filter($filters, static fn($value) => $value !== '' && $value !== 0);
$filterQuery = $appliedFilters !== [] ? http_build_query($appliedFilters) . '&' : '';
$hasAdvancedFilters = $filters['cashier_id'] !== 0
    || $filters['approver_id'] !== 0
    || $filters['date_from'] !== ''
    || $filters['date_to'] !== '';

$cashiers = [];
$approvers = [];
try {
    $cashiers = $pdo->query(
        "SELECT u.user_id, u.full_name FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE r.role_name = 'cashier' AND u.status = 'active'
         ORDER BY u.full_name"
    )->fetchAll(PDO::FETCH_ASSOC);
    $approvers = $pdo->query(
        "SELECT u.user_id, u.full_name FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE r.role_name = 'inventory_manager' AND u.status = 'active'
         ORDER BY u.full_name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $error = $error !== '' ? $error : 'Staff filter options are unavailable.';
}

$kpiTotals = [
    'total' => count($reports),
    'pending' => 0,
    'approved' => 0,
    'returned' => 0,
    'rejected' => 0,
];
foreach ($reports as $r) {
    $st = strtolower((string)($r['status'] ?? ''));
    if (isset($kpiTotals[$st])) {
        $kpiTotals[$st]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Issue Oversight</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/stock-issues.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/stock-issues.css')) ?>">
</head>

<body class="stock-issues-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <header class="page-heading">
                <div>
                    <h1>Stock Issue Oversight</h1>
                    <p class="page-subheading">Track cashier-reported discrepancies and loss resolutions.</p>
                </div>
                <?php if ($detail !== null): ?>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/administrator/stock_issues.php' . ($filterQuery !== '' ? '?' . rtrim($filterQuery, '&') : ''))) ?>" aria-label="Back to reports list">Back to list</a>
                <?php endif; ?>
            </header>

            <?php if ($error): ?>
                <div class="message error" role="alert" data-rm-alert-processed="1"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <section class="stock-kpi-grid" aria-label="Stock issues summary">
                <div class="stock-kpi-card">
                    <span class="stock-kpi-label">In View</span>
                    <strong class="stock-kpi-value"><?= (int)$kpiTotals['total'] ?></strong>
                    <span class="stock-kpi-meta"><?= $appliedFilters !== [] ? count($appliedFilters) . ' filtered' : 'All records' ?></span>
                </div>
                <div class="stock-kpi-card <?= $kpiTotals['pending'] > 0 ? 'stock-kpi-card--warning' : '' ?>">
                    <span class="stock-kpi-label">Pending Review</span>
                    <strong class="stock-kpi-value"><?= (int)$kpiTotals['pending'] ?></strong>
                    <span class="stock-kpi-meta"><?= $kpiTotals['pending'] > 0 ? 'Awaiting manager' : 'All clear' ?></span>
                </div>
                <div class="stock-kpi-card stock-kpi-card--success">
                    <span class="stock-kpi-label">Approved</span>
                    <strong class="stock-kpi-value"><?= (int)$kpiTotals['approved'] ?></strong>
                    <span class="stock-kpi-meta">Deductions applied</span>
                </div>
                <div class="stock-kpi-card">
                    <span class="stock-kpi-label">Returned / Rejected</span>
                    <strong class="stock-kpi-value"><?= (int)($kpiTotals['returned'] + $kpiTotals['rejected']) ?></strong>
                    <span class="stock-kpi-meta"><?= (int)$kpiTotals['returned'] ?> ret · <?= (int)$kpiTotals['rejected'] ?> rej</span>
                </div>
            </section>

            <?php if ($detail !== null): ?>
                <?php
                $report = $detail['report'];
                $revisions = $detail['revisions'] ?? [];
                $batches = $detail['batches'] ?? [];
                $movements = $detail['stock_movements'] ?? [];
                $corrections = $detail['correction_counts'] ?? [];
                ?>
                <section class="history-section" aria-labelledby="detail-heading">
                    <div class="section-header">
                        <h2 id="detail-heading">Report #<?= (int)$report['adjustment_id'] ?> Details</h2>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/administrator/stock_issues.php' . ($filterQuery !== '' ? '?' . rtrim($filterQuery, '&') : ''))) ?>" aria-label="Back to reports list">Back to list</a>
                    </div>

                    <div class="adjustment-card <?= htmlspecialchars($report['status']) ?>">
                        <div class="adjustment-header">
                            <div>
                                <div class="adjustment-product"><?= htmlspecialchars($report['product_name']) ?></div>
                                <div class="adjustment-sku"><span class="sku-code"><?= htmlspecialchars($report['sku']) ?></span></div>
                                <span class="category-tag <?= htmlspecialchars($report['adjustment_type']) ?> u-mt-05">
                                    <?= htmlspecialchars($categoryLabel((string)$report['adjustment_type'])) ?>
                                </span>
                            </div>
                            <div class="u-text-right">
                                <span class="issue-quantity-label"><?= $report['status'] === 'approved' ? 'Deducted' : 'Reported' ?></span>
                                <div class="adjustment-qty"><?= htmlspecialchars($formatIssueQty($report)) ?></div>
                                <span class="adjustment-status <?= htmlspecialchars($report['status']) ?>">
                                    <?= htmlspecialchars(ucfirst($report['status'])) ?>
                                </span>
                            </div>
                        </div>

                        <div class="detail-hero-grid">
                            <div class="detail-metric-card">
                                <span class="detail-metric-label">Cashier</span>
                                <span class="detail-metric-value"><?= htmlspecialchars($report['cashier_name']) ?></span>
                            </div>
                            <div class="detail-metric-card">
                                <span class="detail-metric-label">Shift</span>
                                <span class="detail-metric-value"><?= !empty($report['shift_id']) ? '#' . (int)$report['shift_id'] : '—' ?></span>
                            </div>
                            <div class="detail-metric-card">
                                <span class="detail-metric-label">Reported At</span>
                                <span class="detail-metric-value"><?= htmlspecialchars(format_display_datetime($report['reported_at'])) ?></span>
                            </div>
                            <div class="detail-metric-card">
                                <span class="detail-metric-label">Decided By</span>
                                <span class="detail-metric-value">
                                    <?php if (!empty($report['approved_at'])): ?>
                                        <?= htmlspecialchars($report['reviewer_name'] ?? 'Unknown') ?> · <?= htmlspecialchars(format_display_datetime($report['approved_at'])) ?>
                                    <?php else: ?>
                                        <span class="u-text-muted">Awaiting decision</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($report['reason'])): ?>
                            <div class="detail-reason-box">
                                <strong>Cashier note:</strong> <?= htmlspecialchars($report['reason']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($report['review_notes'])): ?>
                            <div class="detail-reason-box detail-reason-box--decision">
                                <strong>Decision note:</strong> <?= htmlspecialchars($report['review_notes']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($batches)): ?>
                            <details class="issue-batches" open>
                                <summary>Deducted batches (<?= count($batches) ?>)</summary>
                                <div class="table-wrap u-mt-05">
                                    <table aria-label="Deducted batches">
                                        <thead>
                                            <tr>
                                                <th scope="col">Batch</th>
                                                <th scope="col">Expiration</th>
                                                <th scope="col">Quantity Deducted</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($batches as $batch): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($batch['batch_number'] ?? ('#' . $batch['batch_id'])) ?></td>
                                                    <td><?= htmlspecialchars($batch['expiration_date'] ?? 'Not recorded') ?></td>
                                                    <td><?= ($batch['form'] ?? 'piece') === 'pack' && (int)($batch['reported_qty'] ?? 0) > 0 ? '-' . (int)$batch['reported_qty'] . ' pack(s) (' . (int)$batch['quantity'] . ' pcs)' : '-' . (int)$batch['quantity'] . ' pc(s)' ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        <?php endif; ?>

                        <?php if (!empty($movements)): ?>
                            <details class="issue-batches" open>
                                <summary>Linked stock movements (<?= count($movements) ?>)</summary>
                                <div class="table-wrap u-mt-05">
                                    <table aria-label="Linked stock movements">
                                        <thead>
                                            <tr>
                                                <th scope="col">Movement</th>
                                                <th scope="col">Change</th>
                                                <th scope="col">Reason</th>
                                                <th scope="col">Recorded by</th>
                                                <th scope="col">Moved at</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($movements as $movement): ?>
                                                <tr>
                                                    <td>#<?= (int)$movement['movement_id'] ?></td>
                                                    <td><?= (int)$movement['change_qty'] > 0 ? '+' . (int)$movement['change_qty'] : (int)$movement['change_qty'] ?></td>
                                                    <td><?= htmlspecialchars($movement['reason']) ?></td>
                                                    <td><?= htmlspecialchars($movement['moved_by_name'] ?? ('User #' . (int)$movement['moved_by'])) ?></td>
                                                    <td><?= htmlspecialchars(format_display_datetime($movement['moved_at'])) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        <?php endif; ?>

                        <?php if (!empty($corrections)): ?>
                            <details class="issue-batches" open>
                                <summary>Correction inventory counts (<?= count($corrections) ?>)</summary>
                                <div class="table-wrap u-mt-05">
                                    <table aria-label="Correction inventory counts">
                                        <thead>
                                            <tr>
                                                <th scope="col">Count</th>
                                                <th scope="col">System</th>
                                                <th scope="col">Physical</th>
                                                <th scope="col">Difference</th>
                                                <th scope="col">Reason</th>
                                                <th scope="col">Counted by</th>
                                                <th scope="col">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($corrections as $countRow): ?>
                                                <?php $hasCountPack = !empty($countRow['product_pack_size']) && (int)$countRow['product_pack_size'] > 1; ?>
                                                <tr>
                                                    <td>#<?= (int)$countRow['count_id'] ?></td>
                                                    <td>
                                                        <?= (int)$countRow['system_quantity'] ?>
                                                        <?php if ($hasCountPack && array_key_exists('system_sealed_quantity', $countRow)): ?>
                                                            <br><small class="u-text-muted"><?= (int)$countRow['system_sealed_quantity'] ?> pk, <?= (int)$countRow['system_loose_quantity'] ?> loose</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= (int)$countRow['physical_quantity'] ?>
                                                        <?php if ($hasCountPack && array_key_exists('physical_sealed_quantity', $countRow)): ?>
                                                            <br><small class="u-text-muted"><?= (int)$countRow['physical_sealed_quantity'] ?> pk, <?= (int)$countRow['physical_loose_quantity'] ?> loose</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= (int)$countRow['difference_qty'] > 0 ? '+' . (int)$countRow['difference_qty'] : (int)$countRow['difference_qty'] ?></td>
                                                    <td><?= htmlspecialchars($countRow['discrepancy_reason']) ?></td>
                                                    <td><?= htmlspecialchars($countRow['counted_by_name'] ?? 'Unknown') ?></td>
                                                    <td><?= htmlspecialchars($countRow['status']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        <?php endif; ?>

                        <?php if (!empty($revisions)): ?>
                            <details class="issue-batches">
                                <summary>Lifecycle revisions (<?= count($revisions) ?>)</summary>
                                <?php foreach ($revisions as $revision): ?>
                                    <div class="adjustment-details u-mt-05">
                                        <span><strong>#<?= (int)$revision['revision_id'] ?> <?= htmlspecialchars($revision['action']) ?></strong></span>
                                        <span><?= htmlspecialchars($revision['actor_name'] ?? ('User #' . (int)$revision['actor_id'])) ?></span>
                                        <span><?= htmlspecialchars(format_display_datetime($revision['created_at'])) ?></span>
                                        <span><?= htmlspecialchars((string)($revision['old_status'] ?? '—')) ?> → <?= htmlspecialchars((string)($revision['new_status'] ?? '—')) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </details>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="dashboard-section u-mb-15" aria-labelledby="filter-heading">
                <div class="section-header">
                    <h2 id="filter-heading">Filter reports</h2>
                    <?php if ($appliedFilters !== []): ?>
                        <span class="issue-count"><?= count($appliedFilters) ?> active</span>
                    <?php endif; ?>
                </div>

                <div class="stock-status-pills" role="tablist" aria-label="Filter reports by status">
                    <?php
                    $statusTabs = [
                        '' => 'All',
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'returned' => 'Returned',
                        'rejected' => 'Rejected',
                    ];
                    foreach ($statusTabs as $statusVal => $statusLabel):
                        $isActive = $filters['status'] === $statusVal;
                        $pillParams = $filters;
                        if ($statusVal === '') {
                            unset($pillParams['status']);
                        } else {
                            $pillParams['status'] = $statusVal;
                        }
                        $cleanParams = array_filter($pillParams, static fn($v) => $v !== '' && $v !== 0);
                        $pillUrl = app_url('components/administrator/stock_issues.php' . ($cleanParams !== [] ? '?' . http_build_query($cleanParams) : ''));
                    ?>
                        <a href="<?= htmlspecialchars($pillUrl) ?>" 
                           class="stock-status-pill <?= $isActive ? 'is-active' : '' ?>" 
                           role="tab" 
                           aria-selected="<?= $isActive ? 'true' : 'false' ?>">
                            <?= htmlspecialchars($statusLabel) ?>
                            <?php if ($statusVal !== '' && isset($kpiTotals[$statusVal])): ?>
                                <span class="pill-badge"><?= (int)$kpiTotals[$statusVal] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <form method="GET">
                    <div class="report-filter-grid">
                        <div class="form-group">
                            <label for="filter-product">Product</label>
                            <input type="text" name="product" id="filter-product" value="<?= htmlspecialchars($filters['product']) ?>" placeholder="SKU or product name" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label for="filter-status">Status</label>
                            <select name="status" id="filter-status">
                                <option value="">All statuses</option>
                                <?php foreach (StockIssueService::STATUSES as $statusOption): ?>
                                    <option value="<?= htmlspecialchars($statusOption) ?>" <?= $filters['status'] === $statusOption ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ucfirst($statusOption)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="filter-category">Category</label>
                            <select name="category" id="filter-category">
                                <option value="">All categories</option>
                                <?php foreach (StockIssueService::CATEGORIES as $categoryOption): ?>
                                    <option value="<?= htmlspecialchars($categoryOption) ?>" <?= $filters['category'] === $categoryOption ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($categoryLabel($categoryOption)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <details class="issue-batches u-mt-05" <?= $hasAdvancedFilters ? 'open' : '' ?>>
                        <summary>Staff &amp; date filters</summary>
                        <div class="report-filter-grid u-mt-05">
                            <div class="form-group">
                                <label for="filter-cashier">Cashier</label>
                                <select name="cashier_id" id="filter-cashier">
                                    <option value="">All cashiers</option>
                                    <?php foreach ($cashiers as $cashierOption): ?>
                                        <option value="<?= (int)$cashierOption['user_id'] ?>" <?= $filters['cashier_id'] === (int)$cashierOption['user_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cashierOption['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filter-approver">Approver</label>
                                <select name="approver_id" id="filter-approver">
                                    <option value="">All approvers</option>
                                    <?php foreach ($approvers as $approverOption): ?>
                                        <option value="<?= (int)$approverOption['user_id'] ?>" <?= $filters['approver_id'] === (int)$approverOption['user_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($approverOption['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filter-date-from">Reported from</label>
                                <input type="date" name="date_from" id="filter-date-from" value="<?= htmlspecialchars($filters['date_from']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="filter-date-to">Reported to</label>
                                <input type="date" name="date_to" id="filter-date-to" value="<?= htmlspecialchars($filters['date_to']) ?>">
                            </div>
                        </div>
                    </details>

                    <div class="review-actions u-mt-1">
                        <button type="submit" class="btn">Apply filters</button>
                        <?php if ($appliedFilters !== []): ?>
                            <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/administrator/stock_issues.php')) ?>" aria-label="Reset all filters">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="history-section" aria-labelledby="reports-heading">
                <h2 id="reports-heading">Stock Issue reports <span class="issue-count"><?= count($reports) ?></span></h2>
                <?php if (empty($reports)): ?>
                    <div class="issue-empty">
                        <svg class="issue-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M9 5H6a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3" />
                            <rect x="9" y="2" width="6" height="5" rx="1" />
                            <path d="m8 14 3 3 5-6" />
                        </svg>
                        <h3>No stock issue reports match the current filters</h3>
                        <p>Adjust your search keywords, status, or date range to find reports.</p>
                        <?php if ($appliedFilters !== []): ?>
                            <a class="btn btn-secondary u-mt-05" href="<?= htmlspecialchars(app_url('components/administrator/stock_issues.php')) ?>" aria-label="Reset all filters">Clear all filters</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table aria-label="Stock issue reports">
                            <thead>
                                <tr>
                                    <th scope="col">Report</th>
                                    <th scope="col">Product</th>
                                    <th scope="col">Category</th>
                                    <th scope="col">Qty</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Cashier</th>
                                    <th scope="col">Approver</th>
                                    <th scope="col">Reported</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $row): ?>
                                    <tr>
                                        <td>#<?= (int)$row['adjustment_id'] ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($row['product_name']) ?></strong>
                                            <br><span class="sku-code"><?= htmlspecialchars($row['sku']) ?></span>
                                        </td>
                                        <td>
                                            <span class="category-tag <?= htmlspecialchars($row['adjustment_type']) ?>">
                                                <?= htmlspecialchars($categoryLabel((string)$row['adjustment_type'])) ?>
                                            </span>
                                        </td>
                                        <td class="cell-qty"><?= htmlspecialchars($formatIssueQty($row)) ?></td>
                                        <td>
                                            <span class="adjustment-status <?= htmlspecialchars($row['status']) ?>">
                                                <?= htmlspecialchars(ucfirst($row['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($row['cashier_name']) ?></td>
                                        <td><?= htmlspecialchars($row['reviewer_name'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars(format_display_datetime($row['reported_at'])) ?></td>
                                        <td>
                                            <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/administrator/stock_issues.php?' . $filterQuery . 'detail=' . (int)$row['adjustment_id'])) ?>" aria-label="Open report #<?= (int)$row['adjustment_id'] ?>">Open report</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>

</html>
