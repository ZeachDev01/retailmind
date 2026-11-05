<?php
// Shared customer-facing paper; actions belong to the calling workspace.
$v = $view ?? \App\Receipts\ReceiptEngine::prepareSaleViewData($sale ?? [], $items ?? [], $store ?? [], $paperWidthMm ?? 80);
?>
<div class="sale-receipt receipt-print-area" data-paper-width-mm="<?= $v['paper_width_mm'] ?>" aria-label="Sale Receipt">
    <?php if ($v['is_historical']): ?>
        <p class="receipt-historical-notice">Historical receipt: original Store and item details were not preserved and may differ from the original.</p>
    <?php endif; ?>
    <div class="receipt-header">
        <div class="receipt-brand">
            <?php if ($v['store']['logo'] !== ''): ?><img class="receipt-logo" src="<?= $v['store']['logo'] ?>" alt="Store logo"><?php endif; ?>
            <h1><?= $v['store']['name'] ?></h1>
            <div class="receipt-store-line"><?= $v['store']['tagline'] ?></div>
            <div><?= $v['store']['address'] ?></div>
            <div><?= $v['store']['contact'] ?></div>
            <div><?= $v['store']['tin'] ?></div>
        </div>
        <?= $v['metadata_html'] ?>
    </div>

    <div class="sale-receipt-items" aria-label="Purchased items">
        <?php foreach ($v['items'] as $item): ?>
        <div class="sale-receipt-item">
            <strong class="sale-receipt-item-name"><?= $item['name'] ?></strong>
            <div class="sale-receipt-sku">SKU: <?= $item['sku'] ?></div>
            <div class="sale-receipt-line"><span><?= $item['line_quantity_description'] ?></span><strong class="sale-receipt-money"><?= $item['subtotal_formatted'] ?></strong></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="sale-receipt-line"><span>Total Quantity</span><strong class="sale-receipt-money"><?= (int)$v['totals']['total_quantity'] ?></strong></div>
    <div class="sale-receipt-bottom">
        <div class="sale-receipt-totals">
            <div><span>Payment Method</span><strong><?= $v['payment_method'] ?></strong></div>
            <div><span>Subtotal</span><strong class="sale-receipt-money"><?= $v['totals']['subtotal_formatted'] ?></strong></div>
            <div><span>Discount</span><strong class="sale-receipt-money"><?= $v['totals']['discount_formatted'] ?></strong></div>
            <?php if (!empty($v['promotion_name'])): ?><div><span>Promotion</span><strong><?= $v['promotion_name'] ?></strong></div><?php elseif (!empty($v['discount_reason'])): ?><div><span>Discount reason</span><strong><?= $v['discount_reason'] ?></strong></div><?php endif; ?>
            <div class="sale-receipt-grand-total"><span>Total Amount</span><strong class="sale-receipt-money"><?= $v['totals']['total_amount_formatted'] ?></strong></div>
            <div><span>Cash Received</span><strong class="sale-receipt-money"><?= $v['totals']['cash_received_formatted'] ?></strong></div>
            <div><span>Change</span><strong class="sale-receipt-money"><?= $v['totals']['change_due_formatted'] ?></strong></div>
            <?php if (!empty($v['payment_reference'])): ?>
            <div><span>Payment Reference</span><strong><?= $v['payment_reference'] ?></strong></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="sale-receipt-footer"><?= $v['store']['footer'] ?></div>
</div>
