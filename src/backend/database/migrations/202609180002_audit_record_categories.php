<?php

use App\Audit\AuditRecordCategory;
use App\Database\Schema;

return [
    'key' => '202609180002_audit_record_categories',
    'description' => 'Categorize Protected Audit Records for authority-scoped visibility',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing(
            $pdo,
            'activity_log',
            'category',
            "ENUM('store_operation','security','recovery','platform_setting','recovery_account') NULL AFTER `action`"
        );

        $roleNames = [];
        foreach ($pdo->query('SELECT role_id, role_name FROM roles')->fetchAll(PDO::FETCH_ASSOC) as $role) {
            $roleNames[(int)$role['role_id']] = (string)$role['role_name'];
        }
        $uncategorized = $pdo->query(
            'SELECT log_id, module, action, previous_value, new_value FROM activity_log WHERE category IS NULL ORDER BY log_id'
        );
        $categorize = $pdo->prepare('UPDATE activity_log SET category = ? WHERE log_id = ?');
        foreach ($uncategorized->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $snapshots = [];
            foreach (['new_value', 'previous_value'] as $field) {
                $snapshot = json_decode((string)($record[$field] ?? ''), true);
                if (is_array($snapshot) && isset($snapshot['role_id'], $roleNames[(int)$snapshot['role_id']])) {
                    $snapshot['role'] = $roleNames[(int)$snapshot['role_id']];
                }
                $snapshots[] = is_array($snapshot) ? $snapshot : $record[$field] ?? null;
            }
            $categorize->execute([
                AuditRecordCategory::classify(
                    $record['module'] ?? null,
                    (string)$record['action'],
                    $snapshots[0],
                    $snapshots[1]
                ),
                (int)$record['log_id'],
            ]);
        }
        $pdo->exec(
            "ALTER TABLE activity_log MODIFY category
             ENUM('store_operation','security','recovery','platform_setting','recovery_account') NOT NULL"
        );
        Schema::addIndexIfMissing($pdo, 'activity_log', 'idx_activity_log_category_created', '`category`, `created_at`');
    },
];
