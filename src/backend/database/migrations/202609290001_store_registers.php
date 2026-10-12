<?php

use App\Database\Schema;

return [
    'key' => '202609290001_store_registers',
    'description' => 'Named physical Registers maintained by Administrators',
    'up' => static function (PDO $pdo): void {
        // Ticket #87. The Register identity is stable for the life of the
        // record so earlier operational references survive a rename, and a
        // disabled Register is withdrawn from new shifts without losing its
        // history. This must match the `registers` table in database/sql/schema.sql so a
        // fresh install and an upgraded install agree.
        if (!Schema::tableExists($pdo, 'registers')) {
            $pdo->exec("CREATE TABLE registers (
                register_id INT NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                status ENUM('active','disabled') NOT NULL DEFAULT 'active',
                paper_width_mm ENUM('80','58') NOT NULL DEFAULT '80',
                disabled_at DATETIME NULL,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (register_id),
                UNIQUE KEY register_name (name),
                KEY idx_registers_status (status),
                KEY fk_registers_created_by (created_by),
                CONSTRAINT fk_registers_created_by
                    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    },
];
