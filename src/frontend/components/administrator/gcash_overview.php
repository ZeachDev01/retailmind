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
$cashierId = !empty($_GET['cashier_id']) ? (int)$_GET['cashier_id'] : null;
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;

$filterError = null;
$overview = null;

$filters = [
    'actor_role' => (string)current_role(),
    'actor_id' => (int)($_SESSION['user_id'] ?? 0),
    'start_date' => $startDate !== '' ? $startDate : null,
    'end_date' => $endDate !== '' ? $endDate : null,
    'cashier_id' => $cashierId,
    'page' => $page,
];

try {
    $overview = $service->adminOverview($filters);
} catch (DomainException $e) {
    $filterError = $e->getMessage();
    // Fallback without date filters
    try {
        $overview = $service->adminOverview([
            'actor_role' => (string)current_role(),
            'actor_id' => (int)($_SESSION['user_id'] ?? 0),
        ]);
    } catch (Throwable) {
        $overview = null;
    }
}

function gcash_admin_e(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}
function gcash_admin_money(mixed $val): string {
    if ($val === null || $val === '') return '—';
    return '₱' . number_format((float)$val, 2);
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
                <a href="<?= gcash_admin_e(app_url('components/administrator/dashboard.php')) ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>Store Overview
                </a>
            </div>
        </header>

        <?php if ($filterError): ?>
            <div class="alert alert-danger gcash-filter-error" role="alert">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <?= gcash_admin_e($filterError) ?>
            </div>
        <?php endif; ?>

        <!-- SECTION 1: Current Store-wide Expected Balances (unaffected by historical date filters) -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-balances-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-balances-title">Current Expected Balances</h2>
                        <p>Expected service cash and wallet funds from the current or latest counted session. Date filters do not change these balances.</p>
                    </div>
                </div>
                <div>
                    <span class="gcash-status <?= ($overview['session_status'] ?? '') === 'open' ? 'gcash-status-completed' : '' ?>" id="admin-session-status">
                        Session: <?= gcash_admin_e(ucfirst($overview['session_status'] ?? 'None')) ?>
                    </span>
                </div>
            </div>
            <div class="gcash-overview-grid gcash-balances-grid">
                <div class="gcash-card gcash-card-balance">
                    <div class="gcash-card-header">
                        <span class="gcash-card-label">Current Expected Service Cash</span>
                        <span class="gcash-badge gcash-badge-expected">Expected</span>
                    </div>
                    <div class="gcash-card-value" id="admin-expected-cash"><?= gcash_admin_money($overview['current_expected_service_cash'] ?? 0) ?></div>
                    <div class="gcash-card-sub" id="admin-cash-checked">
                        Last check: <?= gcash_admin_e($overview['last_checked_formatted'] ?? '—') ?>
                        <?php if (!empty($overview['controlling_cashier_name'])): ?>
                            &middot; Controller: <?= gcash_admin_e($overview['controlling_cashier_name']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="gcash-card gcash-card-balance">
                    <div class="gcash-card-header">
                        <span class="gcash-card-label">Current Expected Wallet Balance</span>
                        <span class="gcash-badge gcash-badge-expected">Expected</span>
                    </div>
                    <div class="gcash-card-value" id="admin-expected-wallet"><?= gcash_admin_money($overview['current_expected_wallet_balance'] ?? 0) ?></div>
                    <div class="gcash-card-sub" id="admin-wallet-checked">
                        Last check: <?= gcash_admin_e($overview['last_checked_formatted'] ?? '—') ?>
                        <?php if (!empty($overview['controlling_cashier_name'])): ?>
                            &middot; Controller: <?= gcash_admin_e($overview['controlling_cashier_name']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- SECTION 2: Filter Bar -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-filters-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-funnel" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-filters-title">Activity Filters</h2>
                        <p>Filter activity by Philippine reporting date and Cashier.</p>
                    </div>
                </div>
            </div>
            <form method="GET" action="<?= gcash_admin_e(app_url('components/administrator/gcash_overview.php')) ?>" id="gcash-admin-filter-form" class="gcash-filter-bar">
                <div class="gcash-field gcash-filter-date">
                    <label for="filter-start-date" class="gcash-filter-label">Start Date</label>
                    <input type="date" id="filter-start-date" name="start_date" class="form-control" value="<?= gcash_admin_e($startDate) ?>">
                </div>
                <div class="gcash-field gcash-filter-date">
                    <label for="filter-end-date" class="gcash-filter-label">End Date</label>
                    <input type="date" id="filter-end-date" name="end_date" class="form-control" value="<?= gcash_admin_e($endDate) ?>">
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
                        <i class="bi bi-filter" aria-hidden="true"></i>Filter
                    </button>
                    <a href="<?= gcash_admin_e(app_url('components/administrator/gcash_overview.php')) ?>" class="btn btn-secondary" id="btn-reset-filters">
                        Reset
                    </a>
                </div>
            </form>
        </section>

        <!-- SECTION 3: Filtered Activity Totals -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-metrics-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-graph-up" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-metrics-title">Filtered Activity Totals</h2>
                        <p>Principal and gross fees for the selected period. These totals are not the current wallet balance.</p>
                    </div>
                </div>
                <div>
                    <span class="gcash-badge gcash-total-badge" id="admin-total-tx-badge">
                        Total Transactions: <strong id="admin-metric-total-tx"><?= (int)($overview['total_transactions'] ?? 0) ?></strong>
                    </span>
                </div>
            </div>
            <div class="gcash-overview-grid gcash-metrics-grid">
                <div class="gcash-card">
                    <span class="gcash-card-label">Filtered Cash-In Principal</span>
                    <div class="gcash-card-value" id="admin-metric-cash-in-principal"><?= gcash_admin_money($overview['cash_in_principal'] ?? 0) ?></div>
                    <div class="gcash-card-sub">Completed customer deposits</div>
                </div>
                <div class="gcash-card">
                    <span class="gcash-card-label">Filtered Cash-Out Principal</span>
                    <div class="gcash-card-value" id="admin-metric-cash-out-principal"><?= gcash_admin_money($overview['cash_out_principal'] ?? 0) ?></div>
                    <div class="gcash-card-sub">Completed customer payouts</div>
                </div>
                <div class="gcash-card">
                    <span class="gcash-card-label">Gross Cash Fees</span>
                    <div class="gcash-card-value" id="admin-metric-gross-fees-cash"><?= gcash_admin_money($overview['gross_fees_cash'] ?? 0) ?></div>
                    <div class="gcash-card-sub">Collected in cash box</div>
                </div>
                <div class="gcash-card">
                    <span class="gcash-card-label">Gross Wallet Fees</span>
                    <div class="gcash-card-value" id="admin-metric-gross-fees-wallet"><?= gcash_admin_money($overview['gross_fees_wallet'] ?? 0) ?></div>
                    <div class="gcash-card-sub">Transferred into wallet</div>
                </div>
            </div>
        </section>

        <!-- SECTION 4: Reconciliation Differences -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-diff-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-exclamation-diamond" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-diff-title">Reconciliation Differences</h2>
                        <p>Closed sessions with cash or wallet variances. Waiving a discrepancy does not change the recorded variance.</p>
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
                        <?php if (empty($overview['reconciliation_differences'])): ?>
                            <tr>
                                <td colspan="12" class="gcash-empty-msg gcash-empty-cell">No reconciliation differences recorded.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($overview['reconciliation_differences'] as $diff): ?>
                                <tr data-session-id="<?= gcash_admin_e($diff['session_id']) ?>">
                                    <td><strong>#<?= gcash_admin_e($diff['session_id']) ?></strong> (Shift #<?= gcash_admin_e($diff['shift_id']) ?>)</td>
                                    <td><?= gcash_admin_e($diff['cashier_name']) ?></td>
                                    <td><?= gcash_admin_e($diff['closed_at_formatted'] ?: $diff['closed_at']) ?></td>
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

        <!-- SECTION 5: Unresolved Exchanges (Needs Checking) -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-nc-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-nc-title">Unresolved Activity (Needs Checking)</h2>
                        <p>Open exchanges that need confirmation or resolution. Expected balances include recorded partial movements.</p>
                    </div>
                </div>
                <div>
                    <span class="gcash-badge <?= !empty($overview['unresolved_activity']) ? 'gcash-status-needs-checking' : '' ?>" id="admin-nc-count-badge">
                        Pending: <strong id="admin-nc-count"><?= (int)($overview['unresolved_count'] ?? 0) ?></strong>
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
                        </tr>
                    </thead>
                    <tbody id="gcash-admin-nc-body">
                        <?php if (empty($overview['unresolved_activity'])): ?>
                            <tr>
                                <td colspan="9" class="gcash-empty-msg gcash-empty-cell">No unresolved exchanges pending.</td>
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
                                        <?= gcash_admin_money($nc['gross_fee']) ?>
                                        <?php if (!empty($nc['fee_location'])): ?>
                                            <small>(<?= gcash_admin_e(ucfirst($nc['fee_location'])) ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            Cash: <?= ($nc['service_cash_delta'] > 0 ? '+' : '') . gcash_admin_money($nc['service_cash_delta']) ?><br>
                                            Wallet: <?= ($nc['wallet_delta'] > 0 ? '+' : '') . gcash_admin_money($nc['wallet_delta']) ?>
                                        </small>
                                    </td>
                                    <td><code><?= gcash_admin_e($nc['reference_number'] ?: '—') ?></code></td>
                                    <td>
                                        <span class="gcash-status gcash-status-needs-checking">Needs checking</span>
                                        <small><?= gcash_admin_e($nc['notes'] ?: $nc['verification_note']) ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- SECTION 6: Store-wide Transaction History -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-history-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-list-columns-reverse" aria-hidden="true"></i></div>
                    <div>
                        <h2 id="gcash-admin-history-title">Store-wide Transaction History</h2>
                        <p>All Cashier activity, including legacy reference records.</p>
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
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody id="gcash-admin-history-body">
                        <?php if (empty($overview['transactions'])): ?>
                            <tr>
                                <td colspan="10" class="gcash-empty-msg gcash-empty-cell">No transactions found for the selected filter criteria.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($overview['transactions'] as $tx): ?>
                                <tr>
                                    <td><?= gcash_admin_e($tx['time_formatted'] ?? $tx['created_at_formatted'] ?? $tx['created_at']) ?></td>
                                    <td><code><?= gcash_admin_e($tx['reference_number'] ?: '—') ?></code></td>
                                    <td><?= gcash_admin_e($tx['transaction_type']) ?></td>
                                    <td><?= gcash_admin_money($tx['principal_amount']) ?></td>
                                    <td><?= gcash_admin_money($tx['gross_fee']) ?></td>
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
</div>
<script src="<?= gcash_admin_e(app_url('assets/js/gcash-admin-overview.js')) ?>"></script>
</body>
</html>
