<?php
// Synthetic data exercises the shipped paper renderer without changing Store data.
require_once __DIR__ . '/../../app/Receipts/ReceiptDetailsService.php';
require_once __DIR__ . '/../../app/Receipts/SaleReceiptPresentation.php';

$paperWidthMm = (int)($argv[2] ?? 80);
$long = ($argv[1] ?? 'short') === 'long';
$store = ['name' => $long ? 'Synthetic Store with a very long customer-facing name' : 'Synthetic Store',
    'tagline' => 'Sale Receipt', 'address' => '123 Example Street, Tangub City',
    'contact' => '555-0100 • example@example.test', 'tin' => 'TIN: EXAMPLE-123',
    'currency_symbol' => '₱', 'footer' => 'Thank you for shopping with us.'];
$items = [];
for ($i = 1; $i <= ($long ? 40 : 2); $i++) {
    $items[] = ['sku' => $long ? 'LONG-SKU-' . str_repeat('1234567890', 6) . '-' . $i : 'TEST-' . $i,
        'product_name' => $long ? 'Long item ' . $i . ': ' . str_repeat('Special family-size everyday groceries ', 3) : 'Example item ' . $i,
        'quantity' => 2, 'unit_price' => 1234.50, 'subtotal' => 2469.00];
}
$subtotal = count($items) * 2469;
$sale = ['sale_id' => 103, 'sale_date' => '2026-09-30 10:11:12',
    'cashier_name' => $long ? str_repeat('Long Cashier Name ', 5) : 'Example Cashier',
    'register_name' => $long ? str_repeat('Long Register Name ', 5) : 'Front Counter',
    'total_amount' => $subtotal - 123, 'discount_amount' => 123,
    'promotion_name' => $long ? str_repeat('September promotion ', 5) : 'September promotion',
    'payment_method' => $long ? 'ewallet' : 'cash',
    'cash_received' => $long ? null : 5000, 'change_due' => $long ? null : 5000 - ($subtotal - 123),
    'payment_reference' => $long ? 'NONCASH-REFERENCE-' . str_repeat('0123456789', 15) : null,
    'verification_code' => 'SALE-103-EXAMPLE'];
echo '<div class="receipt-container"><div class="no-print">Payment completed — synthetic example</div><button type="button" class="no-print" onclick="printReceiptSection(this)">Print Receipt</button>';
App\Receipts\SaleReceiptPresentation::render($sale, $items, $store,
    'https://example.test/retailmind/components/invoice/sales.php?tab=transactions&sale_id=103&example=' . str_repeat('1234567890', $long ? 20 : 1), $paperWidthMm);
echo '</div>';
