<?php

declare(strict_types=1);

namespace App\GCash;

use DomainException;

final class GCashPhoneNumber
{
    public static function normalize(mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            throw new DomainException('Enter a valid GCash mobile number.');
        }
        $number = preg_replace('/[\s-]+/', '', trim($value ?? ''));
        if ($number === '') return null;
        $number = preg_replace('/^\+?63/', '0', $number);
        if (!preg_match('/\A09[0-9]{9}\z/', $number)) {
            throw new DomainException('Enter an 11-digit GCash mobile number starting with 09.');
        }
        return $number;
    }
}
