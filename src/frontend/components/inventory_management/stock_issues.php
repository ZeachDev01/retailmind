<?php
// components/inventory_management/stock_issues.php — Stock Issue Reviews (tickets #29 + #30).
//
// Inventory Managers review pending cashier stock-issue reports. Rejection
// requires a reason and never changes inventory. Approval atomically re-checks
// available stock, refuses insufficient stock, deducts the full reported
// quantity, and records a linked stock movement. A pending report may be
// returned with a mandatory reason (making it editable by its cashier); an
// Other report may be recategorized while returning, but the reported quantity
// and explanation are never rewritten here. Administrators hold no mutation
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_capability(\App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';
    $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);

    try {
        if ($action === 'approve') {
            $batchAllocations = isset($_POST['batches']) && is_array($_POST['batches']) ? $_POST['batches'] : null;
            $stockIssueService->approveReport($adjustmentId, (int)$_SESSION['user_id'], trim((string)($_POST['review_notes'] ?? '')), $batchAllocations, (string)($_POST['operation_token'] ?? ''));
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
        } elseif ($action === 'return') {
            $newCategory = trim((string)($_POST['new_category'] ?? ''));
            $stockIssueService->returnReport(
                $adjustmentId,
                (int)$_SESSION['user_id'],
                trim((string)($_POST['review_notes'] ?? '')),
                $newCategory !== '' ? $newCategory : null,
                (string)($_POST['operation_token'] ?? '')
            );
            $message = "Report #{$adjustmentId} returned to the cashier for correction. The cashier was notified.";
            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                'Stock issue returned',
                'Inventory Adjustments',
                $adjustmentId,
                ['status' => 'pending'],
                ['status' => 'returned']
            );
        } else {
            throw new RuntimeException('Invalid review action.');
        }
    } catch (Throwable $e) {
        $error = \App\Support\OperatorAlert::message($e, 'The review could not be saved. Refresh the page and try again. Tell your Administrator if this keeps happening.');
    }
}

$formatIssueQty = [StockIssueService::class, 'formatIssueQuantity'];
$pending = $stockIssueService->getPendingReports();
$pendingCount = $stockIssueService->getPendingCount();
$returned = $stockIssueService->getReturnedReports();
$reviews = $stockIssueService->getRecentReviews();
$revisionCache = [];
$revisionsFor = static function (int $adjustmentId) use ($stockIssueService, &$revisionCache): array {
    if (!array_key_exists($adjustmentId, $revisionCache)) {
        try {
            $revisionCache[$adjustmentId] = $stockIssueService->getRevisions($adjustmentId);
        } catch (Throwable $e) {
            $revisionCache[$adjustmentId] = [];
        }
    }
    return $revisionCache[$adjustmentId];
};
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Issue Reviews</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/stock-issues.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/stock-issues.css')) ?>">
<?php require __DIR__ . '/../../../backend/includes/inventory_drafts.php'; ?>
</head>

