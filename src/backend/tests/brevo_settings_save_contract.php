<?php
// "Save and send test email" must not keep a rejected credential. On
// 2026-11-09 a failed test replaced a working Brevo key with the text of the
// failure message itself, so every later send failed with Brevo "Key not
// found". This contract pins the rollback and the paste guard around it.

require_once __DIR__ . '/../bootstrap/app.php';
restore_exception_handler();

$page = (string)@file_get_contents(__DIR__ . '/../../frontend/components/system_administrator/system_settings.php');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$assert($page !== '', 'Brevo settings page must be readable');
$assert(str_contains($page, '$previousSettings = ['), 'Brevo test must snapshot the previous settings');
$assert(str_contains($page, '$saveBrevo($previousSettings);'), 'Failed Brevo test must restore the previous settings');
$assert(str_contains($page, "preg_match('/\\s/', \$apiKey) === 1"), 'Brevo save must reject keys containing whitespace');
$assert(str_contains($page, 'Previous Brevo settings were kept'), 'Failed Brevo test must say the previous settings were kept');
$assert(str_contains($page, "SELECT error_message FROM email_delivery_log"), 'Failed Brevo test must surface the provider reason');

if ($failures) {
    fwrite(STDERR, "Brevo settings save contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Brevo settings save contract: passed\n";
