<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/includes/gcash_admin.php';

use App\GCash\AdminOverviewReport;
use App\GCash\GCashActivityScopeReport;
use App\GCash\GCashExchangeReview;
use App\GCash\GCashLiveCustodySession;
use App\Services\GCashTransactionService;
use App\Services\PhilippineTime;

require_role(['admin', 'super_admin']);

$config = require __DIR__ . '/../../../backend/config/gcash.php';
$service = new GCashTransactionService($pdo, $config);
$custody = new GCashLiveCustodySession($pdo);
$reportBuilder = new AdminOverviewReport($custody, new GCashActivityScopeReport($pdo), new GCashExchangeReview($pdo, $config, $custody));

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
$moneyHistoryError = '';
$allMoneyHistory = ($_GET['movement_scope'] ?? '') === 'all';
$eligibleCashiers = [];
try {
    if (($_GET['tab'] ?? '') === 'money') {
        try {
            $moneyHistory = $service->getMoneyHistory(['session_id' => $_GET['session_id'] ?? null, 'funding_only' => !$allMoneyHistory]);
        } catch (Throwable $e) {
            $moneyHistoryError = \App\Support\OperatorAlert::message($e, 'Service fund movements are unavailable. Reload or ask your Administrator to check the records.');
        }
    }
    if ($canMutate) $eligibleCashiers = $service->eligibleHandoverRecipients();
} catch (Throwable $e) {
    error_log((string)$e);
}

$report = $reportBuilder->forRequest($_GET, (string)current_role(), (int)($_SESSION['user_id'] ?? 0));
$overview = $report->overview;
$filterError = $report->filterError;
$filters = $report->filters;
$page = $report->page;
$period = $report->period;
$startDate = $report->startDate;
$endDate = $report->endDate;
$cashierId = $report->cashierId;
$activeTab = $report->activeTab;
$reviewTab = in_array($activeTab, ['unresolved', 'reconciliation'], true) ? $activeTab : '';
$activeTab = match ($activeTab) {
    'unresolved' => 'transactions',
    'reconciliation' => 'sessions',
    default => $activeTab,
};
$overviewFace = $report->overviewFace;
$isFilteredActivity = $report->isFilteredActivity;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator GCash Overview</title>
    <link rel="stylesheet" href="<?= gcash_admin_e(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= gcash_admin_e(theme_url('assets/css/cashier.css')) ?>">
    <link rel="stylesheet" href="<?= gcash_admin_e(theme_url('assets/css/gcash.css')) ?>">
    <link rel="stylesheet" href="<?= gcash_admin_e(theme_url('assets/css/gcash-admin.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/gcash-admin.css')) ?>">
    <link rel="stylesheet" href="<?= gcash_admin_e(theme_url('assets/css/sale-receipt.css')) ?>">
