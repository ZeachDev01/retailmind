<?php

namespace App\Services;

use PDO;
use RuntimeException;

require_once __DIR__ . '/ReceiptDetailsService.php';

/** Customer-only details, captured inside the Cash Refund transaction. */
final class RefundReceiptService
{
    public function __construct(private PDO $pdo) {}

    public function preserve(int $refundId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Refund receipt details require the refund transaction.');
        }
        $statement = $this->pdo->prepare('SELECT cr.refund_id, cr.sale_id, cr.refund_amount,
            cr.payment_method, cr.payment_reference, cr.reason, cr.created_at, u.full_name AS cashier_name, r.name AS register_name
            FROM cash_refunds cr JOIN users u ON u.user_id = cr.cashier_id
            JOIN cashier_shifts cs ON cs.shift_id = cr.shift_id
            LEFT JOIN registers r ON r.register_id = cs.register_id WHERE cr.refund_id = ?');
        $statement->execute([$refundId]);
        $refund = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$refund) {
            throw new RuntimeException('Refund receipt could not be preserved.');
        }
        $refund['reason'] = CashRefundService::REFUND_REASONS[$refund['reason']];
        $statement = $this->pdo->prepare('SELECT p.product_name, p.sku, cri.quantity, cri.subtotal
            FROM cash_refund_items cri JOIN products p ON p.product_id = cri.product_id
            WHERE cri.refund_id = ? ORDER BY cri.refund_item_id');
        $statement->execute([$refundId]);
        $details = ['refund' => $refund, 'items' => $statement->fetchAll(PDO::FETCH_ASSOC),
            'store' => (new ReceiptDetailsService($this->pdo))->storeInfo()];
        $statement = $this->pdo->prepare('INSERT INTO refund_receipt_details (refund_id, details_json) VALUES (?, ?)');
        $statement->execute([$refundId, json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }

    public function forCashier(int $refundId, int $cashierId, int $storeId): ?array
    {
        // Live ownership and Store checks precede access to immutable customer data.
        $statement = $this->pdo->prepare('SELECT sale_id FROM cash_refunds WHERE refund_id = ? AND cashier_id = ?');
        $statement->execute([$refundId, $cashierId]);
        $saleId = $statement->fetchColumn();
        if ($saleId === false || (new ReceiptDetailsService($this->pdo))->fetchSale((int)$saleId, $storeId) === null) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT details_json FROM refund_receipt_details WHERE refund_id = ?');
        $statement->execute([$refundId]);
        $json = $statement->fetchColumn();
        return $json === false ? null : json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
    }
}
