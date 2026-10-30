<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Services\GCashTransactionService;
use App\Services\PhilippineTime;

require_role(['admin', 'super_admin']);

$service = new GCashTransactionService($pdo, require __DIR__ . '/../../../backend/config/gcash.php');

$startDate = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : PhilippineTime::today();
$endDate = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : PhilippineTime::today();
$cashierId = $_GET['cashier_id'] ?? null;
$period = (string)($_GET['period'] ?? (isset($_GET['start_date']) || isset($_GET['end_date']) ? 'custom' : 'today'));
$activeTab = in_array($_GET['tab'] ?? '', ['overview', 'transactions', 'reconciliation', 'unresolved', 'money'], true) ? $_GET['tab'] : 'overview';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;

$filterError = null;
$overview = null;
$canMutate = current_role() === 'admin' && !(function_exists('is_remote_request') && is_remote_request());

$interventionError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    try {
        if (!$canMutate || ($_POST['action'] ?? '') !== 'emergency_handover') throw new DomainException('Use the in-Store Administrator Workspace for emergency handover.');
        if (($_POST['checked'] ?? '') !== 'yes') throw new DomainException('Confirm the physical cash and wallet checks.');
        $cash = filter_var($_POST['counted_cash'] ?? '', FILTER_VALIDATE_FLOAT);
        $wallet = filter_var($_POST['checked_wallet'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($cash === false || $wallet === false) throw new DomainException('Enter the counted service cash and checked wallet balance.');
        $incoming = filter_var($_POST['incoming_cashier_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $service->emergencyHandover((int)$_SESSION['user_id'], $incoming, $cash, $wallet, (string)($_POST['reason'] ?? ''), []);
        $_SESSION['_flash_success'] = $incoming ? 'Custody handed over. Incoming staff member must accept each unfinished exchange in GCash.' : 'GCash service stopped. Unfinished exchanges remain visible and unsettled.';
        header('Location: ' . app_url('components/administrator/gcash_overview.php?tab=unresolved'));
        exit;
    } catch (DomainException $e) {
        $interventionError = $e->getMessage();
    } catch (Throwable $e) {
        $interventionError = \App\Support\OperatorAlert::message($e, 'Handover could not be saved. Refresh GCash status before trying again.');
    }
}
$moneyHistory = null;
$eligibleCashiers = [];
try {
    if (($_GET['tab'] ?? '') === 'money') $moneyHistory = $service->getMoneyHistory(['session_id' => $_GET['session_id'] ?? null]);
    if ($canMutate) $eligibleCashiers = $service->eligibleHandoverRecipients();
} catch (Throwable $e) {
    error_log((string)$e);
}

$filters = [
    'type' => (string)($_GET['type'] ?? 'all'),
    'search' => trim((string)($_GET['search'] ?? '')),
    'actor_role' => (string)current_role(),
    'actor_id' => (int)($_SESSION['user_id'] ?? 0),
    'period' => $period,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'cashier_id' => $cashierId,
    'page' => $page,
];

// Reporting owns authorized selection and complete traversal.
if (($_GET['export'] ?? '') === 'csv') {
    if (function_exists('is_remote_request') && is_remote_request()) {
        http_response_code(403);
        exit('Bulk exports are not available during remote monitoring.');
    }
    // Stage the complete report before sending headers so failures cannot produce partial downloads.
    $csv = null;
    try {
        $csv = fopen('php://temp', 'w+');
        if ($csv === false) throw new RuntimeException('Unable to stage export.');
        if (fwrite($csv, "\xEF\xBB\xBF") !== 3) throw new RuntimeException('Unable to stage export.');
        if (fputcsv($csv, ['Date / Time', 'Reference No.', 'Type', 'Principal', 'Fee', 'Fee Location', 'Status', 'Cashier', 'Shift', 'Service Cash Movement', 'Wallet Movement', 'Record Basis', 'Legacy Reference Amount'], ',', '"', '') === false) throw new RuntimeException('Unable to stage export.');
        foreach ($service->adminTransactions($filters) as $row) {
            $cells = [$row['time_formatted'], $row['reference_number'], $row['transaction_type'], $row['is_legacy'] ? 'Not recorded' : $row['principal_amount'], $row['is_legacy'] ? 'Not recorded' : $row['gross_fee'], $row['fee_location'] ?? 'Not recorded', $row['verification_status'], $row['cashier_name'] ?: $row['cashier_username'], $row['shift_id'] ?? 'Not recorded', $row['is_legacy'] ? 'Not recorded' : $row['service_cash_delta'], $row['is_legacy'] ? 'Not recorded' : $row['wallet_delta'], $row['is_legacy'] ? 'Legacy reference only' : 'Service exchange', $row['is_legacy'] ? $row['amount'] : null];
            foreach ($cells as &$cell) {
                if (is_string($cell) && preg_match('/^[\x00-\x20]*[=+@\-\t\r\n]/', $cell)) $cell = "'" . $cell;
            }
            unset($cell);
            if (fputcsv($csv, $cells, ',', '"', '') === false) throw new RuntimeException('Unable to stage export.');
        }
        if (!rewind($csv)) throw new RuntimeException('Unable to stage export.');
    } catch (DomainException) {
        if (is_resource($csv)) fclose($csv);
        http_response_code(400);
        exit('Unable to export. Check the filters and try again.');
    } catch (Throwable) {
        if (is_resource($csv)) fclose($csv);
        http_response_code(503);
        exit('Unable to export the complete report. Try again.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gcash-transactions.csv"');
    header('Cache-Control: no-store');
    fpassthru($csv);
    fclose($csv);
    exit;
}

try {
    $overview = $service->adminOverview($filters);
    $startDate = $overview['filters']['start_date'];
    $endDate = $overview['filters']['end_date'];
    $cashierId = $overview['filters']['cashier_id'];
} catch (DomainException $e) {
    $filterError = $e->getMessage();
} catch (Throwable) {
    $filterError = 'Unable to load GCash overview. Try again.';
}

function gcash_admin_e(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}
function gcash_admin_money(mixed $val): string {
    if ($val === null || $val === '') return '—';
    return ((float)$val < 0 ? '−' : '') . '₱' . number_format(abs((float)$val), 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator GCash Overview</title>
    <link rel="stylesheet" href="<?= gcash_admin_e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= gcash_admin_e(app_url('assets/css/cashier.css')) ?>">
    <link rel="stylesheet" href="<?= gcash_admin_e(app_url('assets/css/gcash.css')) ?>">
    <link rel="stylesheet" href="<?= gcash_admin_e(app_url('assets/css/gcash-admin.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/gcash-admin.css')) ?>">
    <link rel="stylesheet" href="<?= gcash_admin_e(app_url('assets/css/sale-receipt.css')) ?>">
</head>
<body class="admin-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="gcash-admin-workspace" data-api="<?= gcash_admin_e(app_url('components/administrator/gcash_overview_api.php')) ?>" data-csrf="<?= gcash_admin_e(csrf_token()) ?>">
        <header class="topbar">
            <div>
                <h1>GCash Overview</h1>

                <span class="gcash-status <?= ($overview['session_status'] ?? '') === 'open' ? 'gcash-status-completed' : '' ?>" id="gcash-session-status">
                    <?= $overview === null ? 'Unavailable' : (!empty($overview['session_id']) ? 'Session #' . (int)$overview['session_id'] . ' · Open' : 'No active session') ?>
                </span>
            </div>
            <div class="gcash-topbar-actions">
                <button type="button" class="btn btn-secondary" id="gcash-refresh" aria-label="Refresh GCash overview"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i>Refresh</button>
                <?php if (!(function_exists('is_remote_request') && is_remote_request())): ?><a class="btn btn-secondary" id="gcash-export" href="?<?= gcash_admin_e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" aria-label="Export transaction report to CSV"><i class="bi bi-download" aria-hidden="true"></i>Export Report</a><?php endif; ?>
                <?php if (in_array('cashier', current_workspace_roles(), true)): ?>
                    <button type="button" class="btn btn-primary" id="gcash-record">Record Exchange</button>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($interventionError !== ''): ?><p class="alert alert-danger" role="alert"><?= gcash_admin_e($interventionError) ?></p><?php endif; ?>
        <?php if ($canMutate && !empty($overview['session_id'])): ?>
        <details class="pos-panel gcash-emergency"><summary>Emergency handover / Stop service</summary>
            <p>Count physical service cash and check GCash wallet. Closes custody immediately; incoming staff must accept unfinished exchanges separately.</p>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= gcash_admin_e(generate_csrf_token()) ?>"><input type="hidden" name="action" value="emergency_handover">
                <div class="form-row">
                    <label for="emergency-counted-cash">Counted service cash (PHP)<input id="emergency-counted-cash" class="pos-input" name="counted_cash" type="number" min="0" step="0.01" required></label>
                    <label for="emergency-checked-wallet">Checked wallet balance (PHP)<input id="emergency-checked-wallet" class="pos-input" name="checked_wallet" type="number" min="0" step="0.01" required></label>
                    <label for="emergency-incoming-cashier">Incoming staff member (open shift required)<select id="emergency-incoming-cashier" class="pos-input" name="incoming_cashier_id"><option value="">Stop service — no replacement</option><?php foreach ($eligibleCashiers as $person): if ((int)$person['user_id'] === (int)($overview['controlling_cashier_id'] ?? 0)) continue; ?><option value="<?= (int)$person['user_id'] ?>"><?= gcash_admin_e($person['full_name']) ?></option><?php endforeach; ?></select></label>
                </div>
                <label for="emergency-reason">Reason<textarea id="emergency-reason" class="pos-input" name="reason" required minlength="5" maxlength="255" rows="2"></textarea></label>
                <label class="gcash-confirmation" for="emergency-checked"><input id="emergency-checked" name="checked" type="checkbox" value="yes" required> I checked both balances and confirm this emergency custody change.</label>
                <p><button class="btn btn-primary" type="submit">Confirm handover or stop</button></p>
            </form>
        </details>
        <?php endif; ?>
        <nav class="gcash-section-nav" role="tablist" aria-label="GCash overview sections">
            <?php foreach (['overview' => 'Overview', 'transactions' => 'Transactions', 'reconciliation' => 'Closing checks', 'unresolved' => 'Unresolved exchanges', 'money' => 'Money history'] as $key => $label): ?>
                <button type="button" role="tab" id="gcash-tab-<?= $key ?>" aria-controls="tab-<?= $key ?>" aria-selected="<?= $key === $activeTab ? 'true' : 'false' ?>" tabindex="<?= $key === $activeTab ? '0' : '-1' ?>">
                    <i class="bi <?= ['overview' => 'bi-wallet2', 'transactions' => 'bi-arrow-left-right', 'reconciliation' => 'bi-journal-check', 'unresolved' => 'bi-clock-history', 'money' => 'bi-journal-text'][$key] ?>" aria-hidden="true"></i>
                    <?= $label ?>
                    <?php if ($key === 'transactions'): ?><span class="gcash-badge"><?= $overview === null ? 'Unavailable' : (int)$overview['matching_record_count'] ?></span><?php endif; ?>
                    <?php if ($key === 'reconciliation'): ?><span class="gcash-badge"><?= $overview === null ? 'Unavailable' : (int)$overview['differences_count'] . ' pending' ?></span><?php endif; ?>
                    <?php if ($key === 'unresolved'): ?><span class="gcash-badge <?= !empty($overview['unresolved_count']) ? 'gcash-status-needs-checking' : '' ?>"><?= $overview === null ? 'Unavailable' : (int)$overview['unresolved_count'] ?></span><?php endif; ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <?php if ($filterError): ?>
            <div class="alert alert-danger gcash-filter-error" role="alert">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <?= gcash_admin_e($filterError) ?>
            </div>
        <?php endif; ?>

        <div id="tab-overview" role="tabpanel" aria-labelledby="gcash-tab-overview" tabindex="0" <?= $activeTab !== 'overview' ? 'hidden' : '' ?>>
        <?php $funds = $overview['recorded_balances'] ?? null; ?>
        <section class="pos-panel gcash-overview-section gcash-funds-panel" aria-labelledby="gcash-admin-balances-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-balances-title">Recorded service funds</h2>
                    </div>
                </div>
                <span class="gcash-badge" id="admin-session-status">Latest session</span>
            </div>

            <div class="gcash-overview-grid gcash-balances-grid">
                <?php foreach (['cash' => ['Service cash', 'service_cash'], 'wallet' => ['GCash wallet', 'wallet_balance']] as $key => [$label, $amountKey]):
                    $amount = $funds[$amountKey] ?? null;
                    $evidenceLabel = $funds === null ? 'Unavailable' : ($amount === null ? 'Not recorded' : ($funds['state'] === 'expected' ? 'Expected' : 'Last counted'));
                ?>
                <div class="gcash-card gcash-card-balance">
                    <div class="gcash-card-header">
                        <span class="gcash-card-label"><?= $label ?></span>
                        <span class="gcash-badge gcash-badge-expected" id="admin-<?= $key ?>-state"><?= $evidenceLabel ?></span>
                    </div>
                    <div class="gcash-card-value" id="admin-expected-<?= $key ?>"><?= $amount === null ? $evidenceLabel : gcash_admin_money($amount) ?></div>

                </div>
                <?php endforeach; ?>
            </div>
            <details class="gcash-fund-details">
                <summary>Balance check &amp; cashier</summary>
                <dl id="admin-cash-checked">
                    <dt>Last actual check</dt><dd><?= gcash_admin_e($funds['checked_at_formatted'] ?? 'Not recorded') ?></dd>
                    <dt>Responsible cashier</dt><dd><?= gcash_admin_e($funds['cashier_name'] ?? 'Not recorded') ?></dd>
                </dl>
            </details>
        </section>

        <section class="pos-panel gcash-overview-section" id="gcash-reporting-scope" aria-label="Activity report filters" data-today="<?= gcash_admin_e(PhilippineTime::today()) ?>">


            <form method="GET" action="<?= gcash_admin_e(app_url('components/administrator/gcash_overview.php')) ?>" id="gcash-admin-filter-form" class="gcash-filter-bar">
                <input type="hidden" name="tab" value="<?= gcash_admin_e($activeTab) ?>">
                <div class="gcash-field">
                    <label for="gcash-reporting-period" class="gcash-filter-label">Reporting period</label>
                    <select id="gcash-reporting-period" name="period" class="form-control">
                        <?php foreach (['today' => 'Today', 'month_to_date' => 'Month-to-date', 'custom' => 'Custom dates'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $period === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="gcash-field gcash-filter-date">
                    <label for="filter-start-date" class="gcash-filter-label">Start date</label>
                    <input type="date" id="filter-start-date" required name="start_date" class="form-control" value="<?= gcash_admin_e($startDate) ?>">
                </div>
                <div class="gcash-field gcash-filter-date">
                    <label for="filter-end-date" class="gcash-filter-label">End date</label>
                    <input type="date" id="filter-end-date" required name="end_date" class="form-control" value="<?= gcash_admin_e($endDate) ?>">
                </div>
                <div class="gcash-field gcash-filter-cashier">
                    <label for="filter-cashier-id" class="gcash-filter-label">Cashier</label>
                    <select id="filter-cashier-id" name="cashier_id" class="form-control">
                        <option value="">All Cashiers</option>
                        <?php foreach ($overview['cashiers'] ?? [] as $c): ?>
                            <option value="<?= gcash_admin_e($c['user_id']) ?>" <?= $cashierId === (int)$c['user_id'] ? 'selected' : '' ?>>
                                <?= gcash_admin_e($c['full_name'] ?: $c['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="gcash-actions gcash-filter-actions">
                    <button type="submit" class="btn btn-primary" id="btn-apply-filters">
                        <i class="bi bi-filter" aria-hidden="true"></i>Apply filters
                    </button>
                    <a href="<?= gcash_admin_e(app_url('components/administrator/gcash_overview.php?tab=' . $activeTab)) ?>" class="btn btn-secondary" id="btn-reset-filters">
                        Reset filters
                    </a>
                </div>
            </form>
            <p id="gcash-filter-status" role="status" aria-live="polite"></p>
        </section>

        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-outstanding-title" id="gcash-outstanding-reviews">
            <div class="pos-panel-header">
                <h2 id="gcash-outstanding-title">Outstanding reviews</h2>
            </div>
            <?php if ($overview === null): ?>
                <p role="alert">Outstanding reviews unavailable. Refresh to try again.</p>
            <?php else: ?>
                <div class="gcash-actions">
                    <button type="button" class="btn btn-secondary" data-review-tab="unresolved">Unresolved exchanges &middot; <?= (int)$overview['unresolved_count'] ?><i class="bi bi-arrow-up-right" aria-hidden="true"></i></button>
                    <button type="button" class="btn btn-secondary" data-review-tab="reconciliation">Closing checks &middot; <?= (int)$overview['differences_count'] ?> pending<i class="bi bi-arrow-up-right" aria-hidden="true"></i></button>
                </div>
            <?php endif; ?>
        </section>

        <section class="pos-panel gcash-overview-section" id="gcash-completed-activity" aria-labelledby="gcash-admin-metrics-title">

            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-graph-up" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-metrics-title">Completed activity</h2>
                    </div>
                </div>
                <div>
                    <span class="gcash-badge gcash-total-badge" id="admin-total-tx-badge">
                        Completed exchanges: <strong id="admin-metric-total-tx"><?= $overview === null ? 'Unavailable' : (int)$overview['completed_exchange_count'] ?></strong>
                    </span>
                </div>
            </div>
            <div class="gcash-overview-grid gcash-metrics-grid">
                <div class="gcash-card">
                    <span class="gcash-card-label">Cash-in principal</span>
                    <div class="gcash-card-value" id="admin-metric-cash-in-principal"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['cash_in_principal']) ?></div>
                </div>
                <div class="gcash-card">
                    <span class="gcash-card-label">Cash-out principal</span>
                    <div class="gcash-card-value" id="admin-metric-cash-out-principal"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['cash_out_principal']) ?></div>
                </div>
                <div class="gcash-card">
                    <span class="gcash-card-label">Gross cash fees</span>
                    <div class="gcash-card-value" id="admin-metric-gross-fees-cash"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['gross_fees_cash']) ?></div>
                </div>
                <div class="gcash-card">
                    <span class="gcash-card-label">Gross wallet fees</span>
                    <div class="gcash-card-value" id="admin-metric-gross-fees-wallet"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['gross_fees_wallet']) ?></div>
                </div>
            </div>
            <div class="gcash-activity-footer">
                <p>Principal excludes fees. Gross fees precede fee returns.</p>
                <button type="button" class="btn btn-secondary" data-review-tab="transactions">View transactions<i class="bi bi-arrow-right" aria-hidden="true"></i></button>
            </div>
        </section>

        </div>

        <div id="tab-transactions" role="tabpanel" aria-labelledby="gcash-tab-transactions" tabindex="0" <?= $activeTab !== 'transactions' ? 'hidden' : '' ?>>
        <!-- SECTION 6: Store-wide Transaction History -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-history-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-list-columns-reverse" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-history-title">Transaction history</h2>
                    </div>
                </div>
                <span class="gcash-badge">Matching records: <strong id="gcash-matching-record-count"><?= $overview === null ? 'Unavailable' : (int)$overview['matching_record_count'] ?></strong></span>
            </div>
            <div class="gcash-transaction-filters" id="gcash-transaction-filters">
                <div class="gcash-transaction-local-filters">
                    <div class="gcash-field gcash-filter-search">
                        <label for="gcash-search" class="gcash-filter-label">Search transactions</label>
                        <input type="search" id="gcash-search" name="search" form="gcash-admin-filter-form" maxlength="200" class="form-control" value="<?= gcash_admin_e($_GET['search'] ?? '') ?>" placeholder="Search reference, shift #, cashier...">
                    </div>
                    <input type="hidden" name="type" form="gcash-admin-filter-form" id="gcash-type-filter" value="<?= gcash_admin_e($_GET['type'] ?? 'all') ?>">
                    <div class="gcash-type-pills" role="group" aria-label="Transaction type">
                        <?php foreach (['all' => ['All', 'all_count'], 'Cash-In' => ['Cash-In', 'cash_in_count'], 'Cash-Out' => ['Cash-Out', 'cash_out_count']] as $value => [$label, $countKey]): ?>
                            <button type="button" class="btn btn-secondary" data-type="<?= $value ?>" aria-pressed="<?= ($_GET['type'] ?? 'all') === $value ? 'true' : 'false' ?>"><?= $label ?> (<?= $overview === null ? 'Unavailable' : (int)$overview['type_counts'][$countKey] ?>)</button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="gcash-table-wrap" tabindex="0" role="region" aria-label="Store-wide transaction history">
                <table class="table" id="gcash-admin-history-table">
                    <thead>
                        <tr>
                            <th scope="col">Date / time</th>
                            <th scope="col">Reference</th>
                            <th scope="col">Type</th>
                            <th scope="col">Principal</th>
                            <th scope="col">Fee / location</th>
                            <th scope="col">Status</th>
                            <th scope="col">Cashier / shift</th>
                            <th scope="col">Cash / wallet movement</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody id="gcash-admin-history-body">
                        <?php if ($overview === null): ?>
                            <tr><td colspan="9" role="alert">Transaction history unavailable for this selection. Try again.</td></tr>
                        <?php elseif (empty($overview['transactions'])): ?>
                            <tr>
                                <td colspan="9" class="gcash-empty-msg gcash-empty-cell">No transactions match these filters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($overview['transactions'] as $tx): ?>
                                <tr data-transaction="<?= gcash_admin_e(json_encode($tx, JSON_INVALID_UTF8_SUBSTITUTE)) ?>">
                                    <td><?= gcash_admin_e($tx['time_formatted'] ?? $tx['created_at_formatted'] ?? $tx['created_at']) ?></td>
                                    <td><code><?= gcash_admin_e($tx['reference_number'] ?: '—') ?></code></td>
                                    <td><?= gcash_admin_e($tx['transaction_type']) ?></td>
                                    <td><?= gcash_admin_money($tx['principal_amount']) ?></td>
                                    <td><span class="gcash-fee-value"><?= $tx['is_legacy'] ? 'Not recorded' : gcash_admin_money($tx['gross_fee']) ?></span><?php if (!empty($tx['fee_location'])): ?><small class="gcash-cell-meta"><?= gcash_admin_e(ucfirst($tx['fee_location'])) ?></small><?php endif; ?></td>
                                    <td>
                                        <?php
                                        $stClass = match($tx['verification_status']) {
                                            'Completed', 'Verified' => 'gcash-status-completed',
                                            'Rejected' => 'gcash-status-rejected',
                                            'Needs checking' => 'gcash-status-needs-checking',
                                            default => ''
                                        };
                                        ?>
                                        <span class="gcash-status <?= $stClass ?>"><?= gcash_admin_e($tx['verification_status']) ?></span>
                                    </td>
                                    <td><?= gcash_admin_e($tx['cashier_name'] ?: $tx['cashier_username']) ?><small class="gcash-cell-meta">Shift <?= $tx['shift_id'] !== null ? '#' . gcash_admin_e($tx['shift_id']) : 'not recorded' ?></small></td>
                                    <td>
                                        <?php if ($tx['is_legacy']): ?>
                                            <span class="gcash-badge gcash-badge-legacy">Legacy reference</span>
                                        <?php else: ?>
                                            <div>Cash: <?= ($tx['service_cash_delta'] > 0 ? '+' : '') . gcash_admin_money($tx['service_cash_delta']) ?></div>
                                            <div>Wallet: <?= ($tx['wallet_delta'] > 0 ? '+' : '') . gcash_admin_money($tx['wallet_delta']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-secondary btn-sm gcash-tx-details">Details</button>
                                        <?php if ($tx['has_receipt']): ?>
                                            <button type="button" class="btn btn-secondary btn-sm gcash-receipt" data-transaction-id="<?= (int)$tx['transaction_id'] ?>">Receipt</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="gcash-scroll-hint">Scroll horizontally for complete table details.</p>
            <!-- Pagination Controls -->
            <div class="gcash-pagination">
                <div>
                    Page <strong id="admin-current-page"><?= $page ?></strong>
                </div>
                <div class="gcash-pagination-actions">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-secondary btn-sm" id="admin-prev-page">Previous</a>
                    <?php endif; ?>
                    <?php if (!empty($overview['has_next'])): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-secondary btn-sm" id="admin-next-page">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        </div>

        <div id="tab-reconciliation" role="tabpanel" aria-labelledby="gcash-tab-reconciliation" tabindex="0" <?= $activeTab !== 'reconciliation' ? 'hidden' : '' ?>>
        <!-- SECTION 4: Reconciliation Differences -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-diff-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-exclamation-diamond" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-diff-title">Closing checks</h2>
                    </div>
                </div>
                <span class="gcash-badge">All dates &middot; All Cashiers</span>
            </div>
            <p class="gcash-section-description">Compare expected balances with closing count. Review records investigation without settling shortage.</p>
            <div class="gcash-table-wrap" tabindex="0" role="region" aria-label="Reconciliation differences">
                <table class="table" id="gcash-admin-diff-table">
                    <thead>
                        <tr>
                            <th scope="col">Session</th>
                            <th scope="col">Cashier</th>
                            <th scope="col">Closed at</th>
                            <th scope="col">Service cash</th>
                            <th scope="col">GCash wallet</th>
                            <th scope="col">Session details</th>
                            <th scope="col">Review status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody id="gcash-admin-diff-body">
                        <?php if ($overview === null): ?>
                            <tr><td colspan="8" role="alert">Reconciliation history unavailable. Refresh to try again.</td></tr>
                        <?php elseif (empty($overview['reconciliation_differences'])): ?>
                            <tr>
                                <td colspan="8" class="gcash-empty-msg gcash-empty-cell">No reconciliation differences recorded.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($overview['reconciliation_differences'] as $diff): ?>
                                <tr data-session-id="<?= gcash_admin_e($diff['session_id']) ?>">
                                    <td><strong>#<?= gcash_admin_e($diff['session_id']) ?></strong> (Shift #<?= gcash_admin_e($diff['shift_id']) ?>)</td>
                                    <td><?= gcash_admin_e($diff['cashier_name']) ?></td>
                                    <td><?= gcash_admin_e($diff['closed_at_formatted'] ?: $diff['closed_at']) ?></td>
                                    <td>
                                        <dl class="gcash-balance-comparison">
                                            <dt>Expected</dt><dd><?= gcash_admin_money($diff['expected_service_cash']) ?></dd>
                                            <dt>Counted</dt><dd><?= gcash_admin_money($diff['closing_service_cash']) ?></dd>
                                            <dt>Difference</dt><dd class="<?= $diff['cash_variance'] < 0 ? 'gcash-variance-negative' : 'gcash-variance-zero' ?>"><?= ($diff['cash_variance'] > 0 ? '+' : '') . gcash_admin_money($diff['cash_variance']) ?></dd>
                                        </dl>
                                    </td>
                                    <td>
                                        <dl class="gcash-balance-comparison">
                                            <dt>Expected</dt><dd><?= gcash_admin_money($diff['expected_wallet_balance']) ?></dd>
                                            <dt>Counted</dt><dd><?= gcash_admin_money($diff['closing_wallet_balance']) ?></dd>
                                            <dt>Difference</dt><dd class="<?= $diff['wallet_variance'] < 0 ? 'gcash-variance-negative' : 'gcash-variance-zero' ?>"><?= ($diff['wallet_variance'] > 0 ? '+' : '') . gcash_admin_money($diff['wallet_variance']) ?></dd>
                                        </dl>
                                    </td>
                                    <td>
                                        <details class="gcash-row-details">
                                            <summary>Session details</summary>
                                            <dl>
                                                <dt>Closed by</dt><dd><?= gcash_admin_e($diff['closed_by_name'] ?? 'Not recorded') ?></dd>
                                                <dt>Closing notes</dt><dd><?= gcash_admin_e($diff['closing_notes'] ?: 'Not recorded') ?></dd>
                                            </dl>
                                        </details>
                                    </td>
                                    <td>
                                        <?php if ($diff['is_waived']): ?>
                                            <span class="gcash-status gcash-status-completed">Reviewed</span>
                                            <details class="gcash-row-details">
                                                <summary>Review details</summary>
                                                <dl>
                                                    <dt>Reviewed by</dt><dd><?= gcash_admin_e($diff['waived_by_name'] ?? 'Admin') ?></dd>
                                                    <dt>Reason</dt><dd><?= gcash_admin_e($diff['waiver_notes']) ?></dd>
                                                </dl>
                                            </details>
                                        <?php else: ?>
                                            <span class="gcash-status gcash-status-needs-checking">Pending review</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$diff['is_waived']): ?>
                                            <?php if ($canMutate): ?><button type="button" class="btn btn-secondary btn-sm admin-btn-waive"
                                                    data-session-id="<?= gcash_admin_e($diff['session_id']) ?>"
                                                    data-cashier="<?= gcash_admin_e($diff['cashier_name']) ?>"
                                                    data-cash-variance="<?= gcash_admin_e($diff['cash_variance']) ?>"
                                                    data-wallet-variance="<?= gcash_admin_e($diff['wallet_variance']) ?>">
                                                Mark reviewed
                                            </button><?php else: ?><span class="text-muted gcash-muted-placeholder">—</span><?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted gcash-muted-placeholder">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="gcash-scroll-hint">Scroll horizontally for complete table details.</p>
        </section>

        </div>

        <div id="tab-unresolved" role="tabpanel" aria-labelledby="gcash-tab-unresolved" tabindex="0" <?= $activeTab !== 'unresolved' ? 'hidden' : '' ?>>
        <!-- SECTION 5: Unresolved Exchanges (Needs Checking) -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-nc-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-nc-title">Unresolved exchanges</h2>
                    </div>
                </div>
                <div class="gcash-panel-meta">
                    <span class="gcash-badge">All dates &middot; All Cashiers</span>
                    <span class="gcash-badge <?= !empty($overview['unresolved_activity']) ? 'gcash-status-needs-checking' : '' ?>" id="admin-nc-count-badge">
                        Pending: <strong id="admin-nc-count"><?= $overview === null ? 'Unavailable' : (int)$overview['unresolved_count'] ?></strong>
                    </span>
                </div>
            </div>
            <p class="gcash-section-description">Verify cash and wallet evidence before clearing. Cancel failed exchanges in Cashier workspace.</p>
            <div class="gcash-table-wrap" tabindex="0" role="region" aria-label="Unresolved activity">
                <table class="table" id="gcash-admin-nc-table">
                    <thead>
                        <tr>
                            <th scope="col">Date / exchange</th>
                            <th scope="col">Cashier</th>
                            <th scope="col">Type</th>
                            <th scope="col">Principal</th>
                            <th scope="col">Fee / location</th>
                            <th scope="col">Movements</th>
                            <th scope="col">Reference</th>
                            <th scope="col">Notes</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody id="gcash-admin-nc-body">
                        <?php if ($overview === null): ?>
                            <tr><td colspan="9" role="alert">Unresolved activity unavailable. Refresh to try again.</td></tr>
                        <?php elseif (empty($overview['unresolved_activity'])): ?>
                            <tr>
                                <td colspan="9" class="gcash-empty-msg gcash-empty-cell">No unresolved activity.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($overview['unresolved_activity'] as $nc): ?>
                                <tr>
                                    <td><?= gcash_admin_e($nc['created_at_formatted'] ?: $nc['created_at']) ?><small class="gcash-cell-meta">Exchange #<?= gcash_admin_e($nc['transaction_id']) ?></small></td>
                                    <td><?= gcash_admin_e($nc['cashier_name']) ?></td>
                                    <td><strong><?= gcash_admin_e($nc['transaction_type']) ?></strong></td>
                                    <td><?= gcash_admin_money($nc['principal_amount']) ?></td>
                                    <td>
                                        <?= $nc['is_legacy'] ? 'Not recorded' : gcash_admin_money($nc['gross_fee']) ?>
                                        <?php if (!empty($nc['fee_location'])): ?>
                                            &middot; <?= gcash_admin_e(ucfirst($nc['fee_location'])) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>Cash: <?= $nc['is_legacy'] ? 'Not recorded' : ($nc['service_cash_delta'] > 0 ? '+' : '') . gcash_admin_money($nc['service_cash_delta']) ?></div>
                                        <div>Wallet: <?= $nc['is_legacy'] ? 'Not recorded' : ($nc['wallet_delta'] > 0 ? '+' : '') . gcash_admin_money($nc['wallet_delta']) ?></div>
                                    </td>
                                    <td><code><?= gcash_admin_e($nc['reference_number'] ?: '—') ?></code></td>
                                    <td>
                                        <?php if ($nc['notes'] ?: $nc['verification_note']): ?>
                                            <details class="gcash-row-details">
                                                <summary>Notes</summary>
                                                <p><?= gcash_admin_e($nc['notes'] ?: $nc['verification_note']) ?></p>
                                            </details>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$nc['is_legacy']): ?>
                                            <?php if ($canMutate): ?><button type="button" class="btn btn-primary gcash-verify" data-transaction-id="<?= (int)$nc['transaction_id'] ?>" data-reference="<?= gcash_admin_e($nc['reference_number'] ?? '') ?>" data-cashier-id="<?= (int)$nc['cashier_id'] ?>" data-confirmed-cash="<?= (int)$nc['confirmed_cash'] ?>" data-confirmed-wallet="<?= (int)$nc['confirmed_wallet'] ?>">Verify &amp; Clear</button><?php else: ?><span class="text-muted gcash-muted-placeholder">—</span><?php endif; ?>
                                        <?php else: ?>
                                            <span class="gcash-badge gcash-badge-legacy">Legacy reference</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="gcash-scroll-hint">Scroll horizontally for complete table details.</p>
        </section>

        </div>

        <dialog id="gcash-verify-dialog" aria-labelledby="gcash-verify-title">
            <h2 id="gcash-verify-title">Verify &amp; Clear Exchange</h2>
            <p>Confirms completed exchange and posts only applicable remaining movements; cannot dismiss failed exchanges.</p>
            <p class="gcash-dialog-routing text-muted">Failed exchange? Cancellations must be handled in Cashier Workspace. <a href="<?= gcash_admin_e(app_url('components/cashier/gcash.php')) ?>" class="gcash-switch-link">Switch to Cashier Workspace</a></p>
            <form id="gcash-verify-form">
                <input type="hidden" id="gcash-verify-tx">
                <input type="hidden" id="gcash-verify-cashier-id">
                <div class="gcash-field"><label for="gcash-verify-reference">GCash reference number</label><input class="form-control" id="gcash-verify-reference" required inputmode="numeric" pattern="[0-9]{13}" minlength="13" maxlength="13"></div>
                <label class="gcash-confirmation"><input type="checkbox" id="gcash-verify-wallet" required> Wallet transfer confirmed in the GCash app</label>
                <div class="gcash-field"><label for="gcash-verify-cash-finding">Cash check evidence &amp; finding</label><input class="form-control" id="gcash-verify-cash-finding" required placeholder="Describe physical service cash check and finding" value="Physical service cash verified"></div>
                <label class="gcash-confirmation"><input type="checkbox" id="gcash-verify-cash" required> Physical service cash exchange confirmed</label>
                <div class="gcash-field"><label for="gcash-verify-wallet-finding">Wallet check evidence &amp; finding</label><input class="form-control" id="gcash-verify-wallet-finding" required placeholder="Describe GCash app wallet check and finding" value="Wallet transfer verified in GCash app"></div>
                <label class="gcash-confirmation"><input type="checkbox" id="gcash-verify-print-receipt"> Print receipt automatically</label>
                <p id="gcash-verify-error" role="alert"></p>
                <div class="gcash-actions"><button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button><button type="submit" class="btn btn-primary">Verify &amp; Clear</button></div>
            </form>
        </dialog>
        <dialog id="txDetailModal" aria-labelledby="tx-detail-title">
            <h2 id="tx-detail-title">Transaction Breakdown</h2>
            <dl id="tx-detail-fields"></dl>
            <div class="gcash-actions"><button type="button" class="btn btn-secondary" data-close-dialog>Close</button></div>
        </dialog>
        <dialog id="gcash-record-dialog" aria-labelledby="gcash-record-title">
            <h2 id="gcash-record-title">Record Exchange</h2>
            <p>Exchanges require an open shift and active GCash session in the Cashier workspace.</p>
            <form method="post" action="<?= gcash_admin_e(app_url('components/auth/workspace.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="workspace" value="cashier">
                <div class="gcash-actions"><button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button><button class="btn btn-primary" type="submit">Open Cashier Workspace</button></div>
            </form>
        </dialog>
        <!-- Discrepancy Waiver Dialog -->
        <div id="tab-money" data-loaded="<?= $moneyHistory !== null ? 'true' : 'false' ?>" role="tabpanel" aria-labelledby="gcash-tab-money" tabindex="0" <?= $activeTab !== 'money' ? 'hidden' : '' ?>>
            <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-money-title">
            <div class="pos-panel-header"><h2 id="gcash-money-title">Money history</h2><span class="gcash-badge">All dates</span></div>
            <p class="gcash-section-description">Funding, exchanges, returns, and corrections by session.</p>
            <form method="get" class="gcash-money-filters">
                <input type="hidden" name="tab" value="money">
                <div class="gcash-field"><label for="gcash-money-session">Session ID</label><input class="form-control" id="gcash-money-session" type="number" name="session_id" min="1" placeholder="All sessions" value="<?= gcash_admin_e($_GET['session_id'] ?? '') ?>"></div>
                <button class="btn btn-primary" type="submit">Load money history</button>
                <a class="btn btn-secondary" href="?tab=money">All sessions</a>
            </form>
            <details class="gcash-history-help"><summary>Timestamp &amp; correction rules</summary><p>Completed exchanges show completion time; unfinished use start time. Corrections and returns show recorded time.</p></details>
            <?php if ($moneyHistory === null): ?><p role="status">Open or reload Money history to load movements.</p><?php elseif (!$moneyHistory): ?><div class="gcash-empty-state"><h3>No movements recorded</h3><p>Session funding, exchanges, and corrections appear here.</p></div><?php else: ?>
            <div class="gcash-table-wrap" tabindex="0" role="region" aria-label="GCash money history"><table class="gcash-table" id="gcash-money-table"><thead><tr><th>Date / time</th><th>Movement</th><th>Session / exchange</th><th>Cash / wallet effect</th><th>Recorded / approved by</th><th>Evidence / reason</th></tr></thead><tbody>
            <?php foreach ($moneyHistory as $movement): ?><tr>
                <td><?= gcash_admin_e($movement['created_at']) ?></td><td><?= gcash_admin_e(ucfirst(str_replace('_', ' ', $movement['movement_type']))) ?><?php if (empty($movement['is_actual_movement'])): ?><small class="gcash-cell-meta">Recording correction · no current money moved</small><?php endif; ?></td>
                <td>#<?= (int)$movement['session_id'] ?> / <?= isset($movement['linked_transaction_id']) ? '#' . (int)$movement['linked_transaction_id'] : '—' ?><?= !empty($movement['linked_session_id']) ? ' · Recovery for session #' . (int)$movement['linked_session_id'] : '' ?></td>
                <td><?php if ($movement['kind'] === 'funding_movement'): ?><?= gcash_admin_e($movement['fund_type']) ?> <?= $movement['direction'] === 'in' ? '+' : '−' ?><?= gcash_admin_money($movement['amount']) ?><?php else: ?><div>Cash <?= gcash_admin_money($movement['service_cash_delta']) ?></div><div>Wallet <?= gcash_admin_money($movement['wallet_delta']) ?></div><?php endif; ?></td>
                <td><?= gcash_admin_e($movement['recorder_name'] ?? 'Not recorded') ?><small class="gcash-cell-meta">Approved: <?= gcash_admin_e($movement['authorizer_name'] ?? 'Not recorded') ?></small></td><td><?= gcash_admin_e($movement['evidence'] ?? '') ?> <?= gcash_admin_e($movement['reason'] ?? '') ?></td>
            </tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
        </div>
        <dialog id="gcash-admin-waiver-dialog" aria-labelledby="waiver-dialog-title">
            <h2 id="waiver-dialog-title" class="gcash-dialog-title">Mark Closing Difference Reviewed</h2>
            <p>Counted variance remains on record. Review logs investigation findings without settling repayment.</p>
            <div id="admin-waiver-summary" class="gcash-waiver-summary">
                <p class="gcash-waiver-session"><strong>Session:</strong> #<span id="waiver-target-session"></span> (<span id="waiver-target-cashier"></span>)</p>
                <p class="gcash-waiver-cash"><strong>Cash Variance:</strong> <span id="waiver-target-cash-var"></span></p>
                <p><strong>Wallet Variance:</strong> <span id="waiver-target-wallet-var"></span></p>
            </div>
            <div id="admin-waiver-error" class="alert alert-danger gcash-waiver-error" hidden></div>
            <form id="admin-waiver-form">
                <label class="gcash-confirmation"><input id="admin-review-cash-checked" name="cash_checked" type="checkbox" required> I checked the physical service cash difference.</label>
                <div class="gcash-field"><label for="admin-review-cash-explanation">Physical service cash findings</label><textarea class="form-control" id="admin-review-cash-explanation" name="cash_explanation" required maxlength="500" rows="2"></textarea></div>
                <label class="gcash-confirmation"><input id="admin-review-wallet-checked" name="wallet_checked" type="checkbox" required> I checked the GCash wallet difference.</label>
                <div class="gcash-field"><label for="admin-review-wallet-explanation">GCash wallet findings</label><textarea class="form-control" id="admin-review-wallet-explanation" name="wallet_explanation" required maxlength="500" rows="2"></textarea></div>
                <input type="hidden" id="waiver-session-id" name="session_id" value="">
                <div class="gcash-field">
                    <label for="admin-waiver-notes" class="gcash-waiver-label">Investigation explanation (5–500 characters)</label>
                    <textarea id="admin-waiver-notes" name="waiver_notes" class="form-control" rows="3" required minlength="5" maxlength="500" placeholder="Explain the conclusion of the physical cash and wallet checks."></textarea>
                </div>
                <div class="gcash-actions gcash-waiver-actions">
                    <button type="button" class="btn btn-secondary" id="admin-waiver-cancel-btn">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="admin-waiver-confirm-btn">Mark reviewed</button>
                </div>
            </form>
        </dialog>
    </main>
    <?php require __DIR__ . '/../../../backend/app/Views/gcash_receipt_dialog.php'; ?>
</div>
<script src="<?= gcash_admin_e(app_url('assets/js/sale-receipt.js')) ?>"></script>
<script src="<?= gcash_admin_e(app_url('assets/js/sale-receipt-dialog.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/sale-receipt-dialog.js')) ?>"></script>
<script src="<?= gcash_admin_e(app_url('assets/js/gcash-admin-overview.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/gcash-admin-overview.js')) ?>"></script>
</body>
</html>
