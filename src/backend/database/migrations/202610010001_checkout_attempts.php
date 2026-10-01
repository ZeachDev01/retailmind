<?php

use App\Database\Schema;

return [
    'key' => '202610010001_checkout_attempts',
    'description' => 'Persist Cashier checkout identities with committed sale outcomes',
    'up' => static function (PDO $pdo): void {
        if (Schema::tableExists($pdo, 'checkout_attempts')) {
            return;
        }
        $pdo->exec('CREATE TABLE checkout_attempts (
            cashier_id INT NOT NULL,
            attempt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            sale_id INT NOT NULL,
            result_json LONGTEXT NOT NULL,
            PRIMARY KEY (cashier_id, attempt_id),
            UNIQUE KEY uq_checkout_attempt_sale (sale_id),
            CONSTRAINT fk_checkout_attempt_cashier FOREIGN KEY (cashier_id) REFERENCES users (user_id) ON DELETE RESTRICT,
            CONSTRAINT fk_checkout_attempt_sale FOREIGN KEY (sale_id) REFERENCES sales (sale_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    },
];
