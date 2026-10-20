<?php

use App\Database\Schema;

return [
    'key' => '202610200006_cash_refund_sales_pack_forms',
    'description' => 'Support selling_form and pack_size on cash_refund_items (issue #252)',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(cash_refund_items)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('selling_form', $cols, true)) {
                $pdo->exec("ALTER TABLE cash_refund_items ADD COLUMN selling_form TEXT NOT NULL DEFAULT 'piece'");
            }
            if (!in_array('pack_size', $cols, true)) {
                $pdo->exec("ALTER TABLE cash_refund_items ADD COLUMN pack_size INTEGER NULL");
            }
            return;
        }

        Schema::addColumnIfMissing(
            $pdo,
            'cash_refund_items',
            'selling_form',
            "ENUM('piece', 'pack') NOT NULL DEFAULT 'piece' AFTER `product_id`"
        );
        Schema::addColumnIfMissing(
            $pdo,
            'cash_refund_items',
            'pack_size',
            "INT NULL DEFAULT NULL AFTER `selling_form`"
        );
    },
];
