<?php

return [
    'key' => '202610280001_retire_unusual_discount_and_reversal_attention',
    'description' => 'Retire unusual discount and legacy reversal attention, notifications, and settings (#330)',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("DELETE FROM notifications
            WHERE attention_key IN ('store.sales.reversals', 'store.sales.unusual-discounts')
               OR attention_destination LIKE '%/sales.php?tab=reversals%'");
        $pdo->exec("DELETE FROM attention_states
            WHERE attention_key IN ('store.sales.reversals', 'store.sales.unusual-discounts')");
        $pdo->exec("DELETE FROM attention_settings
            WHERE (setting_scope = 'store' AND setting_key IN ('reversal_count', 'unusual_discount_percent', 'inventory_escalation_count', 'inventory_unresolved_hours'))
               OR (setting_scope = 'platform' AND setting_key IN ('store_reversal_count_max', 'store_unusual_discount_percent_max', 'store_inventory_unresolved_hours_max'))");
    },
];
