<?php
// Customer copy deliberately excludes operational notes and classifications.
$v = $view ?? \App\Receipts\ReceiptEngine::prepareRefundViewData($details ?? [], $paperWidthMm ?? 80);
?>
<div class="sale-receipt receipt-print-area" data-paper-width-mm="<?= $v['paper_width_mm'] ?>" aria-label="Refund Receipt">
    <div class="receipt-header">
        <div class="receipt-brand">
            <?php if ($v['store']['logo'] !== ''): ?><img class="receipt-logo" src="<?= $v['store']['logo'] ?>" alt="Store logo"><?php endif; ?>
            <?php if ($v['store']['name'] !== ''): ?><h3><?= $v['store']['name'] ?></h3><?php endif; ?>
            <div class="receipt-store-line">Refund Receipt</div>
            <div><?= $v['store']['address'] ?></div>
            <div><?= $v['store']['contact'] ?></div>
            <div><?= $v['store']['tin'] ?></div>
        </div>
        <div class="receipt-meta">
            <div><strong>Refund #<?= (int)$v['refund']['refund_id'] ?></strong></div>
            <div>Original Sale Receipt #<?= (int)$v['refund']['sale_id'] ?></div>
            <div>Date/Time: <?= $v['refund']['created_at_formatted'] ?></div>
            <div>Cashier: <?= $v['refund']['cashier_name'] ?></div>
            <div>Register: <?= $v['refund']['register_name'] ?></div>
            <div>Reason: <?= $v['refund']['reason'] ?></div>
        </div>
    </div>
    <div class="sale-receipt-items" aria-label="Refunded items">
        <?php foreach ($v['items'] as $item): ?>
        <div class="sale-receipt-item">
            <strong class="sale-receipt-item-name"><?= $item['name'] ?></strong>
            <div class="sale-receipt-sku">SKU: <?= $item['sku'] ?></div>
            <div class="sale-receipt-line"><span>Quantity: <?= (int)$item['quantity'] ?></span><strong class="sale-receipt-money"><?= $item['subtotal_formatted'] ?></strong></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="sale-receipt-totals">
        <div><span>Original payment method</span><strong><?= $v['refund']['payment_method'] ?></strong></div>
        <div class="sale-receipt-grand-total"><span>Total refunded</span><strong class="sale-receipt-money"><?= $v['refund']['refund_amount_formatted'] ?></strong></div>
    </div>
    <?php if ($v['refund']['payment_method_raw'] !== 'cash'): ?>
    <?php if (!empty($v['refund']['payment_reference'])): ?>
    <p>Refund completed externally and recorded in RetailMind.</p>
    <p>Payment Reference: <?= $v['refund']['payment_reference'] ?></p>
    <?php else: ?>
    <p>Noncash refund recorded. External settlement evidence is unavailable on this receipt.</p>
    <?php endif; ?>
    <?php endif; ?>
    <div class="sale-receipt-footer"><?= $v['store']['footer'] ?></div>
</div>
