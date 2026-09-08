<?php

use App\Database\Schema;

return [
    'key' => '202608040001_auth_security',
    'description' => 'Authentication security tables and mandatory password-change support',
    'up' => static function (\PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            attempt_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            was_successful BOOLEAN NOT NULL DEFAULT FALSE,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempts_identity (username, ip_address, attempted_at),
            INDEX idx_login_attempts_time (attempted_at)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            reset_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_reset_tokens_expiry (expires_at)
        )");

        Schema::addColumnIfMissing($pdo, 'users', 'failed_login_attempts', 'INT NOT NULL DEFAULT 0');
        Schema::addColumnIfMissing($pdo, 'users', 'locked_until', 'DATETIME NULL');
        Schema::addColumnIfMissing($pdo, 'users', 'last_login_at', 'DATETIME NULL');
        Schema::addColumnIfMissing($pdo, 'users', 'session_version', 'INT NOT NULL DEFAULT 1');
        Schema::addColumnIfMissing($pdo, 'users', 'password_changed_at', 'DATETIME NULL');
        Schema::addColumnIfMissing($pdo, 'users', 'must_change_password', 'BOOLEAN NOT NULL DEFAULT TRUE');

        Schema::addForeignKeyIfMissing($pdo, 'password_reset_tokens', 'fk_password_reset_user', 'user_id', 'users', 'user_id', 'CASCADE');
        $pdo->exec("UPDATE users SET must_change_password = 1 WHERE username = 'superadmin' AND password_changed_at IS NULL");
    },
];
