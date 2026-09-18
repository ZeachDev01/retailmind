<?php

namespace App\Audit;

use App\Authorization\RoleCapabilityPolicy;
use DomainException;
use PDO;

final class ProtectedAuditRecordService
{
    public function __construct(
        private PDO $pdo,
        private RoleCapabilityPolicy $policy
    ) {
    }

    public function records(string $actorRole, array $filters = []): array
    {
        [$whereSql, $params] = $this->where($actorRole, $filters);
        $sql = "SELECT al.log_id, al.user_id, al.action, al.category, al.module, al.record_id,
                       al.previous_value, al.new_value, al.metadata, al.ip_address, al.created_at,
                       CASE WHEN u.is_recovery_account = 1 THEN 'Recovery Account' ELSE u.full_name END AS full_name,
                       CASE WHEN u.is_recovery_account = 1 THEN NULL ELSE u.username END AS username
                FROM activity_log al
                LEFT JOIN users u ON al.user_id = u.user_id
                {$whereSql}
                ORDER BY al.created_at DESC, al.log_id DESC";
        if (isset($filters['limit'])) {
            $sql .= ' LIMIT ' . max(1, min(100, (int)$filters['limit']));
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(string $actorRole, array $filters = []): int
    {
        [$whereSql, $params] = $this->where($actorRole, $filters);
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM activity_log al LEFT JOIN users u ON al.user_id = u.user_id {$whereSql}");
        $statement->execute($params);
        return (int)$statement->fetchColumn();
    }

    public function exportRows(string $actorRole, array $filters = []): array
    {
        return $this->records($actorRole, $filters);
    }

    public function modules(string $actorRole): array
    {
        [$whereSql, $params] = $this->where($actorRole, []);
        $moduleCondition = $whereSql === '' ? 'WHERE' : $whereSql . ' AND';
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT al.module FROM activity_log al {$moduleCondition} al.module IS NOT NULL AND al.module <> '' ORDER BY al.module"
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function where(string $actorRole, array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT)) {
            if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::VIEW_STORE_AUDIT)) {
                throw new DomainException('Your account cannot view Protected Audit Records.');
            }
            $clauses[] = 'al.category = ?';
            $params[] = AuditRecordCategory::STORE_OPERATION;
        }

        $this->exactFilter($clauses, $params, 'al.user_id', $filters['user_id'] ?? null, true);
        $this->likeFilter($clauses, $params, 'al.action', $filters['action'] ?? null);
        $this->exactFilter($clauses, $params, 'al.module', $filters['module'] ?? null);
        if (($filters['category'] ?? '') !== '') {
            $clauses[] = 'al.category = ?';
            $params[] = AuditRecordCategory::requireValid((string)$filters['category']);
        }
        if (($filters['date_from'] ?? '') !== '') {
            $clauses[] = 'DATE(al.created_at) >= ?';
            $params[] = (string)$filters['date_from'];
        }
        if (($filters['date_to'] ?? '') !== '') {
            $clauses[] = 'DATE(al.created_at) <= ?';
            $params[] = (string)$filters['date_to'];
        }
        if (($filters['search'] ?? '') !== '') {
            $search = '%' . (string)$filters['search'] . '%';
            $clauses[] = '(al.action LIKE ? OR al.module LIKE ? OR CAST(al.record_id AS CHAR) LIKE ? OR u.full_name LIKE ? OR (u.is_recovery_account = 0 AND u.username LIKE ?))';
            array_push($params, $search, $search, $search, $search, $search);
        }
        if (($filters['critical_only'] ?? false) === true) {
            $criticalTerms = ['void', 'delete', 'reversal', 'refund', 'fiscal', 'locked', 'toggled', 'adjustment', 'count'];
            $clauses[] = '(' . implode(' OR ', array_fill(0, count($criticalTerms), 'LOWER(al.action) LIKE ?')) . ')';
            foreach ($criticalTerms as $term) {
                $params[] = '%' . $term . '%';
            }
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    private function exactFilter(array &$clauses, array &$params, string $column, $value, bool $integer = false): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $clauses[] = "{$column} = ?";
        $params[] = $integer ? (int)$value : (string)$value;
    }

    private function likeFilter(array &$clauses, array &$params, string $column, $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $clauses[] = "{$column} LIKE ?";
        $params[] = '%' . (string)$value . '%';
    }
}
