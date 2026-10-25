<?php if (function_exists('current_role') && current_role() === 'cashier'): ?>
<button type="button" class="btn" data-store-print
    data-print-endpoint="<?= htmlspecialchars(app_url('components/cashier/store_print.php')) ?>"
    data-csrf="<?= htmlspecialchars(csrf_token()) ?>"
    data-receipt-type="<?= htmlspecialchars($printReceiptType) ?>"
    data-receipt-id="<?= (int)$printReceiptId ?>"><i class="bi bi-printer" aria-hidden="true"></i> Print to Store Printer</button>
<?php endif; ?>
