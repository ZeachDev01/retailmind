<?php
// Cashier authorization and race fixtures never write to the configured Store.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_CHECKOUT_DB_TESTS') !== '1') { echo "Cashier operation serialization: skipped (set RUN_CHECKOUT_DB_TESTS=1)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/HeldSaleService.php';
require_once __DIR__ . '/../app/Services/StockIssueService.php';
use App\Services\CashierShiftService;
use App\Services\HeldSaleService;
$config = require __DIR__ . '/../config/database.php';
$connect = static fn(?string $database = null): PDO => new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4%s', $config['host'], $config['port'], $database ? ';dbname=' . $database : ''), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$refused = static function (callable $operation) use ($assert): void {
    try { $operation(); } catch (DomainException | RuntimeException $e) { return; }
    $assert(false, 'Expected operational refusal');
};
if (($argv[1] ?? '') === '--worker') {
    try {
        $database = $argv[2] ?? '';
        $assert((bool)preg_match('/^retailmind_shift_test_[a-f0-9]{12}$/D', $database), 'Disposable database name');
        $pdo = $connect($database);
        $shifts = new CashierShiftService($pdo);
        echo "ready\n"; flush();
        $assert(trim((string)fgets(STDIN)) === 'go', 'Worker barrier');
        switch ($argv[3]) {
            case 'hold': (new HeldSaleService($pdo, $shifts))->hold(1, 'cashier', [1 => ['qty' => 1]]); break;
            case 'close': $shifts->closeShift(1, 100, ''); break;
            case 'lock': $shifts->lockRegister(1, 'cashier'); break;
        }
        echo "saved\n";
    } catch (DomainException | RuntimeException $e) {
        // The shared Store write gate may refuse a competing write immediately.
        if ($e instanceof PDOException && !in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) { fwrite(STDERR, $e->getMessage()); exit(1); }
        echo "refused\n";
    }
    exit;
}
$database = 'retailmind_shift_test_' . bin2hex(random_bytes(6));
$server = $connect(); $created = false; $workers = [];
$httpServer = null; $httpFiles = [];
try {
    $server->exec("CREATE DATABASE `{$database}`"); $created = true; $pdo = $connect($database);
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__ . '/../sql/schema.sql'), $tables);
    $assert(count($tables[0]) > 0, 'Production schema parsed');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); foreach ($tables[0] as $ddl) $pdo->exec($ddl); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $migration = require __DIR__ . '/../database/migrations/202609260001_shared_database_backups.php'; $migration['up']($pdo);
    $pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code) VALUES (1,'Fixture Store','TEST')");
    $pdo->exec("INSERT INTO roles (role_id,role_name) VALUES (1,'admin'),(2,'cashier'),(3,'inventory_manager')");
    $password = password_hash('fixture-password', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (user_id,full_name,username,password_hash,role_id,branch_id) VALUES (1,'Assigned Administrator','fixture-admin',?,1,1),(2,'Other Cashier','fixture-other',?,2,1),(3,'Reviewer','fixture-reviewer',?,3,1)"); $stmt->execute([$password,$password,$password]);
    $pdo->exec('UPDATE users SET must_change_password=0');
    $pdo->exec('CREATE TABLE user_roles (user_id INT NOT NULL,role_id INT NOT NULL,is_primary TINYINT NOT NULL DEFAULT 0,PRIMARY KEY(user_id,role_id))');
    $pdo->exec('INSERT INTO user_roles (user_id,role_id,is_primary) VALUES (1,1,1),(1,2,0),(2,2,1),(3,3,1)');
    $pdo->exec("INSERT INTO registers (register_id,name) VALUES (1,'Fixture Till')");
    $pdo->exec("INSERT INTO products (product_id,sku,product_name,unit_price,branch_id) VALUES (1,'TEST','Fixture Item',25,1)");
    $pdo->exec('INSERT INTO inventory (product_id,quantity_on_hand) VALUES (1,10)');
    $shifts = new CashierShiftService($pdo); $held = new HeldSaleService($pdo,$shifts);
    $shiftId = $shifts->openShift(1,'cashier',1,100);
    $reporter = new StockIssueService($pdo,null,'cashier');
    $reportId = $reporter->submitReport(['product_id'=>1,'category'=>'damaged','quantity'=>1,'explanation'=>'Broken packaging'],1);
    // A private router creates fixture sessions, then runs the real HTTP handler
    // and account/workspace revalidation against this disposable database.
    $router = tempnam(sys_get_temp_dir(), 'cashier-http-');
    $serverLog = tempnam(sys_get_temp_dir(), 'cashier-http-log-');
    $httpFiles = [$router, $serverLog];
    $bootstrap = var_export(realpath(__DIR__ . '/../bootstrap/app.php'), true);
    $endpoint = var_export(realpath(__DIR__ . '/../../frontend/components/cashier/stock_issues.php'), true);
    $posEndpoint = var_export(realpath(__DIR__ . '/../../frontend/components/cashier/pos.php'), true);
    $fixtureDatabase = var_export($database, true);
    $routerCode = '<?php require ' . $bootstrap . '; $_ENV["DB_NAME"]=' . $fixtureDatabase . ';'
        . ' App\Core\Session::start(); $_SESSION=["user_id"=>(int)($_SERVER["HTTP_X_FIXTURE_USER"]??1),"role"=>($_SERVER["HTTP_X_FIXTURE_WORKSPACE"]??"cashier"),"session_version"=>1,"csrf_token"=>"fixture-csrf","_restore_epoch"=>App\Backup\RecoveryStore::epoch()]; if ($_SERVER["REQUEST_URI"]==="/pos") require ' . $posEndpoint . '; else require ' . $endpoint . ';';
    file_put_contents($router, $routerCode);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $assert(is_resource($socket), 'Reserve local HTTP port');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $httpServer = proc_open([PHP_BINARY, '-S', $address, $router], [0=>['pipe','r'],1=>['file',$serverLog,'a'],2=>['file',$serverLog,'a']], $httpPipes);
    $assert(is_resource($httpServer), 'Private HTTP server starts'); fclose($httpPipes[0]);
    $ready = false;
    for ($attempt=0;$attempt<100;$attempt++) { $probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if ($probe) { fclose($probe);$ready=true;break; } usleep(20000); }
    $assert($ready, 'Private HTTP server ready');
    $request = static function (int $user, string $workspace, array $fields, string $route = '/stock-issues') use ($address): array {
        $context=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'follow_location'=>0,'header'=>"Content-Type: application/x-www-form-urlencoded\r\nX-Fixture-User: {$user}\r\nX-Fixture-Workspace: {$workspace}\r\n",'content'=>http_build_query($fields),'timeout'=>10]]);
        $body=file_get_contents('http://'.$address.$route,false,$context);
        return [$http_response_header[0]??'',(string)$body];
    };
    $post=['csrf_token'=>'fixture-csrf','product_id'=>1,'category'=>'damaged','quantity'=>1,'explanation'=>'HTTP packaging defect','cashier_id'=>2,'shift_id'=>999,'role'=>'admin'];
    [$status,$body]=$request(1,'cashier',$post);
    $assert(str_contains($status,'200'), 'Assigned Administrator HTTP request authorized: '.$status);
    $assert((int)$pdo->query('SELECT reported_by FROM inventory_adjustments ORDER BY adjustment_id DESC LIMIT 1')->fetchColumn()===1,'HTTP ignores forged actor and shift');
    $httpReport=(int)$pdo->query('SELECT MAX(adjustment_id) FROM inventory_adjustments')->fetchColumn();
    [$status]=$request(1,'admin',$post); $assert(str_contains($status,'403'),'Administrator workspace cannot forge Cashier through body');
    [$status]=$request(99,'cashier',$post); $assert(str_contains($status,'302'),'Unknown authenticated identity rejected');
    $before=(int)$pdo->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn();
    [$status]=$request(2,'admin',$post);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn()===$before,'Forged unassigned workspace reloads identity, then refuses missing own shift');
    $void=['csrf_token'=>'fixture-csrf','action'=>'void_cart','void_reason'=>'Customer changed mind'];
    $voidCount=static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE action LIKE 'Voided sale before final checkout:%'")->fetchColumn();
    $request(1,'cashier',$void,'/pos');
    $assert($voidCount()===1,'Open unlocked shift permits HTTP cart void');
    $request(2,'cashier',$void,'/pos');
    $assert($voidCount()===1,'No own shift refuses HTTP cart void');
    $assert((int)$pdo->query('SELECT reported_by FROM inventory_adjustments')->fetchColumn() === 1, 'Assigned Administrator reports under own attribution');
    $refused(fn() => (new StockIssueService($pdo,null,'admin'))->submitReport(['product_id'=>1,'category'=>'damaged','quantity'=>1,'explanation'=>'Broken packaging'],1));
    $refused(fn() => (new StockIssueService($pdo,null,'cashier'))->approveReport($reportId,1));
    // Holds retain shortages and removed products; only explicit review produces
    // a proposal, and neither hold nor resume moves inventory.
    App\Store\StoreWriteGate::begin($pdo);
    $held->assertCartContext(1, 1, $shiftId);
    $refused(fn()=>$held->assertCartContext(1, 2, $shiftId));
    $refused(fn()=>$held->assertCartContext(1, 1, $shiftId + 1));
    $pdo->rollBack();
    $requested = [1 => ['qty'=>20,'price'=>10,'name'=>'Old name'], 999 => ['qty'=>3,'price'=>5,'name'=>'Removed item']];
    $review = $held->review(1, 'cashier', $requested);
    $assert(count($review['changes']) === 4, 'Review reports quantity, price, name and removed product changes');
    $assert($review['requested_cart'][1]['qty'] === 20 && isset($review['requested_cart'][999]), 'Review retains every requested line');
    $assert($review['cart'][1]['qty'] === 10 && !isset($review['cart'][999]), 'Proposal is separate from requested work');
    $changed = $held->hold(1, 'cashier', $requested);
    $resumed = $held->resume(1, 'cashier', $changed['held_sale_id']);
    $assert($resumed['cart'][1]['qty'] === 20 && isset($resumed['cart'][999]), 'Hold and resume never silently clip or drop requested lines');
    $pdo->exec('UPDATE products SET unit_price=30 WHERE product_id=1');
    $pdo->exec('UPDATE inventory SET quantity_on_hand=2 WHERE product_id=1');
    $resumed = $held->resume(1, 'cashier', $changed['held_sale_id']);
    $assert($resumed['review']['cart'][1]['price'] === 30.0 && $resumed['review']['cart'][1]['qty'] === 2, 'Resume revalidates current price and availability');
    $refused(fn()=>$shifts->closeShift(1,100,''));
    $held->discard(1,'cashier',$changed['held_sale_id'],'items_unavailable');
    $refused(fn()=>$held->discardCart(1,'cashier',$requested,'other',''));
    $held->discardCart(1,'cashier',$requested,'other','Customer left');
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory')->fetchColumn() === 2, 'Hold, resume and reasoned ordinary discard move no stock');
    $pdo->exec('UPDATE products SET unit_price=25 WHERE product_id=1');
    $pdo->exec('UPDATE inventory SET quantity_on_hand=10 WHERE product_id=1');
    $cart = $held->hold(1,'cashier',[1=>['qty'=>1]]);
    $shifts->lockRegister(1,'cashier');
    $request(1,'cashier',$void,'/pos');
    $assert($voidCount()===1,'Locked shift refuses HTTP cart void');
    $request(1,'cashier',$post);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn()===$before,'Locked direct HTTP submit writes nothing');
    $request(1,'cashier',['csrf_token'=>'fixture-csrf','correction_action'=>'cancel','adjustment_id'=>$httpReport]);
    $assert($pdo->query("SELECT status FROM inventory_adjustments WHERE adjustment_id={$httpReport}")->fetchColumn()==='pending','Locked direct HTTP correction writes nothing');
    foreach ([fn()=>$held->review(1,'cashier',[1=>['qty'=>1]]),fn()=>$held->discardCart(1,'cashier',[1=>['qty'=>1]],'wrong_item'),fn()=>$held->hold(1,'cashier',[1=>['qty'=>1]]),fn()=>$held->resume(1,'cashier',$cart['held_sale_id']),fn()=>$held->discard(1,'cashier',$cart['held_sale_id'],'wrong_item'),fn()=>$reporter->editReport($reportId,1,['quantity'=>2]),fn()=>$reporter->cancelReport($reportId,1),fn()=>$shifts->addDrawerMovement(1,'cashier','cash_in',10,'other','Test')] as $operation) $refused($operation);
    $assert(count($reporter->getReportsByCashier(1)) === 2, 'Own history remains readable while locked');
    $refused(fn()=>$shifts->unlockRegister(2,'cashier','fixture-password'));
    $refused(fn()=>$shifts->unlockRegister(1,'cashier','wrong-password'));
    $shifts->unlockRegister(1,'cashier','fixture-password');
    $refused(fn()=>$held->resume(2,'cashier',$cart['held_sale_id']));
    $held->discard(1,'cashier',$cart['held_sale_id'],'wrong_item');
    $manager = new StockIssueService($pdo,null,'inventory_manager'); $manager->approveReport($reportId,3);
    $refused(fn()=>$manager->rejectReport($reportId,3,'Changed mind'));
    $refused(fn()=>$reporter->editReport($reportId,1,['quantity'=>2]));
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory')->fetchColumn() === 9, 'Terminal review deducts only once');
    $shifts->closeShift(1,100,'');
    $refused(fn()=>$held->hold(1,'cashier',[1=>['qty'=>1]]));
    $refused(fn()=>$reporter->cancelReport($reportId,1));
    // Repeated simultaneous requests accept either legal order. A winning hold
    // prevents closure; a winning closure/lock prevents a later hold.
    foreach (['close','lock'] as $competitor) {
        for ($round=0;$round<4;$round++) {
            $pdo->exec("DELETE FROM held_sales");
            $pdo->exec("UPDATE cashier_shifts SET status='closed',locked_at=NULL WHERE status='open'");
            $current = $shifts->openShift(1,'cashier',1,100);
            foreach (['hold',$competitor] as $action) {
                $process=proc_open([PHP_BINARY,__FILE__,'--worker',$database,$action],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                $assert(is_resource($process),'Worker starts'); $workers[]=[$process,$pipes];
                $assert(trim((string)fgets($pipes[1]))==='ready','Worker ready');
            }
            foreach ($workers as [$process,$pipes]) { fwrite($pipes[0],"go\n"); fflush($pipes[0]); }
            $results=[];
            foreach ($workers as [$process,$pipes]) { fclose($pipes[0]); $results[]=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($process)===0,'Race worker: '.$error); }
            $workers=[];
            $state=$pdo->query("SELECT status,locked_at FROM cashier_shifts WHERE shift_id={$current}")->fetch(PDO::FETCH_ASSOC);
            $count=(int)$pdo->query("SELECT COUNT(*) FROM held_sales WHERE shift_id={$current} AND status IN ('held','resumed')")->fetchColumn();
            $assert($state['status']!=='closed'||$count===0,'No unresolved Held Sale after closure');
            $assert($state['locked_at']===null||$results[0]==='refused'||$count===1,'Lock admits only previously committed hold');
            if ($state['locked_at']!==null) $refused(fn()=>$held->hold(1,'cashier',[1=>['qty'=>1]]));
        }
    }
    echo "Cashier operation serialization: passed (ownership, assigned Administrator, lock/closed guards, terminal review, hold/close and hold/lock races)\n";
} catch (Throwable $e) { fwrite(STDERR,'Cashier operation serialization failed: '.$e->getMessage()."\n");$failed=true; }
finally { if(is_resource($httpServer)){proc_terminate($httpServer);proc_close($httpServer);} foreach($httpFiles as $file)if(is_file($file))unlink($file); foreach ($workers as [$process,$pipes]) { foreach ($pipes as $pipe) if(is_resource($pipe))fclose($pipe);proc_terminate($process);proc_close($process); } if($created)$server->exec("DROP DATABASE `{$database}`"); }
exit(isset($failed)?1:0);
