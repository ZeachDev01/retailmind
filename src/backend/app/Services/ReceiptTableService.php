<?php

namespace App\Services;

use App\Store\StoreScope;
use DateTimeImmutable;
use PDO;

final class ReceiptTableService
{
    private const DEFAULT_PAGE_LENGTH = 25;
    private const ALLOWED_PAGE_LENGTHS = [10, 25, 50, 100];
    // Column indices match the receipts table order. Ticket #89 appended
    // "Cashier Shift" after "Reversals" rather than inserting it beside
    // "Cashier", so the pre-existing indices (and their contract) are unchanged.
    private const ORDER_COLUMNS = [
        0 => 's.sale_id',
        1 => 's.sale_date',
        2 => 'u.full_name',
        3 => 'item_count',
        4 => 's.total_amount',
        5 => 's.payment_method',
        6 => 'reversal_count',
        7 => 's.shift_id',
    ];
    private const REVERSAL_STATUSES = ['pending', 'approved', 'rejected', 'none'];

    private readonly StoreScope $storeScope;

    public function __construct(private readonly PDO $pdo, ?StoreScope $storeScope = null)
    {
        $this->storeScope = $storeScope ?? new StoreScope($pdo);
    }

    /**
     * Return one DataTables server-side response inside the server-resolved Store scope.
     *
     * The optional cashier identifier is an authorization decision made by the caller;
     * Store identity is never accepted from request or session input.
     *
     * @param array<string, mixed> $request
     * @return array{draw: int, recordsTotal: int, recordsFiltered: int, data: array<int, array<string, mixed>>}
     */
    public function fetch(array $request, ?int $cashierId = null): array
    {
        $normalized = $this->normalizeRequest($request);
        [$scopeClauses, $scopeParams] = $this->scopeConditions($cashierId);
        [$filterClauses, $filterParams] = $this->filterConditions($normalized);

        $recordsTotal = $this->countReceipts($scopeClauses, $scopeParams);
        $recordsFiltered = $this->countReceipts(
            array_merge($scopeClauses, $filterClauses),
            array_merge($scopeParams, $filterParams)
        );

        $whereClauses = array_merge($scopeClauses, $filterClauses);
        $whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
        $orderSql = $this->orderSql($normalized['order_column'], $normalized['order_direction']);
        $sql = "SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date,
                    u.full_name AS cashier_name,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.sale_id) AS item_count,
                    COALESCE(reversal_summary.pending_count, 0) AS pending_reversals,
                    COALESCE(reversal_summary.approved_count, 0) AS approved_reversals,
                    COALESCE(reversal_summary.rejected_count, 0) AS rejected_reversals,
                    COALESCE(reversal_summary.reversal_count, 0) AS reversal_count,
                    COALESCE(refund_summary.refunded_amount, 0) AS refunded_amount,
                    COALESCE(refund_summary.refund_count, 0) AS refund_count,
                    -- Ticket #89: the Cashier Shift a sale ran under and the
                    -- Register that shift anchors, both read by joining the sale
                    -- to its shift. A sale with no shift predates the deployment
                    -- cutoff and is reported as Legacy / Unassigned rather than
                    -- linked to a shift that may not be the one that took the
                    -- money, so neither value is ever inferred.
                    s.shift_id AS shift_id,
                    attribution_shift.register_id AS register_id,
                    attribution_register.name AS register_name
                FROM sales s
                JOIN users u ON u.user_id = s.cashier_id
                LEFT JOIN (
                    SELECT sale_id,
                        SUM(status = 'pending') AS pending_count,
                        SUM(status = 'approved') AS approved_count,
                        SUM(status = 'rejected') AS rejected_count,
                        COUNT(*) AS reversal_count
                    FROM sale_reversals
                    GROUP BY sale_id
                ) reversal_summary ON reversal_summary.sale_id = s.sale_id
                LEFT JOIN (
                    SELECT sale_id, SUM(refund_amount) AS refunded_amount, COUNT(*) AS refund_count
                    FROM cash_refunds GROUP BY sale_id
                ) refund_summary ON refund_summary.sale_id = s.sale_id
                LEFT JOIN cashier_shifts attribution_shift ON attribution_shift.shift_id = s.shift_id
                LEFT JOIN registers attribution_register ON attribution_register.register_id = attribution_shift.register_id
                {$whereSql}
                ORDER BY {$orderSql}
                LIMIT :limit OFFSET :offset";

        $statement = $this->pdo->prepare($sql);
        $this->bindValues($statement, array_merge($scopeParams, $filterParams));
        $statement->bindValue(':limit', $normalized['length'], PDO::PARAM_INT);
        $statement->bindValue(':offset', $normalized['start'], PDO::PARAM_INT);
        $statement->execute();

        $rows = array_map([$this, 'normalizeRow'], $statement->fetchAll(PDO::FETCH_ASSOC));

        return [
            'draw' => $normalized['draw'],
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeRequest(array $request): array
    {
        $draw = max(0, filter_var($request['draw'] ?? 0, FILTER_VALIDATE_INT) ?: 0);
        $start = max(0, filter_var($request['start'] ?? 0, FILTER_VALIDATE_INT) ?: 0);
        $requestedLength = filter_var($request['length'] ?? self::DEFAULT_PAGE_LENGTH, FILTER_VALIDATE_INT);
        $length = in_array($requestedLength, self::ALLOWED_PAGE_LENGTHS, true)
            ? $requestedLength
            : self::DEFAULT_PAGE_LENGTH;
        $search = trim((string)($request['search']['value'] ?? ''));
        $search = function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100);
        $dateFrom = $this->validDate((string)($request['date_from'] ?? ''));
        $dateTo = $this->validDate((string)($request['date_to'] ?? ''));
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            $dateFrom = null;
            $dateTo = null;
        }

