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

    public function getCurrentSession(): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT s.*, u.full_name AS cashier_name, u.username AS cashier_username
             FROM gcash_service_sessions s
             JOIN users u ON s.cashier_id = u.user_id
             WHERE s.status = 'open'
             LIMIT 1"
        );
        $stmt->execute();
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            return null;
        }
        return $session;
    }

    public function calculateSessionExpected(int $sessionId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM gcash_service_sessions WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new DomainException('GCash service session not found.', 404);
        }
        $openingCash = (float)$session['opening_service_cash'];
        $openingWallet = (float)$session['opening_wallet_balance'];
        $expectedCash = $openingCash;
        $expectedWallet = $openingWallet;

        $txStmt = $this->pdo()->prepare(
            "SELECT transaction_type, amount FROM gcash_transactions WHERE session_id = ? AND verification_status = 'Verified'"
        );
        $txStmt->execute([$sessionId]);
        $txs = $txStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($txs as $tx) {
            $type = $tx['transaction_type'];
            $amount = (float)$tx['amount'];
            $principal = (int)round($amount);
            $preview = $this->calculatePreview($type, $principal);
            $expectedCash += (float)$preview['net_service_cash'];
            $expectedWallet += (float)$preview['net_wallet'];
        }

        return [
            'session_id' => $sessionId,
            'opening_service_cash' => round($openingCash, 2),
            'opening_wallet_balance' => round($openingWallet, 2),
            'expected_service_cash' => round($expectedCash, 2),
            'expected_wallet_balance' => round($expectedWallet, 2),
        ];
    }

    public function openSession(int $cashierId, float $openingServiceCash, float $openingWalletBalance): int
    {
        if (!is_finite($openingServiceCash) || $openingServiceCash < 0 || !is_finite($openingWalletBalance) || $openingWalletBalance < 0) {
            throw new DomainException('Opening balances must be 0 or greater.');
        }
        try {
            StoreWriteGate::begin($this->pdo());
            $shiftService = new CashierShiftService($this->pdo());
            $shiftService->requireUnlockedRegister($cashierId);
            $shift = $shiftService->getOpenShift($cashierId);
            if (!$shift) {
                throw new DomainException('Open your Cashier Shift before opening a GCash service session.');
            }
            $shiftId = (int)$shift['shift_id'];

            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $existing = $this->pdo()->query("SELECT session_id, cashier_id FROM gcash_service_sessions WHERE status = 'open'" . $lock)->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                throw new DomainException('Another Cashier currently has responsibility for GCash services. Only one Cashier may control GCash at a time.', 409);
            }

            $stmt = $this->pdo()->prepare(
                "INSERT INTO gcash_service_sessions (shift_id, cashier_id, opening_service_cash, opening_wallet_balance, status)
                 VALUES (?, ?, ?, ?, 'open')"
            );
            $stmt->execute([$shiftId, $cashierId, $openingServiceCash, $openingWalletBalance]);
            $sessionId = (int)$this->pdo()->lastInsertId();

            \log_activity($this->pdo(), $cashierId, 'GCash session opened', 'GCash', $sessionId, null,
                ['shift_id' => $shiftId, 'opening_service_cash' => $openingServiceCash, 'opening_wallet_balance' => $openingWalletBalance],
                null, AuditRecordCategory::STORE_OPERATION);

            $this->pdo()->commit();
            return $sessionId;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('Another Cashier currently has responsibility for GCash services. Only one Cashier may control GCash at a time.', 409, $e);
            }
            throw $e;
        }
    }

    public function closeSession(int $cashierId, float $actualServiceCash, float $actualWalletBalance, string $notes, int $unresolvedCarryover = 0): array
    {
        if (!is_finite($actualServiceCash) || $actualServiceCash < 0 || !is_finite($actualWalletBalance) || $actualWalletBalance < 0) {
            throw new DomainException('Closing balances must be 0 or greater.');
        }
        try {
            StoreWriteGate::begin($this->pdo());
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
            $stmt->execute();
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('No open GCash service session found.', 404);
            }

            $isOwner = (int)$session['cashier_id'] === $cashierId;
            if (!$isOwner) {
                $userStmt = $this->pdo()->prepare('SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?');
                $userStmt->execute([$cashierId]);
                $roleName = $userStmt->fetchColumn();
                if (!in_array($roleName, ['admin', 'super_admin'], true)) {
                    throw new DomainException('Only the responsible Cashier or an Administrator can close this GCash session.', 403);
                }
            }

            $sessionId = (int)$session['session_id'];
            $expected = $this->calculateSessionExpected($sessionId);
            $expectedCash = (float)$expected['expected_service_cash'];
            $expectedWallet = (float)$expected['expected_wallet_balance'];
            $cashVariance = round($actualServiceCash - $expectedCash, 2);
            $walletVariance = round($actualWalletBalance - $expectedWallet, 2);

            $update = $this->pdo()->prepare(
                "UPDATE gcash_service_sessions
                 SET status = 'closed', closed_at = CURRENT_TIMESTAMP, closed_by = ?,
                     expected_service_cash = ?, expected_wallet_balance = ?,
                     closing_service_cash = ?, closing_wallet_balance = ?,
                     cash_variance = ?, wallet_variance = ?, closing_notes = ?,
                     unresolved_carryover_count = ?
                 WHERE session_id = ? AND status = 'open'"
            );
            $update->execute([
                $cashierId,
                $expectedCash, $expectedWallet,
                $actualServiceCash, $actualWalletBalance,
                $cashVariance, $walletVariance,
                $notes !== '' ? $notes : null,
                max(0, $unresolvedCarryover),
                $sessionId,
            ]);

            \log_activity($this->pdo(), $cashierId, 'GCash session closed', 'GCash', $sessionId,
                ['opening_service_cash' => $session['opening_service_cash'], 'opening_wallet_balance' => $session['opening_wallet_balance']],
                ['expected_service_cash' => $expectedCash, 'expected_wallet_balance' => $expectedWallet, 'closing_service_cash' => $actualServiceCash, 'closing_wallet_balance' => $actualWalletBalance, 'cash_variance' => $cashVariance, 'wallet_variance' => $walletVariance, 'unresolved_carryover_count' => $unresolvedCarryover, 'closing_notes' => $notes],
                null, AuditRecordCategory::STORE_OPERATION);

            $this->pdo()->commit();

            return [
                'session_id' => $sessionId,
                'status' => 'closed',
                'expected_service_cash' => $expectedCash,
                'expected_wallet_balance' => $expectedWallet,
                'closing_service_cash' => $actualServiceCash,
                'closing_wallet_balance' => $actualWalletBalance,
                'cash_variance' => $cashVariance,
                'wallet_variance' => $walletVariance,
                'unresolved_carryover_count' => max(0, $unresolvedCarryover),
                'closing_notes' => $notes,
            ];
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    public function handoverSession(int $outgoingCashierId, int $incomingCashierId, float $countedCash, float $checkedWallet, string $notes): array
    {
        if ($outgoingCashierId === $incomingCashierId) {
            throw new DomainException('Select a different Cashier for handover.');
        }
        if (!is_finite($countedCash) || $countedCash < 0 || !is_finite($checkedWallet) || $checkedWallet < 0) {
            throw new DomainException('Handover balances must be 0 or greater.');
        }
        try {
            StoreWriteGate::begin($this->pdo());
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessionStmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
            $sessionStmt->execute();
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('No open GCash service session found to hand over.', 404);
            }
            if ((int)$session['cashier_id'] !== $outgoingCashierId) {
                throw new DomainException('Only the responsible Cashier may hand over GCash responsibility.', 403);
            }

            $shiftService = new CashierShiftService($this->pdo());
            $shiftService->requireUnlockedRegister($incomingCashierId);
            $incomingShift = $shiftService->getOpenShift($incomingCashierId);
            if (!$incomingShift) {
                throw new DomainException('The incoming Cashier must have an active open shift before handover.');
            }
            $incomingShiftId = (int)$incomingShift['shift_id'];

            $sessionId = (int)$session['session_id'];
            $expected = $this->calculateSessionExpected($sessionId);
            $expectedCash = (float)$expected['expected_service_cash'];
            $expectedWallet = (float)$expected['expected_wallet_balance'];
            $cashVariance = round($countedCash - $expectedCash, 2);
            $walletVariance = round($checkedWallet - $expectedWallet, 2);

            $closeStmt = $this->pdo()->prepare(
                "UPDATE gcash_service_sessions
                 SET status = 'closed', closed_at = CURRENT_TIMESTAMP, closed_by = ?,
                     expected_service_cash = ?, expected_wallet_balance = ?,
                     closing_service_cash = ?, closing_wallet_balance = ?,
                     cash_variance = ?, wallet_variance = ?, closing_notes = ?
                 WHERE session_id = ? AND status = 'open'"
            );
            $closeStmt->execute([
                $outgoingCashierId,
                $expectedCash, $expectedWallet,
                $countedCash, $checkedWallet,
                $cashVariance, $walletVariance,
                $notes !== '' ? 'Handover: ' . $notes : 'Handover to Cashier #' . $incomingCashierId,
                $sessionId,
            ]);

            \log_activity($this->pdo(), $outgoingCashierId, 'GCash session closed for handover', 'GCash', $sessionId,
                ['opening_service_cash' => $session['opening_service_cash'], 'opening_wallet_balance' => $session['opening_wallet_balance']],
                ['closing_service_cash' => $countedCash, 'closing_wallet_balance' => $checkedWallet, 'cash_variance' => $cashVariance, 'wallet_variance' => $walletVariance, 'incoming_cashier_id' => $incomingCashierId],
                null, AuditRecordCategory::STORE_OPERATION);

            $openStmt = $this->pdo()->prepare(
                "INSERT INTO gcash_service_sessions (shift_id, cashier_id, opening_service_cash, opening_wallet_balance, status)
                 VALUES (?, ?, ?, ?, 'open')"
            );
            $openStmt->execute([$incomingShiftId, $incomingCashierId, $countedCash, $checkedWallet]);
            $newSessionId = (int)$this->pdo()->lastInsertId();

            \log_activity($this->pdo(), $incomingCashierId, 'GCash session opened via handover', 'GCash', $newSessionId, null,
                ['shift_id' => $incomingShiftId, 'opening_service_cash' => $countedCash, 'opening_wallet_balance' => $checkedWallet, 'predecessor_session_id' => $sessionId, 'handed_over_by' => $outgoingCashierId],
                null, AuditRecordCategory::STORE_OPERATION);

            $this->pdo()->commit();

            return [
                'outgoing_session_id' => $sessionId,
                'incoming_session_id' => $newSessionId,
                'cash_variance' => $cashVariance,
                'wallet_variance' => $walletVariance,
                'closing_service_cash' => $countedCash,
                'closing_wallet_balance' => $checkedWallet,
            ];
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    public function emergencyHandover(int $adminId, int $incomingCashierId, float $countedCash, float $checkedWallet, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Enter a reason for emergency handover.');
        }
        if (!is_finite($countedCash) || $countedCash < 0 || !is_finite($checkedWallet) || $checkedWallet < 0) {
            throw new DomainException('Emergency handover balances must be 0 or greater.');
        }
        try {
            StoreWriteGate::begin($this->pdo());
            $userStmt = $this->pdo()->prepare('SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?');
            $userStmt->execute([$adminId]);
            $roleName = $userStmt->fetchColumn();
            if (!in_array($roleName, ['admin', 'super_admin'], true)) {
                throw new DomainException('Only an Administrator can execute an emergency handover.', 403);
            }

            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessionStmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
            $sessionStmt->execute();
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('No open GCash service session found to hand over.', 404);
            }

            $shiftService = new CashierShiftService($this->pdo());
            $shiftService->requireUnlockedRegister($incomingCashierId);
            $incomingShift = $shiftService->getOpenShift($incomingCashierId);
            if (!$incomingShift) {
                throw new DomainException('The incoming Cashier must have an active open shift before handover.');
            }
            $incomingShiftId = (int)$incomingShift['shift_id'];

            $sessionId = (int)$session['session_id'];
            $expected = $this->calculateSessionExpected($sessionId);
            $expectedCash = (float)$expected['expected_service_cash'];
            $expectedWallet = (float)$expected['expected_wallet_balance'];
            $cashVariance = round($countedCash - $expectedCash, 2);
            $walletVariance = round($checkedWallet - $expectedWallet, 2);

            $closeStmt = $this->pdo()->prepare(
                "UPDATE gcash_service_sessions
                 SET status = 'closed', closed_at = CURRENT_TIMESTAMP, closed_by = ?,
                     expected_service_cash = ?, expected_wallet_balance = ?,
                     closing_service_cash = ?, closing_wallet_balance = ?,
                     cash_variance = ?, wallet_variance = ?, closing_notes = ?
                 WHERE session_id = ? AND status = 'open'"
            );
            $closeStmt->execute([
                $adminId,
                $expectedCash, $expectedWallet,
                $countedCash, $checkedWallet,
                $cashVariance, $walletVariance,
                'Emergency Handover: ' . $reason,
                $sessionId,
            ]);

            \log_activity($this->pdo(), $adminId, 'GCash session closed via emergency handover', 'GCash', $sessionId,
                ['previous_cashier_id' => $session['cashier_id']],
                ['closing_service_cash' => $countedCash, 'closing_wallet_balance' => $checkedWallet, 'cash_variance' => $cashVariance, 'wallet_variance' => $walletVariance, 'incoming_cashier_id' => $incomingCashierId, 'reason' => $reason],
                null, AuditRecordCategory::STORE_OPERATION);

            $openStmt = $this->pdo()->prepare(
                "INSERT INTO gcash_service_sessions (shift_id, cashier_id, opening_service_cash, opening_wallet_balance, status)
                 VALUES (?, ?, ?, ?, 'open')"
            );
            $openStmt->execute([$incomingShiftId, $incomingCashierId, $countedCash, $checkedWallet]);
            $newSessionId = (int)$this->pdo()->lastInsertId();

            \log_activity($this->pdo(), $incomingCashierId, 'GCash session opened via emergency handover', 'GCash', $newSessionId, null,
                ['shift_id' => $incomingShiftId, 'opening_service_cash' => $countedCash, 'opening_wallet_balance' => $checkedWallet, 'predecessor_session_id' => $sessionId, 'authorized_by' => $adminId],
                null, AuditRecordCategory::STORE_OPERATION);

            $this->pdo()->commit();

            return [
                'outgoing_session_id' => $sessionId,
                'incoming_session_id' => $newSessionId,
                'cash_variance' => $cashVariance,
                'wallet_variance' => $walletVariance,
                'closing_service_cash' => $countedCash,
                'closing_wallet_balance' => $checkedWallet,
            ];
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
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

            $session = $this->getCurrentSession();
            $sessionId = null;
            if ($session) {
                if ((int)$session['cashier_id'] !== $cashierId) {
                    throw new DomainException('Another Cashier currently has responsibility for GCash services. Only one Cashier may control GCash at a time.', 409);
                }
                $sessionId = (int)$session['session_id'];
            }

            $stmt = $this->pdo()->prepare("INSERT INTO gcash_transactions (session_id, transaction_type, reference_number, amount, cashier_id, verification_status) VALUES (?, ?, ?, ?, ?, 'Pending')");
            $stmt->execute([$sessionId, $type, $reference, $amount, $cashierId]);
            $id = (int)$this->pdo()->lastInsertId();
            \log_activity($this->pdo(), $cashierId, 'GCash transaction recorded', 'GCash', $id, null,
                ['session_id' => $sessionId, 'reference_number' => $reference, 'amount' => $amount, 'transaction_type' => $type, 'verification_status' => 'Pending'],
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
