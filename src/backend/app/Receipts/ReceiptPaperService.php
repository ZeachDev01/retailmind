<?php

namespace App\Receipts;

use PDO;

/** Printer layout comes from the active workspace, never the recorded sale. */
final class ReceiptPaperService
{
    public function __construct(private PDO $pdo) {}

    public function currentWidth(int $actorId, string $actorRole): int
    {
        if ($actorRole !== 'cashier' || $actorId <= 0) {
            return 80;
        }
        $statement = $this->pdo->prepare(
            "SELECT r.paper_width_mm FROM cashier_shifts cs
             LEFT JOIN registers r ON r.register_id = cs.register_id
             WHERE cs.cashier_id = ? AND cs.status = 'open'
             ORDER BY cs.opened_at DESC LIMIT 1"
        );
        $statement->execute([$actorId]);
        return (string)$statement->fetchColumn() === '58' ? 58 : 80;
    }
}
