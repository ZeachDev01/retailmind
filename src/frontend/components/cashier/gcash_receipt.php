<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['cashier', 'admin', 'super_admin']);
if (current_role() === 'cashier') {
    require_capability(\App\Authorization\RoleCapabilityPolicy::OPERATE_POINT_OF_SALE);
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$id = filter_var($_GET['transaction_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$receipt = $id ? (new \App\Receipts\GCashReceiptService($pdo))->fetch($id, (int)$_SESSION['user_id'], (string)current_role()) : null;
if (!$receipt) {
    http_response_code(404);
    exit('Saved GCash Service Receipt unavailable.');
}
$width = (new \App\Receipts\ReceiptPaperService($pdo))->currentWidth((int)$_SESSION['user_id'], (string)current_role());
\App\Receipts\GCashReceiptPresentation::render($receipt, $width);
