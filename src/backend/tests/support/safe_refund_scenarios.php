<?php
// Included only by the disposable Cash Refund database fixture.
$refused = static function (callable $operation) use ($assert): void {
    try { $operation(); } catch (DomainException | RuntimeException $e) {
        if ($e instanceof PDOException) throw $e;
        return;
    }
    $assert(false,'Expected a safe refund refusal');
};
$snapshot = static function () use ($pdo): array {
    $result=[];
    foreach (['sales','sale_items','sale_receipt_details','cash_refunds','cash_refund_items','refund_receipt_details','stock_movements','inventory','product_batches','activity_log','cash_refund_exception_access'] as $table) {
        $result[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
};
$migration = require __DIR__.'/../../database/migrations/202610010003_safe_refund_exceptions.php';
$migration['up']($pdo); $migration['up']($pdo);
$pdo->exec("INSERT INTO roles(role_id,role_name) VALUES (2,'admin'),(3,'super_admin')");
$hash=password_hash('fixture-password',PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users(user_id,full_name,username,password_hash,role_id,branch_id,status,must_change_password)
 VALUES (2,'Approver','refund-admin',?,2,1,'active',0),(3,'Original seller','refund-seller',?,1,1,'disabled',0),
 (4,'Technical owner','refund-super',?,3,1,'active',0),(5,'Other Cashier','refund-other',?,1,1,'active',0)")->execute([$hash,$hash,$hash,$hash]);
$pdo->exec('UPDATE users SET must_change_password=0 WHERE user_id=1');
$pdo->exec("INSERT INTO registers(register_id,name) VALUES (2,'Other Till')");
$pdo->exec('INSERT INTO cashier_shifts(shift_id,cashier_id,register_id,opening_cash) VALUES (2,5,2,100)');
$pdo->exec("INSERT INTO fiscal_periods(period_id,period_name,start_date,end_date,status,created_by) VALUES (1,'Historical','2020-01-01','2020-01-31','closed',2)");
$pdo->prepare("INSERT INTO fiscal_periods(period_id,period_name,start_date,end_date,status,created_by) VALUES (2,'Today',?,?,'open',2)")->execute([date('Y-m-d'),date('Y-m-d')]);
$pdo->exec("INSERT INTO sales(sale_id,cashier_id,total_amount,payment_method,sale_date) VALUES
 (10,1,74.99,'card','2020-01-15 10:00:00'),(11,3,50,'cash','2020-01-16 10:00:00'),
 (12,3,25,'ewallet','2020-01-16 11:00:00'),(13,1,25,'card','2020-01-17 11:00:00'),(14,1,74.99,'card','2020-01-18 11:00:00')");
$pdo->exec('INSERT INTO sale_items(sale_item_id,sale_id,product_id,quantity,unit_price,subtotal) VALUES (10,10,1,3,25,75),(11,11,1,2,25,50),(12,12,1,1,25,25),(13,13,1,1,25,25)');
$damaged=static fn(int $line,int $quantity=1): array => [$line=>['quantity'=>$quantity,'disposition'=>'damaged']];
$pdo->exec('INSERT INTO sale_items(sale_item_id,sale_id,product_id,quantity,unit_price,subtotal) VALUES (14,14,1,3,25,75)');
$pdo->beginTransaction();$pdo->query('SELECT sale_id FROM sales WHERE sale_id=14 FOR UPDATE')->fetch();
for($i=0;$i<2;$i++) {
    $process=proc_open([PHP_BINARY,__DIR__.'/../cash_refund_concurrency_integration.php','--worker',$database,'discount'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $assert(is_resource($process),'Discounted refund worker starts');$workers[]=[$process,$pipes];
    $assert(trim((string)fgets($pipes[1]))==='ready','Discounted refund worker ready');
}
foreach($workers as [$process,$pipes]) {fwrite($pipes[0],"go\n");fflush($pipes[0]);}
usleep(300000);$pdo->commit();$results=[];
foreach($workers as [$process,$pipes]) {
    fclose($pipes[0]);$results[]=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($process)===0,'Discounted worker: '.$error);
}
$workers=[];sort($results);$assert($results===['refunded','refused'],'Only one overlapping discounted partial refund succeeds');
$assert($service->refundableQuantity(14)===1 && $service->refundableAmount(14)===25.0,'Concurrent discounted refund respects line quantity and actual paid caps');
$service->refund(1,'cashier',14,$damaged(14),'customer_return',null,'FINAL-CENT',true);
$assert($service->refundableAmount(14)===0.0,'Remaining discounted refund exhausts exact paid value');
$before=$snapshot();
foreach ([[null,false],['',true],['REF',false],[str_repeat('a',101),true]] as [$reference,$confirmed]) {
    $refused(fn()=>$service->refund(1,'cashier',10,$damaged(10),'customer_return',null,$reference,$confirmed));
    $assert($snapshot()===$before,'Missing external evidence changes no ledger, receipt, stock or audit');
}
$original=$pdo->query('SELECT * FROM sales WHERE sale_id=10')->fetch(PDO::FETCH_ASSOC);
$values=[];
for($i=0;$i<3;$i++) {
    $id=$service->refund(1,'cashier',10,$damaged(10),'customer_return',null,'CARD-'.$i,true);
    $values[]=(float)$pdo->query('SELECT refund_amount FROM cash_refunds WHERE refund_id='.$id)->fetchColumn();
}
$assert($values===[25.0,24.99,25.0],'Discounted historical partial refunds allocate all paid cents');
$assert($original===$pdo->query('SELECT * FROM sales WHERE sale_id=10')->fetch(PDO::FETCH_ASSOC),'Historical sale unchanged');
$assert($pdo->query('SELECT status FROM fiscal_periods WHERE period_id=1')->fetchColumn()==='closed','Historical fiscal period remains closed');
$exceptions=new \App\Services\RefundExceptionService($pdo);
$assert($service->refundableSaleForCashier(1,11)===null,'Other seller remains hidden without exception');
foreach ([['refund-admin','wrong'],['refund-super','fixture-password'],['refund-other','fixture-password']] as [$user,$password]) {
    $refused(fn()=>$exceptions->openSale(1,'cashier',11,$user,$password,'Original Cashier disabled'));
}
$token=$exceptions->openSale(1,'cashier',11,'refund-admin','fixture-password','Original Cashier disabled');
$assert($service->refundableSaleForCashier(1,11,$token)['cashier_id']===3,'One nominated sale exposed');
$refused(fn()=>$service->refundableSaleForCashier(1,12,$token));
$refused(fn()=>$service->refundableSaleForCashier(5,11,$token));
$exception=['token'=>$token,'username'=>'refund-admin','password'=>'fixture-password'];
$before=$snapshot();
$refused(fn()=>$service->refund(1,'cashier',11,$damaged(11),'customer_return',null,null,false,$exception));
$assert($snapshot()===$before,'Insufficient current drawer rolls back all effects and keeps exception usable');
$shifts->addDrawerMovement(1,'cashier','cash_in',50,'additional_float');
$pdo->exec("INSERT INTO fiscal_period_locks(period_id,table_name) VALUES (2,'cash_refunds')");
$before=$snapshot();
$refused(fn()=>$service->refund(1,'cashier',11,$damaged(11),'customer_return',null,null,false,$exception));
$assert($snapshot()===$before,'Closed current refund ledger changes nothing, including exception');
$pdo->exec('DELETE FROM fiscal_period_locks WHERE period_id=2');
$before=$snapshot();
$wrongApproval=$exception;$wrongApproval['password']='wrong';
$refused(fn()=>$service->refund(1,'cashier',11,$damaged(11),'customer_return',null,null,false,$wrongApproval));
$assert($snapshot()===$before,'A lookup grant alone cannot approve financial settlement');
$pdo->exec("CREATE TRIGGER fail_exception_receipt BEFORE INSERT ON refund_receipt_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture receipt unavailable'");
try {
    $service->refund(1,'cashier',11,$damaged(11),'customer_return',null,null,false,$exception);
    $assert(false,'Receipt failure must abort exception refund');
} catch(PDOException $e) { $assert(str_contains($e->getMessage(),'fixture receipt unavailable'),'Expected receipt fault'); }
$assert($snapshot()===$before,'Receipt failure rolls back money, receipt and exception consumption');
$pdo->exec('DROP TRIGGER fail_exception_receipt');
$pdo->exec("CREATE TRIGGER fail_exception_audit BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture audit unavailable'");
try {
    $service->refund(1,'cashier',11,$damaged(11),'customer_return',null,null,false,$exception);
    $assert(false,'Audit failure must abort exception refund');
} catch(PDOException $e) { $assert(str_contains($e->getMessage(),'fixture audit unavailable'),'Expected audit fault'); }
$assert($snapshot()===$before,'Audit failure rolls back all financial records and exception consumption');
$pdo->exec('DROP TRIGGER fail_exception_audit');
$pdo->exec("INSERT INTO fiscal_period_locks(period_id,table_name) VALUES (2,'stock_movements')");
$before=$snapshot();
$refused(fn()=>$service->refund(1,'cashier',11,[11=>['quantity'=>1,'disposition'=>'restockable']],'customer_return',null,null,false,$exception));
$assert($snapshot()===$before,'Closed applicable stock ledger rolls back every refund effect');
// A Damaged return makes no stock movement, so a stock-only lock does not block it.
$id=$service->refund(1,'cashier',11,$damaged(11),'customer_return',null,null,false,$exception);
$row=$pdo->query('SELECT * FROM cash_refunds WHERE refund_id='.$id)->fetch(PDO::FETCH_ASSOC);
$assert((int)$row['original_cashier_id']===3 && (int)$row['cashier_id']===1 && (int)$row['approved_by']===2 && (int)$row['shift_id']===1,'Immutable exception attribution preserves seller, issuer, approver, paying shift');
$assert($row['exception_reason']==='Original Cashier disabled','Immutable absence reason retained');
$assert((float)$shifts->calculateShift(1)['calculated_expected_cash']===25.0,'Exception pays from issuing shift once');
$before=$snapshot();
$refused(fn()=>$service->refund(1,'cashier',11,$damaged(11),'customer_return',null,null,false,$exception));
$assert($snapshot()===$before,'Reused exception cannot spend another remaining unit');
$assert($service->refundableSaleForCashier(1,11)===null,'Exception does not broaden routine history');
$assert((new \App\Services\RefundReceiptService($pdo))->forCashier($id,1,1)!==null,'Issuer retains own saved Refund Receipt');
$pdo->exec('DELETE FROM fiscal_period_locks WHERE period_id=2');
$expired=$exceptions->openSale(1,'cashier',12,'refund-admin','fixture-password','Original Cashier absent');
$pdo->exec('UPDATE cash_refund_exception_access SET expires_at=1 WHERE sale_id=12');
$refused(fn()=>$service->refundableSaleForCashier(1,12,$expired));
require __DIR__.'/safe_refund_http_scenarios.php';
echo "Safe refund current periods, external evidence and one-sale exception: passed\n";
