<?php
// Store roles exercise the same correction operation and immutable evidence.
$managerLines=$conditionService->details(6,'inventory_manager',$splitRefund);
$assert($managerLines[0]['can_correct'],'Inventory Manager can correct authorized Store refund');

$storeRequest=static function(array $fields,int $user=6,string $workspace='inventory_manager',bool $post=false) use($address): array {
    $options=['method'=>$post?'POST':'GET','ignore_errors'=>true,'follow_location'=>0,'header'=>"Content-Type: application/x-www-form-urlencoded\r\nX-Fixture-User: {$user}\r\nX-Fixture-Workspace: {$workspace}\r\n",'timeout'=>10];
    if($post)$options['content']=http_build_query($fields);
    $body=file_get_contents('http://'.$address.'/invoice?'.http_build_query(['action'=>'refund_conditions']+($post?[]:$fields)),false,stream_context_create(['http'=>$options]));
    return [$http_response_header[0]??'',(string)$body];
};
[$status,$managerDetails]=$storeRequest(['sale_id'=>102]);
$assert(str_contains($status,'200') && str_contains($managerDetails,'Split allocation item') && str_contains($managerDetails,'Correct condition'),'Store receipt details expose manager correction');
// Manager save through existing Receipt History; closed drawers do not block stock-only work.
$managerItem=(int)$managerLines[0]['refund_item_id'];
$pdo->exec("UPDATE cashier_shifts SET status='closed' WHERE shift_id=1");
$immutable=[];
foreach(['sales','sale_items','cash_refunds','cash_refund_items','refund_receipt_details','cash_drawer_movements','cashier_shifts'] as $table)$immutable[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
$managerPost=['csrf_token'=>'fixture-csrf','action'=>'correct_condition','refund_item_id'=>$managerItem,'new_condition'=>'damaged','correction_reason'=>'Manager inspection found damage','expected_version'=>1,'request_token'=>str_repeat('c1',16)];
$before=$snapshot();
[$status]=$storeRequest($managerPost,2,'admin',true);
$assert(str_contains($status,'422') && $snapshot()===$before,'Administrator is read-only for routine condition correction');
[$status]=$storeRequest(['sale_id'=>102],4,'super_admin');
$assert(!str_contains($status,'200'),'Technical owner cannot access Store return details');
$refused(fn()=>$conditionService->details(4,'super_admin',$splitRefund));
$refused(fn()=>$conditionService->details(5,'inventory_manager',$splitRefund));
$pdo->exec('INSERT INTO user_roles(user_id,role_id) VALUES(1,1),(1,4),(5,1),(5,4)');
[$status,$own]=$request(['condition_details'=>$splitRefund],1,'cashier',false);
$assert(str_contains($status,'200') && str_contains($own,'Correct condition'),'Dual-role issuing Cashier keeps correction inside Cashier Workspace');
[$status]=$request(['condition_details'=>$splitRefund],5,'cashier',false);
$assert(str_contains($status,'403'),'Dual-role Cashier cannot preview another issuer refund');
$request($managerPost,5,'cashier');
$storeRequest($managerPost,5,'cashier',true);
$assert($snapshot()===$before,'Dual-role Cashier POSTs never inherit Inventory Manager authority');
foreach(['quantity'=>1,'product_id'=>1,'selling_form'=>'pack','refund_id'=>1,'refund_amount'=>0,'actor_id'=>2] as $field=>$value) {
    $forged=$managerPost;$forged[$field]=$value;$storeRequest($forged,6,'inventory_manager',true);
    $assert($snapshot()===$before,'Store entry rejects '.$field.' tampering');
}
[$status,$managerAfter]=$storeRequest($managerPost,6,'inventory_manager',true);
$assert(str_contains($status,'200') && str_contains($managerAfter,'Correction saved.') && str_contains($managerAfter,'Manager inspection found damage'),'Inventory Manager saves and sees history immediately');
$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=102')->fetchColumn()===17,'Store save removes exactly two pieces');
$assert($conditionService->details(6,'inventory_manager',$splitRefund)[0]['effective_condition']==='damaged','Manager effective condition persisted');
$otherSplit=(int)$pdo->query('SELECT refund_id FROM cash_refunds WHERE sale_id=102 ORDER BY refund_id LIMIT 1')->fetchColumn();
$assert($conditionService->details(6,'inventory_manager',$otherSplit)[0]['effective_condition']==='damaged' && !$conditionService->details(6,'inventory_manager',$otherSplit)[0]['corrections'],'Store correction leaves another refunded line untouched');
$saved=$snapshot();$storeRequest($managerPost,6,'inventory_manager',true);
$assert($snapshot()===$saved,'Store retry creates no duplicate correction or stock movement');
$stale=$managerPost;$stale['request_token']=str_repeat('c2',16);$stale['new_condition']='restockable';$stale['fit_for_sale']='1';
[$status,$error]=$storeRequest($stale,6,'inventory_manager',true);
$assert(str_contains($status,'422') && str_contains($error,'condition changed') && !str_contains($error,'Correction saved') && $snapshot()===$saved,'Store stale preview fails without completion claim');
[$status,$adminHistory]=$storeRequest(['sale_id'=>102],2,'admin');
$assert(str_contains($status,'200') && str_contains($adminHistory,'Inventory Manager') && str_contains($adminHistory,'Manager inspection found damage') && str_contains($adminHistory,'Stock movement #') && str_contains($adminHistory,'2 piece') && !str_contains($adminHistory,'refund-condition-form'),'Administrator sees actor reason quantity and stock evidence without routine action');
$assert(str_contains($adminHistory,'View linked Refund Correction Reviews'),'Legacy correction review entry stays visible');
if (getenv('RUN_REFUND_MANAGER_BROWSER')==='1') {
    $process=proc_open(['node',__DIR__.'/../refund_return_condition_manager_browser_test.js','--browser','http://'.$address], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$browserPipes);
    $assert(is_resource($process),'Store return browser starts');fclose($browserPipes[0]);
    $output=stream_get_contents($browserPipes[1]);$error=stream_get_contents($browserPipes[2]);fclose($browserPipes[1]);fclose($browserPipes[2]);
    $assert(proc_close($process)===0,'Store return browser: '.$output.$error);echo $output;
}
foreach($immutable as $table=>$rows)$assert($rows===$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),'Store condition correction leaves '.$table.' immutable');
// The Store entry point consumes shared pack behavior without a second writer.
[$status,$packStore]=$storeRequest(['sale_id'=>110]);
$assert(str_contains($status,'200') && str_contains($packStore,'3 packs (6 pieces per pack)'),'Store details preserve historical pack form and size');
$packStorePost=['csrf_token'=>'fixture-csrf','action'=>'correct_condition','refund_item_id'=>$packItem,'new_condition'=>'damaged','correction_reason'=>'Manager pack inspection','expected_version'=>3,'request_token'=>str_repeat('c3',16)];
$before=$snapshot();[$status,$packFailure]=$storeRequest($packStorePost,6,'inventory_manager',true);
$assert(str_contains($status,'422') && !str_contains($packFailure,'Correction saved') && $snapshot()===$before,'Store pack save shares insufficient sealed stock guard');
$pdo->exec('UPDATE product_batches SET sealed_quantity=3,loose_quantity=2 WHERE batch_id=110');
[$status,$packStoreAfter]=$storeRequest($packStorePost,6,'inventory_manager',true);
$assert(str_contains($status,'200') && str_contains($packStoreAfter,'Correction saved. 3 packs of Barnats Sales Pack removed from available stock.') && (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=110')->fetchColumn()===24,'Manager Store pack save removes eighteen Base Units with explicit form confirmation');
$assert($conditionService->details(6,'inventory_manager',$packRefund)[1]['effective_condition']==='damaged','Manager pack save leaves sibling returned piece unchanged');
$pdo->exec("UPDATE cashier_shifts SET status='open' WHERE shift_id=1");
if($fixturePath=getenv('RETURN_CONDITION_MANAGER_BROWSER_FIXTURE'))file_put_contents($fixturePath,json_encode(['before'=>$managerDetails,'after'=>$managerAfter,'admin'=>$adminHistory,'cashier'=>$own],JSON_THROW_ON_ERROR));
echo "Return condition Store roles persisted/HTTP checks: passed\n";
