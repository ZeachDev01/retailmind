<?php

return [
    'key' => '202611100001_fiscal_period_scheduling',
    'description' => 'Allow scheduled future Fiscal Periods and opt-in automatic rollover',
    'up' => static function (PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        // 'scheduled' reserves dates without accepting transactions; 'open'
        // stays unique through the existing open_period_key generated column.
        $pdo->exec(
            "ALTER TABLE fiscal_periods
             MODIFY COLUMN status ENUM('scheduled','open','closed','locked') NULL DEFAULT 'open'"
        );
    },
];
