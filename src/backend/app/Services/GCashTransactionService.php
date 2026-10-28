<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Store\StoreWriteGate;
use DomainException;
use PDO;
use PDOException;
use Throwable;

require_once __DIR__ . '/FiscalPeriodGuardService.php';

final class GCashTransactionService
{
    private ?\FiscalPeriodGuardService $fiscalPeriodGuard = null;

    public function __construct(private ?PDO $pdo = null, private array $rules = [])
    {
        if ($pdo) {
            $this->fiscalPeriodGuard = new \FiscalPeriodGuardService($pdo);
        }
    }

    private function pdo(): PDO
    {
        if (!$this->pdo) {
            throw new DomainException('Database connection required for this operation.');
        }
        return $this->pdo;
    }

    private function fiscalPeriodGuard(): \FiscalPeriodGuardService
    {
        if (!$this->fiscalPeriodGuard) {
            $this->fiscalPeriodGuard = new \FiscalPeriodGuardService($this->pdo());
        }
        return $this->fiscalPeriodGuard;
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

        $ncSql = "SELECT t.*, u.full_name AS cashier_name, u.username AS cashier_username,
                         ru.full_name AS responsible_user_name, ru.username AS responsible_username
                  FROM gcash_transactions t
                  JOIN users u ON t.cashier_id = u.user_id
                  LEFT JOIN users ru ON t.responsible_user_id = ru.user_id
                  WHERE (t.responsible_user_id = ? OR (t.responsible_user_id IS NULL AND t.cashier_id = ?))
                    AND t.verification_status = 'Needs checking'";
        $ncParams = [$cashierId, $cashierId];
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
            $nc['responsible_user_id'] = $nc['responsible_user_id'] !== null ? (int)$nc['responsible_user_id'] : null;
            $nc['responsible_user_name'] = $nc['responsible_user_name'] ?? $nc['responsible_username'] ?? null;
            $nc['responsible_role'] = $nc['responsible_role'] ?? null;
            $nc['responsible_label'] = $nc['responsible_user_id'] !== null
                ? ($nc['responsible_user_name'] ?? $nc['responsible_username']) . ' (' . ucfirst($nc['responsible_role'] ?? '') . ')'
                : 'Needs a responsible Cashier';
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
                $tx['has_receipt'] = $tx['verification_status'] === 'Completed' && $tx['receipt_details_json'] !== null;
                unset($tx['receipt_details_json']);
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

    private function reportingRole(array $filters): ?string
    {
        return (function_exists('current_role') ? current_role() : null) ?? ($_SESSION['role'] ?? null) ?? ($filters['actor_role'] ?? null);
    }

    private function reportingSelection(array $filters): array
    {
        $period = (string)($filters['period'] ?? (isset($filters['start_date']) || isset($filters['end_date']) ? 'custom' : 'today'));
        if (!in_array($period, ['today', 'month_to_date', 'custom'], true)) {
            throw new DomainException('Select Today, Month-to-date, or Custom dates.');
        }
        $today = PhilippineTime::today();
        $startDate = $period === 'custom' ? trim((string)($filters['start_date'] ?? '')) : ($period === 'month_to_date' ? substr($today, 0, 7) . '-01' : $today);
        $endDate = $period === 'custom' ? trim((string)($filters['end_date'] ?? '')) : $today;
        $cashierId = isset($filters['cashier_id']) && $filters['cashier_id'] !== '' ? filter_var($filters['cashier_id'], FILTER_VALIDATE_INT) : null;
        if ($cashierId !== null && ($cashierId === false || $cashierId <= 0)) {
            throw new DomainException('Select a valid Cashier or All Cashiers.');
        }

        try {
            $startBound = PhilippineTime::dayStart($startDate);
            $endBound = PhilippineTime::dayAfter($endDate);
        } catch (\InvalidArgumentException) {
            throw new DomainException('Choose valid start and end Philippine calendar dates.');
        }
        if ($startDate > $endDate) {
            throw new DomainException('Start date cannot be after end date.');
        }

        $whereConditions = [];
        $whereParams = [];

        if ($startDate !== null) {
            $whereConditions[] = 't.created_at >= ?';
            $whereParams[] = $startBound;
        }
        if ($endDate !== null) {
            $whereConditions[] = 't.created_at < ?';
            $whereParams[] = $endBound;
        }
        if ($cashierId !== null) {
            $whereConditions[] = 't.cashier_id = ?';
            $whereParams[] = $cashierId;
        }
        $activityConditions = $whereConditions;
        $activityParams = $whereParams;
        $search = trim((string)($filters['search'] ?? ''));
        if (strlen($search) > 200) {
            throw new DomainException('Search must be 200 characters or fewer.');
        }
        $type = (string)($filters['type'] ?? 'all');
        if (!in_array($type, ['all', 'Cash-In', 'Cash-Out'], true)) {
            throw new DomainException('Select All, Cash-In, or Cash-Out.');
        }
        if ($search !== '') {
            $whereConditions[] = "(t.reference_number LIKE ? ESCAPE '!' OR CONCAT('#', t.shift_id) LIKE ? ESCAPE '!' OR u.full_name LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!')";
            $term = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            array_push($whereParams, $term, $term, $term, $term);
        }
        $countsWhere = 'WHERE ' . implode(' AND ', $whereConditions);
        $countsParams = $whereParams;
        $whereSql = $countsWhere;
        if ($type !== 'all') {
            $whereConditions[] = 't.transaction_type = ?';
            $whereParams[] = $type;
            $whereSql = 'WHERE ' . implode(' AND ', $whereConditions);
        }

        return compact('period', 'startDate', 'endDate', 'cashierId', 'activityConditions', 'activityParams', 'countsWhere', 'countsParams', 'whereSql', 'whereParams');
    }

    private function reportingTransactions(string $whereSql, array $whereParams, int $limit, int $offset = 0): array
    {
        $txSql = "SELECT t.*, u.full_name AS cashier_name, u.username AS cashier_username
                  FROM gcash_transactions t
                  JOIN users u ON t.cashier_id = u.user_id
                  {$whereSql}
                  ORDER BY t.transaction_id DESC
                  LIMIT " . $limit . " OFFSET {$offset}";
        $txStmt = $this->pdo()->prepare($txSql);
        $txStmt->execute($whereParams);
        $txs = $txStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($txs !== []) {
            $ids = array_map(static fn($r) => (int)$r['transaction_id'], $txs);
            $inClause = implode(',', $ids);
            $adjStmt = $this->pdo()->query("SELECT * FROM gcash_adjustments WHERE transaction_id IN ({$inClause}) ORDER BY adjustment_id ASC");
            $adjsByTx = [];
            foreach ($adjStmt->fetchAll(PDO::FETCH_ASSOC) as $adj) {
                $adjsByTx[$adj['transaction_id']][] = $adj;
            }
            foreach ($txs as &$tx) {
                $tx['has_receipt'] = $tx['verification_status'] === 'Completed' && $tx['receipt_details_json'] !== null;
                unset($tx['receipt_details_json']);
                $tx['adjustments'] = $adjsByTx[$tx['transaction_id']] ?? [];
                $isLegacy = ($tx['session_id'] === null);
                $tx['is_legacy'] = $isLegacy;
                if ($isLegacy) {
                    $tx['shift_id'] = null;
                    $tx['principal_amount'] = null;
                    $tx['fee_amount'] = null;
                    $tx['fee_method'] = null;
                    $tx['gross_fee'] = 0.00;
                    $tx['fee_location'] = null;
                } else {
                    $tx['principal_amount'] = $tx['principal_amount'] !== null ? (float)$tx['principal_amount'] : null;
                    $tx['fee_amount'] = $tx['fee_amount'] !== null ? (float)$tx['fee_amount'] : null;
                    $tx['gross_fee'] = (float)$tx['gross_fee'];
                    $tx['service_cash_delta'] = (float)$tx['service_cash_delta'];
                    $tx['wallet_delta'] = (float)$tx['wallet_delta'];
                    if ($tx['fee_method'] === 'cash') {
                        $tx['fee_location'] = 'cash';
                    } elseif ($tx['fee_method'] === 'wallet') {
                        $tx['fee_location'] = 'wallet';
                    } elseif ($tx['transaction_type'] === 'Cash-In') {
                        $tx['fee_location'] = 'cash';
                    } else {
                        $tx['fee_location'] = null;
                    }
                }
                $tx['created_at_formatted'] = PhilippineTime::format($tx['created_at']);
                $tx['reviewed_at_formatted'] = $tx['reviewed_at'] ? PhilippineTime::format($tx['reviewed_at']) : null;
                $tx['completed_at_formatted'] = $tx['completed_at'] ? PhilippineTime::format($tx['completed_at']) : null;
                $tx['time_formatted'] = PhilippineTime::format($tx['completed_at'] ?? $tx['reviewed_at'] ?? $tx['created_at']);
            }
            unset($tx);
        }

        return $txs;
    }

    /** Yields matching history in descending transaction order, using bounded batches. */
    public function adminTransactions(array $filters = []): \Generator
    {
        if (!in_array($this->reportingRole($filters), ['admin', 'super_admin'], true)) {
            throw new DomainException('Administrator authorization required.', 403);
        }
        $selection = $this->reportingSelection($filters);
        $beforeId = null;
        do {
            $whereSql = $selection['whereSql'];
            $whereParams = $selection['whereParams'];
            if ($beforeId !== null) {
                $whereSql .= ' AND t.transaction_id < ?';
                $whereParams[] = $beforeId;
            }
            $rows = $this->reportingTransactions($whereSql, $whereParams, 100);
            foreach ($rows as $row) {
                $beforeId = (int)$row['transaction_id'];
                yield $row;
            }
        } while (count($rows) === 100);
    }

    public function overview(array $filters = []): array
    {
        return $this->adminOverview($filters);
    }

    public function adminOverview(array $filters = []): array
    {
        $role = $this->reportingRole($filters);
        if (!in_array($role, ['admin', 'super_admin'], true)) {
            if (!empty($filters['approver_username']) && !empty($filters['approver_password'])) {
                $this->authorizeAdmin($filters['approver_username'], $filters['approver_password']);
            } else {
                throw new DomainException('Administrator authorization required.', 403);
            }
        }

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
            $closedStmt = $this->pdo()->prepare(
                "SELECT s.*, u.full_name AS cashier_name, u.username AS cashier_username
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

        [
            'period' => $period, 'startDate' => $startDate, 'endDate' => $endDate, 'cashierId' => $cashierId,
            'activityConditions' => $whereConditions, 'activityParams' => $activityParams,
            'countsWhere' => $countsWhere, 'countsParams' => $countsParams,
            'whereSql' => $whereSql, 'whereParams' => $whereParams,
        ] = $this->reportingSelection($filters);
        $activityWhere = implode(' AND ', [...$whereConditions, "t.verification_status = 'Completed'", 't.session_id IS NOT NULL']);
        $activityStmt = $this->pdo()->prepare("SELECT COUNT(*) AS completed_exchange_count,
            COALESCE(SUM(CASE WHEN t.transaction_type = 'Cash-In' THEN t.principal_amount ELSE 0 END), 0) AS cash_in_principal,
            COALESCE(SUM(CASE WHEN t.transaction_type = 'Cash-Out' THEN t.principal_amount ELSE 0 END), 0) AS cash_out_principal,
            COALESCE(SUM(CASE WHEN t.fee_method = 'cash' THEN t.gross_fee ELSE 0 END), 0) AS gross_fees_cash,
            COALESCE(SUM(CASE WHEN t.fee_method = 'wallet' THEN t.gross_fee ELSE 0 END), 0) AS gross_fees_wallet
            FROM gcash_transactions t WHERE {$activityWhere}");
        $activityStmt->execute($activityParams);
        $activity = $activityStmt->fetch(PDO::FETCH_ASSOC);
        $countsStmt = $this->pdo()->prepare("SELECT COUNT(*) AS all_count,
            COALESCE(SUM(t.transaction_type = 'Cash-In'), 0) AS cash_in_count,
            COALESCE(SUM(t.transaction_type = 'Cash-Out'), 0) AS cash_out_count
            FROM gcash_transactions t JOIN users u ON t.cashier_id = u.user_id {$countsWhere}");
        $countsStmt->execute($countsParams);
        $typeCounts = $countsStmt->fetch(PDO::FETCH_ASSOC);

        $totalsStmt = $this->pdo()->prepare(
            "SELECT
                COUNT(*) AS total_transactions
            FROM gcash_transactions t JOIN users u ON t.cashier_id = u.user_id
            {$whereSql}"
        );
        $totalsStmt->execute($whereParams);
        $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
        $cashInPrincipal = round((float)$activity['cash_in_principal'], 2);
        $cashOutPrincipal = round((float)$activity['cash_out_principal'], 2);
        $grossFeesCash = round((float)$activity['gross_fees_cash'], 2);
        $grossFeesWallet = round((float)$activity['gross_fees_wallet'], 2);
        $totalTransactions = (int)($totals['total_transactions'] ?? 0);

        $ncSql = "SELECT t.*, u.full_name AS cashier_name, u.username AS cashier_username,
                         ru.full_name AS responsible_user_name, ru.username AS responsible_username
                  FROM gcash_transactions t
                  JOIN users u ON t.cashier_id = u.user_id
                  LEFT JOIN users ru ON t.responsible_user_id = ru.user_id
                  WHERE t.verification_status = 'Needs checking'";
        $ncSql .= " ORDER BY t.transaction_id DESC";
        $ncStmt = $this->pdo()->prepare($ncSql);
        $ncStmt->execute();
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

        $diffSql = "SELECT s.*,
                           u.full_name AS cashier_name,
                           u.username AS cashier_username,
                           cb.full_name AS closed_by_name,
                           wb.full_name AS waived_by_name,
                           ru.full_name AS responsible_user_name,
                           ru.username AS responsible_username
                    FROM gcash_service_sessions s
                    JOIN users u ON s.cashier_id = u.user_id
                    LEFT JOIN users cb ON s.closed_by = cb.user_id
                    LEFT JOIN users wb ON s.waived_by = wb.user_id
                    LEFT JOIN users ru ON s.responsible_user_id = ru.user_id
                    WHERE s.status = 'closed'
                      AND (s.cash_variance != 0 OR s.wallet_variance != 0)";
        $diffSql .= " ORDER BY s.session_id DESC";
        $diffStmt = $this->pdo()->prepare($diffSql);
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

        $page = max(1, min(100000, (int)($filters['page'] ?? 1)));
        $limit = max(10, min(100, (int)($filters['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $rows = $this->reportingTransactions($whereSql, $whereParams, $limit + 1, $offset);
        $hasNext = count($rows) > $limit;
        $txs = array_slice($rows, 0, $limit);

        $cashiers = [];
        try {
            $cashierStmt = $this->pdo()->query(
                "SELECT DISTINCT u.user_id, u.full_name, u.username
                 FROM users u
                 JOIN roles r ON u.role_id = r.role_id
                 WHERE r.role_name = 'cashier' OR u.user_id IN (SELECT DISTINCT cashier_id FROM gcash_transactions)
                 ORDER BY u.full_name ASC"
            );
            $cashiers = $cashierStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}

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
            'type_counts' => $typeCounts,

            'filtered_activity' => [
                'completed_exchange_count' => (int)$activity['completed_exchange_count'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'cashier_id' => $cashierId,
                'cash_in_principal' => $cashInPrincipal,
                'cash_out_principal' => $cashOutPrincipal,
                'gross_fees_cash' => $grossFeesCash,
                'gross_fees_wallet' => $grossFeesWallet,
                'gross_fees_total' => round($grossFeesCash + $grossFeesWallet, 2),
                'total_transactions' => $totalTransactions,
            ],
            'completed_exchange_count' => (int)$activity['completed_exchange_count'],
            'matching_record_count' => $totalTransactions,
            'cash_in_principal' => $cashInPrincipal,
            'cash_out_principal' => $cashOutPrincipal,
            'gross_fees_cash' => $grossFeesCash,
            'gross_fees_wallet' => $grossFeesWallet,
            'gross_fees_total' => round($grossFeesCash + $grossFeesWallet, 2),
            'total_transactions' => $totalTransactions,

            'unresolved_activity' => $unresolved,
            'needs_checking' => $unresolved,
            'unresolved_count' => count($unresolved),
            'needs_checking_count' => count($unresolved),

            'reconciliation_differences' => $differences,
            'differences_count' => count(array_filter($differences, static fn(array $difference): bool => empty($difference['is_reviewed']))),

            'transactions' => $txs,
            'has_next' => $hasNext,
            'page' => $page,
            'cashiers' => $cashiers,
            'filters' => [
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'cashier_id' => $cashierId,
            ],
        ];
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
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_service_sessions', 'GCash session opening');
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

    public function closeSession(int $cashierId, float $actualServiceCash, float $actualWalletBalance, string $notes, int $unresolvedCarryover = 0, ?int $responsibleUserId = null, ?string $transferReason = null): array
    {
        if (!is_finite($actualServiceCash) || $actualServiceCash < 0 || !is_finite($actualWalletBalance) || $actualWalletBalance < 0) {
            throw new DomainException('Closing balances must be 0 or greater.');
        }
        try {
            StoreWriteGate::begin($this->pdo());
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_service_sessions', 'GCash session closing');
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

            $update = $this->pdo()->prepare(
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

            \log_activity($this->pdo(), $cashierId, 'GCash session closed', 'GCash', $sessionId,
                ['opening_service_cash' => $session['opening_service_cash'], 'opening_wallet_balance' => $session['opening_wallet_balance']],
                ['expected_service_cash' => $expectedCash, 'expected_wallet_balance' => $expectedWallet, 'closing_service_cash' => $actualServiceCash, 'closing_wallet_balance' => $actualWalletBalance, 'cash_variance' => $cashVariance, 'wallet_variance' => $walletVariance, 'unresolved_carryover_count' => $unresolvedCarryover, 'closing_notes' => $notes],
                null, AuditRecordCategory::STORE_OPERATION);

            $this->pdo()->commit();
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

    public function handoverSession(int $outgoingCashierId, int $incomingCashierId, float $countedCash, float $checkedWallet, string $notes, ?array $acceptedTransactionIds = null, ?int $differenceResponsibleUserId = null): array
    {
        if ($outgoingCashierId === $incomingCashierId) {
            throw new DomainException('Select a different Cashier for handover.');
        }
        if (!is_finite($countedCash) || $countedCash < 0 || !is_finite($checkedWallet) || $checkedWallet < 0) {
            throw new DomainException('Handover balances must be 0 or greater.');
        }
        try {
            StoreWriteGate::begin($this->pdo());
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_service_sessions', 'GCash session handover');
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

            $diffRespId = null;
            $diffRespRole = null;
            if ($cashVariance != 0.0 || $walletVariance != 0.0) {
                $targetDiffUser = $differenceResponsibleUserId ?? $outgoingCashierId;
                try {
                    $diffRespRole = $this->resolveEligibleRole($targetDiffUser);
                    $diffRespId = $targetDiffUser;
                } catch (Throwable) {
                    $diffRespId = null;
                    $diffRespRole = null;
                }
            }

            $closeStmt = $this->pdo()->prepare(
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
                $outgoingCashierId,
                $expectedCash, $expectedWallet,
                $countedCash, $checkedWallet,
                $cashVariance, $walletVariance,
                $notes !== '' ? 'Handover: ' . $notes : 'Handover to Cashier #' . $incomingCashierId,
                $diffRespId,
                $diffRespRole,
                'Handover investigation responsibility: ' . $notes,
                $sessionId,
            ]);

            if ($acceptedTransactionIds !== null) {
                $pendingTxStmt = $this->pdo()->query("SELECT transaction_id, cashier_id, responsible_user_id FROM gcash_transactions WHERE verification_status = 'Needs checking'");
                $pendingTxs = $pendingTxStmt->fetchAll(PDO::FETCH_ASSOC);
                $updateTxStmt = $this->pdo()->prepare(
                    "UPDATE gcash_transactions
                     SET responsible_user_id = ?, responsible_role = ?, responsibility_notes = ?, responsibility_assigned_at = ?
                     WHERE transaction_id = ?"
                );
                foreach ($pendingTxs as $ptx) {
                    $ptxId = (int)$ptx['transaction_id'];
                    if (in_array($ptxId, $acceptedTransactionIds, true)) {
                        $updateTxStmt->execute([
                            $incomingCashierId,
                            'cashier',
                            'Accepted follow-up investigation responsibility via handover',
                            date('Y-m-d H:i:s'),
                            $ptxId
                        ]);
                        \log_activity($this->pdo(), $incomingCashierId, 'GCash exchange follow-up responsibility accepted', 'GCash', $ptxId,
                            ['responsible_user_id' => $ptx['responsible_user_id']],
                            ['responsible_user_id' => $incomingCashierId, 'responsible_role' => 'cashier', 'investigation_responsibility_only' => true, 'underlying_shortage_ownership_transferred' => false, 'clarification' => 'Handover transfers investigation responsibility, not ownership of underlying money shortage'],
                            'Handover transfers investigation responsibility, not ownership of underlying money shortage',
                            AuditRecordCategory::STORE_OPERATION);
                    } else {
                        $updateTxStmt->execute([
                            null,
                            null,
                            'Unaccepted during handover from Cashier #' . $outgoingCashierId,
                            null,
                            $ptxId
                        ]);
                        \log_activity($this->pdo(), $outgoingCashierId, 'GCash exchange follow-up responsibility unassigned', 'GCash', $ptxId,
                            ['responsible_user_id' => $ptx['responsible_user_id']],
                            ['responsible_user_id' => null, 'responsible_role' => null],
                            'Needs a responsible Cashier. Handover transfers investigation responsibility, not ownership of underlying money shortage',
                            AuditRecordCategory::STORE_OPERATION);
                    }
                }
            }

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

    public function emergencyHandover(int $adminId, int $incomingCashierId, float $countedCash, float $checkedWallet, string $reason, ?array $acceptedTransactionIds = null, ?int $differenceResponsibleUserId = null): array
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

            $diffRespId = null;
            $diffRespRole = null;
            if ($cashVariance != 0.0 || $walletVariance != 0.0) {
                $targetDiffUser = $differenceResponsibleUserId ?? $adminId;
                try {
                    $diffRespRole = $this->resolveEligibleRole($targetDiffUser);
                    $diffRespId = $targetDiffUser;
                } catch (Throwable) {
                    $diffRespId = null;
                    $diffRespRole = null;
                }
            }

            $closeStmt = $this->pdo()->prepare(
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
                $adminId,
                $expectedCash, $expectedWallet,
                $countedCash, $checkedWallet,
                $cashVariance, $walletVariance,
                'Emergency Handover: ' . $reason,
                $diffRespId,
                $diffRespRole,
                'Emergency handover investigation responsibility: ' . $reason,
                $sessionId,
            ]);

            if ($acceptedTransactionIds !== null) {
                $pendingTxStmt = $this->pdo()->query("SELECT transaction_id, cashier_id, responsible_user_id FROM gcash_transactions WHERE verification_status = 'Needs checking'");
                $pendingTxs = $pendingTxStmt->fetchAll(PDO::FETCH_ASSOC);
                $updateTxStmt = $this->pdo()->prepare(
                    "UPDATE gcash_transactions
                     SET responsible_user_id = ?, responsible_role = ?, responsibility_notes = ?, responsibility_assigned_at = ?
                     WHERE transaction_id = ?"
                );
                foreach ($pendingTxs as $ptx) {
                    $ptxId = (int)$ptx['transaction_id'];
                    if (in_array($ptxId, $acceptedTransactionIds, true)) {
                        $updateTxStmt->execute([
                            $incomingCashierId,
                            'cashier',
                            'Accepted follow-up investigation responsibility via emergency handover',
                            date('Y-m-d H:i:s'),
                            $ptxId
                        ]);
                        \log_activity($this->pdo(), $incomingCashierId, 'GCash exchange follow-up responsibility accepted', 'GCash', $ptxId,
                            ['responsible_user_id' => $ptx['responsible_user_id']],
                            ['responsible_user_id' => $incomingCashierId, 'responsible_role' => 'cashier', 'investigation_responsibility_only' => true, 'underlying_shortage_ownership_transferred' => false, 'clarification' => 'Handover transfers investigation responsibility, not ownership of underlying money shortage'],
                            'Handover transfers investigation responsibility, not ownership of underlying money shortage',
                            AuditRecordCategory::STORE_OPERATION);
                    } else {
                        $updateTxStmt->execute([
                            null,
                            null,
                            'Unaccepted during emergency handover',
                            null,
                            $ptxId
                        ]);
                        \log_activity($this->pdo(), $adminId, 'GCash exchange follow-up responsibility unassigned', 'GCash', $ptxId,
                            ['responsible_user_id' => $ptx['responsible_user_id']],
                            ['responsible_user_id' => null, 'responsible_role' => null],
                            'Needs a responsible Cashier. Handover transfers investigation responsibility, not ownership of underlying money shortage',
                            AuditRecordCategory::STORE_OPERATION);
                    }
                }
            }

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
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_transactions', 'GCash transaction');
            (new CashierShiftService($this->pdo()))->requireUnlockedRegister($cashierId);

            $session = $this->getCurrentSession();
            $sessionId = null;
            if ($session) {
                if ((int)$session['cashier_id'] !== $cashierId) {
                    throw new DomainException('Another Cashier currently has responsibility for GCash services. Only one Cashier may control GCash at a time.', 409);
                }
                $sessionId = (int)$session['session_id'];
            }

            $stmt = $this->pdo()->prepare("INSERT INTO gcash_transactions (session_id, transaction_type, reference_number, amount, cashier_id, responsible_user_id, responsible_role, responsibility_assigned_at, verification_status) VALUES (?, ?, ?, ?, ?, ?, 'cashier', CURRENT_TIMESTAMP, 'Pending')");
            $stmt->execute([$sessionId, $type, $reference, $amount, $cashierId, $cashierId]);
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
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_transactions', 'GCash Cash-In');
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

            if ($outcome === 'Completed') {
                (new \App\Receipts\GCashReceiptService($this->pdo()))->preserve($id);
            }
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
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_transactions', 'GCash Cash-Out');
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

            if ($outcome === 'Completed') {
                (new \App\Receipts\GCashReceiptService($this->pdo()))->preserve($id);
            }
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
        return SupervisorApprovalService::authorize(
            $this->pdo(),
            $username,
            $password,
            'Administrator authorization failed: invalid credentials.',
            403
        );
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
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_funding_movements', 'GCash funding movement');
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessionStmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE status = 'open'" . $lock);
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
            StoreWriteGate::begin($this->pdo());
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_adjustments', 'GCash correction');
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
        if (function_exists('is_remote_request') && is_remote_request()) {
            throw new DomainException('Resolving exchanges is not permitted during remote monitoring.', 403);
        }
        $action = strtolower(trim($action));
        if (!in_array($action, ['complete', 'cancel', 'reject'], true)) {
            throw new DomainException('Action must be complete or cancel.');
        }

        try {
            StoreWriteGate::begin($this->pdo());
            $this->fiscalPeriodGuard()->assertOpenNow('gcash_service_sessions', 'GCash emergency handover');
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
            if (array_key_exists('responsible_user_id', $tx) && ($tx['responsible_user_id'] === null || empty($tx['responsible_role']))) {
                throw new DomainException('Cannot clear or resolve exchange without an accepted responsible role on file.', 422);
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
                    $this->pdo(),
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

                (new \App\Receipts\GCashReceiptService($this->pdo()))->preserve($transactionId);

                $this->pdo()->commit();
                \App\Dashboard\StoreOperationsDashboardWorkspace::invalidateCache();
                return [
                    'transaction_id' => $transactionId,
                    'status' => 'Completed',
                    'receipt_id' => $transactionId,
                    'verification_status' => 'Completed',
                ];
            } else {
                $userStmt = $this->pdo()->prepare('SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?');
                $userStmt->execute([$actorId]);
                $actorRole = (string)$userStmt->fetchColumn();
                if ($actorRole === 'admin' || $actorRole === 'super_admin') {
                    throw new DomainException('Cancellation authority belongs to the responsible Cashier in Cashier Workspace; Administrator has no cancellation authority.');
                }

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

    private function getSessionColumns(): array
    {
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo()->query("PRAGMA table_info(gcash_service_sessions)");
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
        }
        $stmt = $this->pdo()->query("SHOW COLUMNS FROM gcash_service_sessions");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    }

    public function getSession(int $sessionId): array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new DomainException('GCash service session not found.', 404);
        }
        return $session;
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
            $stmt = $this->pdo()->prepare("UPDATE gcash_service_sessions SET review_draft_notes = ? WHERE session_id = ?");
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
            $explanation = trim($reviewData);
            $cashChecked = true;
            $walletChecked = true;
            $cashExplanation = 'Physical cash verified';
            $walletExplanation = 'GCash wallet verified';
        } else {
            $explanation = trim((string)($reviewData['explanation'] ?? $reviewData['review_notes'] ?? ''));
            $cashChecked = !empty($reviewData['cash_checked']);
            $walletChecked = !empty($reviewData['wallet_checked']);
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
            StoreWriteGate::begin($this->pdo());
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE session_id = ?" . $lock);
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

            $uStmt = $this->pdo()->prepare('SELECT full_name FROM users WHERE user_id = ?');
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
            $update = $this->pdo()->prepare('UPDATE gcash_service_sessions SET ' . implode(', ', $fields) . ' WHERE session_id = ?');
            $update->execute($params);

            \log_activity(
                $this->pdo(),
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

            $this->pdo()->commit();
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
            StoreWriteGate::begin($this->pdo());
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE session_id = ?" . $lock);
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

            $uStmt = $this->pdo()->prepare('SELECT full_name FROM users WHERE user_id = ?');
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
            $update = $this->pdo()->prepare('UPDATE gcash_service_sessions SET ' . implode(', ', $fields) . ' WHERE session_id = ?');
            $update->execute($params);

            \log_activity(
                $this->pdo(),
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

            $this->pdo()->commit();
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

    public function waiveDiscrepancy(int $adminId, string $adminRole, int $sessionId, string $waiverNotes): void
    {
        $this->markReviewed($adminId, $adminRole, $sessionId, [
            'explanation' => $waiverNotes,
            'cash_checked' => true,
            'wallet_checked' => true,
            'cash_explanation' => 'Physical cash counted and verified: ' . $waiverNotes,
            'wallet_explanation' => 'Wallet balance checked: ' . $waiverNotes,
        ]);
    }

    public function resolveEligibleRole(int $userId): string
    {
        $stmt = $this->pdo()->prepare(
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
            $secStmt = $this->pdo()->prepare(
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

    public function transferExchangeResponsibility(int $actorId, int $transactionId, int $newResponsibleUserId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Enter an explanation for transferring responsibility of at least 5 characters.', 422);
        }
        $targetRole = $this->resolveEligibleRole($newResponsibleUserId);

        try {
            StoreWriteGate::begin($this->pdo());
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $txStmt = $this->pdo()->prepare("SELECT * FROM gcash_transactions WHERE transaction_id = ?" . $lock);
            $txStmt->execute([$transactionId]);
            $tx = $txStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tx) {
                throw new DomainException('Transaction not found.', 404);
            }

            $updateStmt = $this->pdo()->prepare(
                "UPDATE gcash_transactions
                 SET responsible_user_id = ?, responsible_role = ?,
                     responsibility_notes = ?, responsibility_assigned_at = CURRENT_TIMESTAMP
                 WHERE transaction_id = ?"
            );
            $updateStmt->execute([$newResponsibleUserId, $targetRole, $reason, $transactionId]);

            \log_activity(
                $this->pdo(),
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
                'Handover transfers investigation responsibility, not ownership of underlying money shortage: ' . $reason,
                AuditRecordCategory::STORE_OPERATION
            );

            $this->pdo()->commit();
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
            StoreWriteGate::begin($this->pdo());
            $lock = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $sessStmt = $this->pdo()->prepare("SELECT * FROM gcash_service_sessions WHERE session_id = ?" . $lock);
            $sessStmt->execute([$sessionId]);
            $session = $sessStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new DomainException('Session not found.', 404);
            }
            if ($session['status'] !== 'closed') {
                throw new DomainException('Only closed sessions with differences can have responsibility transferred.', 422);
            }

            $updateStmt = $this->pdo()->prepare(
                "UPDATE gcash_service_sessions
                 SET responsible_user_id = ?, responsible_role = ?,
                     responsibility_notes = ?, responsibility_assigned_at = CURRENT_TIMESTAMP
                 WHERE session_id = ?"
            );
            $updateStmt->execute([$newResponsibleUserId, $targetRole, $reason, $sessionId]);

            \log_activity(
                $this->pdo(),
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
                'Handover transfers investigation responsibility, not ownership of underlying money shortage: ' . $reason,
                AuditRecordCategory::STORE_OPERATION
            );

            $this->pdo()->commit();
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
}
