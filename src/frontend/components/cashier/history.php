<?php
// Private, read-only Cashier operational history (#98).
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/CashierOperationalHistoryService.php';

use App\Services\CashierOperationalHistoryService;
use App\Services\PhilippineTime;

require_role(['cashier']);

$cashierId = (int)$_SESSION['user_id'];
// The account identifier comes only from the authenticated session. Request
// filters, URLs, and form data cannot change the owner of any query.
$request = (new CashierOperationalHistoryService($pdo))->resolve($cashierId, $_GET);
['types' => $types, 'type' => $type, 'page' => $page, 'recordId' => $recordId,
    'record' => $record, 'rows' => $rows, 'hasNext' => $hasNext,
    'pageSize' => $pageSize, 'totalRows' => $totalRows, 'totalPages' => $totalPages,
    'fromDay' => $fromDay, 'throughDay' => $throughDay, 'dateError' => $dateError] = $request;
$tabs = array_map(static fn(array $metadata): string => $metadata['label'], $types);
$columns = $types[$type]['columns'];
$idColumn = $types[$type]['id'];
$moneyColumns = ['total_amount', 'cash_received', 'change_due', 'refund_amount', 'amount', 'opening_cash', 'expected_cash', 'actual_cash', 'cash_variance'];
$display = static function (string $column, mixed $value) use ($moneyColumns): string {
    if ($value === null || $value === '') {
        return '—';
    }
    if (in_array($column, $moneyColumns, true)) {
        return '₱' . number_format((float)$value, 2);
    }
    if (in_array($column, ['sale_date', 'created_at', 'opened_at', 'closed_at'], true)) {
        return htmlspecialchars(PhilippineTime::format($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$paymentFormat = static function (?string $method): string {
    if ($method === null || $method === '') return '—';
    $normalized = strtolower(trim($method));
    return match ($normalized) {
        'cash' => 'Cash',
        'gcash' => 'GCash',
        'ewallet', 'e-wallet' => 'E-Wallet',
        'card' => 'Card',
        default => ucwords(str_replace(['_', '-'], ' ', $normalized)),
    };
};
$renderPayment = static function (mixed $value) use ($paymentFormat): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $raw = (string)$value;
    $norm = strtolower(trim($raw));
    $key = preg_replace('/[^a-z0-9]+/i', '-', $norm);
    $label = $paymentFormat($raw);
    return '<span class="transaction-payment payment-' . htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
};
$url = static fn(array $params): string => app_url('components/cashier/history.php?' . http_build_query($params + ['type' => $type, 'date_from' => $fromDay, 'date_to' => $throughDay, 'length' => $pageSize]));
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/sale-receipt.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/sale-receipt.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier-pages.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/cashier-pages.css')) ?>">
</head>
<body class="cashier-history-page">
<a class="skip-link" href="#history-main">Skip to main content</a>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content" id="history-main">
        <h1 class="sr-only">Transaction History</h1>
        <section class="history-workspace <?= htmlspecialchars($tabMeta[$type]['color'] ?? 'tab-sales') ?>" aria-label="Transaction history">
        <?php
        $tabMeta = [
            'sales' => [
                'icon' => 'bi-receipt-cutoff',
                'color' => 'tab-sales',
                'badge' => 'Sales',
                'empty_icon' => 'bi-receipt',
                'empty_title' => 'No sales recorded yet',
                'empty_desc' => 'Customer checkout transactions completed at Point of Sale will appear here automatically with totals, payment methods, and timestamps.',
                'action_label' => 'Open Point of Sale',
                'action_url' => app_url('components/cashier/pos.php'),
                'action_icon' => 'bi-cart-plus',
            ],
            'refunds' => [
                'icon' => 'bi-arrow-counterclockwise',
                'color' => 'tab-refunds',
                'badge' => 'Cash Refunds',
                'empty_icon' => 'bi-arrow-counterclockwise',
                'empty_title' => 'No cash refunds recorded',
                'empty_desc' => 'Approved customer return refunds and cash reimbursements processed during shifts will be tracked here with reasons and notes.',
                'action_label' => 'Point of Sale',
                'action_url' => app_url('components/cashier/pos.php'),
                'action_icon' => 'bi-calculator',
            ],
            'movements' => [
                'icon' => 'bi-arrow-left-right',
                'color' => 'tab-movements',
                'badge' => 'Drawer Movements',
                'empty_icon' => 'bi-arrow-left-right',
                'empty_title' => 'No drawer movements found',
                'empty_desc' => 'Cash register pay-ins, pay-outs, and safe drops recorded during your shifts will show up here with audit logs.',
                'action_label' => null,
                'action_url' => null,
                'action_icon' => null,
            ],
            'shifts' => [
                'icon' => 'bi-clock-history',
                'color' => 'tab-shifts',
                'badge' => 'Cashier Shifts',
                'empty_icon' => 'bi-clock-history',
                'empty_title' => 'No cashier shifts recorded',
                'empty_desc' => 'Opening cash counts, expected drawer totals, counted cash, and shift reconciliation reviews will be logged here.',
                'action_label' => 'Manage Cashier Shifts',
                'action_url' => app_url('components/cashier/shifts.php'),
                'action_icon' => 'bi-cash-stack',
            ],
        ];
        ?>
        <nav class="history-tabs" aria-label="History type">
            <?php foreach ($tabs as $key => $label): 
                $meta = $tabMeta[$key] ?? ['icon' => 'bi-folder2', 'color' => 'tab-default'];
                $isActive = ($key === $type);
            ?>
                <a class="history-tab <?= htmlspecialchars($meta['color']) ?>" href="<?= htmlspecialchars($url(['type' => $key])) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>><span class="history-tab-icon-wrap" aria-hidden="true"><i class="bi <?= htmlspecialchars($meta['icon']) ?>"></i></span><span class="history-tab-label"><?= htmlspecialchars($label) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <form method="GET" class="actions-row history-filters" aria-label="Filter operational history by Philippine date">
            <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
            <input type="hidden" name="length" value="<?= (int)$pageSize ?>">
            <label>From date <input type="date" name="date_from" value="<?= htmlspecialchars($fromDay ?? '') ?>"></label>
            <label>To date <input type="date" name="date_to" value="<?= htmlspecialchars($throughDay ?? '') ?>"></label>
            <button type="submit" class="btn">Apply dates</button>
            <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/history.php?' . http_build_query(['type' => $type]))) ?>">Clear dates</a>
            <p class="history-timezone"><i class="bi bi-clock" aria-hidden="true"></i> Philippine time &middot; Asia/Manila</p>
        </form>
        <?php if ($dateError !== ''): ?><p class="history-error" role="alert"><?= htmlspecialchars($dateError) ?></p><?php endif; ?>
        <?php if ($recordId !== null): ?>
            <p><a href="<?= htmlspecialchars($url(['type' => $type, 'page' => $page])) ?>">← Back to <?= htmlspecialchars($tabs[$type]) ?></a></p>
            <?php if ($record === null): ?>
                <p>This record is unavailable.</p>
            <?php else: ?>
                <div class="card history-details"><h2><?= htmlspecialchars($tabs[$type]) ?> #<?= (int)$record[$idColumn] ?></h2>
                    <dl><?php foreach ($columns as $column => $label): ?>
                        <dt><?= htmlspecialchars($label) ?></dt><dd><?= $column === 'payment_method' ? $renderPayment($record[$column] ?? null) : $display($column, $record[$column] ?? null) ?></dd>
                    <?php endforeach; ?></dl>
                    <?php 
                    $saleId = $record['sale_id'] ?? ($type === 'sales' ? $record[$idColumn] : null);
                    if ($saleId && ($type === 'sales' || !empty($record['sale_id']))): ?>
                        <div style="margin-top: 1.25rem;">
                            <button type="button" class="btn btn-primary" onclick="window.viewReceipt ? window.viewReceipt(<?= (int)$saleId ?>, this) : null">
                                <i class="bi bi-receipt" aria-hidden="true"></i> View receipt
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="history-results-heading">
                <div>
                    <h2>Transaction history</h2>
                    <span class="history-results-meta"><?= htmlspecialchars($tabs[$type]) ?> &middot; <?= count($rows) ?> <?= count($rows) === 1 ? 'record' : 'records' ?> on this page</span>
                </div>
                <span class="history-results-hint">Select Details button for audit breakdown</span>
            </div>
            <?php $moneyCols = $moneyColumns ?? ['total_amount', 'cash_received', 'change_due', 'refund_amount', 'amount', 'opening_cash', 'expected_cash', 'actual_cash', 'cash_variance']; ?>
            <div class="table-wrap history-table-wrap" role="region" aria-label="Operational history records" tabindex="0"><table data-no-smart-table><thead><tr>
                <?php foreach ($columns as $column => $label): 
                    $isMoneyCol = in_array($column, $moneyCols, true);
                    $isDateCol = in_array($column, ['sale_date', 'created_at', 'opened_at', 'closed_at'], true);
                ?>
                    <th scope="col" class="<?= $isMoneyCol ? 'th-money' : '' ?><?= $isDateCol ? ' th-date' : '' ?>"><?= htmlspecialchars($label) ?></th>
                <?php endforeach; ?>
                <th class="history-action-th text-end" scope="col">Action</th>
            </tr></thead><tbody>
                <?php foreach ($rows as $row): ?><tr>
                    <?php foreach ($columns as $column => $label): 
                        $val = $row[$column] ?? null;
                        $isId = ($column === $idColumn);
                        $isMoney = in_array($column, $moneyCols, true);
                        $isDate = in_array($column, ['sale_date', 'created_at', 'opened_at', 'closed_at'], true);
                        $isStatus = ($column === 'status');
                        $isPayment = ($column === 'payment_method');
                        $isNote = in_array($column, ['note', 'closing_notes', 'reason', 'intervention_reason'], true);
                    ?><td data-label="<?= htmlspecialchars($label) ?>" class="<?= $isId ? 'history-id-cell' : '' ?><?= $isMoney ? ' history-money-cell' : '' ?><?= $isDate ? ' history-date-cell' : '' ?><?= $isStatus ? ' history-status-cell' : '' ?><?= $isPayment ? ' history-payment-cell' : '' ?><?= $isNote ? ' history-note-cell' : '' ?>">
                        <?php if ($isId): ?>
                            <span class="history-id-plain">#<?= (int)$row[$idColumn] ?></span>
                        <?php elseif ($isStatus && $val !== null && $val !== ''): 
                            $statusKey = strtolower(str_replace(' ', '-', (string)$val));
                        ?>
                            <span class="history-status-pill status-<?= htmlspecialchars($statusKey) ?>"><?= $display($column, $val) ?></span>
                        <?php elseif ($isPayment): ?>
                            <?= $renderPayment($val) ?>
                        <?php else: ?><?= $display($column, $val) ?><?php endif; ?>
                    </td><?php endforeach; ?>
                    <td class="history-action-cell text-end">
                        <div class="history-row-actions">
                            <button type="button" class="btn btn-primary btn-sm history-open-drawer" data-record="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>" aria-label="Details for <?= htmlspecialchars($tabs[$type]) ?> #<?= (int)$row[$idColumn] ?>">
                                <i class="bi bi-eye" aria-hidden="true"></i> <span>Details</span>
                            </button>
                        </div>
                    </td>
                </tr><?php endforeach; ?>
                <?php 
                if (!empty($rows)) {
                    $targetRows = 6;
                    $ghostCount = max(0, $targetRows - count($rows));
                    $totalCols = count($columns) + 1;
                    for ($i = 0; $i < $ghostCount; $i++): ?>
                        <tr class="table-ghost-row" aria-hidden="true">
                            <?php for ($c = 0; $c < $totalCols; $c++): ?>
                                <td><?= $c === 0 ? '&nbsp;' : '' ?></td>
                            <?php endfor; ?>
                        </tr>
                    <?php endfor;
                }
                ?>
                <?php if (!$rows): 
                    $activeMeta = $tabMeta[$type] ?? [
                        'icon' => 'bi-inbox',
                        'color' => 'tab-default',
                        'badge' => $tabs[$type],
                        'empty_title' => 'No ' . strtolower($tabs[$type]) . ' recorded yet',
                        'empty_desc' => 'Operational records will show up here once recorded.',
                        'action_label' => null,
                        'action_url' => null,
                        'action_icon' => null,
                    ];
                    $isDateFiltered = ($fromDay !== null || $throughDay !== null);
                ?>
                <tr>
                    <td class="history-empty" colspan="<?= count($columns) + 1 ?>">
                        <div class="history-empty-card <?= htmlspecialchars($activeMeta['color']) ?>">
                            <div class="history-empty-icon-wrap" aria-hidden="true">
                                <i class="bi <?= htmlspecialchars($activeMeta['empty_icon'] ?? $activeMeta['icon']) ?>"></i>
                            </div>
                            <span class="history-empty-badge"><?= htmlspecialchars($activeMeta['badge']) ?></span>
                            <?php if ($isDateFiltered): ?>
                                <h3 class="history-empty-title">No <?= htmlspecialchars(strtolower($tabs[$type])) ?> found for selected dates</h3>
                                <p class="history-empty-desc">No records match the filter from <strong><?= htmlspecialchars($fromDay ?? 'start') ?></strong> to <strong><?= htmlspecialchars($throughDay ?? 'end') ?></strong>. Clear dates to view all transactions.</p>
                                <div class="history-empty-actions">
                                    <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('components/cashier/history.php?' . http_build_query(['type' => $type]))) ?>"><i class="bi bi-x-circle" aria-hidden="true"></i> Clear dates</a>
                                </div>
                            <?php else: ?>
                                <h3 class="history-empty-title"><?= htmlspecialchars($activeMeta['empty_title']) ?></h3>
                                <p class="history-empty-desc"><?= htmlspecialchars($activeMeta['empty_desc']) ?></p>
                                <?php if (!empty($activeMeta['action_label']) && !empty($activeMeta['action_url'])): ?>
                                    <div class="history-empty-actions">
                                        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($activeMeta['action_url']) ?>">
                                            <?php if (!empty($activeMeta['action_icon'])): ?><i class="bi <?= htmlspecialchars($activeMeta['action_icon']) ?>" aria-hidden="true"></i> <?php endif; ?><?= htmlspecialchars($activeMeta['action_label']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody></table></div>
            <?php $paginationFilters = ['type' => $type, 'date_from' => $fromDay ?? '', 'date_to' => $throughDay ?? '']; require __DIR__ . '/../../../backend/app/Views/table_pagination.php'; ?>
        <?php endif; ?>
        <dialog id="history-details-drawer" class="history-details-drawer" aria-labelledby="history-drawer-title">
            <div class="history-drawer-header">
                <div class="history-drawer-header-left">
                    <button type="button" class="history-drawer-back-btn" id="history-drawer-back-btn" aria-label="Back to details" hidden>
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    </button>
                    <div>
                        <span class="history-drawer-badge" id="history-drawer-type"><?= htmlspecialchars($tabs[$type]) ?></span>
                        <h2 id="history-drawer-title"><?= htmlspecialchars($tabs[$type]) ?> Details</h2>
                    </div>
                </div>
                <button type="button" class="history-drawer-close" id="history-drawer-close" aria-label="Close details">&times;</button>
            </div>
            <div class="drawer-view-stage" id="history-drawer-stage">
                <!-- View 1: Details -->
                <div class="drawer-view drawer-view-details is-active" id="history-drawer-view-details">
                    <div class="history-drawer-body">
                        <dl class="history-drawer-kv" id="history-drawer-fields"></dl>
                    </div>
                    <div class="history-drawer-footer">
                        <button type="button" class="btn btn-primary" id="history-drawer-receipt-btn" hidden><i class="bi bi-receipt" aria-hidden="true"></i> View receipt</button>
                        <button type="button" class="btn btn-secondary" id="history-drawer-dismiss">Close</button>
                    </div>
                </div>
                <!-- View 2: Receipt -->
                <div class="drawer-view drawer-view-receipt" id="history-drawer-view-receipt" hidden>
                    <div class="history-drawer-body history-drawer-receipt-body" id="history-drawer-receipt-body">
                        <div class="history-drawer-receipt-loading" id="history-drawer-receipt-loading" hidden>
                            <div class="drawer-spinner" aria-hidden="true"></div>
                            <span>Loading receipt…</span>
                        </div>
                        <div class="history-drawer-receipt-error" id="history-drawer-receipt-error" role="alert" hidden>
                            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                            <p id="history-drawer-error-msg">The receipt could not be loaded.</p>
                            <button type="button" class="btn btn-secondary btn-sm" id="history-drawer-retry-btn">Retry</button>
                        </div>
                        <div class="history-drawer-receipt-content" id="history-drawer-receipt-content"></div>
                    </div>
                    <div class="history-drawer-footer">
                        <button type="button" class="btn btn-secondary" id="history-drawer-return-btn"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to details</button>
                        <button type="button" class="btn btn-secondary" id="history-drawer-receipt-dismiss">Close</button>
                    </div>
                </div>
            </div>
        </dialog>
        </section>
    </main>
</div>
<script id="history-columns-json" type="application/json"><?= json_encode($columns ?? []) ?></script>
<script id="history-money-json" type="application/json"><?= json_encode($moneyColumns ?? []) ?></script>
<script>
(() => {
    const drawer = document.getElementById('history-details-drawer');
    if (!drawer) return;
    const columns = JSON.parse(document.getElementById('history-columns-json')?.textContent || '{}');
    const moneyColumns = JSON.parse(document.getElementById('history-money-json')?.textContent || '[]');
    const titleEl = document.getElementById('history-drawer-title');
    const typeBadgeEl = document.getElementById('history-drawer-type');
    const fieldsEl = document.getElementById('history-drawer-fields');
    const closeBtn = document.getElementById('history-drawer-close');
    const dismissBtn = document.getElementById('history-drawer-dismiss');
    const receiptDismissBtn = document.getElementById('history-drawer-receipt-dismiss');
    const receiptBtn = document.getElementById('history-drawer-receipt-btn');
    const backBtn = document.getElementById('history-drawer-back-btn');
    const returnBtn = document.getElementById('history-drawer-return-btn');
    const retryBtn = document.getElementById('history-drawer-retry-btn');
    const viewDetails = document.getElementById('history-drawer-view-details');
    const viewReceipt = document.getElementById('history-drawer-view-receipt');
    const receiptBody = document.getElementById('history-drawer-receipt-body');
    const receiptContent = document.getElementById('history-drawer-receipt-content');
    const receiptLoading = document.getElementById('history-drawer-receipt-loading');
    const receiptError = document.getElementById('history-drawer-receipt-error');
    const receiptErrorMsg = document.getElementById('history-drawer-error-msg');

    const currentType = <?= json_encode($type) ?>;
    const typeLabel = <?= json_encode($tabs[$type] ?? 'Transaction') ?>;
    const idCol = <?= json_encode($idColumn) ?>;
    const receiptEndpoint = <?= json_encode(app_url('components/invoice/sales.php?action=view&ajax=1')) ?>;

    let currentRecord = null;
    let activeSaleId = null;
    let currentView = 'details';
    let isAnimating = false;
    let pendingFetch = null;
    let lastTrigger = null;

    const switchView = (targetView, animated = true) => {
        if (currentView === targetView) return;
        const outgoing = currentView === 'details' ? viewDetails : viewReceipt;
        const incoming = targetView === 'details' ? viewDetails : viewReceipt;
        const isForward = targetView === 'receipt';
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (!animated || prefersReducedMotion) {
            outgoing.classList.remove('is-active', 'anim-slide-out-left', 'anim-slide-out-right');
            outgoing.hidden = true;
            incoming.hidden = false;
            incoming.classList.remove('anim-slide-in-left', 'anim-slide-in-right');
            incoming.classList.add('is-active');
            currentView = targetView;
            return;
        }

        if (isAnimating) return;
        isAnimating = true;

        const outClass = isForward ? 'anim-slide-out-left' : 'anim-slide-out-right';
        const inClass = isForward ? 'anim-slide-in-right' : 'anim-slide-in-left';

        incoming.hidden = false;
        incoming.classList.add(inClass);
        outgoing.classList.add(outClass);

        setTimeout(() => {
            outgoing.classList.remove('is-active', outClass);
            outgoing.hidden = true;
            incoming.classList.remove(inClass);
            incoming.classList.add('is-active');
            isAnimating = false;
            currentView = targetView;
        }, 240);
    };

    const loadReceiptHtml = async (saleId, autoPrint = false) => {
        if (pendingFetch) {
            pendingFetch.abort();
            pendingFetch = null;
        }
        const controller = new AbortController();
        pendingFetch = controller;

        receiptLoading.hidden = false;
        receiptError.hidden = true;
        receiptContent.replaceChildren();

        try {
            const url = new URL(receiptEndpoint, window.location.href);
            url.searchParams.set('sale_id', Number(saleId));
            const response = await fetch(url.toString(), {
                signal: controller.signal,
                headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) throw new Error('Receipt unavailable: HTTP ' + response.status);
            const html = await response.text();
            if (pendingFetch !== controller) return;
            receiptLoading.hidden = true;
            receiptContent.innerHTML = html;
            if (autoPrint) {
                requestAnimationFrame(() => {
                    const printBtn = receiptContent.querySelector('.receipt-actions button');
                    if (printBtn && typeof window.printReceiptSection === 'function') {
                        window.printReceiptSection(printBtn);
                    }
                });
            }
        } catch (err) {
            if (err.name === 'AbortError') return;
            receiptLoading.hidden = true;
            receiptError.hidden = false;
            if (receiptErrorMsg) receiptErrorMsg.textContent = 'Unable to load receipt for sale #' + saleId + '. Please try again.';
        } finally {
            if (pendingFetch === controller) pendingFetch = null;
        }
    };

    const openDrawer = (record, trigger) => {
        if (!record) return;
        currentRecord = record;
        lastTrigger = trigger || null;

        // Reset to details view instantly
        switchView('details', false);
        if (backBtn) backBtn.hidden = true;
        if (typeBadgeEl) typeBadgeEl.textContent = typeLabel;
        if (titleEl) titleEl.textContent = `${typeLabel} #${record[idCol] ?? ''}`;

        if (fieldsEl) {
            fieldsEl.replaceChildren();
            for (const [col, label] of Object.entries(columns)) {
                const dt = document.createElement('dt');
                dt.textContent = label;
                const dd = document.createElement('dd');
                const rawVal = record[col];
                if (rawVal === null || rawVal === undefined || rawVal === '') {
                    dd.textContent = '—';
                } else if (moneyColumns.includes(col)) {
                    const num = Number(rawVal);
                    dd.textContent = isNaN(num) ? String(rawVal) : '₱' + num.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                } else if (col === 'payment_method') {
                    const span = document.createElement('span');
                    const norm = String(rawVal).toLowerCase().trim();
                    const cleanKey = norm.replace(/[^a-z0-9]+/g, '-');
                    span.className = `transaction-payment payment-${cleanKey}`;
                    let label = norm.replace(/[_-]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                    if (norm === 'gcash') label = 'GCash';
                    else if (norm === 'ewallet' || norm === 'e-wallet') label = 'E-Wallet';
                    span.textContent = label;
                    dd.appendChild(span);
                } else {
                    dd.textContent = String(rawVal);
                }
                fieldsEl.append(dt, dd);
            }
        }

        const saleId = record.sale_id ?? (currentType === 'sales' ? record[idCol] : null);
        activeSaleId = saleId;
        if (receiptBtn) {
            if (saleId && (currentType === 'sales' || record.sale_id)) {
                receiptBtn.hidden = false;
                receiptBtn.onclick = () => openReceiptView(Number(saleId), receiptBtn);
            } else {
                receiptBtn.hidden = true;
                receiptBtn.onclick = null;
            }
        }

        if (!drawer.open) drawer.showModal();
    };

    const openReceiptView = (saleId, trigger, autoPrint = false) => {
        if (!saleId) return;
        activeSaleId = Number(saleId);
        lastTrigger = trigger || lastTrigger || null;

        if (!drawer.open) {
            switchView('receipt', false);
            drawer.showModal();
        } else {
            switchView('receipt', true);
        }

        if (backBtn) backBtn.hidden = !currentRecord;
        if (returnBtn) returnBtn.hidden = !currentRecord;
        if (typeBadgeEl) typeBadgeEl.textContent = 'Sale Receipt';
        if (titleEl) titleEl.textContent = `Sale Receipt #${activeSaleId}`;

        loadReceiptHtml(activeSaleId, autoPrint);
    };

    const returnToDetails = () => {
        if (!currentRecord) {
            drawer.close();
            return;
        }
        if (typeBadgeEl) typeBadgeEl.textContent = typeLabel;
        if (titleEl) titleEl.textContent = `${typeLabel} #${currentRecord[idCol] ?? ''}`;
        if (backBtn) backBtn.hidden = true;

        switchView('details', true);
        receiptBtn?.focus();
    };

    const closeDrawer = () => {
        if (pendingFetch) {
            pendingFetch.abort();
            pendingFetch = null;
        }
        drawer.close();
        if (lastTrigger && typeof lastTrigger.focus === 'function') {
            lastTrigger.focus();
        }
    };

    backBtn?.addEventListener('click', returnToDetails);
    returnBtn?.addEventListener('click', returnToDetails);
    retryBtn?.addEventListener('click', () => {
        if (activeSaleId) loadReceiptHtml(activeSaleId);
    });

    closeBtn?.addEventListener('click', closeDrawer);
    dismissBtn?.addEventListener('click', closeDrawer);
    receiptDismissBtn?.addEventListener('click', closeDrawer);
    drawer.addEventListener('click', e => {
        if (e.target === drawer) closeDrawer();
    });

    document.querySelectorAll('.history-open-drawer').forEach(btn => {
        btn.addEventListener('click', e => {
            const raw = btn.getAttribute('data-record');
            if (raw) {
                e.preventDefault();
                try { openDrawer(JSON.parse(raw), btn); } catch (_) {}
            }
        });
    });

    window.viewReceiptInDrawer = openReceiptView;
    window.viewReceipt = (saleId, trigger, autoPrint = false) => {
        openReceiptView(saleId, trigger, autoPrint);
    };
})();
</script>
<?php $selected_sale = null; require __DIR__ . '/../../../backend/app/Views/sale_receipt_dialog.php'; ?>
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/sale-receipt.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt-dialog.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/sale-receipt-dialog.js')) ?>"></script>
</body>
</html>
