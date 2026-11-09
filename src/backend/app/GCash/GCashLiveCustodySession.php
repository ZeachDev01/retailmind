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

/**
 * GCash Live Custody/session module. Current session, expected balances, lifecycle,
 * handover/stop-service (ADR-0025: live custody, not Activity Scope).
 */
final class GCashLiveCustodySession
{
    private ?\FiscalPeriodGuardService $fiscalPeriodGuard = null;

    public function __construct(private PDO $pdo)
    {
        $this->fiscalPeriodGuard = new \FiscalPeriodGuardService($pdo);
    }

    private function fiscalPeriodGuard(): \FiscalPeriodGuardService
    {
        if (!$this->fiscalPeriodGuard) {
            $this->fiscalPeriodGuard = new \FiscalPeriodGuardService($this->pdo);
        }
        return $this->fiscalPeriodGuard;
    }

    public function getCurrentSession(): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.*, u.display_name AS cashier_name, u.username AS cashier_username
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

    public function getSession(int $sessionId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM gcash_service_sessions WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new DomainException('GCash service session not found.', 404);
        }
        return $session;
    }

    public function lastClosedSession(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM gcash_service_sessions WHERE status = 'closed' ORDER BY session_id DESC LIMIT 1");
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        return $session ?: null;
    }

    public function calculateSessionExpected(int $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gcash_service_sessions WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new DomainException('GCash service session not found.', 404);
        }
        $openingCash = (float)$session['opening_service_cash'];
        $openingWallet = (float)$session['opening_wallet_balance'];
        $expectedCash = $openingCash;
        $expectedWallet = $openingWallet;

        $txStmt = $this->pdo->prepare(
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
                    $preview = GCashPricing::calculatePreview($type, $principal);
                    $expectedCash += (float)$preview['net_service_cash'];
                    $expectedWallet += (float)$preview['net_wallet'];
                }
            }
        }

        $fundStmt = $this->pdo->prepare(
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

        $adjStmt = $this->pdo->prepare(
            "SELECT service_cash_delta, wallet_delta FROM gcash_adjustments WHERE session_id = ? AND (correction_mode = 'money_movement' OR correction_mode IS NULL)"
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
            StoreWriteGate::begin($this->pdo);
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_service_sessions', 'GCash session opening');
            $shiftService = new \App\Services\CashierShiftService($this->pdo);
            $shiftService->requireUnlockedRegister($cashierId);
            $shift = $shiftService->getOpenShift($cashierId);
            if (!$shift) {
                throw new DomainException('Open your Cashier Shift before opening a GCash service session.');
            }
            $shiftId = (int)$shift['shift_id'];

            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $existing = $this->pdo->query("SELECT session_id, cashier_id FROM gcash_service_sessions WHERE status = 'open'" . $lock)->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                throw new DomainException('Another Cashier currently has responsibility for GCash services. Only one Cashier may control GCash at a time.', 409);
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO gcash_service_sessions (shift_id, cashier_id, opening_service_cash, opening_wallet_balance, status)
                 VALUES (?, ?, ?, ?, 'open')"
            );
            $stmt->execute([$shiftId, $cashierId, $openingServiceCash, $openingWalletBalance]);
            $sessionId = (int)$this->pdo->lastInsertId();

            \log_activity($this->pdo, $cashierId, 'GCash session opened', 'GCash', $sessionId, null,
                ['shift_id' => $shiftId, 'opening_service_cash' => $openingServiceCash, 'opening_wallet_balance' => $openingWalletBalance],
                null, AuditRecordCategory::STORE_OPERATION);

            $this->pdo->commit();
            return $sessionId;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('Another Cashier currently has responsibility for GCash services. Only one Cashier may control GCash at a time.', 409, $e);
            }
            throw $e;
        }
    }

    public function closeSession(int $cashierId, float $actualServiceCash, float $actualWalletBalance, string $notes, int $unresolvedCarryover = 0, ?int $responsibleUserId = null, ?string $transferReason = null): array
    {
        if (!is_finite($actualServiceCash) || $actualServiceCash < 0 || !is_finite($actualWalletBalance) || $actualWalletBalance < 0) {
            throw new DomainException('Closing balances must be 0 or greater.');
        }
        try {
            StoreWriteGate::begin($this->pdo);
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_service_sessions', 'GCash session closing');
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
            $stmt->execute();
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('No open GCash service session found.', 404);
            }

            $isOwner = (int)$session['cashier_id'] === $cashierId;
            if (!$isOwner) {
                $userStmt = $this->pdo->prepare('SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?');
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

            $assignedResponsibleId = null;
            $assignedResponsibleRole = null;
            if ($cashVariance != 0.0 || $walletVariance != 0.0) {
                $targetUserId = $responsibleUserId ?? $cashierId;
                try {
                    $assignedResponsibleRole = $this->resolveEligibleRole($targetUserId);
                    $assignedResponsibleId = $targetUserId;
                } catch (Throwable) {
                    $assignedResponsibleId = null;
                    $assignedResponsibleRole = null;
                }
            }

            $update = $this->pdo->prepare(
                "UPDATE gcash_service_sessions
                 SET status = 'closed', closed_at = CURRENT_TIMESTAMP, closed_by = ?,
                     expected_service_cash = ?, expected_wallet_balance = ?,
                     closing_service_cash = ?, closing_wallet_balance = ?,
                     cash_variance = ?, wallet_variance = ?, closing_notes = ?,
                     unresolved_carryover_count = ?,
                     responsible_user_id = ?, responsible_role = ?,
                     responsibility_notes = ?, responsibility_assigned_at = " . ($assignedResponsibleId !== null ? "CURRENT_TIMESTAMP" : "NULL") . "
                 WHERE session_id = ? AND status = 'open'"
            );
            $update->execute([
                $cashierId,
                $expectedCash, $expectedWallet,
                $actualServiceCash, $actualWalletBalance,
                $cashVariance, $walletVariance,
                $notes !== '' ? $notes : null,
                max(0, $unresolvedCarryover),
                $assignedResponsibleId,
                $assignedResponsibleRole,
                $transferReason,
                $sessionId,
            ]);

            \log_activity($this->pdo, $cashierId, 'GCash session closed', 'GCash', $sessionId,
                ['opening_service_cash' => $session['opening_service_cash'], 'opening_wallet_balance' => $session['opening_wallet_balance']],
                ['expected_service_cash' => $expectedCash, 'expected_wallet_balance' => $expectedWallet, 'closing_service_cash' => $actualServiceCash, 'closing_wallet_balance' => $actualWalletBalance, 'cash_variance' => $cashVariance, 'wallet_variance' => $walletVariance, 'unresolved_carryover_count' => $unresolvedCarryover, 'closing_notes' => $notes],
                null, AuditRecordCategory::STORE_OPERATION);

            $this->pdo->commit();
            \App\Dashboard\StoreOperationsDashboardWorkspace::invalidateCache();

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

    public function eligibleHandoverRecipients(): array
    {
        return $this->pdo->query(
            "SELECT DISTINCT u.user_id, u.display_name, u.username, r.role_name
             FROM users u
             JOIN roles r ON r.role_id = u.role_id
             JOIN cashier_shifts cs ON cs.cashier_id = u.user_id
             WHERE u.status = 'active' AND u.must_change_password = 0
               AND r.role_name IN ('admin', 'cashier', 'inventory_manager')
               AND cs.status = 'open' AND cs.locked_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM user_roles ur JOIN roles assigned ON assigned.role_id = ur.role_id
                   WHERE ur.user_id = u.user_id AND assigned.role_name = 'super_admin'
               )
             ORDER BY u.display_name, u.user_id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function handoverSession(int $outgoingCashierId, int $incomingCashierId, float $countedCash, float $checkedWallet, string $notes, ?array $acceptedTransactionIds = null, ?int $differenceResponsibleUserId = null): array
    {
        if ($outgoingCashierId === $incomingCashierId) {
            throw new DomainException('Select a different Cashier for handover.');
        }
        if (!is_finite($countedCash) || $countedCash < 0 || !is_finite($checkedWallet) || $checkedWallet < 0) {
            throw new DomainException('Handover balances must be 0 or greater.');
        }
        try {
            StoreWriteGate::begin($this->pdo);
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_service_sessions', 'GCash session handover');
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessionStmt = $this->pdo->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
            $sessionStmt->execute();
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('No open GCash service session found to hand over.', 404);
            }
            $userStmt = $this->pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id
                WHERE u.user_id = ? AND u.status = 'active' AND r.role_name <> 'super_admin'
                  AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles assigned ON assigned.role_id = ur.role_id
                                  WHERE ur.user_id = u.user_id AND assigned.role_name = 'super_admin')");
            $userStmt->execute([$outgoingCashierId]);
            $roleName = $userStmt->fetchColumn();
            if (!$roleName || ((int)$session['cashier_id'] !== $outgoingCashierId && $roleName !== 'admin')) {
                throw new DomainException('Only the responsible Cashier or an Administrator can hand over GCash responsibility.', 403);
            }

            $result = $this->performHandover($session, $outgoingCashierId, $incomingCashierId, $countedCash, $checkedWallet, $notes, $acceptedTransactionIds, $differenceResponsibleUserId, false);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    public function emergencyHandover(int $adminId, ?int $incomingCashierId, float $countedCash, float $checkedWallet, string $reason, ?array $acceptedTransactionIds = null, ?int $differenceResponsibleUserId = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Enter a reason for emergency handover or service stop.');
        }
        if (!is_finite($countedCash) || $countedCash < 0 || !is_finite($checkedWallet) || $checkedWallet < 0) {
            throw new DomainException('Emergency intervention balances must be 0 or greater.');
        }
        try {
            StoreWriteGate::begin($this->pdo);
            $userStmt = $this->pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id
                WHERE u.user_id = ? AND u.status = 'active'
                  AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles assigned ON assigned.role_id = ur.role_id
                                  WHERE ur.user_id = u.user_id AND assigned.role_name = 'super_admin')");
            $userStmt->execute([$adminId]);
            $roleName = $userStmt->fetchColumn();
            if (function_exists('is_remote_request') && is_remote_request()) {
                throw new DomainException('Emergency handover is not permitted during remote monitoring.', 403);
            }
            $activeRole = function_exists('current_role') ? current_role() : null;
            if ($activeRole !== null && $activeRole !== 'admin') {
                throw new DomainException('Only an active Administrator workspace can execute an emergency handover.', 403);
            }
            if ($roleName !== 'admin') {
                throw new DomainException('Only an Administrator can execute an emergency handover.', 403);
            }

            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessionStmt = $this->pdo->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
            $sessionStmt->execute();
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('No open GCash service session found to hand over.', 404);
            }

            $result = $this->performHandover($session, $adminId, $incomingCashierId, $countedCash, $checkedWallet, $reason, $acceptedTransactionIds, $differenceResponsibleUserId, true);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    private function performHandover(array $session, int $actorId, ?int $incomingCashierId, float $countedCash, float $checkedWallet, string $notes, ?array $acceptedTransactionIds, ?int $differenceResponsibleUserId, bool $emergency): array
    {
        $incomingShiftId = null;
        if (!$emergency || ($incomingCashierId !== null && $incomingCashierId > 0)) {
            $recipientRoles = array_column($this->eligibleHandoverRecipients(), 'role_name', 'user_id');
            if (!isset($recipientRoles[$incomingCashierId])) {
                throw new DomainException('Select an eligible staff member with an active open shift before handover.');
            }
            $incomingRole = $recipientRoles[$incomingCashierId];
            $shiftService = new \App\Services\CashierShiftService($this->pdo);
            $shiftService->requireUnlockedRegister($incomingCashierId);
            $incomingShift = $shiftService->getOpenShift($incomingCashierId);
            if (!$incomingShift) {
                throw new DomainException('The incoming Cashier must have an active open shift before handover.');
            }
            $incomingShiftId = (int)$incomingShift['shift_id'];
        } else {
            $incomingCashierId = null;
        }

        $sessionId = (int)$session['session_id'];
        $expected = $this->calculateSessionExpected($sessionId);
        $expectedCash = (float)$expected['expected_service_cash'];
        $expectedWallet = (float)$expected['expected_wallet_balance'];
        $cashVariance = round($countedCash - $expectedCash, 2);
        $walletVariance = round($checkedWallet - $expectedWallet, 2);

        $diffRespId = null;
        $diffRespRole = null;
        if ($cashVariance != 0.0 || $walletVariance != 0.0) {
            $targetDiffUser = $differenceResponsibleUserId ?? $actorId;
            try {
                $diffRespRole = $this->resolveEligibleRole($targetDiffUser);
                $diffRespId = $targetDiffUser;
            } catch (Throwable) {
                $diffRespId = null;
                $diffRespRole = null;
            }
        }

        $closeStmt = $this->pdo->prepare(
            "UPDATE gcash_service_sessions
             SET status = 'closed', closed_at = CURRENT_TIMESTAMP, closed_by = ?,
                 expected_service_cash = ?, expected_wallet_balance = ?,
                 closing_service_cash = ?, closing_wallet_balance = ?,
                 cash_variance = ?, wallet_variance = ?, closing_notes = ?,
                 responsible_user_id = ?, responsible_role = ?,
                 responsibility_notes = ?, responsibility_assigned_at = " . ($diffRespId !== null ? "CURRENT_TIMESTAMP" : "NULL") . "
             WHERE session_id = ? AND status = 'open'"
        );
        $closeStmt->execute([
            $actorId,
            $expectedCash, $expectedWallet,
            $countedCash, $checkedWallet,
            $cashVariance, $walletVariance,
            $emergency ? 'Emergency Handover: ' . $notes : ($notes !== '' ? 'Handover: ' . $notes : 'Handover to Cashier #' . $incomingCashierId),
            $diffRespId,
            $diffRespRole,
            ($emergency ? 'Emergency handover investigation responsibility: ' : 'Handover investigation responsibility: ') . $notes,
            $sessionId,
        ]);

        if ($acceptedTransactionIds !== null || $incomingCashierId === null) {
            $pendingTxStmt = $this->pdo->query("SELECT transaction_id, cashier_id, responsible_user_id FROM gcash_transactions WHERE verification_status = 'Needs checking'");
            $pendingTxs = $pendingTxStmt->fetchAll(PDO::FETCH_ASSOC);
            $updateTxStmt = $this->pdo->prepare(
                "UPDATE gcash_transactions
                 SET responsible_user_id = ?, responsible_role = ?, responsibility_notes = ?, responsibility_assigned_at = ?
                 WHERE transaction_id = ?"
            );
            foreach ($pendingTxs as $ptx) {
                $ptxId = (int)$ptx['transaction_id'];
                if ($incomingCashierId !== null && in_array($ptxId, $acceptedTransactionIds ?? [], true)) {
                    $updateTxStmt->execute([
                        $incomingCashierId,
                        $incomingRole,
                        $emergency ? 'Accepted follow-up investigation responsibility via emergency handover' : 'Accepted follow-up investigation responsibility via handover',
                        date('Y-m-d H:i:s'),
                        $ptxId
                    ]);
                    \log_activity($this->pdo, $incomingCashierId, 'GCash exchange follow-up responsibility accepted', 'GCash', $ptxId,
                        ['responsible_user_id' => $ptx['responsible_user_id']],
                        ['responsible_user_id' => $incomingCashierId, 'responsible_role' => $incomingRole, 'investigation_responsibility_only' => true, 'underlying_shortage_ownership_transferred' => false, 'clarification' => 'Handover transfers investigation responsibility, not ownership of underlying money shortage'],
                        null,
                        AuditRecordCategory::STORE_OPERATION);
                } else {
                    $updateTxStmt->execute([
                        null,
                        null,
                        $emergency ? ($incomingCashierId !== null ? 'Unaccepted during emergency handover' : 'Unaccepted during emergency service stop') : 'Unaccepted during handover from Cashier #' . $actorId,
                        null,
                        $ptxId
                    ]);
                    \log_activity($this->pdo, $actorId, 'GCash exchange follow-up responsibility unassigned', 'GCash', $ptxId,
                        ['responsible_user_id' => $ptx['responsible_user_id']],
                        ['responsible_user_id' => null, 'responsible_role' => null],
                        null,
                        AuditRecordCategory::STORE_OPERATION);
                }
            }
        }

        if ($incomingCashierId !== null) {
            \log_activity($this->pdo, $actorId, $emergency ? 'GCash session closed via emergency handover' : 'GCash session closed for handover', 'GCash', $sessionId,
                $emergency ? ['previous_cashier_id' => $session['cashier_id']] : ['opening_service_cash' => $session['opening_service_cash'], 'opening_wallet_balance' => $session['opening_wallet_balance']],
                ['closing_service_cash' => $countedCash, 'closing_wallet_balance' => $checkedWallet, 'cash_variance' => $cashVariance, 'wallet_variance' => $walletVariance, 'incoming_cashier_id' => $incomingCashierId] + ($emergency ? ['reason' => $notes] : []),
                null, AuditRecordCategory::STORE_OPERATION);

            $openStmt = $this->pdo->prepare(
                "INSERT INTO gcash_service_sessions (shift_id, cashier_id, opening_service_cash, opening_wallet_balance, status)
                 VALUES (?, ?, ?, ?, 'open')"
            );
            $openStmt->execute([$incomingShiftId, $incomingCashierId, $countedCash, $checkedWallet]);
            $newSessionId = (int)$this->pdo->lastInsertId();

            \log_activity($this->pdo, $incomingCashierId, $emergency ? 'GCash session opened via emergency handover' : 'GCash session opened via handover', 'GCash', $newSessionId, null,
                ['shift_id' => $incomingShiftId, 'opening_service_cash' => $countedCash, 'opening_wallet_balance' => $checkedWallet, 'predecessor_session_id' => $sessionId, ($emergency ? 'authorized_by' : 'handed_over_by') => $actorId],
                null, AuditRecordCategory::STORE_OPERATION);
        } else {
            $newSessionId = null;
            \log_activity($this->pdo, $actorId, 'GCash service stopped via emergency intervention', 'GCash', $sessionId,
                ['previous_cashier_id' => $session['cashier_id']],
                ['closing_service_cash' => $countedCash, 'closing_wallet_balance' => $checkedWallet, 'cash_variance' => $cashVariance, 'wallet_variance' => $walletVariance, 'reason' => $notes, 'service_stopped' => true],
                null, AuditRecordCategory::STORE_OPERATION);
        }

        $result = [
            'outgoing_session_id' => $sessionId,
            'incoming_session_id' => $newSessionId,
            'service_stopped' => ($incomingCashierId === null),
            'cash_variance' => $cashVariance,
            'wallet_variance' => $walletVariance,
            'closing_service_cash' => $countedCash,
            'closing_wallet_balance' => $checkedWallet,
        ];
        if (!$emergency) {
            unset($result['service_stopped']);
        }
        return $result;
    }

    private function getSessionColumns(): array
    {
        return GCashSchema::columns($this->pdo, 'gcash_service_sessions');
    }

    public function resolveEligibleRole(int $userId): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.role_name
             FROM users u
             JOIN roles r ON u.role_id = r.role_id
             WHERE u.user_id = ? AND u.status = 'active'"
        );
        $stmt->execute([$userId]);
        $primaryRole = $stmt->fetchColumn();
        if ($primaryRole && in_array($primaryRole, ['admin', 'cashier'], true)) {
            return $primaryRole;
        }

        try {
            $secStmt = $this->pdo->prepare(
                "SELECT r.role_name
                 FROM user_roles ur
                 JOIN roles r ON ur.role_id = r.role_id
                 WHERE ur.user_id = ? AND r.role_name IN ('admin', 'cashier')
                 LIMIT 1"
            );
            $secStmt->execute([$userId]);
            $secRole = $secStmt->fetchColumn();
            if ($secRole) {
                return $secRole;
            }
        } catch (Throwable) {}

        throw new DomainException('Responsible user must be an active Cashier or Administrator.', 422);
    }

    /** Live Custody state for admin overview assembly (ADR-0025). */
    public function custodyOverviewState(): array
    {
        $openSession = $this->getCurrentSession();
        $currentExpectedCash = 0.0;
        $currentExpectedWallet = 0.0;
        $lastCheckedAt = null;
        $lastCheckedFormatted = '—';
        $controllingCashierId = null;
        $controllingCashierName = null;
        $sessionStatus = 'closed';
        $balanceSession = $openSession ?: null;
        $recordedCash = null;
        $recordedWallet = null;

        if ($openSession) {
            $expected = $this->calculateSessionExpected((int)$openSession['session_id']);
            $currentExpectedCash = (float)$expected['expected_service_cash'];
            $currentExpectedWallet = (float)$expected['expected_wallet_balance'];
            $recordedCash = $currentExpectedCash;
            $recordedWallet = $currentExpectedWallet;
            $lastCheckedAt = $openSession['opened_at'];
            $lastCheckedFormatted = PhilippineTime::format($openSession['opened_at']);
            $controllingCashierId = (int)$openSession['cashier_id'];
            $controllingCashierName = $openSession['cashier_name'];
            $sessionStatus = 'open';
        } else {
            $closedStmt = $this->pdo->prepare(
                "SELECT s.*, u.display_name AS cashier_name, u.username AS cashier_username
                 FROM gcash_service_sessions s
                 JOIN users u ON s.cashier_id = u.user_id
                 WHERE s.status = 'closed'
                 ORDER BY s.session_id DESC
                 LIMIT 1"
            );
            $closedStmt->execute();
            $latestClosed = $closedStmt->fetch(PDO::FETCH_ASSOC);
            if ($latestClosed) {
                $balanceSession = $latestClosed;
                $recordedCash = $latestClosed['closing_service_cash'] !== null ? (float)$latestClosed['closing_service_cash'] : null;
                $recordedWallet = $latestClosed['closing_wallet_balance'] !== null ? (float)$latestClosed['closing_wallet_balance'] : null;
                $controllingCashierId = (int)$latestClosed['cashier_id'];
                $controllingCashierName = $latestClosed['cashier_name'] ?: $latestClosed['cashier_username'];
                $currentExpectedCash = (float)($latestClosed['closing_service_cash'] ?? $latestClosed['expected_service_cash'] ?? 0.0);
                $currentExpectedWallet = (float)($latestClosed['closing_wallet_balance'] ?? $latestClosed['expected_wallet_balance'] ?? 0.0);
                $lastCheckedAt = $latestClosed['closed_at'];
                $lastCheckedFormatted = PhilippineTime::format($lastCheckedAt);
                $sessionStatus = 'closed';
            }
        }

        return [
            'recorded_balances' => [
                'service_cash' => $recordedCash,
                'wallet_balance' => $recordedWallet,
                'state' => $balanceSession ? ($openSession ? 'expected' : 'last_counted') : 'not_recorded',
                'session_id' => $balanceSession ? (int)$balanceSession['session_id'] : null,
                'session_status' => $balanceSession ? $balanceSession['status'] : null,
                'cashier_id' => $controllingCashierId,
                'cashier_name' => $controllingCashierName,
                'checked_at' => $lastCheckedAt,
                'checked_at_formatted' => $lastCheckedAt ? PhilippineTime::format($lastCheckedAt) : null,
            ],
            'current_expected_balances' => [
                'expected_service_cash' => $currentExpectedCash,
                'expected_wallet_balance' => $currentExpectedWallet,
                'last_checked_at' => $lastCheckedAt,
                'last_checked_formatted' => $lastCheckedFormatted,
                'controlling_cashier_id' => $controllingCashierId,
                'controlling_cashier_name' => $controllingCashierName,
                'session_status' => $sessionStatus,
            ],
            'current_expected_service_cash' => $currentExpectedCash,
            'current_expected_wallet_balance' => $currentExpectedWallet,
            'last_checked_at' => $lastCheckedAt,
            'last_checked_formatted' => $lastCheckedFormatted,
            'controlling_cashier_id' => $controllingCashierId,
            'controlling_cashier_name' => $controllingCashierName,
            'session_status' => $sessionStatus,
            'session_id' => $openSession ? (int)$openSession['session_id'] : null,
        ];
    }
}
