<?php
// A returned line spans two original sealed sources; unrelated loose pieces stay put.
$pdo->exec("INSERT INTO products(product_id,sku,barcode,product_name,unit_price,cost_price,pack_size) VALUES(110,'RETURN-PACK','RETURN-PACK','Barnats Sales Pack',60,3,6)");
$pdo->exec('INSERT INTO inventory(product_id,quantity_on_hand) VALUES(110,24)');
$pdo->exec("INSERT INTO product_batches(batch_id,product_id,batch_number,quantity,remaining_quantity,loose_quantity,sealed_quantity,cost_price) VALUES(110,110,'sealed-first',30,14,2,2,3),(111,110,'sealed-second',30,10,4,1,4)");
$pdo->exec("INSERT INTO sales(sale_id,cashier_id,total_amount,payment_method) VALUES(110,1,250,'card')");
$pdo->exec("INSERT INTO sale_items(sale_item_id,sale_id,product_id,quantity,unit_price,subtotal,selling_form,pack_size) VALUES(110,110,110,4,60,240,'pack',6),(111,110,110,1,10,10,'piece',NULL)");
$pdo->exec('INSERT INTO sale_item_batches(sale_item_id,batch_id,quantity) VALUES(110,110,12),(110,111,12),(111,110,1)');
$service->refund(1,'cashier',110,[110=>['quantity'=>1,'disposition'=>'damaged']],'customer_return',null,'PACK-COND-FIRST',true);
$packRefund=$service->refund(1,'cashier',110,[110=>['quantity'=>3,'disposition'=>'damaged'],111=>['quantity'=>1,'disposition'=>'damaged']],'customer_return',null,'PACK-COND-SECOND',true);
$packLine=$conditionService->details(1,'cashier',$packRefund)[0];$packItem=(int)$packLine['refund_item_id'];
$packPost=['csrf_token'=>'fixture-csrf','action'=>'correct_condition','refund_item_id'=>$packItem,'new_condition'=>'restockable','correction_reason'=>'Sealed complete packs inspected','expected_version'=>0,'request_token'=>str_repeat('b1',16),'fit_for_sale'=>1];
[$status,$packBefore]=$request(['condition_details'=>$packRefund],1,'cashier',false);
$packMoney=[];foreach(['sales','sale_items','cash_refunds','cash_refund_items','refund_receipt_details','cash_drawer_movements'] as $table)$packMoney[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
$before=$snapshot();$request($packPost,5);$assert($snapshot()===$before,'Another Cashier cannot correct a sealed returned pack through a forged item ID');
foreach(['pack_size'=>12,'quantity'=>1,'selling_form'=>'piece','batch_id'=>111] as $field=>$value){$forged=$packPost;$forged[$field]=$value;$request($forged);$assert($snapshot()===$before,'Pack HTTP '.$field.' tampering changes no stock');}
$before=$snapshot();$unconfirmed=$packPost;unset($unconfirmed['fit_for_sale']);$request($unconfirmed);
$assert($snapshot()===$before,'Opened/incomplete/unconfirmed packs cannot become sellable');
[$status]=$request($packPost);
$assert(str_contains($status,'303'),'Issuing Cashier corrects sealed packs through original refund');
$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=110')->fetchColumn()===42,'Three historical six-piece packs restore eighteen Base Units');
$packBalances=$pdo->query('SELECT remaining_quantity,sealed_quantity,loose_quantity,cost_price FROM product_batches WHERE product_id=110 ORDER BY batch_id')->fetchAll(PDO::FETCH_NUM);
$assert(array_map('intval',array_slice($packBalances[0],0,3))===[20,3,2] && array_map('intval',array_slice($packBalances[1],0,3))===[22,3,4],'Earlier refund offset restores exact original allocations and preserves loose pieces');
$assert((float)$packBalances[0][3]===3.0 && (float)$packBalances[1][3]===4.0,'Pack corrections preserve both original source costs');

$request($packPost);$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=110')->fetchColumn()===42,'Repeated pack submission never duplicates eighteen Base Units');
$assert($conditionService->details(1,'cashier',$packRefund)[1]['effective_condition']==='damaged','Pack correction leaves sibling returned piece unchanged');
[$status,$packAfter]=$request(['condition_details'=>$packRefund],1,'cashier',false);
if($fixturePath=getenv('RETURN_CONDITION_BROWSER_FIXTURE')){
    $data=json_decode(file_get_contents($fixturePath),true);$data['packBefore']=$packBefore;$data['packAfter']=$packAfter;
    file_put_contents($fixturePath,json_encode($data,JSON_THROW_ON_ERROR));
}
$packReverse=$packPost;$packReverse['new_condition']='damaged';$packReverse['expected_version']=1;$packReverse['request_token']=str_repeat('b2',16);unset($packReverse['fit_for_sale']);
$before=$snapshot();$stale=$packReverse;$stale['expected_version']=0;$request($stale);$assert($snapshot()===$before,'Stale pack preview changes no stock or history');
$request($packReverse);$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=110')->fetchColumn()===24,'Later pack reversal removes eighteen Base Units');
$reversed=$pdo->query('SELECT remaining_quantity,sealed_quantity,loose_quantity FROM product_batches WHERE product_id=110 ORDER BY batch_id')->fetchAll(PDO::FETCH_NUM);
$assert(array_map('intval',$reversed[0])===[14,2,2] && array_map('intval',$reversed[1])===[10,1,4],'Reversal removes exact restored sealed allocations while preserving loose pieces');
$packReject=static function(callable $mutate,callable $restore,string $message)use($pdo,$conditionService,$packItem,$snapshot,$assert,$refused):void{
    $mutate();$before=$snapshot();
    $refused(fn()=>$conditionService->correct(1,'cashier',$packItem,'restockable','Inspect sealed packs',2,bin2hex(random_bytes(24)),true));
    $assert($snapshot()===$before,$message);$restore();
};
$packReject(fn()=>$pdo->exec("UPDATE product_batches SET expiration_date='2000-01-01' WHERE batch_id=111"),fn()=>$pdo->exec('UPDATE product_batches SET expiration_date=NULL WHERE batch_id=111'),'Expired allocated pack prevents the entire correction');
$packReject(fn()=>$pdo->exec('UPDATE products SET pack_size=12 WHERE product_id=110'),fn()=>$pdo->exec('UPDATE products SET pack_size=6 WHERE product_id=110'),'Changed current pack size requires reconciliation and never substitutes twelve Base Units');
$packReject(fn()=>$pdo->exec('UPDATE cash_refund_items SET pack_size=NULL WHERE refund_item_id='.$packItem),fn()=>$pdo->exec('UPDATE cash_refund_items SET pack_size=6 WHERE refund_item_id='.$packItem),'Missing historical size cannot fall back to current Product size');
$packReject(fn()=>$pdo->exec('UPDATE sale_item_batches SET quantity=11 WHERE sale_item_id=110 AND batch_id=111'),fn()=>$pdo->exec('UPDATE sale_item_batches SET quantity=12 WHERE sale_item_id=110 AND batch_id=111'),'Incomplete/nonwhole historical pack allocation saves nothing');
$packReject(fn()=>$pdo->exec('UPDATE product_batches SET sealed_quantity=1 WHERE batch_id=110'),fn()=>$pdo->exec('UPDATE product_batches SET sealed_quantity=2 WHERE batch_id=110'),'Incompatible sealed/Base Unit balances require reconciliation');
$before=$snapshot();$pdo->exec("CREATE TRIGGER reject_pack_condition_audit BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected pack audit failure'");
try{$conditionService->correct(1,'cashier',$packItem,'restockable','Sealed complete packs inspected',2,str_repeat('b3',16),true);throw new LogicException('Injected pack failure accepted');}catch(PDOException $e){}finally{$pdo->exec('DROP TRIGGER reject_pack_condition_audit');}
$assert($snapshot()===$before,'Pack audit failure rolls back both batches, sealed/loose, inventory, movements and history');
$pdo->beginTransaction();$pdo->query('SELECT refund_item_id FROM cash_refund_items WHERE refund_item_id='.$packItem.' FOR UPDATE')->fetch();
for($i=0;$i<2;$i++){
    $process=proc_open([PHP_BINARY,__DIR__.'/../cash_refund_concurrency_integration.php','--worker',$database,'condition:'.$packItem],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $assert(is_resource($process),'Pack condition worker starts');$workers[]=[$process,$pipes];$assert(trim((string)fgets($pipes[1]))==='ready','Pack condition worker ready');
}
foreach($workers as [$process,$pipes]){fwrite($pipes[0],"go\n");fflush($pipes[0]);}
usleep(300000);$pdo->commit();$results=[];
foreach($workers as [$process,$pipes]){fclose($pipes[0]);$results[]=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($process)===0,'Pack condition worker '.$error);}
$workers=[];sort($results);$assert($results===['refunded','refused'],'Concurrent pack previews create exactly one accepted transition');
$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=110')->fetchColumn()===42 && $pdo->query('SELECT remaining_quantity,sealed_quantity,loose_quantity,cost_price FROM product_batches WHERE product_id=110 ORDER BY batch_id')->fetchAll(PDO::FETCH_NUM)===$packBalances,'Concurrent correction restores exactly historical Base Units and sealed stock once');
$packHistory=$conditionService->details(1,'cashier',$packRefund)[0]['corrections'];
$assert(count($packHistory)===3 && (int)$packHistory[0]['quantity']===3 && $packHistory[0]['selling_form']==='pack' && (int)$packHistory[0]['pack_size']===6,'Append-only pack history preserves the full historical quantity and six-piece selling form');
$assert(array_map('intval',$pdo->query('SELECT change_qty FROM stock_movements WHERE product_id=110 ORDER BY movement_id')->fetchAll(PDO::FETCH_COLUMN))===[18,-18,18],'Linked pack stock movements use Base Units for every accepted transition');
$pdo->exec('UPDATE product_batches SET sealed_quantity=0,loose_quantity=20 WHERE batch_id=110');$before=$snapshot();
$refused(fn()=>$conditionService->correct(1,'cashier',$packItem,'damaged','Packs already opened',3,str_repeat('b4',16),false));
$assert($snapshot()===$before,'Insufficient sealed stock cannot consume loose pieces or another sealed batch');
foreach($packMoney as $table=>$rows)$assert($rows===$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),'Pack correction preserves '.$table.' and printed evidence');
echo "Pack return condition persisted/HTTP/concurrency checks: passed\n";
