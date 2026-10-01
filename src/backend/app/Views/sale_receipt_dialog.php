<?php // The sole Sale Receipt surface, used by saved history and completed checkout. ?>
<dialog id="receiptModal" class="sale-receipt-dialog" aria-labelledby="saleReceiptTitle"
    data-receipt-endpoint="<?= htmlspecialchars(app_url('components/invoice/sales.php?action=view&ajax=1')) ?>"
    data-initial-receipt="<?= $selected_sale && $activeTab === 'transactions' ? '1' : '0' ?>">
    <div class="sale-receipt-dialog-header">
        <h2 id="saleReceiptTitle" tabindex="-1">Sale Receipt<?= $selected_sale ? ' #' . (int)$selected_sale['sale_id'] : '' ?></h2>
        <button type="button" class="btn btn-secondary" data-close-receipt aria-label="Close Sale Receipt">Close</button>
    </div>
    <div id="receiptContent"><?php if ($selected_sale && $activeTab === 'transactions') receipt_render_details($selected_sale, $selected_items, $canStartSale, $announcePayment); ?></div>
</dialog>
