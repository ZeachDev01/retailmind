<dialog id="receiptModal" class="sale-receipt-dialog" aria-labelledby="saleReceiptTitle"
    data-receipt-endpoint="<?= htmlspecialchars(app_url('components/cashier/gcash_receipt.php')) ?>"
    data-receipt-param="transaction_id" data-receipt-title="GCash Service Receipt">
    <div class="sale-receipt-dialog-header">
        <h2 id="saleReceiptTitle" tabindex="-1">GCash Service Receipt</h2>
        <button type="button" class="btn btn-secondary" data-close-receipt aria-label="Close GCash Service Receipt">Close</button>
    </div>
    <div id="receiptContent"></div>
</dialog>
