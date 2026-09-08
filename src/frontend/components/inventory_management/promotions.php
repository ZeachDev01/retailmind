<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_role(['admin','super_admin']);
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_verify();
    try{
        $action=$_POST['action']??'';
        if($action==='create'){
            $name=trim((string)($_POST['promotion_name']??''));
            $type=in_array($_POST['discount_type']??'', ['percentage','fixed'],true)?$_POST['discount_type']:'percentage';
            $value=(float)($_POST['discount_value']??0);
            $scope=in_array($_POST['scope']??'', ['all','product','category'],true)?$_POST['scope']:'all';
            $productId=$scope==='product'?(int)($_POST['product_id']??0):null;
            $categoryId=$scope==='category'?(int)($_POST['category_id']??0):null;
            $minimum=max(1,(int)($_POST['minimum_quantity']??1));
            $starts=trim((string)($_POST['starts_at']??''));$ends=trim((string)($_POST['ends_at']??''));
            if($name===''||$value<=0||$starts===''||$ends==='')throw new RuntimeException('Name, value, start, and end dates are required.');
            if($type==='percentage'&&$value>100)throw new RuntimeException('Percentage cannot exceed 100%.');
            if(strtotime($ends)<=strtotime($starts))throw new RuntimeException('End date must be later than start date.');
            if($scope==='product'&&!$productId)throw new RuntimeException('Select a product.');
            if($scope==='category'&&!$categoryId)throw new RuntimeException('Select a category.');
            $stmt=$pdo->prepare("INSERT INTO promotions(promotion_name,discount_type,discount_value,scope,product_id,category_id,minimum_quantity,starts_at,ends_at,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,'active',?)");
            $stmt->execute([$name,$type,$value,$scope,$productId?:null,$categoryId?:null,$minimum,date('Y-m-d H:i:s',strtotime($starts)),date('Y-m-d H:i:s',strtotime($ends)),(int)$_SESSION['user_id']]);
            $id=(int)$pdo->lastInsertId();log_activity($pdo,(int)$_SESSION['user_id'],'Created promotion','Promotions',$id,null,['name'=>$name,'type'=>$type,'value'=>$value,'scope'=>$scope]);$message='Promotion created.';
        }elseif(in_array($action,['activate','deactivate'],true)){
            $id=(int)($_POST['promotion_id']??0);$status=$action==='activate'?'active':'inactive';
            $stmt=$pdo->prepare('UPDATE promotions SET status=? WHERE promotion_id=?');$stmt->execute([$status,$id]);
            if(!$stmt->rowCount())throw new RuntimeException('Promotion was not updated.');
            log_activity($pdo,(int)$_SESSION['user_id'],ucfirst($action).' promotion','Promotions',$id);$message='Promotion updated.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$products=$pdo->query("SELECT product_id,sku,product_name,variant_label FROM products WHERE status='active' ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$categories=$pdo->query('SELECT category_id,category_name FROM categories ORDER BY category_name')->fetchAll(PDO::FETCH_ASSOC);
$promotions=$pdo->query("SELECT pr.*,p.product_name,c.category_name,u.full_name created_by_name FROM promotions pr LEFT JOIN products p ON p.product_id=pr.product_id LEFT JOIN categories c ON c.category_id=pr.category_id JOIN users u ON u.user_id=pr.created_by ORDER BY pr.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Promotions</title><link rel="stylesheet" href="<?=htmlspecialchars(app_url('assets/css/style.css'))?>"><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css')) ?>"></head><body><div class="app-shell"><?php include __DIR__.'/../sidebar.php';?><main class="main-content"><div class="topbar"><div><h1>Discounts & Promotions</h1><p class="page-subtitle">Schedule automatic discounts. The POS applies the highest eligible promotion or approved manual discount, not both.</p></div></div>
<?php if($message):?><div class="message success"><?=htmlspecialchars($message)?></div><?php endif;?><?php if($error):?><div class="message error"><?=htmlspecialchars($error)?></div><?php endif;?>
<div class="two-col"><section class="dashboard-section"><h3>Create promotion</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create"><label>Name</label><input name="promotion_name" required><div class="form-grid"><div><label>Discount type</label><select name="discount_type"><option value="percentage">Percentage</option><option value="fixed">Fixed amount</option></select></div><div><label>Value</label><input type="number" name="discount_value" min="0.01" step="0.01" required></div></div><div class="form-grid"><div><label>Scope</label><select name="scope" id="scope"><option value="all">Entire cart</option><option value="product">Product</option><option value="category">Category</option></select></div><div><label>Minimum quantity</label><input type="number" name="minimum_quantity" min="1" value="1"></div></div><div id="product-scope" hidden><label>Product</label><select name="product_id"><option value="">Select product</option><?php foreach($products as $p):?><option value="<?=$p['product_id']?>"><?=htmlspecialchars($p['product_name'].(!empty($p['variant_label'])?' - '.$p['variant_label']:'').' ('.$p['sku'].')')?></option><?php endforeach;?></select></div><div id="category-scope" hidden><label>Category</label><select name="category_id"><option value="">Select category</option><?php foreach($categories as $c):?><option value="<?=$c['category_id']?>"><?=htmlspecialchars($c['category_name'])?></option><?php endforeach;?></select></div><div class="form-grid"><div><label>Starts</label><input type="datetime-local" name="starts_at" value="<?=date('Y-m-d\TH:i')?>" required></div><div><label>Ends</label><input type="datetime-local" name="ends_at" value="<?=date('Y-m-d\TH:i',strtotime('+7 days'))?>" required></div></div><button class="btn">Create promotion</button></form></section>
<section class="dashboard-section"><h3>Promotion schedule</h3><div class="table-wrap"><table><tr><th>Name</th><th>Discount</th><th>Scope</th><th>Window</th><th>Status</th><th>Action</th></tr><?php foreach($promotions as $pr):?><tr><td><?=htmlspecialchars($pr['promotion_name'])?></td><td><?=htmlspecialchars($pr['discount_type']==='percentage'?number_format((float)$pr['discount_value'],2).'%':'₱'.number_format((float)$pr['discount_value'],2))?><br><small>Min qty: <?=(int)$pr['minimum_quantity']?></small></td><td><?=htmlspecialchars($pr['scope']==='product'?($pr['product_name']??'Product'):($pr['scope']==='category'?($pr['category_name']??'Category'):'Entire cart'))?></td><td><?=htmlspecialchars($pr['starts_at'])?><br><?=htmlspecialchars($pr['ends_at'])?></td><td><?=htmlspecialchars(ucfirst($pr['status']))?></td><td><form method="post"><?=csrf_field()?><input type="hidden" name="promotion_id" value="<?=$pr['promotion_id']?>"><input type="hidden" name="action" value="<?=$pr['status']==='active'?'deactivate':'activate'?>"><button class="btn btn-small"><?=$pr['status']==='active'?'Deactivate':'Activate'?></button></form></td></tr><?php endforeach;?><?php if(!$promotions):?><tr><td colspan="6">No promotions created.</td></tr><?php endif;?></table></div></section></div>
</main></div><script>const scope=document.getElementById('scope'),product=document.getElementById('product-scope'),category=document.getElementById('category-scope');function toggle(){product.hidden=scope.value!=='product';category.hidden=scope.value!=='category';}scope.addEventListener('change',toggle);toggle();</script></body></html>
