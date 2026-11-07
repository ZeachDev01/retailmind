<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\CashierShiftService;
use App\Services\StoreShiftReportService;
use App\Support\OperatorAlert;

require_capability(RoleCapabilityPolicy::STORE_OPERATIONS);

$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-d');
$input = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST + $_GET : $_GET;
$filters = [
    'from' => (string)($input['from'] ?? $defaultFrom),
    'to' => (string)($input['to'] ?? $defaultTo),
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

$reportLoaded = true;
try {
    $report = $service->report((string)current_role(), $filters);
} catch (InvalidArgumentException $exception) {
    $reportLoaded = false;
    $error = $exception->getMessage();
    $report = ['headers' => StoreShiftReportService::HEADERS, 'rows' => [], 'totals' => []];
} catch (Throwable $exception) {
    $reportLoaded = false;
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
                'Cashier' => $calc['display_name'] ?? StoreShiftReportService::UNASSIGNED,
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

$cashiers = $pdo->query('SELECT u.user_id, u.display_name FROM users u WHERE EXISTS (SELECT 1 FROM cashier_shifts cs WHERE cs.cashier_id=u.user_id) OR EXISTS (SELECT 1 FROM sales s WHERE s.cashier_id=u.user_id) OR EXISTS (SELECT 1 FROM sale_reversals sr WHERE sr.requested_by=u.user_id) ORDER BY u.display_name, u.user_id')->fetchAll(PDO::FETCH_ASSOC);
$registers = $pdo->query('SELECT register_id, name FROM registers ORDER BY name, register_id')->fetchAll(PDO::FETCH_ASSOC);
$shifts = [];
foreach ($report['rows'] as $row) {
    if ($row['Shift ID'] !== '') $shifts[] = ['shift_id' => $row['Shift ID'], 'opened_at' => $row['Opened At']];
}
if ($targetedShift !== null && !$shifts) {
    $shifts[] = ['shift_id' => $targetedShift['Shift ID'], 'opened_at' => $targetedShift['Opened At']];
}

function shift_report_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function shift_report_money($value): string {
    if ($value === '' || $value === null) return '—';
    return '₱' . number_format((float)$value, 2);
}

$hasActiveFilters = $filters['from'] !== $defaultFrom
    || $filters['to'] !== $defaultTo
    || $filters['cashier_id'] !== ''
    || $filters['register_id'] !== ''
    || $filters['shift_id'] !== '';

$clearShiftUrl = '?' . http_build_query([
    'from' => $filters['from'],
    'to' => $filters['to'],
    'cashier_id' => $filters['cashier_id'],
    'register_id' => $filters['register_id'],
]);

$shiftRowsCount = 0;
$needsReviewCount = 0;
foreach ($report['rows'] as $r) {
    if (!empty($r['Shift ID'])) {
        $shiftRowsCount++;
        if (!empty($r['variance_review_required']) && empty($r['reviewed_at'])) {
            $needsReviewCount++;
        }
    }
}
$totalSalesAmount = (float)($report['totals']['Total Sales'] ?? 0);
$totalRefundsAmount = (float)($report['totals']['Total Refunds'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cashier Shift Report</title>
    <link rel="stylesheet" href="<?= shift_report_e(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= shift_report_e(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
    <link rel="stylesheet" href="<?= shift_report_e(app_url('assets/css/shift-report.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/shift-report.css')) ?>">
</head>
<body class="shift-report-page"><div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<main class="main-content">
    <header class="page-heading shift-page-header">
        <div>
            <h1>Cashier Shift Report</h1>
        </div>
        <a href="#shift-filters" class="btn btn-secondary shift-period-link"><svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>Report filters</a>
        <?php if ($targetedShift !== null): ?>
            <a href="<?= shift_report_e($clearShiftUrl) ?>" class="btn btn-secondary">All shifts</a>
        <?php endif; ?>
    </header>

    <?php if ($error !== ''): ?><div class="alert tag-warning" role="alert"><?= shift_report_e($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert tag-success" role="status"><?= shift_report_e($success) ?></div><?php endif; ?>

    <section class="shift-kpi-grid" aria-label="Report summary">
        <div class="shift-kpi-card">
            <span class="shift-kpi-label">Total Shifts</span>
            <strong class="shift-kpi-value"><?= $reportLoaded ? $shiftRowsCount : '—' ?></strong>
        </div>
        <div class="shift-kpi-card <?= $needsReviewCount > 0 ? 'shift-kpi-card--warning' : '' ?>">
            <span class="shift-kpi-label">Needs Review</span>
            <strong class="shift-kpi-value"><?= $reportLoaded ? $needsReviewCount : '—' ?></strong>
        </div>
        <div class="shift-kpi-card">
            <span class="shift-kpi-label">Total Sales</span>
            <strong class="shift-kpi-value"><?= $reportLoaded ? shift_report_money($totalSalesAmount) : '—' ?></strong>
        </div>
        <div class="shift-kpi-card">
            <span class="shift-kpi-label">Total Refunds</span>
            <strong class="shift-kpi-value"><?= $reportLoaded ? shift_report_money($totalRefundsAmount) : '—' ?></strong>
        </div>
    </section>

    <?php if ($targetedShift !== null): ?>
        <?php
            $isReviewed = !empty($targetedShift['reviewed_at']);
            $needsReview = !empty($targetedShift['variance_review_required']) && !$isReviewed;
            $history = json_decode((string)($targetedShift['review_history'] ?? '[]'), true) ?: [];
            $varianceNum = $targetedShift['Variance'] !== '' ? (float)$targetedShift['Variance'] : null;
            $varianceTone = $varianceNum === null ? 'neutral' : ($varianceNum < 0 ? 'danger' : ($varianceNum > 0 ? 'warning' : 'success'));
        ?>
        <section class="dashboard-section shift-investigation-card u-mb-15" aria-labelledby="shift-review-heading">
            <div class="section-header shift-review-header">
                <div class="shift-review-title-group">
                    <h2 id="shift-review-heading">Shift #<?= (int)$targetedShift['Shift ID'] ?> Review</h2>
                    <?php if ($isReviewed): ?>
                        <span class="badge badge-success">Reviewed</span>
                    <?php elseif ($needsReview): ?>
                        <span class="badge badge-warning">Needs review</span>
                    <?php else: ?>
                        <span class="badge badge-neutral">No review required</span>
                    <?php endif; ?>
                </div>
                <a href="<?= shift_report_e($clearShiftUrl) ?>" class="btn btn-secondary">Close review</a>
            </div>

            <dl class="shift-details">
                <div>
                    <dt>Cashier</dt>
                    <dd><?= shift_report_e($targetedShift['Cashier']) ?> <span class="shift-record-id">#<?= shift_report_e($targetedShift['Cashier ID']) ?></span></dd>
                </div>
                <div>
                    <dt>Register</dt>
                    <dd><?= shift_report_e($targetedShift['Register']) ?> <?php if (($targetedShift['Register ID'] ?? '') !== ''): ?><span class="shift-record-id">#<?= shift_report_e($targetedShift['Register ID']) ?></span><?php endif; ?></dd>
                </div>
                <div>
                    <dt>Opened</dt>
                    <dd><?= shift_report_e($targetedShift['Opened At'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Closed</dt>
                    <dd><?= shift_report_e($targetedShift['Closed At'] ?: 'Still open') ?></dd>
                </div>
            </dl>

            <div class="shift-reconciliation">
                <div class="shift-reconciliation-top">
                    <h3>Cash Reconciliation</h3>
                    <?php if (!empty($targetedShift['variance_threshold'])): ?>
                        <span class="shift-threshold-pill">Threshold: ₱<?= shift_report_e(number_format((float)$targetedShift['variance_threshold'], 2)) ?></span>
                    <?php endif; ?>
                </div>
                <dl class="shift-cash-grid">
                    <div>
                        <dt>Expected Cash</dt>
                        <dd><?= shift_report_money($targetedShift['Expected Cash']) ?></dd>
                    </div>
                    <div>
                        <dt>Counted Cash</dt>
                        <dd><?= $targetedShift['Counted Cash'] !== '' ? shift_report_money($targetedShift['Counted Cash']) : '<span class="u-text-muted">Not counted</span>' ?></dd>
                    </div>
                    <div class="shift-cash-diff shift-cash-diff--<?= $varianceTone ?>">
                        <dt>Difference (<?= shift_report_e($targetedShift['Variance Explanation'] ?: 'Pending closure') ?>)</dt>
                        <dd><?= $targetedShift['Variance'] !== '' ? shift_report_money($targetedShift['Variance']) : '—' ?></dd>
                    </div>
                </dl>
                <?php if (($targetedShift['Closing Notes'] ?? '') !== ''): ?>
                    <div class="shift-closing-notes">
                        <strong>Closing Note:</strong> <?= shift_report_e($targetedShift['Closing Notes']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$isReviewed): ?>
                <div class="investigation-forms">
                    <form method="post" class="shift-review-form shift-review-form--primary">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_reviewed">
                        <input type="hidden" name="shift_id" value="<?= (int)$targetedShift['Shift ID'] ?>">
                        <h3>Complete Review</h3>
                        <div class="form-group u-mt-10">
                            <label for="explanation">Final explanation <span class="required-mark" aria-hidden="true">*</span></label>
                            <textarea id="explanation" name="explanation" rows="3" required placeholder="Root cause or verification notes..."></textarea>
                        </div>
                        <div class="form-group u-mt-10">
                            <label for="remaining_shortage">Remaining shortage (₱)</label>
                            <input id="remaining_shortage" name="remaining_shortage" type="number" step="0.01" min="0" aria-describedby="review-notice" value="<?= shift_report_e($targetedShift['review_shortage'] ?? (floatval($targetedShift['Variance']) < 0 ? abs(floatval($targetedShift['Variance'])) : 0)) ?>">
                        </div>
                        <details class="shift-review-notice">
                            <summary>Review terms</summary>
                            <p id="review-notice">Marking reviewed explicitly records remaining shortage. It implies neither recovery, debt assignment nor wage-deduction permission.</p>
                        </details>
                        <button type="submit" class="btn btn-primary u-mt-10">Mark reviewed</button>
                    </form>

                    <form method="post" class="shift-review-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_draft">
                        <input type="hidden" name="shift_id" value="<?= (int)$targetedShift['Shift ID'] ?>">
                        <h3>Draft notes</h3>
                        <div class="form-group u-mt-10">
                            <label for="draft_notes">Draft notes</label>
                            <textarea id="draft_notes" name="draft_notes" rows="5" placeholder="Working notes..."><?= shift_report_e($targetedShift['review_draft_notes'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-secondary u-mt-10">Save draft</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="reviewed-summary">
                    <div class="reviewed-summary-grid">
                        <div>
                            <h3>Review Completed</h3>
                            <p><strong>Reviewed:</strong> <?= shift_report_e($targetedShift['reviewed_at']) ?> (User #<?= shift_report_e($targetedShift['reviewed_by']) ?>)</p>
                            <p><strong>Explanation:</strong> <?= shift_report_e($targetedShift['review_notes']) ?></p>
                            <p><strong>Remaining Shortage:</strong> ₱<?= shift_report_e(number_format((float)($targetedShift['review_shortage'] ?? 0), 2)) ?></p>
                        </div>
                        <details class="shift-reopen-disclosure">
                            <summary class="btn btn-secondary">Reopen review</summary>
                            <form method="post" class="shift-reopen-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reopen_review">
                                <input type="hidden" name="shift_id" value="<?= (int)$targetedShift['Shift ID'] ?>">
                                <div class="form-group">
                                    <label for="reopen_reason">Reason to reopen <span class="required-mark" aria-hidden="true">*</span></label>
                                    <input id="reopen_reason" name="reopen_reason" type="text" required placeholder="e.g. Found missing voucher">
                                </div>
                                <button type="submit" class="btn btn-secondary u-mt-10">Confirm reopen</button>
                            </form>
                        </details>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($history)): ?>
                <details class="review-history-log u-mt-15">
                    <summary>Review Audit History (<?= count($history) ?>)</summary>
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
                </details>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="dashboard-section shift-results" aria-labelledby="shift-records-heading">
        <div class="section-header shift-results-header">
            <div>
                <h2 id="shift-records-heading">Shift records <span class="shift-count"><?= $reportLoaded ? count($report['rows']) : '—' ?></span></h2>
            </div>
            <form method="post" class="report-actions shift-export-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="export_csv">
                <?php foreach ($filters as $name => $value): ?>
                    <input type="hidden" name="<?= shift_report_e($name) ?>" value="<?= shift_report_e($value) ?>">
                <?php endforeach; ?>
                <button class="btn btn-secondary" type="submit" <?= $error !== '' ? 'disabled' : '' ?>><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/></svg>Export CSV</button>
            </form>
        </div>

        <div id="shift-filters" class="report-filters">
        <form method="get" class="report-filter-grid" aria-label="Filter shifts">
            <div class="form-group">
                <label for="from">From date</label>
                <input id="from" name="from" type="date" value="<?= shift_report_e($filters['from']) ?>" required>
            </div>
            <div class="form-group">
                <label for="to">To date</label>
                <input id="to" name="to" type="date" value="<?= shift_report_e($filters['to']) ?>" required>
            </div>
            <div class="form-group">
                <label for="cashier_id">Cashier</label>
                <select id="cashier_id" name="cashier_id">
                    <option value="">All Cashiers</option>
                    <?php foreach ($cashiers as $cashier): ?>
                        <option value="<?= (int)$cashier['user_id'] ?>" <?= $filters['cashier_id'] === (string)$cashier['user_id'] ? 'selected' : '' ?>><?= shift_report_e($cashier['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="register_id">Register</label>
                <select id="register_id" name="register_id">
                    <option value="">All Registers</option>
                    <option value="legacy" <?= $filters['register_id'] === 'legacy' ? 'selected' : '' ?>>Legacy / Unassigned</option>
                    <?php foreach ($registers as $register): ?>
                        <option value="<?= (int)$register['register_id'] ?>" <?= $filters['register_id'] === (string)$register['register_id'] ? 'selected' : '' ?>><?= shift_report_e($register['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="shift_id">Jump to Shift</label>
                <input id="shift_id" name="shift_id" type="text" list="shift-options" value="<?= shift_report_e($filters['shift_id']) ?>" placeholder="All shifts" pattern="[1-9][0-9]*|legacy" title="Enter a Shift ID or legacy; leave blank for all shifts.">
                <datalist id="shift-options">
                    <option value="legacy">Legacy / Unassigned Only</option>
                    <?php foreach ($shifts as $shift): ?>
                        <option value="<?= (int)$shift['shift_id'] ?>">#<?= (int)$shift['shift_id'] ?> · <?= shift_report_e(substr((string)$shift['opened_at'], 0, 16)) ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="shift-filter-actions">
                <button type="submit" class="btn">Apply filters</button>
                <?php if ($hasActiveFilters): ?>
                    <a href="shift_report.php" class="btn btn-secondary">Reset filters</a>
                <?php endif; ?>
            </div>
        </form>
        </div>

        <div class="table-wrap" role="region" aria-label="Cashier Shift report results" tabindex="0">
            <table class="shift-summary-table" data-no-smart-table>
                <thead>
                    <tr>
                        <th scope="col">Shift / Date</th>
                        <th scope="col">Cashier / Register</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num-col">Total Sales</th>
                        <th scope="col" class="num-col">Refunds</th>
                        <th scope="col" class="num-col">Drawer cash</th>
                        <th scope="col" class="num-col">Variance</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['rows'] as $rowIndex => $row): ?>
                        <?php
                            $rowShiftId = $row['Shift ID'] ?? '';
                            $isShiftRow = $rowShiftId !== '';
                            $rowReviewed = !empty($row['reviewed_at']);
                            $rowNeedsReview = !empty($row['variance_review_required']) && !$rowReviewed;
                            $rowStatus = (string)($row['Status'] ?? '');
                            $rowVariance = $row['Variance'] ?? '';
                            $rowVarFloat = $rowVariance !== '' ? (float)$rowVariance : null;
                            $isSelectedRow = $isShiftRow && (string)$rowShiftId === $filters['shift_id'];
                            $inspectUrl = '?' . http_build_query([
                                'from' => $filters['from'],
                                'to' => $filters['to'],
                                'cashier_id' => $filters['cashier_id'],
                                'register_id' => $filters['register_id'],
                                'shift_id' => $rowShiftId,
                            ]);
                        ?>
                        <tr class="<?= $isSelectedRow ? 'is-selected-shift' : '' ?> <?= $rowNeedsReview ? 'needs-review-row' : '' ?>">
                            <td data-label="Shift / Date">
                                <?php if ($isShiftRow): ?>
                                    <strong class="shift-id-pill">#<?= (int)$rowShiftId ?></strong>
                                <?php else: ?>
                                    <span class="badge badge-neutral"><?= shift_report_e($row['Record']) ?></span>
                                <?php endif; ?>
                                <div class="cell-sub"><?= shift_report_e($row['Date']) ?></div>
                            </td>
                            <td data-label="Cashier / Register">
                                <strong><?= shift_report_e($row['Cashier']) ?></strong>
                                <div class="cell-sub"><?= shift_report_e($row['Register']) ?></div>
                            </td>
                            <td data-label="Status">
                                <?php if ($rowNeedsReview): ?>
                                    <span class="badge badge-warning">Needs review</span>
                                <?php elseif ($rowReviewed): ?>
                                    <span class="badge badge-success">Reviewed</span>
                                <?php elseif ($rowStatus === 'open'): ?>
                                    <span class="badge badge-info">Open</span>
                                <?php elseif ($rowStatus === 'closed'): ?>
                                    <span class="badge badge-neutral">Closed</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral">Legacy</span>
                                <?php endif; ?>
                            </td>
                            <td class="num-col" data-label="Total sales">
                                <strong><?= shift_report_money($row['Total Sales']) ?></strong>
                            </td>
                            <td class="num-col" data-label="Refunds">
                                <?= (float)($row['Total Refunds'] ?? 0) > 0 ? shift_report_money($row['Total Refunds']) : '<span class="u-text-muted">₱0.00</span>' ?>
                            </td>
                            <td class="num-col" data-label="Drawer cash">
                                <?php if ($isShiftRow): ?>
                                    <div>Expected <?= shift_report_money($row['Expected Cash']) ?></div>
                                    <div class="cell-sub">Counted <?= $row['Counted Cash'] !== '' ? shift_report_money($row['Counted Cash']) : 'Pending' ?></div>
                                <?php else: ?>
                                    <span class="u-text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="num-col" data-label="Variance">
                                <?php if ($rowVarFloat === null): ?>
                                    <span class="u-text-muted">—</span>
                                <?php elseif (abs($rowVarFloat) < 0.005): ?>
                                    <span class="badge badge-success">Balanced</span>
                                <?php elseif ($rowVarFloat < 0): ?>
                                    <span class="variance-amount variance-amount--short">-₱<?= shift_report_e(number_format(abs($rowVarFloat), 2)) ?></span>
                                    <div class="cell-sub">Shortage</div>
                                <?php else: ?>
                                    <span class="variance-amount variance-amount--over">+₱<?= shift_report_e(number_format($rowVarFloat, 2)) ?></span>
                                    <div class="cell-sub">Overage</div>
                                <?php endif; ?>
                            </td>
                            <td class="shift-actions-cell">
                                <div class="shift-row-actions">
                                    <?php if ($isShiftRow): ?>
                                        <a href="<?= shift_report_e($inspectUrl) ?>" class="btn <?= $rowNeedsReview ? 'btn-primary' : 'btn-secondary' ?>" aria-label="<?= $rowNeedsReview ? 'Review shift #' . (int)$rowShiftId : 'Inspect shift #' . (int)$rowShiftId ?>">
                                            <?= $rowNeedsReview ? 'Review' : 'Inspect' ?>
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-secondary" popovertarget="shift-breakdown-<?= (int)$rowIndex ?>" aria-label="Breakdown for <?= $isShiftRow ? 'Shift #' . (int)$rowShiftId : shift_report_e($row['Record'] . ' on ' . $row['Date']) ?>">Breakdown</button>
                                        <div popover class="shift-breakdown-popover" id="shift-breakdown-<?= (int)$rowIndex ?>" role="region" aria-labelledby="shift-breakdown-title-<?= (int)$rowIndex ?>">
                                            <div class="shift-breakdown-header">
                                                <div>
                                                    <h3 id="shift-breakdown-title-<?= (int)$rowIndex ?>"><?= $isShiftRow ? 'Shift #' . (int)$rowShiftId : shift_report_e($row['Record']) ?> breakdown</h3>
                                                    <p><?= shift_report_e($row['Cashier']) ?> · <?= shift_report_e($row['Date']) ?></p>
                                                </div>
                                                <button type="button" class="btn btn-secondary" popovertarget="shift-breakdown-<?= (int)$rowIndex ?>" popovertargetaction="hide">Close</button>
                                            </div>
                                            <div class="shift-breakdown-grid">
                                                <div>
                                                    <h4>Sales by method</h4>
                                                    <p><?= (int)($row['Sale Count'] ?? 0) ?> transactions</p>
                                                    <p>Cash: <?= shift_report_money($row['Cash Sales']) ?></p>
                                                    <p>Card: <?= shift_report_money($row['Card Sales']) ?></p>
                                                    <p>E-Wallet: <?= shift_report_money($row['E-Wallet Sales']) ?></p>
                                                </div>
                                                <div>
                                                    <h4>Refunds by Method</h4>
                                                    <p>Cash: <?= shift_report_money($row['Cash Refunds']) ?></p>
                                                    <p>Card: <?= shift_report_money($row['Card Refunds']) ?></p>
                                                    <p>E-Wallet: <?= shift_report_money($row['E-Wallet Refunds']) ?></p>
                                                </div>
                                                <?php if ($isShiftRow): ?>
                                                    <div>
                                                        <h4>Drawer Movements</h4>
                                                        <p>Opening: <?= shift_report_money($row['Opening Cash']) ?></p>
                                                        <p>Cash In: <?= shift_report_money($row['Cash In']) ?></p>
                                                        <p>Cash Out: <?= shift_report_money($row['Cash Out']) ?></p>
                                                        <p>Safe Drops: <?= shift_report_money($row['Safe Drops']) ?></p>
                                                    </div>
                                                    <div>
                                                        <h4>Audit Metadata</h4>
                                                        <p>Opened: <?= shift_report_e($row['Opened At'] ?: '—') ?></p>
                                                        <p>Closed: <?= shift_report_e($row['Closed At'] ?: 'Open') ?></p>
                                                        <?php if (($row['Closed By'] ?? '') !== ''): ?><p>Closed By: <?= shift_report_e($row['Closed By']) ?></p><?php endif; ?>
                                                        <?php if (($row['Intervention Reason'] ?? '') !== ''): ?><p>Intervention: <?= shift_report_e($row['Intervention Reason']) ?></p><?php endif; ?>
                                                        <?php if (($row['Closing Notes'] ?? '') !== ''): ?><p>Note: <?= shift_report_e($row['Closing Notes']) ?></p><?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$report['rows']): ?>
                        <tr><td colspan="8" class="shift-empty">
                            <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h4"/></svg>
                            <h3><?= $reportLoaded ? 'No shifts to show' : 'Report unavailable' ?></h3>
                            <p><?= $reportLoaded ? 'No records match this report. Try another date range or clear your filters.' : 'Results could not be loaded. Check your filters and apply them again to retry.' ?></p>
                            <a href="#shift-filters" class="btn btn-secondary">Adjust filters</a>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
                    <?php if ($report['rows']): ?>
                    <tfoot>
                        <tr class="shift-totals-row">
                            <th scope="row" colspan="3">Total</th>
                            <td class="num-col" data-label="Total sales">
                                <strong><?= shift_report_money($report['totals']['Total Sales'] ?? 0) ?></strong>
                            </td>
                            <td class="num-col" data-label="Refunds"><?= shift_report_money($report['totals']['Total Refunds'] ?? 0) ?></td>
                            <td colspan="3" class="cell-sub">
                                Cash In: <?= shift_report_money($report['totals']['Cash In'] ?? 0) ?> ·
                                Cash Out: <?= shift_report_money($report['totals']['Cash Out'] ?? 0) ?> ·
                                Safe Drops: <?= shift_report_money($report['totals']['Safe Drops'] ?? 0) ?>
                            </td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
            </table>
        </div>
    </section>
</main></div></body></html>
