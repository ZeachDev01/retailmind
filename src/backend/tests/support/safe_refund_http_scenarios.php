<?php
// Real production refund handler, authenticated fixture sessions, disposable DB.
$router=tempnam(sys_get_temp_dir(),'refund-http-');
$log=tempnam(sys_get_temp_dir(),'refund-http-log-');
$httpServer=null;
try {
    $bootstrap=var_export(realpath(__DIR__.'/../../bootstrap/app.php'),true);
    $endpoint=var_export(realpath(__DIR__.'/../../../frontend/components/cashier/refunds.php'),true);
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
    [$status,$body]=$request(['sale_id'=>11],1,'cashier',false);
    $assert(str_contains($status,'200') && !str_contains($body,'Refund sale #11'),'Unapproved HTTP access exposes no other seller sale');
    $post=['csrf_token'=>'fixture-csrf','sale_id'=>11,'quantity'=>[11=>1],'disposition'=>[11=>'damaged'],'reason'=>'customer_return','approved_by'=>2,'cashier_id'=>3,'shift_id'=>2];
    $before=$snapshot();$request($post);
    $assert($snapshot()===$before,'Forged approver and actor IDs grant no refund');
    $lookup=['csrf_token'=>'fixture-csrf','action'=>'exception_lookup','sale_id'=>11,'exception_reason'=>'Original Cashier disabled','approver_username'=>'refund-admin','approver_password'=>'fixture-password'];
    [$status,$body]=$request($lookup);
    $assert(str_contains($status,'200') && str_contains($body,'Refund sale #11'),'Credentialed HTTP exception loads just nominated sale: '.$status.' '.substr(strip_tags($body),0,120));
    preg_match('/name="exception_token" value="([a-f0-9]{64})"/',$body,$matches);
    $httpToken=$matches[1]??'';$assert($httpToken!=='','HTTP exception renders a one-use token');
    if($fixturePath=getenv('SAFE_REFUND_BROWSER_FIXTURE')) file_put_contents($fixturePath,json_encode(['exception'=>$body,'noncash'=>$request(['sale_id'=>13],1,'cashier',false)[1]],JSON_THROW_ON_ERROR));
    $post+=['exception_token'=>$httpToken,'approver_username'=>'refund-admin','approver_password'=>'fixture-password'];
    $before=$snapshot();$request($post,5);$request($post,2,'admin');
    $assert($snapshot()===$before,'Other issuer and non-Cashier workspace cannot use the exception');
    $mismatched=$post;$mismatched['sale_id']=12;$mismatched['quantity']=[12=>1];$mismatched['disposition']=[12=>'damaged'];$request($mismatched);
    $assert($snapshot()===$before,'Mismatched sale cannot use HTTP approval');
    [$status]=$request($post);$assert(str_contains($status,'303'),'Specific authorized HTTP refund redirects to saved receipt');
    $row=$pdo->query('SELECT * FROM cash_refunds ORDER BY refund_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $assert((int)$row['sale_id']===11 && (int)$row['cashier_id']===1 && (int)$row['approved_by']===2,'HTTP exception preserves authenticated issuer and approver');
    $before=$snapshot();$request($post);$assert($snapshot()===$before,'HTTP repeated approval cannot create another payout');
    [$status,$body]=$request(['refund_id'=>$row['refund_id']],1,'cashier',false);
    $assert(str_contains($status,'200') && str_contains($body,'Original Sale Receipt #11'),'Issuer can reprint saved exception refund');
    [$status]=$request(['refund_id'=>$row['refund_id']],5,'cashier',false);
    $assert(str_contains($status,'404'),'Another Cashier cannot access exception Refund Receipt');
    $post=['csrf_token'=>'fixture-csrf','sale_id'=>13,'quantity'=>[13=>1],'disposition'=>[13=>'damaged'],'reason'=>'customer_return'];
    $before=$snapshot();$request($post);$assert($snapshot()===$before,'HTTP noncash refund cannot omit external evidence');
    $post+=['external_completed'=>'1','payment_reference'=>'HTTP-CARD-SETTLEMENT'];
    [$status]=$request($post);$assert(str_contains($status,'303'),'HTTP noncash refund records externally verified reference');
    $row=$pdo->query('SELECT * FROM cash_refunds ORDER BY refund_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    [$status,$body]=$request(['refund_id'=>$row['refund_id']],1,'cashier',false);
    $assert(str_contains($body,'HTTP-CARD-SETTLEMENT') && str_contains($body,'Refund completed externally and recorded in RetailMind.'),'Saved customer receipt describes external settlement');
    echo "Cash Refund real HTTP narrow authorization and settlement: passed\n";
} finally {
    if(is_resource($httpServer)){proc_terminate($httpServer);proc_close($httpServer);}
    @unlink($router);@unlink($log);
}
