<?php

return [
    'key' => '202610190004_gcash_funding_and_corrections',
    'description' => 'GCash service funding movements, linked corrections, and discrepancy waiver',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $pdo->exec("CREATE TABLE IF NOT EXISTS gcash_funding_movements (
            movement_id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            movement_type ENUM('replenishment','withdrawal','product_drawer_transfer','owner_capital') NOT NULL,
            fund_type ENUM('cash','wallet') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            direction ENUM('in','out') NOT NULL,
            evidence_reference VARCHAR(120) NULL,
            reason VARCHAR(255) NOT NULL,
            authorized_by INT NOT NULL,
            recorded_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_gcash_funding_session (session_id),
            INDEX idx_gcash_funding_auth (authorized_by),
            INDEX idx_gcash_funding_rec (recorded_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS gcash_adjustments (
            adjustment_id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id BIGINT UNSIGNED NOT NULL,
            session_id INT NOT NULL,
            service_cash_delta DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            wallet_delta DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            reason VARCHAR(255) NOT NULL,
            authorized_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_gcash_adj_tx (transaction_id),
            INDEX idx_gcash_adj_session (session_id),
            INDEX idx_gcash_adj_auth (authorized_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = [];
        if ($driver === 'sqlite') {
            foreach ($pdo->query("PRAGMA table_info(`gcash_service_sessions`)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[$col['name']] = true;
            }
        } else {
            foreach ($pdo->query("SHOW COLUMNS FROM `gcash_service_sessions`")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[$col['Field']] = true;
            }
        }

        $colsToAdd = [
            'waiver_notes' => 'TEXT NULL AFTER unresolved_carryover_count',
            'waived_by' => 'INT NULL AFTER waiver_notes',
            'waived_at' => 'TIMESTAMP NULL AFTER waived_by',
        ];

        foreach ($colsToAdd as $col => $def) {
            if (!isset($columns[$col])) {
                $pdo->exec("ALTER TABLE gcash_service_sessions ADD COLUMN `{$col}` {$def}");
            }
        }

        if ($driver !== 'sqlite') {
            try {
                $pdo->exec("ALTER TABLE gcash_funding_movements
                    ADD CONSTRAINT fk_gcash_funding_session FOREIGN KEY (session_id) REFERENCES gcash_service_sessions(session_id),
                    ADD CONSTRAINT fk_gcash_funding_auth FOREIGN KEY (authorized_by) REFERENCES users(user_id),
                    ADD CONSTRAINT fk_gcash_funding_rec FOREIGN KEY (recorded_by) REFERENCES users(user_id)");
            } catch (Throwable $e) {}

            try {
                $pdo->exec("ALTER TABLE gcash_adjustments
                    ADD CONSTRAINT fk_gcash_adj_tx FOREIGN KEY (transaction_id) REFERENCES gcash_transactions(transaction_id),
                    ADD CONSTRAINT fk_gcash_adj_session FOREIGN KEY (session_id) REFERENCES gcash_service_sessions(session_id),
                    ADD CONSTRAINT fk_gcash_adj_auth FOREIGN KEY (authorized_by) REFERENCES users(user_id)");
            } catch (Throwable $e) {}

            try {
                $pdo->exec("ALTER TABLE gcash_service_sessions
                    ADD INDEX idx_gcash_sessions_waived_by (waived_by),
                    ADD CONSTRAINT fk_gcash_session_waived_by FOREIGN KEY (waived_by) REFERENCES users(user_id)");
            } catch (Throwable $e) {}
        }
    },
];

