<div class="receipt-container">
    <div class="checkout-complete-banner"><div class="checkout-complete-copy"><strong>GCash exchange completed</strong><span>Saved GCash Service Receipt #<?= (int)$receipt['transaction_id'] ?></span></div></div>
    <div class="receipt-actions no-print">
        <button type="button" class="btn" onclick="printReceiptSection(this)">Print Receipt</button>
        <button type="button" class="btn btn-secondary" onclick="closeReceiptModal()">Return to GCash</button>
    </div>
    <div class="sale-receipt gcash-service-receipt receipt-print-area" data-paper-width-mm="<?= $paperWidthMm ?>" aria-label="GCash Service Receipt">
        <div class="receipt-header">
            <div class="receipt-brand">
                <h1><?= $escape($store['name']) ?></h1>
                <div>GCash Service Receipt</div>
                <?php foreach (['address', 'contact', 'tin'] as $key): ?><div><?= $escape($store[$key]) ?></div><?php endforeach; ?>
            </div>
            <div class="receipt-meta">
                <div>Transaction #<?= (int)$receipt['transaction_id'] ?> · <?= $escape($receipt['transaction_type']) ?></div>
                <div>Completed: <?= $escape(\App\Services\PhilippineTime::format($receipt['completed_at'])) ?></div>
                <div>Cashier: <?= $escape($receipt['cashier_name']) ?></div>
                <div>Register: <?= $escape($receipt['register_name']) ?></div>
            </div>
        </div>
        <div class="sale-receipt-totals">
            <div><span>Principal</span><strong class="sale-receipt-money"><?= $money($receipt['principal_amount']) ?></strong></div>
            <div><span>Service fee</span><strong class="sale-receipt-money"><?= $money($receipt['fee_amount']) ?></strong></div>
            <div><span>Fee method</span><strong><?= $escape($receipt['fee_method']) ?></strong></div>
            <?php foreach (['cash_collected' => 'Cash collected', 'cash_paid_out' => 'Cash paid out', 'wallet_sent' => 'Wallet sent to customer', 'wallet_received' => 'Wallet received from customer'] as $key => $label): ?>
                <?php if ($receipt[$key] !== '0.00'): ?><div><span><?= $label ?></span><strong class="sale-receipt-money"><?= $money($receipt[$key]) ?></strong></div><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="gcash-receipt-reference">
            <div>GCash reference</div>
            <?= code128_svg($receipt['reference_number']) ?>
            <div class="gcash-receipt-digits"><?= $escape($receipt['reference_number']) ?></div>
        </div>
        <div class="sale-receipt-footer"><?= $escape($store['footer']) ?></div>
    </div>
</div>