</head>
<body class="admin-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="gcash-admin-workspace" data-api="<?= gcash_admin_e(app_url('components/administrator/gcash_overview_api.php')) ?>" data-fragments="<?= gcash_admin_e(app_url('components/administrator/gcash_overview_fragments.php')) ?>" data-csrf="<?= gcash_admin_e(csrf_token()) ?>">
        <header class="topbar">
            <div>
                <img class="gcash-session-logo" src="<?= gcash_admin_e(theme_url('assets/img/gcash-logo.svg')) ?>" alt="GCash" width="88" height="28">
                <h1>GCash Overview</h1>

                <?php require __DIR__ . '/../../../backend/app/Views/gcash/session_status.php'; ?>
            </div>
        </header>

        <?php if ($interventionError !== ''): ?><p class="alert alert-danger" role="alert"><?= gcash_admin_e($interventionError) ?></p><?php endif; ?>
        <?php if ($canMutate && !empty($overview['session_id'])): ?>
        <details class="pos-panel gcash-emergency"><summary>Emergency handover / Stop service</summary>
            <p>Count service cash and check the GCash wallet. Closes custody immediately; incoming staff must accept unfinished exchanges.</p>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= gcash_admin_e(generate_csrf_token()) ?>"><input type="hidden" name="action" value="emergency_handover">
                <div class="form-row">
                    <label for="emergency-counted-cash">Counted service cash (PHP)<input id="emergency-counted-cash" class="pos-input" name="counted_cash" type="number" min="0" step="0.01" autocomplete="off" required></label>
                    <label for="emergency-checked-wallet">Checked wallet balance (PHP)<input id="emergency-checked-wallet" class="pos-input" name="checked_wallet" type="number" min="0" step="0.01" autocomplete="off" required></label>
                    <label for="emergency-incoming-cashier">Incoming staff member (open shift required)<select id="emergency-incoming-cashier" class="pos-input" name="incoming_cashier_id"><option value="">Stop service — no replacement</option><?php foreach ($eligibleCashiers as $person): if ((int)$person['user_id'] === (int)($overview['controlling_cashier_id'] ?? 0)) continue; ?><option value="<?= (int)$person['user_id'] ?>"><?= gcash_admin_e($person['display_name']) ?></option><?php endforeach; ?></select></label>
                </div>
                <label for="emergency-reason">Reason<textarea id="emergency-reason" class="pos-input" name="reason" required minlength="5" maxlength="255" rows="2"></textarea></label>
                <label class="gcash-confirmation" for="emergency-checked"><input id="emergency-checked" name="checked" type="checkbox" value="yes" required> I checked both balances and confirm this emergency custody change.</label>
                <p><button class="btn btn-primary" type="submit">Confirm handover or stop</button></p>
            </form>
        </details>
        <?php endif; ?>
        <nav class="gcash-section-nav gcash-tablist" role="tablist" aria-label="GCash overview sections">
            <?php foreach (['overview' => 'Overview', 'transactions' => 'Transactions', 'sessions' => 'Sessions', 'money' => 'Fund movements'] as $key => $label): ?>
                <button type="button" role="tab" class="gcash-tab <?= $key === $activeTab ? 'active' : '' ?>" id="gcash-tab-<?= $key ?>" aria-controls="tab-<?= $key ?>" aria-selected="<?= $key === $activeTab ? 'true' : 'false' ?>" tabindex="<?= $key === $activeTab ? '0' : '-1' ?>">
                    <i class="bi <?= ['overview' => 'bi-wallet2', 'transactions' => 'bi-arrow-left-right', 'reconciliation' => 'bi-journal-check', 'sessions' => 'bi-clock-history', 'unresolved' => 'bi-clock-history', 'money' => 'bi-journal-text'][$key] ?>" aria-hidden="true"></i>
                    <?= $label ?>
                    <?php if ($key === 'transactions'): ?><span class="gcash-badge" data-fragment="transactions-badge"><?= $overview === null ? 'Unavailable' : (int)$overview['matching_record_count'] ?></span><?php endif; ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <?php require __DIR__ . '/../../../backend/app/Views/gcash/filter_error.php'; ?>

        <div id="tab-overview" role="tabpanel" aria-labelledby="gcash-tab-overview" tabindex="0" <?= $activeTab !== 'overview' ? 'hidden' : '' ?>>
        <div class="gcash-balances-row">
            <?php require __DIR__ . '/../../../backend/app/Views/gcash/balances.php'; ?>
            <?php require __DIR__ . '/../../../backend/app/Views/gcash/outstanding_reviews.php'; ?>
        </div>
        <section class="gcash-activity-row" aria-labelledby="gcash-reporting-title">
        <div class="pos-panel gcash-overview-section" id="gcash-reporting-scope" data-today="<?= gcash_admin_e(PhilippineTime::today()) ?>">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div>
                        <h2 id="gcash-reporting-title">Activity report</h2>
                    </div>
                </div>
            </div>
            <div class="gcash-filter-bar gcash-compact-filter-bar">
                <div class="gcash-field">
                    <label for="gcash-reporting-period" class="gcash-filter-label"><i class="bi bi-clock-history" aria-hidden="true"></i> Reporting period</label>
                    <select id="gcash-reporting-period" name="period" form="gcash-admin-filter-form" class="form-control" autocomplete="off">
                        <?php foreach (['today' => 'Today', 'month_to_date' => 'Month-to-date', 'custom' => 'Custom dates'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $period === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="gcash-field gcash-filter-cashier">
                    <label for="filter-cashier-id" class="gcash-filter-label"><i class="bi bi-person-badge" aria-hidden="true"></i> Cashier</label>
                    <select id="filter-cashier-id" name="cashier_id" form="gcash-admin-filter-form" class="form-control" autocomplete="off">
                        <option value="">All Cashiers</option>
                        <?php foreach ($overview['cashiers'] ?? [] as $c): ?>
                            <option value="<?= gcash_admin_e($c['user_id']) ?>" <?= $cashierId === (int)$c['user_id'] ? 'selected' : '' ?>>
                                <?= gcash_admin_e($c['display_name'] ?: $c['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="gcash-actions gcash-filter-actions">
                    <button type="button" class="btn btn-secondary" data-open-dialog="gcash-filter-dialog" aria-haspopup="dialog">Filters</button>
                    <button type="submit" form="gcash-admin-filter-form" class="btn btn-primary" id="btn-apply-filters">
                        <i class="bi bi-funnel-fill" aria-hidden="true"></i>Apply filters
                    </button>
                    <a href="<?= gcash_admin_e(app_url('components/administrator/gcash_overview.php?tab=' . $activeTab)) ?>" class="btn btn-secondary" id="btn-reset-filters">
                        Reset filters
                    </a>
                </div>
            </div>
            <p id="gcash-filter-status" role="status" aria-live="polite"></p>
        </div>
        <?php require __DIR__ . '/../../../backend/app/Views/gcash/activity_metrics.php'; ?>

        </section>
        </div>

        <div id="tab-transactions" role="tabpanel" aria-labelledby="gcash-tab-transactions" tabindex="0" <?= $activeTab !== 'transactions' ? 'hidden' : '' ?>>
        <!-- SECTION 6: Store-wide Transaction History -->
        <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-history-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div>
                        <h2 id="gcash-admin-history-title">Transaction history</h2>
                    </div>
                </div>
                <?php require __DIR__ . '/../../../backend/app/Views/gcash/transactions_summary.php'; ?>
                <button type="button" id="gcash-tab-unresolved" class="btn btn-secondary" data-review-tab="unresolved" aria-haspopup="dialog" aria-controls="tab-unresolved">Unresolved exchanges <span class="gcash-badge" data-fragment="unresolved-badge"><?= $overview === null ? 'Unavailable' : (int)$overview['unresolved_count'] ?></span></button>
            </div>
            <div class="gcash-transaction-filters" id="gcash-transaction-filters">
                <div class="gcash-transaction-local-filters">
                    <div class="gcash-field gcash-filter-search">
                        <label for="gcash-search" class="gcash-filter-label"><i class="bi bi-search" aria-hidden="true"></i> Search transactions</label>
                        <input type="search" id="gcash-search" name="search" form="gcash-admin-filter-form" maxlength="200" class="form-control" autocomplete="off" value="<?= gcash_admin_e($_GET['search'] ?? '') ?>" placeholder="Reference, shift, or cashier">
                    </div>
                    <input type="hidden" name="type" form="gcash-admin-filter-form" id="gcash-type-filter" value="<?= gcash_admin_e($_GET['type'] ?? 'all') ?>">
                    <?php require __DIR__ . '/../../../backend/app/Views/gcash/transactions_type_pills.php'; ?>
                </div>
            </div>
            <div class="gcash-table-wrap" tabindex="0" role="region" aria-label="Store-wide transaction history">
                <table class="table" id="gcash-admin-history-table" data-no-ghost-rows data-no-smart-table>
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
                    <?php require __DIR__ . '/../../../backend/app/Views/gcash/transactions_body.php'; ?>
                </table>
            </div>
            <?php require __DIR__ . '/../../../backend/app/Views/gcash/transactions_pagination.php'; ?>
        </section>

        </div>

        <dialog id="tab-reconciliation" class="gcash-review-drawer" aria-labelledby="gcash-admin-diff-title" data-initial-open="<?= $reviewTab === 'reconciliation' ? 'true' : 'false' ?>">
        <button type="button" class="btn btn-secondary gcash-drawer-close" data-close-dialog>Close</button>
        <!-- SECTION 4: Reconciliation Differences -->
        <?php require __DIR__ . '/../../../backend/app/Views/gcash/reconciliation_panel.php'; ?>

        </dialog>

        <div id="tab-sessions" role="tabpanel" aria-labelledby="gcash-tab-sessions" tabindex="0" <?= $activeTab !== 'sessions' ? 'hidden' : '' ?>>
            <div class="gcash-session-actions"><button type="button" id="gcash-tab-reconciliation" class="btn btn-secondary" data-review-tab="reconciliation" aria-haspopup="dialog" aria-controls="tab-reconciliation">Closing checks <span class="gcash-badge" data-fragment="reconciliation-badge"><?= $overview === null ? 'Unavailable' : (int)$overview['differences_count'] . ' pending' ?></span></button></div>
            <?php if ($activeTab === 'sessions') {
                $sessionHistoryCashierId = null;
                $sessionHistoryPath = app_url('components/administrator/gcash_overview.php');
                require __DIR__ . '/../../../backend/app/Views/gcash/session_history.php';
            } ?>
        </div>

        <dialog id="tab-unresolved" class="gcash-review-drawer" aria-labelledby="gcash-admin-nc-title" data-initial-open="<?= $reviewTab === 'unresolved' ? 'true' : 'false' ?>">
        <button type="button" class="btn btn-secondary gcash-drawer-close" data-close-dialog>Close</button>
        <p class="gcash-review-scope">All pending exchanges · independent of report filters</p>
        <!-- SECTION 5: Unresolved Exchanges (Needs Checking) -->
        <?php require __DIR__ . '/../../../backend/app/Views/gcash/unresolved_panel.php'; ?>

        </dialog>

        <dialog id="gcash-filter-dialog" class="gcash-filter-drawer" aria-labelledby="gcash-filter-title">
            <div class="pos-panel-header"><h2 id="gcash-filter-title">Report filters</h2><button type="button" class="btn btn-secondary" data-close-dialog>Close</button></div>
            <form method="get" action="<?= gcash_admin_e(app_url('components/administrator/gcash_overview.php')) ?>" id="gcash-admin-filter-form">
                <input type="hidden" name="tab" value="<?= gcash_admin_e($activeTab) ?>">
                <div class="gcash-field"><label for="filter-start-date">Start date</label><input type="date" id="filter-start-date" required name="start_date" class="form-control" autocomplete="off" value="<?= gcash_admin_e($startDate) ?>"></div>
                <div class="gcash-field"><label for="filter-end-date">End date</label><input type="date" id="filter-end-date" required name="end_date" class="form-control" autocomplete="off" value="<?= gcash_admin_e($endDate) ?>"></div>
                <div class="gcash-actions"><button type="submit" class="btn btn-primary">Apply filters</button></div>
            </form>
        </dialog>
        <dialog id="gcash-record-details" class="gcash-filter-drawer" aria-labelledby="gcash-record-details-title">
            <div class="pos-panel-header"><h2 id="gcash-record-details-title">Record details</h2><button type="button" class="btn btn-secondary" data-close-dialog>Close</button></div>
            <div id="gcash-record-details-body"></div>
        </dialog>

        <dialog id="gcash-verify-dialog" aria-labelledby="gcash-verify-title">
            <h2 id="gcash-verify-title">Verify &amp; Clear Exchange</h2>
            <p>Clears the exchange and posts only the remaining movements.</p>
            <p class="gcash-dialog-routing text-muted">Failed exchange? Verify &amp; Clear cannot dismiss it — cancel it in the <a href="<?= gcash_admin_e(app_url('components/cashier/gcash.php')) ?>" class="gcash-switch-link">Cashier workspace</a>.</p>
            <form id="gcash-verify-form">
                <input type="hidden" id="gcash-verify-tx" name="transaction_id">
                <input type="hidden" id="gcash-verify-cashier-id" name="cashier_id">
                <div class="gcash-field"><label for="gcash-verify-reference">GCash reference number</label><input class="form-control" id="gcash-verify-reference" name="reference" autocomplete="off" required inputmode="numeric" pattern="[0-9]{13}" minlength="13" maxlength="13"></div>
                <label class="gcash-confirmation"><input type="checkbox" id="gcash-verify-wallet" name="confirmed_wallet" required> Wallet transfer confirmed in the GCash app</label>
                <div class="gcash-field"><label for="gcash-verify-cash-finding">Cash check evidence &amp; finding</label><input class="form-control" id="gcash-verify-cash-finding" name="cash_finding" autocomplete="off" required placeholder="Describe physical service cash check and finding…" value="Physical service cash verified"></div>
                <label class="gcash-confirmation"><input type="checkbox" id="gcash-verify-cash" name="confirmed_cash" required> Physical service cash exchange confirmed</label>
                <div class="gcash-field"><label for="gcash-verify-wallet-finding">Wallet check evidence &amp; finding</label><input class="form-control" id="gcash-verify-wallet-finding" name="wallet_finding" autocomplete="off" required placeholder="Describe GCash app wallet check and finding…" value="Wallet transfer verified in GCash app"></div>
                <p id="gcash-verify-error" role="alert"></p>
                <div class="gcash-actions"><button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button><button type="submit" class="btn btn-primary">Verify &amp; Clear</button></div>
            </form>
        </dialog>
        <dialog id="txDetailModal" aria-labelledby="tx-detail-title">
            <h2 id="tx-detail-title">Transaction Breakdown</h2>
            <dl id="tx-detail-fields"></dl>
            <div class="gcash-actions"><button type="button" class="btn btn-secondary" data-close-dialog>Close</button></div>
        </dialog>
        <div id="tab-money" data-loaded="<?= $moneyHistory !== null ? 'true' : 'false' ?>" role="tabpanel" aria-labelledby="gcash-tab-money" tabindex="0" <?= $activeTab !== 'money' ? 'hidden' : '' ?>>
            <section class="pos-panel gcash-overview-section" aria-labelledby="gcash-money-title">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div>
                        <h2 id="gcash-money-title">Service Fund Movements</h2>
                    </div>
                </div>
                <span class="info-tooltip">
                    <button class="info-tooltip-trigger" type="button" aria-describedby="gcash-money-rules">Timestamp &amp; correction rules <i class="bi bi-info-circle" aria-hidden="true"></i></button>
                    <span class="tooltip-box" id="gcash-money-rules" role="tooltip">Finished exchanges use completion time; unfinished use start time; corrections use recorded time.</span>
                </span>
            </div>
            <form method="get" class="gcash-money-filters">
                <input type="hidden" name="tab" value="money">
                <div class="gcash-field"><label for="gcash-money-scope">Records</label><select class="form-control" id="gcash-money-scope" name="movement_scope"><option value="funds" <?= !$allMoneyHistory ? 'selected' : '' ?>>Service fund movements</option><option value="all" <?= $allMoneyHistory ? 'selected' : '' ?>>All money history</option></select></div>
                <div class="gcash-field"><label for="gcash-money-session"><i class="bi bi-hash" aria-hidden="true"></i> Session ID</label><input class="form-control" id="gcash-money-session" type="number" name="session_id" min="1" autocomplete="off" placeholder="All sessions" value="<?= gcash_admin_e($_GET['session_id'] ?? '') ?>"></div>
                <button class="btn btn-primary" type="submit">Load movements</button>
                <a class="btn btn-secondary" href="?tab=money<?= $allMoneyHistory ? '&amp;movement_scope=all' : '' ?>">All sessions</a>
            </form>
            <?php if ($moneyHistoryError !== ''): ?><p role="alert"><?= gcash_admin_e($moneyHistoryError) ?></p><?php elseif ($moneyHistory === null): ?><p role="status">Open or reload Service Fund Movements to load records.</p><?php elseif (!$moneyHistory): ?><div class="gcash-empty-state"><i class="bi bi-arrow-left-right" aria-hidden="true"></i><h3>No fund movements found</h3><p>Try another session or view all sessions.</p><a class="btn btn-secondary" href="?tab=money<?= $allMoneyHistory ? '&amp;movement_scope=all' : '' ?>">All sessions</a></div><?php else: ?>
            <div class="gcash-table-wrap" tabindex="0" role="region" aria-label="GCash money history"><table class="gcash-table" id="gcash-money-table" data-no-ghost-rows><thead><tr><th scope="col">Date / time</th><th scope="col">Movement</th><th scope="col">Session / exchange</th><th scope="col">Cash / wallet effect</th><th scope="col">Recorded / approved by</th><th scope="col">Evidence / reason</th></tr></thead><tbody>
            <?php foreach ($moneyHistory as $movement): ?><tr>
                <td><?= gcash_admin_e($movement['created_at']) ?></td><td><span class="gcash-type-badge gcash-movement-badge"><?= gcash_admin_e(ucfirst(str_replace('_', ' ', $movement['movement_type']))) ?></span><?php if (empty($movement['is_actual_movement'])): ?><small class="gcash-cell-meta">Correction · no money moved</small><?php endif; ?></td>
                <td>#<?= (int)$movement['session_id'] ?> / <?= isset($movement['linked_transaction_id']) ? '#' . (int)$movement['linked_transaction_id'] : '—' ?><?= !empty($movement['linked_session_id']) ? ' · Recovery for session #' . (int)$movement['linked_session_id'] : '' ?></td>
                <td><?php if ($movement['kind'] === 'funding_movement'): ?><?= gcash_admin_e($movement['fund_type']) ?> <?= $movement['direction'] === 'in' ? '+' : '−' ?><?= gcash_admin_money($movement['amount']) ?><?php else: ?><div class="gcash-movement-line"><span class="gcash-movement-label">Cash</span> <?= gcash_admin_money($movement['service_cash_delta']) ?></div><div class="gcash-movement-line"><span class="gcash-movement-label">Wallet</span> <?= gcash_admin_money($movement['wallet_delta']) ?></div><?php endif; ?></td>
                <td><?= gcash_admin_e($movement['recorder_name'] ?? '—') ?><?php if (!empty($movement['authorizer_name'])): ?><small class="gcash-cell-meta">Approved: <?= gcash_admin_e($movement['authorizer_name']) ?></small><?php endif; ?></td><td><?= gcash_admin_e($movement['evidence'] ?? '') ?> <?= gcash_admin_e($movement['reason'] ?? '') ?></td>
            </tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
        </div>
        <!-- Closing-difference review dialog -->
        <dialog id="gcash-admin-waiver-dialog" aria-labelledby="waiver-dialog-title">
            <h2 id="waiver-dialog-title" class="gcash-dialog-title">Mark Closing Difference Reviewed</h2>
            <p>The variance stays on record. Your findings are logged; this does not settle repayment.</p>
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
                    <textarea id="admin-waiver-notes" name="waiver_notes" class="form-control" rows="3" required minlength="5" maxlength="500" placeholder="Explain the conclusion of the physical cash and wallet checks…"></textarea>
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
<script src="<?= gcash_admin_e(app_url('assets/js/info-tooltip.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/info-tooltip.js')) ?>"></script>
</body>
</html>
