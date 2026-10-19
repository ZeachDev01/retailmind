<?php

require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\GCashTransactionService;

$service = new GCashTransactionService();

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$refuse = static function (callable $work, string $context = '') use ($check): void {
    try {
        $work();
    } catch (DomainException $e) {
        return;
    }
    $check(false, "Expected request to be refused: {$context}");
};

// 1. Bracket boundaries verification
$boundaries = [
    1 => 5.0,
    350 => 5.0,
    499 => 5.0,
    500 => 10.0,
    1000 => 10.0,
    1001 => 15.0,
    1500 => 15.0,
    1501 => 20.0,
    2000 => 20.0,
    2001 => 25.0,
    2500 => 25.0,
    2501 => 30.0,
    3000 => 30.0,
    3001 => 35.0,
    3500 => 35.0,
    3501 => 40.0,
    4000 => 40.0,
    4001 => 45.0,
    4500 => 45.0,
    4501 => 50.0,
    5000 => 50.0,
    5001 => 55.0,
    5500 => 55.0,
    5501 => 60.0,
    6000 => 60.0,
    6001 => 65.0,
    6500 => 65.0,
    6501 => 70.0,
    7000 => 70.0,
    7001 => 75.0,
    7500 => 75.0,
    7501 => 80.0,
    8000 => 80.0,
    8001 => 85.0,
    8500 => 85.0,
    8501 => 90.0,
    9000 => 90.0,
    9001 => 95.0,
    9500 => 95.0,
    9501 => 100.0,
    10000 => 100.0,
    10001 => 100.01,
    12345 => 123.45,
    50000 => 500.0,
];

foreach ($boundaries as $principal => $expectedFee) {
    $fee = $service->feeForPrincipal($principal);
    $check($fee === $expectedFee, "Fee for {$principal} expected {$expectedFee}, got {$fee}");

    $res = $service->calculateFee($principal);
    $check($res['principal'] === $principal && $res['fee'] === $expectedFee, "calculateFee for {$principal} failed");

    $resStr = $service->calculateFee((string)$principal);
    $check($resStr['principal'] === $principal && $resStr['fee'] === $expectedFee, "calculateFee string for {$principal} failed");
}

// 2. Reject zero, negative, fractional-peso, malformed, and unsupported-range values
$invalidInputs = [
    0, -1, -500, '0', '-1', '-500',
    '1.5', '10.50', '10.00', '1.0', '1000.01',
    'NaN', 'abc', '1e3', '', ' ', '01', '00500',
    50001, '50001', 999999, '999999'
];

foreach ($invalidInputs as $invalid) {
    $refuse(fn() => $service->validatePrincipal($invalid), "validatePrincipal: " . var_export($invalid, true));
    $refuse(fn() => $service->calculateFee($invalid), "calculateFee: " . var_export($invalid, true));
    $refuse(fn() => $service->calculatePreview('Cash-In', $invalid), "calculatePreview: " . var_export($invalid, true));
}

// Float types
$refuse(fn() => $service->validatePrincipal(1.5), 'float 1.5');
$refuse(fn() => $service->validatePrincipal(0.0), 'float 0.0');
$refuse(fn() => $service->validatePrincipal(-10.0), 'float -10.0');

// Reject invalid transaction types
$refuse(fn() => $service->calculatePreview('Payment', 1000), 'type Payment');
$refuse(fn() => $service->calculatePreview('Invalid', 1000), 'type Invalid');
$refuse(fn() => $service->calculatePreview('', 1000), 'type empty');

// Reject invalid fee method for Cash-Out
$refuse(fn() => $service->calculatePreview('Cash-Out', 1000, 'InvalidMethod'), 'fee method InvalidMethod');

