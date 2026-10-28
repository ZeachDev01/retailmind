<?php

return [
    'key' => '202610280005_gcash_movement_evidence',
    'description' => 'Persist GCash correction modes, actual return evidence, and funding receipt attribution',
    'up' => static function (PDO $pdo): void {
        $columns = [
            'gcash_adjustments' => [
                'correction_mode' => "VARCHAR(30) NOT NULL DEFAULT 'money_movement'",
                'fee_delta' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
                'evidence' => 'TEXT NULL',
                'recorded_by' => 'INT NULL',
            ],
            'gcash_funding_movements' => [
                'received_by' => 'INT NULL',
                'receipt_confirmed' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'linked_session_id' => 'INT NULL',
            ],
        ];
        foreach ($columns as $table => $definitions) {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $existing = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
                foreach ($definitions as $column => $definition) {
                    if (!in_array($column, $existing, true)) {
                        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                    }
                }
            } else {
                foreach ($definitions as $column => $definition) {
                    \App\Database\Schema::addColumnIfMissing($pdo, $table, $column, $definition);
                }
            }
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec("ALTER TABLE gcash_funding_movements MODIFY movement_type ENUM('replenishment','withdrawal','product_drawer_transfer','owner_capital','shortage_recovery') NOT NULL");
        }
    },
];
