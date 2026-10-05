<?php

namespace App\Services;

use InvalidArgumentException;
use PDO;

require_once __DIR__ . '/PhilippineTime.php';

/** Read-only, identity-scoped operational records for the active Cashier. */
final class CashierOperationalHistoryService
{
    public const PAGE_SIZE = 25;

    /** @return array<string, array{label: string, id: string, columns: array<string, string>}> */
    public static function types(): array
    {
        return [
            'sales' => ['label' => 'Sales', 'id' => 'sale_id', 'columns' => ['sale_id' => 'Sale', 'shift_id' => 'Shift', 'sale_date' => 'Date', 'total_amount' => 'Amount', 'payment_method' => 'Payment', 'cash_received' => 'Cash received', 'change_due' => 'Change']],
            'refunds' => ['label' => 'Cash Refunds', 'id' => 'refund_id', 'columns' => ['refund_id' => 'Refund', 'sale_id' => 'Sale', 'shift_id' => 'Shift', 'created_at' => 'Date', 'refund_amount' => 'Amount', 'payment_method' => 'Payment', 'reason' => 'Reason', 'note' => 'Note']],
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

    /** @return array<int, array<string, mixed>> */
    public function page(string $type, int $cashierId, int $page = 1, int $size = self::PAGE_SIZE, ?string $fromDay = null, ?string $throughDay = null): array
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
        $sql = "SELECT {$query['columns']} FROM {$query['table']} {$alias}
                WHERE {$alias}.cashier_id = :cashier_id{$dateConditions}
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
        return $statement->fetchAll(PDO::FETCH_ASSOC);
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
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function query(string $type): array
    {
        if (!isset(self::QUERIES[$type])) {
            throw new InvalidArgumentException('Unknown history type.');
        }
        return self::QUERIES[$type];
    }
}
