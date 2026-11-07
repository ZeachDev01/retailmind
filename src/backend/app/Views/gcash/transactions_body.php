<tbody id="gcash-admin-history-body" data-fragment="transactions-body">
    <?php if ($overview === null): ?>
        <tr><td colspan="9" role="alert">Transaction history unavailable for this selection. Try again.</td></tr>
    <?php elseif (empty($overview['transactions'])): ?>
        <tr>
            <td colspan="9" class="gcash-empty-msg gcash-empty-cell">No transactions match these filters.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($overview['transactions'] as $tx): ?>
            <tr tabindex="0" data-transaction="<?= gcash_admin_e(json_encode($tx, JSON_INVALID_UTF8_SUBSTITUTE)) ?>">
                <td><?= gcash_admin_e($tx['time_formatted'] ?? $tx['created_at_formatted'] ?? $tx['created_at']) ?></td>
                <td><code><?= gcash_admin_e($tx['reference_number'] ?: '—') ?></code></td>
                <td>
                    <span class="gcash-type-badge gcash-type-<?= strtolower(str_replace('-', '', $tx['transaction_type'])) ?>">
                        <i class="bi <?= $tx['transaction_type'] === 'Cash-In' ? 'bi-arrow-down-left' : ($tx['transaction_type'] === 'Cash-Out' ? 'bi-arrow-up-right' : 'bi-arrow-left-right') ?>" aria-hidden="true"></i>
                        <?= gcash_admin_e($tx['transaction_type']) ?>
                    </span>
                </td>
                <td><strong><?= gcash_admin_money($tx['principal_amount']) ?></strong></td>
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
                        <div class="gcash-movement-line"><span class="gcash-movement-label">Cash:</span> <span class="<?= $tx['service_cash_delta'] > 0 ? 'gcash-delta-positive' : ($tx['service_cash_delta'] < 0 ? 'gcash-delta-negative' : '') ?>"><?= ($tx['service_cash_delta'] > 0 ? '+' : '') . gcash_admin_money($tx['service_cash_delta']) ?></span></div>
                        <div class="gcash-movement-line"><span class="gcash-movement-label">Wallet:</span> <span class="<?= $tx['wallet_delta'] > 0 ? 'gcash-delta-positive' : ($tx['wallet_delta'] < 0 ? 'gcash-delta-negative' : '') ?>"><?= ($tx['wallet_delta'] > 0 ? '+' : '') . gcash_admin_money($tx['wallet_delta']) ?></span></div>
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
