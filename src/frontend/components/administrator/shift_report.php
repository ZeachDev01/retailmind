<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;
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
$error = '';
try {
    $report = $service->report((string)current_role(), $filters);
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
    $report = ['headers' => StoreShiftReportService::HEADERS, 'rows' => [], 'totals' => []];
} catch (Throwable $exception) {
    $error = OperatorAlert::message($exception, 'The Cashier Shift report could not be loaded. Try again or tell your Administrator.');
    $report = ['headers' => StoreShiftReportService::HEADERS, 'rows' => [], 'totals' => []];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'export_csv' && $error === '') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cashier_shifts_' . date('Ymd_His') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        StoreShiftReportService::csv(fopen('php://output', 'w'), $report);
        exit;
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
    <link rel="stylesheet" href="<?= shift_report_e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= shift_report_e(app_url('assets/css/reports.css')) ?>">
</head>
<body><div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<main class="main-content">
    <header class="page-heading"><p class="page-kicker">Administrator workspace</p><h1>Cashier Shift Report</h1>
        <p class="page-subtitle">Store-wide drawer accountability. Dates select shifts by opening date; each shift shows its full activity. Unassigned sales use sale date.</p></header>
    <?php if ($error !== ''): ?><div class="alert tag-warning"><?= shift_report_e($error) ?></div><?php endif; ?>
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
        <p class="section-description">Payment totals are separate from physical drawer cash. Expected cash is the closing snapshot; blank counted cash means no reconciliation was recorded. Older sale reversals without a Shift are listed as Legacy / Unassigned.</p>
        <div class="table-wrap"><table><thead><tr><?php foreach ($report['headers'] as $header): ?><th><?= shift_report_e($header) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($report['rows'] as $row): ?><tr><?php foreach ($report['headers'] as $header): ?><td><?= shift_report_e($row[$header] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
            <?php if (!$report['rows']): ?><tr><td colspan="<?= count($report['headers']) ?>">No records match these filters.</td></tr><?php endif; ?>
            <?php if ($report['rows']): ?><tr><th>TOTAL</th><?php foreach (array_slice($report['headers'], 1) as $header): ?><td><?= shift_report_e($report['totals'][$header] ?? '') ?></td><?php endforeach; ?></tr><?php endif; ?></tbody></table></div>
        <form method="post" class="report-actions"><?= csrf_field() ?><input type="hidden" name="action" value="export_csv">
            <?php foreach ($filters as $name => $value): ?><input type="hidden" name="<?= shift_report_e($name) ?>" value="<?= shift_report_e($value) ?>"><?php endforeach; ?>
            <button class="btn" type="submit" <?= $error !== '' ? 'disabled' : '' ?>>Export CSV</button>
        </form>
    </section>
</main></div></body></html>
