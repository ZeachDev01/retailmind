<?php

namespace App\Audit;

require_once __DIR__ . '/../Backup/RecordProvenance.php';

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
                {$whereSql}";
        $order = [];
        $columns = $this->tableColumns();
        foreach (array_slice((array)($filters['order'] ?? []), 0, 6) as $item) {
            $index = filter_var($item['column'] ?? null, FILTER_VALIDATE_INT);
            if ($index !== false && $index !== null && isset($columns[$index]) && $index < 6) {
                $direction = ($item['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
                $order[] = ($index === 5 ? 'al.record_id' : $columns[$index]) . ' ' . $direction;
            }
        }
        $sql .= ' ORDER BY ' . ($order ? implode(', ', $order) : 'al.created_at DESC') . ', al.log_id DESC';
        if (isset($filters['limit'])) {
            $sql .= ' LIMIT ' . max(1, min(2000, (int)$filters['limit']));
            $sql .= ' OFFSET ' . max(0, (int)($filters['offset'] ?? 0));
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $records = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($records !== []) {
            $isAvailable = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                && \App\Database\Schema::tableExists($this->pdo, 'store_record_origins');
            if ($isAvailable) {
                $keys = [];
                foreach ($records as $record) {
                    $keys[] = \App\Backup\RecordProvenance::key(['log_id' => $record['log_id']]);
                }
                $keys = array_values(array_unique($keys));
                if (!empty($keys)) {
                    $placeholders = implode(',', array_fill(0, count($keys), '?'));
                    $originsStmt = $this->pdo->prepare("SELECT record_origin.local_key AS record_key,
                               person.origin_id, person.source_key, person.local_key, person.original_details
                        FROM store_record_origins record_origin
                        JOIN store_record_origins person
                            ON person.origin_id = record_origin.origin_id AND person.table_name = 'users'
                        WHERE record_origin.table_name = 'activity_log'
                          AND record_origin.local_key IN ($placeholders)");
                    $originsStmt->execute($keys);
                    $byKey = [];
                    while ($row = $originsStmt->fetch(PDO::FETCH_ASSOC)) {
                        $byKey[$row['record_key']][] = $row;
                    }
                    foreach ($records as &$record) {
                        $k = \App\Backup\RecordProvenance::key(['log_id' => $record['log_id']]);
                        $candidates = $byKey[$k] ?? [];
                        $matched = array_values(array_filter($candidates, static fn($r) => (string)$r['local_key'] === (string)$record['user_id']));
                        if (count($matched) !== 1) continue;
                        $m = $matched[0];
                        $identity = [
                            'origin_id' => $m['origin_id'],
                            'source_id' => (int)$m['source_key'],
                            'local_id' => (int)$m['local_key'],
                            'original' => $m['original_details'] === null ? null : json_decode($m['original_details'], true, 512, JSON_THROW_ON_ERROR),
                        ];
                        $record['actor_provenance'] = $identity;
                        $record['full_name'] = $identity['original']['full_name'] ?? $record['full_name'];
                        $record['username'] = $identity['original']['username'] ?? $record['username'];
                    }
                    unset($record);
                }
            }
            foreach ($records as &$record) {
                self::maskSensitiveRecord($record);
            }
            unset($record);
        }
        return $records;
    }

    public static function maskSensitiveRecord(array &$record): void
    {
        foreach (['previous_value', 'new_value', 'metadata'] as $field) {
            if (empty($record[$field]) || !is_string($record[$field])) {
                continue;
            }
            $val = $record[$field];
            $decoded = json_decode($val, true);
            if (is_array($decoded)) {
                array_walk_recursive($decoded, static function (&$v, $k): void {
                    if (is_string($k) && preg_match('/password|passphrase|token|secret/i', $k)) {
                        $v = '***';
                    }
                });
                $record[$field] = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $record[$field] = preg_replace(
                    '/"(password|password_hash|new_password|current_password|passphrase|secret)"\s*:\s*"(?:\\\\"|[^"])*"/i',
                    '"$1":"***"',
                    $val
                );
            }
        }
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
            $clauses[] = 'COALESCE(u.is_recovery_account, 0) = 0';
            $clauses[] = "COALESCE(al.module, '') <> 'Recovery Account'";
            $clauses[] = "al.category <> '" . AuditRecordCategory::RECOVERY_ACCOUNT . "'";
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
        $columns = $this->tableColumns();
        // Match each word across columns; quotes keep a multi-word phrase together.
        preg_match_all('/"([^"]+)"|(\S+)/u', (string)($filters['search'] ?? ''), $terms, PREG_SET_ORDER);
        foreach ($terms as $term) {
            $search = '%' . $this->escapeLike($term[1] !== '' ? $term[1] : $term[2]) . '%';
            $clauses[] = '(' . implode(' OR ', array_map(static fn(string $column): string => "{$column} LIKE ? ESCAPE '!'", $columns)) . ')';
            array_push($params, ...array_fill(0, count($columns), $search));
        }
        foreach ($columns as $index => $column) {
            $search = $filters['columns'][$index]['columnControl']['search'] ?? [];
            $value = trim((string)($search['value'] ?? ''));
            $logic = (string)($search['logic'] ?? 'contains');
            if ($logic === 'empty' || $logic === 'notEmpty') {
                $clauses[] = "{$column} " . ($logic === 'empty' ? "= ''" : "<> ''");
            } elseif ($value !== '') {
                if ($index === 0) {
                    $column = 'DATE(al.created_at)';
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if (!$date || $date->format('Y-m-d') !== $value) {
                        throw new \InvalidArgumentException('Enter a valid date in YYYY-MM-DD format.');
                    }
                } elseif ($index === 5) {
                    if (!is_numeric($value)) {
                        throw new \InvalidArgumentException('Enter a valid record number.');
                    }
                    $column = 'al.record_id';
                }
                $operator = match ($logic) {
                    'equal' => '=', 'notEqual' => '<>', 'greater' => '>', 'greaterOrEqual' => '>=',
                    'less' => '<', 'lessOrEqual' => '<=', 'notContains' => 'NOT LIKE',
                    'contains', 'starts', 'ends' => 'LIKE',
                    default => throw new \InvalidArgumentException('Choose a supported search condition.'),
                };
                $clauses[] = "{$column} {$operator} ?" . (str_contains($operator, 'LIKE') ? " ESCAPE '!'" : '');
                $params[] = match ($logic) {
                    'contains', 'notContains' => '%' . $this->escapeLike($value) . '%',
                    'starts' => $this->escapeLike($value) . '%',
                    'ends' => '%' . $this->escapeLike($value),
                    default => $value,
                };
            }
        }
        if (isset($filters['log_ids']) && is_array($filters['log_ids'])) {
            $validIds = array_values(array_unique(array_filter(array_map('intval', $filters['log_ids']), static fn(int $id): bool => $id > 0)));
            if ($validIds === []) {
                $clauses[] = '1 = 0';
            } else {
                $clauses[] = 'al.log_id IN (' . implode(', ', array_fill(0, count($validIds), '?')) . ')';
                array_push($params, ...$validIds);
            }
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

    private function tableColumns(): array
    {
        return [
            "CAST(al.created_at AS CHAR)",
            "CASE WHEN u.is_recovery_account = 1 THEN 'Recovery Account' WHEN al.user_id IS NULL THEN 'System / Unknown' ELSE CONCAT_WS(' ', u.full_name, u.username) END",
            'al.action',
            "REPLACE(al.category, '_', ' ')",
            "COALESCE(al.module, '-')",
            "COALESCE(CAST(al.record_id AS CHAR), '-')",
            "CONCAT_WS(' ', al.ip_address, al.previous_value, al.new_value, CASE WHEN al.action IN ('Failed sign-ins reviewed', 'Application log acknowledged') THEN al.metadata END)",
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
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
