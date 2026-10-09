<?php

namespace App\Receipts;

require_once __DIR__ . '/../Services/PhilippineTime.php';

final class RefundReceiptPresentation
{
    public static function render(array $details, int $paperWidthMm = 80): void
    {
        $paperWidthMm = $paperWidthMm === 58 ? 58 : 80;
        $refund = $details['refund'];
        $items = $details['items'];
        $store = $details['store'];
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn($value): string => $escape($store['currency_symbol']) . number_format((float)$value, 2);
        require __DIR__ . '/../Views/refund_receipt.php';
    }
}
