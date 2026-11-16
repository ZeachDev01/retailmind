<?php $activityEmpty = $overview !== null && (int)$overview['completed_exchange_count'] === 0; ?>
<div class="pos-panel gcash-overview-section" id="gcash-completed-activity" role="group" aria-label="Completed activity" data-fragment="activity-metrics">
    <div class="pos-panel-header" <?= $activityEmpty ? 'hidden' : '' ?>>
        <div class="gcash-activity-header-badges">
            <?php
            $totalGrossFees = ($overview !== null && isset($overview['gross_fees_cash'], $overview['gross_fees_wallet']))
                ? (float)$overview['gross_fees_cash'] + (float)$overview['gross_fees_wallet']
                : null;
            ?>
            <?php if ($totalGrossFees !== null): ?>
                <span class="gcash-badge gcash-badge-revenue" title="Fees before returns for the selected report range">
                    Gross fees: <strong><?= gcash_admin_money($totalGrossFees) ?></strong>
                </span>
            <?php endif; ?>
            <span class="gcash-badge gcash-total-badge" id="admin-total-tx-badge">
                Completed exchanges: <strong id="admin-metric-total-tx"><?= $overview === null ? 'Unavailable' : (int)$overview['completed_exchange_count'] ?></strong>
            </span>
        </div>
    </div>
    <div class="gcash-overview-grid gcash-metrics-grid" <?= $activityEmpty ? 'hidden' : '' ?>>
        <div class="gcash-card gcash-metric-card gcash-metric-cashin">
            <div class="gcash-metric-header">
                <span class="gcash-metric-icon-tile gcash-icon-cashin"><i class="bi bi-arrow-down-left" aria-hidden="true"></i></span>
                <div class="gcash-metric-labels">
                    <span class="gcash-card-label">Cash-in</span>
                </div>
            </div>
            <div class="gcash-card-value" id="admin-metric-cash-in-principal"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['cash_in_principal']) ?></div>
        </div>
        <div class="gcash-card gcash-metric-card gcash-metric-cashout">
            <div class="gcash-metric-header">
                <span class="gcash-metric-icon-tile gcash-icon-cashout"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
                <div class="gcash-metric-labels">
                    <span class="gcash-card-label">Cash-out</span>
                </div>
            </div>
            <div class="gcash-card-value" id="admin-metric-cash-out-principal"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['cash_out_principal']) ?></div>
        </div>
        <div class="gcash-card gcash-metric-card gcash-metric-cashfees">
            <div class="gcash-metric-header">
                <span class="gcash-metric-icon-tile gcash-icon-cashfee"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
                <div class="gcash-metric-labels">
                    <span class="gcash-card-label">Cash fees</span>
                </div>
            </div>
            <div class="gcash-card-value gcash-income-value" id="admin-metric-gross-fees-cash"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['gross_fees_cash']) ?></div>
        </div>
        <div class="gcash-card gcash-metric-card gcash-metric-walletfees">
            <div class="gcash-metric-header">
                <span class="gcash-metric-icon-tile gcash-icon-walletfee"><i class="bi bi-wallet2" aria-hidden="true"></i></span>
                <div class="gcash-metric-labels">
                    <span class="gcash-card-label">Wallet fees</span>
                </div>
            </div>
            <div class="gcash-card-value gcash-income-value" id="admin-metric-gross-fees-wallet"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['gross_fees_wallet']) ?></div>
        </div>
    </div>
    <div class="gcash-empty-state gcash-activity-empty" <?= !$activityEmpty ? 'hidden' : '' ?>>
        <i class="bi bi-receipt" aria-hidden="true"></i>
        <h3>No completed exchanges for this period</h3>
        <p>Try a different date range or cashier.</p>
        <a class="btn btn-secondary" href="<?= gcash_admin_e(app_url('components/administrator/gcash_overview.php?tab=overview')) ?>">Reset filters</a>
    </div>
    <div class="gcash-activity-footer">
        <details class="gcash-totals-help" <?= $activityEmpty ? 'hidden' : '' ?>>
            <summary>About these totals</summary>
            <p>Cash-in and cash-out amounts exclude fees. Fees are shown before fee returns.</p>
        </details>
        <button type="button" class="btn btn-secondary gcash-view-tx-btn" data-review-tab="transactions">View transactions<i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    </div>
</div>
