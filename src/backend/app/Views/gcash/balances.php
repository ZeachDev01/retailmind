<?php $funds = $overview['recorded_balances'] ?? null; ?>
<section class="pos-panel gcash-overview-section gcash-funds-panel" aria-labelledby="gcash-admin-balances-title" data-fragment="balances">
    <div class="pos-panel-header">
        <div class="pos-panel-title">
            <div class="pos-panel-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
            <div>
                <h2 id="gcash-admin-balances-title">Service balances</h2>
            </div>
        </div>
        <span class="gcash-badge gcash-badge-neutral"><i class="bi bi-shield-lock" aria-hidden="true"></i> Current Session</span>
    </div>

    <div class="gcash-overview-grid gcash-balances-grid">
        <?php foreach (['cash' => ['Service cash', 'service_cash'], 'wallet' => ['GCash wallet', 'wallet_balance']] as $key => [$label, $amountKey]):
            $amount = $funds[$amountKey] ?? null;
            $evidenceLabel = $funds === null ? 'Unavailable' : ($amount === null ? 'Not recorded' : ($funds['state'] === 'expected' ? 'Expected' : 'Last counted'));
            $subLabel = $key === 'cash' ? 'Cash in Drawer' : 'GCash App Wallet';
            $hint = $key === 'cash' ? 'Physical cash in cashier drawer' : 'Digital balance in merchant app';
            $icon = $key === 'cash' ? 'bi-cash-stack' : 'bi-phone';
        ?>
        <div class="gcash-card gcash-card-balance gcash-balance-card-<?= $key ?>">
            <div class="gcash-card-header">
                <div class="gcash-balance-header-wrap">
                    <span class="gcash-balance-icon-tile gcash-icon-<?= $key ?>"><i class="bi <?= $icon ?>" aria-hidden="true"></i></span>
                    <div>
                        <span class="gcash-card-label"><?= $label ?></span>
                        <span class="gcash-card-friendly-tag"><?= $subLabel ?></span>
                    </div>
                </div>
                <span class="gcash-badge gcash-badge-expected" id="admin-<?= $key ?>-state"><?= $evidenceLabel ?></span>
            </div>
            <div class="gcash-card-value" id="admin-expected-<?= $key ?>"><?= $amount === null ? '—' : gcash_admin_money($amount) ?></div>
            <span class="gcash-card-explain"><i class="bi bi-info-circle" aria-hidden="true"></i> <?= $hint ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    $hasCash = ($funds['service_cash'] ?? null) !== null;
    $hasWallet = ($funds['wallet_balance'] ?? null) !== null;
    $combinedFunds = ($hasCash && $hasWallet) ? (float)$funds['service_cash'] + (float)$funds['wallet_balance'] : null;
    ?>
    <?php if ($combinedFunds !== null): ?>
    <div class="gcash-combined-pool">
        <div class="gcash-combined-left">
            <span class="gcash-combined-icon"><i class="bi bi-piggy-bank" aria-hidden="true"></i></span>
            <div>
                <strong class="gcash-combined-title">Total Service Capital</strong>
                <span class="gcash-combined-sub">Drawer Cash + GCash App Balance combined</span>
            </div>
        </div>
        <div class="gcash-combined-amount"><?= gcash_admin_money($combinedFunds) ?></div>
    </div>
    <?php endif; ?>
    <details class="gcash-fund-details">
        <summary>Balance check &amp; cashier</summary>
        <dl id="admin-cash-checked">
            <dt>Last actual check</dt><dd><?= gcash_admin_e($funds['checked_at_formatted'] ?? 'Not recorded') ?></dd>
            <dt>Responsible cashier</dt><dd><?= gcash_admin_e($funds['cashier_name'] ?? 'Not recorded') ?></dd>
        </dl>
    </details>
</section>
