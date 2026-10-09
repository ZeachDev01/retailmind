<?php
// Synthetic customer receipt exercises production presentation without Store writes.
require_once __DIR__ . '/../../app/Receipts/RefundReceiptPresentation.php';
$long = ($argv[1] ?? '') === 'long';
$items = [];
for ($i = 1; $i <= ($long ? 35 : 2); $i++) {
    $items[] = ['product_name' => $long ? str_repeat('Long grocery product name ', 5) . $i : 'Example item ' . $i,
        'sku' => $long ? str_repeat('SKU0123456789', 8) . $i : 'TEST-' . $i,
        'quantity' => 2, 'subtotal' => 2469];
}
$details = ['refund' => ['refund_id' => 105, 'sale_id' => 103, 'created_at' => '2026-09-30 10:11:12',
    'cashier_name' => $long ? str_repeat('Example Cashier ', 5) : 'Example Cashier',
    'register_name' => $long ? str_repeat('Front Counter ', 6) : 'Front Counter',
    'reason' => 'Other', 'payment_method' => $long ? 'ewallet' : 'cash', 'payment_reference' => $long ? str_repeat('EXTERNAL',12) : null, 'refund_amount' => count($items) * 2469,
    'note' => 'PRIVATE-REFUND-NOTE', 'disposition' => 'damaged'], 'items' => $items,
    'store' => ['name' => 'Synthetic Store', 'address' => '123 Example Street, Tangub City',
    'contact' => '555-0100 / example@example.test', 'tin' => 'TIN EXAMPLE123', 'currency_symbol' => '₱',
    'footer' => 'Thank you for shopping with us.']];
if (($argv[1] ?? '') === 'legacy-noncash') {
    $details['refund']['payment_method'] = 'card';
    unset($details['refund']['payment_reference']);
}
echo '<section class="receipt-container"><h2 class="no-print">Refund recorded — synthetic example</h2><button class="no-print" type="button" onclick="printReceiptSection(this)">Print Refund Receipt</button>';
App\Receipts\RefundReceiptPresentation::render($details, (int)($argv[2] ?? 80));
echo '</section>';
