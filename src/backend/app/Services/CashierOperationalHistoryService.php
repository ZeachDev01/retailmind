<?php

namespace App\Services;

use InvalidArgumentException;
use PDO;

require_once __DIR__ . '/PhilippineTime.php';
require_once __DIR__ . '/ShiftReconciliationCalculator.php';

/** Read-only, identity-scoped operational records for the active Cashier. */
final class CashierOperationalHistoryService
{
    public const PAGE_SIZE = 10;

    private const REFUND_STATUS_SQL = "CASE WHEN
        (SELECT ROUND(SUM(refund_amount), 2) FROM cash_refunds WHERE sale_id = r.sale_id)
        >= (SELECT total_amount FROM sales WHERE sale_id = r.sale_id)
        THEN 'full' ELSE 'partial' END";

    public static function categoryOptions(string $type): array
    {
        return match ($type) {
            'refunds' => ['partial' => 'Partially refunded', 'full' => 'Fully refunded'],
            'movements' => ['cash_in' => 'Cash in', 'cash_out' => 'Cash out', 'safe_drop' => 'Safe drop'],
            default => [],
        };
    }

    /** @return array<string, array{label: string, id: string, columns: array<string, string>}> */
    public static function types(): array
    {
        return [
            'sales' => ['label' => 'Sales', 'id' => 'sale_id', 'columns' => ['sale_id' => 'Sale', 'shift_id' => 'Shift', 'sale_date' => 'Date', 'total_amount' => 'Amount', 'payment_method' => 'Payment', 'cash_received' => 'Cash received', 'change_due' => 'Change']],
            'refunds' => ['label' => 'Cash Refunds', 'id' => 'refund_id', 'columns' => ['refund_id' => 'Refund', 'sale_id' => 'Sale', 'shift_id' => 'Shift', 'created_at' => 'Date', 'refund_amount' => 'Amount', 'refund_status' => 'Sale refund status', 'payment_method' => 'Payment', 'reason' => 'Reason', 'note' => 'Note']],
            'movements' => ['label' => 'Drawer Movements', 'id' => 'drawer_movement_id', 'columns' => ['drawer_movement_id' => 'Movement', 'shift_id' => 'Shift', 'created_at' => 'Date', 'movement_type' => 'Type', 'amount' => 'Amount', 'reason' => 'Reason', 'note' => 'Note']],
            'shifts' => ['label' => 'Cashier Shifts', 'id' => 'shift_id', 'columns' => ['shift_id' => 'Shift', 'register_id' => 'Register', 'opened_at' => 'Opened', 'closed_at' => 'Closed', 'status' => 'Status', 'opening_cash' => 'Opening cash', 'expected_cash' => 'Expected cash', 'actual_cash' => 'Counted cash', 'cash_variance' => 'Variance', 'closing_notes' => 'Closing notes', 'closed_by' => 'Closed by', 'intervention_reason' => 'Intervention reason']],
        ];
    }

