<?php
// Production receipt queries and HTTP handler against a disposable database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_CHECKOUT_DB_TESTS') !== '1') { echo "Receipt History integration: skipped\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
set_exception_handler(static function (Throwable $exception): void { fwrite(STDERR, $exception->getMessage()."\n"); exit(1); });

use App\Core\Database;
use App\Receipts\ReceiptDetailsService;
use App\Receipts\ReceiptTableService;

$config = require __DIR__ . '/../config/database.php';
$server = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database = 'retailmind_receipt_test_' . bin2hex(random_bytes(6));
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
try {
    $server->exec("CREATE DATABASE `{$database}`");
    $config['database'] = $database;
    $pdo = Database::connection($config);
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__.'/../database/sql/schema.sql'), $tables);
    $assert(count($tables[0]) > 0, 'Canonical receipt fixture tables found');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables[0] as $ddl) $pdo->exec($ddl);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO branches(branch_id,branch_name,branch_code) VALUES(1,'Receipt Store','RECEIPT');
        INSERT INTO roles(role_id,role_name) VALUES(1,'cashier');
        INSERT INTO users(user_id,full_name,username,password_hash,role_id,branch_id,must_change_password) VALUES(1,'Saved Seller','receipt-one','unused',1,1,0),(2,'Other Seller','receipt-two','unused',1,1,0);
        INSERT INTO registers(register_id,name) VALUES(1,'Saved Till'),(2,'Other Till');
        INSERT INTO cashier_shifts(shift_id,cashier_id,register_id,opening_cash,locked_at) VALUES(1,1,1,100,CURRENT_TIMESTAMP),(2,2,2,100,NULL);
        INSERT INTO products(product_id,sku,product_name,unit_price,branch_id) VALUES(1,'REC-1','Saved Product',10,1)");
    $dates=[1=>'2026-09-30 23:59:59',2=>'2026-10-01 00:00:00',3=>'2026-10-01 23:59:59',4=>'2026-10-02 00:00:00',5=>'2026-10-01 08:39:18',6=>'2026-10-01 08:39:18'];
    $insert=$pdo->prepare("INSERT INTO sales(sale_id,cashier_id,shift_id,total_amount,payment_method,cash_received,change_due,sale_date) VALUES(?,?,?,10,'cash',20,10,?)");
    foreach($dates as $id=>$date) {
        $owner=$id===5?2:1;
        $insert->execute([$id,$owner,$owner,$date]);
        $pdo->prepare('INSERT INTO sale_items(sale_item_id,sale_id,product_id,quantity,unit_price,subtotal) VALUES(?,?,1,1,10,10)')->execute([$id,$id]);
    }
    $pdo->exec("INSERT INTO cash_refunds(refund_id,sale_id,shift_id,cashier_id,refund_amount,payment_method,reason) VALUES(1,2,1,1,4,'cash','customer_return'),(2,3,1,1,10,'cash','customer_return');
        INSERT INTO sale_reversals(reversal_id,sale_id,reversal_type,status,reason,settlement_method,refund_amount,requested_by) VALUES(1,6,'refund','approved','Legacy evidence','cash',2,1),(2,1,'return','pending','Pending history','none',0,1),(3,4,'return','rejected','Rejected history','none',0,1)");
    $pdo->prepare('INSERT INTO checkout_attempts(cashier_id,attempt_id,request_hash,sale_id) VALUES(1,?,?,6)')->execute([str_repeat('a',32),str_repeat('b',64)]);
    $pdo->beginTransaction();
    (new ReceiptDetailsService($pdo))->preserveSale(6);
    $pdo->commit();
    $saved=$pdo->query('SELECT details_json FROM sale_receipt_details WHERE sale_id=6')->fetchColumn();
    $pdo->exec("UPDATE registers SET name='Renamed Till' WHERE register_id=1; UPDATE users SET full_name='Renamed Seller' WHERE user_id=1; UPDATE products SET product_name='Renamed Product' WHERE product_id=1");
    $service=new ReceiptTableService($pdo);
    $request=['date_from'=>'2026-10-01','date_to'=>'2026-10-01'];
    $rows=$service->fetch($request,1)['data'];
    $assert(array_column($rows,'sale_id')===[3,6,2],'Receipt day filters use underlying Manila timestamps and private stable ordering');
    $byId=array_column($rows,null,'sale_id');
    $assert($byId[2]['total_amount']===10.0 && $byId[2]['cash_refunded_amount']===4.0 && $byId[2]['refunded_amount']===4.0 && $byId[2]['remaining_net_amount']===6.0,'Original paid, refunded and net stay separate');
    $assert($byId[6]['cash_refunded_amount']===0.0 && $byId[6]['legacy_refunded_amount']===2.0 && $byId[6]['remaining_net_amount']===8.0,'Approved Legacy Reversal value stays distinct from Cash Refunds');
    $assert($byId[6]['sale_date_display']==='Oct 1, 2026 · 8:39 AM','Receipt table does not shift transaction instant again');
    foreach(['partial'=>[2],'full'=>[3],'none'=>[6]] as $status=>$ids) $assert(array_column($service->fetch($request+['refund_status'=>$status],1)['data'],'sale_id')===$ids,'Cash Refund filter '.$status.' is distinct');
    foreach(['pending'=>[1],'approved'=>[6],'rejected'=>[4]] as $status=>$ids) $assert(array_column($service->fetch(['reversal_status'=>$status],1)['data'],'sale_id')===$ids,'Legacy Reversal filter '.$status.' stays distinct');
    $snapshot=static function() use($pdo): array {
        $result=[];
        foreach(['sales','sale_items','cash_refunds','sale_reversals','cash_drawer_movements','stock_movements','activity_log','sale_receipt_details','checkout_attempts'] as $table) $result[$table]=$pdo->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
        return $result;
    };
    $before=$snapshot();
    require __DIR__.'/support/receipt_history_http_scenarios.php';
    $assert($snapshot()===$before,'Receipt navigation, filters, reload and reprints create no financial or audit records');
    $assert($pdo->query('SELECT details_json FROM sale_receipt_details WHERE sale_id=6')->fetchColumn()===$saved,'Historical customer snapshot remains byte-for-byte stable');
    echo "Receipt History amounts, status filters and Manila boundaries: passed\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
