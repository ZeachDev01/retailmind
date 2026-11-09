<?php

declare(strict_types=1);

namespace App\GCash;

use App\Audit\AuditRecordCategory;
use App\Services\PhilippineTime;
use App\Store\StoreWriteGate;
use DomainException;
use PDO;
use Throwable;

require_once __DIR__ . '/../Services/FiscalPeriodGuardService.php';

/** GCash exchange review/difference module. */
final class GCashExchangeReview
{
    private ?\FiscalPeriodGuardService $fiscalPeriodGuard = null;

    public function __construct(private PDO $pdo, private array $rules = [], private ?GCashLiveCustodySession $custody = null)
    {
        $this->fiscalPeriodGuard = new \FiscalPeriodGuardService($pdo);
        $this->custody ??= new GCashLiveCustodySession($pdo);
    }

    private function fiscalPeriodGuard(): \FiscalPeriodGuardService
    {
        if (!$this->fiscalPeriodGuard) {
            $this->fiscalPeriodGuard = new \FiscalPeriodGuardService($this->pdo);
        }
        return $this->fiscalPeriodGuard;
    }

    public function authorizeAdmin(string $username, string $password): int
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw new DomainException('Administrator username and password are required.', 403);
        }
        return \App\Services\SupervisorApprovalService::authorize(
            $this->pdo,
            $username,
            $password,
            'Administrator authorization failed: invalid credentials.',
            403
        );
    }

    public function resolveEligibleRole(int $userId): string
    {
        return $this->custody->resolveEligibleRole($userId);
    }

    private function getSessionColumns(): array
    {
        return GCashSchema::columns($this->pdo, 'gcash_service_sessions');
    }

    public function getSession(int $sessionId): array
    {
        return $this->custody->getSession($sessionId);
    }

    private function adjustmentColumns(): array
    {
        return GCashSchema::columns($this->pdo, 'gcash_adjustments');
    }

    /** @return array{remaining_cash: float, remaining_wallet: float, fees_returned: float, has_returns: bool} */
    public function failedExchangeBalance(array $tx): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gcash_adjustments WHERE transaction_id = ?');
        $stmt->execute([(int)$tx['transaction_id']]);
        $cash = (float)$tx['service_cash_delta'];
        $wallet = (float)$tx['wallet_delta'];
        $feesReturned = 0.0;
        $hasReturns = false;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $adjustment) {
            if (($adjustment['correction_mode'] ?? 'money_movement') !== 'money_movement') continue;
            $hasReturns = $hasReturns || (float)$adjustment['service_cash_delta'] != 0.0 || (float)$adjustment['wallet_delta'] != 0.0;
            $cash += (float)$adjustment['service_cash_delta'];
            $wallet += (float)$adjustment['wallet_delta'];
            $feesReturned += max(0.0, -(float)($adjustment['fee_delta'] ?? 0));
        }
        return ['remaining_cash' => abs(round($cash, 2)), 'remaining_wallet' => abs(round($wallet, 2)), 'fees_returned' => round($feesReturned, 2), 'has_returns' => $hasReturns];
    }

    public function unresolvedExchanges(?int $responsibleUserId = null): array
    {
        $ncSql = "SELECT t.*, u.display_name AS cashier_name, u.username AS cashier_username,
                         ru.display_name AS responsible_user_name, ru.username AS responsible_username
                  FROM gcash_transactions t
                  JOIN users u ON t.cashier_id = u.user_id
                  LEFT JOIN users ru ON t.responsible_user_id = ru.user_id
                  WHERE t.verification_status = 'Needs checking'";
        $ncParams = [];
        if ($responsibleUserId !== null) {
            $ncSql .= " AND t.responsible_user_id = ?";
            $ncParams[] = $responsibleUserId;
        }
        $ncSql .= " ORDER BY t.transaction_id DESC";
        $ncStmt = $this->pdo->prepare($ncSql);
        $ncStmt->execute($ncParams);
        $unresolved = [];
        foreach ($ncStmt->fetchAll(PDO::FETCH_ASSOC) as $nc) {
            $isLegacy = ($nc['session_id'] === null);
            $nc['is_legacy'] = $isLegacy;
            $nc['created_at_formatted'] = PhilippineTime::format($nc['created_at']);
            $nc['time_formatted'] = PhilippineTime::format($nc['created_at']);
            $nc['principal_amount'] = $nc['principal_amount'] !== null ? (float)$nc['principal_amount'] : null;
            $nc['fee_amount'] = $nc['fee_amount'] !== null ? (float)$nc['fee_amount'] : null;
            $nc['gross_fee'] = (float)$nc['gross_fee'];
            $nc['service_cash_delta'] = (float)$nc['service_cash_delta'];
            $nc['wallet_delta'] = (float)$nc['wallet_delta'];
            $nc['responsible_user_id'] = $nc['responsible_user_id'] !== null ? (int)$nc['responsible_user_id'] : null;
            $nc['responsible_user_name'] = $nc['responsible_user_name'] ?? $nc['responsible_username'] ?? null;
            $nc['responsible_role'] = $nc['responsible_role'] ?? null;
            $nc['responsible_label'] = $nc['responsible_user_id'] !== null
                ? ($nc['responsible_user_name'] ?? $nc['responsible_username']) . ' (' . ucfirst($nc['responsible_role'] ?? '') . ')'
                : 'Needs a responsible Cashier';
            if ($isLegacy) {
                $nc['fee_location'] = null;
            } elseif ($nc['fee_method'] === 'cash') {
                $nc['fee_location'] = 'cash';
            } elseif ($nc['fee_method'] === 'wallet') {
                $nc['fee_location'] = 'wallet';
            } elseif ($nc['transaction_type'] === 'Cash-In') {
                $nc['fee_location'] = 'cash';
            } else {
                $nc['fee_location'] = null;
            }
            $unresolved[] = $nc;
        }
        return $unresolved;
    }

    public function reconciliationDifferences(): array
    {
        $diffSql = "SELECT s.*,
                           u.display_name AS cashier_name,
                           u.username AS cashier_username,
                           cb.display_name AS closed_by_name,
                           wb.display_name AS waived_by_name,
                           ru.display_name AS responsible_user_name,
                           ru.username AS responsible_username
                    FROM gcash_service_sessions s
                    JOIN users u ON s.cashier_id = u.user_id
                    LEFT JOIN users cb ON s.closed_by = cb.user_id
                    LEFT JOIN users wb ON s.waived_by = wb.user_id
                    LEFT JOIN users ru ON s.responsible_user_id = ru.user_id
                    WHERE s.status = 'closed'
                      AND (s.cash_variance != 0 OR s.wallet_variance != 0)";
        $diffSql .= " ORDER BY s.session_id DESC";
        $diffStmt = $this->pdo->prepare($diffSql);
        $diffStmt->execute();
        $differences = [];
        foreach ($diffStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $isWaived = !empty($d['waived_at']);
            $isReviewed = !empty($d['reviewed_at']) || $isWaived;
            $reviewedBy = $d['reviewed_by'] !== null ? (int)$d['reviewed_by'] : ($d['waived_by'] !== null ? (int)$d['waived_by'] : null);
            $reviewedAt = $d['reviewed_at'] ?? $d['waived_at'];
            $reviewNotes = $d['review_notes'] ?? $d['waiver_notes'];

            $differences[] = [
                'session_id' => (int)$d['session_id'],
                'shift_id' => (int)$d['shift_id'],
                'cashier_id' => (int)$d['cashier_id'],
                'cashier_name' => $d['cashier_name'] ?? $d['cashier_username'],
                'cashier_username' => $d['cashier_username'],
                'expected_service_cash' => (float)$d['expected_service_cash'],
                'expected_wallet_balance' => (float)$d['expected_wallet_balance'],
                'closing_service_cash' => (float)$d['closing_service_cash'],
                'closing_wallet_balance' => (float)$d['closing_wallet_balance'],
                'cash_variance' => (float)$d['cash_variance'],
                'wallet_variance' => (float)$d['wallet_variance'],
                'closing_notes' => (string)($d['closing_notes'] ?? ''),
                'closed_at' => $d['closed_at'],
                'closed_at_formatted' => PhilippineTime::format($d['closed_at']),
                'closed_by' => $d['closed_by'] !== null ? (int)$d['closed_by'] : null,
                'closed_by_name' => $d['closed_by_name'] ?? null,
                'waiver_notes' => $d['waiver_notes'],
                'waived_by' => $d['waived_by'] !== null ? (int)$d['waived_by'] : null,
                'waived_by_name' => $d['waived_by_name'] ?? null,
                'waived_at' => $d['waived_at'],
                'waived_at_formatted' => $d['waived_at'] ? PhilippineTime::format($d['waived_at']) : null,
                'is_waived' => $isWaived,
                'is_reviewed' => $isReviewed,
                'reviewed_by' => $reviewedBy,
                'reviewed_by_name' => $d['waived_by_name'] ?? null,
                'reviewed_at' => $reviewedAt,
                'reviewed_at_formatted' => $reviewedAt ? PhilippineTime::format($reviewedAt) : null,
                'review_notes' => $reviewNotes,
                'review_draft_notes' => $d['review_draft_notes'] ?? null,
                'review_shortage' => isset($d['review_shortage']) && $d['review_shortage'] !== null ? (float)$d['review_shortage'] : null,
                'review_history' => isset($d['review_history']) ? (json_decode((string)$d['review_history'], true) ?: []) : [],
                'cash_check_explanation' => $d['cash_check_explanation'] ?? null,
                'wallet_check_explanation' => $d['wallet_check_explanation'] ?? null,
                'responsible_user_id' => $d['responsible_user_id'] !== null ? (int)$d['responsible_user_id'] : null,
                'responsible_user_name' => $d['responsible_user_name'] ?? $d['responsible_username'] ?? null,
                'responsible_role' => $d['responsible_role'] ?? null,
                'responsible_notes' => $d['responsibility_notes'] ?? null,
                'responsible_label' => $d['responsible_user_id'] !== null
                    ? ($d['responsible_user_name'] ?? $d['responsible_username']) . ' (' . ucfirst($d['responsible_role'] ?? '') . ')'
                    : 'Needs a responsible role',
            ];
        }
        return $differences;
    }

    public function previewLinkedCorrection(int $transactionId, string $mode, float $cashDelta, float $walletDelta): array
    {
        $mode = in_array($mode, ['recording_mistake', 'money_movement'], true) ? $mode : 'money_movement';
        $tx = $this->pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = " . (int)$transactionId)->fetch(PDO::FETCH_ASSOC);
        if (!$tx) {
            throw new DomainException('Transaction not found.', 404);
        }
        $targetSessionId = null;
        $responsibleCashierId = $tx['responsible_user_id'] !== null ? (int)$tx['responsible_user_id'] : (int)$tx['cashier_id'];
        if ($mode === 'money_movement') {
            $stmt = $this->pdo->prepare("SELECT session_id FROM gcash_service_sessions WHERE cashier_id = ? AND status = 'open' ORDER BY session_id DESC LIMIT 1");
            $stmt->execute([$responsibleCashierId]);
            $targetSessionId = $stmt->fetchColumn() ?: null;
        }
        return [
            'transaction_id' => $transactionId,
            'mode' => $mode,
            'cash_delta' => round($cashDelta, 2),
            'wallet_delta' => round($walletDelta, 2),
            'affects_present_money' => ($mode === 'money_movement'),
            'target_session_id' => $targetSessionId ? (int)$targetSessionId : null,
            'responsible_cashier_id' => $responsibleCashierId,
        ];
    }

    public function recordLinkedCorrection(int $adminId, string $adminRole, int $transactionId, float $cashDelta, float $walletDelta, string $reason, ?int $sessionId = null, string $mode = 'money_movement', ?int $recordedBy = null): int
    {
        if (function_exists('is_remote_request') && is_remote_request()) {
            throw new DomainException('Linked corrections are not permitted during remote monitoring.', 403);
        }
        if ($adminRole !== 'admin') {
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
            StoreWriteGate::begin($this->pdo);
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_adjustments', 'GCash correction');
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $txStmt = $this->pdo->prepare("SELECT * FROM gcash_transactions WHERE transaction_id = ?" . $lock);
            $txStmt->execute([$transactionId]);
            $tx = $txStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tx) {
                throw new DomainException('Transaction not found.', 404);
            }
            if ($tx['verification_status'] !== 'Completed') {
                throw new DomainException('Linked corrections may only be recorded against Completed transactions.', 422);
            }

            $mode = in_array($mode, ['recording_mistake', 'money_movement'], true) ? $mode : 'money_movement';
            $responsibleUser = $tx['responsible_user_id'] !== null ? (int)$tx['responsible_user_id'] : (int)$tx['cashier_id'];

            if ($mode === 'money_movement') {
                if ($sessionId === null) {
                    $sessStmt = $this->pdo->prepare("SELECT session_id FROM gcash_service_sessions WHERE cashier_id = ? AND status = 'open' ORDER BY session_id DESC LIMIT 1");
                    $sessStmt->execute([$responsibleUser]);
                    $sessionId = $sessStmt->fetchColumn() ?: null;
                }
                if (!$sessionId) {
                    throw new DomainException('Cannot record money movement: responsible Cashier must have an eligible open GCash session in an open Fiscal Period.', 422);
                }
                $sessionId = (int)$sessionId;
            } else {
                // Recording mistake: links to original historical session without moving present money
                if ($sessionId === null) {
                    $sessionId = (int)($tx['session_id'] ?? 0);
                }
                if ($sessionId <= 0) {
                    $openSession = $this->pdo->query("SELECT session_id FROM gcash_service_sessions WHERE status = 'open' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    $sessionId = $openSession ? (int)$openSession['session_id'] : 1;
                }
            }

            $hasModeCol = false;
            try {
                $hasModeCol = in_array('correction_mode', GCashSchema::columns($this->pdo, 'gcash_adjustments'), true);
            } catch (Throwable) {}

            if ($hasModeCol) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO gcash_adjustments (transaction_id, session_id, correction_mode, service_cash_delta, wallet_delta, reason, authorized_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $transactionId,
                    $sessionId,
                    $mode,
                    $cashDelta,
                    $walletDelta,
                    $reason,
                    $adminId,
                ]);
            } else {
                $stmt = $this->pdo->prepare(
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
            }
            $adjId = (int)$this->pdo->lastInsertId();
            if (in_array('recorded_by', $this->adjustmentColumns(), true)) {
                $this->pdo->prepare('UPDATE gcash_adjustments SET recorded_by=? WHERE adjustment_id=?')->execute([$recordedBy ?? $adminId, $adjId]);
            }


            \log_activity(
                $this->pdo,
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

            $this->pdo->commit();
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
        if (function_exists('is_remote_request') && is_remote_request()) {
            throw new DomainException('Resolving exchanges is not permitted during remote monitoring.', 403);
        }
        $action = strtolower(trim($action));
        if (!in_array($action, ['complete', 'cancel', 'reject'], true)) {
            throw new DomainException('Action must be complete or cancel.');
        }

        try {
            StoreWriteGate::begin($this->pdo);
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_service_sessions', 'GCash emergency handover');
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $txStmt = $this->pdo->prepare("SELECT * FROM gcash_transactions WHERE transaction_id = ?" . $lock);
            $txStmt->execute([$transactionId]);
            $tx = $txStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tx) {
                throw new DomainException('Transaction not found.', 404);
            }
            if ($tx['verification_status'] !== 'Needs checking') {
                throw new DomainException('This transaction is no longer in Needs checking status.', 409);
            }
            if (array_key_exists('responsible_user_id', $tx) && ($tx['responsible_user_id'] === null || empty($tx['responsible_role']))) {
                throw new DomainException('Cannot clear or resolve exchange without an accepted responsible role on file.', 422);
            }

            $type = $tx['transaction_type'];
            $principal = (int)round((float)$tx['amount']);
            $feeMethodStr = $tx['fee_method'] === 'wallet' ? 'Fee via GCash' : 'Fee in cash';
            $preview = GCashPricing::calculatePreview($type, $principal, $feeMethodStr);
            $targetFee = (float)$preview['fee'];
            $targetCashDelta = (float)$preview['net_service_cash'];
            $targetWalletDelta = (float)$preview['net_wallet'];

            $priorConfirmedWallet = (bool)$tx['confirmed_wallet'];
            $priorConfirmedCash = (bool)$tx['confirmed_cash'];

            if ($action === 'complete') {
                $returns = $this->pdo->prepare('SELECT 1 FROM gcash_adjustments WHERE transaction_id=? AND (service_cash_delta<>0 OR wallet_delta<>0) LIMIT 1');
                $returns->execute([$transactionId]);
                if ($returns->fetchColumn()) {
                    throw new DomainException('Actual returns have started. Finish returning or recovering the remaining money; this exchange cannot be marked completed.');
                }

                if (isset($data['confirmed_cash']) && $data['confirmed_cash'] === false) {
                    throw new DomainException('Uncertain cash evidence leaves the exchange pending. Both physical cash and wallet checks must be confirmed.');
                }
                if (isset($data['confirmed_wallet']) && $data['confirmed_wallet'] === false) {
                    throw new DomainException('Uncertain wallet evidence leaves the exchange pending. Both physical cash and wallet checks must be confirmed.');
                }

                $cashFinding = trim((string)($data['cash_finding'] ?? ''));
                $walletFinding = trim((string)($data['wallet_finding'] ?? ''));

                $isUncertain = static function (string $text): bool {
                    return (bool)preg_match('/\b(uncertain|unconfirmed|unverified|discrepancy|not found|missing|unchecked|doubtful|unknown|unproven|offline)\b/i', $text);
                };
                if ($isUncertain($cashFinding) || $isUncertain($walletFinding)) {
                    throw new DomainException('Uncertain cash or wallet evidence leaves the exchange pending. Both physical cash and wallet checks must be confirmed.');
                }

                if ($cashFinding === '' && $walletFinding === '') {
                    $noteStr = (string)($data['notes'] ?? $data['note'] ?? '');
                    if (preg_match('/expected.*balance|system.*balance/i', $noteStr)) {
                        throw new DomainException('Expected system balances alone are insufficient evidence. Physical cash and wallet check findings required.');
                    }
                }

                if ($cashFinding === '') {
                    $cashFinding = $priorConfirmedCash ? 'Physical service cash confirmed by Cashier at entry' : 'Physical service cash confirmed';
                }
                if ($walletFinding === '') {
                    $walletFinding = $priorConfirmedWallet ? 'Wallet transfer confirmed in GCash app at entry' : 'Wallet transfer confirmed in GCash app';
                }

                $cashCheckedBy = !empty($data['cash_checked_by']) ? (int)$data['cash_checked_by'] : ($priorConfirmedCash ? (int)$tx['cashier_id'] : $actorId);
                $walletCheckedBy = !empty($data['wallet_checked_by']) ? (int)$data['wallet_checked_by'] : ($priorConfirmedWallet ? (int)$tx['cashier_id'] : $actorId);
                $refRaw = trim((string)($data['reference'] ?? $data['reference_number'] ?? ''));
                if (!$priorConfirmedWallet) {
                    if ($refRaw === '') {
                        throw new DomainException('A valid reference number is required to complete wallet transfer.');
                    }
                    $reference = GCashReference::normalize($refRaw, (int)($this->rules['min_length'] ?? 13), (int)($this->rules['max_length'] ?? 13));
                } else {
                    $reference = $tx['reference_number'] !== null ? $tx['reference_number'] : ($refRaw !== '' ? GCashReference::normalize($refRaw, (int)($this->rules['min_length'] ?? 13), (int)($this->rules['max_length'] ?? 13)) : null);
                    if ($reference === null) {
                        throw new DomainException('A valid reference number is required to complete this transaction.');
                    }
                }

                if (!$priorConfirmedCash && $targetCashDelta < 0) {
                    $openSession = $this->pdo->query("SELECT session_id FROM gcash_service_sessions WHERE status = 'open' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if ($openSession) {
                        $expected = $this->custody->calculateSessionExpected((int)$openSession['session_id']);
                        $cashPayout = (float)$preview['cash_payout'];
                        if ($expected['expected_service_cash'] < $cashPayout) {
                            throw new DomainException(sprintf('Insufficient service cash balance. Available: PHP %.2f, required: PHP %.2f.', $expected['expected_service_cash'], $cashPayout), 422);
                        }
                    }
                }

                $existingNotes = (string)($tx['notes'] ?? '');
                $extraNote = trim((string)($data['notes'] ?? $data['note'] ?? ''));
                $resolvedNotes = $existingNotes . ($extraNote !== '' ? ' [Resolved Completed: ' . $extraNote . ']' : ' [Resolved Completed]');

                $updateStmt = $this->pdo->prepare(
                    "UPDATE gcash_transactions
                     SET verification_status = 'Completed',
                         verified_by = ?,
                         reference_number = ?,
                         confirmed_wallet = 1,
                         confirmed_cash = 1,
                         cash_checked_by = ?,
                         cash_finding = ?,
                         wallet_checked_by = ?,
                         wallet_finding = ?,
                         service_cash_delta = ?,
                         wallet_delta = ?,
                         gross_fee = ?,
                         completed_at = CURRENT_TIMESTAMP,
                         reviewed_at = CURRENT_TIMESTAMP,
                         notes = ?
                     WHERE transaction_id = ? AND verification_status = 'Needs checking'"
                );
                $updateStmt->execute([
                    $actorId,
                    $reference,
                    $cashCheckedBy,
                    $cashFinding,
                    $walletCheckedBy,
                    $walletFinding,
                    $targetCashDelta,
                    $targetWalletDelta,
                    $targetFee,
                    $resolvedNotes,
                    $transactionId,
                ]);

                \log_activity(
                    $this->pdo,
                    $actorId,
                    'GCash Needs checking resolved completed',
                    'GCash',
                    $transactionId,
                    ['verification_status' => 'Needs checking', 'confirmed_cash' => $tx['confirmed_cash'], 'confirmed_wallet' => $tx['confirmed_wallet']],
                    [
                        'verification_status' => 'Completed',
                        'verified_by' => $actorId,
                        'reference_number' => $reference,
                        'cash_checked_by' => $cashCheckedBy,
                        'cash_finding' => $cashFinding,
                        'wallet_checked_by' => $walletCheckedBy,
                        'wallet_finding' => $walletFinding,
                        'service_cash_delta' => $targetCashDelta,
                        'wallet_delta' => $targetWalletDelta,
                        'gross_fee' => $targetFee,
                    ],
                    null,
                    AuditRecordCategory::STORE_OPERATION
                );

                (new \App\Receipts\ReceiptEngine($this->pdo))->preserveGCash($transactionId);

                $this->pdo->commit();
                \App\Dashboard\StoreOperationsDashboardWorkspace::invalidateCache();
                return [
                    'transaction_id' => $transactionId,
                    'status' => 'Completed',
                    'receipt_id' => $transactionId,
                    'verification_status' => 'Completed',
                ];
            } else {
                $userStmt = $this->pdo->prepare('SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?');
                $userStmt->execute([$actorId]);
                $storedRole = (string)$userStmt->fetchColumn();
                $actorRole = (function_exists('current_role') ? current_role() : null) ?? $storedRole;
                if ($actorRole === 'admin' || $actorRole === 'super_admin') {
                    throw new DomainException('Cancellation authority belongs to the responsible Cashier in Cashier Workspace; Administrator has no cancellation authority.');
                }

                if ($actorId !== (int)($tx['responsible_user_id'] ?? $tx['cashier_id'])) {
                    throw new DomainException('Only the responsible Cashier can cancel this exchange.', 403);
                }
                $hadCash = (float)$tx['service_cash_delta'] != 0.0;
                $hadWallet = (float)$tx['wallet_delta'] != 0.0;

                if ($hadCash || $hadWallet) {
                    $remaining = $this->failedExchangeBalance($tx);
                    if ($remaining['remaining_cash'] > 0 || $remaining['remaining_wallet'] > 0) {
                        throw new DomainException('Cannot cancel exchange without actual return evidence. Record actual returns with supervisor approval in an open GCash session.');
                    }
                }

                $reason = trim((string)($data['reason'] ?? $data['notes'] ?? $data['note'] ?? ''));
                if (mb_strlen($reason) < 5) {
                    throw new DomainException('Enter a cancellation reason of at least 5 characters.');
                }

                $existingNotes = (string)($tx['notes'] ?? '');
                $resolvedNotes = $existingNotes . ' [Cancelled: ' . $reason . ']';

                $updateStmt = $this->pdo->prepare(
                    "UPDATE gcash_transactions
                     SET verification_status = 'Rejected',
                         notes = ?
                     WHERE transaction_id = ? AND verification_status = 'Needs checking'"
                );
                $updateStmt->execute([$resolvedNotes, $transactionId]);

                \log_activity(
                    $this->pdo,
                    $actorId,
                    'GCash Needs checking cancelled',
                    'GCash',
                    $transactionId,
                    ['verification_status' => 'Needs checking'],
                    ['verification_status' => 'Rejected', 'cancellation_reason' => $reason],
                    null,
                    AuditRecordCategory::STORE_OPERATION
                );

                $this->pdo->commit();
                \App\Dashboard\StoreOperationsDashboardWorkspace::invalidateCache();
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

    public function saveReviewDraft(int $adminId, string $adminRole, int $sessionId, string $draftNotes): array
    {
        if ($adminRole !== 'admin') {
            throw new DomainException('Only an Administrator can save review drafts.', 403);
        }
        if (function_exists('is_remote_request') && is_remote_request()) {
            throw new DomainException('Review drafts are not permitted during remote monitoring.', 403);
        }
        $session = $this->getSession($sessionId);
        if ($session['status'] !== 'closed') {
            throw new DomainException('Only closed sessions can have review drafts.', 422);
        }
        $cols = $this->getSessionColumns();
        if (in_array('review_draft_notes', $cols, true)) {
            $stmt = $this->pdo->prepare("UPDATE gcash_service_sessions SET review_draft_notes = ? WHERE session_id = ?");
            $stmt->execute([$draftNotes, $sessionId]);
        }
        return $this->getSession($sessionId);
    }

    public function markReviewed(int $adminId, string $adminRole, int $sessionId, array|string $reviewData, ?float $remainingShortage = null): array
    {
        if ($adminRole !== 'admin') {
            throw new DomainException('Only an Administrator can review closing differences.', 403);
        }
        if (function_exists('is_remote_request') && is_remote_request()) {
            throw new DomainException('Discrepancy review is not permitted during remote monitoring.', 403);
        }

        if (is_string($reviewData)) {
            throw new DomainException('Explicit physical cash and wallet checks and explanations are required.');
        } else {
            $explanation = trim((string)($reviewData['explanation'] ?? $reviewData['review_notes'] ?? ''));
            $cashChecked = ($reviewData['cash_checked'] ?? false) === true;
            $walletChecked = ($reviewData['wallet_checked'] ?? false) === true;
            $cashExplanation = trim((string)($reviewData['cash_explanation'] ?? ''));
            $walletExplanation = trim((string)($reviewData['wallet_explanation'] ?? ''));
            if (isset($reviewData['remaining_shortage']) && $remainingShortage === null) {
                $remainingShortage = (float)$reviewData['remaining_shortage'];
            }
        }

        if (mb_strlen($explanation) < 5 || mb_strlen($explanation) > 500) {
            throw new DomainException('Enter review explanation between 5 and 500 characters.');
        }
        if (!$cashChecked || !$walletChecked) {
            throw new DomainException('Physical cash and wallet checks must both be confirmed before completing review.');
        }
        if ($cashExplanation === '') {
            throw new DomainException('Enter an explanation for the physical cash check.');
        }
        if ($walletExplanation === '') {
            throw new DomainException('Enter an explanation for the wallet check.');
        }

        try {
            StoreWriteGate::begin($this->pdo);
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare("SELECT * FROM gcash_service_sessions WHERE session_id = ?" . $lock);
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('GCash service session not found.', 404);
            }
            if ($session['status'] !== 'closed') {
                throw new DomainException('Only closed sessions with differences can be reviewed.', 422);
            }
            if ($session['waived_at'] !== null || !empty($session['reviewed_at'])) {
                throw new DomainException('This session difference has already been reviewed.', 409);
            }
            if (array_key_exists('responsible_user_id', $session) && ($session['responsible_user_id'] === null || empty($session['responsible_role']))) {
                throw new DomainException('Cannot waive closing discrepancy without an accepted responsible role on file.', 422);
            }
            if ((float)$session['cash_variance'] == 0.0 && (float)$session['wallet_variance'] == 0.0) {
                throw new DomainException('This session has no closing differences to review.', 422);
            }

            if ($remainingShortage === null) {
                $cashShortage = (float)$session['cash_variance'] < 0 ? abs((float)$session['cash_variance']) : 0.00;
                $walletShortage = (float)$session['wallet_variance'] < 0 ? abs((float)$session['wallet_variance']) : 0.00;
                $shortage = round($cashShortage + $walletShortage, 2);
            } else {
                $shortage = round($remainingShortage, 2);
                if ($shortage < 0) {
                    throw new \InvalidArgumentException('Remaining shortage cannot be negative.');
                }
            }

            $uStmt = $this->pdo->prepare('SELECT display_name FROM users WHERE user_id = ?');
            $uStmt->execute([$adminId]);
            $adminName = (string)($uStmt->fetchColumn() ?: "User #{$adminId}");

            $history = json_decode((string)($session['review_history'] ?? '[]'), true) ?: [];
            $now = date('Y-m-d H:i:s');
            $disclaimer = 'Implies neither recovery, debt assignment nor wage-deduction permission.';
            $history[] = [
                'action' => 'reviewed',
                'reviewed_by' => $adminId,
                'reviewer_name' => $adminName,
                'reviewed_at' => $now,
                'explanation' => $explanation,
                'cash_checked' => true,
                'cash_explanation' => $cashExplanation,
                'wallet_checked' => true,
                'wallet_explanation' => $walletExplanation,
                'remaining_shortage' => $shortage,
                'disclaimer' => $disclaimer,
            ];

            $cols = $this->getSessionColumns();
            $fields = [
                'waiver_notes = ?',
                'waived_by = ?',
                'waived_at = CURRENT_TIMESTAMP',
            ];
            $params = [$explanation, $adminId];

            if (in_array('reviewed_by', $cols, true)) {
                $fields[] = 'reviewed_by = ?';
                $params[] = $adminId;
            }
            if (in_array('reviewed_at', $cols, true)) {
                $fields[] = 'reviewed_at = CURRENT_TIMESTAMP';
            }
            if (in_array('review_notes', $cols, true)) {
                $fields[] = 'review_notes = ?';
                $params[] = $explanation;
            }
            if (in_array('review_shortage', $cols, true)) {
                $fields[] = 'review_shortage = ?';
                $params[] = $shortage;
            }
            if (in_array('review_history', $cols, true)) {
                $fields[] = 'review_history = ?';
                $params[] = json_encode($history, JSON_THROW_ON_ERROR);
            }
            if (in_array('cash_check_explanation', $cols, true)) {
                $fields[] = 'cash_check_explanation = ?';
                $params[] = $cashExplanation;
            }
            if (in_array('wallet_check_explanation', $cols, true)) {
                $fields[] = 'wallet_check_explanation = ?';
                $params[] = $walletExplanation;
            }

            $params[] = $sessionId;
            $update = $this->pdo->prepare('UPDATE gcash_service_sessions SET ' . implode(', ', $fields) . ' WHERE session_id = ?');
            $update->execute($params);

            \log_activity(
                $this->pdo,
                $adminId,
                'GCash closing difference reviewed',
                'GCash',
                $sessionId,
                null,
                null,
                null,
                AuditRecordCategory::STORE_OPERATION,
                [
                    'session_id' => $sessionId,
                    'reviewed_by' => $adminId,
                    'explanation' => $explanation,
                    'remaining_shortage' => $shortage,
                    'cash_variance' => (float)$session['cash_variance'],
                    'wallet_variance' => (float)$session['wallet_variance'],
                    'cash_explanation' => $cashExplanation,
                    'wallet_explanation' => $walletExplanation,
                    'disclaimer' => $disclaimer,
                ]
            );

            $this->pdo->commit();
            \App\Dashboard\StoreOperationsDashboardWorkspace::invalidateCache();

            return $this->getSession($sessionId);
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function reopenReview(int $adminId, string $adminRole, int $sessionId, string $reason): array
    {
        if ($adminRole !== 'admin') {
            throw new DomainException('Only an Administrator can reopen closing difference reviews.', 403);
        }
        if (function_exists('is_remote_request') && is_remote_request()) {
            throw new DomainException('Reopening reviews is not permitted during remote monitoring.', 403);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new DomainException('Enter a reason for reopening the review between 5 and 500 characters.');
        }

        try {
            StoreWriteGate::begin($this->pdo);
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare("SELECT * FROM gcash_service_sessions WHERE session_id = ?" . $lock);
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('GCash service session not found.', 404);
            }
            if ($session['status'] !== 'closed') {
                throw new DomainException('Only closed sessions can be reopened.', 422);
            }
            if ($session['waived_at'] === null && empty($session['reviewed_at'])) {
                throw new DomainException('Only reviewed sessions can be reopened.', 422);
            }

            $uStmt = $this->pdo->prepare('SELECT display_name FROM users WHERE user_id = ?');
            $uStmt->execute([$adminId]);
            $adminName = (string)($uStmt->fetchColumn() ?: "User #{$adminId}");

            $history = json_decode((string)($session['review_history'] ?? '[]'), true) ?: [];
            $now = date('Y-m-d H:i:s');
            $prevReviewedBy = (int)($session['reviewed_by'] ?? $session['waived_by']);
            $prevReviewedAt = $session['reviewed_at'] ?? $session['waived_at'];
            $prevNotes = $session['review_notes'] ?? $session['waiver_notes'];
            $prevShortage = isset($session['review_shortage']) && $session['review_shortage'] !== null ? (float)$session['review_shortage'] : null;

            $history[] = [
                'action' => 'reopened',
                'reopened_by' => $adminId,
                'reopener_name' => $adminName,
                'reopened_at' => $now,
                'reason' => $reason,
                'previous_reviewed_by' => $prevReviewedBy,
                'previous_reviewed_at' => $prevReviewedAt,
                'previous_review_notes' => $prevNotes,
                'previous_shortage' => $prevShortage,
                'previous_cash_explanation' => $session['cash_check_explanation'] ?? null,
                'previous_wallet_explanation' => $session['wallet_check_explanation'] ?? null,
            ];

            $cols = $this->getSessionColumns();
            $fields = [
                'waiver_notes = NULL',
                'waived_by = NULL',
                'waived_at = NULL',
            ];
            $params = [];

            if (in_array('reviewed_by', $cols, true)) {
                $fields[] = 'reviewed_by = NULL';
            }
            if (in_array('reviewed_at', $cols, true)) {
                $fields[] = 'reviewed_at = NULL';
            }
            if (in_array('review_history', $cols, true)) {
                $fields[] = 'review_history = ?';
                $params[] = json_encode($history, JSON_THROW_ON_ERROR);
            }

            $params[] = $sessionId;
            $update = $this->pdo->prepare('UPDATE gcash_service_sessions SET ' . implode(', ', $fields) . ' WHERE session_id = ?');
            $update->execute($params);

            \log_activity(
                $this->pdo,
                $adminId,
                'GCash closing difference review reopened',
                'GCash',
                $sessionId,
                null,
                null,
                null,
                AuditRecordCategory::STORE_OPERATION,
                [
                    'session_id' => $sessionId,
                    'reopened_by' => $adminId,
                    'reopen_reason' => $reason,
                ]
            );

            $this->pdo->commit();
            \App\Dashboard\StoreOperationsDashboardWorkspace::invalidateCache();

            return $this->getSession($sessionId);
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function markClosingDifferenceReviewed(int $adminId, string $adminRole, int $sessionId, array|string $reviewData, ?float $remainingShortage = null): array
    {
        return $this->markReviewed($adminId, $adminRole, $sessionId, $reviewData, $remainingShortage);
    }

    public function reopenClosingDifferenceReview(int $adminId, string $adminRole, int $sessionId, string $reason): array
    {
        return $this->reopenReview($adminId, $adminRole, $sessionId, $reason);
    }

    public function waiveDiscrepancy(int $adminId, string $adminRole, int $sessionId, string $waiverNotes, array $checks = []): void
    {
        $this->markReviewed($adminId, $adminRole, $sessionId, ['explanation' => $waiverNotes] + $checks);
    }

    public function transferExchangeResponsibility(int $actorId, int $transactionId, int $newResponsibleUserId, string $reason, bool $acceptOnly = false): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Enter an explanation for transferring responsibility of at least 5 characters.', 422);
        }
        $targetRole = $this->resolveEligibleRole($newResponsibleUserId);

        try {
            StoreWriteGate::begin($this->pdo);
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $txStmt = $this->pdo->prepare("SELECT * FROM gcash_transactions WHERE transaction_id = ?" . $lock);
            $txStmt->execute([$transactionId]);
            $tx = $txStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tx) {
                throw new DomainException('Transaction not found.', 404);
            }

            if ($acceptOnly) {
                if ($actorId !== $newResponsibleUserId || $tx['verification_status'] !== 'Needs checking' || $tx['responsible_user_id'] !== null) {
                    throw new DomainException('Only unassigned pending exchanges can be accepted by their incoming Cashier.', 409);
                }
                (new \App\Services\CashierShiftService($this->pdo))->requireUnlockedRegister($actorId);
                $targetRole = 'cashier';
            }
            $updateStmt = $this->pdo->prepare(
                "UPDATE gcash_transactions
                 SET responsible_user_id = ?, responsible_role = ?,
                     responsibility_notes = ?, responsibility_assigned_at = CURRENT_TIMESTAMP
                 WHERE transaction_id = ?"
            );
            $updateStmt->execute([$newResponsibleUserId, $targetRole, $reason, $transactionId]);

            \log_activity(
                $this->pdo,
                $actorId,
                'GCash exchange follow-up responsibility transferred',
                'GCash',
                $transactionId,
                ['responsible_user_id' => $tx['responsible_user_id'], 'responsible_role' => $tx['responsible_role']],
                [
                    'responsible_user_id' => $newResponsibleUserId,
                    'responsible_role' => $targetRole,
                    'reason' => $reason,
                    'investigation_responsibility_only' => true,
                    'underlying_shortage_ownership_transferred' => false,
                ],
                null,
                AuditRecordCategory::STORE_OPERATION
            );

            $this->pdo->commit();
            \App\Dashboard\StoreOperationsDashboardWorkspace::invalidateCache();

            return [
                'transaction_id' => $transactionId,
                'responsible_user_id' => $newResponsibleUserId,
                'responsible_role' => $targetRole,
                'reason' => $reason,
            ];
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function transferDifferenceResponsibility(int $actorId, int $sessionId, int $newResponsibleUserId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Enter an explanation for transferring responsibility of at least 5 characters.', 422);
        }
        $targetRole = $this->resolveEligibleRole($newResponsibleUserId);

        try {
            StoreWriteGate::begin($this->pdo);
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessStmt = $this->pdo->prepare("SELECT * FROM gcash_service_sessions WHERE session_id = ?" . $lock);
            $sessStmt->execute([$sessionId]);
            $session = $sessStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('Session not found.', 404);
            }
            if ($session['status'] !== 'closed') {
                throw new DomainException('Only closed sessions with differences can have responsibility transferred.', 422);
            }

            $updateStmt = $this->pdo->prepare(
                "UPDATE gcash_service_sessions
                 SET responsible_user_id = ?, responsible_role = ?,
                     responsibility_notes = ?, responsibility_assigned_at = CURRENT_TIMESTAMP
                 WHERE session_id = ?"
            );
            $updateStmt->execute([$newResponsibleUserId, $targetRole, $reason, $sessionId]);

            \log_activity(
                $this->pdo,
                $actorId,
                'GCash closing difference responsibility transferred',
                'GCash',
                $sessionId,
                ['responsible_user_id' => $session['responsible_user_id'], 'responsible_role' => $session['responsible_role']],
                [
                    'responsible_user_id' => $newResponsibleUserId,
                    'responsible_role' => $targetRole,
                    'reason' => $reason,
                    'investigation_responsibility_only' => true,
                    'underlying_shortage_ownership_transferred' => false,
                ],
                null,
                AuditRecordCategory::STORE_OPERATION
            );

            $this->pdo->commit();
            \App\Dashboard\StoreOperationsDashboardWorkspace::invalidateCache();

            return [
                'session_id' => $sessionId,
                'responsible_user_id' => $newResponsibleUserId,
                'responsible_role' => $targetRole,
                'reason' => $reason,
            ];
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function recordFailedExchangeReturn(int $actorId, int $transactionId, array $data): array
    {
        if (function_exists('is_remote_request') && is_remote_request()) {
            throw new DomainException('Recording returns is not permitted during remote monitoring.', 403);
        }

        $returnedCash = filter_var($data['returned_cash'] ?? 0, FILTER_VALIDATE_FLOAT);
        $returnedWallet = filter_var($data['returned_wallet'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($returnedCash === false || $returnedWallet === false || round($returnedCash, 2) !== $returnedCash || round($returnedWallet, 2) !== $returnedWallet) {
            throw new DomainException('Enter return amounts in pesos and centavos.');
        }
        $evidence = trim((string)($data['evidence'] ?? ''));
        $reason = trim((string)($data['reason'] ?? ''));

        if (!is_finite($returnedCash) || !is_finite($returnedWallet) || $returnedCash < 0 || $returnedWallet < 0 || ($returnedCash <= 0 && $returnedWallet <= 0)) {
            throw new DomainException('Enter return evidence and an amount greater than zero.');
        }
        if ($evidence === '') {
            throw new DomainException('Return evidence is required.');
        }
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Enter a cancellation reason of at least 5 characters.');
        }

        $authorizedBy = $this->authorizeAdmin(
            (string)($data['approver_username'] ?? ''),
            (string)($data['approver_password'] ?? '')
        );

        require_once __DIR__ . '/../Services/InventorySubmission.php';
        $submission = new \InventorySubmission($this->pdo, $actorId, 'gcash_return', $data + ['transaction_id' => $transactionId]);
        \App\Store\StoreWriteGate::begin($this->pdo);
        try {
            if (($completed = $submission->completed()) !== null) {
                $this->pdo->commit();
                return $completed;
            }

            $this->fiscalPeriodGuard()->assertOpenNow('gcash_service_sessions', 'GCash failed exchange return');

            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $txStmt = $this->pdo->prepare("SELECT * FROM gcash_transactions WHERE transaction_id = ?" . $lock);
            $txStmt->execute([$transactionId]);
            $tx = $txStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tx) {
                throw new DomainException('Transaction not found.', 404);
            }
            if ($tx['verification_status'] !== 'Needs checking') {
                throw new DomainException('This transaction is no longer in Needs checking status.', 409);
            }

            $responsibleUser = $tx['responsible_user_id'] !== null ? (int)$tx['responsible_user_id'] : (int)$tx['cashier_id'];
            if ($actorId !== $responsibleUser) {
                throw new DomainException('Only the responsible Cashier can record returns for this exchange.', 403);
            }

            $sessStmt = $this->pdo->prepare("SELECT session_id FROM gcash_service_sessions WHERE cashier_id = ? AND status = 'open' ORDER BY session_id DESC LIMIT 1");
            $sessStmt->execute([$actorId]);
            $currentSessionId = $sessStmt->fetchColumn();
            if (!$currentSessionId) {
                throw new DomainException('Cannot record return: responsible Cashier must have an eligible open GCash session in an open Fiscal Period.', 422);
            }
            $currentSessionId = (int)$currentSessionId;

            $remaining = $this->failedExchangeBalance($tx);
            $remainingCash = $remaining['remaining_cash'];
            $remainingWallet = $remaining['remaining_wallet'];
            if ($returnedCash > $remainingCash || $returnedWallet > $remainingWallet) {
                throw new DomainException(sprintf('Return amount exceeds remaining obligation (Cash: PHP %.2f, Wallet: PHP %.2f).', $remainingCash, $remainingWallet));
            }
            // Undo the original movement: received money goes out, sent money comes back in.
            $cashDelta = (float)$tx['service_cash_delta'] < 0 ? $returnedCash : -$returnedCash;
            $walletDelta = (float)$tx['wallet_delta'] < 0 ? $returnedWallet : -$returnedWallet;

            $columns = $this->adjustmentColumns();
            $hasEvidenceCol = in_array('evidence', $columns, true);
            $feeReturned = filter_var($data['fee_returned'] ?? 0, FILTER_VALIDATE_FLOAT);
            if ($feeReturned === false || round($feeReturned, 2) !== $feeReturned) throw new DomainException('Enter the fee return in pesos and centavos.');
            $feeLimit = max(0.0, round((float)($tx['fee_amount'] ?? $tx['gross_fee']) - $remaining['fees_returned'], 2));
            $feeMoney = ($tx['fee_method'] ?? 'cash') === 'wallet' ? $returnedWallet : $returnedCash;
            if (!is_finite($feeReturned) || $feeReturned < 0 || $feeReturned > $feeLimit || $feeReturned > $feeMoney) {
                throw new DomainException('Fee return must be part of the actual money returned and cannot exceed the remaining original fee.');
            }
            $hasFeeDeltaCol = in_array('fee_delta', $columns, true);
            if ($hasEvidenceCol && $hasFeeDeltaCol) {
                $insAdj = $this->pdo->prepare(
                    "INSERT INTO gcash_adjustments (transaction_id, session_id, service_cash_delta, wallet_delta, fee_delta, reason, evidence, authorized_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $insAdj->execute([
                    $transactionId,
                    $currentSessionId,
                    $cashDelta,
                    $walletDelta,
                    -$feeReturned,
                    'Return for #' . $transactionId . ': ' . $reason,
                    $evidence,
                    $authorizedBy,
                ]);
            } elseif ($hasEvidenceCol) {
                $insAdj = $this->pdo->prepare(
                    "INSERT INTO gcash_adjustments (transaction_id, session_id, service_cash_delta, wallet_delta, reason, evidence, authorized_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $insAdj->execute([
                    $transactionId,
                    $currentSessionId,
                    $cashDelta,
                    $walletDelta,
                    'Return for #' . $transactionId . ': ' . $reason,
                    $evidence,
                    $authorizedBy,
                ]);
            } else {
                $insAdj = $this->pdo->prepare(
                    "INSERT INTO gcash_adjustments (transaction_id, session_id, service_cash_delta, wallet_delta, reason, authorized_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $insAdj->execute([
                    $transactionId,
                    $currentSessionId,
                    $cashDelta,
                    $walletDelta,
                    'Return for #' . $transactionId . ' (' . $evidence . '): ' . $reason,
                    $authorizedBy,
                ]);
            }

            if (in_array('recorded_by', $columns, true)) {
                $this->pdo->prepare('UPDATE gcash_adjustments SET recorded_by=? WHERE adjustment_id=?')->execute([$actorId, (int)$this->pdo->lastInsertId()]);
            }
            $newRemainingCash = max(0.0, round($remainingCash - $returnedCash, 2));
            $newRemainingWallet = max(0.0, round($remainingWallet - $returnedWallet, 2));
            $totalRemaining = round($newRemainingCash + $newRemainingWallet, 2);
            $isCompleted = ($totalRemaining == 0.0);

            $existingNotes = (string)($tx['notes'] ?? '');
            if ($isCompleted) {
                $resolvedNotes = $existingNotes . ' [Fully Returned & Cancelled: ' . $reason . ' (Evidence: ' . $evidence . ')]';
                $upd = $this->pdo->prepare("UPDATE gcash_transactions SET verification_status = 'Rejected', notes = ? WHERE transaction_id = ?");
                $upd->execute([$resolvedNotes, $transactionId]);
            } else {
                $partialNotes = $existingNotes . sprintf(' [Partial Return: Cash -%.2f, Wallet -%.2f, Remaining: %.2f]', $returnedCash, $returnedWallet, $totalRemaining);
                $upd = $this->pdo->prepare("UPDATE gcash_transactions SET notes = ? WHERE transaction_id = ?");
                $upd->execute([$partialNotes, $transactionId]);
            }

            \log_activity(
                $this->pdo,
                $actorId,
                $isCompleted ? 'GCash failed exchange fully returned' : 'GCash failed exchange partial return recorded',
                'GCash',
                $transactionId,
                ['remaining_obligation' => $remainingCash + $remainingWallet],
                [
                    'returned_cash' => $returnedCash,
                    'returned_wallet' => $returnedWallet,
                    'remaining_obligation' => $totalRemaining,
                    'evidence' => $evidence,
                    'reason' => $reason,
                    'session_id' => $currentSessionId,
                    'authorized_by' => $authorizedBy,
                    'completed' => $isCompleted,
                ],
                null,
                AuditRecordCategory::STORE_OPERATION
            );

            $result = [
                'transaction_id' => $transactionId,
                'completed' => $isCompleted,
                'returned_cash' => $returnedCash,
                'returned_wallet' => $returnedWallet,
                'remaining_obligation' => $totalRemaining,
                'session_id' => $currentSessionId,
            ];
            $submission->remember($result);
            $this->pdo->commit();
            \App\Dashboard\StoreOperationsDashboardWorkspace::invalidateCache();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
