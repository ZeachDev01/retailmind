<?php

use App\Database\Schema;

return [
    'key' => '202610010003_safe_refund_exceptions',
    'description' => 'Preserve external settlement and specific Cash Refund exception attribution (#113)',
    'up' => static function (PDO $pdo): void {
        foreach (['original_cashier_id'=>'INT NULL','approved_by'=>'INT NULL','exception_reason'=>'VARCHAR(255) NULL','payment_reference'=>'VARCHAR(100) NULL'] as $name=>$definition) {
            Schema::addColumnIfMissing($pdo,'cash_refunds',$name,$definition);
        }
        foreach (['original_cashier_id','approved_by'] as $column) {
            Schema::addForeignKeyIfMissing($pdo,'cash_refunds','fk_cash_refunds_'.$column,$column,'users','user_id','RESTRICT');
        }
        if (!Schema::tableExists($pdo,'cash_refund_exception_access')) {
            $pdo->exec("CREATE TABLE cash_refund_exception_access (
                access_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                token_hash CHAR(64) NOT NULL,
                sale_id INT NOT NULL, cashier_id INT NOT NULL, shift_id INT NOT NULL,
                approved_by INT NOT NULL, exception_reason VARCHAR(255) NOT NULL,
                expires_at BIGINT NOT NULL, used_refund_id INT NULL,
                UNIQUE KEY uq_refund_exception_token (token_hash),
                FOREIGN KEY (sale_id) REFERENCES sales(sale_id) ON DELETE RESTRICT,
                FOREIGN KEY (cashier_id) REFERENCES users(user_id) ON DELETE RESTRICT,
                FOREIGN KEY (shift_id) REFERENCES cashier_shifts(shift_id) ON DELETE RESTRICT,
                FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE RESTRICT,
                FOREIGN KEY (used_refund_id) REFERENCES cash_refunds(refund_id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    },
];
