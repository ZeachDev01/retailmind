<section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-nc-title" data-fragment="unresolved">
    <div class="pos-panel-header">
        <div class="pos-panel-title">
            <div class="pos-panel-icon gcash-icon-alert"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
            <div>
                <h2 id="gcash-admin-nc-title">Unresolved exchanges</h2>
            </div>
        </div>
        <div class="gcash-panel-meta">
            <span class="gcash-badge <?= !empty($overview['unresolved_activity']) ? 'gcash-status-needs-checking' : '' ?>" id="admin-nc-count-badge">
                Pending: <strong id="admin-nc-count"><?= $overview === null ? 'Unavailable' : (int)$overview['unresolved_count'] ?></strong>
            </span>
        </div>
    </div>
    <div class="gcash-tab-guide">
        <div class="gcash-tab-guide-icon gcash-guide-warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
        <div class="gcash-tab-guide-content">
            <strong class="gcash-tab-guide-title">Interrupted or Unverified Customer Exchanges</strong>
            <span class="gcash-tab-guide-desc">Exchanges flagged during cashier shifts where network or cash transfers were incomplete. Check customer GCash SMS/Reference and physical drawer cash, then click <strong>Verify &amp; Clear</strong>.</span>
        </div>
    </div>
    <p class="gcash-section-description">Clear an exchange once its cash and wallet movements check out. Cancel failed exchanges in the Cashier workspace.</p>
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
                        <td colspan="9" class="gcash-empty-msg gcash-empty-cell">
                            <div class="gcash-empty-inner">
                                <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                                <p>No unresolved activity.</p>
                                <span class="gcash-empty-sub">All transactions are fully confirmed and accounted for.</span>
                            </div>
                        </td>
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
                                <div class="gcash-movement-line"><span class="gcash-movement-label">Cash:</span> <span class="<?= !$nc['is_legacy'] && $nc['service_cash_delta'] > 0 ? 'gcash-delta-positive' : (!$nc['is_legacy'] && $nc['service_cash_delta'] < 0 ? 'gcash-delta-negative' : '') ?>"><?= $nc['is_legacy'] ? 'Not recorded' : ($nc['service_cash_delta'] > 0 ? '+' : '') . gcash_admin_money($nc['service_cash_delta']) ?></span></div>
                                <div class="gcash-movement-line"><span class="gcash-movement-label">Wallet:</span> <span class="<?= !$nc['is_legacy'] && $nc['wallet_delta'] > 0 ? 'gcash-delta-positive' : (!$nc['is_legacy'] && $nc['wallet_delta'] < 0 ? 'gcash-delta-negative' : '') ?>"><?= $nc['is_legacy'] ? 'Not recorded' : ($nc['wallet_delta'] > 0 ? '+' : '') . gcash_admin_money($nc['wallet_delta']) ?></span></div>
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
</section>
