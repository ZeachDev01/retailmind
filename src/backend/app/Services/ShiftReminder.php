<?php
namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ShiftReminder
{
    public static function validate(array $input): array
    {
        $enabled = $input['shift_reminder_enabled'] ?? '0';
        $time = $input['shift_reminder_time'] ?? '';
        if (!is_string($enabled) || !in_array($enabled, ['0', '1'], true)
            || !is_string($time) || ($time !== '' && !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time))) {
            throw new InvalidArgumentException('Choose a valid reminder time.');
        }
        if ($enabled === '1' && $time === '') {
            throw new InvalidArgumentException('Choose a reminder time before enabling reminders.');
        }
        return ['shift_reminder_enabled' => $enabled, 'shift_reminder_time' => $time];
    }

    public static function dueAt(array $settings, ?array $shift): ?DateTimeImmutable
    {
        if (($settings['shift_reminder_enabled'] ?? '0') !== '1' || $shift === null) return null;
        $settings = self::validate($settings);
        $opened = new DateTimeImmutable($shift['opened_at'], new DateTimeZone('Asia/Manila'));
        [$hour, $minute] = array_map('intval', explode(':', $settings['shift_reminder_time']));
        $due = $opened->setTime($hour, $minute);
        return $opened > $due ? $due->modify('+1 day') : $due;
    }
}
