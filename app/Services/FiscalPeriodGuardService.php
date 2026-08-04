<?php
// app/Services/FiscalPeriodGuardService.php

class FiscalPeriodGuardService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function assertOpenForDate(string $date, string $tableName, string $operationLabel = 'transaction'): void
    {
        $period = $this->findPeriodForDate($date);
        if (!$period) {
            return;
        }

        if (in_array($period['status'], ['closed', 'locked'], true)) {
            throw new RuntimeException(sprintf(
                'Cannot record %s dated %s because fiscal period "%s" is %s.',
                $operationLabel,
                $this->formatDate($date),
                $period['period_name'],
                $period['status']
            ));
        }

        if ($this->hasLock((int)$period['period_id'], $tableName)) {
            throw new RuntimeException(sprintf(
                'Cannot record %s dated %s because fiscal period "%s" is locked for %s.',
                $operationLabel,
                $this->formatDate($date),
                $period['period_name'],
                $tableName
            ));
        }
    }

    public function assertOpenNow(string $tableName, string $operationLabel = 'transaction'): void
    {
        $this->assertOpenForDate(date('Y-m-d H:i:s'), $tableName, $operationLabel);
    }

    private function findPeriodForDate(string $date): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT period_id, period_name, status
             FROM fiscal_periods
             WHERE DATE(?) BETWEEN start_date AND end_date
             ORDER BY start_date DESC, period_id DESC
             LIMIT 1"
        );
        $stmt->execute([$date]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);

        return $period ?: null;
    }

    private function hasLock(int $periodId, string $tableName): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM fiscal_period_locks
             WHERE period_id = ?
               AND (table_name IS NULL OR table_name = '' OR table_name = ?)"
        );
        $stmt->execute([$periodId, $tableName]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function formatDate(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp ? date('Y-m-d', $timestamp) : $date;
    }
}
