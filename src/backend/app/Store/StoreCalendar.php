<?php

namespace App\Store;

use App\Database\Schema;
use PDO;
use PDOStatement;
use RuntimeException;

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
     * Records a new calendar entry for a specific date.
     * Supports both (pdo, date, status, userId) and legacy (pdo, branchId, date, status, userId).
     * Avoids duplicate history if current status already matches.
     */
    public function recordCalendarDate(PDO $pdo, mixed ...$args): void
    {
        if (count($args) === 4 && is_numeric($args[0])) {
            // Legacy signature: ($branchId, $date, $status, $userId)
            $date = (string)$args[1];
            $status = self::normalizeStatus((string)$args[2]);
            $userId = $args[3] !== null ? (int)$args[3] : null;
        } else {
            // New signature: ($date, $status, $userId)
            $date = (string)$args[0];
            $status = self::normalizeStatus((string)$args[1]);
            $userId = isset($args[2]) && $args[2] !== null ? (int)$args[2] : null;
        }

        // Check if latest status matches to avoid redundant history
        $latest = $this->getCalendarDateStatusAtCutoff($pdo, $date, date('Y-m-d H:i:s'));
        if ($latest === $status) {
            return;
        }

        $stmt = $this->prepareCalendarInsert($pdo);
        $stmt->execute([$date, str_replace('_', '-', $status), $userId]);
    }

    /**
     * Records a calendar status across an inclusive date range [startDate, endDate].
     * Enforces bounds order, 366-day cap, skips unchanged dates, and inserts changed dates.
     * Returns array of changed date details: [['date' => ..., 'previous_status' => ..., 'new_status' => ...], ...]
     */
    public function recordCalendarRange(PDO $pdo, string $startDate, string $endDate, string $status, ?int $userId = null): array
    {
        $status = self::normalizeStatus($status);
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);

        if ($start > $end) {
            throw new \InvalidArgumentException("Invalid date range: start date '{$startDate}' must be before or equal to through date '{$endDate}' (reversed bounds).");
        }

        // Inclusive day count
        $diffDays = (int)$start->diff($end)->format('%a') + 1;
        if ($diffDays > 366) {
            throw new \InvalidArgumentException("Date range exceeds the maximum allowed limit of 366 inclusive days ({$diffDays} days requested).");
        }

        // Fetch current statuses across the range
        $currentStatuses = $this->getCalendarDateRangeAtCutoff($pdo, $startDate, $endDate, date('Y-m-d H:i:s'));

        $stmt = $this->prepareCalendarInsert($pdo);

        $changed = [];
        $cur = clone $start;

        while ($cur <= $end) {
            $curDate = $cur->format('Y-m-d');
            $prevStatus = $currentStatuses[$curDate] ?? null;

            if ($prevStatus !== $status) {
                $stmt->execute([$curDate, str_replace('_', '-', $status), $userId]);
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

    private function prepareCalendarInsert(PDO $pdo): PDOStatement
    {
        $columns = 'calendar_date, status, recorded_by, recorded_at';
        $values = '?, ?, ?, CURRENT_TIMESTAMP';
        // Retain the internal Store identity on legacy schemas without requiring it on fresh installs.
        if (Schema::columnExists($pdo, 'store_calendar', 'branch_id')) {
            $columns .= ', branch_id';
            $values .= ', ' . (new StoreScope($pdo))->id();
        }
        return $pdo->prepare("INSERT INTO store_calendar ({$columns}) VALUES ({$values})");
    }

    /**
     * Reconstructs the status of a specific date at cutoff.
     * Supports both ($date, $cutoff) and legacy ($branchId, $date, $cutoff).
     * Resolves equal timestamps deterministically using calendar_id DESC.
     */
    public function getCalendarDateStatusAtCutoff(PDO $pdo, mixed ...$args): ?string
    {
        if (count($args) === 3 && is_numeric($args[0])) {
            // Legacy signature: ($branchId, $date, $cutoffTimestamp)
            $date = (string)$args[1];
            $cutoffTimestamp = (string)$args[2];
        } else {
            // New signature: ($date, $cutoffTimestamp)
            $date = (string)$args[0];
            $cutoffTimestamp = (string)$args[1];
        }

        $stmt = $pdo->prepare(
            'SELECT status FROM store_calendar 
             WHERE calendar_date = ? AND recorded_at <= ? 
             ORDER BY recorded_at DESC, calendar_id DESC 
             LIMIT 1'
        );
        $stmt->execute([$date, $cutoffTimestamp]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : self::normalizeStatus((string)$status);
    }

    /**
     * Returns an associative array of [date => status] for a range of dates at cutoff.
     * Supports both ($startDate, $endDate, $cutoff) and legacy ($branchId, $startDate, $endDate, $cutoff).
     * Resolves equal timestamps deterministically using calendar_id DESC.
     */
    public function getCalendarDateRangeAtCutoff(PDO $pdo, mixed ...$args): array
    {
        if (count($args) === 4 && is_numeric($args[0])) {
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

        $sql = '
            SELECT c1.calendar_date, c1.status 
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
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$startDate, $endDate, $cutoffTimestamp, $cutoffTimestamp]);

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['calendar_date']] = self::normalizeStatus($row['status']);
        }
        return $result;
    }
}
