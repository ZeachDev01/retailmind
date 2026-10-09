<?php
// Uses only a freshly created disposable database, never the Store ledger.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_REFUND_DB_TESTS') !== '1') { echo "Legacy transition: skipped\n"; exit; }
require_once __DIR__.'/../bootstrap/app.php';
require_once __DIR__.'/../app/Services/SaleReversalService.php';
use App\Services\CashRefundService;
use App\Services\CashierShiftService;
$config = require __DIR__ . '/../config/database.php';
$connect = static function (?string $database = null) use ($config): PDO {
    return new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4%s',
        $config['host'], $config['port'], $database ? ';dbname=' . $database : ''),
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
};

$evidence=['no_prior_effects'=>true,'restockable'=>true,'refund_ineligibility_acknowledged'=>true,'paying_shift_id'=>1,'single_payout_confirmed'=>true,'external_completed'=>true,'payment_reference'=>'EXTERNAL-114'];
if (($argv[1]??'')==='--worker') {
    try {
        $database=$argv[2]??'';
        if (!preg_match('/^retailmind_legacy_test_[a-f0-9]{12}$/',$database)) throw new RuntimeException('Bad fixture name');
        $pdo=$connect($database); $_SESSION=['user_id'=>2,'role'=>'admin'];
        echo "ready\n";flush();fgets(STDIN);
        $id=(int)$argv[4];
        if ($argv[3]==='refund') (new CashRefundService($pdo,new CashierShiftService($pdo)))->refund(1,'cashier',$id,[$id=>['quantity'=>1,'disposition'=>'damaged']],'customer_return');
        else (new SaleReversalService($pdo))->{($argv[3]==='reject'?'rejectReversal':'approveReversal')}($id,2,'Verified test decision',$evidence);
        echo "committed\n";
    } catch (DomainException $e) { echo "refused\n"; }
    catch (PDOException $e) { if (!in_array((int)($e->errorInfo[1]??0),[1205,1213],true)) { fwrite(STDERR,$e->getMessage());exit(1); } echo "refused\n"; }
    exit;
}
$assert=static function(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); };
$database='retailmind_legacy_test_'.bin2hex(random_bytes(6));$server=$connect();$created=false;$failed=false;
try {
    $server->exec("CREATE DATABASE `{$database}`");$created=true;$pdo=$connect($database);
    $schema = file_get_contents(__DIR__ . '/../database/sql/schema.sql');
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', $schema, $tables);
    $assert(count($tables[0]) > 0, 'Canonical table definitions must be present.');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables[0] as $ddl) {
        $pdo->exec($ddl);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $migration = require __DIR__ . '/../database/migrations/202609260001_shared_database_backups.php';
    $migration['up']($pdo);

    $pdo->exec("INSERT INTO branches(branch_id,branch_name,branch_code) VALUES(1,'Fixture','TEST')");
    $pdo->exec("INSERT INTO roles(role_id,role_name) VALUES(1,'cashier'),(2,'admin'),(3,'super_admin'),(4,'inventory_manager')");
    $pdo->exec("INSERT INTO users(user_id,full_name,username,password_hash,role_id,branch_id) VALUES(1,'Cashier','cashier114','unused',1,1),(2,'Administrator','admin114','unused',2,1),(3,'Super Administrator','super114','unused',3,1),(4,'Inventory Manager','inventory114','unused',4,1)");
    $pdo->exec('UPDATE users SET must_change_password=0');
    $pdo->exec("INSERT INTO registers(register_id,name) VALUES(1,'Fixture Register')");
    $pdo->exec('INSERT INTO cashier_shifts(shift_id,cashier_id,register_id,opening_cash) VALUES(1,1,1,100)');
    $pdo->exec("INSERT INTO products(product_id,sku,product_name,unit_price,branch_id,quantity_sold) VALUES(1,'LEGACY114','Item',100,1,100)");
    $pdo->exec('INSERT INTO inventory(product_id,quantity_on_hand) VALUES(1,10)');
    $seed=static function(int $id,string $method='cash',string $settlement='cash',float $amount=200) use($pdo):void {
        $pdo->prepare('INSERT INTO sales(sale_id,cashier_id,shift_id,total_amount,payment_method) VALUES(?,1,1,500,?)')->execute([$id,$method]);
        $pdo->prepare('INSERT INTO sale_items(sale_item_id,sale_id,product_id,quantity,unit_price,subtotal) VALUES(?,?,1,5,100,500)')->execute([$id,$id]);
        $pdo->prepare('INSERT INTO product_batches(batch_id,product_id,quantity,remaining_quantity) VALUES(?,1,5,0)')->execute([$id]);
        $pdo->prepare('INSERT INTO sale_item_batches(sale_item_id,batch_id,quantity) VALUES(?,?,5)')->execute([$id,$id]);
        $pdo->prepare("INSERT INTO sale_reversals(reversal_id,sale_id,reversal_type,reason,requested_by,settlement_method,refund_amount) VALUES(?,?,'return','Original request',1,?,?)")->execute([$id,$id,$settlement,$amount]);
        $pdo->prepare('INSERT INTO sale_reversal_items(reversal_id,sale_item_id,product_id,quantity,unit_price,subtotal) VALUES(?,?,1,2,100,200)')->execute([$id,$id]);
    };
    $snapshot=static function() use($pdo):array { $out=[]; foreach(['sale_reversals','sale_reversal_items','inventory','product_batches','products','stock_movements','cash_refunds','cash_refund_items','activity_log','sales','cashier_shifts'] as $table)$out[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();return $out; };
    $legacy=new SaleReversalService($pdo);$shifts=new CashierShiftService($pdo);$refund=new CashRefundService($pdo,$shifts);
    $refuse=static function(callable $fn,string $message) use($snapshot,$assert):void { $before=$snapshot();try{$fn();throw new LogicException('Expected refusal: '.$message);}catch(DomainException|RuntimeException $e){$assert($snapshot()===$before,'No effects: '.$message);} };
    $_SESSION=['user_id'=>2,'role'=>'admin'];
    $seed(1);$before=$snapshot();$cash=$shifts->calculateShift(1)['calculated_expected_cash'];
    $legacy->approveReversal(1,2,'Verified two Restockable units',$evidence);
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn()===12,'Stock rises by 2');
    $assert((int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id=1')->fetchColumn()===2,'Allocated batch restores 2');
    $assert($shifts->calculateShift(1)['calculated_expected_cash']===$cash-200,'Original open drawer deducts once');
    $assert($legacy->reviewBalances(1)['paid_before']===300.0,'Nominal paid balance is 300');
    $before=$snapshot();$legacy->approveReversal(1,2,'Retry',$evidence);$assert($snapshot()===$before,'Committed approval retry immutable');
    $refuse(fn()=>$refund->refund(1,'cashier',1,[1=>['quantity'=>1,'disposition'=>'damaged']],'customer_return'),'Approved legacy blocks whole sale');
    $seed(2);$before=$snapshot();$legacy->rejectReversal(2,2,'Replaced by separate Cash Refund',$evidence);
    $assert($snapshot()['inventory']===$before['inventory'] && $snapshot()['cashier_shifts']===$before['cashier_shifts'],'Reject no stock/cash writes');
    $before=$snapshot();$legacy->rejectReversal(2,2,'Retry',$evidence);$assert($snapshot()===$before,'Reject retry one audit');
    $refund->refund(1,'cashier',2,[2=>['quantity'=>1,'disposition'=>'damaged']],'customer_return');
    $seed(3,'card','card');$cash=$shifts->calculateShift(1)['calculated_expected_cash'];$legacy->approveReversal(3,2,'External card verified',$evidence);$assert($shifts->calculateShift(1)['calculated_expected_cash']===$cash,'External settlement cash unchanged');
    $seed(4,'ewallet','ewallet');$legacy->approveReversal(4,2,'External e-wallet verified',$evidence);
    $seed(5,'cash','none',0);$legacy->approveReversal(5,2,'Zero-money Restockable return justified',$evidence);
    $refuse(fn()=>$refund->refund(1,'cashier',5,[5=>['quantity'=>1,'disposition'=>'damaged']],'customer_return'),'Zero approval blocks Cash Refunds');
    $seed(6,'cash','exchange',200);$refuse(fn()=>$legacy->approveReversal(6,2,'Review',$evidence),'Exchange credit unsupported');
    $seed(7,'cash','cash',201);$refuse(fn()=>$legacy->approveReversal(7,2,'Review',$evidence),'Arbitrary amount unsupported');
    $seed(8);$pdo->exec('DELETE FROM sale_item_batches WHERE sale_item_id=8');$refuse(fn()=>$legacy->approveReversal(8,2,'Review',$evidence),'Missing batch evidence');
    $seed(14,'cash','none',0);$pdo->exec("UPDATE sale_reversals SET reversal_type='exchange' WHERE reversal_id=14");
    $refuse(fn()=>$legacy->approveReversal(14,2,'Exchange zero-money',$evidence),'Zero-money exchange unsupported');
    $pdo->exec("UPDATE sale_reversals SET settlement_method='cash',refund_amount=200 WHERE reversal_id=14");
    $refuse(fn()=>$legacy->approveReversal(14,2,'Exchange missing separate sale',$evidence),'Exchange separate sale must be acknowledged');
    $exchangeEvidence=$evidence+['separate_replacement_sale_acknowledged'=>true];
    $legacy->approveReversal(14,2,'Ordinary refund, replacement sale separately paid',$exchangeEvidence);
    $seed(13);$pdo->exec('DELETE FROM sale_item_batches WHERE sale_item_id=13');
    $pdo->exec('UPDATE product_batches SET quantity=3 WHERE batch_id=13');
    $pdo->exec('INSERT INTO sale_item_batches(sale_item_id,batch_id,quantity) VALUES(13,13,2),(13,13,3)');
    $refuse(fn()=>$legacy->approveReversal(13,2,'Duplicate batch',$evidence),'Duplicate allocation cannot exceed batch capacity');
    $seed(9);$bad=$evidence;$bad['no_prior_effects']=false;$refuse(fn()=>$legacy->rejectReversal(9,2,'Review',$bad),'Outside effects investigation');
    $bad=$evidence;$bad['restockable']=false;$refuse(fn()=>$legacy->approveReversal(9,2,'Review',$bad),'Damaged not representable');
    $pdo->exec("UPDATE cashier_shifts SET status='closed',closed_at=NOW(),expected_cash=999,actual_cash=999,cash_variance=0 WHERE shift_id=1");
    $refuse(fn()=>$legacy->approveReversal(9,2,'Review',$evidence),'Closed paying drawer');$pdo->exec("UPDATE cashier_shifts SET status='open',closed_at=NULL WHERE shift_id=1");
    $pdo->exec("UPDATE sales SET sale_date='2020-01-01 12:00:00' WHERE sale_id=9");
    $pdo->exec("INSERT INTO fiscal_periods(period_name,start_date,end_date,status,created_by) VALUES('Closed history','2020-01-01','2020-01-31','closed',2)");
    $refuse(fn()=>$legacy->approveReversal(9,2,'Historical period',$evidence),'Closed original Fiscal Period');
    $pdo->exec("UPDATE sales SET sale_date=NOW() WHERE sale_id=9");
    $pdo->exec('UPDATE cashier_shifts SET locked_at=NOW() WHERE shift_id=1');
    $refuse(fn()=>$legacy->approveReversal(9,2,'Locked drawer',$evidence),'Locked drawer');$pdo->exec('UPDATE cashier_shifts SET locked_at=NULL WHERE shift_id=1');
    $bad=$evidence;$bad['paying_shift_id']=999;$refuse(fn()=>$legacy->approveReversal(9,2,'Different drawer',$bad),'Different physical paying drawer');
    $pdo->exec('UPDATE sales SET shift_id=NULL WHERE sale_id=9');$refuse(fn()=>$legacy->approveReversal(9,2,'Unassigned drawer',$evidence),'Unassigned drawer');$pdo->exec('UPDATE sales SET shift_id=1 WHERE sale_id=9');
    $pdo->exec("CREATE TRIGGER fail_legacy_audit BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture audit failure'");
    $refuse(fn()=>$legacy->rejectReversal(9,2,'Atomic rejection',$evidence),'Audit failure rollback');$pdo->exec('DROP TRIGGER fail_legacy_audit');
    foreach([['user_id'=>1,'role'=>'cashier'],['user_id'=>4,'role'=>'inventory_manager'],['user_id'=>3,'role'=>'super_admin']] as $session){$_SESSION=$session;$refuse(fn()=>$legacy->rejectReversal(9,$session['user_id'],'Forged authority',$evidence),'No Administrator authorization');}
    $_SESSION=['user_id'=>2,'role'=>'admin'];$refuse(fn()=>$legacy->requestReversal(9,'return','New request',[9=>1],2,'cash',100),'All new requests disabled');
    $race=static function(array $jobs) use($database,$assert):array {
        $workers=[];foreach($jobs as [$operation,$id]){$process=proc_open([PHP_BINARY,__FILE__,'--worker',$database,$operation,$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$assert(is_resource($process),'Worker starts');$assert(trim(fgets($pipes[1]))==='ready','Worker ready');$workers[]=[$process,$pipes];}
        foreach($workers as [$process,$pipes]){fwrite($pipes[0],"go\n");fflush($pipes[0]);}
        $results=[];foreach($workers as [$process,$pipes]){fclose($pipes[0]);$results[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($process)===0,'Worker exits '.$err);}return $results;
    };
    $seed(10);$pdo->exec("INSERT INTO sale_reversals(reversal_id,sale_id,reversal_type,reason,requested_by,settlement_method,refund_amount) VALUES(20,10,'return','Overlap',1,'cash',400)");$pdo->exec('INSERT INTO sale_reversal_items(reversal_id,sale_item_id,product_id,quantity,unit_price,subtotal) VALUES(20,10,1,4,100,400)');
    $results=$race([['approve',10],['approve',20]]);$assert(count(array_filter($results,fn($r)=>$r==='committed'))===1,'Overlapping approvals consume at most remaining quantity');
    $seed(11);$results=$race([['approve',11],['refund',11]]);$assert(count(array_filter($results,fn($r)=>$r==='committed'))===1,'Approval/refund serialized');
    $seed(12);$race([['reject',12],['refund',12]]);$assert($pdo->query('SELECT status FROM sale_reversals WHERE reversal_id=12')->fetchColumn()==='rejected','Reject/refund sees committed rejection or pending refusal');
    require __DIR__.'/support/legacy_transition_http_scenarios.php';
    echo "Legacy transition balances, immutable history, unsupported states, audit rollback and ledger races: passed\n";
} catch(Throwable $e){fwrite(STDERR,'Legacy transition test failed: '.$e->getMessage()."\n");$failed=true;}
finally{if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$pdo=null;if($created)$server->exec("DROP DATABASE `{$database}`");}
exit($failed?1:0);
