<?php

return [
    'key' => '202611030002_retire_recovery_account_page',
    'description' => 'Point saved Recovery Account warnings to Protected Audit Records',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("UPDATE notifications
            SET attention_destination = 'components/system_administrator/audit_logs.php'
            WHERE attention_destination = 'components/system_administrator/recovery_account.php'");
    },
];
