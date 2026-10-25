<?php
return [
    'key' => '202610250002_identity_provenance',
    'description' => 'Retain roleless Historical Actors and installation-aware record provenance',
    'up' => static function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE users MODIFY role_id INT NULL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS store_record_origins (
            origin_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            table_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            source_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            local_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            original_details LONGTEXT NULL,
            PRIMARY KEY (origin_id, table_name, source_key),
            KEY idx_store_record_local (table_name, local_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    },
];
