<?php
namespace App\Services;

use InvalidArgumentException;

final class CashDenominationCounter
{
    public const DENOMINATIONS = [
        'Bills' => ['bill_1000' => 100000, 'bill_500' => 50000, 'bill_200' => 20000, 'bill_100' => 10000, 'bill_50' => 5000, 'bill_20' => 2000],
        'Coins' => ['coin_20' => 2000, 'coin_10' => 1000, 'coin_5' => 500, 'coin_1' => 100, 'coin_025' => 25, 'coin_005' => 5, 'coin_001' => 1],
    ];
    public const MAX_QUANTITY = 1000000;

    public static function count(mixed $quantities): array
    {
        $allowed = self::DENOMINATIONS['Bills'] + self::DENOMINATIONS['Coins'];
        if (!is_array($quantities) || array_diff_key($quantities, $allowed)) {
            throw new InvalidArgumentException('Enter valid bill and coin quantities.');
        }
        $result = ['quantities' => [], 'bill_cents' => 0, 'coin_cents' => 0, 'total_cents' => 0];
        foreach (self::DENOMINATIONS as $group => $denominations) {
            foreach ($denominations as $key => $cents) {
                $quantity = array_key_exists($key, $quantities) ? $quantities[$key] : 0;
                if ((!is_int($quantity) && !is_string($quantity)) || !preg_match('/^[0-9]{1,7}$/D', (string)$quantity)
                    || (int)$quantity > self::MAX_QUANTITY) {
                    throw new InvalidArgumentException('Use whole quantities from 0 to ' . self::MAX_QUANTITY . '.');
                }
                $result['quantities'][$key] = (int)$quantity;
                $result[$group === 'Bills' ? 'bill_cents' : 'coin_cents'] += $cents * (int)$quantity;
            }
        }
        $result['total_cents'] = $result['bill_cents'] + $result['coin_cents'];
        return $result;
    }

    public static function pesos(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
