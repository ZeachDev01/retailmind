<?php

use App\Database\Schema;

return [
    'key' => '202610200004_stock_counts_and_issues_forms',
    'description' => 'Support Sales Pack and loose piece forms in inventory counts and stock issues (issue #248)',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            // inventory_counts
            $countCols = $pdo->query("PRAGMA table_info(inventory_counts)")->fetchAll(PDO::FETCH_COLUMN, 1);
            $newCountCols = [
                'system_sealed_quantity' => 'INTEGER NOT NULL DEFAULT 0',
                'system_loose_quantity' => 'INTEGER NOT NULL DEFAULT 0',
                'physical_sealed_quantity' => 'INTEGER NOT NULL DEFAULT 0',
                'physical_loose_quantity' => 'INTEGER NOT NULL DEFAULT 0',
                'difference_sealed_qty' => 'INTEGER NOT NULL DEFAULT 0',
                'difference_loose_qty' => 'INTEGER NOT NULL DEFAULT 0',
            ];
            foreach ($newCountCols as $col => $definition) {
                if (!in_array($col, $countCols, true)) {
                    $pdo->exec("ALTER TABLE inventory_counts ADD COLUMN {$col} {$definition}");
                }
            }

            // inventory_count_batches
            $cbCols = $pdo->query("PRAGMA table_info(inventory_count_batches)")->fetchAll(PDO::FETCH_COLUMN, 1);
            $newCbCols = [
                'system_sealed_quantity' => 'INTEGER NOT NULL DEFAULT 0',
                'system_loose_quantity' => 'INTEGER NOT NULL DEFAULT 0',
                'physical_sealed_quantity' => 'INTEGER NOT NULL DEFAULT 0',
                'physical_loose_quantity' => 'INTEGER NOT NULL DEFAULT 0',
                'difference_sealed_qty' => 'INTEGER NOT NULL DEFAULT 0',
                'difference_loose_qty' => 'INTEGER NOT NULL DEFAULT 0',
            ];
            foreach ($newCbCols as $col => $definition) {
                if (!in_array($col, $cbCols, true)) {
                    $pdo->exec("ALTER TABLE inventory_count_batches ADD COLUMN {$col} {$definition}");
                }
            }

            // inventory_adjustments
            $adjCols = $pdo->query("PRAGMA table_info(inventory_adjustments)")->fetchAll(PDO::FETCH_COLUMN, 1);
            $newAdjCols = [
                'form' => "TEXT NOT NULL DEFAULT 'piece'",
                'reported_qty' => 'INTEGER NOT NULL DEFAULT 0',
                'pack_size' => 'INTEGER NULL',
            ];
            foreach ($newAdjCols as $col => $definition) {
                if (!in_array($col, $adjCols, true)) {
                    $pdo->exec("ALTER TABLE inventory_adjustments ADD COLUMN {$col} {$definition}");
                }
            }

            // stock_issue_batches
            $sibCols = $pdo->query("PRAGMA table_info(stock_issue_batches)")->fetchAll(PDO::FETCH_COLUMN, 1);
            $newSibCols = [
                'form' => "TEXT NOT NULL DEFAULT 'piece'",
                'reported_qty' => 'INTEGER NOT NULL DEFAULT 0',
            ];
            foreach ($newSibCols as $col => $definition) {
                if (!in_array($col, $sibCols, true)) {
                    $pdo->exec("ALTER TABLE stock_issue_batches ADD COLUMN {$col} {$definition}");
                }
            }

            $pdo->exec("UPDATE inventory_adjustments SET reported_qty = ABS(adjustment_qty), form = 'piece' WHERE reported_qty = 0 AND adjustment_qty != 0");
            $pdo->exec("UPDATE inventory_counts SET physical_loose_quantity = physical_quantity, system_loose_quantity = system_quantity, difference_loose_qty = difference_qty WHERE physical_sealed_quantity = 0 AND physical_loose_quantity = 0 AND physical_quantity > 0");
            $pdo->exec("UPDATE inventory_count_batches SET physical_loose_quantity = physical_quantity, system_loose_quantity = system_quantity, difference_loose_qty = difference_qty WHERE physical_sealed_quantity = 0 AND physical_loose_quantity = 0 AND physical_quantity > 0");
            $pdo->exec("UPDATE stock_issue_batches SET reported_qty = quantity, form = 'piece' WHERE reported_qty = 0 AND quantity > 0");
            return;
        }

        // MySQL
        Schema::addColumnIfMissing($pdo, 'inventory_counts', 'system_sealed_quantity', 'INT NOT NULL DEFAULT 0 AFTER `difference_qty`');
        Schema::addColumnIfMissing($pdo, 'inventory_counts', 'system_loose_quantity', 'INT NOT NULL DEFAULT 0 AFTER `system_sealed_quantity`');
        Schema::addColumnIfMissing($pdo, 'inventory_counts', 'physical_sealed_quantity', 'INT NOT NULL DEFAULT 0 AFTER `system_loose_quantity`');
        Schema::addColumnIfMissing($pdo, 'inventory_counts', 'physical_loose_quantity', 'INT NOT NULL DEFAULT 0 AFTER `physical_sealed_quantity`');
        Schema::addColumnIfMissing($pdo, 'inventory_counts', 'difference_sealed_qty', 'INT NOT NULL DEFAULT 0 AFTER `physical_loose_quantity`');
        Schema::addColumnIfMissing($pdo, 'inventory_counts', 'difference_loose_qty', 'INT NOT NULL DEFAULT 0 AFTER `difference_sealed_qty`');

        Schema::addColumnIfMissing($pdo, 'inventory_count_batches', 'system_sealed_quantity', 'INT NOT NULL DEFAULT 0 AFTER `difference_qty`');
        Schema::addColumnIfMissing($pdo, 'inventory_count_batches', 'system_loose_quantity', 'INT NOT NULL DEFAULT 0 AFTER `system_sealed_quantity`');
        Schema::addColumnIfMissing($pdo, 'inventory_count_batches', 'physical_sealed_quantity', 'INT NOT NULL DEFAULT 0 AFTER `system_loose_quantity`');
        Schema::addColumnIfMissing($pdo, 'inventory_count_batches', 'physical_loose_quantity', 'INT NOT NULL DEFAULT 0 AFTER `physical_sealed_quantity`');
        Schema::addColumnIfMissing($pdo, 'inventory_count_batches', 'difference_sealed_qty', 'INT NOT NULL DEFAULT 0 AFTER `physical_loose_quantity`');
        Schema::addColumnIfMissing($pdo, 'inventory_count_batches', 'difference_loose_qty', 'INT NOT NULL DEFAULT 0 AFTER `difference_sealed_qty`');

        Schema::addColumnIfMissing($pdo, 'inventory_adjustments', 'form', "VARCHAR(20) NOT NULL DEFAULT 'piece' AFTER `status`");
        Schema::addColumnIfMissing($pdo, 'inventory_adjustments', 'reported_qty', 'INT NOT NULL DEFAULT 0 AFTER `form`');
        Schema::addColumnIfMissing($pdo, 'inventory_adjustments', 'pack_size', 'INT NULL DEFAULT NULL AFTER `reported_qty`');

        Schema::addColumnIfMissing($pdo, 'stock_issue_batches', 'form', "VARCHAR(20) NOT NULL DEFAULT 'piece' AFTER `quantity`");
        Schema::addColumnIfMissing($pdo, 'stock_issue_batches', 'reported_qty', 'INT NOT NULL DEFAULT 0 AFTER `form`');

        $pdo->exec("UPDATE inventory_adjustments SET reported_qty = ABS(adjustment_qty), form = 'piece' WHERE reported_qty = 0 AND adjustment_qty != 0");
        $pdo->exec("UPDATE inventory_counts SET physical_loose_quantity = physical_quantity, system_loose_quantity = system_quantity, difference_loose_qty = difference_qty WHERE physical_sealed_quantity = 0 AND physical_loose_quantity = 0 AND physical_quantity > 0");
        $pdo->exec("UPDATE inventory_count_batches SET physical_loose_quantity = physical_quantity, system_loose_quantity = system_quantity, difference_loose_qty = difference_qty WHERE physical_sealed_quantity = 0 AND physical_loose_quantity = 0 AND physical_quantity > 0");
        $pdo->exec("UPDATE stock_issue_batches SET reported_qty = quantity, form = 'piece' WHERE reported_qty = 0 AND quantity > 0");
    },
];
