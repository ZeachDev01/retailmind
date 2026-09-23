<?php
// components/inventory_management/stock_issues.php — Stock Issue Reviews (ticket #29).
//
// Inventory Managers review pending cashier stock-issue reports. Rejection
// requires a reason and never changes inventory. Approval atomically re-checks
// available stock, refuses insufficient stock, deducts the full reported
// quantity, and records a linked stock movement. Administrators hold no
// mutation authority here: this page is inventory_manager-only.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/StockIssueService.php';
require_role(['inventory_manager']);

$stockIssueService = new StockIssueService($pdo);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';
    $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);

    try {
        if ($action === 'approve') {
            $stockIssueService->approveReport($adjustmentId, (int)$_SESSION['user_id'], trim((string)($_POST['review_notes'] ?? '')));
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
            $stockIssueService->rejectReport($adjustmentId, (int)$_SESSION['user_id'], trim((string)($_POST['review_notes'] ?? '')));
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
    } catch (Exception $e) {
        $error = 'Review failed: ' . $e->getMessage();
    }
}

$pending = $stockIssueService->getPendingReports();
$reviews = $stockIssueService->getRecentReviews();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Issue Reviews</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div>
                    <h1>Stock Issue Reviews</h1>
                    <p class="page-subtitle">Approve deductions or reject with a reason. Only approvals change inventory.</p>
                </div>
                <span class="badge-role"><?= count($pending) ?> Pending Review</span>
            </div>

            <?php if ($message): ?>
                <div class="message success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="history-section">
                <h3>Pending Reports</h3>
                <?php if (empty($pending)): ?>
                    <p>No pending stock issue reports. Cashier submissions will appear here.</p>
                <?php else: ?>
                    <?php foreach ($pending as $report): ?>
                        <?php $short = (int)$report['quantity_on_hand'] < abs((int)$report['adjustment_qty']); ?>
                        <div class="adjustment-card pending">
                            <div class="adjustment-header">
                                <div>
                                    <div class="adjustment-product"><?= htmlspecialchars($report['product_name']) ?></div>
                                    <div class="adjustment-sku"><?= htmlspecialchars($report['sku']) ?></div>
                                    <span class="adjustment-type <?= htmlspecialchars($report['adjustment_type']) ?>">
                                        <?= htmlspecialchars(ucfirst($report['adjustment_type'])) ?>
                                    </span>
                                </div>
                                <div class="u-text-right">
                                    <div class="adjustment-qty">-<?= abs((int)$report['adjustment_qty']) ?></div>
                                    <span class="adjustment-status pending">Pending</span>
                                </div>
                            </div>
                            <div class="adjustment-details">
                                <strong>Report ID:</strong> #<?= (int)$report['adjustment_id'] ?> |
                                <strong>Cashier:</strong> <?= htmlspecialchars($report['cashier_name']) ?> |
                                <strong>Shift:</strong> #<?= (int)$report['shift_id'] ?> |
                                <strong>Reported:</strong> <?= htmlspecialchars($report['reported_at']) ?>
                                <br><strong>Available stock:</strong> <?= (int)$report['quantity_on_hand'] ?>
                                <?php if ($short): ?>
                                    <strong class="adjustment-status rejected">Insufficient stock — approval will be refused</strong>
                                <?php endif; ?>
                            </div>
                            <?php if ($report['reason']): ?>
                                <div class="adjustment-reason">
                                    <strong>Cashier explanation:</strong> <?= htmlspecialchars($report['reason']) ?>
                                </div>
                            <?php endif; ?>
                            <form method="POST" class="review-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="adjustment_id" value="<?= (int)$report['adjustment_id'] ?>">
                                <div class="form-group">
                                    <label for="review-notes-<?= (int)$report['adjustment_id'] ?>">Reviewer note (required to reject)</label>
                                    <textarea name="review_notes" id="review-notes-<?= (int)$report['adjustment_id'] ?>" placeholder="Reason for rejection, or an optional approval note..."></textarea>
                                </div>
                                <div class="review-actions">
                                    <button type="submit" name="action" value="approve" class="btn-submit" <?= $short ? 'disabled title="Insufficient available stock"' : '' ?>>Approve &amp; Deduct</button>
                                    <button type="submit" name="action" value="reject" class="btn btn-secondary">Reject</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($reviews)): ?>
                <div class="history-section u-mt-2">
                    <h3>Recent Decisions</h3>
                    <?php foreach ($reviews as $review): ?>
                        <div class="adjustment-card <?= htmlspecialchars($review['status']) ?>">
                            <div class="adjustment-header">
                                <div>
                                    <div class="adjustment-product"><?= htmlspecialchars($review['product_name']) ?></div>
                                    <div class="adjustment-sku"><?= htmlspecialchars($review['sku']) ?></div>
                                    <span class="adjustment-type <?= htmlspecialchars($review['adjustment_type']) ?>">
                                        <?= htmlspecialchars(ucfirst($review['adjustment_type'])) ?>
                                    </span>
                                </div>
                                <div class="u-text-right">
                                    <div class="adjustment-qty">-<?= abs((int)$review['adjustment_qty']) ?></div>
                                    <span class="adjustment-status <?= htmlspecialchars($review['status']) ?>">
                                        <?= htmlspecialchars(ucfirst($review['status'])) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="adjustment-details">
                                <strong>Report ID:</strong> #<?= (int)$review['adjustment_id'] ?> |
                                <strong>Cashier:</strong> <?= htmlspecialchars($review['cashier_name']) ?> |
                                <strong>Reviewed by:</strong> <?= htmlspecialchars($review['reviewer_name'] ?? 'Unknown') ?> at <?= htmlspecialchars(format_display_datetime($review['approved_at'])) ?>
                            </div>
                            <?php if (!empty($review['review_notes'])): ?>
                                <div class="adjustment-reason">
                                    <strong>Reviewer note:</strong> <?= htmlspecialchars($review['review_notes']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