<body class="stock-issues-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <div class="topbar">
                <div>
                    <h1>Stock Issue Reviews</h1>
                    <p class="page-subheading">Review cashier incident reports (damaged, expired, lost, or other stock issues). Approve to deduct inventory, or return with guidance.</p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message success" role="status"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Overview KPI Cards -->
            <div class="stock-kpi-grid">
                <div class="stock-kpi-card <?= $pendingCount > 0 ? 'stock-kpi-card--warning' : '' ?>">
                    <span class="stock-kpi-label">Pending Review</span>
                    <span class="stock-kpi-value"><?= (int)$pendingCount ?></span>
                    <span class="stock-kpi-meta"><?= $pendingCount > 0 ? 'Requires immediate review' : 'All caught up' ?></span>
                </div>
                <div class="stock-kpi-card">
                    <span class="stock-kpi-label">Returned to Cashier</span>
                    <span class="stock-kpi-value"><?= count($returned) ?></span>
                    <span class="stock-kpi-meta">Awaiting cashier corrections</span>
                </div>
                <div class="stock-kpi-card stock-kpi-card--success">
                    <span class="stock-kpi-label">Recent Decisions</span>
                    <span class="stock-kpi-value"><?= count($reviews) ?></span>
                    <span class="stock-kpi-meta">Approved, rejected, or completed</span>
                </div>
            </div>

            <!-- Quick Navigation Pills -->
            <nav class="stock-status-pills" aria-label="Review queues">
                <a href="#pending-section" class="stock-status-pill is-active">
                    <span>Pending Review</span>
                    <span class="pill-badge"><?= (int)$pendingCount ?></span>
                </a>
                <?php if (!empty($returned)): ?>
                    <a href="#returned-section" class="stock-status-pill">
                        <span>Returned to Cashier</span>
                        <span class="pill-badge"><?= count($returned) ?></span>
                    </a>
                <?php endif; ?>
                <?php if (!empty($reviews)): ?>
                    <a href="#reviews-section" class="stock-status-pill">
                        <span>Recent Decisions</span>
                        <span class="pill-badge"><?= count($reviews) ?></span>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="history-section" id="pending-section">
                <h2>Pending review <span class="issue-count"><?= (int)$pendingCount ?></span></h2>
                <?php if (empty($pending)): ?>
                    <div class="issue-empty">
                        <svg class="issue-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M9 5H6a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3" />
                            <rect x="9" y="2" width="6" height="5" rx="1" />
                            <path d="m8 14 3 3 5-6" />
                        </svg>
                        <h3>No reports waiting for review</h3>
                        <p>All cashier stock issue reports have been reviewed. New submissions will appear here automatically.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending as $report): ?>
                        <?php
                        $productBatches = $stockIssueService->getProductBatches((int)$report['product_id']);
                        $reportForm = $report['form'] ?? 'piece';
                        $repQty = (int)($report['reported_qty'] ?? 0) ?: abs((int)$report['adjustment_qty']);
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
                        <div class="adjustment-card pending" id="report-<?= (int)$report['adjustment_id'] ?>">
                            <!-- Topbar: Badges & Timestamp -->
                            <div class="adjustment-card-topbar">
                                <div class="adjustment-badges">
                                    <span class="report-id-badge">Report #<?= (int)$report['adjustment_id'] ?></span>
                                    <span class="category-tag <?= htmlspecialchars($report['adjustment_type']) ?>">
                                        <?= htmlspecialchars(ucfirst($report['adjustment_type'])) ?>
                                    </span>
                                    <span class="adjustment-status pending">Pending Review</span>
                                </div>
                                <div class="adjustment-time">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <span><?= htmlspecialchars(format_display_datetime($report['reported_at'])) ?></span>
                                </div>
                            </div>

                            <!-- Product Info -->
                            <div class="adjustment-product-info">
                                <div class="adjustment-product-name"><?= htmlspecialchars($report['product_name']) ?></div>
                                <span class="sku-code">SKU: <?= htmlspecialchars($report['sku']) ?></span>
                            </div>

                            <!-- Metrics Comparison Grid -->
                            <div class="detail-hero-grid">
                                <div class="detail-metric-card">
                                    <span class="detail-metric-label">Quantity to Deduct</span>
                                    <span class="detail-metric-value cell-qty"><?= htmlspecialchars($formatIssueQty($report)) ?></span>
                                    <small class="muted">Form: <?= htmlspecialchars(ucfirst($reportForm)) ?></small>
                                </div>
                                <div class="detail-metric-card">
                                    <span class="detail-metric-label">Available in Stock</span>
                                    <span class="detail-metric-value"><?= (int)$report['quantity_on_hand'] ?> pcs</span>
                                    <?php if ($hasPackConfig && !empty($productBatches)): ?>
                                        <small class="muted"><?= $availSealedSum ?> sealed pk, <?= $availLooseSum ?> loose pcs</small>
                                    <?php endif; ?>
                                    <?php if ($short): ?>
                                        <div class="issue-warning">⚠ Insufficient stock to approve</div>
                                    <?php else: ?>
                                        <div class="issue-ok">✓ Sufficient stock available</div>
                                    <?php endif; ?>
                                </div>
                                <div class="detail-metric-card">
                                    <span class="detail-metric-label">Reported By</span>
                                    <span class="detail-metric-value"><?= htmlspecialchars($report['cashier_name']) ?></span>
                                    <small class="muted">Cashier Shift #<?= (int)$report['shift_id'] ?></small>
                                </div>
                            </div>

                            <!-- Cashier Explanation -->
                            <?php if (!empty($report['reason'])): ?>
                                <div class="detail-reason-box">
                                    <div class="reason-box-header">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                        <span>Cashier Explanation</span>
                                    </div>
                                    <p class="reason-box-text"><?= htmlspecialchars($report['reason']) ?></p>
                                </div>
                            <?php endif; ?>

                            <!-- Batch Deductions if batches exist -->
                            <form method="POST" class="review-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="adjustment_id" value="<?= (int)$report['adjustment_id'] ?>">
                                
                                <?php
                                if (!empty($productBatches)):
                                    $fefoAllocations = $stockIssueService->recommendBatchAllocation((int)$report['product_id'], $repQty, $reportForm);
                                ?>
                                    <details class="issue-batches">
                                        <summary><strong>Review batch deductions (FEFO)</strong></summary>
                                        <p>Earliest expiry first. Quantities must total <?= $repQty ?> <?= $reportForm === 'pack' ? 'sealed packs' : 'loose pieces' ?> to approve.</p>
                                        <div class="table-wrap u-mt-1">
                                            <table>
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
                                                                       value="<?= (int)($fefoAllocations[$pb['batch_id']] ?? 0) ?>"
                                                                       min="0"
                                                                       max="<?= $maxDeduct ?>"
                                                                       aria-label="<?= htmlspecialchars('Deduct from batch ' . ($pb['batch_number'] ?? ('#' . $pb['batch_id']))) ?>"
                                                                       class="form-control">
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </details>
                                <?php endif; ?>

                                <!-- Review Notes & Form Inputs -->
                                <div class="form-group">
                                    <label for="review-notes-<?= (int)$report['adjustment_id'] ?>">Reviewer note</label>
                                    <small id="note-help-<?= (int)$report['adjustment_id'] ?>">Optional when approving. <strong>Required to reject or return.</strong></small>
                                    <textarea name="review_notes" id="review-notes-<?= (int)$report['adjustment_id'] ?>" rows="2" placeholder="Write feedback or guidance for the cashier…" aria-describedby="note-help-<?= (int)$report['adjustment_id'] ?>"></textarea>
                                </div>
                                <?php if ($report['adjustment_type'] === 'other'): ?>
                                    <div class="form-group">
                                        <label for="recat-<?= (int)$report['adjustment_id'] ?>">Recategorize when returning (optional)</label>
                                        <select name="new_category" id="recat-<?= (int)$report['adjustment_id'] ?>" aria-describedby="category-help-<?= (int)$report['adjustment_id'] ?>">
                                            <option value="">Keep as Other</option>
                                            <option value="damaged">Damaged</option>
                                            <option value="missing">Missing / Lost</option>
                                            <option value="expired">Expired</option>
                                        </select>
                                        <small id="category-help-<?= (int)$report['adjustment_id'] ?>">Allows you to assign a specific category when sending back to the cashier.</small>
                                    </div>
                                <?php endif; ?>

                                <div class="review-actions">
                                    <button type="submit" name="action" value="approve" class="btn btn-action-approve" <?= $short ? 'disabled title="Insufficient available stock"' : '' ?>>
                                        ✓ Approve &amp; deduct <?= abs((int)$report['adjustment_qty']) ?>
                                    </button>
                                    <button type="submit" name="action" value="return" class="btn btn-action-return">
                                        ↩ Return for correction
                                    </button>
                                    <button type="submit" name="action" value="reject" class="btn btn-action-reject">
                                        ✕ Reject
                                    </button>
                                </div>
                                <div class="action-hints">
                                    <span><strong>Approve:</strong> Confirms the issue and permanently deducts <?= abs((int)$report['adjustment_qty']) ?> item(s) from inventory.</span>
                                    <span><strong>Return:</strong> Sends report back to cashier to correct quantity or explanation.</span>
                                    <span><strong>Reject:</strong> Declines report permanently. Stock remains completely unchanged.</span>
                                </div>
                            </form>

                            <!-- Revision Timeline -->
                            <?php $pendingRevisions = $revisionsFor((int)$report['adjustment_id']); ?>
                            <?php if (!empty($pendingRevisions)): ?>
                                <details class="revision-history-accordion">
                                    <summary><strong>Activity Trail</strong> (<?= count($pendingRevisions) ?> <?= count($pendingRevisions) === 1 ? 'event' : 'events' ?>)</summary>
                                    <div class="revision-timeline">
                                        <?php foreach ($pendingRevisions as $revision): ?>
                                            <div class="timeline-entry">
                                                <div class="timeline-dot"></div>
                                                <div class="timeline-content">
                                                    <div class="timeline-title">
                                                        <strong><?= htmlspecialchars(ucfirst($revision['action'])) ?></strong>
                                                        <span>by <?= htmlspecialchars($revision['actor_name'] ?: ('User #' . $revision['actor_id'])) ?></span>
                                                    </div>
                                                    <div class="timeline-meta">
                                                        <span><?= htmlspecialchars(format_display_datetime($revision['created_at'])) ?></span>
                                                        <?php if (!empty($revision['old_status']) || !empty($revision['new_status'])): ?>
                                                            <span class="timeline-transition">
                                                                <span class="adjustment-status <?= htmlspecialchars((string)$revision['old_status']) ?>"><?= htmlspecialchars(ucfirst((string)$revision['old_status'])) ?></span>
                                                                →
                                                                <span class="adjustment-status <?= htmlspecialchars((string)$revision['new_status']) ?>"><?= htmlspecialchars(ucfirst((string)$revision['new_status'])) ?></span>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($returned)): ?>
                <div class="history-section issue-history u-mt-2" id="returned-section">
                    <details open>
                        <summary><h2>Returned for correction <span class="issue-count"><?= count($returned) ?></span></h2></summary>
                        <?php foreach ($returned as $report): ?>
                            <div class="adjustment-card returned">
                                <div class="adjustment-card-topbar">
                                    <div class="adjustment-badges">
                                        <span class="report-id-badge">Report #<?= (int)$report['adjustment_id'] ?></span>
                                        <span class="category-tag <?= htmlspecialchars($report['adjustment_type']) ?>">
                                            <?= htmlspecialchars(ucfirst($report['adjustment_type'])) ?>
                                        </span>
                                        <span class="adjustment-status returned">Returned to Cashier</span>
                                    </div>
                                    <div class="adjustment-time">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        <span><?= htmlspecialchars(format_display_datetime($report['reported_at'])) ?></span>
                                    </div>
                                </div>

                                <div class="adjustment-product-info">
                                    <div class="adjustment-product-name"><?= htmlspecialchars($report['product_name']) ?></div>
                                    <span class="sku-code">SKU: <?= htmlspecialchars($report['sku']) ?></span>
                                </div>

                                <div class="detail-hero-grid">
                                    <div class="detail-metric-card">
                                        <span class="detail-metric-label">Reported Quantity</span>
                                        <span class="detail-metric-value cell-qty"><?= htmlspecialchars($formatIssueQty($report)) ?></span>
                                    </div>
                                    <div class="detail-metric-card">
                                        <span class="detail-metric-label">Cashier</span>
                                        <span class="detail-metric-value"><?= htmlspecialchars($report['cashier_name']) ?></span>
                                    </div>
                                    <div class="detail-metric-card">
                                        <span class="detail-metric-label">Returned By</span>
                                        <span class="detail-metric-value"><?= htmlspecialchars($report['reviewer_name'] ?? 'Unknown') ?></span>
                                        <small class="muted"><?= htmlspecialchars(format_display_datetime($report['approved_at'])) ?></small>
                                    </div>
                                </div>

                                <?php if (!empty($report['review_notes'])): ?>
                                    <div class="detail-reason-box detail-reason-box--decision">
                                        <div class="reason-box-header">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                            <span>Manager Return Guidance</span>
                                        </div>
                                        <p class="reason-box-text"><?= htmlspecialchars($report['review_notes']) ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php $returnedRevisions = $revisionsFor((int)$report['adjustment_id']); ?>
                                <?php if (!empty($returnedRevisions)): ?>
                                    <details class="revision-history-accordion">
                                        <summary><strong>Activity Trail</strong> (<?= count($returnedRevisions) ?> <?= count($returnedRevisions) === 1 ? 'event' : 'events' ?>)</summary>
                                        <div class="revision-timeline">
                                            <?php foreach ($returnedRevisions as $revision): ?>
                                                <div class="timeline-entry">
                                                    <div class="timeline-dot"></div>
                                                    <div class="timeline-content">
                                                        <div class="timeline-title">
                                                            <strong><?= htmlspecialchars(ucfirst($revision['action'])) ?></strong>
                                                            <span>by <?= htmlspecialchars($revision['actor_name'] ?: ('User #' . $revision['actor_id'])) ?></span>
                                                        </div>
                                                        <div class="timeline-meta">
                                                            <span><?= htmlspecialchars(format_display_datetime($revision['created_at'])) ?></span>
                                                            <?php if (!empty($revision['old_status']) || !empty($revision['new_status'])): ?>
                                                                <span class="timeline-transition">
                                                                    <span class="adjustment-status <?= htmlspecialchars((string)$revision['old_status']) ?>"><?= htmlspecialchars(ucfirst((string)$revision['old_status'])) ?></span>
                                                                    →
                                                                    <span class="adjustment-status <?= htmlspecialchars((string)$revision['new_status']) ?>"><?= htmlspecialchars(ucfirst((string)$revision['new_status'])) ?></span>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </details>
                </div>
            <?php endif; ?>

            <?php if (!empty($reviews)): ?>
                <div class="history-section issue-history u-mt-2" id="reviews-section">
                    <details open>
                        <summary><h2>Recent decisions <span class="issue-count"><?= count($reviews) ?></span></h2></summary>
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
                                    </div>
                                    <div class="detail-metric-card">
                                        <span class="detail-metric-label">Cashier</span>
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
                                        <span><strong>Approved reports are locked.</strong> Need to rectify a mistake? Correct it with a linked inventory count.</span>
                                        <div class="u-mt-1">
                                            <a class="btn btn-secondary btn-small" href="<?= htmlspecialchars(app_url('components/inventory_management/inventory_counts.php?correct=' . (int)$review['adjustment_id'])) ?>">Correct via inventory count</a>
                                        </div>
                                    </div>
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

                                <?php $reviewRevisions = $revisionsFor((int)$review['adjustment_id']); ?>
                                <?php if (!empty($reviewRevisions)): ?>
                                    <details class="revision-history-accordion">
                                        <summary><strong>Activity Trail</strong> (<?= count($reviewRevisions) ?> <?= count($reviewRevisions) === 1 ? 'event' : 'events' ?>)</summary>
                                        <div class="revision-timeline">
                                            <?php foreach ($reviewRevisions as $revision): ?>
                                                <div class="timeline-entry">
                                                    <div class="timeline-dot"></div>
                                                    <div class="timeline-content">
                                                        <div class="timeline-title">
                                                            <strong><?= htmlspecialchars(ucfirst($revision['action'])) ?></strong>
                                                            <span>by <?= htmlspecialchars($revision['actor_name'] ?: ('User #' . $revision['actor_id'])) ?></span>
                                                        </div>
                                                        <div class="timeline-meta">
                                                            <span><?= htmlspecialchars(format_display_datetime($revision['created_at'])) ?></span>
                                                            <?php if (!empty($revision['old_status']) || !empty($revision['new_status'])): ?>
                                                                <span class="timeline-transition">
                                                                    <span class="adjustment-status <?= htmlspecialchars((string)$revision['old_status']) ?>"><?= htmlspecialchars(ucfirst((string)$revision['old_status'])) ?></span>
                                                                    →
                                                                    <span class="adjustment-status <?= htmlspecialchars((string)$revision['new_status']) ?>"><?= htmlspecialchars(ucfirst((string)$revision['new_status'])) ?></span>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </details>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>

</html>
