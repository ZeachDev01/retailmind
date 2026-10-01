<?php

namespace App\Services;

use PDO;

/** The page's read-only request path; identity is supplied by the authenticated session. */
final class CashierOperationalHistoryRequest
{
    public static function resolve(PDO $pdo, int $authenticatedCashierId, array $query): array
    {
        $types = CashierOperationalHistoryService::types();
        $type = (string)($query['type'] ?? 'sales');
        if (!isset($types[$type])) {
            $type = 'sales';
        }
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $page = is_int($page) ? max(1, min(100000, $page)) : 1;
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

        $history = new CashierOperationalHistoryService($pdo);
        $record = $recordId === null ? null : $history->find($type, $authenticatedCashierId, $recordId);
        $rows = $recordId === null && $dateError === '' ? $history->page($type, $authenticatedCashierId, $page, CashierOperationalHistoryService::PAGE_SIZE, $fromDay, $throughDay) : [];
        $hasNext = $recordId === null && count($rows) === CashierOperationalHistoryService::PAGE_SIZE
            && $history->page($type, $authenticatedCashierId, $page + 1, CashierOperationalHistoryService::PAGE_SIZE, $fromDay, $throughDay) !== [];
        return compact('types', 'type', 'page', 'recordId', 'record', 'rows', 'hasNext', 'fromDay', 'throughDay', 'dateError');
    }
}
