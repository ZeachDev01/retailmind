<?php

namespace App\Receipts;

use PDO;
use RuntimeException;

/** Customer details are captured inside the financial transaction, read without posting. */
final class GCashReceiptService
{
    public function __construct(private PDO $pdo) {}

    public function preserve(int $transactionId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('GCash receipt details require the completion transaction.');
        }
        $statement = $this->pdo->prepare("SELECT t.*, u.full_name AS cashier_name, u.username AS cashier_username, r.name AS register_name
            FROM gcash_transactions t JOIN users u ON u.user_id = t.cashier_id
            LEFT JOIN cashier_shifts cs ON cs.shift_id = t.shift_id
            LEFT JOIN registers r ON r.register_id = cs.register_id
            WHERE t.transaction_id = ? AND t.verification_status = 'Completed'");
        $statement->execute([$transactionId]);
        $tx = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$tx || !$tx['completed_at'] || !$tx['reference_number']) {
            throw new RuntimeException('Only a completed exchange can preserve a GCash Service Receipt.');
        }
        if ($tx['receipt_details_json'] !== null) {
            return;
        }
        $principal = (int)round((float)$tx['principal_amount'] * 100);
        $fee = (int)round((float)$tx['fee_amount'] * 100);
        $cashIn = $tx['transaction_type'] === 'Cash-In';
        $cashFee = $tx['fee_method'] === 'cash';
        $decimal = static fn(int $centavos): string => number_format($centavos / 100, 2, '.', '');
        $store = (new ReceiptDetailsService($this->pdo))->storeInfo();
        $store['tagline'] = 'GCash Service Receipt';
        $details = [
            'store' => $store,
            'transaction_id' => (int)$tx['transaction_id'],
            'cashier_id' => (int)$tx['cashier_id'],
            'cashier_name' => $tx['cashier_name'] ?: $tx['cashier_username'],
            'register_name' => $tx['register_name'] ?: 'Unassigned',
            'completed_at' => $tx['completed_at'],
            'transaction_type' => $tx['transaction_type'],
            'reference_number' => $tx['reference_number'],
            'principal_amount' => $decimal($principal),
            'fee_amount' => $decimal($fee),
            'fee_method' => $cashFee ? 'Fee in cash' : 'Fee via GCash',
            'cash_collected' => $decimal($cashIn ? $principal + $fee : ($cashFee ? $fee : 0)),
            'cash_paid_out' => $decimal($cashIn ? 0 : $principal),
            'wallet_sent' => $decimal($cashIn ? $principal : 0),
            'wallet_received' => $decimal($cashIn ? 0 : $principal + ($cashFee ? 0 : $fee)),
        ];
        $this->pdo->prepare('UPDATE gcash_transactions SET receipt_details_json = ? WHERE transaction_id = ? AND receipt_details_json IS NULL')
            ->execute([json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $transactionId]);
    }

    public function fetch(int $transactionId, int $actorId, string $actorRole): ?array
    {
        if ($actorId <= 0 || !in_array($actorRole, ['cashier', 'admin', 'super_admin'], true)) {
            return null;
        }
        $statement = $this->pdo->prepare("SELECT receipt_details_json FROM gcash_transactions
            WHERE transaction_id = ? AND verification_status = 'Completed'" . ($actorRole === 'cashier' ? ' AND cashier_id = ?' : ''));
        $statement->execute($actorRole === 'cashier' ? [$transactionId, $actorId] : [$transactionId]);
        $json = $statement->fetchColumn();
        return $json ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
    }
}