    private const QUERIES = [
        'sales' => [
            'id' => 'sale_id', 'table' => 'sales', 'alias' => 's',
            'columns' => 's.sale_id, s.shift_id, s.sale_date, s.total_amount, s.payment_method, s.cash_received, s.change_due',
            'date' => 's.sale_date',
        ],
        'refunds' => [
            'id' => 'refund_id', 'table' => 'cash_refunds', 'alias' => 'r',
            'columns' => 'r.refund_id, r.sale_id, r.shift_id, r.created_at, r.refund_amount, r.payment_method, r.reason, r.note',
            'date' => 'r.created_at',
        ],
        'movements' => [
            'id' => 'drawer_movement_id', 'table' => 'cash_drawer_movements', 'alias' => 'm',
            'columns' => 'm.drawer_movement_id, m.shift_id, m.created_at, m.movement_type, m.amount, m.reason, m.note',
            'date' => 'm.created_at',
        ],
        'shifts' => [
            'id' => 'shift_id', 'table' => 'cashier_shifts', 'alias' => 'cs',
            'columns' => 'cs.shift_id, cs.register_id, cs.opened_at, cs.closed_at, cs.status, cs.opening_cash, cs.expected_cash, cs.actual_cash, cs.cash_variance, cs.closing_notes, cs.closed_by, cs.intervention_reason',
            'date' => 'cs.opened_at',
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Identity must come from the authenticated session, never request filters. */
    public function resolve(int $authenticatedCashierId, array $query): array
    {
        $types = self::types();
        $type = is_string($query['type'] ?? null) ? $query['type'] : 'sales';
        if (!isset($types[$type])) {
            $type = 'sales';
        }
        $category = is_string($query['category'] ?? null) ? $query['category'] : '';
        if (!isset(self::categoryOptions($type)[$category])) $category = '';
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $page = is_int($page) ? max(1, min(100000, $page)) : 1;
        $pageSize = filter_var($query['length'] ?? self::PAGE_SIZE, FILTER_VALIDATE_INT);
        $pageSize = in_array($pageSize, [10, 25, 50, 100], true) ? $pageSize : self::PAGE_SIZE;
        $recordId = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT);
        $recordId = is_int($recordId) && $recordId > 0 ? $recordId : null;
        $fromDay = null;
        $throughDay = null;
        $dateError = '';
        try {
            $readDay = static function (mixed $input): ?string {
                if ($input === '') return null;
                if (!is_string($input)) throw new \InvalidArgumentException('Choose a valid Philippine calendar date.');
                PhilippineTime::dayStart($input);
                return $input;
            };
            $fromDay = $readDay($query['date_from'] ?? '');
            $throughDay = $readDay($query['date_to'] ?? '');
            if ($fromDay !== null && $throughDay !== null && $fromDay > $throughDay) {
                throw new \InvalidArgumentException('The end date must be on or after the start date.');
            }
        } catch (\InvalidArgumentException $exception) {
            $dateError = $exception->getMessage();
        }

        $record = $recordId === null ? null : $this->find($type, $authenticatedCashierId, $recordId);
        $rows = $recordId === null && $dateError === '' ? $this->page($type, $authenticatedCashierId, $page, $pageSize, $fromDay, $throughDay, $category) : [];
        $totalRows = $recordId === null && $dateError === '' ? $this->count($type, $authenticatedCashierId, $fromDay, $throughDay, $category) : 0;
        $totalPages = max(1, (int)ceil($totalRows / $pageSize));
        $hasNext = $page < $totalPages;
        return compact('types', 'type', 'page', 'pageSize', 'totalRows', 'totalPages', 'recordId', 'record', 'rows', 'hasNext', 'fromDay', 'throughDay', 'dateError', 'category');
    }

    /** @return array<int, array<string, mixed>> */
    public function page(string $type, int $cashierId, int $page = 1, int $size = self::PAGE_SIZE, ?string $fromDay = null, ?string $throughDay = null, string $category = ''): array
    {
        $query = $this->query($type);
        $page = max(1, $page);
        $size = max(1, min(100, $size));
        $alias = $query['alias'];
        $dateConditions = '';
        $dateParameters = [];
        if ($fromDay !== null) {
            $dateConditions .= " AND {$query['date']} >= :date_start";
            $dateParameters[':date_start'] = PhilippineTime::dayStart($fromDay);
        }
        if ($throughDay !== null) {
            $dateConditions .= " AND {$query['date']} < :date_after";
            $dateParameters[':date_after'] = PhilippineTime::dayAfter($throughDay);
        }
        if ($fromDay !== null && $throughDay !== null && $fromDay > $throughDay) {
            throw new InvalidArgumentException('The end date must be on or after the start date.');
        }
        $categoryCondition = $this->categoryCondition($type, $category);
        if ($categoryCondition !== '') $dateParameters[':category'] = $category;
        $sql = "SELECT {$query['columns']} FROM {$query['table']} {$alias}
                WHERE {$alias}.cashier_id = :cashier_id{$dateConditions}{$categoryCondition}
                ORDER BY {$query['date']} DESC, {$alias}.{$query['id']} DESC
                LIMIT :size OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':cashier_id', $cashierId, PDO::PARAM_INT);
        $statement->bindValue(':size', $size, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $size, PDO::PARAM_INT);
        foreach ($dateParameters as $name => $value) {
            $statement->bindValue($name, $value, PDO::PARAM_STR);
        }
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $type === 'shifts' ? $this->withLiveBalances($rows) : $rows;
    }

    public function count(string $type, int $cashierId, ?string $fromDay = null, ?string $throughDay = null, string $category = ''): int
    {
        $query = $this->query($type);
        $where = $query['alias'] . '.cashier_id = :cashier_id';
        $parameters = [':cashier_id' => $cashierId];
        if ($fromDay !== null) {
            $where .= " AND {$query['date']} >= :date_start";
            $parameters[':date_start'] = PhilippineTime::dayStart($fromDay);
        }
        if ($throughDay !== null) {
            $where .= " AND {$query['date']} < :date_after";
            $parameters[':date_after'] = PhilippineTime::dayAfter($throughDay);
        }
        $categoryCondition = $this->categoryCondition($type, $category);
        $where .= $categoryCondition;
        if ($categoryCondition !== '') $parameters[':category'] = $category;
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$query['table']} {$query['alias']} WHERE {$where}");
        $statement->execute($parameters);
        return (int)$statement->fetchColumn();
    }

    /** A guessed identifier never reveals another Cashier's record. */
    public function find(string $type, int $cashierId, int $recordId): ?array
    {
        if ($recordId <= 0) {
            return null;
        }
        $query = $this->query($type);
        $alias = $query['alias'];
        $statement = $this->pdo->prepare(
            "SELECT {$query['columns']} FROM {$query['table']} {$alias}
             WHERE {$alias}.cashier_id = :cashier_id AND {$alias}.{$query['id']} = :record_id"
        );
        $statement->execute([':cashier_id' => $cashierId, ':record_id' => $recordId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        return $type === 'shifts' && $row !== null ? $this->withLiveBalances([$row])[0] : $row;
    }

    private function withLiveBalances(array $rows): array
    {
        $openingCashes = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'open') {
                $openingCashes[(int)$row['shift_id']] = (float)$row['opening_cash'];
            }
        }
        $summaries = (new ShiftReconciliationCalculator($this->pdo))->calculateBatch(array_keys($openingCashes), $openingCashes);
        foreach ($rows as &$row) {
            if ($row['status'] === 'open') {
                $row['expected_cash'] = $summaries[(int)$row['shift_id']]['calculated_expected_cash'];
            }
        }
        unset($row);
        return $rows;
    }

    private function query(string $type): array
    {
        if (!isset(self::QUERIES[$type])) {
            throw new InvalidArgumentException('Unknown history type.');
        }
        $query = self::QUERIES[$type];
        if ($type === 'refunds') $query['columns'] .= ', ' . self::REFUND_STATUS_SQL . ' AS refund_status';
        return $query;
    }

    private function categoryCondition(string $type, string $category): string
    {
        if (!isset(self::categoryOptions($type)[$category])) return '';
        $column = $type === 'refunds' ? self::REFUND_STATUS_SQL : 'm.movement_type';
        return " AND ({$column}) = :category";
    }
}
