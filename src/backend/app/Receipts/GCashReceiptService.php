<?php

namespace App\Receipts;

use PDO;

require_once __DIR__ . "/ReceiptEngine.php";

/** Compatible entry point; receipt rules belong to Receipt Engine. */
final class GCashReceiptService
{
    public function __construct(private PDO $pdo) {}

    public function preserve(int $transactionId): void
    {
        (new ReceiptEngine($this->pdo))->preserveGCash($transactionId);
    }

    public function fetch(int $transactionId, int $actorId, string $actorRole): ?array
    {
        return (new ReceiptEngine($this->pdo))->fetchGCash($transactionId, $actorId, $actorRole);
    }
}
