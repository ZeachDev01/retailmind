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
    <link rel="stylesheet" href="<?= shift_report_e(app_url('assets/css/shift-report.css')) ?>">
</head>
<body class="shift-report-page"><div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<main class="main-content">
    <header class="page-heading"><h1>Cashier Shift Report</h1></header>
    <?php if ($error !== ''): ?><div class="alert tag-warning" role="alert"><?= shift_report_e($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert tag-success" role="status"><?= shift_report_e($success) ?></div><?php endif; ?>

    <?php if ($targetedShift !== null): ?>
        <?php
            $isReviewed = !empty($targetedShift['reviewed_at']);
            $needsReview = !empty($targetedShift['variance_review_required']) && !$isReviewed;
            $history = json_decode((string)($targetedShift['review_history'] ?? '[]'), true) ?: [];
        ?>
        <section class="dashboard-section shift-investigation-card u-mb-15">
            <div class="section-header">
                <div>
                    <h2>Shift #<?= (int)$targetedShift['Shift ID'] ?> Review</h2>
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
            <dl class="shift-details">
                <div>
                    <dt>Cashier</dt>
                    <dd><?= shift_report_e($targetedShift['Cashier']) ?> <span class="shift-record-id">#<?= shift_report_e($targetedShift['Cashier ID']) ?></span></dd>
                </div>
                <div>
                    <dt>Register</dt>
                    <dd><?= shift_report_e($targetedShift['Register']) ?> <span class="shift-record-id">#<?= shift_report_e($targetedShift['Register ID']) ?></span></dd>
                </div>
                <div>
                    <dt>Opened</dt>
                    <dd><?= shift_report_e($targetedShift['Opened At']) ?></dd>
                </div>
                <div>
                    <dt>Closed</dt>
                    <dd><?= shift_report_e($targetedShift['Closed At']) ?></dd>
                </div>
            </dl>
            <div class="shift-reconciliation">
                <h3>Cash Reconciliation</h3>
                <dl class="shift-cash-grid">
                    <div><dt>Expected Cash</dt><dd>₱<?= shift_report_e($targetedShift['Expected Cash']) ?></dd></div>
                    <div><dt>Counted Cash</dt><dd><?= $targetedShift['Counted Cash'] !== '' ? '₱' . shift_report_e($targetedShift['Counted Cash']) : 'None' ?></dd></div>
                    <div><dt>Difference</dt><dd>₱<?= shift_report_e($targetedShift['Variance']) ?></dd></div>
                </dl>
                <div class="shift-variance-context">
                    <strong><?= shift_report_e($targetedShift['Variance Explanation']) ?></strong>
                    <?php if (!empty($targetedShift['variance_threshold'])): ?><span>Store limit at closure: ₱<?= shift_report_e(number_format((float)$targetedShift['variance_threshold'], 2)) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="shift-closing-notes">
                <h3>Closing Notes</h3>
                <p><?= shift_report_e($targetedShift['Closing Notes'] !== '' ? $targetedShift['Closing Notes'] : 'None provided') ?></p>
            </div>

            <?php if (!$isReviewed): ?>
                <div class="investigation-forms">
                    <form method="post" class="shift-review-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_draft">
                        <input type="hidden" name="shift_id" value="<?= (int)$targetedShift['Shift ID'] ?>">
                        <h3>Investigation Notes</h3>
                        <div class="form-group u-mt-10">
                            <label for="draft_notes">Draft notes</label>
                            <textarea id="draft_notes" name="draft_notes" rows="6"><?= shift_report_e($targetedShift['review_draft_notes'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-secondary u-mt-10">Save Draft</button>
                    </form>

                    <form method="post" class="shift-review-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_reviewed">
                        <input type="hidden" name="shift_id" value="<?= (int)$targetedShift['Shift ID'] ?>">
                        <h3>Complete Review</h3>
                        <div class="form-group u-mt-10">
                            <label for="explanation">Explanation (Required)</label>
                            <textarea id="explanation" name="explanation" rows="3" required></textarea>
                        </div>
                        <div class="form-group u-mt-10">
                            <label for="remaining_shortage">Remaining Shortage (PHP)</label>
                            <input id="remaining_shortage" name="remaining_shortage" type="number" step="0.01" min="0" aria-describedby="review-notice" value="<?= shift_report_e($targetedShift['review_shortage'] ?? (floatval($targetedShift['Variance']) < 0 ? abs(floatval($targetedShift['Variance'])) : 0)) ?>">
                        </div>
                        <details class="shift-review-notice">
                            <summary>Review terms</summary>
                            <p id="review-notice">Marking reviewed explicitly records remaining shortage. It implies neither recovery, debt assignment nor wage-deduction permission.</p>
                        </details>
                        <button type="submit" class="btn btn-primary u-mt-10">Mark Reviewed</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="reviewed-summary">
                    <h3>Review Completed</h3>
                    <p><strong>Reviewed at:</strong> <?= shift_report_e($targetedShift['reviewed_at']) ?> by user #<?= shift_report_e($targetedShift['reviewed_by']) ?></p>
                    <p><strong>Explanation:</strong> <?= shift_report_e($targetedShift['review_notes']) ?></p>
                    <p><strong>Remaining Shortage:</strong> ₱<?= shift_report_e(number_format((float)($targetedShift['review_shortage'] ?? 0), 2)) ?></p>
                    <details class="shift-review-notice"><summary>Review terms</summary><p>Implies neither recovery, debt assignment nor wage-deduction permission.</p></details>

                    <form method="post" class="shift-reopen-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reopen_review">
                        <input type="hidden" name="shift_id" value="<?= (int)$targetedShift['Shift ID'] ?>">
                        <h4>Reopen Review</h4>
                        <div class="form-group">
                            <label for="reopen_reason">Reason to reopen (Required)</label>
                            <input id="reopen_reason" name="reopen_reason" type="text" required>
                        </div>
                        <button type="submit" class="btn btn-secondary">Reopen Review</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!empty($history)): ?>
                <div class="review-history-log u-mt-15">
                    <h4>Review History</h4>
                    <ul>
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
    <section class="dashboard-section u-mt-15"><div class="section-header"><h2>Results (<?= count($report['rows']) ?>)</h2></div>
        <div class="table-wrap" role="region" aria-label="Cashier Shift report results" tabindex="0"><table><thead><tr><?php foreach ($report['headers'] as $header): ?><th scope="col"><?= shift_report_e($header) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($report['rows'] as $row): ?><tr><?php foreach ($report['headers'] as $header): ?><td><?= shift_report_e($row[$header] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
            <?php if (!$report['rows']): ?><tr><td colspan="<?= count($report['headers']) ?>">No records match these filters.</td></tr><?php endif; ?>
            <?php if ($report['rows']): ?><tr><th>TOTAL</th><?php foreach (array_slice($report['headers'], 1) as $header): ?><td><?= shift_report_e($report['totals'][$header] ?? '') ?></td><?php endforeach; ?></tr><?php endif; ?></tbody></table></div>
        <form method="post" class="report-actions"><?= csrf_field() ?><input type="hidden" name="action" value="export_csv">
            <?php foreach ($filters as $name => $value): ?><input type="hidden" name="<?= shift_report_e($name) ?>" value="<?= shift_report_e($value) ?>"><?php endforeach; ?>
            <button class="btn btn-secondary" type="submit" <?= $error !== '' ? 'disabled' : '' ?>>Export CSV</button>
        </form>
        <details class="shift-report-definitions"><summary>Report definitions</summary>
            <p>Dates use each shift's opening date; unassigned sales use their sale date. Payment totals differ from physical drawer cash. Expected cash is the closing snapshot; a blank counted cash value means no reconciliation was recorded. Older reversals without a Shift are Legacy / Unassigned.</p>
        </details>
    </section>
</main></div></body></html>
