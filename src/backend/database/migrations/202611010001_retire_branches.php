<?php

return [
    'key' => '202611010001_retire_branches',
    'description' => 'Retire branch storage and normalize compatibility scope for the single Store',
    'up' => static function (PDO $pdo): void {
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('branches','users','products','store_calendar')")
            ->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('branches', $tables, true)) {
            return;
        }

        $storeIds = [];
        foreach (['users', 'products'] as $table) {
            if (!in_array($table, $tables, true)) {
                continue;
            }
            $ids = $pdo->query("SELECT DISTINCT branch_id FROM `{$table}` WHERE branch_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $storeIds = array_merge($storeIds, array_map('intval', $ids));
        }
        if (in_array('store_calendar', $tables, true)) {
            $hasScope = $pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'store_calendar' AND COLUMN_NAME = 'branch_id'")->fetchColumn();
            if ($hasScope) {
                $ids = $pdo->query('SELECT DISTINCT branch_id FROM store_calendar WHERE branch_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
                $storeIds = array_merge($storeIds, array_map('intval', $ids));
            }
        }
        if (count(array_unique($storeIds)) > 1) {
            throw new RuntimeException('Cannot remove branches while users or products belong to multiple branches. Consolidate stores first.');
        }

        foreach (['users', 'products'] as $table) {
            $constraints = $pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME = \'branches\'');
            $constraints->execute([$table]);
            foreach ($constraints->fetchAll(PDO::FETCH_COLUMN) as $constraint) {
                $quoted = str_replace('`', '``', (string)$constraint);
                $pdo->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$quoted}`");
            }
        }

        $pdo->exec('DROP TABLE `branches`');
    },
];
