<?php

return [
    'key' => '202610190002_gcash_service_sessions',
    'description' => 'GCash service sessions for tracking service cash, wallet balances, and cashier handover',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS gcash_service_sessions (
            session_id INT AUTO_INCREMENT PRIMARY KEY,
            shift_id INT NOT NULL,
            cashier_id INT NOT NULL,
            opening_service_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            opening_wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            expected_service_cash DECIMAL(12,2) NULL,
            expected_wallet_balance DECIMAL(12,2) NULL,
            closing_service_cash DECIMAL(12,2) NULL,
            closing_wallet_balance DECIMAL(12,2) NULL,
            cash_variance DECIMAL(12,2) NULL,
            wallet_variance DECIMAL(12,2) NULL,
            closing_notes TEXT NULL,
            closed_at TIMESTAMP NULL,
            closed_by INT NULL,
            unresolved_carryover_count INT NOT NULL DEFAULT 0,
            is_open INT GENERATED ALWAYS AS (IF(status = 'open', 1, NULL)) STORED,
            UNIQUE KEY uq_gcash_open_session (is_open),
            INDEX idx_gcash_sessions_shift (shift_id),
            INDEX idx_gcash_sessions_cashier (cashier_id),
            INDEX idx_gcash_sessions_closed_by (closed_by),
            CONSTRAINT fk_gcash_session_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts(shift_id),
            CONSTRAINT fk_gcash_session_cashier FOREIGN KEY (cashier_id) REFERENCES users(user_id),
            CONSTRAINT fk_gcash_session_closed_by FOREIGN KEY (closed_by) REFERENCES users(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = [];
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            foreach ($pdo->query("PRAGMA table_info(`gcash_transactions`)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[$col['name']] = true;
            }
        } else {
            foreach ($pdo->query("SHOW COLUMNS FROM `gcash_transactions`")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[$col['Field']] = true;
            }
        }
        if (!isset($columns['session_id'])) {
            $pdo->exec("ALTER TABLE gcash_transactions ADD COLUMN session_id INT NULL AFTER transaction_id, ADD INDEX idx_gcash_transaction_session (session_id), ADD CONSTRAINT fk_gcash_transaction_session FOREIGN KEY (session_id) REFERENCES gcash_service_sessions(session_id)");
        }
    },
];
