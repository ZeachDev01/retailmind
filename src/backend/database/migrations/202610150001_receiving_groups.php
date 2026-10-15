<?php

use App\Database\Schema;

return [
    'key' => '202610150001_receiving_groups',
    'description' => 'Dedicated grouped receiving history and stable shopping trip grouping',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_receiving_groups (
            group_id INT AUTO_INCREMENT PRIMARY KEY,
            received_by INT NOT NULL,
            received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            supplier VARCHAR(150) NULL,
            invoice_number VARCHAR(100) NULL,
            notes TEXT NULL,
            INDEX idx_stock_receiving_groups_received_by (received_by),
            INDEX idx_stock_receiving_groups_received_at (received_at),
            CONSTRAINT fk_stock_receiving_groups_user FOREIGN KEY (received_by) REFERENCES users (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        Schema::addColumnIfMissing($pdo, 'stock_receiving', 'group_id', 'INT NULL AFTER receiving_id');
        Schema::addIndexIfMissing($pdo, 'stock_receiving', 'idx_stock_receiving_group_id', 'group_id');
        Schema::addForeignKeyIfMissing($pdo, 'stock_receiving', 'fk_stock_receiving_group',
            'group_id', 'stock_receiving_groups', 'group_id', 'SET NULL');

        $pdo->exec("INSERT INTO stock_receiving_groups (group_id, received_by, received_at, supplier, invoice_number, notes)
            SELECT sr.receiving_id, sr.received_by, sr.received_at, sr.supplier, sr.invoice_number, sr.notes
            FROM stock_receiving sr
            LEFT JOIN stock_receiving_groups srg ON srg.group_id = sr.receiving_id
            WHERE sr.group_id IS NULL AND srg.group_id IS NULL");
        $pdo->exec("UPDATE stock_receiving SET group_id = receiving_id WHERE group_id IS NULL");
    },
];
