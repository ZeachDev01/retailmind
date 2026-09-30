<?php // Shared customer-facing paper; actions belong to the calling workspace. ?>
        <div class="sale-receipt receipt-print-area" aria-label="Sale Receipt">
            <?php if (!empty($sale['receipt_historical_notice'])): ?>
                <p class="receipt-historical-notice">Historical receipt: original Store and item details were not preserved and may differ from the original.</p>
            <?php endif; ?>
            <div class="receipt-header">
                <div class="receipt-brand">
                    <h1><?= $escape($store['name']) ?></h1>
                    <div class="receipt-store-line"><?= $escape($store['tagline']) ?></div>
                    <div><?= $escape($store['address']) ?></div>
                    <div><?= $escape($store['contact']) ?></div>
                    <div><?= $escape($store['tin']) ?></div>
                </div>
                <?php // Legacy / Unassigned attribution is rendered without exposing the Cashier Shift identifier. ?>
                <?= \App\Services\ReceiptDetailsService::renderMetadata($sale) ?>
            </div>

            <div class="sale-receipt-items" aria-label="Purchased items">
                <?php foreach ($items as $item): ?>
                <div class="sale-receipt-item">
                    <strong class="sale-receipt-item-name"><?= $escape($item['product_name']) ?></strong>
                    <div class="sale-receipt-sku">SKU: <?= $escape($item['sku']) ?></div>
                    <div class="sale-receipt-line"><span><?= (int)$item['quantity'] ?> × <?= $money($item['unit_price']) ?></span><strong class="sale-receipt-money"><?= $money($item['subtotal']) ?></strong></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="sale-receipt-line"><span>Total Quantity</span><strong class="sale-receipt-money"><?= (int)$quantityTotal ?></strong></div>
            <div class="sale-receipt-bottom">
                <div class="sale-receipt-totals">
                    <div><span>Payment Method</span><strong><?= $escape(strtoupper($sale['payment_method'])) ?></strong></div>
                    <div><span>Subtotal</span><strong class="sale-receipt-money"><?= $money($itemSubtotal) ?></strong></div>
                    <div><span>Discount</span><strong class="sale-receipt-money"><?= $money($discount) ?></strong></div>
                    <?php if (!empty($sale['promotion_name'])): ?><div><span>Promotion</span><strong><?= $escape($sale['promotion_name']) ?></strong></div><?php elseif (!empty($sale['discount_reason'])): ?><div><span>Discount reason</span><strong><?= $escape($sale['discount_reason']) ?></strong></div><?php endif; ?>
                    <div class="sale-receipt-grand-total"><span>Total Amount</span><strong class="sale-receipt-money"><?= $money($sale['total_amount']) ?></strong></div>
                    <div><span>Cash Received</span><strong class="sale-receipt-money"><?= $sale['cash_received'] !== null ? $money($sale['cash_received']) : '-' ?></strong></div>
                    <div><span>Change</span><strong class="sale-receipt-money"><?= $sale['change_due'] !== null ? $money($sale['change_due']) : '-' ?></strong></div>
                    <?php if (!empty($sale['payment_reference'])): ?>
                    <div><span>Payment Reference</span><strong><?= $escape($sale['payment_reference']) ?></strong></div>
                    <?php endif; ?>
                </div>
                <div class="sale-receipt-verification">
                    <div class="sale-receipt-code" aria-label="Receipt verification code"><?= $escape($verificationCode) ?></div>
                    <div class="receipt-store-line">Verification Code</div>
                    <div class="receipt-url"><?= $escape($receiptUrl) ?></div>
                </div>
            </div>
            <div class="sale-receipt-footer"><?= $escape($store['footer']) ?></div>
        </div>
