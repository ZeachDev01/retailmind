<?php
$v = $view ?? \App\Receipts\ReceiptEngine::prepareGCashViewData($receipt ?? [], $paperWidthMm ?? 80);
?>
<div class="receipt-container">
    <div class="checkout-complete-banner"><div class="checkout-complete-copy"><strong>GCash exchange completed</strong><span>Saved GCash Service Receipt #<?= (int)$v['transaction_id'] ?></span></div></div>
    <div class="receipt-actions no-print">
        <button type="button" class="btn" onclick="printReceiptSection(this)">Print Receipt</button>
        <button type="button" class="btn btn-secondary" onclick="closeReceiptModal()">Return to GCash</button>
    </div>
    <div class="sale-receipt gcash-service-receipt receipt-print-area" data-paper-width-mm="<?= $v['paper_width_mm'] ?>" aria-label="GCash Service Receipt">
        <div class="receipt-header">
            <div class="receipt-brand">
                <?php if ($v['store']['logo'] !== ''): ?><img class="receipt-logo" src="<?= $v['store']['logo'] ?>" alt="Store logo"><?php endif; ?>
                <?php if ($v['store']['name'] !== ''): ?><h1><?= $v['store']['name'] ?></h1><?php endif; ?>
                <div>GCash Service Receipt</div>
                <?php foreach (['address', 'contact', 'tin'] as $key): ?><div><?= $v['store'][$key] ?></div><?php endforeach; ?>
            </div>
            <div class="receipt-meta">
                <div>Transaction #<?= (int)$v['transaction_id'] ?> · <?= $v['transaction_type'] ?></div>
                <div>Completed: <?= $v['completed_at_formatted'] ?></div>
                <div>Cashier: <?= $v['cashier_name'] ?></div>
                <div>Register: <?= $v['register_name'] ?></div>
            </div>
        </div>
        <div class="sale-receipt-totals">
            <div><span>Principal</span><strong class="sale-receipt-money"><?= $v['principal_amount_formatted'] ?></strong></div>
            <div><span>Service fee</span><strong class="sale-receipt-money"><?= $v['fee_amount_formatted'] ?></strong></div>
            <div><span>Fee method</span><strong><?= $v['fee_method'] ?></strong></div>
            <?php foreach (['cash_collected' => 'Cash collected', 'cash_paid_out' => 'Cash paid out', 'wallet_sent' => 'Wallet sent to customer', 'wallet_received' => 'Wallet received from customer'] as $key => $label): ?>
                <?php if ($v['amounts'][$key] !== '0.00'): ?><div><span><?= $label ?></span><strong class="sale-receipt-money"><?= $v['amounts'][$key . '_formatted'] ?></strong></div><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="gcash-receipt-reference">
            <div>GCash reference</div>
            <?= $v['barcode_svg'] ?>
            <div class="gcash-receipt-digits"><?= $v['reference_number'] ?></div>
        </div>
        <div class="sale-receipt-footer"><?= $v['store']['footer'] ?></div>
    </div>
</div>
