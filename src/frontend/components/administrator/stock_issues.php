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

$stockIssueService = new StockIssueService($pdo);
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
    $error = \App\Support\OperatorAlert::message($exception, 'Stock issue reports could not be loaded. Refresh the page and try again. Tell your Administrator if this keeps happening.');
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
        $error = \App\Support\OperatorAlert::message($exception, 'The report details could not be loaded. Refresh the page and try again. Tell your Administrator if this keeps happening.');
    }
}

$appliedFilters = array_filter($filters, static fn($value) => $value !== '' && $value !== 0);
$filterQuery = $appliedFilters !== [] ? http_build_query($appliedFilters) . '&' : '';

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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Issue Oversight</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div>
                    <h1>Stock Issue Oversight</h1>
                    <p class="page-subtitle">Read-only review of every Stock Issue report: Damaged, Missing/Lost, Expired, and Other. Administrators cannot approve, reject, edit, or delete reports from this page.</p>
                </div>
                <span class="badge-role"><?= count($reports) ?> Reports</span>
            </div>

            <?php if ($error): ?>
                <div class="message error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="history-section">
                <h3>Filters</h3>
                <form method="GET" class="review-form">
                    <div class="form-grid">
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
                        <div class="form-group">
                            <label for="filter-product">Product</label>
                            <input type="text" name="product" id="filter-product" value="<?= htmlspecialchars($filters['product']) ?>" placeholder="SKU or product name">
                        </div>
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
                    <div class="review-actions">
                        <button type="submit" class="btn-submit">Apply filters</button>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/administrator/stock_issues.php')) ?>">Clear</a>
                    </div>
                </form>
            </div>

            <?php if ($detail !== null): ?>
                <?php $report = $detail['report']; ?>
                <div class="history-section u-mt-2">
                    <div class="section-header">
                        <div>
                            <h3>Report #<?= (int)$report['adjustment_id'] ?> — Stock Issue detail</h3>
                            <p class="section-description">Complete lifecycle history. Approved and rejected reports are immutable; corrections happen through a separate Inventory Manager inventory count.</p>
                        </div>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/administrator/stock_issues.php' . ($filterQuery !== '' ? '?' . rtrim($filterQuery, '&') : ''))) ?>">Back to list</a>
                    </div>

                    <div class="adjustment-card <?= htmlspecialchars($report['status']) ?>">
                        <div class="adjustment-header">
                            <div>
                                <div class="adjustment-product"><?= htmlspecialchars($report['product_name']) ?></div>
                                <div class="adjustment-sku"><?= htmlspecialchars($report['sku']) ?></div>
                                <span class="adjustment-type <?= htmlspecialchars($report['adjustment_type']) ?>">
                                    <?= htmlspecialchars($categoryLabel((string)$report['adjustment_type'])) ?>
                                </span>
                            </div>
                            <div class="u-text-right">
                                <div class="adjustment-qty">-<?= abs((int)$report['adjustment_qty']) ?></div>
                                <span class="adjustment-status <?= htmlspecialchars($report['status']) ?>">
                                    <?= htmlspecialchars(ucfirst($report['status'])) ?>
                                </span>
                            </div>
                        </div>
                        <div class="adjustment-details">
                            <strong>Cashier:</strong> <?= htmlspecialchars($report['cashier_name']) ?> |
                            <strong>Shift:</strong> <?= !empty($report['shift_id']) ? '#' . (int)$report['shift_id'] : '—' ?> |
                            <strong>Reported:</strong> <?= htmlspecialchars($report['reported_at']) ?>
                            <?php if (!empty($report['approved_at'])): ?>
                                <br><strong>Decided by:</strong> <?= htmlspecialchars($report['reviewer_name'] ?? 'Unknown') ?> at <?= htmlspecialchars($report['approved_at']) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($report['reason'])): ?>
                            <div class="adjustment-reason">
                                <strong>Cashier explanation:</strong> <?= htmlspecialchars($report['reason']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($report['review_notes'])): ?>
                            <div class="adjustment-reason">
                                <strong>Decision reason:</strong> <?= htmlspecialchars($report['review_notes']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h4>Lifecycle revisions (<?= count($detail['revisions']) ?>)</h4>
                    <?php if (empty($detail['revisions'])): ?>
                        <p>No revisions recorded.</p>
                    <?php else: ?>
                        <?php foreach ($detail['revisions'] as $revision): ?>
                            <div class="adjustment-details">
                                <strong>#<?= (int)$revision['revision_id'] ?> <?= htmlspecialchars($revision['action']) ?></strong>
                                by <?= htmlspecialchars($revision['actor_name'] ?? ('User #' . (int)$revision['actor_id'])) ?>
                                at <?= htmlspecialchars($revision['created_at']) ?>
                                (<?= htmlspecialchars((string)($revision['old_status'] ?? '—')) ?> → <?= htmlspecialchars((string)($revision['new_status'] ?? '—')) ?>)
                                <?php if (!empty($revision['old_values']) || !empty($revision['new_values'])): ?>
                                    <br><small class="muted">
                                        old: <?= htmlspecialchars((string)($revision['old_values'] ?? '—')) ?><br>
                                        new: <?= htmlspecialchars((string)($revision['new_values'] ?? '—'))
                                    ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <h4>Linked stock movements</h4>
                    <?php if (empty($detail['stock_movements'])): ?>
                        <p>No stock movement is linked to this report (approvals record one; rejections and returns never change inventory).</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Movement</th>
                                        <th>Change</th>
                                        <th>Reason</th>
                                        <th>Recorded by</th>
                                        <th>Moved at</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detail['stock_movements'] as $movement): ?>
                                        <tr>
                                            <td>#<?= (int)$movement['movement_id'] ?></td>
                                            <td><?= (int)$movement['change_qty'] > 0 ? '+' . (int)$movement['change_qty'] : (int)$movement['change_qty'] ?></td>
                                            <td><?= htmlspecialchars($movement['reason']) ?></td>
                                            <td><?= htmlspecialchars($movement['moved_by_name'] ?? ('User #' . (int)$movement['moved_by'])) ?></td>
                                            <td><?= htmlspecialchars($movement['moved_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <h4>Correction inventory counts</h4>
                    <?php if (empty($detail['correction_counts'])): ?>
                        <p>No correction inventory count is linked to this report.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Count</th>
                                        <th>System</th>
                                        <th>Physical</th>
                                        <th>Difference</th>
                                        <th>Reason</th>
                                        <th>Counted by</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detail['correction_counts'] as $countRow): ?>
                                        <tr>
                                            <td>#<?= (int)$countRow['count_id'] ?></td>
                                            <td><?= (int)$countRow['system_quantity'] ?></td>
                                            <td><?= (int)$countRow['physical_quantity'] ?></td>
                                            <td><?= (int)$countRow['difference_qty'] > 0 ? '+' . (int)$countRow['difference_qty'] : (int)$countRow['difference_qty'] ?></td>
                                            <td><?= htmlspecialchars($countRow['discrepancy_reason']) ?></td>
                                            <td><?= htmlspecialchars($countRow['counted_by_name'] ?? 'Unknown') ?></td>
                                            <td><?= htmlspecialchars($countRow['status']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <p class="muted">Both records remain independently auditable: this report and each linked inventory count keep their own history.</p>
                </div>
            <?php endif; ?>

            <div class="history-section u-mt-2">
                <h3>Stock Issue reports (<?= count($reports) ?>)</h3>
                <?php if (empty($reports)): ?>
                    <p>No stock issue reports match the current filters.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Report</th>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Qty</th>
                                    <th>Status</th>
                                    <th>Cashier</th>
                                    <th>Approver</th>
                                    <th>Reported</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $row): ?>
                                    <tr>
                                        <td>#<?= (int)$row['adjustment_id'] ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($row['product_name']) ?></strong>
                                            <br><small class="muted"><?= htmlspecialchars($row['sku']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($categoryLabel((string)$row['adjustment_type'])) ?></td>
                                        <td>-<?= abs((int)$row['adjustment_qty']) ?></td>
                                        <td>
                                            <span class="adjustment-status <?= htmlspecialchars($row['status']) ?>">
                                                <?= htmlspecialchars(ucfirst($row['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($row['cashier_name']) ?></td>
                                        <td><?= htmlspecialchars($row['reviewer_name'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($row['reported_at']) ?></td>
                                        <td>
                                            <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/administrator/stock_issues.php?' . $filterQuery . 'detail=' . (int)$row['adjustment_id'])) ?>">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>
