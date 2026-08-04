<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
require_role(['admin','super_admin','cashier']);

$userId = (int)$_SESSION['user_id'];
$pdo->prepare("UPDATE held_sales SET status='expired',resolved_at=NOW() WHERE status='held' AND expires_at IS NOT NULL AND expires_at<NOW()")->execute();

function held_sale_rows(PDO $pdo, int $userId): array {
    $stmt=$pdo->prepare("SELECT held_sale_id id,reference_no,customer_label,cart_json,item_count,total_amount,created_at,expires_at FROM held_sales WHERE cashier_id=? AND status='held' ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    return array_map(static function(array $row): array {
        $row['id']=(int)$row['id'];$row['item_count']=(int)$row['item_count'];$row['total_amount']=(float)$row['total_amount'];
        $row['cart']=json_decode((string)$row['cart_json'],true)?:[];unset($row['cart_json']);return $row;
    },$stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($_SERVER['REQUEST_METHOD']==='GET') {
    echo json_encode(['success'=>true,'held_sales'=>held_sale_rows($pdo,$userId)]);exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405);echo json_encode(['success'=>false,'message'=>'Method not allowed.']);exit; }
$input=json_decode((string)file_get_contents('php://input'),true)?:[];
csrf_verify($_SERVER['HTTP_X_CSRF_TOKEN']??($input['csrf_token']??null));
$action=(string)($input['action']??'');
try {
    if ($action==='hold') {
        $cart=$input['cart']??[];
        if (!is_array($cart)||!$cart) throw new RuntimeException('Cart is empty.');
        $ids=array_values(array_filter(array_map('intval',array_keys($cart))));
        if (!$ids) throw new RuntimeException('Cart is invalid.');
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$pdo->prepare("SELECT p.product_id,p.product_name,p.sku,p.barcode,p.unit_price,p.reorder_level,p.safety_stock,i.quantity_on_hand FROM products p JOIN inventory i ON i.product_id=p.product_id WHERE p.status='active' AND p.product_id IN ($placeholders)");
        $stmt->execute($ids);$db=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $p){$db[(int)$p['product_id']]=$p;}
        $clean=[];$count=0;$total=0.0;
        foreach($cart as $id=>$item){$id=(int)$id;$qty=max(0,(int)($item['qty']??0));if($qty<1||!isset($db[$id]))continue;$p=$db[$id];$qty=min($qty,(int)$p['quantity_on_hand']);if($qty<1)continue;$clean[$id]=['name'=>$p['product_name'],'price'=>(float)$p['unit_price'],'qty'=>$qty,'stock'=>(int)$p['quantity_on_hand'],'reorder_level'=>(int)$p['reorder_level'],'safety_stock'=>(int)$p['safety_stock'],'sku'=>$p['sku'],'barcode'=>$p['barcode']];$count+=$qty;$total+=(float)$p['unit_price']*$qty;}
        if(!$clean)throw new RuntimeException('No active products can be held.');
        $shiftStmt=$pdo->prepare("SELECT shift_id FROM cashier_shifts WHERE cashier_id=? AND status='open' ORDER BY opened_at DESC LIMIT 1");$shiftStmt->execute([$userId]);$shiftId=$shiftStmt->fetchColumn()?:null;
        $reference='H'.date('ymdHis').str_pad((string)random_int(0,999),3,'0',STR_PAD_LEFT);
        $stmt=$pdo->prepare("INSERT INTO held_sales(cashier_id,shift_id,reference_no,customer_label,cart_json,item_count,total_amount,expires_at) VALUES(?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))");
        $stmt->execute([$userId,$shiftId,$reference,trim((string)($input['customer_label']??''))?:null,json_encode($clean,JSON_UNESCAPED_UNICODE),$count,$total]);
        echo json_encode(['success'=>true,'id'=>(int)$pdo->lastInsertId(),'reference_no'=>$reference,'held_sales'=>held_sale_rows($pdo,$userId)]);exit;
    }
    if (in_array($action,['resume','cancel'],true)) {
        $id=(int)($input['id']??0);$newStatus=$action==='resume'?'resumed':'cancelled';
        $pdo->beginTransaction();
        $stmt=$pdo->prepare("SELECT cart_json FROM held_sales WHERE held_sale_id=? AND cashier_id=? AND status='held' FOR UPDATE");$stmt->execute([$id,$userId]);$json=$stmt->fetchColumn();
        if($json===false)throw new RuntimeException('Held sale not found or already resolved.');
        $pdo->prepare("UPDATE held_sales SET status=?,resolved_at=NOW() WHERE held_sale_id=?")->execute([$newStatus,$id]);$pdo->commit();
        echo json_encode(['success'=>true,'cart'=>json_decode((string)$json,true)?:[],'held_sales'=>held_sale_rows($pdo,$userId)]);exit;
    }
    throw new RuntimeException('Invalid action.');
} catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(400);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
