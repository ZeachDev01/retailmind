<?php

require_once __DIR__ . '/../bootstrap/app.php';

use App\GCash\GCashReference;
use App\Services\GCashTransactionService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$throws = static function (callable $fn, string $message) use (&$failures): void {
    try {
        $fn();
        $failures[] = $message;
    } catch (DomainException $e) {
        // expected
    }
};

$rules = ['min_length' => 13, 'max_length' => 13];
$service = new GCashTransactionService(null, $rules);

$assert($service->reference('1234567890123') === '1234567890123', 'exact 13 digits accepted');
$assert($service->reference('1234 5678 90123') === '1234567890123', 'spaces normalized');
$assert($service->reference('1234-5678-90123') === '1234567890123', 'dashes normalized');
$assert(GCashReference::normalize('1234567890123', 13, 13) === '1234567890123', 'module normalizes exact length');
$throws(fn() => $service->reference('123'), 'short reference refused');
$throws(fn() => $service->reference('12345678901234'), 'long reference refused');
$throws(fn() => $service->reference('123456789012A'), 'non-numeric refused');

$wide = new GCashTransactionService(null, ['min_length' => 6, 'max_length' => 32]);
$assert($wide->reference('123456') === '123456', 'min boundary accepted');
$assert($wide->reference(str_repeat('1', 32)) === str_repeat('1', 32), 'max boundary accepted');

if ($failures) {
    fwrite(STDERR, "GCash reference PHP contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "GCash reference PHP contract: passed\n";
