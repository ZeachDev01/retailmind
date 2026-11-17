<?php
declare(strict_types=1);

namespace App\Inventory;

use InvalidArgumentException;

final class ProductCost
{
    public static function known(array $product): bool
    {
        return ($product['cost_price'] ?? null) !== null && $product['cost_price'] !== ''
            && ((float)$product['cost_price'] > 0 || !empty($product['cost_known']));
    }

    public static function input(mixed $value): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) return null;
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value)
            || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 99999999.99) {
            throw new InvalidArgumentException('Cost price must be a valid number of PHP 0 or more within range.');
        }
        return round((float)$value, 2);
    }
}
