<?php

namespace App\Store;

use App\Database\Schema;
use PDO;
use RuntimeException;
use InvalidArgumentException;
use DateTime;
use Throwable;

final class StoreCalendar
{
    /**
     * Normalize status string, ensuring consistent exceptional_closure.
     */
    public static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'exceptional-closure') {
            return 'exceptional_closure';
        }
        return $status;
    }

    /**
     * Normalize an optional operator note: trimmed, empty becomes null.
     */
    public static function normalizeNotes(?string $notes): ?string
    {
        $notes = trim((string)$notes);
        return $notes === '' ? null : $notes;
    }

    /**
     * Checks if a table exists safely across MySQL and SQLite.
     */
    private function tableExists(PDO $pdo, string $tableName): bool
    {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = ?");
                $stmt->execute([$tableName]);
                return (int)$stmt->fetchColumn() > 0;
            }
            return Schema::tableExists($pdo, $tableName);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Checks if a column exists safely across MySQL and SQLite.
     */
    private function columnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->query("PRAGMA table_info(`{$tableName}`)");
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (strcasecmp((string)($row['name'] ?? ''), $columnName) === 0) {
                            return true;
                        }
                    }
                }
                return false;
            }
            return Schema::columnExists($pdo, $tableName, $columnName);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Record a weekly store schedule with an effective date.
     * Prevents redundant duplicate revisions if identical to latest schedule for this effective date.
     */
    public function recordWeeklySchedule(
        PDO $pdo,
        string $effectiveDate,
        array $daysOpen,
        ?int $userId = null,
        string $source = 'manual'
    ): int {
        $effectiveDate = trim($effectiveDate);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            throw new InvalidArgumentException("Invalid effective date format: {$effectiveDate}");
        }

        $normalizeDay = function(string $day) use ($daysOpen): int {
            if (isset($daysOpen["{$day}_open"])) {
                return !empty($daysOpen["{$day}_open"]) ? 1 : 0;
            }
            if (isset($daysOpen[$day])) {
                return !empty($daysOpen[$day]) ? 1 : 0;
            }
            return 0;
        };

        $mon = $normalizeDay('monday');
        $tue = $normalizeDay('tuesday');
        $wed = $normalizeDay('wednesday');
        $thu = $normalizeDay('thursday');
        $fri = $normalizeDay('friday');
        $sat = $normalizeDay('saturday');
        $sun = $normalizeDay('sunday');

        // Check deduplication against latest schedule for this effective date
        $latest = $this->getWeeklyScheduleAtCutoff($pdo, $effectiveDate, date('Y-m-d H:i:s'));
        if ($latest !== null
            && $latest['effective_date'] === $effectiveDate
            && (int)$latest['monday_open'] === $mon
            && (int)$latest['tuesday_open'] === $tue
            && (int)$latest['wednesday_open'] === $wed
            && (int)$latest['thursday_open'] === $thu
            && (int)$latest['friday_open'] === $fri
            && (int)$latest['saturday_open'] === $sat
            && (int)$latest['sunday_open'] === $sun
        ) {
            return (int)$latest['schedule_id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO store_weekly_schedule
             (effective_date, monday_open, tuesday_open, wednesday_open, thursday_open, friday_open, saturday_open, sunday_open, recorded_by, source, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([$effectiveDate, $mon, $tue, $wed, $thu, $fri, $sat, $sun, $userId, $source]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Reconstruct the active weekly schedule at cutoff for a given target date.
     */
    public function getWeeklyScheduleAtCutoff(PDO $pdo, string $targetDate, string $cutoff): ?array
    {
        if (!$this->tableExists($pdo, 'store_weekly_schedule')) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT schedule_id, effective_date, monday_open, tuesday_open, wednesday_open, thursday_open, friday_open, saturday_open, sunday_open, recorded_by, source, recorded_at
             FROM store_weekly_schedule
             WHERE effective_date <= ? AND recorded_at <= ?
             ORDER BY effective_date DESC, recorded_at DESC, schedule_id DESC
             LIMIT 1'
        );
        $stmt->execute([$targetDate, $cutoff]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Check if a completed product sale occurred on the given Philippine business date at or before cutoff.
     * Cash refunds, GCash transactions, and abandoned transactions do NOT establish opening.
     */
    public function hasCompletedSaleOnDateAtCutoff(PDO $pdo, string $date, string $cutoff): bool
    {
        if (!$this->tableExists($pdo, 'sales') || !$this->tableExists($pdo, 'sale_items')) {
            return false;
        }

        $dayStart = $date . ' 00:00:00';
        $effectiveEnd = min($date . ' 23:59:59', $cutoff);

        if ($effectiveEnd < $dayStart) {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM sales s
                 JOIN sale_items si ON si.sale_id = s.sale_id
                 WHERE s.sale_date >= ? AND s.sale_date <= ? AND si.quantity > 0
                 LIMIT 1'
            );
            $stmt->execute([$dayStart, $effectiveEnd]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Latest date-specific calendar exception at cutoff, including its optional note.
     */
    private function getCalendarDateExceptionAtCutoff(PDO $pdo, string $date, string $cutoff): ?array
    {
        if (!$this->tableExists($pdo, 'store_calendar')) {
            return null;
        }

        $hasSource = $this->columnExists($pdo, 'store_calendar', 'source');
        $hasNotes = $this->columnExists($pdo, 'store_calendar', 'notes');
        $sourceCol = $hasSource ? 'source' : "'' AS source";
        $notesCol = $hasNotes ? 'notes' : 'NULL AS notes';

        $stmt = $pdo->prepare(
            "SELECT calendar_id, status, {$sourceCol}, {$notesCol}, recorded_at
             FROM store_calendar
             WHERE calendar_date = ? AND recorded_at <= ?
             ORDER BY recorded_at DESC, calendar_id DESC
             LIMIT 1"
        );
        $stmt->execute([$date, $cutoff]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Records a new calendar entry for a specific date (date exception).
     * Supports both (pdo, date, status, userId, notes) and legacy (pdo, branchId, date, status, userId, notes).
     * Avoids duplicate history if current status and note already match.
     */
    public function recordCalendarDate(PDO $pdo, mixed ...$args): void
    {
        if (count($args) >= 4 && is_numeric($args[0])) {
            // Legacy signature: ($branchId, $date, $status, $userId, $notes)
            $date = (string)$args[1];
            $status = self::normalizeStatus((string)$args[2]);
            $userId = $args[3] !== null ? (int)$args[3] : null;
            $notes = self::normalizeNotes(isset($args[4]) ? (string)$args[4] : null);
        } else {
            // New signature: ($date, $status, $userId, $notes)
            $date = (string)$args[0];
            $status = self::normalizeStatus((string)$args[1]);
            $userId = isset($args[2]) && $args[2] !== null ? (int)$args[2] : null;
            $notes = self::normalizeNotes(isset($args[3]) ? (string)$args[3] : null);
        }

        // Check if latest status and note match to avoid redundant history
        $latest = $this->getCalendarDateExceptionAtCutoff($pdo, $date, date('Y-m-d H:i:s'));
        if ($latest !== null
            && self::normalizeStatus($latest['status']) === $status
            && self::normalizeNotes($latest['notes']) === $notes
        ) {
            return;
        }

        $this->insertCalendarEntry($pdo, $date, str_replace('_', '-', $status), $userId, $notes);
    }

    /**
     * Records a calendar status across an inclusive date range [startDate, endDate].
     * Enforces bounds order, 366-day cap, skips unchanged dates, and inserts changed dates.
     * The note applies to every changed date in the range.
     * Returns array of changed date details: [['date' => ..., 'previous_status' => ..., 'new_status' => ...], ...]
     */
    public function recordCalendarRange(PDO $pdo, string $startDate, string $endDate, string $status, ?int $userId = null, ?string $notes = null): array
    {
        $status = self::normalizeStatus($status);
        $notes = self::normalizeNotes($notes);
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);

        if ($start > $end) {
            throw new InvalidArgumentException("Invalid date range: start date '{$startDate}' must be before or equal to through date '{$endDate}' (reversed bounds).");
        }

        // Inclusive day count
        $diffDays = (int)$start->diff($end)->format('%a') + 1;
        if ($diffDays > 366) {
            throw new InvalidArgumentException("Date range exceeds the maximum allowed limit of 366 inclusive days ({$diffDays} days requested).");
        }

        // Fetch current resolved statuses and notes across the range
        $currentEvidence = $this->getCalendarDateEvidenceRangeAtCutoff($pdo, $startDate, $endDate, date('Y-m-d H:i:s'));

        $changed = [];
        $cur = clone $start;

        while ($cur <= $end) {
            $curDate = $cur->format('Y-m-d');
            $prevStatus = $currentEvidence[$curDate]['status'] ?? null;
            $prevNotes = $currentEvidence[$curDate]['notes'] ?? null;

            if ($prevStatus !== $status || $prevNotes !== $notes) {
                $this->insertCalendarEntry($pdo, $curDate, str_replace('_', '-', $status), $userId, $notes);
                $changed[] = [
                    'date' => $curDate,
                    'previous_status' => $prevStatus,
                    'new_status' => $status
                ];
            }

            $cur->modify('+1 day');
        }

        return $changed;
    }

    private function insertCalendarEntry(PDO $pdo, string $date, string $status, ?int $userId, ?string $notes): void
    {
        if ($this->columnExists($pdo, 'store_calendar', 'notes')) {
            $stmt = $pdo->prepare('INSERT INTO store_calendar (calendar_date, status, recorded_by, notes, recorded_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)');
            $stmt->execute([$date, $status, $userId, $notes]);
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO store_calendar (calendar_date, status, recorded_by, recorded_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
        $stmt->execute([$date, $status, $userId]);
    }

    /**
     * Resolves complete calendar evidence for a date at cutoff:
     * - Checks completed product sale (automatic open)
     * - Date-specific exception from store_calendar
     * - Weekly schedule from store_weekly_schedule
     * - Unrecorded fallback (null)
     */
    public function getCalendarDateEvidenceAtCutoff(PDO $pdo, string $date, string $cutoff): array
    {
        $hasSale = $this->hasCompletedSaleOnDateAtCutoff($pdo, $date, $cutoff);

        $underlyingStatus = null;
        $underlyingSource = null;
        $isException = false;
        $exceptionRow = null;
        $effectiveDate = null;
        $recordedAt = null;
        $notes = null;

        // 1. Check date exception in store_calendar
        $exceptionRow = $this->getCalendarDateExceptionAtCutoff($pdo, $date, $cutoff);
        if ($exceptionRow) {
            $underlyingStatus = self::normalizeStatus($exceptionRow['status']);
            $underlyingSource = !empty($exceptionRow['source']) ? $exceptionRow['source'] : 'manual';
            $isException = true;
            $recordedAt = $exceptionRow['recorded_at'];
            $notes = self::normalizeNotes($exceptionRow['notes'] ?? null);
        }

        // 2. If no date exception, check weekly schedule
        if ($exceptionRow === null) {
            $schedule = $this->getWeeklyScheduleAtCutoff($pdo, $date, $cutoff);
            if ($schedule !== null) {
                $dayName = strtolower(date('l', strtotime($date))) . '_open';
                $isOpen = (bool)($schedule[$dayName] ?? 0);
                $underlyingStatus = $isOpen ? 'open' : 'closed';
                $underlyingSource = 'weekly_schedule';
                $effectiveDate = $schedule['effective_date'];
                $recordedAt = $schedule['recorded_at'];
            }
        }

        // 3. Resolve final status and provenance
        if ($hasSale) {
            $status = 'open';
            $source = 'sale';
            $hasSaleOverride = ($underlyingStatus !== 'open');
        } elseif ($isException) {
            $status = $underlyingStatus;
            $source = $underlyingSource;
            $hasSaleOverride = false;
        } elseif ($underlyingSource === 'weekly_schedule') {
            $status = $underlyingStatus;
            $source = 'weekly_schedule';
            $hasSaleOverride = false;
        } else {
            $status = null;
            $source = 'unrecorded';
            $hasSaleOverride = false;
        }

        return [
            'date' => $date,
            'status' => $status,
            'source' => $source,
            'is_exception' => $isException,
            'has_sale_override' => $hasSaleOverride,
            'underlying_status' => $underlyingStatus,
            'effective_date' => $effectiveDate,
            'recorded_at' => $recordedAt,
            'notes' => $notes,
        ];
    }

    /**
     * Reconstructs the status of a specific date at cutoff.
     * Supports both ($date, $cutoff) and legacy ($branchId, $date, $cutoff).
     * Resolves sales override -> date exception -> weekly schedule -> null.
     */
    public function getCalendarDateStatusAtCutoff(PDO $pdo, mixed ...$args): ?string
    {
        if (count($args) >= 3 && is_numeric($args[0])) {
            // Legacy signature: ($branchId, $date, $cutoffTimestamp)
            $date = (string)$args[1];
            $cutoffTimestamp = (string)$args[2];
        } else {
            // New signature: ($date, $cutoffTimestamp)
            $date = (string)$args[0];
            $cutoffTimestamp = (string)$args[1];
        }

        $evidence = $this->getCalendarDateEvidenceAtCutoff($pdo, $date, $cutoffTimestamp);
        return $evidence['status'];
    }

    /**
     * Batch reconstruct evidence across an inclusive date range [startDate, endDate] at cutoff.
     * Efficiently fetches sales, date exceptions, and weekly schedules in batch.
     */
    public function getCalendarDateEvidenceRangeAtCutoff(PDO $pdo, string $startDate, string $endDate, string $cutoff): array
    {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        if ($start > $end) {
            throw new InvalidArgumentException("Start date '{$startDate}' must be before or equal to end date '{$endDate}'.");
        }

        // 1. Batch fetch sale dates in range up to cutoff
        $saleDates = [];
        if ($this->tableExists($pdo, 'sales') && $this->tableExists($pdo, 'sale_items')) {
            $startTs = $startDate . ' 00:00:00';
            $endTs = min($endDate . ' 23:59:59', $cutoff);
            if ($endTs >= $startTs) {
                try {
                    $stmt = $pdo->prepare(
                        'SELECT DISTINCT DATE(s.sale_date) AS sale_date
                         FROM sales s
                         JOIN sale_items si ON si.sale_id = s.sale_id
                         WHERE s.sale_date >= ? AND s.sale_date <= ? AND si.quantity > 0'
                    );
                    $stmt->execute([$startTs, $endTs]);
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $saleDates[$row['sale_date']] = true;
                    }
                } catch (Throwable) {
                    // Ignore and continue without sales evidence if query fails
                }
            }
        }

        // 2. Batch fetch date exceptions
        $exceptions = [];
        if ($this->tableExists($pdo, 'store_calendar')) {
            try {
                $hasSource = $this->columnExists($pdo, 'store_calendar', 'source');
                $hasNotes = $this->columnExists($pdo, 'store_calendar', 'notes');
                $sourceCol = $hasSource ? 'c1.source,' : "'' AS source,";
                $notesCol = $hasNotes ? 'c1.notes,' : 'NULL AS notes,';
                $sql = "
                    SELECT c1.calendar_date, c1.status, {$sourceCol} {$notesCol} c1.recorded_at
                    FROM store_calendar c1
                    WHERE c1.calendar_date >= ?
                      AND c1.calendar_date <= ?
                      AND c1.recorded_at <= ?
                      AND c1.calendar_id = (
                          SELECT c2.calendar_id
                          FROM store_calendar c2
                          WHERE c2.calendar_date = c1.calendar_date
                            AND c2.recorded_at <= ?
                          ORDER BY c2.recorded_at DESC, c2.calendar_id DESC
                          LIMIT 1
                      )
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$startDate, $endDate, $cutoff, $cutoff]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $exceptions[$row['calendar_date']] = $row;
                }
            } catch (Throwable) {
                // Ignore if query fails
            }
        }

        // 3. Batch fetch weekly schedules applicable to this range
        $schedules = [];
        if ($this->tableExists($pdo, 'store_weekly_schedule')) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT schedule_id, effective_date, monday_open, tuesday_open, wednesday_open, thursday_open, friday_open, saturday_open, sunday_open, source, recorded_at
                     FROM store_weekly_schedule
                     WHERE effective_date <= ? AND recorded_at <= ?
                     ORDER BY effective_date ASC, recorded_at ASC, schedule_id ASC'
                );
                $stmt->execute([$endDate, $cutoff]);
                $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                // Ignore if query fails
            }
        }

        // Helper to find latest effective weekly schedule for a given date
        $findActiveSchedule = function(string $date) use (&$schedules): ?array {
            $latest = null;
            foreach ($schedules as $sched) {
                if ($sched['effective_date'] <= $date) {
                    $latest = $sched;
                }
            }
            return $latest;
        };

        $result = [];
        $cur = clone $start;

        while ($cur <= $end) {
            $curDate = $cur->format('Y-m-d');
            $hasSale = !empty($saleDates[$curDate]);

            $underlyingStatus = null;
            $underlyingSource = null;
            $isException = false;
            $effectiveDate = null;
            $recordedAt = null;
            $notes = null;

            if (isset($exceptions[$curDate])) {
                $ex = $exceptions[$curDate];
                $underlyingStatus = self::normalizeStatus($ex['status']);
                $underlyingSource = !empty($ex['source']) ? $ex['source'] : 'manual';
                $isException = true;
                $recordedAt = $ex['recorded_at'];
                $notes = self::normalizeNotes($ex['notes'] ?? null);
            } else {
                $sched = $findActiveSchedule($curDate);
                if ($sched !== null) {
                    $dayName = strtolower($cur->format('l')) . '_open';
                    $isOpen = (bool)($sched[$dayName] ?? 0);
                    $underlyingStatus = $isOpen ? 'open' : 'closed';
                    $underlyingSource = 'weekly_schedule';
                    $effectiveDate = $sched['effective_date'];
                    $recordedAt = $sched['recorded_at'];
                }
            }

            if ($hasSale) {
                $status = 'open';
                $source = 'sale';
                $hasSaleOverride = ($underlyingStatus !== 'open');
            } elseif ($isException) {
                $status = $underlyingStatus;
                $source = $underlyingSource;
                $hasSaleOverride = false;
            } elseif ($underlyingSource === 'weekly_schedule') {
                $status = $underlyingStatus;
                $source = 'weekly_schedule';
                $hasSaleOverride = false;
            } else {
                $status = null;
                $source = 'unrecorded';
                $hasSaleOverride = false;
            }

            $result[$curDate] = [
                'date' => $curDate,
                'status' => $status,
                'source' => $source,
                'is_exception' => $isException,
                'has_sale_override' => $hasSaleOverride,
                'underlying_status' => $underlyingStatus,
                'effective_date' => $effectiveDate,
                'recorded_at' => $recordedAt,
                'notes' => $notes,
            ];

            $cur->modify('+1 day');
        }

        return $result;
    }

    /**
     * Returns an associative array of [date => status] for a range of dates at cutoff.
     * Supports both ($startDate, $endDate, $cutoff) and legacy ($branchId, $startDate, $endDate, $cutoff).
     * Filters out unrecorded dates (null status) for backward compatibility.
     */
    public function getCalendarDateRangeAtCutoff(PDO $pdo, mixed ...$args): array
    {
        if (count($args) >= 4 && is_numeric($args[0])) {
            // Legacy signature: ($branchId, $startDate, $endDate, $cutoffTimestamp)
            $startDate = (string)$args[1];
            $endDate = (string)$args[2];
            $cutoffTimestamp = (string)$args[3];
        } else {
            // New signature: ($startDate, $endDate, $cutoffTimestamp)
            $startDate = (string)$args[0];
            $endDate = (string)$args[1];
            $cutoffTimestamp = (string)$args[2];
        }

        $evidenceRange = $this->getCalendarDateEvidenceRangeAtCutoff($pdo, $startDate, $endDate, $cutoffTimestamp);
        $result = [];
        foreach ($evidenceRange as $date => $item) {
            if ($item['status'] !== null) {
                $result[$date] = $item['status'];
            }
        }
        return $result;
    }
}
