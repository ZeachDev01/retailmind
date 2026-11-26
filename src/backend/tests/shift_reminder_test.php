<?php
require_once __DIR__ . '/../bootstrap/app.php';
use App\Services\ShiftReminder;

function checkReminder(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$settings = ['shift_reminder_enabled' => '1', 'shift_reminder_time' => '20:00'];
foreach ([
    ['2026-11-26 08:00:00', '2026-11-26 20:00:00'],
    ['2026-11-26 20:00:00', '2026-11-26 20:00:00'],
    ['2026-11-26 20:00:01', '2026-11-27 20:00:00'],
    ['2026-11-25 08:00:00', '2026-11-25 20:00:00'],
] as [$opened, $expected]) {
    $due = ShiftReminder::dueAt($settings, ['opened_at' => $opened]);
    checkReminder($due->format('Y-m-d H:i:s') === $expected, 'Incorrect due time for ' . $opened);
    checkReminder($due->getOffset() === 28800, 'Use Philippine time');
}
checkReminder(ShiftReminder::dueAt([], ['opened_at' => '2026-11-26']) === null, 'Disabled by default');
checkReminder(ShiftReminder::dueAt($settings, null) === null, 'No shift, no reminder');
foreach (['24:00', '20:60', '8:00', '', [], '20:00:00'] as $invalid) {
    try {
        ShiftReminder::validate(['shift_reminder_enabled' => '1', 'shift_reminder_time' => $invalid]);
        throw new RuntimeException('Invalid time accepted');
    } catch (InvalidArgumentException) {}
}
checkReminder(ShiftReminder::validate([])['shift_reminder_enabled'] === '0', 'Allow disabled empty time');
echo "Shift reminder timing and validation passed.\n";
