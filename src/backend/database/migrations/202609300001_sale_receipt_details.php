<?php

use App\Database\Schema;

return [
    'key' => '202609300001_sale_receipt_details',
    'description' => 'Preserve customer-facing Sale Receipt details at checkout',
    'up' => static function (PDO $pdo): void {
        if (Schema::tableExists($pdo, 'sale_receipt_details')) {
            return;
        }
        $pdo->exec('CREATE TABLE sale_receipt_details (
            sale_id INT NOT NULL PRIMARY KEY,
            details_json LONGTEXT NOT NULL,
            CONSTRAINT fk_sale_receipt_details_sale FOREIGN KEY (sale_id)
                REFERENCES sales (sale_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    },
];
