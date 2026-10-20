<?php

return [
    'key' => '202610190001_attention_notification_type',
    'description' => 'Allow shared attention notifications in the daily operational check',
    'up' => static function (PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec("ALTER TABLE notifications MODIFY COLUMN type
                ENUM('low_stock','replenishment','adjustment','system','fiscal_period','expiring_stock','expired_stock','attention') NOT NULL");
        }
    },
];
