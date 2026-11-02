<?php

namespace App\Receipts;

use PDO;

require_once __DIR__ . "/ReceiptEngine.php";

/** Compatible entry point; receipt rules belong to Receipt Engine. */
final class RefundReceiptService
{
    public function __construct(private PDO $pdo) {}

    public function preserve(int $refundId): void
    {
        (new ReceiptEngine($this->pdo))->preserveRefund($refundId);
    }

    public function forCashier(int $refundId, int $cashierId, int $storeId): ?array
    {
        return (new ReceiptEngine($this->pdo))->fetchRefundForCashier($refundId, $cashierId, $storeId);
    }
}
