<?php

declare(strict_types=1);

namespace App\GCash;

use App\Services\PhilippineTime;
use InvalidArgumentException;
use PDO;

final class GCashSessionHistory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function page(array $query, ?int $ownCashierId): array
    {
        $cashierId = $ownCashierId ?? (filter_var($query['session_cashier'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null);
        $status = in_array($query['session_status'] ?? '', ['open', 'closed'], true) ? $query['session_status'] : '';
        $from = is_string($query['session_from'] ?? null) ? $query['session_from'] : '';
        $to = is_string($query['session_to'] ?? null) ? $query['session_to'] : '';
        $pageSize = filter_var($query['session_length'] ?? 15, FILTER_VALIDATE_INT);
        $pageSize = in_array($pageSize, [10, 15, 25, 50, 100], true) ? $pageSize : 15;
        $page = filter_var($query['session_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $conditions = [];
        $params = [];
        if ($cashierId !== null) {
            $conditions[] = 's.cashier_id = ?';
            $params[] = $cashierId;
        }
        if ($ownCashierId === null && $status !== '') {
            $conditions[] = 's.status = ?';
            $params[] = $status;
        }
        if ($ownCashierId === null && ($from !== '' || $to !== '')) {
            if ($from !== '' && $to !== '' && $from > $to) {
                throw new InvalidArgumentException('Choose an end date on or after the start date.');
            }
            $bounds = [];
            $dateParams = [];
            if ($from !== '') {
                $bounds[] = '%s >= ?';
                $dateParams[] = PhilippineTime::dayStart($from);
            }
            if ($to !== '') {
                $bounds[] = '%s < ?';
                $dateParams[] = PhilippineTime::dayAfter($to);
            }
            $dateConditions = implode(' AND ', $bounds);
            $conditions[] = '((' . str_replace('%s', 's.opened_at', $dateConditions)
                . ') OR (' . str_replace('%s', 's.closed_at', $dateConditions) . '))';
            $params = array_merge($params, $dateParams, $dateParams);
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM gcash_service_sessions s' . $where);
        $count->execute($params);
        $totalRows = (int)$count->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;
        $stmt = $this->pdo->prepare(
            "SELECT s.session_id, s.status, s.wallet_number, s.opened_at, s.closed_at, s.opening_service_cash, s.opening_wallet_balance,
                    s.closing_service_cash, s.closing_wallet_balance,
                    COALESCE(NULLIF(u.display_name, ''), u.username) AS cashier_name,
                    COALESCE(NULLIF(c.display_name, ''), c.username) AS closed_by_name
             FROM gcash_service_sessions s
             JOIN users u ON u.user_id = s.cashier_id
             LEFT JOIN users c ON c.user_id = s.closed_by
             $where
             ORDER BY COALESCE(s.closed_at, s.opened_at) DESC, s.session_id DESC
             LIMIT $pageSize OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return compact('rows', 'page', 'pageSize', 'totalRows', 'totalPages', 'cashierId', 'status', 'from', 'to');
    }

    public function cashiers(): array
    {
        return $this->pdo->query(
            "SELECT DISTINCT u.user_id, COALESCE(NULLIF(u.display_name, ''), u.username) AS cashier_name
             FROM gcash_service_sessions s JOIN users u ON u.user_id = s.cashier_id
             ORDER BY cashier_name, u.user_id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
