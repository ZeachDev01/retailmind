<?php
// Exercise the actual action template without requiring a database.
$source = file_get_contents(__DIR__ . '/../app/Views/sale_receipt_support.php');
preg_match('/<div class="receipt-actions no-print">(.*?)<\/div>/s', $source, $match);
if (!isset($match[1])) throw new RuntimeException('Receipt actions template missing.');
function app_url(string $path): string { return '/' . $path; }
function current_role(): string { return $GLOBALS['testRole']; }
$sale = ['sale_id' => 42];
$canStartSale = true;
foreach ([['cashier', true], ['admin', true], ['cashier', false]] as [$testRole, $historyDrawer]) {
    ob_start();
    eval('?>' . $match[1]);
    $html = ob_get_clean();
    $hasRefund = str_contains($html, 'refunds.php?sale_id=42');
    $hasNewSale = str_contains($html, '> New Sale');
    if ($hasRefund !== ($historyDrawer && $testRole === 'cashier') || $hasNewSale !== !$historyDrawer) {
        throw new RuntimeException('Incorrect receipt actions for ' . $testRole . ', history=' . (int)$historyDrawer);
    }
    if (!str_contains($html, 'Print Receipt')) throw new RuntimeException('Print action missing.');
}
echo "Receipt drawer actions: cashier refund, no New Sale, admin access, and checkout actions passed.\n";
