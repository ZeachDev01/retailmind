<?php

use App\Database\Schema;

return [
    'key' => '202609300001_accountable_drawer_movements',
    'description' => 'Record accountable Cashier drawer movements (ticket #94)',
    'up' => static function (PDO $pdo): void {
        // Existing pay-in and pay-out records remain readable and keep their
        // effect on reconciliation. New records use the three issue #94 types.
        $pdo->exec("ALTER TABLE cash_drawer_movements MODIFY COLUMN movement_type
            ENUM('pay_in','pay_out','cash_in','cash_out','safe_drop') NOT NULL");
        Schema::addColumnIfMissing($pdo, 'cash_drawer_movements', 'note', 'VARCHAR(255) NULL AFTER `reason`');
        Schema::addColumnIfMissing($pdo, 'cash_drawer_movements', 'cashier_id', 'INT NULL AFTER `note`');
        $pdo->exec('UPDATE cash_drawer_movements cdm
            JOIN cashier_shifts cs ON cs.shift_id = cdm.shift_id
            SET cdm.cashier_id = cs.cashier_id WHERE cdm.cashier_id IS NULL');
        $pdo->exec('ALTER TABLE cash_drawer_movements MODIFY COLUMN cashier_id INT NOT NULL');
        Schema::addIndexIfMissing($pdo, 'cash_drawer_movements', 'idx_drawer_movements_cashier', '`cashier_id`');
        Schema::addForeignKeyIfMissing($pdo, 'cash_drawer_movements', 'fk_drawer_cashier', 'cashier_id', 'users', 'user_id', 'RESTRICT');
    },
];