// 3. The three PHP 1,000 examples from #236
// Cash-In 1000
$ci = $service->calculatePreview('Cash-In', 1000);
$check($ci['type'] === 'Cash-In', 'Cash-In type');
$check($ci['principal'] === 1000, 'Cash-In principal');
$check($ci['fee'] === 10.0, 'Cash-In fee');
$check($ci['fee_location'] === 'cash', 'Cash-In fee location');
$check($ci['customer_transfer'] === 0.0, 'Cash-In customer transfer');
$check($ci['counter_collect'] === 1010.0, 'Cash-In counter collect');
$check($ci['cash_payout'] === 0.0, 'Cash-In cash payout');
$check($ci['wallet_transfer'] === 1000.0, 'Cash-In wallet transfer');
$check($ci['net_service_cash'] === 1010.0, 'Cash-In net cash');
$check($ci['net_wallet'] === -1000.0, 'Cash-In net wallet');

// Cash-Out: Fee via GCash
$coWallet = $service->calculatePreview('Cash-Out', 1000, 'Fee via GCash');
$check($coWallet['type'] === 'Cash-Out', 'Cash-Out GCash type');
$check($coWallet['principal'] === 1000, 'Cash-Out GCash principal');
$check($coWallet['fee'] === 10.0, 'Cash-Out GCash fee');
$check($coWallet['fee_location'] === 'wallet', 'Cash-Out GCash fee location');
$check($coWallet['customer_transfer'] === 1010.0, 'Cash-Out GCash customer transfer');
$check($coWallet['counter_collect'] === 0.0, 'Cash-Out GCash counter collect');
$check($coWallet['cash_payout'] === 1000.0, 'Cash-Out GCash cash payout');
$check($coWallet['wallet_transfer'] === 0.0, 'Cash-Out GCash wallet transfer');
$check($coWallet['net_service_cash'] === -1000.0, 'Cash-Out GCash net cash');
$check($coWallet['net_wallet'] === 1010.0, 'Cash-Out GCash net wallet');

// Cash-Out: Fee in cash
$coCash = $service->calculatePreview('Cash-Out', 1000, 'Fee in cash');
$check($coCash['type'] === 'Cash-Out', 'Cash-Out Cash type');
$check($coCash['principal'] === 1000, 'Cash-Out Cash principal');
$check($coCash['fee'] === 10.0, 'Cash-Out Cash fee');
$check($coCash['fee_location'] === 'cash', 'Cash-Out Cash fee location');
$check($coCash['customer_transfer'] === 1000.0, 'Cash-Out Cash customer transfer');
$check($coCash['counter_collect'] === 10.0, 'Cash-Out Cash counter collect');
$check($coCash['cash_payout'] === 1000.0, 'Cash-Out Cash cash payout');
$check($coCash['wallet_transfer'] === 0.0, 'Cash-Out Cash wallet transfer');
$check($coCash['net_service_cash'] === -990.0, 'Cash-Out Cash net cash');
$check($coCash['net_wallet'] === 1000.0, 'Cash-Out Cash net wallet');

// 4. PHP 12,345 example
$ci12k = $service->calculatePreview('Cash-In', 12345);
$check($ci12k['fee'] === 123.45, '12,345 fee 123.45');
$check($ci12k['counter_collect'] === 12468.45, '12,345 CI counter collect');
$check($ci12k['net_service_cash'] === 12468.45, '12,345 CI net cash');
$check($ci12k['net_wallet'] === -12345.0, '12,345 CI net wallet');

$coCash12k = $service->calculatePreview('Cash-Out', 12345, 'Fee in cash');
$check($coCash12k['fee'] === 123.45, '12,345 fee 123.45');
$check($coCash12k['counter_collect'] === 123.45, '12,345 CO cash counter collect');
$check($coCash12k['net_service_cash'] === -12221.55, '12,345 CO cash net cash');
$check($coCash12k['net_wallet'] === 12345.0, '12,345 CO cash net wallet');

$coWallet12k = $service->calculatePreview('Cash-Out', 12345, 'Fee via GCash');
$check($coWallet12k['fee'] === 123.45, '12,345 fee 123.45');
$check($coWallet12k['customer_transfer'] === 12468.45, '12,345 CO wallet customer transfer');
$check($coWallet12k['net_service_cash'] === -12345.0, '12,345 CO wallet net cash');
$check($coWallet12k['net_wallet'] === 12468.45, '12,345 CO wallet net wallet');

echo "GCash fee preview: passed\n";
