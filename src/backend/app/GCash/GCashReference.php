<?php

declare(strict_types=1);

namespace App\GCash;

use DomainException;

/**
 * Canonical GCash reference normalization/validation (slice 2 extraction). Behavior preserved.
 */
final class GCashReference
{
    public static function normalize(string $value, int $minLength, int $maxLength): string
    {
        $reference = preg_replace('/[\s-]+/u', '', $value);
        if (!is_string($reference) || !preg_match('/\A[0-9]{' . $minLength . ',' . $maxLength . '}\z/', $reference)) {
            throw new DomainException('Enter a numeric GCash reference matching the configured length.');
        }
        return $reference;
    }
}
