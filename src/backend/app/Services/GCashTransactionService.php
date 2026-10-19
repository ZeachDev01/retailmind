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

    public function cashierOverview(int $cashierId): array
    {
        $shiftService = new CashierShiftService($this->pdo());
        $shift = $shiftService->getOpenShift($cashierId);
        $shiftId = $shift ? (int)$shift['shift_id'] : null;

        $session = $this->getCurrentSession();
        $sessionId = null;
        $isControlling = false;
        if ($session && (int)$session['cashier_id'] === $cashierId) {
            $sessionId = (int)$session['session_id'];
            $isControlling = true;
        }

        $expectedCash = 0.0;
        $expectedWallet = 0.0;
        $lastCheckedAt = null;
        $lastCheckedFormatted = null;

        if ($session && $isControlling) {
            $expected = $this->calculateSessionExpected($sessionId);
            $expectedCash = (float)$expected['expected_service_cash'];
            $expectedWallet = (float)$expected['expected_wallet_balance'];
            $lastCheckedAt = $session['opened_at'];
            $lastCheckedFormatted = PhilippineTime::format($session['opened_at']);
        }

        $cashInPrincipal = 0.0;
        $cashOutPrincipal = 0.0;
        $grossFeesCash = 0.0;
        $grossFeesWallet = 0.0;

        if ($sessionId) {
            $stmt = $this->pdo()->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN transaction_type = 'Cash-In' AND verification_status = 'Completed' THEN principal_amount ELSE 0 END), 0) AS cash_in_principal,
                    COALESCE(SUM(CASE WHEN transaction_type = 'Cash-Out' AND verification_status = 'Completed' THEN principal_amount ELSE 0 END), 0) AS cash_out_principal,
                    COALESCE(SUM(CASE WHEN fee_method = 'cash' AND verification_status = 'Completed' THEN gross_fee ELSE 0 END), 0) AS gross_fees_cash,
                    COALESCE(SUM(CASE WHEN fee_method = 'wallet' AND verification_status = 'Completed' THEN gross_fee ELSE 0 END), 0) AS gross_fees_wallet
                FROM gcash_transactions
                WHERE cashier_id = ? AND session_id = ?"
            );
            $stmt->execute([$cashierId, $sessionId]);
            $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($metrics) {
                $cashInPrincipal = round((float)$metrics['cash_in_principal'], 2);
                $cashOutPrincipal = round((float)$metrics['cash_out_principal'], 2);
                $grossFeesCash = round((float)$metrics['gross_fees_cash'], 2);
                $grossFeesWallet = round((float)$metrics['gross_fees_wallet'], 2);
            }
        } elseif ($shiftId) {
            $stmt = $this->pdo()->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN transaction_type = 'Cash-In' AND verification_status = 'Completed' THEN principal_amount ELSE 0 END), 0) AS cash_in_principal,
                    COALESCE(SUM(CASE WHEN transaction_type = 'Cash-Out' AND verification_status = 'Completed' THEN principal_amount ELSE 0 END), 0) AS cash_out_principal,
                    COALESCE(SUM(CASE WHEN fee_method = 'cash' AND verification_status = 'Completed' THEN gross_fee ELSE 0 END), 0) AS gross_fees_cash,
                    COALESCE(SUM(CASE WHEN fee_method = 'wallet' AND verification_status = 'Completed' THEN gross_fee ELSE 0 END), 0) AS gross_fees_wallet
                FROM gcash_transactions
                WHERE cashier_id = ? AND shift_id = ?"
            );
            $stmt->execute([$cashierId, $shiftId]);
            $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($metrics) {
                $cashInPrincipal = round((float)$metrics['cash_in_principal'], 2);
                $cashOutPrincipal = round((float)$metrics['cash_out_principal'], 2);
                $grossFeesCash = round((float)$metrics['gross_fees_cash'], 2);
                $grossFeesWallet = round((float)$metrics['gross_fees_wallet'], 2);
            }
        }

        $ncSql = "SELECT t.*, u.full_name AS cashier_name, u.username AS cashier_username
                  FROM gcash_transactions t
                  JOIN users u ON t.cashier_id = u.user_id
                  WHERE t.cashier_id = ? AND t.verification_status = 'Needs checking'";
        $ncParams = [$cashierId];
        if ($sessionId) {
            $ncSql .= " AND t.session_id = ?";
            $ncParams[] = $sessionId;
        } elseif ($shiftId) {
            $ncSql .= " AND t.shift_id = ?";
            $ncParams[] = $shiftId;
        }
        $ncSql .= " ORDER BY t.transaction_id DESC";
        $ncStmt = $this->pdo()->prepare($ncSql);
        $ncStmt->execute($ncParams);
        $needsChecking = [];
        foreach ($ncStmt->fetchAll(PDO::FETCH_ASSOC) as $nc) {
            $nc['is_legacy'] = ($nc['session_id'] === null);
            $nc['created_at_formatted'] = PhilippineTime::format($nc['created_at']);
            $nc['time_formatted'] = PhilippineTime::format($nc['created_at']);
            $nc['principal_amount'] = $nc['principal_amount'] !== null ? (float)$nc['principal_amount'] : null;
            $nc['fee_amount'] = $nc['fee_amount'] !== null ? (float)$nc['fee_amount'] : null;
            $nc['gross_fee'] = (float)$nc['gross_fee'];
            $needsChecking[] = $nc;
        }

        return [
            'shift' => $shift,
            'session' => $isControlling ? $session : null,
            'is_controlling_cashier' => $isControlling,
            'expected_service_cash' => $expectedCash,
            'expected_wallet_balance' => $expectedWallet,
            'last_checked_at' => $lastCheckedAt,
            'last_checked_formatted' => $lastCheckedFormatted,
            'cash_in_principal' => $cashInPrincipal,
            'cash_out_principal' => $cashOutPrincipal,
            'gross_fees_cash' => $grossFeesCash,
            'gross_fees_wallet' => $grossFeesWallet,
            'gross_fees_total' => round($grossFeesCash + $grossFeesWallet, 2),
            'needs_checking' => $needsChecking,
            'needs_checking_count' => count($needsChecking),
        ];
    }

    public function history(int $cashierId, int $page): array
    {
        $offset = (max(1, min(100000, $page)) - 1) * 25;
        $stmt = $this->pdo()->prepare(
            'SELECT t.*, u.full_name AS cashier_name, u.username AS cashier_username
             FROM gcash_transactions t
             JOIN users u ON t.cashier_id = u.user_id
             WHERE t.cashier_id = ?
             ORDER BY t.transaction_id DESC
             LIMIT 26 OFFSET ' . $offset
        );
        $stmt->execute([$cashierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $txs = array_slice($rows, 0, 25);
        if ($txs !== []) {
            $ids = array_map(static fn($r) => (int)$r['transaction_id'], $txs);
            $inClause = implode(',', $ids);
            $adjStmt = $this->pdo()->query("SELECT * FROM gcash_adjustments WHERE transaction_id IN ({$inClause}) ORDER BY adjustment_id ASC");
            $adjsByTx = [];
            foreach ($adjStmt->fetchAll(PDO::FETCH_ASSOC) as $adj) {
                $adjsByTx[$adj['transaction_id']][] = $adj;
            }
            foreach ($txs as &$tx) {
                $tx['adjustments'] = $adjsByTx[$tx['transaction_id']] ?? [];
                $isLegacy = ($tx['session_id'] === null);
                $tx['is_legacy'] = $isLegacy;
                if ($isLegacy) {
                    $tx['shift_id'] = null;
                    $tx['principal_amount'] = null;
                    $tx['fee_amount'] = null;
                    $tx['fee_method'] = null;
                    $tx['gross_fee'] = 0.00;
                }
                $tx['created_at_formatted'] = PhilippineTime::format($tx['created_at']);
                $tx['reviewed_at_formatted'] = $tx['reviewed_at'] ? PhilippineTime::format($tx['reviewed_at']) : null;
                $tx['completed_at_formatted'] = $tx['completed_at'] ? PhilippineTime::format($tx['completed_at']) : null;
                $tx['time_formatted'] = PhilippineTime::format($tx['completed_at'] ?? $tx['reviewed_at'] ?? $tx['created_at']);
            }
            unset($tx);
        }
        return ['transactions' => $txs, 'has_next' => count($rows) > 25, 'page' => $page];
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
            "SELECT transaction_type, amount, verification_status, service_cash_delta, wallet_delta FROM gcash_transactions WHERE session_id = ?"
        );
        $txStmt->execute([$sessionId]);
        $txs = $txStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($txs as $tx) {
            $status = $tx['verification_status'];
            if ($status === 'Completed' || $status === 'Needs checking' || $status === 'Rejected') {
                $expectedCash += (float)$tx['service_cash_delta'];
                $expectedWallet += (float)$tx['wallet_delta'];
            } elseif ($status === 'Verified') {
                if ((float)$tx['service_cash_delta'] != 0.0 || (float)$tx['wallet_delta'] != 0.0) {
                    $expectedCash += (float)$tx['service_cash_delta'];
                    $expectedWallet += (float)$tx['wallet_delta'];
                } else {
                    $type = $tx['transaction_type'];
                    $amount = (float)$tx['amount'];
                    $principal = (int)round($amount);
                    $preview = $this->calculatePreview($type, $principal);
                    $expectedCash += (float)$preview['net_service_cash'];
                    $expectedWallet += (float)$preview['net_wallet'];
                }
            }
        }

        $fundStmt = $this->pdo()->prepare(
            "SELECT fund_type, direction, amount FROM gcash_funding_movements WHERE session_id = ?"
        );
        $fundStmt->execute([$sessionId]);
        foreach ($fundStmt->fetchAll(PDO::FETCH_ASSOC) as $mv) {
            $amt = (float)$mv['amount'];
            $sign = $mv['direction'] === 'in' ? 1.0 : -1.0;
            if ($mv['fund_type'] === 'cash') {
                $expectedCash += $sign * $amt;
            } elseif ($mv['fund_type'] === 'wallet') {
                $expectedWallet += $sign * $amt;
            }
        }

        $adjStmt = $this->pdo()->prepare(
            "SELECT service_cash_delta, wallet_delta FROM gcash_adjustments WHERE session_id = ?"
        );
        $adjStmt->execute([$sessionId]);
        foreach ($adjStmt->fetchAll(PDO::FETCH_ASSOC) as $adj) {
            $expectedCash += (float)$adj['service_cash_delta'];
            $expectedWallet += (float)$adj['wallet_delta'];
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
                $userStmt = $this->pdo()->prepare('SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?');
                $userStmt->execute([$outgoingCashierId]);
                $roleName = $userStmt->fetchColumn();
                if (!in_array($roleName, ['admin', 'super_admin'], true)) {
                    throw new DomainException('Only the responsible Cashier or an Administrator can hand over GCash responsibility.', 403);
                }
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

    public function recordCashIn(int $cashierId, array $data): int
    {
        $outcome = (string)($data['outcome'] ?? $data['status'] ?? 'Completed');
        if (!in_array($outcome, ['Completed', 'Needs checking'], true)) {
            throw new DomainException('Outcome must be Completed or Needs checking.');
        }

        $principal = $this->validatePrincipal($data['principal'] ?? $data['amount'] ?? null);
        $preview = $this->calculatePreview('Cash-In', $principal);
        $authoritativeFee = (float)$preview['fee'];
        $counterCollect = (float)$preview['counter_collect'];
        $walletTransfer = (float)$preview['wallet_transfer'];
        $netCash = (float)$preview['net_service_cash'];
        $netWallet = (float)$preview['net_wallet'];

        $checks = [
            'fee' => $authoritativeFee,
            'preview_fee' => $authoritativeFee,
            'fee_amount' => $authoritativeFee,
            'counter_collect' => $counterCollect,
            'preview_counter_collect' => $counterCollect,
            'wallet_transfer' => $walletTransfer,
            'preview_wallet_transfer' => $walletTransfer,
            'net_service_cash' => $netCash,
            'net_wallet' => $netWallet,
        ];
        foreach ($checks as $key => $expectedVal) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $val = filter_var($data[$key], FILTER_VALIDATE_FLOAT);
                if ($val === false || abs($val - $expectedVal) > 0.009) {
                    throw new DomainException('The preview totals are stale or inconsistent with current fee calculation. Refresh preview before recording.', 422);
                }
            }
        }

        $confirmedWallet = !empty($data['confirmed_wallet']) || !empty($data['wallet_sent']);
        $confirmedCash = !empty($data['confirmed_cash']) || !empty($data['cash_collected']);
        $notes = isset($data['notes']) ? trim((string)$data['notes']) : (isset($data['note']) ? trim((string)$data['note']) : null);

        if ($outcome === 'Completed') {
            if (!$confirmedWallet || !$confirmedCash) {
                throw new DomainException('Both wallet transfer and counter cash receipt must be confirmed for completed Cash-In.');
            }
            $refRaw = (string)($data['reference'] ?? $data['reference_number'] ?? '');
            $reference = $this->reference($refRaw);
            $serviceCashDelta = $counterCollect;
            $walletDelta = -$walletTransfer;
            $grossFee = $authoritativeFee;
            $completedAt = date('Y-m-d H:i:s');
        } else {
            if ($notes === null || mb_strlen($notes) < 5) {
                throw new DomainException('Enter an explanatory note of at least 5 characters for transactions held as Needs checking.');
            }
            $refRaw = trim((string)($data['reference'] ?? $data['reference_number'] ?? ''));
            $reference = $refRaw !== '' ? $this->reference($refRaw) : null;
            $serviceCashDelta = $confirmedCash ? $counterCollect : 0.00;
            $walletDelta = $confirmedWallet ? -$walletTransfer : 0.00;
            $grossFee = ($confirmedCash && $confirmedWallet) ? $authoritativeFee : 0.00;
            $completedAt = null;
        }

        try {
            StoreWriteGate::begin($this->pdo());
            $shiftService = new CashierShiftService($this->pdo());
            $shiftService->requireUnlockedRegister($cashierId);
            $shift = $shiftService->getOpenShift($cashierId);
            if (!$shift) {
                throw new DomainException('Open your Cashier Shift before recording GCash transactions.');
            }
            $shiftId = (int)$shift['shift_id'];

            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessionStmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
            $sessionStmt->execute();
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('Open a GCash service session before recording transactions.');
            }
            if ((int)$session['cashier_id'] !== $cashierId) {
                throw new DomainException('Another Cashier currently has responsibility for GCash services. Only one Cashier may control GCash at a time.', 409);
            }
            $sessionId = (int)$session['session_id'];

            if ($walletDelta < 0) {
                $expected = $this->calculateSessionExpected($sessionId);
                $currentWallet = (float)$expected['expected_wallet_balance'];
                if ($currentWallet < abs($walletDelta)) {
                    throw new DomainException(sprintf(
                        'Insufficient Store GCash wallet balance. Available: PHP %.2f, required: PHP %.2f.',
                        $currentWallet,
                        abs($walletDelta)
                    ));
                }
            }

            $stmt = $this->pdo()->prepare(
                "INSERT INTO gcash_transactions (
                    session_id, shift_id, transaction_type, reference_number, amount,
                    principal_amount, fee_amount, fee_method, service_cash_delta, wallet_delta,
                    gross_fee, cashier_id, confirmed_wallet, confirmed_cash, notes,
                    verification_status, completed_at
                ) VALUES (
                    ?, ?, 'Cash-In', ?, ?,
                    ?, ?, 'cash', ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?
                )"
            );
            $stmt->execute([
                $sessionId,
                $shiftId,
                $reference,
                $principal,
                $principal,
                $authoritativeFee,
                $serviceCashDelta,
                $walletDelta,
                $grossFee,
                $cashierId,
                $confirmedWallet ? 1 : 0,
                $confirmedCash ? 1 : 0,
                $notes,
                $outcome,
                $completedAt,
            ]);
            $id = (int)$this->pdo()->lastInsertId();

            \log_activity(
                $this->pdo(),
                $cashierId,
                'GCash Cash-In ' . strtolower($outcome),
                'GCash',
                $id,
                null,
                [
                    'session_id' => $sessionId,
                    'shift_id' => $shiftId,
                    'reference_number' => $reference,
                    'principal_amount' => $principal,
                    'fee_amount' => $authoritativeFee,
                    'fee_method' => 'cash',
                    'service_cash_delta' => $serviceCashDelta,
                    'wallet_delta' => $walletDelta,
                    'gross_fee' => $grossFee,
                    'verification_status' => $outcome,
                    'confirmed_wallet' => $confirmedWallet ? 1 : 0,
                    'confirmed_cash' => $confirmedCash ? 1 : 0,
                    'notes' => $notes,
                ],
                null,
                AuditRecordCategory::STORE_OPERATION
            );

            $this->pdo()->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('This GCash reference has already been recorded.', 409, $e);
            }
            throw $e;
        }
    }

    public function recordCashOut(int $cashierId, array $data): int
    {
        $outcome = (string)($data['outcome'] ?? $data['status'] ?? 'Completed');
        if (!in_array($outcome, ['Completed', 'Needs checking'], true)) {
            throw new DomainException('Outcome must be Completed or Needs checking.');
        }

        $principal = $this->validatePrincipal($data['principal'] ?? $data['amount'] ?? null);
        $feeMethodNorm = $this->normalizeCashOutFeeMethod($data['fee_method'] ?? null);
        $dbFeeMethod = $feeMethodNorm === 'Fee via GCash' ? 'wallet' : 'cash';

        $preview = $this->calculatePreview('Cash-Out', $principal, $feeMethodNorm);
        $authoritativeFee = (float)$preview['fee'];
        $customerTransfer = (float)$preview['customer_transfer'];
        $counterCollect = (float)$preview['counter_collect'];
        $cashPayout = (float)$preview['cash_payout'];
        $netCash = (float)$preview['net_service_cash'];
        $netWallet = (float)$preview['net_wallet'];

        $checks = [
            'fee' => $authoritativeFee,
            'preview_fee' => $authoritativeFee,
            'fee_amount' => $authoritativeFee,
            'customer_transfer' => $customerTransfer,
            'preview_customer_transfer' => $customerTransfer,
            'counter_collect' => $counterCollect,
            'preview_counter_collect' => $counterCollect,
            'cash_payout' => $cashPayout,
            'preview_cash_payout' => $cashPayout,
            'net_service_cash' => $netCash,
            'net_wallet' => $netWallet,
        ];
        foreach ($checks as $key => $expectedVal) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $val = filter_var($data[$key], FILTER_VALIDATE_FLOAT);
                if ($val === false || abs($val - $expectedVal) > 0.009) {
                    throw new DomainException('The preview totals are stale or inconsistent with current fee calculation. Refresh preview before recording.', 422);
                }
            }
        }
        if (isset($data['preview_fee_method']) && $data['preview_fee_method'] !== '' && $data['preview_fee_method'] !== $feeMethodNorm) {
            throw new DomainException('The preview totals are stale or inconsistent with current fee calculation. Refresh preview before recording.', 422);
        }

        $confirmedWallet = !empty($data['confirmed_wallet']) || !empty($data['wallet_received']) || !empty($data['wallet_sent']) || !empty($data['wallet_transferred']);
        $confirmedCash = !empty($data['confirmed_cash']) || !empty($data['cash_handed']) || !empty($data['cash_payout_done']) || !empty($data['cash_collected']);
        $notes = isset($data['notes']) ? trim((string)$data['notes']) : (isset($data['note']) ? trim((string)$data['note']) : null);

        if ($outcome === 'Completed') {
            if (!$confirmedWallet || !$confirmedCash) {
                throw new DomainException('Both wallet transfer and cash handover must be confirmed for completed Cash-Out.');
            }
            $refRaw = (string)($data['reference'] ?? $data['reference_number'] ?? '');
            $reference = $this->reference($refRaw);
            if ($feeMethodNorm === 'Fee via GCash') {
                $serviceCashDelta = round(-$principal, 2);
                $walletDelta = round($principal + $authoritativeFee, 2);
            } else {
                $serviceCashDelta = round(-($principal - $authoritativeFee), 2);
                $walletDelta = (float)$principal;
            }
            $grossFee = $authoritativeFee;
            $completedAt = date('Y-m-d H:i:s');
        } else {
            if ($notes === null || mb_strlen($notes) < 5) {
                throw new DomainException('Enter an explanatory note of at least 5 characters for transactions held as Needs checking.');
            }
            $refRaw = trim((string)($data['reference'] ?? $data['reference_number'] ?? ''));
            $reference = $refRaw !== '' ? $this->reference($refRaw) : null;
            if ($feeMethodNorm === 'Fee via GCash') {
                $serviceCashDelta = $confirmedCash ? round(-$principal, 2) : 0.00;
                $walletDelta = $confirmedWallet ? round($principal + $authoritativeFee, 2) : 0.00;
            } else {
                $serviceCashDelta = $confirmedCash ? round(-($principal - $authoritativeFee), 2) : 0.00;
                $walletDelta = $confirmedWallet ? (float)$principal : 0.00;
            }
            $grossFee = ($confirmedCash && $confirmedWallet) ? $authoritativeFee : 0.00;
            $completedAt = null;
        }

        try {
            StoreWriteGate::begin($this->pdo());
            $shiftService = new CashierShiftService($this->pdo());
            $shiftService->requireUnlockedRegister($cashierId);
            $shift = $shiftService->getOpenShift($cashierId);
            if (!$shift) {
                throw new DomainException('Open your Cashier Shift before recording GCash transactions.');
            }
            $shiftId = (int)$shift['shift_id'];

            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessionStmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
            $sessionStmt->execute();
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('Open a GCash service session before recording transactions.');
            }
            if ((int)$session['cashier_id'] !== $cashierId) {
                throw new DomainException('Another Cashier currently has responsibility for GCash services. Only one Cashier may control GCash at a time.', 409);
            }
            $sessionId = (int)$session['session_id'];

            if ($confirmedCash) {
                $expected = $this->calculateSessionExpected($sessionId);
                $currentCash = (float)$expected['expected_service_cash'];
                if ($currentCash < $cashPayout) {
                    throw new DomainException(sprintf(
                        'Insufficient service cash balance. Available: PHP %.2f, required: PHP %.2f.',
                        $currentCash,
                        $cashPayout
                    ), 422);
                }
            }

            $stmt = $this->pdo()->prepare(
                "INSERT INTO gcash_transactions (
                    session_id, shift_id, transaction_type, reference_number, amount,
                    principal_amount, fee_amount, fee_method, service_cash_delta, wallet_delta,
                    gross_fee, cashier_id, confirmed_wallet, confirmed_cash, notes,
                    verification_status, completed_at
                ) VALUES (
                    ?, ?, 'Cash-Out', ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?
                )"
            );
            $stmt->execute([
                $sessionId,
                $shiftId,
                $reference,
                $principal,
                $principal,
                $authoritativeFee,
                $dbFeeMethod,
                $serviceCashDelta,
                $walletDelta,
                $grossFee,
                $cashierId,
                $confirmedWallet ? 1 : 0,
                $confirmedCash ? 1 : 0,
                $notes,
                $outcome,
                $completedAt,
            ]);
            $id = (int)$this->pdo()->lastInsertId();

            \log_activity(
                $this->pdo(),
                $cashierId,
                'GCash Cash-Out ' . strtolower($outcome),
                'GCash',
                $id,
                null,
                [
                    'session_id' => $sessionId,
                    'shift_id' => $shiftId,
                    'reference_number' => $reference,
                    'principal_amount' => $principal,
                    'fee_amount' => $authoritativeFee,
                    'fee_method' => $dbFeeMethod,
                    'service_cash_delta' => $serviceCashDelta,
                    'wallet_delta' => $walletDelta,
                    'gross_fee' => $grossFee,
                    'verification_status' => $outcome,
                    'confirmed_wallet' => $confirmedWallet ? 1 : 0,
                    'confirmed_cash' => $confirmedCash ? 1 : 0,
                    'notes' => $notes,
                ],
                null,
                AuditRecordCategory::STORE_OPERATION
            );

            $this->pdo()->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('This GCash reference has already been recorded.', 409, $e);
            }
            throw $e;
        }
    }

    public function authorizeAdmin(string $username, string $password): int
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw new DomainException('Administrator username and password are required.', 403);
        }
        $locking = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->pdo()->prepare(
            "SELECT u.user_id, u.password_hash, r.role_name
             FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE u.username = ? AND u.status = 'active' AND u.must_change_password = 0 AND r.role_name IN ('admin', 'super_admin')" . $locking
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            throw new DomainException('Administrator authorization failed: invalid credentials.', 403);
        }
        return (int)$user['user_id'];
    }

    public function recordFundingMovement(int $actorId, string $actorRole, array $data): int
    {
        $type = (string)($data['movement_type'] ?? '');
        if (!in_array($type, ['replenishment', 'withdrawal', 'product_drawer_transfer', 'owner_capital'], true)) {
            throw new DomainException('Select a valid funding movement type.');
        }
        $fundType = (string)($data['fund_type'] ?? '');
        if (!in_array($fundType, ['cash', 'wallet'], true)) {
            throw new DomainException('Select cash or wallet.');
        }
        $direction = (string)($data['direction'] ?? '');
        if (!in_array($direction, ['in', 'out'], true)) {
            throw new DomainException('Select in or out direction.');
        }
        $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($amount === false || $amount <= 0 || !is_finite($amount) || round($amount, 2) !== round($amount, 4)) {
            throw new DomainException('Enter a positive funding amount in pesos and centavos.');
        }
        $amount = round($amount, 2);

        $reason = trim((string)($data['reason'] ?? ''));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new DomainException('Enter a reason between 5 and 255 characters.');
        }

        $evidence = trim((string)($data['evidence_reference'] ?? $data['evidence'] ?? ''));
        if ($fundType === 'wallet' || in_array($type, ['owner_capital', 'product_drawer_transfer'], true)) {
            if ($evidence === '') {
                throw new DomainException('Evidence reference (transaction reference, transfer slip, or receipt) is required.');
            }
        }
        if (strlen($evidence) > 120) {
            throw new DomainException('Evidence reference must be 120 characters or fewer.');
        }

        try {
            StoreWriteGate::begin($this->pdo());
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessionStmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
            $sessionStmt->execute();
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('Open a GCash service session before recording funding movements.');
            }
            $sessionId = (int)$session['session_id'];

            $isAdmin = in_array($actorRole, ['admin', 'super_admin'], true);
            if ($isAdmin) {
                $authorizedBy = $actorId;
            } elseif ($actorRole === 'cashier') {
                if ((int)$session['cashier_id'] !== $actorId) {
                    throw new DomainException('Another Cashier currently has responsibility for GCash services.', 409);
                }
                $authorizedBy = $this->authorizeAdmin((string)($data['approver_username'] ?? ''), (string)($data['approver_password'] ?? ''));
            } else {
                throw new DomainException('Unauthorized role for funding movement.', 403);
            }

            if ($direction === 'out') {
                $expected = $this->calculateSessionExpected($sessionId);
                if ($fundType === 'cash' && $expected['expected_service_cash'] < $amount) {
                    throw new DomainException(sprintf('Insufficient service cash balance. Available: PHP %.2f, required: PHP %.2f.', $expected['expected_service_cash'], $amount), 422);
                }
                if ($fundType === 'wallet' && $expected['expected_wallet_balance'] < $amount) {
                    throw new DomainException(sprintf('Insufficient service wallet balance. Available: PHP %.2f, required: PHP %.2f.', $expected['expected_wallet_balance'], $amount), 422);
                }
            }

            if ($type === 'product_drawer_transfer' && $fundType === 'cash') {
                $openShift = (new CashierShiftService($this->pdo()))->getOpenShift($actorId);
                if ($openShift) {
                    $drawerType = $direction === 'in' ? 'cash_out' : 'cash_in';
                    $drawerReason = 'other';
                    $drawerNote = 'GCash funding transfer (Session #' . $sessionId . '): ' . substr($reason, 0, 180);
                    $this->pdo()->prepare(
                        'INSERT INTO cash_drawer_movements (shift_id, cashier_id, movement_type, amount, reason, note, recorded_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$openShift['shift_id'], $actorId, $drawerType, $amount, $drawerReason, $drawerNote, $actorId]);
                }
            }

            $stmt = $this->pdo()->prepare(
                'INSERT INTO gcash_funding_movements (session_id, movement_type, fund_type, amount, direction, evidence_reference, reason, authorized_by, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $sessionId,
                $type,
                $fundType,
                $amount,
                $direction,
                $evidence !== '' ? $evidence : null,
                $reason,
                $authorizedBy,
                $actorId,
            ]);
            $movementId = (int)$this->pdo()->lastInsertId();

            \log_activity(
                $this->pdo(),
                $actorId,
                'GCash funding movement recorded',
                'GCash',
                $movementId,
                null,
                [
                    'movement_id' => $movementId,
                    'session_id' => $sessionId,
                    'movement_type' => $type,
                    'fund_type' => $fundType,
                    'amount' => $amount,
                    'direction' => $direction,
                    'evidence_reference' => $evidence,
                    'reason' => $reason,
                    'authorized_by' => $authorizedBy,
                    'recorded_by' => $actorId,
                ],
                null,
                AuditRecordCategory::STORE_OPERATION
            );

            $this->pdo()->commit();
            return $movementId;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function recordLinkedCorrection(int $adminId, string $adminRole, int $transactionId, float $cashDelta, float $walletDelta, string $reason, ?int $sessionId = null): int
    {
        if (!in_array($adminRole, ['admin', 'super_admin'], true)) {
            throw new DomainException('Only an Administrator can record linked corrections.', 403);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new DomainException('Enter an adjustment reason between 5 and 255 characters.');
        }
        if (!is_finite($cashDelta) || !is_finite($walletDelta) || (round($cashDelta, 2) === 0.0 && round($walletDelta, 2) === 0.0)) {
            throw new DomainException('Enter at least one non-zero cash or wallet adjustment.');
        }
        $cashDelta = round($cashDelta, 2);
        $walletDelta = round($walletDelta, 2);

        try {
            StoreWriteGate::begin($this->pdo());
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $txStmt = $this->pdo()->prepare("SELECT * FROM gcash_transactions WHERE transaction_id = ?" . $lock);
            $txStmt->execute([$transactionId]);
            $tx = $txStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tx) {
                throw new DomainException('Transaction not found.', 404);
            }
            if ($tx['verification_status'] !== 'Completed') {
                throw new DomainException('Linked corrections may only be recorded against Completed transactions.', 422);
            }

            if ($sessionId === null) {
                $openSession = $this->pdo()->query("SELECT session_id FROM gcash_service_sessions WHERE status = 'open' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $sessionId = $openSession ? (int)$openSession['session_id'] : (int)($tx['session_id'] ?? 0);
            }
            if ($sessionId <= 0) {
                throw new DomainException('A valid session is required for linked adjustments.');
            }

            $stmt = $this->pdo()->prepare(
                'INSERT INTO gcash_adjustments (transaction_id, session_id, service_cash_delta, wallet_delta, reason, authorized_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $transactionId,
                $sessionId,
                $cashDelta,
                $walletDelta,
                $reason,
                $adminId,
            ]);
            $adjId = (int)$this->pdo()->lastInsertId();

            \log_activity(
                $this->pdo(),
                $adminId,
                'GCash linked correction recorded',
                'GCash',
                $adjId,
                null,
                [
                    'adjustment_id' => $adjId,
                    'transaction_id' => $transactionId,
                    'session_id' => $sessionId,
                    'service_cash_delta' => $cashDelta,
                    'wallet_delta' => $walletDelta,
                    'reason' => $reason,
                    'authorized_by' => $adminId,
                ],
                null,
                AuditRecordCategory::STORE_OPERATION
            );

            $this->pdo()->commit();
            return $adjId;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function resolveNeedsChecking(int $actorId, int $transactionId, string $action, array $data = []): array
    {
        $action = strtolower(trim($action));
        if (!in_array($action, ['complete', 'cancel', 'reject'], true)) {
            throw new DomainException('Action must be complete or cancel.');
        }

        try {
            StoreWriteGate::begin($this->pdo());
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $txStmt = $this->pdo()->prepare("SELECT * FROM gcash_transactions WHERE transaction_id = ?" . $lock);
            $txStmt->execute([$transactionId]);
            $tx = $txStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tx) {
                throw new DomainException('Transaction not found.', 404);
            }
            if ($tx['verification_status'] !== 'Needs checking') {
                throw new DomainException('This transaction is no longer in Needs checking status.', 409);
            }

            $type = $tx['transaction_type'];
            $principal = (int)round((float)$tx['amount']);
            $feeMethodStr = $tx['fee_method'] === 'wallet' ? 'Fee via GCash' : 'Fee in cash';
            $preview = $this->calculatePreview($type, $principal, $feeMethodStr);
            $targetFee = (float)$preview['fee'];
            $targetCashDelta = (float)$preview['net_service_cash'];
            $targetWalletDelta = (float)$preview['net_wallet'];

            $priorConfirmedWallet = (bool)$tx['confirmed_wallet'];
            $priorConfirmedCash = (bool)$tx['confirmed_cash'];

            if ($action === 'complete') {
                $refRaw = trim((string)($data['reference'] ?? $data['reference_number'] ?? ''));
                if (!$priorConfirmedWallet) {
                    if ($refRaw === '') {
                        throw new DomainException('A valid reference number is required to complete wallet transfer.');
                    }
                    $reference = $this->reference($refRaw);
                } else {
                    $reference = $tx['reference_number'] !== null ? $tx['reference_number'] : ($refRaw !== '' ? $this->reference($refRaw) : null);
                    if ($reference === null) {
                        throw new DomainException('A valid reference number is required to complete this transaction.');
                    }
                }

                if (!$priorConfirmedCash && $targetCashDelta < 0) {
                    $openSession = $this->pdo()->query("SELECT session_id FROM gcash_service_sessions WHERE status = 'open' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if ($openSession) {
                        $expected = $this->calculateSessionExpected((int)$openSession['session_id']);
                        $cashPayout = (float)$preview['cash_payout'];
                        if ($expected['expected_service_cash'] < $cashPayout) {
                            throw new DomainException(sprintf('Insufficient service cash balance. Available: PHP %.2f, required: PHP %.2f.', $expected['expected_service_cash'], $cashPayout), 422);
                        }
                    }
                }

                $existingNotes = (string)($tx['notes'] ?? '');
                $extraNote = trim((string)($data['notes'] ?? $data['note'] ?? ''));
                $resolvedNotes = $existingNotes . ($extraNote !== '' ? ' [Resolved Completed: ' . $extraNote . ']' : ' [Resolved Completed]');

                $updateStmt = $this->pdo()->prepare(
                    "UPDATE gcash_transactions
                     SET verification_status = 'Completed',
                         reference_number = ?,
                         confirmed_wallet = 1,
                         confirmed_cash = 1,
                         service_cash_delta = ?,
                         wallet_delta = ?,
                         gross_fee = ?,
                         completed_at = CURRENT_TIMESTAMP,
                         notes = ?
                     WHERE transaction_id = ? AND verification_status = 'Needs checking'"
                );
                $updateStmt->execute([
                    $reference,
                    $targetCashDelta,
                    $targetWalletDelta,
                    $targetFee,
                    $resolvedNotes,
                    $transactionId,
                ]);

                \log_activity(
                    $this->pdo(),
                    $actorId,
                    'GCash Needs checking resolved completed',
                    'GCash',
                    $transactionId,
                    ['verification_status' => 'Needs checking', 'confirmed_cash' => $tx['confirmed_cash'], 'confirmed_wallet' => $tx['confirmed_wallet']],
                    ['verification_status' => 'Completed', 'reference_number' => $reference, 'service_cash_delta' => $targetCashDelta, 'wallet_delta' => $targetWalletDelta, 'gross_fee' => $targetFee],
                    null,
                    AuditRecordCategory::STORE_OPERATION
                );

                $this->pdo()->commit();
                return [
                    'transaction_id' => $transactionId,
                    'status' => 'Completed',
                    'verification_status' => 'Completed',
                ];
            } else {
                $reason = trim((string)($data['reason'] ?? $data['notes'] ?? $data['note'] ?? ''));
                if (mb_strlen($reason) < 5) {
                    throw new DomainException('Enter a cancellation reason of at least 5 characters.');
                }

                $hadCash = (float)$tx['service_cash_delta'] != 0.0;
                $hadWallet = (float)$tx['wallet_delta'] != 0.0;

                if ($hadCash || $hadWallet) {
                    $userStmt = $this->pdo()->prepare('SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?');
                    $userStmt->execute([$actorId]);
                    $roleName = (string)$userStmt->fetchColumn();
                    if (in_array($roleName, ['admin', 'super_admin'], true)) {
                        $authorizedBy = $actorId;
                    } else {
                        $authorizedBy = $this->authorizeAdmin((string)($data['approver_username'] ?? ''), (string)($data['approver_password'] ?? ''));
                    }

                    $offsetCash = - (float)$tx['service_cash_delta'];
                    $offsetWallet = - (float)$tx['wallet_delta'];
                    $sessionId = (int)($tx['session_id'] ?? 0);
                    if ($sessionId <= 0) {
                        $openSession = $this->pdo()->query("SELECT session_id FROM gcash_service_sessions WHERE status = 'open' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                        $sessionId = $openSession ? (int)$openSession['session_id'] : 0;
                    }

                    $adjStmt = $this->pdo()->prepare(
                        'INSERT INTO gcash_adjustments (transaction_id, session_id, service_cash_delta, wallet_delta, reason, authorized_by)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $adjStmt->execute([
                        $transactionId,
                        $sessionId,
                        $offsetCash,
                        $offsetWallet,
                        'Cancellation offset for #' . $transactionId . ': ' . $reason,
                        $authorizedBy,
                    ]);
                }

                $existingNotes = (string)($tx['notes'] ?? '');
                $resolvedNotes = $existingNotes . ' [Cancelled: ' . $reason . ']';

                $updateStmt = $this->pdo()->prepare(
                    "UPDATE gcash_transactions
                     SET verification_status = 'Rejected',
                         notes = ?
                     WHERE transaction_id = ? AND verification_status = 'Needs checking'"
                );
                $updateStmt->execute([$resolvedNotes, $transactionId]);

                \log_activity(
                    $this->pdo(),
                    $actorId,
                    'GCash Needs checking cancelled',
                    'GCash',
                    $transactionId,
                    ['verification_status' => 'Needs checking'],
                    ['verification_status' => 'Rejected', 'cancellation_reason' => $reason],
                    null,
                    AuditRecordCategory::STORE_OPERATION
                );

                $this->pdo()->commit();
                return [
                    'transaction_id' => $transactionId,
                    'status' => 'Rejected',
                    'verification_status' => 'Rejected',
                ];
            }
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function waiveDiscrepancy(int $adminId, string $adminRole, int $sessionId, string $waiverNotes): void
    {
        if (!in_array($adminRole, ['admin', 'super_admin'], true)) {
            throw new DomainException('Only an Administrator can waive closing discrepancies.', 403);
        }
        $waiverNotes = trim($waiverNotes);
        if (mb_strlen($waiverNotes) < 5 || mb_strlen($waiverNotes) > 500) {
            throw new DomainException('Enter discrepancy waiver notes between 5 and 500 characters.');
        }

        try {
            StoreWriteGate::begin($this->pdo());
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE session_id = ?" . $lock);
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('GCash service session not found.', 404);
            }
            if ($session['status'] !== 'closed') {
                throw new DomainException('Only closed sessions with discrepancies can be waived.', 422);
            }
            if ($session['waived_at'] !== null) {
                throw new DomainException('This session discrepancy has already been approved/waived.', 409);
            }

            $update = $this->pdo()->prepare(
                'UPDATE gcash_service_sessions
                 SET waiver_notes = ?, waived_by = ?, waived_at = CURRENT_TIMESTAMP
                 WHERE session_id = ?'
            );
            $update->execute([$waiverNotes, $adminId, $sessionId]);

            \log_activity(
                $this->pdo(),
                $adminId,
                'GCash discrepancy waived',
                'GCash',
                $sessionId,
                null,
                [
                    'session_id' => $sessionId,
                    'waiver_notes' => $waiverNotes,
                    'waived_by' => $adminId,
                    'cash_variance' => $session['cash_variance'],
                    'wallet_variance' => $session['wallet_variance'],
                ],
                null,
                AuditRecordCategory::STORE_OPERATION
            );

            $this->pdo()->commit();
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}

