<?php
require_once __DIR__.'/../bootstrap/app.php';
require_once __DIR__.'/../app/Services/FiscalPeriodGuardService.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach (["roles(role_id INTEGER PRIMARY KEY,role_name TEXT)","users(user_id INTEGER PRIMARY KEY,role_id INTEGER,status TEXT,full_name TEXT)","user_roles(user_id INTEGER,role_id INTEGER)","products(product_id INTEGER PRIMARY KEY,product_name TEXT)","inventory(product_id INTEGER PRIMARY KEY,quantity_on_hand INTEGER)","product_batches(batch_id INTEGER PRIMARY KEY,product_id INTEGER,remaining_quantity INTEGER,loose_quantity INTEGER,sealed_quantity INTEGER,expiration_date TEXT)","sale_item_batches(sale_item_batch_id INTEGER PRIMARY KEY,sale_item_id INTEGER,batch_id INTEGER,quantity INTEGER)","cash_refunds(refund_id INTEGER PRIMARY KEY,cashier_id INTEGER,refund_amount REAL)","cash_refund_items(refund_item_id INTEGER PRIMARY KEY,refund_id INTEGER,sale_item_id INTEGER,product_id INTEGER,quantity INTEGER,disposition TEXT,selling_form TEXT,pack_size INTEGER)","stock_movements(movement_id INTEGER PRIMARY KEY,product_id INTEGER,change_qty INTEGER,reason TEXT,moved_by INTEGER)","activity_log(log_id INTEGER PRIMARY KEY,user_id INTEGER,action TEXT,category TEXT,module TEXT,record_id INTEGER,previous_value TEXT,new_value TEXT,ip_address TEXT)","fiscal_periods(period_id INTEGER PRIMARY KEY,period_name TEXT,start_date TEXT,end_date TEXT,status TEXT)","fiscal_period_locks(period_id INTEGER,table_name TEXT)"] as $table) $pdo->exec('CREATE TABLE '.$table);
$pdo->exec("INSERT INTO roles VALUES(1,'cashier'); INSERT INTO users VALUES(1,1,'active','Carl'),(2,1,'active','Other'); INSERT INTO products VALUES(1,'Barnats'); INSERT INTO inventory VALUES(1,9); INSERT INTO product_batches VALUES(1,1,9,9,0,NULL); INSERT INTO sale_item_batches VALUES(1,1,1,1); INSERT INTO cash_refunds VALUES(1,1,10); INSERT INTO cash_refund_items VALUES(1,1,1,1,1,'damaged','piece',NULL); INSERT INTO fiscal_periods VALUES(1,'Open','2000-01-01','2100-01-01','open')");
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "ok - $message\n";}
function refuses($fn){try{$fn();}catch(DomainException|InvalidArgumentException $e){return;}throw new RuntimeException('Expected refusal');}
$service=new App\Services\RefundReturnConditionService($pdo);
$line=$service->details(1,'cashier',1)[0];
check($line['effective_condition']==='damaged' && $line['version']===0,'original condition visible');
$id=$service->correct(1,'cashier',1,'restockable','Inspected fit for sale',0,str_repeat('a',32),true);
check((int)$pdo->query('SELECT quantity_on_hand FROM inventory')->fetchColumn()===10,'Damaged return restores one original piece');
check((int)$pdo->query('SELECT loose_quantity FROM product_batches')->fetchColumn()===10,'Original loose batch restored');
check($service->correct(1,'cashier',1,'restockable','Inspected fit for sale',0,str_repeat('a',32),true)===$id,'Retry returns original correction');
refuses(fn()=>$service->correct(2,'cashier',1,'damaged','Other staff',1,str_repeat('b',32),false));
refuses(fn()=>$service->correct(1,'cashier',1,'damaged','Stale preview',0,str_repeat('b',32),false));
$service->correct(1,'cashier',1,'damaged','Inspection found damage',1,str_repeat('c',32),false);
check((int)$pdo->query('SELECT quantity_on_hand FROM inventory')->fetchColumn()===9,'Later reversal removes exactly one piece');
check($pdo->query('SELECT disposition FROM cash_refund_items')->fetchColumn()==='damaged' && (float)$pdo->query('SELECT refund_amount FROM cash_refunds')->fetchColumn()===10.0,'Original refund and money unchanged');
refuses(fn()=>$service->correct(1,'cashier',1,'damaged','No change',2,str_repeat('7',32),false));
refuses(fn()=>$service->correct(1,'cashier',1,'restockable','Not inspected',2,str_repeat('8',32),false));
check(count($service->details(1,'cashier',1)[0]['corrections'])===2,'Same condition and missing physical confirmation append nothing');
$pdo->exec("UPDATE cash_refund_items SET selling_form='pack',pack_size=6 WHERE refund_item_id=1");
refuses(fn()=>$service->correct(1,'cashier',1,'restockable','Unsupported pack',2,str_repeat('9',32),true));
check((int)$pdo->query('SELECT quantity_on_hand FROM inventory')->fetchColumn()===9,'Unsupported forms cannot move stock');
