<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Store\StoreWriteGate;
use DomainException;
use PDO;
use PDOException;
use Throwable;

final class GCashTransactionService
{
    public function __construct(private ?PDO $pdo = null, private array $rules = []) {}

    private function pdo(): PDO
    {
        if (!$this->pdo) {
            throw new DomainException('Database connection required for this operation.');
        }
        return $this->pdo;
    }

    public function calculateFee(mixed $principal): array
    {
        $amount = $this->validatePrincipal($principal);
        return [
            'principal' => $amount,
            'fee' => $this->feeForPrincipal($amount),
        ];
    }

    public function calculatePreview(string $type, mixed $principal, ?string $feeMethod = null): array
    {
        if (!in_array($type, ['Cash-In', 'Cash-Out'], true)) {
            throw new DomainException('Select Cash-In or Cash-Out.');
        }
        $amount = $this->validatePrincipal($principal);
        $fee = $this->feeForPrincipal($amount);

        if ($type === 'Cash-In') {
            $method = 'Fee in cash';
            return [
                'type' => 'Cash-In',
                'principal' => $amount,
                'fee' => $fee,
                'fee_method' => $method,
                'fee_location' => 'cash',
                'customer_transfer' => 0.0,
                'counter_collect' => round($amount + $fee, 2),
                'cash_payout' => 0.0,
                'wallet_transfer' => (float)$amount,
                'net_service_cash' => round($amount + $fee, 2),
                'net_wallet' => round(-$amount, 2),
            ];
        }

        $method = $this->normalizeCashOutFeeMethod($feeMethod);
        if ($method === 'Fee via GCash') {
            return [
                'type' => 'Cash-Out',
                'principal' => $amount,
                'fee' => $fee,
                'fee_method' => 'Fee via GCash',
                'fee_location' => 'wallet',
                'customer_transfer' => round($amount + $fee, 2),
                'counter_collect' => 0.0,
                'cash_payout' => (float)$amount,
                'wallet_transfer' => 0.0,
                'net_service_cash' => round(-$amount, 2),
                'net_wallet' => round($amount + $fee, 2),
            ];
        }

        return [
            'type' => 'Cash-Out',
            'principal' => $amount,
            'fee' => $fee,
            'fee_method' => 'Fee in cash',
            'fee_location' => 'cash',
            'customer_transfer' => (float)$amount,
            'counter_collect' => (float)$fee,
            'cash_payout' => (float)$amount,
            'wallet_transfer' => 0.0,
            'net_service_cash' => round(-($amount - $fee), 2),
            'net_wallet' => (float)$amount,
        ];
    }

    public function validatePrincipal(mixed $principal): int
    {
        if (!is_int($principal) && !is_string($principal)) {
            throw new DomainException('Enter a whole-peso principal amount from 1 to 50,000.');
        }
        $str = is_string($principal) ? trim($principal) : (string)$principal;
        if (!preg_match('/\A[1-9][0-9]{0,6}\z/', $str)) {
            throw new DomainException('Enter a whole-peso principal amount from 1 to 50,000.');
        }
        $val = (int)$str;
        if ($val < 1 || $val > 50000) {
            throw new DomainException('Enter a whole-peso principal amount from 1 to 50,000.');
        }
        return $val;
    }

    public function feeForPrincipal(int $principal): float
    {
        if ($principal < 500) {
            return 5.0;
        }
        if ($principal <= 1000) {
            return 10.0;
        }
        if ($principal <= 10000) {
            return (float)((int)ceil($principal / 500) * 5);
        }
        return round($principal * 0.01, 2);
    }

    private function normalizeCashOutFeeMethod(?string $method): string
    {
        if ($method === null || $method === '' || $method === 'Fee via GCash' || $method === 'wallet' || $method === 'gcash') {
            return 'Fee via GCash';
        }
        if ($method === 'Fee in cash' || $method === 'cash') {
            return 'Fee in cash';
        }
        throw new DomainException('Select a valid fee payment method.');
    }

