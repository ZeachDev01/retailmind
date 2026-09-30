<?php

use App\Database\Schema;

return [
    'key' => '202609300003_administrator_shift_intervention',
    'description' => 'Preserve the closing actor and intervention reason for Cashier Shifts (ticket #96)',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'cashier_shifts', 'closed_by', 'INT NULL AFTER `closing_notes`');
        Schema::addColumnIfMissing($pdo, 'cashier_shifts', 'intervention_reason', 'TEXT NULL AFTER `closed_by`');
        Schema::addIndexIfMissing($pdo, 'cashier_shifts', 'idx_cashier_shifts_closed_by', '`closed_by`');
        Schema::addForeignKeyIfMissing($pdo, 'cashier_shifts', 'fk_cashier_shifts_closed_by', 'closed_by', 'users', 'user_id', 'RESTRICT');
    },
];
