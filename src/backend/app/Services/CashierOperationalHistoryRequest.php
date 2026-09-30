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

        $history = new CashierOperationalHistoryService($pdo);
        $record = $recordId === null ? null : $history->find($type, $authenticatedCashierId, $recordId);
        $rows = $recordId === null ? $history->page($type, $authenticatedCashierId, $page) : [];
        $hasNext = $recordId === null && count($rows) === CashierOperationalHistoryService::PAGE_SIZE
            && $history->page($type, $authenticatedCashierId, $page + 1) !== [];
        return compact('types', 'type', 'page', 'recordId', 'record', 'rows', 'hasNext');
    }
}