    public function reference(string $value): string
    {
        $reference = preg_replace('/[\s-]+/u', '', $value);
        if (!is_string($reference) || !preg_match('/\A[0-9]{' . $this->rules['min_length'] . ',' . $this->rules['max_length'] . '}\z/', $reference)) {
            throw new DomainException('Enter a numeric GCash reference matching the configured length.');
        }
        return $reference;
    }

    public function exists(string $reference): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM gcash_transactions WHERE reference_number = ?');
        $stmt->execute([$this->reference($reference)]);
        return (bool)$stmt->fetchColumn();
    }

    public function history(int $cashierId, int $page): array
    {
        $offset = (max(1, min(100000, $page)) - 1) * 25;
        $stmt = $this->pdo()->prepare('SELECT * FROM gcash_transactions WHERE cashier_id = ? ORDER BY transaction_id DESC LIMIT 26 OFFSET ' . $offset);
        $stmt->execute([$cashierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['transactions' => array_slice($rows, 0, 25), 'has_next' => count($rows) > 25];
    }

    public function save(int $cashierId, string $reference, string $amount, string $type): int
    {
        $reference = $this->reference($reference);
        if (!in_array($type, ['Cash-In', 'Cash-Out', 'Payment'], true)) {
            throw new DomainException('Select Cash-In, Cash-Out, or Payment.');
        }
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $amount) || (float)$amount <= 0) {
            throw new DomainException('Enter an amount from 0.01 to 99,999,999.99 with at most two decimal places.');
        }
        try {
            StoreWriteGate::begin($this->pdo());
            (new CashierShiftService($this->pdo()))->requireUnlockedRegister($cashierId);
            $stmt = $this->pdo()->prepare("INSERT INTO gcash_transactions (transaction_type, reference_number, amount, cashier_id, verification_status) VALUES (?, ?, ?, ?, 'Pending')");
            $stmt->execute([$type, $reference, $amount, $cashierId]);
            $id = (int)$this->pdo()->lastInsertId();
            \log_activity($this->pdo(), $cashierId, 'GCash transaction recorded', 'GCash', $id, null,
                ['reference_number' => $reference, 'amount' => $amount, 'transaction_type' => $type, 'verification_status' => 'Pending'],
                null, AuditRecordCategory::STORE_OPERATION);
            $this->pdo()->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('This GCash reference has already been recorded.', 409, $e);
            }
            throw $e;
        }
    }

    public function review(int $cashierId, int $id, string $status, bool $confirmed, string $note): void
    {
        $note = trim($note);
        if (!in_array($status, ['Verified', 'Rejected'], true) || !preg_match('/\A.{5,500}\z/us', $note)) {
            throw new DomainException('Choose Verified or Rejected and enter a review note of 5 to 500 characters.');
        }
        if ($status === 'Verified' && !$confirmed) {
            throw new DomainException('Check the actual reference, amount, and transaction type in the authorized GCash account before confirming.');
        }
        try {
            StoreWriteGate::begin($this->pdo());
            (new CashierShiftService($this->pdo()))->requireUnlockedRegister($cashierId);
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo()->prepare('SELECT * FROM gcash_transactions WHERE transaction_id = ? AND cashier_id = ?' . $lock);
            $stmt->execute([$id, $cashierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new DomainException('This transaction is unavailable.', 404); }
            if ($row['verification_status'] !== 'Pending') {
                throw new DomainException('This transaction has already been reviewed. Refresh history.', 409);
            }
            $stmt = $this->pdo()->prepare("UPDATE gcash_transactions SET verification_status = ?, verified_by = ?, verification_note = ?, reviewed_at = CURRENT_TIMESTAMP WHERE transaction_id = ? AND verification_status = 'Pending'");
            $stmt->execute([$status, $cashierId, $note, $id]);
            \log_activity($this->pdo(), $cashierId, 'GCash transaction ' . strtolower($status), 'GCash', $id,
                ['verification_status' => 'Pending'], ['verification_status' => $status, 'verification_note' => $note],
                null, AuditRecordCategory::STORE_OPERATION);
            $this->pdo()->commit();
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }
}
