<?php

declare(strict_types=1);

namespace App\GCash;

use DomainException;

/**
 * Canonical GCash pricing/preview rules (slice 1 extraction). Behavior/messages preserved.
 */
final class GCashPricing
{
    public static function calculateFee(mixed $principal): array
    {
        $amount = self::validatePrincipal($principal);
        return [
            'principal' => $amount,
            'fee' => self::feeForPrincipal($amount),
        ];
    }

    public static function calculatePreview(string $type, mixed $principal, ?string $feeMethod = null): array
    {
        if (!in_array($type, ['Cash-In', 'Cash-Out'], true)) {
            throw new DomainException('Select Cash-In or Cash-Out.');
        }
        $amount = self::validatePrincipal($principal);
        $fee = self::feeForPrincipal($amount);

        if ($type === 'Cash-In') {
            $method = 'Fee in cash';
            return [
                'type' => 'Cash-In',
                'principal' => $amount,
                'fee' => $fee,
                'fee_method' => $method,
                'fee_location' => 'cash',
                'customer_transfer' => 0.0,
                'counter_collect' => round($amount + $fee, 2),
                'cash_payout' => 0.0,
                'wallet_transfer' => (float)$amount,
                'net_service_cash' => round($amount + $fee, 2),
                'net_wallet' => round(-$amount, 2),
            ];
        }

        $method = self::normalizeCashOutFeeMethod($feeMethod);
        if ($method === 'Fee via GCash') {
            return [
                'type' => 'Cash-Out',
                'principal' => $amount,
                'fee' => $fee,
                'fee_method' => 'Fee via GCash',
                'fee_location' => 'wallet',
                'customer_transfer' => round($amount + $fee, 2),
                'counter_collect' => 0.0,
                'cash_payout' => (float)$amount,
                'wallet_transfer' => 0.0,
                'net_service_cash' => round(-$amount, 2),
                'net_wallet' => round($amount + $fee, 2),
            ];
        }

        return [
            'type' => 'Cash-Out',
            'principal' => $amount,
            'fee' => $fee,
            'fee_method' => 'Fee in cash',
            'fee_location' => 'cash',
            'customer_transfer' => (float)$amount,
            'counter_collect' => (float)$fee,
            'cash_payout' => (float)$amount,
            'wallet_transfer' => 0.0,
            'net_service_cash' => round(-($amount - $fee), 2),
            'net_wallet' => (float)$amount,
        ];
    }

    public static function validatePrincipal(mixed $principal): int
    {
        if (!is_int($principal) && !is_string($principal)) {
            throw new DomainException('Enter a whole-peso principal amount from 1 to 50,000.');
        }
        $str = is_string($principal) ? trim($principal) : (string)$principal;
        if (!preg_match('/\A[1-9][0-9]{0,6}\z/', $str)) {
            throw new DomainException('Enter a whole-peso principal amount from 1 to 50,000.');
        }
        $val = (int)$str;
        if ($val < 1 || $val > 50000) {
            throw new DomainException('Enter a whole-peso principal amount from 1 to 50,000.');
        }
        return $val;
    }

    public static function feeForPrincipal(int $principal): float
    {
        if ($principal < 500) {
            return 5.0;
        }
        if ($principal <= 1000) {
            return 10.0;
        }
        if ($principal <= 10000) {
            return (float)((int)ceil($principal / 500) * 5);
        }
        return round($principal * 0.01, 2);
    }

    public static function normalizeCashOutFeeMethod(?string $method): string
    {
        if ($method === null || $method === '' || $method === 'Fee via GCash' || $method === 'wallet' || $method === 'gcash') {
            return 'Fee via GCash';
        }
        if ($method === 'Fee in cash' || $method === 'cash') {
            return 'Fee in cash';
        }
        throw new DomainException('Select a valid fee payment method.');
    }
}
