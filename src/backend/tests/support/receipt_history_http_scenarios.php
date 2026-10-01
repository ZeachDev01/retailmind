<?php
// Included by the disposable receipt fixture; never mounted by the application.
$router=tempnam(sys_get_temp_dir(),'receipt-http-');
$log=tempnam(sys_get_temp_dir(),'receipt-http-log-');
$process=null;
try {
    $bootstrap=var_export(realpath(__DIR__.'/../../bootstrap/app.php'),true);
    $endpoint=var_export(realpath(__DIR__.'/../../../frontend/components/invoice/sales.php'),true);
    $fixtureDatabase=var_export($database,true);
    file_put_contents($router,'<?php require '.$bootstrap.'; $_ENV["DB_NAME"]='.$fixtureDatabase.'; App\Core\Session::start(); $_SESSION["user_id"]=(int)($_SERVER["HTTP_X_FIXTURE_USER"]??1); $_SESSION["role"]="cashier"; $_SESSION["session_version"]=1; $_SESSION["csrf_token"]="fixture-csrf"; $_SESSION["_restore_epoch"]=App\Backup\RecoveryStore::epoch(); if(isset($_SERVER["HTTP_X_FIXTURE_COMPLETE"])) $_SESSION["completed_checkout_sale"]=(int)$_SERVER["HTTP_X_FIXTURE_COMPLETE"]; require '.$endpoint.';');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    $assert(is_resource($socket),'Reserve receipt HTTP fixture port');
    $address=stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);
    $assert(is_resource($process),'Receipt HTTP server starts');fclose($pipes[0]);
    $ready=false;
    for($i=0;$i<100;$i++) { $probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($probe){fclose($probe);$ready=true;break;}usleep(20000); }
    $assert($ready,'Receipt HTTP server ready');
    $cookies=[];
    $request=static function(array $query,int $user=1,?int $complete=null) use($address,&$cookies): array {
        $headers="X-Fixture-User: {$user}\r\n";
        if(isset($cookies[$user])) $headers.='Cookie: '.$cookies[$user]."\r\n";
        if($complete!==null) $headers.="X-Fixture-Complete: {$complete}\r\n";
        $body=file_get_contents('http://'.$address.'/?'.http_build_query($query),false,stream_context_create(['http'=>['ignore_errors'=>true,'follow_location'=>0,'header'=>$headers,'timeout'=>10]]));
        foreach($http_response_header??[] as $header) if(preg_match('/^Set-Cookie:\s*([^;]+)/i',$header,$match)) $cookies[$user]=$match[1];
        return [$http_response_header[0]??'',(string)$body];
    };
    [$status,$body]=$request(['action'=>'view','ajax'=>1,'sale_id'=>6]);
    $assert(str_contains($status,'200') && str_contains($body,'Saved Till') && str_contains($body,'Saved Seller') && str_contains($body,'Saved Product'),'Locked owner can view saved transaction-time receipt despite later rename: '.$status.' '.substr(strip_tags($body),0,100));
    $assert(str_contains($body,'Oct 1, 2026 · 8:39 AM') && !str_contains($body,'Payment completed'),'Historical AJAX has Manila time and no payment success');
    [$status,$body]=$request(['action'=>'view','ajax'=>1,'sale_id'=>6],2);
    $assert(str_contains($status,'403') && !str_contains($body,'Saved Product'),'Another Cashier cannot guess the saved receipt');
    [$status,$body]=$request(['action'=>'data','cashier_id'=>2,'date_from'=>'2026-10-01','date_to'=>'2026-10-01']);
    $response=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    $assert(str_contains($status,'200') && array_column($response['data'],'sale_id')===[3,6,2],'Direct receipt data rejects forged Cashier filter while locked');
    [$status,$body]=$request(['sale_id'=>6,'checkout'=>'complete'],1,6);
    $assert(str_contains($status,'200') && substr_count($body,'<strong>Payment completed</strong>')===1 && substr_count($body,'class="sale-receipt receipt-print-area"')===1,'Saved checkout opens one receipt with one completion announcement');
    $assert(str_contains($body,'<dialog') && !str_contains($body,'Renamed Product'),'Checkout uses shared modal with saved product');
    $completedHtml=$body;
    [$status,$body]=$request(['sale_id'=>6,'checkout'=>'complete']);
    $assert(str_contains($status,'200') && !str_contains($body,'<strong>Payment completed</strong>'),'Reload does not announce another payment');
    if($fixturePath=getenv('RECEIPT_HISTORY_BROWSER_FIXTURE')) file_put_contents($fixturePath,json_encode(['completed'=>$completedHtml,'repeat'=>$body,'attempt'=>str_repeat('a',32)],JSON_THROW_ON_ERROR));
    [$status,$body]=$request(['sale_id'=>2]);
    $assert(str_contains($status,'200') && str_contains($body,'historical') && !str_contains($body,'<strong>Payment completed</strong>'),'Unsnapshotted historical receipt retains notice without payment success');
    [$status,$body]=$request(['sale_id'=>5,'checkout'=>'complete'],1,5);
    $assert(!str_contains($body,'Saved Product') && !str_contains($body,'<strong>Payment completed</strong>'),'Forged completion query grants neither other sale nor payment announcement');
    echo "Receipt History real HTTP locked visibility and completion-once: passed\n";
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    @unlink($router);@unlink($log);
}
