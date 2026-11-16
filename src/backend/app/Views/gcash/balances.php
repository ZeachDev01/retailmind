<?php
$funds = $overview['recorded_balances'] ?? null;
$balancesEmpty = $funds !== null && $funds['service_cash'] === null && $funds['wallet_balance'] === null;
?>
<section class="pos-panel gcash-overview-section gcash-funds-panel" aria-labelledby="gcash-admin-balances-title" data-fragment="balances">
    <div class="pos-panel-header">
        <div class="pos-panel-title">
            <div>
                <h2 id="gcash-admin-balances-title">Service balances</h2>
            </div>
        </div>
        <span class="gcash-badge gcash-badge-neutral">Outside report filters</span>
    </div>

    <?php if ($balancesEmpty): ?>
    <div class="gcash-empty-state">
        <i class="bi bi-wallet2" aria-hidden="true"></i>
        <h3>No service balances yet</h3>
        <p>Balances appear after a GCash session starts.</p>
    </div>
    <?php endif; ?>
    <div class="gcash-overview-grid gcash-balances-grid" <?= $balancesEmpty ? 'hidden' : '' ?>>
        <?php foreach (['cash' => ['Service cash', 'service_cash'], 'wallet' => ['GCash wallet', 'wallet_balance']] as $key => [$label, $amountKey]):
            $amount = $funds[$amountKey] ?? null;
            $evidenceLabel = $funds === null ? 'Unavailable' : ($amount === null ? '' : ($funds['state'] === 'expected' ? 'Expected' : 'Last counted'));
        ?>
        <div class="gcash-card gcash-card-balance gcash-balance-card-<?= $key ?>">
            <div class="gcash-card-header">
                <span class="gcash-card-label"><?= $label ?></span>
                <span class="gcash-badge gcash-badge-expected" id="admin-<?= $key ?>-state" <?= $funds !== null && $amount === null ? 'hidden' : '' ?>><?= $evidenceLabel ?></span>
            </div>
            <div class="gcash-card-value" id="admin-expected-<?= $key ?>"><?= $amount === null ? '—' : gcash_admin_money($amount) ?></div>
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
        <span>Total funds</span>
        <div class="gcash-combined-amount"><?= gcash_admin_money($combinedFunds) ?></div>
    </div>
    <?php endif; ?>
    <dl class="gcash-fund-details" id="admin-cash-checked" <?= $balancesEmpty ? 'hidden' : '' ?>>
        <div><dt>Cashier</dt><dd><?= gcash_admin_e($funds['cashier_name'] ?? '—') ?></dd></div>
        <div><dt>Last check</dt><dd><?= gcash_admin_e($funds['checked_at_formatted'] ?? '—') ?></dd></div>
    </dl>
</section>
