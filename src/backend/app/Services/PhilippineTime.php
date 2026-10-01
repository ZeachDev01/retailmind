<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/** Transaction TIMESTAMP reads use a +08:00 database session; never shift history. */
final class PhilippineTime
{
    public const ZONE = 'Asia/Manila';
    public const DATABASE_OFFSET = '+08:00';

    public static function format(mixed $value): string
    {
        $zone = new DateTimeZone(self::ZONE);
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($zone)->format('M j, Y · g:i A');
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?$/D', $value)) {
            return '—';
        }
        try {
            // An explicit source offset takes precedence; zone-less SQL values
            // already project the stored instant into the pinned read timezone.
            $instant = new DateTimeImmutable($value, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                return '—';
            }
            return $instant->setTimezone($zone)->format('M j, Y · g:i A');
        } catch (\Exception) {
            return '—';
        }
    }

    public static function dayStart(string $day): string
    {
        return self::day($day)->format('Y-m-d H:i:s');
    }

    /** Exclusive bound, including subsecond records in the final selected day. */
    public static function dayAfter(string $day): string
    {
        return self::day($day)->modify('+1 day')->format('Y-m-d H:i:s');
    }

    private static function day(string $value): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::ZONE));
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Choose a valid Philippine calendar date.');
        }
        return $day;
    }
}
