<?php

declare(strict_types=1);

namespace App\GCash;

use App\Audit\AuditRecordCategory;
use App\Services\PhilippineTime;
use App\Store\StoreWriteGate;
use DomainException;
use PDO;
use PDOException;
use Throwable;

require_once __DIR__ . '/../Services/FiscalPeriodGuardService.php';

/** GCash exchange workflow module. Recording, funding, money history, legacy paths. */
final class GCashExchangeWorkflow
{
    private ?\FiscalPeriodGuardService $fiscalPeriodGuard = null;
    private ?GCashExchangeReview $review = null;

    public function __construct(
        private PDO $pdo,
        private array $rules = [],
        private ?GCashLiveCustodySession $custody = null,
        ?GCashExchangeReview $review = null
    ) {
        $this->fiscalPeriodGuard = new \FiscalPeriodGuardService($pdo);
        $this->custody ??= new GCashLiveCustodySession($pdo);
        $this->review = $review;
    }

    private function fiscalPeriodGuard(): \FiscalPeriodGuardService
    {
        if (!$this->fiscalPeriodGuard) {
            $this->fiscalPeriodGuard = new \FiscalPeriodGuardService($this->pdo);
        }
        return $this->fiscalPeriodGuard;
    }

    public function exists(string $reference): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM gcash_transactions WHERE reference_number = ?');
        $stmt->execute([GCashReference::normalize($reference, (int)($this->rules['min_length'] ?? 13), (int)($this->rules['max_length'] ?? 13))]);
        return (bool)$stmt->fetchColumn();
    }

    public function save(int $cashierId, string $reference, string $amount, string $type): int
    {
        $reference = GCashReference::normalize($reference, (int)($this->rules['min_length'] ?? 13), (int)($this->rules['max_length'] ?? 13));
        if (!in_array($type, ['Cash-In', 'Cash-Out', 'Payment'], true)) {
            throw new DomainException('Select Cash-In, Cash-Out, or Payment.');
        }
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $amount) || (float)$amount <= 0) {
            throw new DomainException('Enter an amount from 0.01 to 99,999,999.99 with at most two decimal places.');
        }
        try {
            StoreWriteGate::begin($this->pdo);
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_transactions', 'GCash transaction');
            (new \App\Services\CashierShiftService($this->pdo))->requireUnlockedRegister($cashierId);

            $session = $this->custody->getCurrentSession();
            $sessionId = null;
            if ($session) {
                if ((int)$session['cashier_id'] !== $cashierId) {
                    throw new DomainException('Another Cashier currently has responsibility for GCash services. Only one Cashier may control GCash at a time.', 409);
                }
                $sessionId = (int)$session['session_id'];
            }

            $stmt = $this->pdo->prepare("INSERT INTO gcash_transactions (session_id, transaction_type, reference_number, amount, cashier_id, responsible_user_id, responsible_role, responsibility_assigned_at, verification_status) VALUES (?, ?, ?, ?, ?, ?, 'cashier', CURRENT_TIMESTAMP, 'Pending')");
            $stmt->execute([$sessionId, $type, $reference, $amount, $cashierId, $cashierId]);
            $id = (int)$this->pdo->lastInsertId();
            \log_activity($this->pdo, $cashierId, 'GCash transaction recorded', 'GCash', $id, null,
                ['session_id' => $sessionId, 'reference_number' => $reference, 'amount' => $amount, 'transaction_type' => $type, 'verification_status' => 'Pending'],
                null, AuditRecordCategory::STORE_OPERATION);
            $this->pdo->commit();
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
            StoreWriteGate::begin($this->pdo);
            (new \App\Services\CashierShiftService($this->pdo))->requireUnlockedRegister($cashierId);
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare('SELECT * FROM gcash_transactions WHERE transaction_id = ? AND cashier_id = ?' . $lock);
            $stmt->execute([$id, $cashierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new DomainException('This transaction is unavailable.', 404); }
            if ($row['verification_status'] !== 'Pending') {
                throw new DomainException('This transaction has already been reviewed. Refresh history.', 409);
            }
            $stmt = $this->pdo->prepare("UPDATE gcash_transactions SET verification_status = ?, verified_by = ?, verification_note = ?, reviewed_at = CURRENT_TIMESTAMP WHERE transaction_id = ? AND verification_status = 'Pending'");
            $stmt->execute([$status, $cashierId, $note, $id]);
            \log_activity($this->pdo, $cashierId, 'GCash transaction ' . strtolower($status), 'GCash', $id,
                ['verification_status' => 'Pending'], ['verification_status' => $status, 'verification_note' => $note],
                null, AuditRecordCategory::STORE_OPERATION);
            $this->pdo->commit();
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

        $principal = GCashPricing::validatePrincipal($data['principal'] ?? $data['amount'] ?? null);
        $preview = GCashPricing::calculatePreview('Cash-In', $principal);
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
            $reference = GCashReference::normalize($refRaw, (int)($this->rules['min_length'] ?? 13), (int)($this->rules['max_length'] ?? 13));
            $serviceCashDelta = $counterCollect;
            $walletDelta = -$walletTransfer;
            $grossFee = $authoritativeFee;
            $completedAt = date('Y-m-d H:i:s');
        } else {
            if ($notes === null || mb_strlen($notes) < 5) {
                throw new DomainException('Enter an explanatory note of at least 5 characters for transactions held as Needs checking.');
            }
            $refRaw = trim((string)($data['reference'] ?? $data['reference_number'] ?? ''));
            $reference = $refRaw !== '' ? GCashReference::normalize($refRaw, (int)($this->rules['min_length'] ?? 13), (int)($this->rules['max_length'] ?? 13)) : null;
            $serviceCashDelta = $confirmedCash ? $counterCollect : 0.00;
            $walletDelta = $confirmedWallet ? -$walletTransfer : 0.00;
            $grossFee = ($confirmedCash && $confirmedWallet) ? $authoritativeFee : 0.00;
            $completedAt = null;
        }

        try {
            StoreWriteGate::begin($this->pdo);
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_transactions', 'GCash Cash-In');
            $shiftService = new \App\Services\CashierShiftService($this->pdo);
            $shiftService->requireUnlockedRegister($cashierId);
            $shift = $shiftService->getOpenShift($cashierId);
            if (!$shift) {
                throw new DomainException('Open your Cashier Shift before recording GCash transactions.');
            }
            $shiftId = (int)$shift['shift_id'];

            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessionStmt = $this->pdo->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
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
                $expected = $this->custody->calculateSessionExpected($sessionId);
                $currentWallet = (float)$expected['expected_wallet_balance'];
                if ($currentWallet < abs($walletDelta)) {
                    throw new DomainException(sprintf(
                        'Insufficient Store GCash wallet balance. Available: PHP %.2f, required: PHP %.2f.',
                        $currentWallet,
                        abs($walletDelta)
                    ));
                }
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO gcash_transactions (
                    session_id, shift_id, transaction_type, reference_number, amount,
                    principal_amount, fee_amount, fee_method, service_cash_delta, wallet_delta,
                    gross_fee, cashier_id, responsible_user_id, responsible_role, responsibility_assigned_at, confirmed_wallet, confirmed_cash, notes,
                    verification_status, completed_at
                ) VALUES (
                    ?, ?, 'Cash-In', ?, ?,
                    ?, ?, 'cash', ?, ?,
                    ?, ?, ?, 'cashier', CURRENT_TIMESTAMP, ?, ?, ?,
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
                $cashierId,
                $confirmedWallet ? 1 : 0,
                $confirmedCash ? 1 : 0,
                $notes,
                $outcome,
                $completedAt,
            ]);
            $id = (int)$this->pdo->lastInsertId();

            \log_activity(
                $this->pdo,
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

            if ($outcome === 'Completed') {
                (new \App\Receipts\ReceiptEngine($this->pdo))->preserveGCash($id);
            }
            $this->pdo->commit();
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

        $principal = GCashPricing::validatePrincipal($data['principal'] ?? $data['amount'] ?? null);
        $feeMethodNorm = GCashPricing::normalizeCashOutFeeMethod($data['fee_method'] ?? null);
        $dbFeeMethod = $feeMethodNorm === 'Fee via GCash' ? 'wallet' : 'cash';

        $preview = GCashPricing::calculatePreview('Cash-Out', $principal, $feeMethodNorm);
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
            $reference = GCashReference::normalize($refRaw, (int)($this->rules['min_length'] ?? 13), (int)($this->rules['max_length'] ?? 13));
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
            $reference = $refRaw !== '' ? GCashReference::normalize($refRaw, (int)($this->rules['min_length'] ?? 13), (int)($this->rules['max_length'] ?? 13)) : null;
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
            StoreWriteGate::begin($this->pdo);
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_transactions', 'GCash Cash-Out');
            $shiftService = new \App\Services\CashierShiftService($this->pdo);
            $shiftService->requireUnlockedRegister($cashierId);
            $shift = $shiftService->getOpenShift($cashierId);
            if (!$shift) {
                throw new DomainException('Open your Cashier Shift before recording GCash transactions.');
            }
            $shiftId = (int)$shift['shift_id'];

            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessionStmt = $this->pdo->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
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
                $expected = $this->custody->calculateSessionExpected($sessionId);
                $currentCash = (float)$expected['expected_service_cash'];
                if ($currentCash < $cashPayout) {
                    throw new DomainException(sprintf(
                        'Insufficient service cash balance. Available: PHP %.2f, required: PHP %.2f.',
                        $currentCash,
                        $cashPayout
                    ), 422);
                }
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO gcash_transactions (
                    session_id, shift_id, transaction_type, reference_number, amount,
                    principal_amount, fee_amount, fee_method, service_cash_delta, wallet_delta,
                    gross_fee, cashier_id, responsible_user_id, responsible_role, responsibility_assigned_at, confirmed_wallet, confirmed_cash, notes,
                    verification_status, completed_at
                ) VALUES (
                    ?, ?, 'Cash-Out', ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, 'cashier', CURRENT_TIMESTAMP, ?, ?, ?,
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
                $cashierId,
                $confirmedWallet ? 1 : 0,
                $confirmedCash ? 1 : 0,
                $notes,
                $outcome,
                $completedAt,
            ]);
            $id = (int)$this->pdo->lastInsertId();

            \log_activity(
                $this->pdo,
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

            if ($outcome === 'Completed') {
                (new \App\Receipts\ReceiptEngine($this->pdo))->preserveGCash($id);
            }
            $this->pdo->commit();
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
        return $this->reviewModule()->authorizeAdmin($username, $password);
    }

    private function reviewModule(): GCashExchangeReview
    {
        return $this->review ??= new GCashExchangeReview($this->pdo, $this->rules, $this->custody);
    }

    public function previewProductDrawerFunding(int $cashierId, float $amount): array
    {
        $openShift = (new \App\Services\CashierShiftService($this->pdo))->getOpenShift($cashierId);
        if (!$openShift) {
            throw new DomainException('No open Cashier Shift or Product drawer found for this Cashier.', 422);
        }
        $regName = 'Register #' . ($openShift['register_id'] ?? 1);
        try {
            $regStmt = $this->pdo->prepare("SELECT name FROM registers WHERE register_id = ?");
            $regStmt->execute([$openShift['register_id']]);
            $rn = $regStmt->fetchColumn();
            if ($rn) { $regName = (string)$rn; }
        } catch (Throwable) {}

        return [
            'cashier_id' => $cashierId,
            'shift_id' => (int)$openShift['shift_id'],
            'register_id' => (int)$openShift['register_id'],
            'register_name' => $regName,
            'amount' => round($amount, 2),
        ];
    }

    public function recordShortageRecovery(int $cashierId, array $data): int
    {
        $targetSessionId = filter_var($data['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$targetSessionId) {
            throw new DomainException('A valid target session ID is required to link shortage recovery.', 422);
        }
        $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($amount === false || !is_finite($amount) || $amount <= 0 || round($amount, 2) !== $amount) {
            throw new DomainException('Enter a positive shortage recovery amount.', 422);
        }
        $amount = round($amount, 2);

        $authBy = $this->authorizeAdmin((string)($data['approver_username'] ?? ''), (string)($data['approver_password'] ?? ''));

        if (empty($data['receipt_confirmed'])) {
            throw new DomainException('The responsible Cashier must confirm receipt of funds before posting.', 422);
        }

        StoreWriteGate::begin($this->pdo);
        try {
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_funding_movements', 'GCash shortage recovery');

            // Find target session
            $tStmt = $this->pdo->prepare("SELECT * FROM gcash_service_sessions WHERE session_id = ?");
            $tStmt->execute([$targetSessionId]);
            $target = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                throw new DomainException('Target session for shortage recovery not found.', 404);
            }
            if ($target['status'] !== 'closed') {
                throw new DomainException('Shortage recovery can only be applied to a closed session.', 422);
            }

            // Target session review check
            $reviewShortage = (float)($target['review_shortage'] ?? (max(0.0, -(float)$target['cash_variance']) + max(0.0, -(float)$target['wallet_variance'])));
            $recoveryStmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM gcash_funding_movements WHERE movement_type='shortage_recovery' AND linked_session_id=? AND direction='in' AND receipt_confirmed=1");
            $recoveryStmt->execute([$targetSessionId]);
            $recovered = (float)$recoveryStmt->fetchColumn();
            $remaining = max(0.0, round($reviewShortage - $recovered, 2));
            if ($amount > $remaining) {
                throw new DomainException(sprintf('Recovery amount PHP %.2f exceeds remaining unrecovered shortage PHP %.2f.', $amount, $remaining), 422);
            }

            // Current open session for cashier
            $openStmt = $this->pdo->prepare("SELECT session_id FROM gcash_service_sessions WHERE cashier_id = ? AND status = 'open' ORDER BY session_id DESC LIMIT 1");
            $openStmt->execute([$cashierId]);
            $currentSessionId = $openStmt->fetchColumn();
            if (!$currentSessionId) {
                throw new DomainException('Responsible Cashier must have an eligible open GCash session in an open Fiscal Period.', 422);
            }
            $currentSessionId = (int)$currentSessionId;

            // Record funding movement
            $evidence = trim((string)($data['evidence_reference'] ?? $data['evidence'] ?? ''));
            $reason = trim((string)($data['reason'] ?? ''));

            if ($evidence === '' || mb_strlen($reason) < 5 || !in_array($data['fund_type'] ?? 'cash', ['cash', 'wallet'], true)) {
                throw new DomainException('Provide actual receipt evidence, a reason, and cash or wallet for the recovery.');
            }

            $hasExtraCols = false;
            try {
                $hasExtraCols = in_array('linked_session_id', GCashSchema::columns($this->pdo, 'gcash_funding_movements'), true);
            } catch (Throwable) {}

            if ($hasExtraCols) {
                $ins = $this->pdo->prepare(
                    "INSERT INTO gcash_funding_movements (session_id, movement_type, fund_type, amount, direction, evidence_reference, reason, authorized_by, recorded_by, received_by, receipt_confirmed, linked_session_id)
                     VALUES (?, 'shortage_recovery', ?, ?, 'in', ?, ?, ?, ?, ?, 1, ?)"
                );
                $ins->execute([$currentSessionId, (string)($data['fund_type'] ?? 'cash'), $amount, $evidence, $reason, $authBy, $cashierId, $cashierId, $targetSessionId]);
            } else {
                $ins = $this->pdo->prepare(
                    "INSERT INTO gcash_funding_movements (session_id, movement_type, fund_type, amount, direction, evidence_reference, reason, authorized_by, recorded_by)
                     VALUES (?, 'shortage_recovery', ?, ?, 'in', ?, ?, ?, ?)"
                );
                $ins->execute([$currentSessionId, (string)($data['fund_type'] ?? 'cash'), $amount, $evidence, $reason, $authBy, $cashierId]);
            }
            $movementId = (int)$this->pdo->lastInsertId();

            // Update target session recovered_shortage without modifying cash_variance or review_shortage
            $newRecovered = round($recovered + $amount, 2);
            $hasRecoveredCol = false;
            try {
                $hasRecoveredCol = in_array('recovered_shortage', GCashSchema::columns($this->pdo, 'gcash_service_sessions'), true);
            } catch (Throwable) {}
            if ($hasRecoveredCol) {
                $upd = $this->pdo->prepare("UPDATE gcash_service_sessions SET recovered_shortage = ? WHERE session_id = ?");
                $upd->execute([$newRecovered, $targetSessionId]);
            }

            \log_activity(
                $this->pdo,
                $cashierId,
                'GCash shortage recovery recorded',
                'GCash',
                $movementId,
                null,
                [
                    'movement_id' => $movementId,
                    'current_session_id' => $currentSessionId,
                    'target_session_id' => $targetSessionId,
                    'recovered_amount' => $amount,
                    'authorized_by' => $authBy,
                ],
                null,
                \App\Audit\AuditRecordCategory::STORE_OPERATION
            );

            $this->pdo->commit();
            return $movementId;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }


    private function adjustmentColumns(): array
    {
        return GCashSchema::columns($this->pdo, 'gcash_adjustments');
    }

    public function getMoneyHistory(array $filters = []): array
    {
        $sessionId = filter_var($filters['session_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $items = [];

        // 1. Funding movements
        $sql = "SELECT fm.*, u.display_name AS recorder_name, au.display_name AS authorizer_name
                FROM gcash_funding_movements fm
                LEFT JOIN users u ON fm.recorded_by = u.user_id
                LEFT JOIN users au ON fm.authorized_by = au.user_id";
        $params = [];
        if ($sessionId) {
            $sql .= " WHERE fm.session_id = ?";
            $params[] = $sessionId;
        }
        $sql .= " ORDER BY fm.movement_id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'id' => (int)$r['movement_id'],
                'kind' => 'funding_movement',
                'movement_type' => $r['movement_type'],
                'fund_type' => $r['fund_type'],
                'amount' => (float)$r['amount'],
                'direction' => $r['direction'],
                'session_id' => (int)$r['session_id'],
                'evidence' => $r['evidence_reference'],
                'reason' => $r['reason'],
                'recorder_name' => $r['recorder_name'],
                'authorizer_name' => $r['authorizer_name'],
                'is_actual_movement' => true,
                'linked_session_id' => isset($r['linked_session_id']) && $r['linked_session_id'] ? (int)$r['linked_session_id'] : null,
                'linked_transaction_id' => isset($r['linked_transaction_id']) && $r['linked_transaction_id'] ? (int)$r['linked_transaction_id'] : null,
                'created_at' => $r['created_at'],
            ];
        }

        // 2. Adjustments / Corrections
        $hasRecorder = in_array('recorded_by', $this->adjustmentColumns(), true);
        $adjSql = "SELECT a.*, au.display_name AS authorizer_name, " . ($hasRecorder ? 'rec.display_name' : 'NULL') . " AS recorder_name FROM gcash_adjustments a LEFT JOIN users au ON a.authorized_by = au.user_id";
        if ($hasRecorder) $adjSql .= ' LEFT JOIN users rec ON rec.user_id=a.recorded_by';
        $adjParams = [];
        if ($sessionId) {
            $adjSql .= " WHERE a.session_id = ?";
            $adjParams[] = $sessionId;
        }
        $adjSql .= " ORDER BY a.adjustment_id DESC";
        $adjStmt = $this->pdo->prepare($adjSql);
        $adjStmt->execute($adjParams);
        foreach ($adjStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $isMovement = ($a['correction_mode'] ?? 'money_movement') === 'money_movement';
            $items[] = [
                'id' => (int)$a['adjustment_id'],
                'kind' => 'adjustment',
                'movement_type' => $a['correction_mode'] ?? 'money_movement',
                'service_cash_delta' => (float)$a['service_cash_delta'],
                'wallet_delta' => (float)$a['wallet_delta'],
                'amount' => abs((float)$a['service_cash_delta']) + abs((float)$a['wallet_delta']),
                'session_id' => (int)$a['session_id'],
                'reason' => $a['reason'],
                'authorizer_name' => $a['authorizer_name'],
                'is_actual_movement' => $isMovement,
                'linked_transaction_id' => (int)$a['transaction_id'],
                'recorder_name' => $a['recorder_name'],
                'evidence' => $a['evidence'] ?? null,
                'created_at' => $a['created_at'],
            ];
        }

        $sql = "SELECT t.*, u.display_name AS recorder_name, v.display_name AS authorizer_name FROM gcash_transactions t LEFT JOIN users u ON u.user_id=t.cashier_id LEFT JOIN users v ON v.user_id=t.verified_by";
        if ($sessionId) $sql .= ' WHERE t.session_id = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($sessionId ? [$sessionId] : []);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
            $items[] = [
                'id' => (int)$tx['transaction_id'], 'kind' => 'exchange',
                'movement_type' => $tx['transaction_type'] . ' · ' . $tx['verification_status'],
                'service_cash_delta' => (float)$tx['service_cash_delta'], 'wallet_delta' => (float)$tx['wallet_delta'],
                'amount' => (float)$tx['amount'], 'session_id' => (int)$tx['session_id'],
                'reason' => $tx['notes'] ?? '', 'evidence' => $tx['reference_number'],
                'recorder_name' => $tx['recorder_name'], 'authorizer_name' => $tx['authorizer_name'],
                'is_actual_movement' => true, 'linked_transaction_id' => (int)$tx['transaction_id'],
                'created_at' => $tx['verification_status'] === 'Completed' ? ($tx['completed_at'] ?? $tx['created_at']) : $tx['created_at'],
            ];
        }
        usort($items, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']) ?: $b['id'] <=> $a['id']);
        return $items;
    }

    public function recordFundingMovement(int $actorId, string $actorRole, array $data): int
    {
        $type = (string)($data['movement_type'] ?? '');
        if (!in_array($type, ['replenishment', 'withdrawal', 'product_drawer_transfer', 'owner_capital', 'shortage_recovery'], true)) {
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
            StoreWriteGate::begin($this->pdo);
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_funding_movements', 'GCash funding movement');
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessionStmt = $this->pdo->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
            $sessionStmt->execute();
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('Open a GCash service session before recording funding movements.');
            }
            $sessionId = (int)$session['session_id'];

            if (function_exists('is_remote_request') && is_remote_request()) {
                throw new DomainException('Funding movements are not permitted during remote monitoring.', 403);
            }
            $isAdmin = $actorRole === 'admin';
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

            $receiptConfirmed = !empty($data['receipt_confirmed']);
            if (!$receiptConfirmed) {
                throw new DomainException('The responsible Cashier must confirm receipt of funds before posting.', 422);
            }

            if ($direction === 'out') {
                $expected = $this->custody->calculateSessionExpected($sessionId);
                if ($fundType === 'cash' && $expected['expected_service_cash'] < $amount) {
                    throw new DomainException(sprintf('Insufficient service cash balance. Available: PHP %.2f, required: PHP %.2f.', $expected['expected_service_cash'], $amount), 422);
                }
                if ($fundType === 'wallet' && $expected['expected_wallet_balance'] < $amount) {
                    throw new DomainException(sprintf('Insufficient service wallet balance. Available: PHP %.2f, required: PHP %.2f.', $expected['expected_wallet_balance'], $amount), 422);
                }
            }

            if ($type === 'product_drawer_transfer' && $fundType === 'cash') {
                $openShift = (new \App\Services\CashierShiftService($this->pdo))->getOpenShift($actorId);
                if (!$openShift) {
                    throw new DomainException('Cannot transfer from product drawer: Cashier has no open drawer / shift.', 422);
                }
                $drawerType = $direction === 'in' ? 'cash_out' : 'cash_in';
                $drawerReason = 'other';
                $drawerNote = 'GCash funding transfer (Session #' . $sessionId . '): ' . substr($reason, 0, 180);
                $this->pdo->prepare(
                    'INSERT INTO cash_drawer_movements (shift_id, cashier_id, movement_type, amount, reason, note, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$openShift['shift_id'], $actorId, $drawerType, $amount, $drawerReason, $drawerNote, $actorId]);
            }

            $hasReceiptCol = false;
            try {
                $hasReceiptCol = in_array('receipt_confirmed', GCashSchema::columns($this->pdo, 'gcash_funding_movements'), true);
            } catch (Throwable) {}

            if ($hasReceiptCol) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO gcash_funding_movements (session_id, movement_type, fund_type, amount, direction, evidence_reference, reason, authorized_by, recorded_by, received_by, receipt_confirmed)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
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
                    $actorId,
                ]);
            } else {
                $stmt = $this->pdo->prepare(
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
            }
            $movementId = (int)$this->pdo->lastInsertId();

            \log_activity(
                $this->pdo,
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

            $this->pdo->commit();
            return $movementId;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
