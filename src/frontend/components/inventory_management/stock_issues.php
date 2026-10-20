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
            $stockIssueService->approveReport($adjustmentId, (int)$_SESSION['user_id'], trim((string)($_POST['review_notes'] ?? '')), $batchAllocations);
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
        } elseif ($action === 'return') {
            $newCategory = trim((string)($_POST['new_category'] ?? ''));
            $stockIssueService->returnReport(
                $adjustmentId,
                (int)$_SESSION['user_id'],
                trim((string)($_POST['review_notes'] ?? '')),
                $newCategory !== '' ? $newCategory : null
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
</head>

<body class="stock-issues-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <div class="topbar">
                <div>
                    <h1>Stock Issue Reviews</h1>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message success" role="status"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="history-section">
                <h2>Pending review <span class="issue-count"><?= (int)$pendingCount ?></span></h2>
                <?php if (empty($pending)): ?>
                    <div class="issue-empty">
                        <svg class="issue-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M9 5H6a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3" />
                            <rect x="9" y="2" width="6" height="5" rx="1" />
                            <path d="m8 14 3 3 5-6" />
                        </svg>
                        <h3>No reports to review</h3>
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
                                    <span class="issue-quantity-label">To deduct</span>
                                    <div class="adjustment-qty"><?= htmlspecialchars($formatIssueQty($report)) ?></div>
                                </div>
                            </div>
                            <div class="adjustment-details">
                                <span>Report #<?= (int)$report['adjustment_id'] ?></span>
                                <span><strong>Cashier:</strong> <?= htmlspecialchars($report['cashier_name']) ?></span>
                                <span><strong>Shift:</strong> #<?= (int)$report['shift_id'] ?></span>
                                <span><strong>Reported:</strong> <?= htmlspecialchars(format_display_datetime($report['reported_at'])) ?></span>
                            </div>
                            <div class="issue-stock">
                                <span><strong>Available stock:</strong> <?= (int)$report['quantity_on_hand'] ?> pcs<?= $hasPackConfig && !empty($productBatches) ? " ({$availSealedSum} sealed pk, {$availLooseSum} loose pcs)" : '' ?></span>
                                <?php if ($short): ?>
                                    <strong class="issue-warning">Not enough stock to approve. Reject or return this report.</strong>
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
                                    <label for="review-notes-<?= (int)$report['adjustment_id'] ?>">Review note</label>
                                    <small id="note-help-<?= (int)$report['adjustment_id'] ?>">Required to reject or return. Optional when approving.</small>
                                    <textarea name="review_notes" id="review-notes-<?= (int)$report['adjustment_id'] ?>" rows="2" aria-describedby="note-help-<?= (int)$report['adjustment_id'] ?>"></textarea>
                                </div>
                                <?php if ($report['adjustment_type'] === 'other'): ?>
                                    <div class="form-group">
                                        <label for="recat-<?= (int)$report['adjustment_id'] ?>">Category when returning (optional)</label>
                                        <select name="new_category" id="recat-<?= (int)$report['adjustment_id'] ?>" aria-describedby="category-help-<?= (int)$report['adjustment_id'] ?>">
                                            <option value="">Keep as Other</option>
                                            <option value="damaged">Damaged</option>
                                            <option value="missing">Missing / Lost</option>
                                            <option value="expired">Expired</option>
                                        </select>
                                        <small id="category-help-<?= (int)$report['adjustment_id'] ?>">Changes the category only when you return this report.</small>
                                    </div>
                                <?php endif; ?>
                                <?php
                                if (!empty($productBatches)):
                                    $fefoAllocations = $stockIssueService->recommendBatchAllocation((int)$report['product_id'], $repQty, $reportForm);
                                ?>
                                    <details class="issue-batches">
                                        <summary>Review batch deductions</summary>
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
                                                                <input type="number"
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
                                <div class="review-actions">
                                    <button type="submit" name="action" value="approve" class="btn-submit" <?= $short ? 'disabled title="Insufficient available stock"' : '' ?>>Approve &amp; deduct <?= abs((int)$report['adjustment_qty']) ?></button>
                                    <button type="submit" name="action" value="reject" class="btn btn-secondary">Reject</button>
                                    <button type="submit" name="action" value="return" class="btn btn-secondary">Return for correction</button>
                                </div>
                            </form>
                            <?php $pendingRevisions = $revisionsFor((int)$report['adjustment_id']); ?>
                            <?php if (!empty($pendingRevisions)): ?>
                                <details class="adjustment-reason">
                                    <summary>Revision history (<?= count($pendingRevisions) ?>)</summary>
                                    <?php foreach ($pendingRevisions as $revision): ?>
                                        <div>#<?= (int)$revision['revision_id'] ?> <?= htmlspecialchars($revision['action']) ?> by #<?= (int)$revision['actor_id'] ?> at <?= htmlspecialchars($revision['created_at']) ?> (<?= htmlspecialchars((string)($revision['old_status'] ?? '')) ?> → <?= htmlspecialchars((string)($revision['new_status'] ?? '')) ?>)</div>
                                    <?php endforeach; ?>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($returned)): ?>
                <details class="history-section issue-history u-mt-2">
                    <summary><h2>Returned for correction <span class="issue-count"><?= count($returned) ?></span></h2></summary>
                    <?php foreach ($returned as $report): ?>
                        <div class="adjustment-card returned">
                            <div class="adjustment-header">
                                <div>
                                    <div class="adjustment-product"><?= htmlspecialchars($report['product_name']) ?></div>
                                    <div class="adjustment-sku"><?= htmlspecialchars($report['sku']) ?></div>
                                    <span class="adjustment-type <?= htmlspecialchars($report['adjustment_type']) ?>">
                                        <?= htmlspecialchars(ucfirst($report['adjustment_type'])) ?>
                                    </span>
                                </div>
                                <div class="u-text-right">
                                    <span class="issue-quantity-label">Reported</span>
                                    <div class="adjustment-qty"><?= htmlspecialchars($formatIssueQty($report)) ?></div>
                                </div>
                            </div>
                            <div class="adjustment-details">
                                <span>Report #<?= (int)$report['adjustment_id'] ?></span>
                                <span><strong>Cashier:</strong> <?= htmlspecialchars($report['cashier_name']) ?></span>
                                <span><strong>Returned by:</strong> <?= htmlspecialchars($report['reviewer_name'] ?? 'Unknown') ?> at <?= htmlspecialchars(format_display_datetime($report['approved_at'])) ?></span>
                            </div>
                            <?php if (!empty($report['review_notes'])): ?>
                                <div class="adjustment-reason">
                                    <strong>Return reason:</strong> <?= htmlspecialchars($report['review_notes']) ?>
                                </div>
                            <?php endif; ?>
                            <?php $returnedRevisions = $revisionsFor((int)$report['adjustment_id']); ?>
                            <?php if (!empty($returnedRevisions)): ?>
                                <details class="adjustment-reason">
                                    <summary>Revision history (<?= count($returnedRevisions) ?>)</summary>
                                    <?php foreach ($returnedRevisions as $revision): ?>
                                        <div>#<?= (int)$revision['revision_id'] ?> <?= htmlspecialchars($revision['action']) ?> by #<?= (int)$revision['actor_id'] ?> at <?= htmlspecialchars($revision['created_at']) ?> (<?= htmlspecialchars((string)($revision['old_status'] ?? '')) ?> → <?= htmlspecialchars((string)($revision['new_status'] ?? '')) ?>)</div>
                                    <?php endforeach; ?>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>

            <?php if (!empty($reviews)): ?>
                <details class="history-section issue-history u-mt-2">
                    <summary><h2>Recent decisions <span class="issue-count"><?= count($reviews) ?></span></h2></summary>
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
                                    <span class="issue-quantity-label"><?= $review['status'] === 'approved' ? 'Deducted' : 'Reported' ?></span>
                                    <div class="adjustment-qty"><?= htmlspecialchars($formatIssueQty($review)) ?></div>
                                    <span class="adjustment-status <?= htmlspecialchars($review['status']) ?>">
                                        <?= htmlspecialchars(ucfirst($review['status'])) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="adjustment-details">
                                <span>Report #<?= (int)$review['adjustment_id'] ?></span>
                                <span><strong>Cashier:</strong> <?= htmlspecialchars($review['cashier_name']) ?></span>
                                <span><strong>Reviewed by:</strong> <?= htmlspecialchars($review['reviewer_name'] ?? 'Unknown') ?> at <?= htmlspecialchars(format_display_datetime($review['approved_at'])) ?></span>
                            </div>
                            <?php if ($review['status'] === 'approved'): ?>
                                <?php $reviewBatches = $stockIssueService->getReportBatches((int)$review['adjustment_id']); ?>
                                <?php if (!empty($reviewBatches)): ?>
                                    <div class="adjustment-reason">
                                        <strong>Deducted batches:</strong>
                                        <ul class="u-mt-1" style="margin-left: 1.25rem;">
                                            <?php foreach ($reviewBatches as $rb): ?>
                                                <li>Batch <?= htmlspecialchars($rb['batch_number'] ?? ('#' . $rb['batch_id'])) ?><?= !empty($rb['expiration_date']) ? ' (Exp: ' . htmlspecialchars($rb['expiration_date']) . ')' : '' ?>: <?= ($rb['form'] ?? 'piece') === 'pack' && (int)($rb['reported_qty'] ?? 0) > 0 ? '-' . (int)$rb['reported_qty'] . ' pack(s) (' . (int)$rb['quantity'] . ' pcs)' : '-' . (int)$rb['quantity'] . ' pc(s)' ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                <div class="adjustment-reason">
                                    <strong>Approved reports are locked.</strong> Correct mistakes with a linked inventory count.
                                    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/inventory_management/inventory_counts.php?correct=' . (int)$review['adjustment_id'])) ?>">Correct via inventory count</a>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($review['review_notes'])): ?>
                                <div class="adjustment-reason">
                                    <strong>Reviewer note:</strong> <?= htmlspecialchars($review['review_notes']) ?>
                                </div>
                            <?php endif; ?>
                            <?php $reviewRevisions = $revisionsFor((int)$review['adjustment_id']); ?>
                            <?php if (!empty($reviewRevisions)): ?>
                                <details class="adjustment-reason">
                                    <summary>Revision history (<?= count($reviewRevisions) ?>)</summary>
                                    <?php foreach ($reviewRevisions as $revision): ?>
                                        <div>#<?= (int)$revision['revision_id'] ?> <?= htmlspecialchars($revision['action']) ?> by #<?= (int)$revision['actor_id'] ?> at <?= htmlspecialchars($revision['created_at']) ?> (<?= htmlspecialchars((string)($revision['old_status'] ?? '')) ?> → <?= htmlspecialchars((string)($revision['new_status'] ?? '')) ?>)</div>
                                    <?php endforeach; ?>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        </main>
    </div>
</body>

</html>
