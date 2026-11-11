<section class="pos-panel gcash-overview-section" id="gcash-completed-activity" aria-labelledby="gcash-admin-metrics-title" data-fragment="activity-metrics">
    <div class="pos-panel-header">
        <div class="pos-panel-title">
            <div>
                <h2 id="gcash-admin-metrics-title">Completed activity</h2>
            </div>
        </div>
        <div class="gcash-activity-header-badges">
            <?php
            $totalGrossFees = ($overview !== null && isset($overview['gross_fees_cash'], $overview['gross_fees_wallet']))
                ? (float)$overview['gross_fees_cash'] + (float)$overview['gross_fees_wallet']
                : null;
            ?>
            <?php if ($totalGrossFees !== null): ?>
                <span class="gcash-badge gcash-badge-revenue" title="Total convenience fees earned today">
                    <i class="bi bi-cash-coin" aria-hidden="true"></i> Total Fees Earned: <strong><?= gcash_admin_money($totalGrossFees) ?></strong>
                </span>
            <?php endif; ?>
            <span class="gcash-badge gcash-total-badge" id="admin-total-tx-badge">
                Completed exchanges: <strong id="admin-metric-total-tx"><?= $overview === null ? 'Unavailable' : (int)$overview['completed_exchange_count'] ?></strong>
            </span>
        </div>
    </div>
    <div class="gcash-overview-grid gcash-metrics-grid">
        <div class="gcash-card gcash-metric-card gcash-metric-cashin">
            <div class="gcash-metric-header">
                <span class="gcash-metric-icon-tile gcash-icon-cashin"><i class="bi bi-arrow-down-left" aria-hidden="true"></i></span>
                <div class="gcash-metric-labels">
                    <span class="gcash-card-label">Cash-in amount</span>
                    <span class="gcash-flow-tag gcash-flow-in">+ Drawer / - Wallet</span>
                </div>
            </div>
            <div class="gcash-card-value" id="admin-metric-cash-in-principal"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['cash_in_principal']) ?></div>
            <span class="gcash-card-explain"><i class="bi bi-phone" aria-hidden="true"></i> Loaded to customer’s GCash</span>
        </div>
        <div class="gcash-card gcash-metric-card gcash-metric-cashout">
            <div class="gcash-metric-header">
                <span class="gcash-metric-icon-tile gcash-icon-cashout"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
                <div class="gcash-metric-labels">
                    <span class="gcash-card-label">Cash-out amount</span>
                    <span class="gcash-flow-tag gcash-flow-out">- Drawer / + Wallet</span>
                </div>
            </div>
            <div class="gcash-card-value" id="admin-metric-cash-out-principal"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['cash_out_principal']) ?></div>
            <span class="gcash-card-explain"><i class="bi bi-cash" aria-hidden="true"></i> Encashed to customer from drawer</span>
        </div>
        <div class="gcash-card gcash-metric-card gcash-metric-cashfees">
            <div class="gcash-metric-header">
                <span class="gcash-metric-icon-tile gcash-icon-cashfee"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
                <div class="gcash-metric-labels">
                    <span class="gcash-card-label">Cash fees</span>
                    <span class="gcash-fee-tag gcash-fee-tag-cash">Drawer Income</span>
                </div>
            </div>
            <div class="gcash-card-value gcash-income-value" id="admin-metric-gross-fees-cash"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['gross_fees_cash']) ?></div>
            <span class="gcash-card-explain"><i class="bi bi-check2" aria-hidden="true"></i> Convenience fees collected in cash</span>
        </div>
        <div class="gcash-card gcash-metric-card gcash-metric-walletfees">
            <div class="gcash-metric-header">
                <span class="gcash-metric-icon-tile gcash-icon-walletfee"><i class="bi bi-wallet2" aria-hidden="true"></i></span>
                <div class="gcash-metric-labels">
                    <span class="gcash-card-label">Wallet fees</span>
                    <span class="gcash-fee-tag gcash-fee-tag-wallet">App Income</span>
                </div>
            </div>
            <div class="gcash-card-value gcash-income-value" id="admin-metric-gross-fees-wallet"><?= $overview === null ? 'Unavailable' : gcash_admin_money($overview['gross_fees_wallet']) ?></div>
            <span class="gcash-card-explain"><i class="bi bi-check2" aria-hidden="true"></i> Convenience fees inside GCash app</span>
        </div>
    </div>
    <div class="gcash-activity-footer">
        <details class="gcash-totals-help">
            <summary>About these totals</summary>
            <p>Cash-in and cash-out amounts exclude fees. Fees are shown before fee returns.</p>
        </details>
        <button type="button" class="btn btn-secondary gcash-view-tx-btn" data-review-tab="transactions">View transactions<i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    </div>
</section>
