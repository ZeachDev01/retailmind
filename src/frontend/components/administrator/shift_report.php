<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\CashierShiftService;
use App\Services\StoreShiftReportService;
use App\Support\OperatorAlert;

require_capability(RoleCapabilityPolicy::STORE_OPERATIONS);

$input = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$filters = [
    'from' => (string)($input['from'] ?? date('Y-m-01')),
    'to' => (string)($input['to'] ?? date('Y-m-d')),
    'cashier_id' => (string)($input['cashier_id'] ?? ''),
    'register_id' => (string)($input['register_id'] ?? ''),
    'shift_id' => (string)($input['shift_id'] ?? ''),
];

$service = new StoreShiftReportService($pdo, role_capability_policy());
$shiftService = new CashierShiftService($pdo);
$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'export_csv') {
            require_local_request('Bulk exports are not available during remote monitoring.');
            $report = $service->report((string)current_role(), $filters);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="cashier_shifts_' . date('Ymd_His') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            StoreShiftReportService::csv(fopen('php://output', 'w'), $report);
            exit;
        } elseif ($action === 'save_draft') {
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $draftNotes = (string)($_POST['draft_notes'] ?? '');
            $shiftService->saveReviewDraft($shiftId, (int)$_SESSION['user_id'], (string)current_role(), $draftNotes);
            $success = 'Draft investigation notes saved.';
        } elseif ($action === 'mark_reviewed') {
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $explanation = (string)($_POST['explanation'] ?? '');
            $shortage = isset($_POST['remaining_shortage']) && $_POST['remaining_shortage'] !== '' ? (float)$_POST['remaining_shortage'] : null;
            $shiftService->markReviewed($shiftId, (int)$_SESSION['user_id'], (string)current_role(), $explanation, $shortage);
            $success = 'Cashier Shift variance marked as reviewed.';
        } elseif ($action === 'reopen_review') {
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $reason = (string)($_POST['reopen_reason'] ?? '');
            $shiftService->reopenReview($shiftId, (int)$_SESSION['user_id'], (string)current_role(), $reason);
            $success = 'Cashier Shift review reopened. Attention restored.';
        }
    } catch (Throwable $exception) {
        $error = OperatorAlert::message($exception, 'Action failed. Check your inputs or try again.');
    }
}

try {
    $report = $service->report((string)current_role(), $filters);
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
    $report = ['headers' => StoreShiftReportService::HEADERS, 'rows' => [], 'totals' => []];
} catch (Throwable $exception) {
    $error = OperatorAlert::message($exception, 'The Cashier Shift report could not be loaded. Try again or tell your Administrator.');
    $report = ['headers' => StoreShiftReportService::HEADERS, 'rows' => [], 'totals' => []];
}

$targetedShift = null;
if ($filters['shift_id'] !== '' && $filters['shift_id'] !== 'legacy') {
    $targetId = (int)$filters['shift_id'];
    foreach ($report['rows'] as $r) {
        if (($r['Shift ID'] ?? 0) === $targetId) {
            $targetedShift = $r;
            break;
        }
    }
    if ($targetedShift === null) {
        try {
            $calc = $shiftService->calculateShift($targetId);
            $varianceVal = (float)($calc['cash_variance'] ?? 0);
            $varianceExplanation = $varianceVal < 0 ? 'Shortage: PHP ' . number_format(abs($varianceVal), 2) . ' short' : ($varianceVal > 0 ? 'Overage: PHP ' . number_format($varianceVal, 2) . ' extra' : 'Balanced');
            $targetedShift = [
                'Shift ID' => $targetId,
                'Cashier' => $calc['full_name'] ?? StoreShiftReportService::UNASSIGNED,
                'Cashier ID' => $calc['cashier_id'] ?? '',
                'Register' => $calc['register_name'] ?? StoreShiftReportService::UNASSIGNED,
                'Register ID' => $calc['register_id'] ?? '',
                'Opened At' => $calc['opened_at'] ?? '',
                'Closed At' => $calc['closed_at'] ?? '',
                'Expected Cash' => number_format((float)($calc['expected_cash'] ?? $calc['calculated_expected_cash']), 2, '.', ''),
                'Counted Cash' => $calc['actual_cash'] !== null ? number_format((float)$calc['actual_cash'], 2, '.', '') : '',
                'Variance' => $calc['cash_variance'] !== null ? number_format((float)$calc['cash_variance'], 2, '.', '') : '',
                'Variance Explanation' => $varianceExplanation,
                'Material Variance' => !empty($calc['variance_review_required']) ? 'Yes' : 'No',
                'Closing Notes' => $calc['closing_notes'] ?? '',
                'review_draft_notes' => $calc['review_draft_notes'] ?? null,
                'review_notes' => $calc['review_notes'] ?? null,
                'review_shortage' => $calc['review_shortage'] ?? null,
                'review_history' => $calc['review_history'] ?? null,
                'reviewed_by' => $calc['reviewed_by'] ?? null,
                'reviewed_at' => $calc['reviewed_at'] ?? null,
                'variance_threshold' => $calc['variance_threshold'] ?? null,
                'variance_review_required' => $calc['variance_review_required'] ?? null,
            ];
        } catch (Throwable) {}
    }
}

