<?php
// Synthetic saved paper in the shipped dialog template; never boots Store writes.
require_once __DIR__ . '/../../app/Receipts/ReceiptDetailsService.php';
require_once __DIR__ . '/../../app/Receipts/SaleReceiptPresentation.php';
function app_url(string $path): string { return '/' . $path; }
function receipt_store_info(): array { return $GLOBALS['store']; }
function receipt_money($value, ?array $store = null): string { return '₱' . number_format((float)$value, 2); }
$source = file_get_contents(__DIR__ . '/../../../frontend/components/invoice/sales.php');
preg_match('/function receipt_render_details\([^)]*\): void \{.*?^\}/ms', $source, $match);
$renderer = preg_replace('/    \$paperWidthMm = .*?(?=    \$store =)/s', '    $paperWidthMm = (int)$GLOBALS["fixturePaperWidth"];' . "\n", $match[0]);
eval($renderer);
$paperWidthMm = (int)($argv[2] ?? 80); $GLOBALS['fixturePaperWidth'] = $paperWidthMm;
$argv = [__FILE__, 'long', $paperWidthMm];
ob_start(); require __DIR__ . '/sale_receipt_fixture.php'; $rendered = ob_get_clean();
preg_match('/<div class="sale-receipt receipt-print-area".*(?=<\/div>$)/s', $rendered, $match);
$paper = $match[0];
$sale['receipt_store'] = $store; $selected_sale = $sale; $selected_items = $items; $canStartSale = true;
$activeTab = 'transactions'; $announcePayment = true;
require __DIR__ . '/../../app/Views/sale_receipt_dialog.php';
