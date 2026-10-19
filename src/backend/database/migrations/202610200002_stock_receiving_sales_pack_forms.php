<?php

use App\Database\Schema;

return [
    'key' => '202610200002_stock_receiving_sales_pack_forms',
    'description' => 'Support Sales Pack and loose piece forms in stock receiving (issue #247)',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(stock_receiving)")->fetchAll(PDO::FETCH_COLUMN, 1);
            $newCols = [
                'received_packs' => 'INTEGER NOT NULL DEFAULT 0',
                'accepted_packs' => 'INTEGER NOT NULL DEFAULT 0',
                'damaged_packs' => 'INTEGER NOT NULL DEFAULT 0',
                'rejected_packs' => 'INTEGER NOT NULL DEFAULT 0',
                'received_loose' => 'INTEGER NOT NULL DEFAULT 0',
                'accepted_loose' => 'INTEGER NOT NULL DEFAULT 0',
                'damaged_loose' => 'INTEGER NOT NULL DEFAULT 0',
                'rejected_loose' => 'INTEGER NOT NULL DEFAULT 0',
                'pack_size_used' => 'INTEGER NULL',
            ];
            foreach ($newCols as $col => $definition) {
                if (!in_array($col, $cols, true)) {
                    $pdo->exec("ALTER TABLE stock_receiving ADD COLUMN {$col} {$definition}");
                }
            }
            $pdo->exec("UPDATE stock_receiving SET received_loose = received_qty, accepted_loose = accepted_qty, damaged_loose = damaged_qty WHERE received_loose = 0 AND accepted_loose = 0 AND received_qty > 0");
            return;
        }

        Schema::addColumnIfMissing($pdo, 'stock_receiving', 'received_packs', 'INT NOT NULL DEFAULT 0 AFTER `pullout_id`');
        Schema::addColumnIfMissing($pdo, 'stock_receiving', 'accepted_packs', 'INT NOT NULL DEFAULT 0 AFTER `received_packs`');
        Schema::addColumnIfMissing($pdo, 'stock_receiving', 'damaged_packs', 'INT NOT NULL DEFAULT 0 AFTER `accepted_packs`');
        Schema::addColumnIfMissing($pdo, 'stock_receiving', 'rejected_packs', 'INT NOT NULL DEFAULT 0 AFTER `damaged_packs`');
        Schema::addColumnIfMissing($pdo, 'stock_receiving', 'received_loose', 'INT NOT NULL DEFAULT 0 AFTER `rejected_packs`');
        Schema::addColumnIfMissing($pdo, 'stock_receiving', 'accepted_loose', 'INT NOT NULL DEFAULT 0 AFTER `received_loose`');
        Schema::addColumnIfMissing($pdo, 'stock_receiving', 'damaged_loose', 'INT NOT NULL DEFAULT 0 AFTER `accepted_loose`');
        Schema::addColumnIfMissing($pdo, 'stock_receiving', 'rejected_loose', 'INT NOT NULL DEFAULT 0 AFTER `damaged_loose`');
        Schema::addColumnIfMissing($pdo, 'stock_receiving', 'pack_size_used', 'INT NULL DEFAULT NULL AFTER `rejected_loose`');

        $pdo->exec("UPDATE stock_receiving SET received_loose = received_qty, accepted_loose = accepted_qty, damaged_loose = damaged_qty WHERE received_loose = 0 AND accepted_loose = 0 AND received_qty > 0");
    },
];
