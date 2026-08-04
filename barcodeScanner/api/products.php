<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';
require_role(['admin','super_admin','inventory_manager','cashier']);

$q = trim((string)($_GET['q'] ?? ''));
$code = trim((string)($_GET['code'] ?? ''));
$id = (int)($_GET['id'] ?? 0);
$category = (int)($_GET['category'] ?? 0);
$limit = max(1, min(30, (int)($_GET['limit'] ?? 12)));
$managementSearch = (($_GET['scope'] ?? '') === 'all') && in_array(current_role(), ['admin', 'super_admin', 'inventory_manager'], true);

$select = "SELECT p.product_id,p.sku,p.barcode,p.case_barcode,p.parent_product_id,p.variant_label,p.product_name,p.unit_price,
                  COALESCE(p.reorder_level,0) reorder_level,COALESCE(p.safety_stock,0) safety_stock,
                  LEAST(i.quantity_on_hand,COALESCE(b.sellable_quantity,i.quantity_on_hand)) quantity_on_hand,
                  b.next_expiration_date
           FROM products p JOIN inventory i ON i.product_id=p.product_id
           LEFT JOIN (
             SELECT product_id,
                    SUM(CASE WHEN expiration_date IS NULL OR expiration_date>=CURDATE() THEN remaining_quantity ELSE 0 END) sellable_quantity,
                    MIN(CASE WHEN expiration_date IS NULL OR expiration_date>=CURDATE() THEN expiration_date END) next_expiration_date
             FROM product_batches WHERE remaining_quantity>0 GROUP BY product_id
           ) b ON b.product_id=p.product_id
           WHERE 1=1";
if (!$managementSearch) {
    $select .= " AND p.status='active' AND i.quantity_on_hand>0
                  AND COALESCE(b.sellable_quantity,i.quantity_on_hand)>0";
}
$params = [];
if ($id > 0) {
    $select .= ' AND p.product_id=?';
    $params[] = $id;
} elseif ($code !== '') {
    $select .= ' AND (p.barcode=? OR p.sku=? OR p.case_barcode=?)';
    array_push($params, $code, $code, $code);
} elseif ($category > 0) {
    $select .= ' AND p.category_id=?';
    $params[] = $category;
} elseif ($q !== '') {
    $select .= ' AND (p.product_name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.case_barcode LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
} else {
    echo json_encode(['success'=>true,'products'=>[]]);
    exit;
}
$select .= " ORDER BY CASE WHEN p.barcode=? OR p.sku=? OR p.case_barcode=? THEN 0 ELSE 1 END,p.product_name LIMIT {$limit}";
$rank = $code !== '' ? $code : $q;
array_push($params, $rank, $rank, $rank);
$stmt = $pdo->prepare($select);
$stmt->execute($params);
$products = array_map(static function(array $p): array {
    return [
        'product_id'=>(int)$p['product_id'],'sku'=>$p['sku'],'barcode'=>$p['barcode'],'case_barcode'=>$p['case_barcode'],
        'name'=>$p['product_name'] . (!empty($p['variant_label']) ? ' - ' . $p['variant_label'] : ''),'product_name'=>$p['product_name'],'variant_label'=>$p['variant_label'],'price'=>(float)$p['unit_price'],'unit_price'=>(float)$p['unit_price'],
        'quantity_on_hand'=>(int)$p['quantity_on_hand'],'reorder_level'=>(int)$p['reorder_level'],
        'safety_stock'=>(int)$p['safety_stock'],'next_expiration_date'=>$p['next_expiration_date'],
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));
echo json_encode(['success'=>true,'products'=>$products,'product'=>$products[0]??null]);
