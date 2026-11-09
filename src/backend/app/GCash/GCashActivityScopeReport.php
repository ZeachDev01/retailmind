<?php

declare(strict_types=1);

namespace App\GCash;

use App\Services\PhilippineTime;
use DomainException;
use PDO;
use Throwable;

/**
 * GCash Activity Scope read module. Filtered exchange/reporting queries only;
 * never returns GCash Live Custody/session-balance facts (ADR-0025).
 */
final class GCashActivityScopeReport
{
    public function __construct(private PDO $pdo)
    {
    }

    public function cashierHistory(int $cashierId, int $page): array
    {
        $offset = (max(1, min(100000, $page)) - 1) * 25;
        $stmt = $this->pdo->prepare(
            'SELECT t.*, u.display_name AS cashier_name, u.username AS cashier_username
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
            $adjStmt = $this->pdo->query("SELECT * FROM gcash_adjustments WHERE transaction_id IN ({$inClause}) ORDER BY adjustment_id ASC");
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

    /** Filtered Activity Scope/reporting parts for admin overview assembly (ADR-0025). */
    public function filteredOverview(array $filters): array
    {
        [
            'period' => $period, 'startDate' => $startDate, 'endDate' => $endDate, 'cashierId' => $cashierId,
            'activityConditions' => $whereConditions, 'activityParams' => $activityParams,
            'countsWhere' => $countsWhere, 'countsParams' => $countsParams,
            'whereSql' => $whereSql, 'whereParams' => $whereParams,
        ] = $this->reportingSelection($filters);
        $activityWhere = implode(' AND ', [...$whereConditions, "t.verification_status = 'Completed'", 't.session_id IS NOT NULL']);
        $activityStmt = $this->pdo->prepare("SELECT COUNT(*) AS completed_exchange_count,
            COALESCE(SUM(CASE WHEN t.transaction_type = 'Cash-In' THEN t.principal_amount ELSE 0 END), 0) AS cash_in_principal,
            COALESCE(SUM(CASE WHEN t.transaction_type = 'Cash-Out' THEN t.principal_amount ELSE 0 END), 0) AS cash_out_principal,
            COALESCE(SUM(CASE WHEN t.fee_method = 'cash' THEN t.gross_fee ELSE 0 END), 0) AS gross_fees_cash,
            COALESCE(SUM(CASE WHEN t.fee_method = 'wallet' THEN t.gross_fee ELSE 0 END), 0) AS gross_fees_wallet
            FROM gcash_transactions t WHERE {$activityWhere}");
        $activityStmt->execute($activityParams);
        $activity = $activityStmt->fetch(PDO::FETCH_ASSOC);
        $countsStmt = $this->pdo->prepare("SELECT COUNT(*) AS all_count,
            COALESCE(SUM(t.transaction_type = 'Cash-In'), 0) AS cash_in_count,
            COALESCE(SUM(t.transaction_type = 'Cash-Out'), 0) AS cash_out_count
            FROM gcash_transactions t JOIN users u ON t.cashier_id = u.user_id {$countsWhere}");
        $countsStmt->execute($countsParams);
        $typeCounts = $countsStmt->fetch(PDO::FETCH_ASSOC);

        $totalsStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total_transactions
            FROM gcash_transactions t JOIN users u ON t.cashier_id = u.user_id {$whereSql}"
        );
        $totalsStmt->execute($whereParams);
        $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
        $cashInPrincipal = round((float)$activity['cash_in_principal'], 2);
        $cashOutPrincipal = round((float)$activity['cash_out_principal'], 2);
        $grossFeesCash = round((float)$activity['gross_fees_cash'], 2);
        $grossFeesWallet = round((float)$activity['gross_fees_wallet'], 2);
        $totalTransactions = (int)($totals['total_transactions'] ?? 0);

        $startBound = PhilippineTime::dayStart($startDate);
        $endBound = PhilippineTime::dayAfter($endDate);
        $hasFeeDelta = false;
        try {
            $hasFeeDelta = in_array('fee_delta', GCashSchema::columns($this->pdo, 'gcash_adjustments'), true);
        } catch (Throwable) {}
        $feesReturned = 0.00;
        if ($hasFeeDelta) {
            $retStmt = $this->pdo->prepare("SELECT COALESCE(SUM(-fee_delta), 0) FROM gcash_adjustments WHERE fee_delta < 0 AND created_at >= ? AND created_at < ?");
            $retStmt->execute([$startBound, $endBound]);
            $feesReturned = round((float)$retStmt->fetchColumn(), 2);
        }
        $feesCharged = round($grossFeesCash + $grossFeesWallet, 2);
        $feesAfterReturns = round($feesCharged - $feesReturned, 2);

        $page = max(1, min(100000, (int)($filters['page'] ?? 1)));
        $limit = max(10, min(100, (int)($filters['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $rows = $this->reportingTransactions($whereSql, $whereParams, $limit + 1, $offset);
        $hasNext = count($rows) > $limit;
        $txs = array_slice($rows, 0, $limit);

        $cashiers = [];
        try {
            $cashierStmt = $this->pdo->query(
                "SELECT DISTINCT u.user_id, u.display_name, u.username
                 FROM users u
                 JOIN roles r ON u.role_id = r.role_id
                 WHERE r.role_name = 'cashier' OR u.user_id IN (SELECT DISTINCT cashier_id FROM gcash_transactions)
                 ORDER BY u.display_name ASC"
            );
            $cashiers = $cashierStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}

        return [
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
            'fees_charged' => $feesCharged,
            'fees_returned' => $feesReturned,
            'fees_after_returns' => $feesAfterReturns,
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

        $effectiveExpr = "(CASE WHEN t.verification_status = 'Completed' AND t.completed_at IS NOT NULL THEN t.completed_at ELSE t.created_at END)";
        if ($startDate !== null) {
            $whereConditions[] = "{$effectiveExpr} >= ?";
            $whereParams[] = $startBound;
        }
        if ($endDate !== null) {
            $whereConditions[] = "{$effectiveExpr} < ?";
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
            $whereConditions[] = "(t.reference_number LIKE ? ESCAPE '!' OR CONCAT('#', t.shift_id) LIKE ? ESCAPE '!' OR u.display_name LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!')";
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
        $txSql = "SELECT t.*, u.display_name AS cashier_name, u.username AS cashier_username
                  FROM gcash_transactions t
                  JOIN users u ON t.cashier_id = u.user_id
                  {$whereSql}
                  ORDER BY (CASE WHEN t.verification_status = 'Completed' AND t.completed_at IS NOT NULL THEN t.completed_at ELSE t.created_at END) DESC, t.transaction_id DESC
                  LIMIT " . $limit . " OFFSET {$offset}";
        $txStmt = $this->pdo->prepare($txSql);
        $txStmt->execute($whereParams);
        $txs = $txStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($txs !== []) {
            $ids = array_map(static fn($r) => (int)$r['transaction_id'], $txs);
            $inClause = implode(',', $ids);
            $adjStmt = $this->pdo->query("SELECT * FROM gcash_adjustments WHERE transaction_id IN ({$inClause}) ORDER BY adjustment_id ASC");
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
}
