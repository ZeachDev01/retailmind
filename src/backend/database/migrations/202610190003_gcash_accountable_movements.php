<?php

return [
    'key' => '202610190003_gcash_accountable_movements',
    'description' => 'GCash accountable money movements with principal, fee, deltas, and shift attribution',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $columns = [];
        if ($driver === 'sqlite') {
            foreach ($pdo->query("PRAGMA table_info(`gcash_transactions`)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[$col['name']] = true;
            }
        } else {
            foreach ($pdo->query("SHOW COLUMNS FROM `gcash_transactions`")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[$col['Field']] = true;
            }
        }

        if ($driver !== 'sqlite') {
            $pdo->exec("ALTER TABLE gcash_transactions
                MODIFY COLUMN reference_number VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
                MODIFY COLUMN verification_status ENUM('Pending','Verified','Rejected','Completed','Needs checking') NOT NULL DEFAULT 'Pending'");
        }

        $colsToAdd = [
            'shift_id' => 'INT NULL AFTER session_id',
            'principal_amount' => 'DECIMAL(12,2) NULL AFTER amount',
            'fee_amount' => 'DECIMAL(12,2) NULL AFTER principal_amount',
            'fee_method' => "ENUM('cash','wallet') NULL AFTER fee_amount",
            'service_cash_delta' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER fee_method",
            'wallet_delta' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER service_cash_delta",
            'gross_fee' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER wallet_delta",
            'confirmed_wallet' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER cashier_id",
            'confirmed_cash' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER confirmed_wallet",
            'notes' => "TEXT NULL AFTER confirmed_cash",
            'completed_at' => "DATETIME NULL AFTER reviewed_at",
        ];

        foreach ($colsToAdd as $col => $def) {
            if (!isset($columns[$col])) {
                $pdo->exec("ALTER TABLE gcash_transactions ADD COLUMN `{$col}` {$def}");
            }
        }

        if ($driver !== 'sqlite') {
            $indexes = [];
            foreach ($pdo->query("SHOW INDEX FROM `gcash_transactions`")->fetchAll(PDO::FETCH_ASSOC) as $idx) {
                $indexes[$idx['Key_name']] = true;
            }
            if (!isset($indexes['idx_gcash_transaction_shift'])) {
                $pdo->exec("ALTER TABLE gcash_transactions ADD INDEX idx_gcash_transaction_shift (shift_id), ADD CONSTRAINT fk_gcash_transaction_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts(shift_id)");
            }
        }
    },
];
