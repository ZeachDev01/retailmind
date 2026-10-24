<?php

namespace App\Receipts;

final class GCashReceiptPresentation
{
    public static function render(array $receipt, int $paperWidthMm = 80): void
    {
        require_once __DIR__ . '/../../includes/barcode.php';
        $paperWidthMm = $paperWidthMm === 58 ? 58 : 80;
        $store = $receipt['store'];
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn($value): string => $escape($store['currency_symbol']) . number_format((float)$value, 2);
        require __DIR__ . '/../Views/gcash_receipt.php';
    }
}
