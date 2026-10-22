<?php

require_once __DIR__ . '/../Store/StoreWriteGate.php';

/**
 * The single write boundary for Store fiscal periods.
 *
 * Lifecycle methods deliberately live beside the posting guard. This keeps
 * date/range rules identical for the administrator route and operational
 * services, and lets callers use the same PDO transaction and row locks.
 */
class FiscalPeriodGuardService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function assertOpenForDate(string $date, string $tableName, string $operationLabel = 'transaction'): void
    {
        $day = $this->effectiveDay($date);
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->pdo->prepare(
            'SELECT period_id, period_name, status FROM fiscal_periods
             WHERE start_date <= ? AND end_date >= ? ORDER BY start_date DESC, period_id DESC LIMIT 1' . $lock
        );
        $stmt->execute([$day, $day]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            throw new RuntimeException(sprintf(
                'Cannot record %s dated %s because no open fiscal period covers that date.',
                $operationLabel,
                $day
            ));
        }
        if ($period['status'] !== 'open') {
            throw new RuntimeException(sprintf(
                'Cannot record %s dated %s because fiscal period "%s" is %s.',
                $operationLabel,
                $day,
                $period['period_name'],
                $period['status']
            ));
        }
        if ($this->hasLock((int)$period['period_id'], $tableName)) {
            throw new RuntimeException(sprintf(
                'Cannot record %s dated %s because fiscal period "%s" is locked for %s.',
                $operationLabel,
                $day,
                $period['period_name'],
                $tableName
            ));
        }
    }

    public function assertOpenNow(string $tableName, string $operationLabel = 'transaction'): void
    {
        $this->assertOpenForDate(date('Y-m-d'), $tableName, $operationLabel);
    }

    public function createPeriod(
        string $name,
        string $startDate,
        string $endDate,
        int $actorId,
        ?string $today = null
    ): int {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Fiscal period name is required.');
        }
        $start = $this->date($startDate, 'start date');
        $end = $this->date($endDate, 'end date');
        $today = $this->date($today ?? date('Y-m-d'), 'current date');
        if ($start > $end) {
            throw new InvalidArgumentException('Start date must not be after end date.');
        }

        \App\Store\StoreWriteGate::begin($this->pdo);
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $periods = $this->pdo->query(
                'SELECT period_id, start_date, end_date, status FROM fiscal_periods
                 ORDER BY start_date, period_id' . $lock
            )->fetchAll(PDO::FETCH_ASSOC);
            $last = $periods ? $periods[count($periods) - 1] : null;
            if ($last && $start !== date('Y-m-d', strtotime($last['end_date'] . ' +1 day'))) {
                throw new RuntimeException('The next fiscal period must start the day after the previous period ends.');
            }
            foreach ($periods as $period) {
                if ($start <= $period['end_date'] && $end >= $period['start_date']) {
                    throw new RuntimeException('Fiscal period dates overlap an existing period.');
                }
                if ($period['status'] === 'open') {
                    throw new RuntimeException('Only one fiscal period may be open at a time.');
                }
            }
            if (!$periods && ($today < $start || $today > $end)) {
                throw new RuntimeException("The first fiscal period must include today's date.");
            }
            $stmt = $this->pdo->prepare(
                "INSERT INTO fiscal_periods
                    (period_name, start_date, end_date, created_by, status)
                 VALUES (?, ?, ?, ?, 'open')"
            );
            $stmt->execute([$name, $start, $end, $actorId]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function closePeriod(int $periodId, int $actorId, ?string $today = null): void
    {
        $today = $this->date($today ?? date('Y-m-d'), 'current date');
        \App\Store\StoreWriteGate::begin($this->pdo);
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?' . $lock);
            $stmt->execute([$periodId]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$period) {
                throw new RuntimeException('Fiscal period not found.');
            }
            if ($period['status'] !== 'open') {
                throw new RuntimeException('Only an open fiscal period can be closed.');
            }
            if ($today < $period['end_date']) {
                throw new RuntimeException('Fiscal period cannot be closed before its end date.');
            }
            $blockers = [];
            if ($this->count('SELECT COUNT(*) FROM cashier_shifts WHERE status = \'open\'') > 0) {
                $blockers[] = 'open Cashier Shifts';
            }
            if ($this->tableExists('gcash_service_sessions')) {
                $open = $this->count("SELECT COUNT(*) FROM gcash_service_sessions
                    WHERE status = 'open' OR COALESCE(unresolved_carryover_count, 0) <> 0");
                if ($open > 0) {
                    $blockers[] = 'open or unreconciled GCash sessions';
                }
            }
            if ($this->tableExists('inventory_counts')) {
                $pending = $this->count("SELECT COUNT(*) FROM inventory_counts
                    WHERE status = 'pending' AND DATE(counted_at) BETWEEN ? AND ?", [$period['start_date'], $period['end_date']]);
                if ($pending > 0) {
                    $blockers[] = 'pending Inventory Counts';
                }
            }
            if ($blockers) {
                throw new RuntimeException('Cannot close fiscal period: ' . implode('; ', $blockers) . '.');
            }
            $update = $this->pdo->prepare(
                "UPDATE fiscal_periods SET status = 'closed', closed_at = NOW(), closed_by = ?
                 WHERE period_id = ? AND status = 'open'"
            );
            $update->execute([$actorId, $periodId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Fiscal period changed while it was being closed. Try again.');
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function lockPeriod(int $periodId): void
    {
        $stmt = $this->pdo->prepare("UPDATE fiscal_periods SET status = 'locked' WHERE period_id = ? AND status = 'closed'");
        $stmt->execute([$periodId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Only a closed fiscal period can be locked.');
        }
    }

    public function amendOpenEndDate(int $periodId, string $endDate): array
        {
            $end = $this->date($endDate, 'end date');
            $this->pdo->beginTransaction();
            try {
                $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $stmt = $this->pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?' . $lock);
                $stmt->execute([$periodId]);
                $period = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$period || $period['status'] !== 'open') {
                    throw new RuntimeException('Only an open fiscal period can be amended.');
                }
                if ($end < $period['start_date']) {
                    throw new InvalidArgumentException('End date must not be before the start date.');
                }
                $overlapCount = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM fiscal_periods
                     WHERE period_id <> ? AND start_date <= ? AND end_date >= ?'
                );
                $overlapCount->execute([$periodId, $end, $period['start_date']]);
                if ((int)$overlapCount->fetchColumn() > 0) {
                    throw new RuntimeException('The amended fiscal period interval overlaps another fiscal period.');
                }
                $successor = $this->pdo->prepare(
                    'SELECT start_date FROM fiscal_periods
                     WHERE period_id <> ? AND start_date > ? ORDER BY start_date, period_id LIMIT 1'
                );
                $successor->execute([$periodId, $period['start_date']]);
                $successorStart = $successor->fetchColumn();
                if ($successorStart !== false) {
                    $expectedSuccessorStart = date('Y-m-d', strtotime($end . ' +1 day'));
                    if ($successorStart !== $expectedSuccessorStart) {
                        throw new RuntimeException('The amended fiscal period must end the day before its successor starts.');
                    }
                }
                foreach (['sales' => 'sale_date', 'purchase_history' => 'purchase_date', 'stock_movements' => 'created_at'] as $table => $column) {
                    if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
                        continue;
                    }
                    $query = "SELECT COUNT(*) FROM `{$table}` WHERE DATE(`{$column}`) BETWEEN ? AND ? AND DATE(`{$column}`) > ?";
                    $check = $this->pdo->prepare($query);
                    $check->execute([$period['start_date'], $period['end_date'], $end]);
                    if ((int)$check->fetchColumn() > 0) {
                        throw new RuntimeException('The amended end date would exclude existing transactions.');
                    }
                }
                $update = $this->pdo->prepare('UPDATE fiscal_periods SET end_date = ? WHERE period_id = ? AND status = \'open\'');
                $update->execute([$end, $periodId]);
                $this->pdo->commit();
                return ['before' => $period, 'end_date' => $end];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }

    private function date(string $value, string $label): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new InvalidArgumentException("Invalid {$label}.");
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Invalid {$label}.");
        }
        return $value;
    }

    private function effectiveDay(string $value): string
    {
        $day = substr($value, 0, 10);
        $this->date($day, 'effective date');
        return $day;
    }

    private function count(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return $this->count(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            ) > 0;
        }
        return $this->count(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        ) > 0;
    }

    private function columnExists(string $table, string $column): bool
        {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $this->pdo->query("PRAGMA table_info(`{$table}`)");
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $field) {
                    if ($field['name'] === $column) {
                        return true;
                    }
                }
                return false;
            }
            return $this->count(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            ) > 0;
        }
    private function hasLock(int $periodId, string $tableName): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM fiscal_period_locks
             WHERE period_id = ? AND (table_name IS NULL OR table_name = \'\' OR table_name = ?)'
        );
        $stmt->execute([$periodId, $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
