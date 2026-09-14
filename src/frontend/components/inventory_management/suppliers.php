<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin','super_admin','inventory_manager']);
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_inventory_management();
    csrf_verify();$action=$_POST['action']??'';
    try{
        if($action==='save'){
            $id=(int)($_POST['supplier_id']??0);$name=trim((string)($_POST['supplier_name']??''));
            if($name==='')throw new RuntimeException('Supplier name is required.');
            $data=[trim((string)($_POST['contact_person']??''))?:null,trim((string)($_POST['email']??''))?:null,trim((string)($_POST['phone']??''))?:null,trim((string)($_POST['address']??''))?:null,max(0,(int)($_POST['standard_lead_time_days']??7)),max(0,(float)($_POST['minimum_order_value']??0)),($_POST['status']??'active')==='inactive'?'inactive':'active',trim((string)($_POST['notes']??''))?:null];
            if($id>0){$before=$pdo->prepare('SELECT * FROM suppliers WHERE supplier_id=?');$before->execute([$id]);$old=$before->fetch();$stmt=$pdo->prepare('UPDATE suppliers SET supplier_name=?,contact_person=?,email=?,phone=?,address=?,standard_lead_time_days=?,minimum_order_value=?,status=?,notes=? WHERE supplier_id=?');$stmt->execute(array_merge([$name],$data,[$id]));log_activity($pdo,(int)$_SESSION['user_id'],'Updated supplier','Suppliers',$id,$old,array_merge(['supplier_name'=>$name],$data));$message='Supplier updated.';}
            else{$stmt=$pdo->prepare('INSERT INTO suppliers(supplier_name,contact_person,email,phone,address,standard_lead_time_days,minimum_order_value,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)');$stmt->execute(array_merge([$name],$data,[(int)$_SESSION['user_id']]));$id=(int)$pdo->lastInsertId();log_activity($pdo,(int)$_SESSION['user_id'],'Created supplier','Suppliers',$id);$message='Supplier created.';}
        }elseif($action==='map_product'){
            $supplierId=(int)($_POST['supplier_id']??0);$productId=(int)($_POST['product_id']??0);if(!$supplierId||!$productId)throw new RuntimeException('Supplier and product are required.');
            $preferred=!empty($_POST['is_preferred'])?1:0;if($preferred){$pdo->prepare('UPDATE supplier_products SET is_preferred=0 WHERE product_id=?')->execute([$productId]);}
            $stmt=$pdo->prepare('INSERT INTO supplier_products(supplier_id,product_id,supplier_sku,last_unit_cost,minimum_order_quantity,lead_time_days,is_preferred) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE supplier_sku=VALUES(supplier_sku),last_unit_cost=VALUES(last_unit_cost),minimum_order_quantity=VALUES(minimum_order_quantity),lead_time_days=VALUES(lead_time_days),is_preferred=VALUES(is_preferred)');
            $stmt->execute([$supplierId,$productId,trim((string)($_POST['supplier_sku']??''))?:null,($_POST['last_unit_cost']??'')!==''?(float)$_POST['last_unit_cost']:null,max(1,(int)($_POST['minimum_order_quantity']??1)),max(0,(int)($_POST['lead_time_days']??7)),$preferred]);
            if($preferred){$nameStmt=$pdo->prepare('SELECT supplier_name FROM suppliers WHERE supplier_id=?');$nameStmt->execute([$supplierId]);$name=$nameStmt->fetchColumn();$pdo->prepare('UPDATE products SET preferred_supplier=?,supplier_lead_time_days=? WHERE product_id=?')->execute([$name,max(0,(int)($_POST['lead_time_days']??7)),$productId]);}
            $message='Supplier-product terms saved.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$suppliers=$pdo->query("SELECT s.*,COUNT(sp.supplier_product_id) product_count FROM suppliers s LEFT JOIN supplier_products sp ON sp.supplier_id=s.supplier_id GROUP BY s.supplier_id ORDER BY s.supplier_name")->fetchAll(PDO::FETCH_ASSOC);
$products=$pdo->query("SELECT product_id,sku,product_name FROM products WHERE status='active' ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$mappings=$pdo->query("SELECT sp.*,s.supplier_name,p.product_name,p.sku FROM supplier_products sp JOIN suppliers s ON s.supplier_id=sp.supplier_id JOIN products p ON p.product_id=sp.product_id ORDER BY s.supplier_name,p.product_name")->fetchAll(PDO::FETCH_ASSOC);
ob_start(static fn(string $output): string => str_replace('components/inventory_management/purchase_orders.php', 'components/invoice/purchase_orders.php', $output));
$activeSupplierCount = count(array_filter($suppliers, static fn(array $supplier): bool => $supplier['status'] === 'active'));
$preferredTermCount = count(array_filter($mappings, static fn(array $mapping): bool => (int) $mapping['is_preferred'] === 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Suppliers</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css')) ?>">
</head>
<body>
<div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<main class="main-content suppliers-page">
    <header class="page-heading">
        <div>
            <span class="eyebrow">Inventory partners</span>
            <h1>Supplier management</h1>
            <p class="page-subtitle">Keep supplier contacts, purchasing terms, and product relationships ready for your next order.</p>
        </div>
        <div class="page-heading-actions">
            <a class="btn btn-quiet" href="<?= htmlspecialchars(app_url('components/inventory_management/reorder_planner.php')) ?>">Reorder planning</a>
            <a class="btn" href="<?= htmlspecialchars(app_url('components/inventory_management/purchase_orders.php')) ?>">Purchase orders</a>
        </div>
    </header>

    <?php if ($message): ?><div class="message success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="supplier-metrics" aria-label="Supplier summary">
        <article class="supplier-metric"><span class="supplier-metric__value"><?= count($suppliers) ?></span><span class="supplier-metric__label">Total suppliers</span><span class="supplier-metric__hint">Available in the directory</span></article>
        <article class="supplier-metric"><span class="supplier-metric__value supplier-metric__value--green"><?= $activeSupplierCount ?></span><span class="supplier-metric__label">Active partners</span><span class="supplier-metric__hint">Ready for purchasing</span></article>
        <article class="supplier-metric"><span class="supplier-metric__value supplier-metric__value--blue"><?= count($mappings) ?></span><span class="supplier-metric__label">Product terms</span><span class="supplier-metric__hint"><?= $preferredTermCount ?> preferred relationship<?= $preferredTermCount === 1 ? '' : 's' ?></span></article>
    </div>

    <div class="supplier-workflows">
        <section class="dashboard-section supplier-form-card">
            <div class="section-header"><div><span class="section-kicker">01 / Directory</span><h3>Add supplier</h3><p class="section-description">Create a purchasing partner profile and set its default terms.</p></div></div>
            <form method="post" class="supplier-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="save">
                <div class="form-grid">
                    <div><label for="supplier_name">Supplier name</label><input id="supplier_name" name="supplier_name" required placeholder="e.g. Metro Wholesale"></div>
                    <div><label for="contact_person">Contact person</label><input id="contact_person" name="contact_person" placeholder="Primary contact"></div>
                    <div><label for="email">Email</label><input id="email" type="email" name="email" placeholder="name@company.com"></div>
                    <div><label for="phone">Phone</label><input id="phone" name="phone" placeholder="Contact number"></div>
                    <div><label for="standard_lead_time_days">Default lead time <span>(days)</span></label><input id="standard_lead_time_days" type="number" min="0" name="standard_lead_time_days" value="7"></div>
                    <div><label for="minimum_order_value">Minimum order value</label><input id="minimum_order_value" type="number" min="0" step="0.01" name="minimum_order_value" value="0" placeholder="0.00"></div>
                </div>
                <div class="form-grid supplier-form-grid--wide"><div><label for="address">Address</label><textarea id="address" name="address" rows="2" placeholder="Delivery or billing address"></textarea></div><div><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" placeholder="Internal notes"></textarea></div></div>
                <div class="supplier-form-footer"><label class="supplier-status-field" for="status">Supplier status<select id="status" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label><button class="btn" type="submit">Save supplier</button></div>
            </form>
        </section>

        <section class="dashboard-section supplier-form-card">
            <div class="section-header"><div><span class="section-kicker">02 / Purchasing terms</span><h3>Map product terms</h3><p class="section-description">Record supplier-specific costs, quantities, and lead times.</p></div></div>
            <form method="post" class="supplier-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="map_product">
                <div class="form-grid supplier-form-grid--wide">
                    <div><label for="mapping_supplier_id">Supplier</label><select id="mapping_supplier_id" name="supplier_id" required><option value="">Select supplier</option><?php foreach ($suppliers as $s): ?><option value="<?= $s['supplier_id'] ?>"><?= htmlspecialchars($s['supplier_name']) ?></option><?php endforeach; ?></select></div>
                    <div><label for="product_id">Product</label><select id="product_id" name="product_id" required><option value="">Select product</option><?php foreach ($products as $p): ?><option value="<?= $p['product_id'] ?>"><?= htmlspecialchars($p['product_name'] . ' (' . $p['sku'] . ')') ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-grid">
                    <div><label for="supplier_sku">Supplier SKU</label><input id="supplier_sku" name="supplier_sku" placeholder="Optional reference"></div>
                    <div><label for="last_unit_cost">Last unit cost</label><input id="last_unit_cost" type="number" min="0" step="0.01" name="last_unit_cost" placeholder="0.00"></div>
                    <div><label for="minimum_order_quantity">Minimum order quantity</label><input id="minimum_order_quantity" type="number" min="1" name="minimum_order_quantity" value="1"></div>
                    <div><label for="lead_time_days">Lead time <span>(days)</span></label><input id="lead_time_days" type="number" min="0" name="lead_time_days" value="7"></div>
                </div>
                <div class="supplier-form-footer"><label class="supplier-checkbox"><input type="checkbox" name="is_preferred" value="1"> Preferred supplier for this product</label><button class="btn" type="submit">Save terms</button></div>
            </form>
        </section>
    </div>

    <section class="dashboard-section supplier-list-card">
        <div class="section-header"><div><span class="section-kicker">Supplier directory</span><h3>Suppliers</h3><p class="section-description">A quick view of partner availability and linked products.</p></div><span class="table-count"><?= count($suppliers) ?> record<?= count($suppliers) === 1 ? '' : 's' ?></span></div>
        <?php if (!$suppliers): ?><div class="empty-state"><div><i class="bi bi-building"></i><strong>No suppliers yet</strong><span>Add your first supplier above to start mapping product terms.</span></div></div><?php else: ?><div class="table-wrap"><table class="supplier-table"><thead><tr><th>Name</th><th>Contact</th><th>Lead time</th><th>Products</th><th>Status</th></tr></thead><tbody><?php foreach ($suppliers as $s): ?><tr><td><strong><?= htmlspecialchars($s['supplier_name']) ?></strong><span class="table-secondary"><?= htmlspecialchars(trim(($s['email'] ?? '') . ' ' . ($s['phone'] ?? '')) ?: 'No contact details') ?></span></td><td><?= htmlspecialchars($s['contact_person'] ?? '-') ?></td><td><?= (int) $s['standard_lead_time_days'] ?> days</td><td><span class="count-badge"><?= (int) $s['product_count'] ?></span></td><td><span class="status-badge <?= $s['status'] === 'active' ? 'status-badge--active' : 'status-badge--inactive' ?>"><?= htmlspecialchars(ucfirst($s['status'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>

    <section class="dashboard-section supplier-list-card">
        <div class="section-header"><div><span class="section-kicker">Product coverage</span><h3>Product supply terms</h3><p class="section-description">Compare the commercial terms used when creating replenishment requests.</p></div><span class="table-count"><?= count($mappings) ?> mapping<?= count($mappings) === 1 ? '' : 's' ?></span></div>
        <?php if (!$mappings): ?><div class="empty-state"><div><i class="bi bi-link-45deg"></i><strong>No product terms mapped</strong><span>Choose a supplier and product above to add purchasing terms.</span></div></div><?php else: ?><div class="table-wrap"><table class="supplier-table terms-table"><thead><tr><th>Supplier</th><th>Product</th><th>Cost</th><th>MOQ</th><th>Lead</th><th>Preferred</th></tr></thead><tbody><?php foreach ($mappings as $m): ?><tr><td><?= htmlspecialchars($m['supplier_name']) ?></td><td><strong><?= htmlspecialchars($m['product_name']) ?></strong><span class="table-secondary"><?= htmlspecialchars($m['sku']) ?></span></td><td><?= $m['last_unit_cost'] !== null ? '₱' . number_format((float) $m['last_unit_cost'], 2) : '-' ?></td><td><?= (int) $m['minimum_order_quantity'] ?></td><td><?= (int) $m['lead_time_days'] ?> days</td><td><?= $m['is_preferred'] ? '<span class="status-badge status-badge--preferred">Preferred</span>' : '<span class="table-secondary">Standard</span>' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
</main>
</div>
</body>
</html>
