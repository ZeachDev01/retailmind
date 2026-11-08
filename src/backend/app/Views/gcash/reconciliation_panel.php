<section class="pos-panel gcash-overview-section" aria-labelledby="gcash-admin-diff-title" data-fragment="reconciliation">
    <div class="pos-panel-header">
        <div class="pos-panel-title">
            <div class="pos-panel-icon gcash-icon-alert"><i class="bi bi-exclamation-diamond" aria-hidden="true"></i></div>
            <div>
                <h2 id="gcash-admin-diff-title">Closing checks</h2>
            </div>
        </div>
        <?php $diffCount = (int)($overview['differences_count'] ?? 0); ?>
        <?php if ($diffCount > 0): ?>
            <span class="gcash-badge gcash-badge-attention"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> <?= $diffCount ?> pending review</span>
        <?php endif; ?>
    </div>
    <p class="gcash-section-description">Mark reviewed once you have investigated. The difference stays on record.</p>
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
                        <td colspan="8" class="gcash-empty-msg gcash-empty-cell">
                            <div class="gcash-empty-inner">
                                <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                                <p>No reconciliation differences recorded.</p>
                                <span class="gcash-empty-sub">All cashier shift balances matched expected cash and wallet amounts exactly.</span>
                            </div>
                        </td>
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
                                    <span class="gcash-status gcash-status-completed"><i class="bi bi-check2" aria-hidden="true"></i> Reviewed</span>
                                    <details class="gcash-row-details">
                                        <summary>Review details</summary>
                                        <dl>
                                            <dt>Reviewed by</dt><dd><?= gcash_admin_e($diff['waived_by_name'] ?? 'Admin') ?></dd>
                                            <dt>Reason</dt><dd><?= gcash_admin_e($diff['waiver_notes']) ?></dd>
                                        </dl>
                                    </details>
                                <?php else: ?>
                                    <span class="gcash-status gcash-status-needs-checking"><i class="bi bi-clock-history" aria-hidden="true"></i> Pending review</span>
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
</section>