        $cashierId = filter_var($request['cashier_id'] ?? null, FILTER_VALIDATE_INT);
        $cashierId = is_int($cashierId) && $cashierId > 0 ? $cashierId : null;
        $reversalStatus = strtolower(trim((string)($request['reversal_status'] ?? '')));
        $reversalStatus = in_array($reversalStatus, self::REVERSAL_STATUSES, true) ? $reversalStatus : null;

        $orderColumn = filter_var($request['order'][0]['column'] ?? null, FILTER_VALIDATE_INT);
        $orderColumn = is_int($orderColumn) && isset(self::ORDER_COLUMNS[$orderColumn]) ? $orderColumn : null;
        $orderDirection = strtolower((string)($request['order'][0]['dir'] ?? ''));
        $orderDirection = in_array($orderDirection, ['asc', 'desc'], true) ? $orderDirection : null;

        return [
            'draw' => $draw,
            'start' => $start,
            'length' => $length,
            'search' => $search,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'cashier_id' => $cashierId,
            'reversal_status' => $reversalStatus,
            'order_column' => $orderColumn,
            'order_direction' => $orderDirection,
        ];
    }

    private function validDate(string $value): ?string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** @return array{0: array<int, string>, 1: array<string, int|string>} */
    private function scopeConditions(?int $cashierId): array
    {
        $clauses = [
            '(u.branch_id = :scope_store_id OR EXISTS (
                SELECT 1 FROM sale_items scope_si
                JOIN products scope_p ON scope_p.product_id = scope_si.product_id
                WHERE scope_si.sale_id = s.sale_id AND scope_p.branch_id = :scope_store_product_id
            ))',
        ];
        $storeId = $this->storeScope->id();
        $params = [':scope_store_id' => $storeId, ':scope_store_product_id' => $storeId];
        if ($cashierId !== null) {
            $clauses[] = 's.cashier_id = :scope_cashier_id';
            $params[':scope_cashier_id'] = $cashierId;
        }
        return [$clauses, $params];
    }

    /**
     * @param array<string, mixed> $request
     * @return array{0: array<int, string>, 1: array<string, int|string>}
     */
    private function filterConditions(array $request): array
    {
        $clauses = [];
        $params = [];
        if ($request['search'] !== '') {
            $clauses[] = '(CAST(s.sale_id AS CHAR) LIKE :search_receipt OR s.payment_method LIKE :search_payment)';
            $params[':search_receipt'] = '%' . $request['search'] . '%';
            $params[':search_payment'] = '%' . $request['search'] . '%';
        }
        if ($request['date_from'] !== null) {
            $clauses[] = 's.sale_date >= :date_from';
            $params[':date_from'] = $request['date_from'] . ' 00:00:00';
        }
        if ($request['date_to'] !== null) {
            $clauses[] = 's.sale_date < DATE_ADD(:date_to, INTERVAL 1 DAY)';
            $params[':date_to'] = $request['date_to'];
        }
        if ($request['cashier_id'] !== null) {
            $clauses[] = 's.cashier_id = :filter_cashier_id';
            $params[':filter_cashier_id'] = $request['cashier_id'];
        }
        if ($request['reversal_status'] === 'none') {
            $clauses[] = 'COALESCE(reversal_summary.reversal_count, 0) = 0';
        } elseif ($request['reversal_status'] !== null) {
            $column = $request['reversal_status'] . '_count';
            $clauses[] = "COALESCE(reversal_summary.{$column}, 0) > 0";
        }
        return [$clauses, $params];
    }

    /** @param array<int, string> $whereClauses @param array<string, int|string> $params */
    private function countReceipts(array $whereClauses, array $params): int
    {
        $whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM sales s
             JOIN users u ON u.user_id = s.cashier_id
             LEFT JOIN (
                SELECT sale_id,
                    SUM(status = 'pending') AS pending_count,
                    SUM(status = 'approved') AS approved_count,
                    SUM(status = 'rejected') AS rejected_count,
                    COUNT(*) AS reversal_count
                FROM sale_reversals
                GROUP BY sale_id
             ) reversal_summary ON reversal_summary.sale_id = s.sale_id
             {$whereSql}"
        );
        $this->bindValues($statement, $params);
        $statement->execute();
        return (int)$statement->fetchColumn();
    }

    private function orderSql(?int $column, ?string $direction): string
    {
        if ($column === null || $direction === null) {
            return 's.sale_date DESC, s.sale_id DESC';
        }
        return self::ORDER_COLUMNS[$column] . ' ' . strtoupper($direction) . ', s.sale_id DESC';
    }

    /** @param array<string, int|string> $params */
    private function bindValues(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeRow(array $row): array
    {
        foreach (['sale_id', 'cashier_id', 'item_count', 'pending_reversals', 'approved_reversals', 'rejected_reversals', 'reversal_count', 'refund_count'] as $key) {
            $row[$key] = (int)$row[$key];
        }
        $row['total_amount'] = (float)$row['total_amount'];
        $row['refunded_amount'] = (float)$row['refunded_amount'];

        // Ticket #89: a sale with no Cashier Shift is shown as Legacy /
        // Unassigned. It is never given a shift or a Register it did not run
        // under, so the label states the absence rather than hiding it.
        $legacy = $row['shift_id'] === null;
        $row['shift_id'] = $legacy ? null : (int)$row['shift_id'];
        $row['register_id'] = $row['register_id'] === null ? null : (int)$row['register_id'];
        $row['register_name'] = $row['register_name'] === null ? null : (string)$row['register_name'];
        $row['attribution_label'] = $legacy ? 'Legacy / Unassigned' : 'Shift #' . $row['shift_id'];

        return $row;
    }
}
