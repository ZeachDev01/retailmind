<?php

return [
    'key' => '202611260001_cashier_login_sessions',
    'description' => 'Track one active cashier login and device transfer approval',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cashier_login_sessions (
            user_id INT PRIMARY KEY,
            owner_hash VARCHAR(64) NOT NULL,
            session_version INT NOT NULL,
            authenticated_at BIGINT NOT NULL,
            last_activity BIGINT NOT NULL,
            pending_hash VARCHAR(64) NULL,
            pending_until BIGINT NULL,
            approved INT NOT NULL DEFAULT 0
        )" . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB' : ''));
    },
];
