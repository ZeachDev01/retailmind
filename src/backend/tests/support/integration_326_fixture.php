<?php
require __DIR__ . '/cashier_ui_fixture.php';
if ($argv[1] !== 'create') exit;
$pdo->exec("INSERT INTO cash_refunds (refund_id,sale_id,shift_id,cashier_id,refund_amount,reason) VALUES (50,1,1,4,10,'Integration correction fixture')");
$pdo->exec("INSERT INTO gcash_service_sessions (session_id,shift_id,cashier_id,opening_service_cash,opening_wallet_balance,status) VALUES (1,1,4,500,1000,'open')");
$pdo->exec("INSERT INTO gcash_transactions (transaction_id,session_id,shift_id,cashier_id,transaction_type,amount,principal_amount,fee_amount,fee_method,service_cash_delta,wallet_delta,confirmed_cash,confirmed_wallet,verification_status,responsible_user_id,responsible_role,reference_number) VALUES (50,1,1,4,'Cash-In',100,100,2,'cash',0,-100,0,1,'Needs checking',4,'cashier','3260000000050')");
