<?php // Customer copy deliberately excludes operational notes and classifications. ?>
<div class="sale-receipt receipt-print-area" data-paper-width-mm="<?= $paperWidthMm ?>" aria-label="Refund Receipt">
    <div class="receipt-header">
        <div class="receipt-brand">
            <h1><?= $escape($store['name']) ?></h1>
            <div class="receipt-store-line">Refund Receipt</div>
            <div><?= $escape($store['address']) ?></div>
            <div><?= $escape($store['contact']) ?></div>
            <div><?= $escape($store['tin']) ?></div>
        </div>
        <div class="receipt-meta">
            <div><strong>Refund #<?= (int)$refund['refund_id'] ?></strong></div>
            <div>Original Sale Receipt #<?= (int)$refund['sale_id'] ?></div>
            <div>Date/Time: <?= $escape($refund['created_at']) ?></div>
            <div>Cashier: <?= $escape($refund['cashier_name']) ?></div>
            <div>Register: <?= $escape($refund['register_name'] ?: 'Legacy / Unassigned') ?></div>
            <div>Reason: <?= $escape($refund['reason']) ?></div>
        </div>
    </div>
    <div class="sale-receipt-items" aria-label="Refunded items">
        <?php foreach ($items as $item): ?>
        <div class="sale-receipt-item">
            <strong class="sale-receipt-item-name"><?= $escape($item['product_name']) ?></strong>
            <div class="sale-receipt-sku">SKU: <?= $escape($item['sku']) ?></div>
            <div class="sale-receipt-line"><span>Quantity: <?= (int)$item['quantity'] ?></span><strong class="sale-receipt-money"><?= $money($item['subtotal']) ?></strong></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="sale-receipt-totals">
        <div><span>Original payment method</span><strong><?= $escape(strtoupper($refund['payment_method'])) ?></strong></div>
        <div class="sale-receipt-grand-total"><span>Total refunded</span><strong class="sale-receipt-money"><?= $money($refund['refund_amount']) ?></strong></div>
    </div>
    <?php if ($refund['payment_method'] !== 'cash'): ?>
    <?php if (!empty($refund['payment_reference'])): ?>
    <p>Refund completed externally and recorded in RetailMind.</p>
    <p>Payment Reference: <?= $escape($refund['payment_reference']) ?></p>
    <?php else: ?>
    <p>Noncash refund recorded. External settlement evidence is unavailable on this receipt.</p>
    <?php endif; ?>
    <?php endif; ?>
    <div class="sale-receipt-footer"><?= $escape($store['footer']) ?></div>
</div>
