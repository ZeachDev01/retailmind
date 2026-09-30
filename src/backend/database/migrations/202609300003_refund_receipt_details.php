<?php

use App\Database\Schema;

return [
    'key' => '202609300003_refund_receipt_details',
    'description' => 'Preserve customer-facing Refund Receipt details atomically',
    'up' => static function (PDO $pdo): void {
        if (Schema::tableExists($pdo, 'refund_receipt_details')) {
            return;
        }
        $pdo->exec('CREATE TABLE refund_receipt_details (
            refund_id INT NOT NULL PRIMARY KEY,
            details_json LONGTEXT NOT NULL,
            CONSTRAINT fk_refund_receipt_details_refund FOREIGN KEY (refund_id)
                REFERENCES cash_refunds (refund_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    },
];
