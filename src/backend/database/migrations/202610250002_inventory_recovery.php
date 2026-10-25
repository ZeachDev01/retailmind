<?php
return [
    'key' => '202610250002_inventory_recovery',
    'description' => 'Private inventory draft keys and committed submission identities (#316)',
    'up' => static function (PDO $pdo): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS inventory_draft_keys (
            user_id INTEGER PRIMARY KEY,
            encryption_key VARCHAR(64) NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS inventory_submissions (
            user_id INTEGER NOT NULL,
            operation_kind VARCHAR(40) NOT NULL,
            operation_token VARCHAR(64) NOT NULL,
            payload_hash VARCHAR(64) NOT NULL,
            result_json TEXT NOT NULL,
            PRIMARY KEY (user_id, operation_kind, operation_token)
        )');
    },
];
