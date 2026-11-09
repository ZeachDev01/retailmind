<?php
// Brevo test-send failure (2026-11-09): Brevo answers HTTP 400
// "sender name is missing" when the payload sender name is blank. Store name
// defaults to an empty string in store_settings, so the Brevo payload must
// fall back to the app name instead of sending an empty sender name.

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
restore_exception_handler();

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$assert(brevo_sender_name(['store_name' => '']) === 'RetailMind', 'Empty Store name must fall back to RetailMind');
$assert(brevo_sender_name(['store_name' => '   ']) === 'RetailMind', 'Whitespace Store name must fall back to RetailMind');
$assert(brevo_sender_name(['store_name' => 'Shalom Store']) === 'Shalom Store', 'Configured Store name must be used');
$assert(brevo_sender_name([]) === 'RetailMind', 'Missing Store name must fall back to RetailMind');

$source = (string)@file_get_contents(__DIR__ . '/../includes/functions.php');
$assert(str_contains($source, "'name' => brevo_sender_name(\$config)"), 'Brevo payload must use brevo_sender_name');
$assert(str_contains($source, "\$detail = trim((string)(\$json['message'] ?? ''));"), 'Brevo HTTP errors must surface the API message, not just the status code');

if ($failures) {
    fwrite(STDERR, "Brevo sender contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Brevo sender contract: passed\n";
