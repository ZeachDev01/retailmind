<?php
// Real production refund handler, authenticated fixture sessions, disposable DB.
$router=tempnam(sys_get_temp_dir(),'legacy-http-');
$log=tempnam(sys_get_temp_dir(),'legacy-http-log-');
$httpServer=null;
try {
    $bootstrap=var_export(realpath(__DIR__.'/../../bootstrap/app.php'),true);
    $endpoint=var_export(realpath(__DIR__.'/../../../frontend/components/invoice/legacy_reversals.php'),true);
    $fixtureDatabase=var_export($database,true);
    file_put_contents($router,'<?php require '.$bootstrap.'; $_ENV["DB_NAME"]='.$fixtureDatabase.'; App\Core\Session::start(); $_SESSION=["user_id"=>(int)($_SERVER["HTTP_X_FIXTURE_USER"]??1),"role"=>($_SERVER["HTTP_X_FIXTURE_WORKSPACE"]??"cashier"),"session_version"=>1,"csrf_token"=>"fixture-csrf","_restore_epoch"=>App\Backup\RecoveryStore::epoch()]; require '.$endpoint.';');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    $assert(is_resource($socket),'Reserve refund HTTP fixture port');
    $address=stream_socket_get_name($socket,false);fclose($socket);
    $httpServer=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);
    $assert(is_resource($httpServer),'Refund HTTP fixture starts');fclose($pipes[0]);
    $ready=false;
    for($i=0;$i<100;$i++) { $probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($probe){fclose($probe);$ready=true;break;}usleep(20000); }
    $assert($ready,'Refund HTTP fixture ready');
    $request=static function(array $fields,int $user=1,string $workspace='cashier',bool $post=true) use($address): array {
        $options=['method'=>$post?'POST':'GET','ignore_errors'=>true,'follow_location'=>0,'header'=>"Content-Type: application/x-www-form-urlencoded\r\nX-Fixture-User: {$user}\r\nX-Fixture-Workspace: {$workspace}\r\n",'timeout'=>10];
        if($post)$options['content']=http_build_query($fields);
        $body=file_get_contents('http://'.$address.'/'.($post?'':'?'.http_build_query($fields)),false,stream_context_create(['http'=>$options]));
        return [$http_response_header[0]??'',(string)$body];
    };
    $post=['csrf_token'=>'fixture-csrf','action'=>'request','sale_id'=>9,'requested_by'=>2,'approved_by'=>2];
    $before=$snapshot();
    foreach([[1,'cashier'],[2,'admin'],[3,'super_admin'],[4,'inventory_manager']] as [$user,$role]) {
        [$status,$body]=$request($post,$user,$role);
        $assert(!str_contains($status,'500'),'Direct request refusal has no fatal error: '.$body);
        $assert($snapshot()===$before,'HTTP new requests disabled for '.$role);
    }
    foreach([[1,'cashier'],[4,'inventory_manager'],[3,'super_admin']] as [$user,$role]) {
        [$status]=$request(['csrf_token'=>'fixture-csrf','action'=>'reject','reversal_id'=>9,'decision_reason'=>'Forged Administrator','no_prior_effects'=>'1','approved_by'=>2],$user,$role);
        $assert(!str_contains($status,'500') && $snapshot()===$before,'HTTP operational decision role boundary '.$role);
    }
    [$status]=$request(['csrf_token'=>'wrong','action'=>'reject','reversal_id'=>9,'decision_reason'=>'CSRF','no_prior_effects'=>'1'],2,'admin');
    $assert(str_contains($status,'403') && $snapshot()===$before,'CSRF rejects without effects');
    [$status,$body]=$request(['csrf_token'=>'fixture-csrf','action'=>'approve','reversal_id'=>9,'decision_reason'=>'Missing evidence'],2,'admin');
    $assert(!str_contains($status,'500') && str_contains($body,'Verify no payout') && $snapshot()===$before,'Unsupported HTTP decision explains refusal without effects');
    [$status,$body]=$request(['sale_id'=>9],2,'admin',false);
    $assert(str_contains($status,'200') && str_contains($body,'Nominal paid balance') && str_contains($body,'Stock before / after'),'Administrator numerical preview accessible');
    [$status]=$request(['csrf_token'=>'fixture-csrf','action'=>'reject','reversal_id'=>9,'decision_reason'=>'Verified no effects, replaced by separate Cash Refund','no_prior_effects'=>'1','approved_by'=>1],2,'admin');
    $assert(str_contains($status,'200'),'Administrator explicit rejection works over HTTP');
    $record=$pdo->query('SELECT * FROM sale_reversals WHERE reversal_id=9')->fetch();
    $assert($record['status']==='rejected' && (int)$record['approved_by']===2,'Authenticated Administrator overrides forged actor');
    echo "Legacy Reversal real HTTP authorization, CSRF and refusal: passed\n";
} finally {
    if(is_resource($httpServer)){proc_terminate($httpServer);proc_close($httpServer);}
    @unlink($router);@unlink($log);
}
