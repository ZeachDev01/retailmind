<?php
require_once __DIR__ . '/../app/Services/CashDenominationCounter.php';
use App\Services\CashDenominationCounter as Counter;

function check(bool $okay, string $message): void {
    if (!$okay) throw new RuntimeException($message);
}
check(Counter::count(['bill_1000' => '2', 'bill_500' => '3', 'bill_100' => '5'])['total_cents'] === 400000, 'example totals exactly PHP 4,000');
$coins = Counter::count(['bill_20' => 1, 'coin_20' => 1, 'coin_025' => 3, 'coin_005' => 2, 'coin_001' => 7]);
check($coins['bill_cents'] === 2000 && $coins['coin_cents'] === 2092 && $coins['total_cents'] === 4092, 'separate bills and coins with exact centavos');
check(Counter::pesos(4092) === '40.92' && Counter::pesos(1) === '0.01', 'exact decimal serialization');
check(Counter::count([])['total_cents'] === 0, 'empty drawer is valid');
foreach ([null, 'invalid', ['unknown' => 1], ['coin_1' => -1], ['bill_100' => 1.5], ['coin_1' => '1e2'], ['coin_1' => ''], ['coin_1' => []], ['coin_1' => true], ['coin_1' => 1000001]] as $invalid) {
    try { Counter::count($invalid); throw new RuntimeException('Invalid quantities accepted'); }
    catch (InvalidArgumentException $expected) {}
}
$all = [];
foreach (Counter::DENOMINATIONS as $values) foreach ($values as $key => $cents) $all[$key] = Counter::MAX_QUANTITY;
check(Counter::count($all)['total_cents'] < 1000000000000, 'maximum fits the existing DECIMAL(12,2) columns');
echo "Cash denomination counter: passed\n";
