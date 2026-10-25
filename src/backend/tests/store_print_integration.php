<?php
// Saved-receipt print boundary, disposable database; no physical printer used.
require __DIR__ . '/../bootstrap/app.php';
use App\Core\Database;
use App\Receipts\StorePrintService;
$config = require __DIR__ . '/../config/database.php';
$server = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'retailmind_print_test_' . bin2hex(random_bytes(5));
$check = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$process = null;
$router = tempnam(sys_get_temp_dir(), 'rm-print-router-');
$log = tempnam(sys_get_temp_dir(), 'rm-print-log-');
$private = sys_get_temp_dir() . '/rm-print-private-' . bin2hex(random_bytes(6));
try {
    $server->exec("CREATE DATABASE `$name`");
    $config['database'] = $name;
    $pdo = Database::connection($config);
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__ . '/../database/sql/schema.sql'), $tables);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables[0] as $ddl) $pdo->exec($ddl);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO branches(branch_id,branch_name,branch_code) VALUES(1,'Print Store','PRINT');
        INSERT INTO roles(role_id,role_name) VALUES(1,'cashier');
        INSERT INTO users(user_id,full_name,username,password_hash,role_id,branch_id,must_change_password) VALUES(1,'Seller','seller','unused',1,1,0),(2,'Other','other','unused',1,1,0);
        INSERT INTO registers(register_id,name,paper_width_mm) VALUES(1,'Till','58');
        INSERT INTO cashier_shifts(shift_id,cashier_id,register_id,opening_cash,status) VALUES(1,1,1,100,'open');
        INSERT INTO products(product_id,sku,barcode,product_name,unit_price,cost_price,branch_id) VALUES(1,'P1','P1','Milk',10,3,1);
        INSERT INTO sales(sale_id,cashier_id,shift_id,total_amount,payment_method) VALUES(1,1,1,10,'cash');
        INSERT INTO sale_items(sale_item_id,sale_id,product_id,quantity,unit_price,subtotal) VALUES(1,1,1,1,10,10)");
    $service = new StorePrintService($pdo, 'test-store');
    $job = $service->submit(1, 'cashier', 1, 'sale', 1);
    $check($job['status'] === 'queued', 'Saved receipt queues independently');
    $check($service->submit(1, 'cashier', 1, 'sale', 1)['job_id'] === $job['job_id'], 'Duplicate taps use one job');
    foreach ([[2,'cashier',1], [1,'admin',1], [1,'cashier',2]] as [$actor,$role,$store]) {
        try { $service->submit($actor,$role,$store,'sale',1); throw new RuntimeException('Authorization bypass'); }
        catch (DomainException $e) {}
    }
    $claimed = $service->claim();
    $check($claimed['job_id'] === $job['job_id'] && str_contains($claimed['payload'], 'Milk'), 'Worker receives saved receipt content');
    $check(str_contains($claimed['payload'], 'Total Amount PHP 10.00'), 'Printable labels and amounts remain separated');
    foreach (explode("\n", substr($claimed['payload'], 2)) as $line) $check(strlen($line) <= 32, '58mm receipt wraps every printable line');
    $check($service->claim() === null, 'Claimed delivery never silently replays');
    $service->finish($job['job_id'], 'uncertain');
    $next = $service->submit(1,'cashier',1,'sale',1,$job['job_id']);
    $check($next['job_id'] !== $job['job_id'], 'Explicit informed reprint creates a new delivery');
    $check($service->submit(1,'cashier',1,'sale',1,$job['job_id'])['job_id'] === $next['job_id'], 'Duplicate reprint taps deduplicate');
    $check((new StorePrintService($pdo,'different-installation'))->claim()===null, 'Worker is bound to its intended deployment');
    $check((int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 1 && (int)$pdo->query('SELECT SUM(quantity) FROM sale_items')->fetchColumn() === 1, 'Print retries never repeat sales or stock effects');
    $bootstrap = var_export(realpath(__DIR__ . '/../bootstrap/app.php'), true);
    $endpoint = var_export(realpath(__DIR__ . '/../../frontend/components/cashier/store_print.php'), true);
    file_put_contents($router, '<?php require ' . $bootstrap . '; $_ENV["DB_NAME"]=' . var_export($name,true) . '; $_ENV["BACKUP_STORAGE_PATH"]=' . var_export($private,true) . ';
        $_ENV["STORE_PRINT_DEPLOYMENT"]="test-store";
        App\Core\Session::start();
        $_SESSION["user_id"]=(int)($_SERVER["HTTP_X_FIXTURE_USER"] ?? 1);
        $_SESSION["role"]="cashier"; $_SESSION["session_version"]=1;
        $_SESSION["csrf_token"]="fixture-csrf"; $_SESSION["_authenticated_at"]=time();
        $_SESSION["_last_activity"]=time(); $_SESSION["_restore_epoch"]=App\Backup\RecoveryStore::epoch();
        require ' . $endpoint . ';');
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', $address, $router], [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes);
    fclose($pipes[0]);
    for ($i=0;$i<100;$i++) { if ($probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1)) { fclose($probe); break; } usleep(20000); }
    $request = static function ($token, $actor=1, $method='POST') use ($address) {
        $body = http_build_query(['csrf_token'=>$token,'receipt_type'=>'sale','receipt_id'=>1]);
        $context = stream_context_create(['http'=>['method'=>$method,'header'=>"Content-Type: application/x-www-form-urlencoded\r\nX-Fixture-User: $actor",'content'=>$method==='POST'?$body:'','ignore_errors'=>true,'follow_location'=>0]]);
        $result = file_get_contents('http://'.$address.'/store_print.php',false,$context);
        preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0],$status);
        return [(int)$status[1],$result];
    };
    $check($request('wrong')[0]===403, 'HTTP boundary rejects forged requests');
    $check($request('fixture-csrf',1,'GET')[0]===405, 'GET cannot queue printing');
    $check($request('fixture-csrf',2)[0]===422, 'HTTP boundary rejects another cashier receipt');
    [$status,$json]=$request('fixture-csrf');
    $check($status===200 && json_decode($json,true)['job_id']===$next['job_id'], 'Authenticated HTTP retry reconciles existing job');
    $check(!str_contains($json,'payload') && !str_contains($json,'Milk'), 'Status response contains no saved receipt payload');
    if (PHP_OS_FAMILY === 'Windows') {
        // A random nonexistent queue compiles/runs the delivery adapter without printing paper.
        $workerEnv = array_merge(getenv(), ['DB_NAME'=>$name, 'STORE_PRINT_DEPLOYMENT'=>'test-store', 'STORE_PRINT_PRINTER'=>'RetailMind_MISSING_TEST_' . bin2hex(random_bytes(8))]);
        $worker = proc_open([PHP_BINARY, __DIR__ . '/../scripts/store_print_worker.php'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $workerPipes, null, $workerEnv);
        fclose($workerPipes[0]);
        stream_get_contents($workerPipes[1]); fclose($workerPipes[1]);
        $workerError = stream_get_contents($workerPipes[2]); fclose($workerPipes[2]);
        $check(proc_close($worker)===0, 'PC worker drains queue without crashing: '.$workerError);
        $check($service->status(1,'sale',1,$next['job_id'])['status']==='uncertain', 'Unavailable Windows printer yields uncertain delivery without replay');
        $check($service->claim()===null, 'Failed Windows delivery is never automatically claimed again');
    }
    $pdo->exec("INSERT INTO cash_refunds(refund_id,sale_id,shift_id,cashier_id,refund_amount,payment_method,reason) VALUES(1,1,1,1,5,'cash','customer_return')");
    $pdo->beginTransaction();
    (new App\Receipts\RefundReceiptService($pdo))->preserve(1);
    $pdo->commit();
    $refund = $service->submit(1,'cashier',1,'refund',1);
    $refundPayload = $service->claim();
    $check($refundPayload['job_id']===$refund['job_id'] && str_contains($refundPayload['payload'],'Total refunded PHP 5.00'), 'Refund printing uses saved customer-facing refund details');
    $check($service->submit(1,'cashier',1,'refund',1)['job_id']===$refund['job_id'], 'Repeated refund printing reuses its delivery');
    $check((int)$pdo->query('SELECT COUNT(*) FROM cash_refunds')->fetchColumn()===1, 'Refund printing creates no additional refund');
    echo "Store printing integration passed\n";
} finally {
    if (is_resource($process)) proc_terminate($process);
    unlink($router); unlink($log);
    foreach (glob($private . '/*') ?: [] as $file) unlink($file);
    if (is_dir($private)) rmdir($private);
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}
