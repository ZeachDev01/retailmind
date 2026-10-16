<?php

return [
    'key' => '202610150002_retire_procurement_attention',
    'description' => 'Remove retired purchasing notifications and approval thresholds (#186)',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("DELETE FROM notifications
            WHERE type = 'replenishment'
               OR reference_type IN ('replenishment_request', 'purchase_order')
               OR attention_key = 'store.approvals.overdue'
               OR attention_destination LIKE '%/replenishment_requests.php%'
               OR attention_destination LIKE '%/purchase_orders.php%'");
        $pdo->exec("DELETE FROM attention_states WHERE attention_key = 'store.approvals.overdue'");
        $pdo->exec("DELETE FROM attention_settings
            WHERE (setting_scope = 'store' AND setting_key = 'overdue_approval_hours')
               OR (setting_scope = 'platform' AND setting_key = 'store_overdue_approval_hours_max')");
    },
];
