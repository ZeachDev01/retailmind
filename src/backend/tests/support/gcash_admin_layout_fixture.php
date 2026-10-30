<?php
require __DIR__ . '/cashier_ui_fixture.php';
if ($argv[1] !== 'create') exit;
require_once __DIR__ . '/../../includes/functions.php';
$service = new App\Services\GCashTransactionService($pdo, require __DIR__ . '/../../config/gcash.php');
$service->openSession(4, 1000, 5000);
$service->recordCashIn(4, [
    'principal' => 1000, 'reference' => '0000000000101',
    'confirmed_cash' => true, 'confirmed_wallet' => true,
]);
$service->closeSession(4, 2000, 4000, 'Test closing cash count differs from expected by ten pesos');
$service->openSession(4, 2000, 4000);
$service->recordCashIn(4, [
    'principal' => 500, 'outcome' => 'Needs checking',
    'confirmed_cash' => true, 'confirmed_wallet' => false,
    'notes' => 'Customer paid cash; wallet transfer requires checking in the GCash app.',
]);
