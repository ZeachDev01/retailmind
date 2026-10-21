<?php

use App\Database\Schema;

return [
    'key' => '202610210001_notification_workspaces',
    'description' => 'Scope notification history and pending daily email by Workspace',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'attention_states', 'notification_level', 'INT NOT NULL DEFAULT 0');
        Schema::addColumnIfMissing($pdo, 'notifications', 'workspace', "VARCHAR(30) NOT NULL DEFAULT ''");
        Schema::addColumnIfMissing($pdo, 'notifications', 'inapp_enabled', 'BOOLEAN NOT NULL DEFAULT TRUE');
        Schema::addColumnIfMissing($pdo, 'notifications', 'email_pending', 'BOOLEAN NOT NULL DEFAULT FALSE');
        Schema::addIndexIfMissing($pdo, 'notifications', 'idx_notifications_workspace', '`user_id`, `workspace`, `inapp_enabled`, `is_read`');
        // Existing alerts are already known to their recipients: seed the level memory from the
        // last alert's severity so the first run after upgrade cannot replay every active condition.
        $pdo->exec("UPDATE attention_states s SET s.notification_level = COALESCE((
                SELECT CASE n.attention_severity WHEN 'critical' THEN 3000 WHEN 'warning' THEN 2000 ELSE 1000 END
                FROM notifications n
                WHERE n.user_id = s.user_id AND n.attention_key = s.attention_key
                ORDER BY n.notification_id DESC LIMIT 1), 1000)
            WHERE s.is_active = 1 AND s.notification_level = 0");
        $pdo->exec("UPDATE notifications n LEFT JOIN inventory_adjustments ia
            ON n.reference_type = 'stock_issue' AND ia.adjustment_id = n.reference_id
            SET n.workspace = CASE
                WHEN n.reference_type = 'stock_issue' THEN IF(ia.reported_by = n.user_id, 'cashier', 'inventory_manager')
                WHEN n.attention_key LIKE 'platform.%' OR n.reference_type = 'model_training' THEN 'super_admin'
                WHEN n.attention_key LIKE 'store.%' OR n.type = 'fiscal_period' THEN 'admin'
                WHEN n.type IN ('low_stock','replenishment','adjustment','expiring_stock','expired_stock') THEN 'inventory_manager'
                ELSE COALESCE(
                    (SELECT r.role_name FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                     WHERE ur.user_id = n.user_id
                     ORDER BY FIELD(r.role_name, 'super_admin', 'admin', 'inventory_manager', 'cashier') LIMIT 1),
                    (SELECT r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = n.user_id),
                    '')
            END WHERE n.workspace = ''");
    },
];
