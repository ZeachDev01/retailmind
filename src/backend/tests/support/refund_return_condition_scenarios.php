<?php
// Canonical MySQL tables and the production signed-in handler, disposable Store only.
$conditionService = new App\Services\RefundReturnConditionService($pdo);
$pdo->exec("INSERT INTO products(product_id,sku,product_name,unit_price,cost_price) VALUES (100,'RETURN-100','Barnats',10,3)");
$pdo->exec('INSERT INTO inventory(product_id,quantity_on_hand) VALUES(100,9)');
$pdo->exec("INSERT INTO product_batches(batch_id,product_id,batch_number,quantity,remaining_quantity,loose_quantity,sealed_quantity,cost_price) VALUES(100,100,'original-return',10,9,9,0,3)");
$pdo->exec("INSERT INTO sales(sale_id,cashier_id,total_amount,payment_method) VALUES(100,1,20,'card')");
$pdo->exec("INSERT INTO sale_items(sale_item_id,sale_id,product_id,quantity,unit_price,subtotal,selling_form) VALUES(100,100,100,1,10,10,'piece'),(101,100,100,1,10,10,'piece')");
$pdo->exec('INSERT INTO sale_item_batches(sale_item_id,batch_id,quantity) VALUES(100,100,1),(101,100,1)');
$returnRefund=$service->refund(1,'cashier',100,[100=>['quantity'=>1,'disposition'=>'damaged'],101=>['quantity'=>1,'disposition'=>'damaged']],'customer_return',null,'RETURN-FIXTURE',true);
$returnItems=$conditionService->details(1,'cashier',$returnRefund);$returnItem=(int)$returnItems[0]['refund_item_id'];
$pdo->exec("UPDATE cashier_shifts SET status='closed' WHERE shift_id=1");
$financialBefore=[];
foreach(['sales','sale_items','cash_refunds','cash_refund_items','refund_receipt_details','cash_drawer_movements','cashier_shifts'] as $table)$financialBefore[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
$postCondition=['csrf_token'=>'fixture-csrf','action'=>'correct_condition','refund_item_id'=>$returnItem,'new_condition'=>'restockable','correction_reason'=>'Inspected whole and fit for sale','expected_version'=>0,'request_token'=>str_repeat('d',32),'fit_for_sale'=>1];
[$status,$details]=$request(['condition_details'=>$returnRefund],1,'cashier',false);
$assert(str_contains($status,'200') && str_contains($details,'Barnats') && str_contains($details,'Correct condition'),'Issuing Cashier sees correction in existing details');
if($fixturePath=getenv('RETURN_CONDITION_BROWSER_FIXTURE')) file_put_contents($fixturePath,json_encode(['before'=>$details,'refund'=>$returnRefund],JSON_THROW_ON_ERROR));
$before=$snapshot();$request($postCondition,5);$assert($snapshot()===$before,'Other Cashier cannot correct a changed refund item ID');
foreach (['quantity'=>2,'product_id'=>1,'batch_id'=>1,'price'=>0,'refund_amount'=>0,'selling_form'=>'pack'] as $field=>$value) {
    $forged=$postCondition;$forged[$field]=$value;$request($forged);$assert($snapshot()===$before,'HTTP '.$field.' tampering creates no movement');
}
[$status]=$request($postCondition);$assert(str_contains($status,'303'),'Condition save works without a separate approval or open cash drawer');
$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=100')->fetchColumn()===10,'Barnats 9 becomes 10 once');
$assert((int)$pdo->query('SELECT loose_quantity FROM product_batches WHERE batch_id=100')->fetchColumn()===10,'Original loose allocation restored without changing batch cost');
$request($postCondition);$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=100')->fetchColumn()===10,'HTTP retry does not repeat stock effect');
$after=$conditionService->details(1,'cashier',$returnRefund);
$assert($after[0]['effective_condition']==='restockable'&&$after[1]['effective_condition']==='damaged','Only selected line changes');
$stale=$postCondition;$stale['request_token']=str_repeat('e',32);$stale['new_condition']='damaged';$request($stale);$assert(count($conditionService->details(1,'cashier',$returnRefund)[0]['corrections'])===1,'Stale preview creates no second history entry');
[$status,$history]=$request(['condition_details'=>$returnRefund],1,'cashier',false);
$assert(str_contains($history,'Inspected whole and fit for sale')&&str_contains($history,'Edited Cashier'),'Saved details identify actor and reason');
if($fixturePath=getenv('RETURN_CONDITION_BROWSER_FIXTURE'))file_put_contents($fixturePath,json_encode(['before'=>$details,'after'=>$history,'refund'=>$returnRefund],JSON_THROW_ON_ERROR));
$conditionService->correct(1,'cashier',$returnItem,'damaged','Found concealed damage',1,str_repeat('f',32),false);
$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=100')->fetchColumn()===9,'Latest-condition reversal removes one piece');
foreach($financialBefore as $table=>$rows)$assert($rows===$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),'Condition correction leaves '.$table.' immutable');
$pdo->exec("UPDATE cashier_shifts SET status='open' WHERE shift_id=1");
$before=$snapshot();$pdo->exec("CREATE TRIGGER reject_condition_audit BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected condition audit failure'");
try{$conditionService->correct(1,'cashier',$returnItem,'restockable','Inspect again',2,str_repeat('1',32),true);throw new LogicException('Injected audit failure accepted');}catch(PDOException $e){}finally{$pdo->exec('DROP TRIGGER reject_condition_audit');}
$assert($snapshot()===$before && count($conditionService->details(1,'cashier',$returnRefund)[0]['corrections'])===2,'Audit failure rolls back stock movement and correction history');
$pdo->exec("UPDATE product_batches SET expiration_date='2000-01-01' WHERE batch_id=100");
$refused(fn()=>$conditionService->correct(1,'cashier',$returnItem,'restockable','Expired return',2,str_repeat('2',32),true));
$pdo->exec('UPDATE product_batches SET expiration_date=NULL WHERE batch_id=100');
$reviewService=new App\Services\RefundCorrectionReviewService($pdo);
$pdo->exec("INSERT INTO roles(role_id,role_name) VALUES(4,'inventory_manager')");
$pdo->exec("INSERT INTO users(user_id,display_name,username,password_hash,role_id,must_change_password) VALUES(6,'Inventory Manager','return-manager','unused',4,0)");
$refused(fn()=>$reviewService->createReview(2,'admin',$returnRefund,'stock_only',null,6,'Attempt duplicate correction'));
$pdo->exec("INSERT INTO refund_correction_reviews(refund_id,correction_type,status,cash_status,stock_status,explanation,reviewed_by,responsible_inventory_manager_id) VALUES($returnRefund,'stock_only','Reviewed � correction outstanding','not_required','pending','Historical ambiguous proposal',2,6)");
$legacyReview=(int)$pdo->lastInsertId();
$refused(fn()=>$reviewService->executeStockCorrection($legacyReview,6,'inventory_manager',100,1,'Attempt duplicate legacy execution'));
$refused(fn()=>$conditionService->correct(1,'cashier',$returnItem,'restockable','Pending legacy conflict',2,str_repeat('3',32),true));
$pdo->exec('DELETE FROM refund_correction_reviews WHERE review_id='.$legacyReview);
$allocation=$pdo->query('SELECT sale_item_batch_id,sale_item_id,batch_id,quantity FROM sale_item_batches WHERE sale_item_id=100')->fetch(PDO::FETCH_ASSOC);
$pdo->exec('DELETE FROM sale_item_batches WHERE sale_item_id=100');$before=$snapshot();
$refused(fn()=>$conditionService->correct(1,'cashier',$returnItem,'restockable','Missing allocation',2,str_repeat('4',32),true));
$assert($snapshot()===$before,'Missing allocation changes no stock or history');
$pdo->prepare('INSERT INTO sale_item_batches(sale_item_batch_id,sale_item_id,batch_id,quantity) VALUES(?,?,?,?)')->execute(array_values($allocation));
$pdo->exec("UPDATE fiscal_periods SET status='closed' WHERE period_id=2");$before=$snapshot();
try{$conditionService->correct(1,'cashier',$returnItem,'restockable','Closed fiscal period',2,str_repeat('5',32),true);throw new LogicException('Closed fiscal period accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
$assert($snapshot()===$before,'Closed fiscal period changes nothing');$pdo->exec("UPDATE fiscal_periods SET status='open' WHERE period_id=2");
$pdo->beginTransaction();$pdo->query('SELECT refund_item_id FROM cash_refund_items WHERE refund_item_id='.$returnItem.' FOR UPDATE')->fetch();
for($i=0;$i<2;$i++){
 $process=proc_open([PHP_BINARY,__DIR__.'/../cash_refund_concurrency_integration.php','--worker',$database,'condition:'.$returnItem],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $assert(is_resource($process),'Condition worker starts');$workers[]=[$process,$pipes];$assert(trim((string)fgets($pipes[1]))==='ready','Condition worker ready');
}
foreach($workers as [$process,$pipes]){fwrite($pipes[0],"go\n");fflush($pipes[0]);}
usleep(300000);$pdo->commit();$results=[];
foreach($workers as [$process,$pipes]){fclose($pipes[0]);$results[]=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($process)===0,'Condition worker '.$error);}
$workers=[];sort($results);$assert($results===['refunded','refused'],'Only one simultaneous condition transition succeeds');
$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=100')->fetchColumn()===10,'Concurrent correction restores exactly one piece');
$pdo->exec('UPDATE product_batches SET loose_quantity=0,remaining_quantity=0 WHERE batch_id=100');$before=$snapshot();
$refused(fn()=>$conditionService->correct(1,'cashier',$returnItem,'damaged','Stock sold since refund',3,str_repeat('6',32),false));
$assert($snapshot()===$before,'Insufficient original loose stock never takes another batch');
$pdo->exec("INSERT INTO products(product_id,sku,barcode,product_name,unit_price,cost_price) VALUES(102,'RETURN-SPLIT','RETURN-SPLIT','Split allocation item',10,3)");
$pdo->exec('INSERT INTO inventory(product_id,quantity_on_hand) VALUES(102,17)');
$pdo->exec("INSERT INTO product_batches(batch_id,product_id,batch_number,quantity,remaining_quantity,loose_quantity,sealed_quantity,cost_price) VALUES(102,102,'first-source',10,9,9,0,3),(103,102,'second-source',10,8,8,0,4)");
$pdo->exec("INSERT INTO sales(sale_id,cashier_id,total_amount,payment_method) VALUES(102,1,30,'card')");
$pdo->exec("INSERT INTO sale_items(sale_item_id,sale_id,product_id,quantity,unit_price,subtotal,selling_form) VALUES(102,102,102,3,10,30,'piece')");
$pdo->exec('INSERT INTO sale_item_batches(sale_item_id,batch_id,quantity) VALUES(102,102,1),(102,103,2)');
$service->refund(1,'cashier',102,[102=>['quantity'=>1,'disposition'=>'damaged']],'customer_return',null,'SPLIT-FIRST',true);
$splitRefund=$service->refund(1,'cashier',102,[102=>['quantity'=>2,'disposition'=>'damaged']],'customer_return',null,'SPLIT-SECOND',true);
$splitLine=$conditionService->details(1,'cashier',$splitRefund)[0];
$conditionService->correct(1,'cashier',(int)$splitLine['refund_item_id'],'restockable','Inspected both pieces',0,str_repeat('a1',16),true);
$assert((int)$pdo->query('SELECT loose_quantity FROM product_batches WHERE batch_id=102')->fetchColumn()===9 && (int)$pdo->query('SELECT loose_quantity FROM product_batches WHERE batch_id=103')->fetchColumn()===10,'Partial refund offset preserves exact historical batch provenance');
$assert((float)$pdo->query('SELECT cost_price FROM product_batches WHERE batch_id=103')->fetchColumn()===4.0,'Condition correction preserves source batch cost');
echo "Return condition persisted/HTTP checks: passed\n";
require __DIR__.'/refund_return_condition_pack_scenarios.php';
require __DIR__.'/refund_return_condition_manager_scenarios.php';
