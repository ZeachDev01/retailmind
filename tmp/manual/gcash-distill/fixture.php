<?php
require 'C:/xampp/htdocs/retailmind/src/backend/tests/support/theme_fixture.php';
if ($argv[1] !== 'create') exit;
$pdo->exec("INSERT INTO fiscal_periods (period_name,start_date,end_date,status,created_by) VALUES ('GCash visual check','2026-01-01','2026-12-31','open',1)");
require_once 'C:/xampp/htdocs/retailmind/src/backend/tests/support' . '/../../includes/functions.php';
$service = new App\Services\GCashTransactionService($pdo, require 'C:/xampp/htdocs/retailmind/src/backend/tests/support' . '/../../config/gcash.php');
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

