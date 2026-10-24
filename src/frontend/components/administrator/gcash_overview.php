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
$activeTab = in_array($_GET['tab'] ?? '', ['overview', 'transactions', 'reconciliation', 'unresolved'], true) ? $_GET['tab'] : 'overview';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;

$filterError = null;
$overview = null;

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

// Export uses the same authorized query and walks every matching page.
if (($_GET['export'] ?? '') === 'csv') {
    if ($filterError || $overview === null) {
        http_response_code(400);
        exit('Unable to export. Check the filters and try again.');
    }
    $exportFilters = array_merge($filters, ['page' => 1, 'limit' => 100]);
    // Stage the complete report before sending headers so failures cannot produce partial downloads.
    $csv = null;
    try {
        $csv = fopen('php://temp', 'w+');
        if ($csv === false) throw new RuntimeException('Unable to stage export.');
        if (fwrite($csv, "\xEF\xBB\xBF") !== 3) throw new RuntimeException('Unable to stage export.');
        if (fputcsv($csv, ['Date / Time', 'Reference No.', 'Type', 'Principal', 'Fee', 'Fee Location', 'Status', 'Cashier', 'Shift', 'Service Cash Movement', 'Wallet Movement', 'Record Basis', 'Legacy Reference Amount'], ',', '"', '') === false) throw new RuntimeException('Unable to stage export.');
        do {
            $batch = $service->adminOverview($exportFilters);
            foreach ($batch['transactions'] as $row) {
                $cells = [$row['time_formatted'], $row['reference_number'], $row['transaction_type'], $row['is_legacy'] ? 'Not recorded' : $row['principal_amount'], $row['is_legacy'] ? 'Not recorded' : $row['gross_fee'], $row['fee_location'] ?? 'Not recorded', $row['verification_status'], $row['cashier_name'] ?: $row['cashier_username'], $row['shift_id'] ?? 'Not recorded', $row['is_legacy'] ? 'Not recorded' : $row['service_cash_delta'], $row['is_legacy'] ? 'Not recorded' : $row['wallet_delta'], $row['is_legacy'] ? 'Legacy reference only' : 'Service exchange', $row['is_legacy'] ? $row['amount'] : null];
                foreach ($cells as &$cell) {
                    if (is_string($cell) && preg_match('/^[\x00-\x20]*[=+@\-\t\r\n]/', $cell)) $cell = "'" . $cell;
                }
                unset($cell);
                if (fputcsv($csv, $cells, ',', '"', '') === false) throw new RuntimeException('Unable to stage export.');
            }
            $exportFilters['page']++;
        } while ($batch['has_next']);
        if (!rewind($csv)) throw new RuntimeException('Unable to stage export.');
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
    <link rel="stylesheet" href="<?= gcash_admin_e(app_url('assets/css/gcash-admin.css')) ?>">
    <link rel="stylesheet" href="<?= gcash_admin_e(app_url('assets/css/sale-receipt.css')) ?>">
</head>
<body class="admin-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="gcash-admin-workspace" data-api="<?= gcash_admin_e(app_url('components/administrator/gcash_overview_api.php')) ?>" data-csrf="<?= gcash_admin_e(csrf_token()) ?>">
        <header class="topbar">
            <div>
                <h1>GCash Overview</h1>
                <p class="page-subtitle">Review Store-wide GCash balances, reconciliation differences, and transaction history.</p>
            </div>
            <div class="gcash-topbar-actions">
                <span id="gcash-session-status" class="gcash-status <?= ($overview['session_status'] ?? '') === 'open' ? 'gcash-status-completed' : '' ?>">
                    <?= $overview === null ? 'Unavailable' : (!empty($overview['session_id']) ? 'Active Session #' . (int)$overview['session_id'] . ' (Open)' : 'No active session') ?>
                </span>
                <button type="button" class="btn btn-secondary" id="gcash-refresh">Refresh</button>
                <a class="btn btn-secondary" id="gcash-export" href="?<?= gcash_admin_e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export Report</a>
                <?php if (in_array('cashier', current_workspace_roles(), true)): ?>
                    <button type="button" class="btn btn-primary" id="gcash-record">Record Exchange</button>
                <?php endif; ?>
                <a href="<?= gcash_admin_e(app_url('components/administrator/dashboard.php')) ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>Store Overview
                </a>
            </div>
        </header>

        <nav class="gcash-section-nav" role="tablist" aria-label="GCash overview sections">
            <?php foreach (['overview' => 'Balances & Summary', 'transactions' => 'Transactions & Filters', 'reconciliation' => 'Reconciliation Log', 'unresolved' => 'Unresolved Activity'] as $key => $label): ?>
                <button type="button" role="tab" id="gcash-tab-<?= $key ?>" aria-controls="tab-<?= $key ?>" aria-selected="<?= $key === $activeTab ? 'true' : 'false' ?>" tabindex="<?= $key === $activeTab ? '0' : '-1' ?>">
                    <?= $label ?>
                    <?php if ($key === 'transactions'): ?><span class="gcash-badge"><?= $overview === null ? 'Unavailable' : (int)$overview['matching_record_count'] ?></span><?php endif; ?>
                    <?php if ($key === 'reconciliation'): ?><span class="gcash-badge"><?= $overview === null ? 'Unavailable' : 'Pending (' . (int)$overview['differences_count'] . ')' ?></span><?php endif; ?>
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
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-balances-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-balances-title">Recorded Service Funds</h2>
                        <p>Expected amounts follow recorded movements in an open session. Last counted amounts are closing actual counts. These are not a live GCash app balance; reporting and list filters do not change them.</p>
                    </div>
                </div>
                <span class="gcash-status <?= ($funds['session_status'] ?? '') === 'open' ? 'gcash-status-completed' : '' ?>" id="admin-session-status">
                    <?= $funds === null ? 'Session unavailable' : ($funds['session_id'] === null ? 'No recorded session' : 'Session #' . (int)$funds['session_id'] . ' / ' . ucfirst($funds['session_status'])) ?>
                </span>
            </div>
            <div class="gcash-overview-grid gcash-balances-grid">
                <?php foreach (['cash' => ['GCash Service Cash', 'service_cash'], 'wallet' => ['GCash Service Wallet', 'wallet_balance']] as $key => [$label, $amountKey]):
                    $amount = $funds[$amountKey] ?? null;
                    $evidenceLabel = $funds === null ? 'Unavailable' : ($amount === null ? 'Not recorded' : ($funds['state'] === 'expected' ? 'Expected' : 'Last counted'));
                ?>
                <div class="gcash-card gcash-card-balance">
                    <div class="gcash-card-header">
                        <span class="gcash-card-label"><?= $label ?></span>
                        <span class="gcash-badge gcash-badge-expected" id="admin-<?= $key ?>-state"><?= $evidenceLabel ?></span>
                    </div>
                    <div class="gcash-card-value" id="admin-expected-<?= $key ?>"><?= $amount === null ? $evidenceLabel : gcash_admin_money($amount) ?></div>
                    <div class="gcash-card-sub" id="admin-<?= $key ?>-checked">
                        Last actual check: <?= gcash_admin_e($funds['checked_at_formatted'] ?? 'Not recorded') ?>
                        <?php if (!empty($funds['cashier_name'])): ?>
                            &middot; Responsible Cashier: <?= gcash_admin_e($funds['cashier_name']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="gcash-card-sub" id="admin-combined-funds">Combined recorded funds: <?= $funds === null ? 'Unavailable' : (($funds['service_cash'] === null || $funds['wallet_balance'] === null) ? 'Not recorded' : gcash_admin_money($funds['service_cash'] + $funds['wallet_balance'])) ?>. Assess each fund separately; the combined amount does not establish that either fund is sufficient.</p>
        </section>

        <section class="pos-panel gcash-overview-section" id="gcash-reporting-scope" aria-label="Shared reporting scope" data-today="<?= gcash_admin_e(PhilippineTime::today()) ?>">
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
                    <label for="filter-start-date" class="gcash-filter-label">Start Date</label>
                    <input type="date" id="filter-start-date" required name="start_date" class="form-control" value="<?= gcash_admin_e($startDate) ?>">
                </div>
                <div class="gcash-field gcash-filter-date">
                    <label for="filter-end-date" class="gcash-filter-label">End Date</label>
                    <input type="date" id="filter-end-date" required name="end_date" class="form-control" value="<?= gcash_admin_e($endDate) ?>">
                </div>
                <div class="gcash-field gcash-filter-cashier">
                    <label for="filter-cashier-id" class="gcash-filter-label">Cashier</label>
                    <select id="filter-cashier-id" name="cashier_id" class="form-control">
                        <option value="">All Cashiers (Store-wide)</option>
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
                    <a href="<?= gcash_admin_e(app_url('components/administrator/gcash_overview.php?tab=transactions')) ?>" class="btn btn-secondary" id="btn-reset-filters">
                        Reset
                    </a>
                </div>
            </form>
            <p id="gcash-selected-dates">Selected Philippine dates: <?= gcash_admin_e($startDate) ?> through <?= gcash_admin_e($endDate) ?>. Applies to completed activity and Transactions.</p>
            <p id="gcash-filter-status" role="status" aria-live="polite"></p>
        </section>

        <!-- SECTION 3: Filtered Activity Totals -->

        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-outstanding-title" id="gcash-outstanding-reviews">
            <h2 id="gcash-outstanding-title">Outstanding reviews</h2>
            <p>Store-wide &middot; All dates &middot; All Cashiers. Reporting and transaction filters do not narrow these reviews.</p>
            <?php if ($overview === null): ?>
                <p role="alert">Outstanding reviews unavailable. Refresh to try again.</p>
            <?php else: ?>
                <div class="gcash-actions">
                    <button type="button" class="btn btn-secondary" data-review-tab="unresolved">Review unresolved exchanges (<?= (int)$overview['unresolved_count'] ?>)</button>
                    <button type="button" class="btn btn-secondary" data-review-tab="reconciliation">Review closing discrepancies (<?= (int)$overview['differences_count'] ?> pending)</button>
                </div>
            <?php endif; ?>
        </section>

        <section class="pos-panel gcash-overview-section" id="gcash-completed-activity" aria-labelledby="gcash-admin-metrics-title">

            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-graph-up" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-metrics-title">Completed Service Activity</h2>
                        <p>Exchanged principal is not revenue. Gross service fees are before expenses, not net profit. Transaction search and type do not change these totals.</p>
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
                    <div class="gcash-card-sub">Completed customer deposits</div>
                </div>
                <div class="gcash-card">
                    <span class="gcash-card-label">Cash-out principal</span>
                    <div class="gcash-card-value" id="admin-metric-cash-out-principal"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['cash_out_principal']) ?></div>
                    <div class="gcash-card-sub">Completed customer payouts</div>
                </div>
                <div class="gcash-card">
                    <span class="gcash-card-label">Gross Cash Fees</span>
                    <div class="gcash-card-value" id="admin-metric-gross-fees-cash"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['gross_fees_cash']) ?></div>
                    <div class="gcash-card-sub">Collected in cash box</div>
                </div>
                <div class="gcash-card">
                    <span class="gcash-card-label">Gross Wallet Fees</span>
                    <div class="gcash-card-value" id="admin-metric-gross-fees-wallet"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['gross_fees_wallet']) ?></div>
                    <div class="gcash-card-sub">Transferred into wallet</div>
                </div>
            </div>
        </section>

        </div>

        <div id="tab-transactions" role="tabpanel" aria-labelledby="gcash-tab-transactions" tabindex="0" <?= $activeTab !== 'transactions' ? 'hidden' : '' ?>>
        <!-- SECTION 2: Filter Bar -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-filters-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-funnel" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-filters-title">Transaction Filters</h2>
                        <p>Search and type apply only to this transaction list.</p>
                    </div>
                </div>
            </div>
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
        </section>

        <!-- SECTION 6: Store-wide Transaction History -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-history-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-list-columns-reverse" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-history-title">Store-wide Transaction History</h2>
                        <p>Matching records: <strong id="gcash-matching-record-count"><?= $overview === null ? 'Unavailable' : (int)$overview['matching_record_count'] ?></strong>. Shared period and Cashier scope, narrowed by search and type. Includes all statuses and legacy references.</p>
                    </div>
                </div>
            </div>
            <div class="gcash-table-wrap" tabindex="0" role="region" aria-label="Store-wide transaction history">
                <table class="table" id="gcash-admin-history-table">
                    <thead>
                        <tr>
                            <th scope="col">Date / Time</th>
                            <th scope="col">Reference</th>
                            <th scope="col">Type</th>
                            <th scope="col">Principal</th>
                            <th scope="col">Fee</th>
                            <th scope="col">Fee Location</th>
                            <th scope="col">Status</th>
                            <th scope="col">Cashier</th>
                            <th scope="col">Shift</th>
                            <th scope="col">Net Ledger Movement</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody id="gcash-admin-history-body">
                        <?php if ($overview === null): ?>
                            <tr><td colspan="11" role="alert">Transaction history unavailable for this selection. Try again.</td></tr>
                        <?php elseif (empty($overview['transactions'])): ?>
                            <tr>
                                <td colspan="11" class="gcash-empty-msg gcash-empty-cell">No transactions found for the selected filter criteria.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($overview['transactions'] as $tx): ?>
                                <tr data-transaction="<?= gcash_admin_e(json_encode($tx, JSON_INVALID_UTF8_SUBSTITUTE)) ?>">
                                    <td><?= gcash_admin_e($tx['time_formatted'] ?? $tx['created_at_formatted'] ?? $tx['created_at']) ?></td>
                                    <td><code><?= gcash_admin_e($tx['reference_number'] ?: '—') ?></code></td>
                                    <td><?= gcash_admin_e($tx['transaction_type']) ?></td>
                                    <td><?= gcash_admin_money($tx['principal_amount']) ?></td>
                                    <td><?= $tx['is_legacy'] ? 'Not recorded' : gcash_admin_money($tx['gross_fee']) ?></td>
                                    <td>
                                        <?php if (!empty($tx['fee_location'])): ?>
                                            <?= gcash_admin_e(ucfirst($tx['fee_location'])) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
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
                                    <td><?= gcash_admin_e($tx['cashier_name'] ?: $tx['cashier_username']) ?></td>
                                    <td><?= $tx['shift_id'] !== null ? '#' . gcash_admin_e($tx['shift_id']) : '—' ?></td>
                                    <td>
                                        <?php if ($tx['is_legacy']): ?>
                                            <span class="gcash-badge gcash-badge-legacy">Legacy reference</span>
                                            <small>Legacy reference record (amount: <?= gcash_admin_money($tx['amount']) ?>)</small>
                                        <?php else: ?>
                                            <small>
                                                Cash &Delta;: <?= ($tx['service_cash_delta'] > 0 ? '+' : '') . gcash_admin_money($tx['service_cash_delta']) ?>,
                                                Wallet &Delta;: <?= ($tx['wallet_delta'] > 0 ? '+' : '') . gcash_admin_money($tx['wallet_delta']) ?>
                                                <?php if (!empty($tx['adjustments'])): ?>
                                                    &middot; <?= count($tx['adjustments']) ?> adjustment(s)
                                                <?php endif; ?>
                                            </small>
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
                        <h2 id="gcash-admin-diff-title">Reconciliation Differences</h2>
                        <p>Store-wide &middot; All dates &middot; All Cashiers. Closed sessions with cash or wallet variances. Waiving a discrepancy does not change the recorded variance.</p>
                    </div>
                </div>
            </div>
            <div class="gcash-table-wrap" tabindex="0" role="region" aria-label="Reconciliation differences">
                <table class="table" id="gcash-admin-diff-table">
                    <thead>
                        <tr>
                            <th scope="col">Session</th>
                            <th scope="col">Cashier</th>
                            <th scope="col">Closed At</th>
                            <th scope="col">Expected Cash</th>
                            <th scope="col">Actual Cash</th>
                            <th scope="col">Cash Variance</th>
                            <th scope="col">Expected Wallet</th>
                            <th scope="col">Actual Wallet</th>
                            <th scope="col">Wallet Variance</th>
                            <th scope="col">Closing Notes</th>
                            <th scope="col">Waiver Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody id="gcash-admin-diff-body">
                        <?php if ($overview === null): ?>
                            <tr><td colspan="12" role="alert">Reconciliation history unavailable. Refresh to try again.</td></tr>
                        <?php elseif (empty($overview['reconciliation_differences'])): ?>
                            <tr>
                                <td colspan="12" class="gcash-empty-msg gcash-empty-cell">No Reconciliation Differences Recorded. All recorded sessions have zero variance.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($overview['reconciliation_differences'] as $diff): ?>
                                <tr data-session-id="<?= gcash_admin_e($diff['session_id']) ?>">
                                    <td><strong>#<?= gcash_admin_e($diff['session_id']) ?></strong> (Shift #<?= gcash_admin_e($diff['shift_id']) ?>)</td>
                                    <td><?= gcash_admin_e($diff['cashier_name']) ?></td>
                                    <td><?= gcash_admin_e($diff['closed_at_formatted'] ?: $diff['closed_at']) ?><small>Closed by <?= gcash_admin_e($diff['closed_by_name'] ?? 'Not recorded') ?></small></td>
                                    <td><?= gcash_admin_money($diff['expected_service_cash']) ?></td>
                                    <td><?= gcash_admin_money($diff['closing_service_cash']) ?></td>
                                    <td class="<?= $diff['cash_variance'] > 0 ? 'gcash-variance-positive' : ($diff['cash_variance'] < 0 ? 'gcash-variance-negative' : 'gcash-variance-zero') ?>">
                                        <?= ($diff['cash_variance'] > 0 ? '+' : '') . gcash_admin_money($diff['cash_variance']) ?>
                                    </td>
                                    <td><?= gcash_admin_money($diff['expected_wallet_balance']) ?></td>
                                    <td><?= gcash_admin_money($diff['closing_wallet_balance']) ?></td>
                                    <td class="<?= $diff['wallet_variance'] > 0 ? 'gcash-variance-positive' : ($diff['wallet_variance'] < 0 ? 'gcash-variance-negative' : 'gcash-variance-zero') ?>">
                                        <?= ($diff['wallet_variance'] > 0 ? '+' : '') . gcash_admin_money($diff['wallet_variance']) ?>
                                    </td>
                                    <td><small><?= gcash_admin_e($diff['closing_notes']) ?></small></td>
                                    <td>
                                        <?php if ($diff['is_waived']): ?>
                                            <span class="gcash-status gcash-status-completed">Waived</span>
                                            <small>By <?= gcash_admin_e($diff['waived_by_name'] ?? 'Admin') ?>: <?= gcash_admin_e($diff['waiver_notes']) ?></small>
                                        <?php else: ?>
                                            <span class="gcash-status gcash-status-needs-checking">Pending Review</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$diff['is_waived']): ?>
                                            <button type="button" class="btn btn-secondary btn-sm admin-btn-waive"
                                                    data-session-id="<?= gcash_admin_e($diff['session_id']) ?>"
                                                    data-cashier="<?= gcash_admin_e($diff['cashier_name']) ?>"
                                                    data-cash-variance="<?= gcash_admin_e($diff['cash_variance']) ?>"
                                                    data-wallet-variance="<?= gcash_admin_e($diff['wallet_variance']) ?>">
                                                Waive Discrepancy
                                            </button>
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
        </section>

        </div>

        <div id="tab-unresolved" role="tabpanel" aria-labelledby="gcash-tab-unresolved" tabindex="0" <?= $activeTab !== 'unresolved' ? 'hidden' : '' ?>>
        <!-- SECTION 5: Unresolved Exchanges (Needs Checking) -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-nc-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-nc-title">Unresolved Activity (Needs Checking)</h2>
                        <p>Store-wide &middot; All dates &middot; All Cashiers. Open exchanges that need confirmation or resolution. Expected balances include recorded partial movements.</p>
                    </div>
                </div>
                <div>
                    <span class="gcash-badge <?= !empty($overview['unresolved_activity']) ? 'gcash-status-needs-checking' : '' ?>" id="admin-nc-count-badge">
                        Pending: <strong id="admin-nc-count"><?= $overview === null ? 'Unavailable' : (int)$overview['unresolved_count'] ?></strong>
                    </span>
                </div>
            </div>
            <div class="gcash-table-wrap" tabindex="0" role="region" aria-label="Unresolved activity">
                <table class="table" id="gcash-admin-nc-table">
                    <thead>
                        <tr>
                            <th scope="col">Date / Time</th>
                            <th scope="col">Tx ID</th>
                            <th scope="col">Cashier</th>
                            <th scope="col">Type</th>
                            <th scope="col">Principal</th>
                            <th scope="col">Fee location</th>
                            <th scope="col">Movements</th>
                            <th scope="col">Reference</th>
                            <th scope="col">Notes and status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody id="gcash-admin-nc-body">
                        <?php if ($overview === null): ?>
                            <tr><td colspan="10" role="alert">Unresolved activity unavailable. Refresh to try again.</td></tr>
                        <?php elseif (empty($overview['unresolved_activity'])): ?>
                            <tr>
                                <td colspan="10" class="gcash-empty-msg gcash-empty-cell">No Unresolved Activity Pending.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($overview['unresolved_activity'] as $nc): ?>
                                <tr>
                                    <td><?= gcash_admin_e($nc['created_at_formatted'] ?: $nc['created_at']) ?></td>
                                    <td>#<?= gcash_admin_e($nc['transaction_id']) ?></td>
                                    <td><?= gcash_admin_e($nc['cashier_name']) ?></td>
                                    <td><strong><?= gcash_admin_e($nc['transaction_type']) ?></strong></td>
                                    <td><?= gcash_admin_money($nc['principal_amount']) ?></td>
                                    <td>
                                        <?= $nc['is_legacy'] ? 'Not recorded' : gcash_admin_money($nc['gross_fee']) ?>
                                        <?php if (!empty($nc['fee_location'])): ?>
                                            <small>(<?= gcash_admin_e(ucfirst($nc['fee_location'])) ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            Cash: <?= $nc['is_legacy'] ? 'Not recorded' : ($nc['service_cash_delta'] > 0 ? '+' : '') . gcash_admin_money($nc['service_cash_delta']) ?><br>
                                            Wallet: <?= $nc['is_legacy'] ? 'Not recorded' : ($nc['wallet_delta'] > 0 ? '+' : '') . gcash_admin_money($nc['wallet_delta']) ?>
                                        </small>
                                    </td>
                                    <td><code><?= gcash_admin_e($nc['reference_number'] ?: '—') ?></code></td>
                                    <td>
                                        <span class="gcash-status gcash-status-needs-checking">Needs checking</span>
                                        <small><?= gcash_admin_e($nc['notes'] ?: $nc['verification_note']) ?></small>
                                    </td>
                                    <td>
                                        <?php if (!$nc['is_legacy']): ?>
                                            <button type="button" class="btn btn-primary gcash-verify" data-transaction-id="<?= (int)$nc['transaction_id'] ?>" data-reference="<?= gcash_admin_e($nc['reference_number'] ?? '') ?>">Verify &amp; Clear</button>
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
        </section>

        </div>

        <dialog id="gcash-verify-dialog" aria-labelledby="gcash-verify-title">
            <h2 id="gcash-verify-title">Verify &amp; Clear Exchange</h2>
            <p>Confirm the actual exchange against the GCash app and service cash before completing transaction #<strong id="gcash-verify-id"></strong>. This completes the exchange, posts only the remaining movements, and creates an audit record. Previously posted partial movements stay recorded and are not posted again.</p>
            <form id="gcash-verify-form">
                <input type="hidden" id="gcash-verify-tx">
                <div class="gcash-field"><label for="gcash-verify-reference">GCash reference number</label><input class="form-control" id="gcash-verify-reference" required inputmode="numeric" pattern="[0-9]{13}" minlength="13" maxlength="13"></div>
                <label class="gcash-confirmation"><input type="checkbox" id="gcash-verify-wallet" required> Wallet transfer confirmed in the GCash app</label>
                <label class="gcash-confirmation"><input type="checkbox" id="gcash-verify-cash" required> Physical service cash exchange confirmed</label>
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
            <p>Record and verify exchanges in the Cashier workspace with an open Cashier Shift and control of the GCash Service session.</p>
            <form method="post" action="<?= gcash_admin_e(app_url('components/auth/workspace.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="workspace" value="cashier">
                <div class="gcash-actions"><button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button><button class="btn btn-primary" type="submit">Open Cashier Workspace</button></div>
            </form>
        </dialog>
        <!-- Discrepancy Waiver Dialog -->
        <dialog id="gcash-admin-waiver-dialog" aria-labelledby="waiver-dialog-title">
            <h2 id="waiver-dialog-title" class="gcash-dialog-title">Waive Closing Discrepancy</h2>
            <p>The counted variance stays on record. This waiver records your approval and reason.</p>
            <div id="admin-waiver-summary" class="gcash-waiver-summary">
                <p class="gcash-waiver-session"><strong>Session:</strong> #<span id="waiver-target-session"></span> (<span id="waiver-target-cashier"></span>)</p>
                <p class="gcash-waiver-cash"><strong>Cash Variance:</strong> <span id="waiver-target-cash-var"></span></p>
                <p><strong>Wallet Variance:</strong> <span id="waiver-target-wallet-var"></span></p>
            </div>
            <div id="admin-waiver-error" class="alert alert-danger gcash-waiver-error" hidden></div>
            <form id="admin-waiver-form">
                <input type="hidden" id="waiver-session-id" name="session_id" value="">
                <div class="gcash-field">
                    <label for="admin-waiver-notes" class="gcash-waiver-label">Reason for waiver (5–500 characters)</label>
                    <textarea id="admin-waiver-notes" name="waiver_notes" class="form-control" rows="3" required minlength="5" maxlength="500" placeholder="Explain why this closing discrepancy was approved or resolved without modifying recorded cash."></textarea>
                </div>
                <div class="gcash-actions gcash-waiver-actions">
                    <button type="button" class="btn btn-secondary" id="admin-waiver-cancel-btn">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="admin-waiver-confirm-btn">Confirm Waiver</button>
                </div>
            </form>
        </dialog>
    </main>
    <?php require __DIR__ . '/../../../backend/app/Views/gcash_receipt_dialog.php'; ?>
</div>
<script src="<?= gcash_admin_e(app_url('assets/js/sale-receipt.js')) ?>"></script>
<script src="<?= gcash_admin_e(app_url('assets/js/sale-receipt-dialog.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/sale-receipt-dialog.js')) ?>"></script>
<script src="<?= gcash_admin_e(app_url('assets/js/gcash-admin-overview.js')) ?>"></script>
</body>
</html>
