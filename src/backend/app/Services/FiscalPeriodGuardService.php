<?php

require_once __DIR__ . '/../Store/StoreWriteGate.php';
require_once __DIR__ . '/../Attention/Clock.php';
require_once __DIR__ . '/../Attention/AttentionSettingsService.php';
require_once __DIR__ . '/../Attention/SystemClock.php';

/**
 * The single write boundary for Store fiscal periods.
 *
 * Lifecycle methods deliberately live beside the posting guard. This keeps
 * date/range rules identical for the administrator route and operational
 * services, and lets callers use the same PDO transaction and row locks.
 */
class FiscalPeriodGuardService
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const AUTO_ROLLOVER_SETTING = 'fiscal_period_auto_rollover';

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

    /**
     * Add a period to the end of the Store's reporting chain.
     *
     * A range that covers today becomes open when nothing else is open.
     * Everything else is scheduled: its dates are reserved, but it accepts no
     * transactions until it is opened (manually or by automatic rollover).
     */
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
                'SELECT period_id, period_name, start_date, end_date, status FROM fiscal_periods
                 ORDER BY start_date, period_id' . $lock
            )->fetchAll(PDO::FETCH_ASSOC);
            $last = $periods ? $periods[count($periods) - 1] : null;
            if ($last) {
                $expectedStart = date('Y-m-d', strtotime($last['end_date'] . ' +1 day'));
                if ($start !== $expectedStart) {
                    throw new RuntimeException(
                        'The next fiscal period must start the day after the previous period ends (' . $expectedStart . ').'
                        . ' Use Generate periods to schedule the rest of the chain without gaps.'
                    );
                }
            }
            $hasOpen = false;
            $coversToday = false;
            foreach ($periods as $period) {
                if (strcasecmp((string)$period['period_name'], $name) === 0) {
                    throw new RuntimeException('A fiscal period with that name already exists.');
                }
                if ($start <= $period['end_date'] && $end >= $period['start_date']) {
                    throw new RuntimeException('Fiscal period dates overlap an existing period.');
                }
                if ($period['status'] === 'open') {
                    $hasOpen = true;
                }
                if ($period['start_date'] <= $today && $period['end_date'] >= $today) {
                    $coversToday = true;
                }
            }
            if ($hasOpen || $coversToday) {
                $status = self::STATUS_SCHEDULED;
            } elseif ($today >= $start && $today <= $end) {
                $status = 'open';
            } elseif ($periods) {
                throw new RuntimeException(
                    'Create and open a fiscal period that includes today before adding later periods.'
                );
            } else {
                throw new RuntimeException("The first fiscal period must include today's date.");
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO fiscal_periods
                    (period_name, start_date, end_date, created_by, status)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $start, $end, $actorId, $status]);
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

    /**
     * Create a contiguous set of calendar-aligned periods in one transaction.
     *
     * The chain always continues from the day after the last period (or starts
     * today when the Store has none), so no day is left without fiscal
     * coverage. Any invalid or duplicate name aborts the whole batch.
     */
    public function generatePeriodSchedule(
        string $cadence,
        int $count,
        string $prefix,
        int $actorId,
        ?string $today = null
    ): array {
        $cadence = strtolower(trim($cadence));
        if (!in_array($cadence, ['monthly', 'quarterly'], true)) {
            throw new InvalidArgumentException('Choose monthly or quarterly periods.');
        }
        if ($count < 1 || $count > 24) {
            throw new InvalidArgumentException('Generate between 1 and 24 fiscal periods.');
        }
        $prefix = trim($prefix);
        if (mb_strlen($prefix) > 20) {
            throw new InvalidArgumentException('Period name prefix must be 20 characters or fewer.');
        }
        $today = $this->date($today ?? date('Y-m-d'), 'current date');

        \App\Store\StoreWriteGate::begin($this->pdo);
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $periods = $this->pdo->query(
                'SELECT period_id, period_name, start_date, end_date, status FROM fiscal_periods
                 ORDER BY start_date, period_id' . $lock
            )->fetchAll(PDO::FETCH_ASSOC);
            $last = $periods ? $periods[count($periods) - 1] : null;

            $hasOpen = false;
            $coversToday = false;
            $takenNames = [];
            foreach ($periods as $period) {
                $takenNames[mb_strtolower((string)$period['period_name'])] = true;
                if ($period['status'] === 'open') {
                    $hasOpen = true;
                }
                if ($period['start_date'] <= $today && $period['end_date'] >= $today) {
                    $coversToday = true;
                }
            }

            $cursor = new DateTimeImmutable(
                $last
                    ? date('Y-m-d', strtotime((string)$last['end_date'] . ' +1 day'))
                    : $today
            );

            $ranges = [];
            for ($i = 0; $i < $count; $i++) {
                if ($cadence === 'monthly') {
                    $rangeEnd = $cursor->modify('last day of this month');
                    $label = $cursor->format('Y-m');
                } else {
                    $quarter = intdiv((int)$cursor->format('n') - 1, 3) + 1;
                    $rangeEnd = $cursor->setDate((int)$cursor->format('Y'), $quarter * 3, 1)
                        ->modify('last day of this month');
                    $label = 'Q' . $quarter . ' ' . $cursor->format('Y');
                }
                $name = $prefix === '' ? $label : $prefix . ' ' . $label;
                $nameKey = mb_strtolower($name);
                if (isset($takenNames[$nameKey])) {
                    throw new RuntimeException(
                        'Fiscal period "' . $name . '" already exists. Nothing was generated.'
                    );
                }
                $takenNames[$nameKey] = true;
                $ranges[] = [
                    'name' => $name,
                    'start' => $cursor->format('Y-m-d'),
                    'end' => $rangeEnd->format('Y-m-d'),
                ];
                $cursor = $rangeEnd->modify('+1 day');
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO fiscal_periods
                    (period_name, start_date, end_date, created_by, status)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $created = [];
            foreach ($ranges as $range) {
                if ($hasOpen || $coversToday) {
                    $status = self::STATUS_SCHEDULED;
                } elseif ($today >= $range['start'] && $today <= $range['end']) {
                    $status = 'open';
                    $hasOpen = true;
                } elseif ($periods) {
                    throw new RuntimeException(
                        'Create and open a fiscal period that includes today before adding later periods.'
                    );
                } else {
                    throw new RuntimeException("The first fiscal period must include today's date.");
                }
                $insert->execute([$range['name'], $range['start'], $range['end'], $actorId, $status]);
                $created[] = [
                    'period_id' => (int)$this->pdo->lastInsertId(),
                    'period_name' => $range['name'],
                    'start_date' => $range['start'],
                    'end_date' => $range['end'],
                    'status' => $status,
                ];
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Scheduling future periods signals intent to keep the chain running.
        $autoRolloverEnabled = false;
        if (in_array(self::STATUS_SCHEDULED, array_column($created, 'status'), true)) {
            try {
                $settings = new \App\Attention\AttentionSettingsService($this->pdo, new \App\Attention\SystemClock());
                if ((float)($settings->storeSettings()[self::AUTO_ROLLOVER_SETTING] ?? 0) <= 0) {
                    $settings->updateStore('admin', [self::AUTO_ROLLOVER_SETTING => 1], $actorId);
                    $autoRolloverEnabled = true;
                }
            } catch (Throwable $e) {
                error_log('Fiscal period automatic rollover could not be enabled: ' . $e->getMessage());
            }
        }

        return [
            'periods' => $created,
            'start_date' => $created[0]['start_date'],
            'end_date' => $created[count($created) - 1]['end_date'],
            'auto_rollover_enabled' => $autoRolloverEnabled,
        ];
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
            $blockers = $this->closureBlockers($period);
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
        $this->notifyClosedManually($period);
    }

    public function lockPeriod(int $periodId): void
    {
        $stmt = $this->pdo->prepare("UPDATE fiscal_periods SET status = 'locked' WHERE period_id = ? AND status = 'closed'");
        $stmt->execute([$periodId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Only a closed fiscal period can be locked.');
        }
    }

    /**
     * Open a scheduled period that has reached its start date.
     */
    public function openScheduledPeriod(int $periodId, int $actorId, ?string $today = null): void
    {
        $today = $this->date($today ?? date('Y-m-d'), 'current date');
        \App\Store\StoreWriteGate::begin($this->pdo);
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?' . $lock);
            $stmt->execute([$periodId]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$period || $period['status'] !== self::STATUS_SCHEDULED) {
                throw new RuntimeException('Only a scheduled fiscal period can be opened.');
            }
            if ($today < $period['start_date']) {
                throw new RuntimeException('This fiscal period cannot be opened before ' . $period['start_date'] . '.');
            }
            if ($this->count("SELECT COUNT(*) FROM fiscal_periods WHERE status = 'open'") > 0) {
                throw new RuntimeException('Only one fiscal period may be open at a time. Close the current period first.');
            }
            $update = $this->pdo->prepare(
                "UPDATE fiscal_periods SET status = 'open' WHERE period_id = ? AND status = 'scheduled'"
            );
            $update->execute([$periodId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Fiscal period changed while it was being opened. Try again.');
            }
            $this->record('Fiscal period opened', $actorId, $periodId, $period, ['status' => 'open']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Remove a scheduled period that has not become the Store's active window.
     * Later periods must be removed first so the chain never develops a gap.
     */
    public function deleteScheduledPeriod(int $periodId, int $actorId): array
    {
        \App\Store\StoreWriteGate::begin($this->pdo);
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?' . $lock);
            $stmt->execute([$periodId]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$period || $period['status'] !== self::STATUS_SCHEDULED) {
                throw new RuntimeException('Only a scheduled fiscal period can be deleted.');
            }
            if ($this->count('SELECT COUNT(*) FROM fiscal_periods WHERE start_date > ?', [$period['start_date']]) > 0) {
                throw new RuntimeException('Delete later fiscal periods first so the remaining dates stay continuous.');
            }
            $delete = $this->pdo->prepare("DELETE FROM fiscal_periods WHERE period_id = ? AND status = 'scheduled'");
            $delete->execute([$periodId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Fiscal period changed while it was being deleted. Try again.');
            }
            $this->record('Scheduled fiscal period deleted', $actorId, $periodId, $period, null);
            $this->pdo->commit();
            return $period;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function openNextPeriod(int $predecessorPeriodId, string $periodName, string $endDate, int $actorId): int
    {
        $periodName = trim($periodName);
        if ($periodName === '') {
            throw new InvalidArgumentException('Fiscal period name is required.');
        }
        $end = $this->date($endDate, 'end date');
        \App\Store\StoreWriteGate::begin($this->pdo);
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?' . $lock);
            $stmt->execute([$predecessorPeriodId]);
            $pred = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pred || !in_array($pred['status'], ['closed', 'locked'], true)) {
                throw new RuntimeException('Only a closed or locked fiscal period can have a successor opened.');
            }
            $nextStart = date('Y-m-d', strtotime($pred['end_date'] . ' +1 day'));
            if ($end < $nextStart) {
                throw new InvalidArgumentException('End date must not be before the start date (' . $nextStart . ').');
            }

            // Check overlap
            $overlap = $this->pdo->prepare('SELECT COUNT(*) FROM fiscal_periods WHERE start_date <= ? AND end_date >= ?');
            $overlap->execute([$end, $nextStart]);
            if ((int)$overlap->fetchColumn() > 0) {
                throw new RuntimeException('The next fiscal period interval overlaps an existing fiscal period.');
            }

            $hasCreatedBy = $this->columnExists('fiscal_periods', 'created_by');
            if ($hasCreatedBy) {
                $ins = $this->pdo->prepare('INSERT INTO fiscal_periods (period_name, start_date, end_date, created_by, status) VALUES (?, ?, ?, ?, "open")');
                $ins->execute([$periodName, $nextStart, $end, $actorId]);
            } else {
                $ins = $this->pdo->prepare('INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES (?, ?, ?, "open")');
                $ins->execute([$periodName, $nextStart, $end]);
            }
            $newId = (int)$this->pdo->lastInsertId();

            $this->record('Next fiscal period opened', $actorId, $newId, null, [
                'period_id' => $newId,
                'period_name' => $periodName,
                'start_date' => $nextStart,
                'end_date' => $end,
                'predecessor_id' => $predecessorPeriodId,
            ]);

            $this->pdo->commit();
            return $newId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function amendOpenEndDate(int $periodId, string $endDate): array
    {
        return $this->amendEndDate($periodId, $endDate, ['open']);
    }

    public function amendScheduledEndDate(int $periodId, string $endDate): array
    {
        return $this->amendEndDate($periodId, $endDate, [self::STATUS_SCHEDULED]);
    }

    /**
     * Automatic rollover: close an expired open period and open its scheduled
     * successor, but only when the Store setting allows it and closure
     * prerequisites are already satisfied. One period per call, so a long
     * absence never skips a reporting window.
     */
    public function rolloverIfDue(?string $today = null, ?int $actorId = null): array
    {
        $result = [
            'enabled' => false,
            'rolled' => false,
            'blockers' => [],
            'closed_period_id' => null,
            'opened_period_id' => null,
            'period_name' => null,
        ];
        $today = $this->date($today ?? date('Y-m-d'), 'current date');
        if (!$this->autoRolloverEnabled()) {
            return $result;
        }
        $result['enabled'] = true;

        // Cheap read before taking the Store write lock on every page load.
        if ($this->count("SELECT COUNT(*) FROM fiscal_periods WHERE status = 'open' AND end_date < ?", [$today]) === 0) {
            return $result;
        }

        \App\Store\StoreWriteGate::begin($this->pdo);
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare(
                "SELECT * FROM fiscal_periods WHERE status = 'open' AND end_date < ?
                 ORDER BY end_date, period_id LIMIT 1" . $lock
            );
            $stmt->execute([$today]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$period) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                return $result;
            }

            $result['period_name'] = $period['period_name'];
            $blockers = $this->closureBlockers($period);
            if ($blockers) {
                $result['blockers'] = $blockers;
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                $this->notifyRollover($result, $period, $today);
                return $result;
            }

            $update = $this->pdo->prepare(
                "UPDATE fiscal_periods SET status = 'closed', closed_at = NOW(), closed_by = NULL
                 WHERE period_id = ? AND status = 'open'"
            );
            $update->execute([$period['period_id']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Fiscal period changed while it was being rolled over. Try again.');
            }
            $result['closed_period_id'] = (int)$period['period_id'];

            $nextStart = date('Y-m-d', strtotime($period['end_date'] . ' +1 day'));
            $next = $this->pdo->prepare(
                "SELECT * FROM fiscal_periods WHERE status = 'scheduled' AND start_date = ?
                 ORDER BY start_date, period_id LIMIT 1" . $lock
            );
            $next->execute([$nextStart]);
            $successor = $next->fetch(PDO::FETCH_ASSOC);
            if ($successor) {
                $open = $this->pdo->prepare(
                    "UPDATE fiscal_periods SET status = 'open' WHERE period_id = ? AND status = 'scheduled'"
                );
                $open->execute([$successor['period_id']]);
                if ($open->rowCount() === 1) {
                    $result['opened_period_id'] = (int)$successor['period_id'];
                }
            }

            $result['rolled'] = true;
            $this->record('Fiscal period rolled over automatically', $actorId, (int)$period['period_id'], $period, [
                'closed_period_id' => $result['closed_period_id'],
                'opened_period_id' => $result['opened_period_id'],
            ]);
            $this->pdo->commit();
            $this->notifyRollover($result, $period, $today);
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function amendEndDate(int $periodId, string $endDate, array $allowedStatuses): array
    {
        $end = $this->date($endDate, 'end date');
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare('SELECT * FROM fiscal_periods WHERE period_id = ?' . $lock);
            $stmt->execute([$periodId]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
            $scheduledOnly = $allowedStatuses === [self::STATUS_SCHEDULED];
            if (!$period || !in_array($period['status'], $allowedStatuses, true)) {
                throw new RuntimeException(
                    $scheduledOnly
                        ? 'Only a scheduled fiscal period can be amended.'
                        : 'Only an open fiscal period can be amended.'
                );
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
            $status = (string)$period['status'];
            $update = $this->pdo->prepare('UPDATE fiscal_periods SET end_date = ? WHERE period_id = ? AND status = ?');
            $update->execute([$end, $periodId, $status]);
            $this->pdo->commit();
            return ['before' => $period, 'end_date' => $end];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function closureBlockers(array $period): array
    {
        $blockers = [];
        if ($this->count("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'open'") > 0) {
            $blockers[] = 'open Cashier Shifts';
        }
        if ($this->tableExists('gcash_service_sessions')) {
            $openSessions = $this->count("SELECT COUNT(*) FROM gcash_service_sessions WHERE status = 'open'");
            $unassignedPending = $this->tableExists('gcash_transactions')
                ? $this->count("SELECT COUNT(*) FROM gcash_transactions WHERE verification_status = 'Needs checking' AND responsible_user_id IS NULL")
                : 0;
            if ($openSessions > 0 || $unassignedPending > 0) {
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
        return $blockers;
    }

    /**
     * Notify Administrators once per rollover event, and at most once per
     * period per day while a rollover stays blocked, so a stuck overdue period
     * cannot flood the bell on repeated page loads.
     */
    private function notifyRollover(array $result, array $period, string $today): void
    {
        $closedId = (int)$period['period_id'];
        $blocked = !empty($result['blockers']);
        $missingSuccessor = !$blocked && !empty($result['rolled']) && $result['opened_period_id'] === null;

        if ($blocked) {
            $key = 'store.fiscal-period.rollover-blocked.' . $closedId . '.' . $today;
            $severity = 'critical';
            $title = 'Fiscal Period rollover is blocked';
            $message = sprintf(
                'Cannot close "%s" (ended %s) automatically: %s. Resolve these, then close the period or wait for the next daily check.',
                (string)$period['period_name'],
                (string)$period['end_date'],
                implode('; ', $result['blockers'])
            );
        } elseif ($missingSuccessor) {
            $key = 'store.fiscal-period.rollover.orphan.' . $closedId;
            $severity = 'critical';
            $title = 'Fiscal Period rolled over without a successor';
            $message = sprintf(
                '"%s" closed automatically, but no scheduled fiscal period starts on %s. Trading is blocked until you generate the next period.',
                (string)$period['period_name'],
                date('M j, Y', strtotime((string)$period['end_date'] . ' +1 day'))
            );
        } elseif (!empty($result['rolled'])) {
            $key = 'store.fiscal-period.rollover.' . $closedId;
            $severity = 'info';
            $title = 'Fiscal Period rolled over automatically';
            $message = sprintf('"%s" closed and the next scheduled period is now open.', (string)$period['period_name']);
        } else {
            return;
        }

        $this->insertPeriodNotice($period, $key, $severity, $title, $message);
    }

    /**
     * Manual closure uses the same bell and daily Brevo email as a rollover.
     */
    private function notifyClosedManually(array $period): void
    {
        $this->insertPeriodNotice(
            $period,
            'store.fiscal-period.closed.' . (int)$period['period_id'],
            'info',
            'Fiscal Period closed',
            sprintf(
                '"%s" (ended %s) was closed. New sales need an open period that covers their date.',
                (string)$period['period_name'],
                (string)$period['end_date']
            )
        );
    }

    private function insertPeriodNotice(array $period, string $key, string $severity, string $title, string $message): void
    {
        foreach (['notifications', 'users', 'roles', 'user_roles'] as $table) {
            if (!$this->tableExists($table)) {
                return;
            }
        }
        $destination = 'components/system_administrator/fiscal_periods.php';
        $closedId = (int)$period['period_id'];

        $hasPrefs = $this->tableExists('notification_preferences');
        $sql = 'SELECT u.user_id'
            . ($hasPrefs ? ', COALESCE(np.notify_inapp, 1) AS notify_inapp' : ', 1 AS notify_inapp')
            . ' FROM users u'
            . ($hasPrefs ? ' LEFT JOIN notification_preferences np ON np.user_id = u.user_id' : '')
            . " WHERE u.status = 'active' AND EXISTS (
                SELECT 1 FROM roles r WHERE r.role_name = 'admin' AND (
                    EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.user_id AND ur.role_id = r.role_id)
                    OR (r.role_id = u.role_id AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.user_id))
                ))";

        try {
            $recipients = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $seen = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND attention_key = ?');
            $insert = $this->pdo->prepare(
                "INSERT INTO notifications
                    (user_id, type, title, message, reference_id, reference_type, attention_key,
                     attention_severity, attention_count, attention_destination, is_read, created_at,
                     workspace, inapp_enabled, email_pending)
                 VALUES (?, 'fiscal_period', ?, ?, ?, 'fiscal_period', ?, ?, 1, ?, 0, ?, 'admin', ?, 1)"
            );
            foreach ($recipients as $recipient) {
                $userId = (int)$recipient['user_id'];
                $seen->execute([$userId, $key]);
                if ((int)$seen->fetchColumn() > 0) {
                    continue;
                }
                $insert->execute([
                    $userId,
                    $title,
                    $message,
                    $closedId,
                    $key,
                    $severity,
                    $destination,
                    date('Y-m-d H:i:s'),
                    (int)!empty($recipient['notify_inapp']),
                ]);
            }
        } catch (Throwable $e) {
            error_log('Fiscal period notification skipped: ' . $e->getMessage());
        }
    }

    private function autoRolloverEnabled(): bool
    {
        try {
            $service = new \App\Attention\AttentionSettingsService($this->pdo, new \App\Attention\SystemClock());
            return (float)($service->storeSettings()[self::AUTO_ROLLOVER_SETTING] ?? 0) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function record(string $action, ?int $actorId, int $periodId, ?array $before, ?array $after): void
    {
        if (!function_exists('log_activity')) {
            return;
        }
        log_activity($this->pdo, $actorId, $action, 'Fiscal Periods', $periodId, $before, $after);
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
