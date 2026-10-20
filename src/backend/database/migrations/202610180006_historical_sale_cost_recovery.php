<?php

use App\Database\Schema;

return [
    'key' => '202610180006_historical_sale_cost_recovery',
    'description' => 'Gross Profit: establish stable historical costs for older sales (ticket #230)',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return;
        }

        if (Schema::tableExists($pdo, 'sale_item_batches')) {
            $pdo->exec("ALTER TABLE `sale_item_batches` MODIFY COLUMN `batch_id` INT(11) NULL");
        }
    },
];
