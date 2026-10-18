<?php

use App\Database\Schema;

return [
    'key' => '202610180003_product_warning_lead_time',
    'description' => 'Optional warning_lead_time_days on products (ticket #223)',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('warning_lead_time_days', $cols, true)) {
                $pdo->exec("ALTER TABLE products ADD COLUMN warning_lead_time_days INTEGER NULL");
            }
            return;
        }

        Schema::addColumnIfMissing(
            $pdo,
            'products',
            'warning_lead_time_days',
            'INT NULL AFTER `expiration_date`'
        );
    },
];
