<?php

namespace App\Store;

use PDO;
use RuntimeException;

final class StoreCalendar
{
    /**
     * Records a new calendar entry for a specific date. 
     * The new entry is appended to history.
     */
    public function recordCalendarDate(PDO $pdo, int $branchId, string $date, string $status, ?int $userId): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO store_calendar (branch_id, calendar_date, status, recorded_by, recorded_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([$branchId, $date, $status, $userId]);
    }

    /**
     * Reconstructs the status of a specific date as it was known at the cutoff time.
     * Returns 'open', 'closed', 'holiday', 'exceptional-closure', or null if unrecorded.
     */
    public function getCalendarDateStatusAtCutoff(PDO $pdo, int $branchId, string $date, string $cutoffTimestamp): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT status FROM store_calendar WHERE branch_id = ? AND calendar_date = ? AND recorded_at <= ? ORDER BY recorded_at DESC LIMIT 1'
        );
        $stmt->execute([$branchId, $date, $cutoffTimestamp]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : (string)$status;
    }

    /**
     * Returns an associative array of [date => status] for a range of dates, 
     * as known at the cutoff time. Missing dates will not be in the array.
     */
    public function getCalendarDateRangeAtCutoff(PDO $pdo, int $branchId, string $startDate, string $endDate, string $cutoffTimestamp): array
    {
        // To get the latest status per date before cutoff, we can use a subquery or a JOIN.
        // A simple correlated subquery works well since the range is usually small (e.g., a month).
        $sql = '
            SELECT c1.calendar_date, c1.status 
            FROM store_calendar c1
            WHERE c1.branch_id = ? 
              AND c1.calendar_date >= ? 
              AND c1.calendar_date <= ?
              AND c1.recorded_at <= ?
              AND c1.recorded_at = (
                  SELECT MAX(c2.recorded_at) 
                  FROM store_calendar c2 
                  WHERE c2.branch_id = c1.branch_id 
                    AND c2.calendar_date = c1.calendar_date 
                    AND c2.recorded_at <= ?
              )
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$branchId, $startDate, $endDate, $cutoffTimestamp, $cutoffTimestamp]);
        
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['calendar_date']] = $row['status'];
        }
        return $result;
    }
}