$cashiers = $pdo->query('SELECT u.user_id, u.full_name FROM users u WHERE EXISTS (SELECT 1 FROM cashier_shifts cs WHERE cs.cashier_id=u.user_id) OR EXISTS (SELECT 1 FROM sales s WHERE s.cashier_id=u.user_id) OR EXISTS (SELECT 1 FROM sale_reversals sr WHERE sr.requested_by=u.user_id) ORDER BY u.full_name, u.user_id')->fetchAll(PDO::FETCH_ASSOC);
$registers = $pdo->query('SELECT register_id, name FROM registers ORDER BY name, register_id')->fetchAll(PDO::FETCH_ASSOC);
$shifts = $pdo->query('SELECT shift_id, opened_at FROM cashier_shifts ORDER BY shift_id DESC')->fetchAll(PDO::FETCH_ASSOC);
function shift_report_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cashier Shift Report</title>
    <link rel="stylesheet" href="<?= shift_report_e(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= shift_report_e(app_url('assets/css/reports.css')) ?>">
</head>
<body><div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<main class="main-content">
    <header class="page-heading"><h1>Cashier Shift Report</h1>
        <p class="page-subtitle">Review Store-wide drawer activity. Dates use each shift's opening date; unassigned sales use their sale date.</p></header>
    <?php if ($error !== ''): ?><div class="alert tag-warning"><?= shift_report_e($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert tag-success"><?= shift_report_e($success) ?></div><?php endif; ?>

    <?php if ($targetedShift !== null): ?>
        <?php
            $isReviewed = !empty($targetedShift['reviewed_at']);
            $needsReview = !empty($targetedShift['variance_review_required']) && !$isReviewed;
            $history = json_decode((string)($targetedShift['review_history'] ?? '[]'), true) ?: [];
        ?>
        <section class="dashboard-section shift-investigation-card u-mb-15">
            <div class="section-header">
                <div>
                    <h2>Shift #<?= (int)$targetedShift['Shift ID'] ?> Investigation &amp; Variance Review</h2>
                    <p>Accountable supervisor review for drawer cash variances.</p>
                </div>
                <div>
                    <?php if ($isReviewed): ?>
                        <span class="badge badge-success">Reviewed</span>
                    <?php elseif ($needsReview): ?>
                        <span class="badge badge-warning">Needs review</span>
                    <?php else: ?>
                        <span class="badge badge-neutral">No review required</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-grid u-mt-10">
                <div class="stat-card">
                    <div class="label">Cashier</div>
                    <div class="value-sub"><?= shift_report_e($targetedShift['Cashier']) ?> (#<?= shift_report_e($targetedShift['Cashier ID']) ?>)</div>
                </div>
                <div class="stat-card">
                    <div class="label">Register</div>
                    <div class="value-sub"><?= shift_report_e($targetedShift['Register']) ?> (#<?= shift_report_e($targetedShift['Register ID']) ?>)</div>
                </div>
                <div class="stat-card">
                    <div class="label">Shift Timing</div>
                    <div class="value-sub">Opened: <?= shift_report_e($targetedShift['Opened At']) ?><br>Closed: <?= shift_report_e($targetedShift['Closed At']) ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Cash Reconciliation</div>
                    <div class="value-sub">
                        Expected: ₱<?= shift_report_e($targetedShift['Expected Cash']) ?><br>
                        Counted: <?= $targetedShift['Counted Cash'] !== '' ? '₱' . shift_report_e($targetedShift['Counted Cash']) : 'None' ?><br>
                        <strong>Difference: ₱<?= shift_report_e($targetedShift['Variance']) ?></strong>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="label">Variance Explanation</div>
                    <div class="value-sub"><strong><?= shift_report_e($targetedShift['Variance Explanation']) ?></strong></div>
                    <?php if (!empty($targetedShift['variance_threshold'])): ?><div class="hint">Store limit at closure: ₱<?= shift_report_e(number_format((float)$targetedShift['variance_threshold'], 2)) ?></div><?php endif; ?>
                </div>
                <div class="stat-card">
                    <div class="label">Closing Notes</div>
                    <div class="value-sub"><?= shift_report_e($targetedShift['Closing Notes'] !== '' ? $targetedShift['Closing Notes'] : 'None provided') ?></div>
                </div>
            </div>

            <?php if (!$isReviewed): ?>
                <div class="investigation-forms u-mt-15" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
                    <form method="post" class="card" style="padding: 1rem; border: 1px solid var(--border); border-radius: 8px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_draft">
                        <input type="hidden" name="shift_id" value="<?= (int)$targetedShift['Shift ID'] ?>">
                        <h3>Investigation Draft Notes</h3>
                        <p class="hint">Save investigation progress without completing or clearing pending attention.</p>
                        <div class="form-group u-mt-10">
                            <textarea name="draft_notes" rows="4" style="width: 100%;" placeholder="e.g. CCTV reviewed, float envelopes verified..."><?= shift_report_e($targetedShift['review_draft_notes'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-secondary u-mt-10">Save Draft Notes</button>
                    </form>

                    <form method="post" class="card" style="padding: 1rem; border: 1px solid var(--border); border-radius: 8px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_reviewed">
                        <input type="hidden" name="shift_id" value="<?= (int)$targetedShift['Shift ID'] ?>">
                        <h3>Complete Review</h3>
                        <p class="hint">Finished investigation conclusion and final explanation.</p>
                        <div class="form-group u-mt-10">
                            <label for="explanation">Explanation (Required)</label>
                            <textarea id="explanation" name="explanation" rows="3" style="width: 100%;" required placeholder="Explain findings and cause of difference..."></textarea>
                        </div>
                        <div class="form-group u-mt-10">
                            <label for="remaining_shortage">Explicit Remaining Shortage (PHP)</label>
                            <input id="remaining_shortage" name="remaining_shortage" type="number" step="0.01" min="0" value="<?= shift_report_e($targetedShift['review_shortage'] ?? (floatval($targetedShift['Variance']) < 0 ? abs(floatval($targetedShift['Variance'])) : 0)) ?>">
                        </div>
                        <p class="hint" style="font-size: 0.8rem; color: var(--muted); margin-top: 0.5rem;">
                            <em>Notice: Marking reviewed explicitly records remaining shortage. It implies neither recovery, debt assignment nor wage-deduction permission.</em>
                        </p>
                        <button type="submit" class="btn btn-primary u-mt-10">Mark Reviewed</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="reviewed-summary card u-mt-15" style="padding: 1rem; border: 1px solid var(--border); border-radius: 8px; background: var(--theme-surface-soft, #f8fafc);">
                    <h3>Review Completed</h3>
                    <p><strong>Reviewed at:</strong> <?= shift_report_e($targetedShift['reviewed_at']) ?> by user #<?= shift_report_e($targetedShift['reviewed_by']) ?></p>
                    <p><strong>Explanation:</strong> <?= shift_report_e($targetedShift['review_notes']) ?></p>
                    <p><strong>Remaining Shortage:</strong> ₱<?= shift_report_e(number_format((float)($targetedShift['review_shortage'] ?? 0), 2)) ?></p>
                    <p class="hint" style="font-size: 0.8rem; color: var(--muted);"><em>Implies neither recovery, debt assignment nor wage-deduction permission.</em></p>

                    <form method="post" class="u-mt-10" style="border-top: 1px solid var(--border); padding-top: 1rem;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reopen_review">
                        <input type="hidden" name="shift_id" value="<?= (int)$targetedShift['Shift ID'] ?>">
                        <h4>Reopen Review</h4>
                        <p class="hint">Requires a reason. Restores shift attention while preserving review history.</p>
                        <div class="form-group" style="display: flex; gap: 0.5rem; align-items: center;">
                            <input name="reopen_reason" type="text" style="flex: 1;" placeholder="Reason to reopen..." required>
                            <button type="submit" class="btn btn-warning">Reopen Review</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!empty($history)): ?>
                <div class="review-history-log u-mt-15">
                    <h4>Review &amp; Reopen History</h4>
                    <ul style="font-size: 0.85rem; line-height: 1.6; margin-left: 1.5rem;">
                        <?php foreach ($history as $h): ?>
                            <li>
                                <strong><?= shift_report_e(ucfirst($h['action'] ?? 'event')) ?></strong>
                                on <?= shift_report_e($h['reviewed_at'] ?? $h['reopened_at'] ?? '') ?>
                                by <?= shift_report_e($h['reviewer_name'] ?? $h['reopener_name'] ?? ('User #' . ($h['reviewed_by'] ?? $h['reopened_by'] ?? ''))) ?>:
                                <em><?= shift_report_e($h['explanation'] ?? $h['reason'] ?? '') ?></em>
                                <?php if (isset($h['remaining_shortage'])): ?> (Shortage: ₱<?= shift_report_e(number_format((float)$h['remaining_shortage'], 2)) ?>)<?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="dashboard-section report-filters"><h2>Filters</h2>
        <form method="get" class="report-filter-grid">
            <div class="form-group"><label for="from">From</label><input id="from" name="from" type="date" value="<?= shift_report_e($filters['from']) ?>" required></div>
            <div class="form-group"><label for="to">To</label><input id="to" name="to" type="date" value="<?= shift_report_e($filters['to']) ?>" required></div>
            <div class="form-group"><label for="cashier_id">Cashier</label><select id="cashier_id" name="cashier_id"><option value="">All Cashiers</option>
            <?php foreach ($cashiers as $cashier): ?><option value="<?= (int)$cashier['user_id'] ?>" <?= $filters['cashier_id'] === (string)$cashier['user_id'] ? 'selected' : '' ?>><?= shift_report_e($cashier['full_name']) ?> (#<?= (int)$cashier['user_id'] ?>)</option><?php endforeach; ?>
            </select></div>
            <div class="form-group"><label for="register_id">Register</label><select id="register_id" name="register_id"><option value="">All Registers</option><option value="legacy" <?= $filters['register_id'] === 'legacy' ? 'selected' : '' ?>>Legacy / Unassigned</option>
            <?php foreach ($registers as $register): ?><option value="<?= (int)$register['register_id'] ?>" <?= $filters['register_id'] === (string)$register['register_id'] ? 'selected' : '' ?>><?= shift_report_e($register['name']) ?> (#<?= (int)$register['register_id'] ?>)</option><?php endforeach; ?>
            </select></div>
            <div class="form-group"><label for="shift_id">Cashier Shift</label><select id="shift_id" name="shift_id"><option value="">All shifts</option><option value="legacy" <?= $filters['shift_id'] === 'legacy' ? 'selected' : '' ?>>Legacy / Unassigned</option>
            <?php foreach ($shifts as $shift): ?><option value="<?= (int)$shift['shift_id'] ?>" <?= $filters['shift_id'] === (string)$shift['shift_id'] ? 'selected' : '' ?>>#<?= (int)$shift['shift_id'] ?> · <?= shift_report_e($shift['opened_at']) ?></option><?php endforeach; ?>
            </select></div><button type="submit" class="btn">Run Report</button>
        </form>
    </section>
    <section class="dashboard-section u-mt-15"><div class="section-header"><div><h2>Results</h2><p><?= count($report['rows']) ?> rows</p></div></div>
        <p class="section-description">Payment totals differ from physical drawer cash. Expected cash is the closing snapshot; a blank counted cash value means no reconciliation was recorded. Older reversals without a Shift are Legacy / Unassigned.</p>
        <div class="table-wrap"><table><thead><tr><?php foreach ($report['headers'] as $header): ?><th><?= shift_report_e($header) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($report['rows'] as $row): ?><tr><?php foreach ($report['headers'] as $header): ?><td><?= shift_report_e($row[$header] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
            <?php if (!$report['rows']): ?><tr><td colspan="<?= count($report['headers']) ?>">No records match these filters.</td></tr><?php endif; ?>
            <?php if ($report['rows']): ?><tr><th>TOTAL</th><?php foreach (array_slice($report['headers'], 1) as $header): ?><td><?= shift_report_e($report['totals'][$header] ?? '') ?></td><?php endforeach; ?></tr><?php endif; ?></tbody></table></div>
        <form method="post" class="report-actions"><?= csrf_field() ?><input type="hidden" name="action" value="export_csv">
            <?php foreach ($filters as $name => $value): ?><input type="hidden" name="<?= shift_report_e($name) ?>" value="<?= shift_report_e($value) ?>"><?php endforeach; ?>
            <button class="btn" type="submit" <?= $error !== '' ? 'disabled' : '' ?>>Export report CSV</button>
        </form>
    </section>
</main></div></body></html>