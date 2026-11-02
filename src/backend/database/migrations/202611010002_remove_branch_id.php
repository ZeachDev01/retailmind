<?php

use App\Database\Schema;

return [
    'key' => '202611010002_remove_branch_id',
    'description' => 'Remove obsolete branch identifiers from the single-store schema',
    'up' => static function (PDO $pdo): void {
        $storeIds = [];
        foreach (['users', 'products', 'store_calendar'] as $table) {
            if (!Schema::tableExists($pdo, $table) || !Schema::columnExists($pdo, $table, 'branch_id')) {
                continue;
            }
            $ids = $pdo->query("SELECT DISTINCT branch_id FROM `{$table}` WHERE branch_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $storeIds = array_merge($storeIds, array_map('intval', $ids));
        }
        if (count(array_unique($storeIds)) > 1) {
            throw new RuntimeException('Cannot remove branch identifiers while records belong to multiple stores. Consolidate stores first.');
        }

        if (Schema::tableExists($pdo, 'products')) {
            foreach (['sku', 'barcode', 'case_barcode', 'pack_barcode'] as $column) {
                $statement = $pdo->query("SELECT 1 FROM products WHERE `{$column}` IS NOT NULL GROUP BY `{$column}` HAVING COUNT(*) > 1 LIMIT 1");
                if ($statement->fetchColumn()) {
                    throw new RuntimeException("Cannot remove branch scope: duplicate product {$column} values exist.");
                }
            }
            $indexes = $pdo->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND (INDEX_NAME LIKE 'uq_products_branch_%' OR INDEX_NAME='idx_products_branch_id')")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($indexes as $index) {
                $quoted = str_replace('`', '``', (string)$index);
                $pdo->exec("ALTER TABLE products DROP INDEX `{$quoted}`");
            }
            if (Schema::columnExists($pdo, 'products', 'branch_id')) {
                $pdo->exec('ALTER TABLE products DROP COLUMN branch_id');
            }
            foreach (['sku', 'barcode', 'case_barcode', 'pack_barcode'] as $column) {
                $uniqueIndex = $pdo->prepare("SELECT MIN(NON_UNIQUE) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND INDEX_NAME=?");
                $uniqueIndex->execute([$column]);
                if ($uniqueIndex->fetchColumn() === '1') {
                    $quoted = str_replace('`', '``', $column);
                    $pdo->exec("ALTER TABLE products DROP INDEX `{$quoted}`");
                }
                $uniqueIndex->execute([$column]);
                $nonUnique = $uniqueIndex->fetchColumn();
                if ($nonUnique === false || $nonUnique === null) {
                    $pdo->exec("ALTER TABLE products ADD UNIQUE KEY `{$column}` (`{$column}`)");
                }
            }
        }

        if (Schema::tableExists($pdo, 'users')) {
            if (Schema::indexExists($pdo, 'users', 'idx_users_branch_id')) {
                $pdo->exec('ALTER TABLE users DROP INDEX idx_users_branch_id');
            }
            if (Schema::columnExists($pdo, 'users', 'branch_id')) {
                $pdo->exec('ALTER TABLE users DROP COLUMN branch_id');
            }
        }

        if (Schema::tableExists($pdo, 'store_calendar') && Schema::columnExists($pdo, 'store_calendar', 'branch_id')) {
            $pdo->exec('ALTER TABLE store_calendar DROP COLUMN branch_id');
        }
    },
];
