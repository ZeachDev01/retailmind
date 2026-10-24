<?php

namespace App\Receipts;

final class SaleReceiptPresentation
{
    public static function render(array $sale, array $items, array $store, int $paperWidthMm = 80): void
    {
        $paperWidthMm = $paperWidthMm === 58 ? 58 : 80;
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn($value): string => $escape($store['currency_symbol']) . number_format((float)$value, 2);
        $itemSubtotal = array_reduce($items, static fn($total, $item) => $total + (float)$item['subtotal'], 0.0);
        $quantityTotal = array_reduce($items, static function ($total, $item) {
            $quantity = (int)($item['quantity'] ?? 0);
            if (($item['selling_form'] ?? 'piece') === 'pack') {
                $quantity *= max(1, (int)($item['pack_size'] ?? 1));
            }
            return $total + $quantity;
        }, 0);
        $discount = max((float)($sale['discount_amount'] ?? 0), $itemSubtotal - (float)$sale['total_amount']);
        require __DIR__ . '/../Views/sale_receipt.php';
    }
}
