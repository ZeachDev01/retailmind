<?php
// src/backend/database/migrations/202610200005_supplier_movements_stock_forms.php

use App\Database\Schema;

return [
    'key' => '202610200005_supplier_movements_stock_forms',
    'description' => 'Preserve sealed packs and loose pieces on supplier pullouts and replacement receiving (issue #249)',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(supplier_pullouts)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('pulled_packs', $cols, true)) {
                $pdo->exec('ALTER TABLE supplier_pullouts ADD COLUMN pulled_packs INTEGER NOT NULL DEFAULT 0');
            }
            if (!in_array('pulled_loose', $cols, true)) {
                $pdo->exec('ALTER TABLE supplier_pullouts ADD COLUMN pulled_loose INTEGER NOT NULL DEFAULT 0');
            }
            if (!in_array('pack_size_used', $cols, true)) {
                $pdo->exec('ALTER TABLE supplier_pullouts ADD COLUMN pack_size_used INTEGER NOT NULL DEFAULT 1');
            }
            $pdo->exec('UPDATE supplier_pullouts SET pulled_loose = quantity WHERE pulled_packs = 0 AND pulled_loose = 0 AND quantity > 0');
            $pdo->exec('UPDATE supplier_pullouts SET pack_size_used = 1 WHERE pack_size_used IS NULL OR pack_size_used <= 0');
            return;
        }

        Schema::addColumnIfMissing($pdo, 'supplier_pullouts', 'pulled_packs', 'INT NOT NULL DEFAULT 0 AFTER `quantity`');
        Schema::addColumnIfMissing($pdo, 'supplier_pullouts', 'pulled_loose', 'INT NOT NULL DEFAULT 0 AFTER `pulled_packs`');
        Schema::addColumnIfMissing($pdo, 'supplier_pullouts', 'pack_size_used', 'INT NOT NULL DEFAULT 1 AFTER `pulled_loose`');

        $pdo->exec('UPDATE supplier_pullouts SET pulled_loose = quantity WHERE pulled_packs = 0 AND pulled_loose = 0 AND quantity > 0');
        $pdo->exec('UPDATE supplier_pullouts SET pack_size_used = 1 WHERE pack_size_used IS NULL OR pack_size_used <= 0');
    },
];
