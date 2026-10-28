<?php

use App\Database\Schema;

return [
    'key' => '202610280004_gcash_follow_up_responsibility',
    'description' => 'Persist accepted GCash follow-up responsibility for unresolved exchanges and closing differences (#333)',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'gcash_transactions', 'responsible_user_id', 'INT NULL AFTER `cashier_id`');
        Schema::addColumnIfMissing($pdo, 'gcash_transactions', 'responsible_role', "VARCHAR(20) NULL AFTER `responsible_user_id`");
        Schema::addColumnIfMissing($pdo, 'gcash_transactions', 'responsibility_notes', 'TEXT NULL AFTER `responsible_role`');
        Schema::addColumnIfMissing($pdo, 'gcash_transactions', 'responsibility_assigned_at', 'DATETIME NULL AFTER `responsibility_notes`');
        Schema::addIndexIfMissing($pdo, 'gcash_transactions', 'idx_gcash_tx_responsible', 'responsible_user_id');

        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'responsible_user_id', 'INT NULL AFTER `waived_at`');
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'responsible_role', "VARCHAR(20) NULL AFTER `responsible_user_id`");
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'responsibility_notes', 'TEXT NULL AFTER `responsible_role`');
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'responsibility_assigned_at', 'DATETIME NULL AFTER `responsibility_notes`');
        Schema::addIndexIfMissing($pdo, 'gcash_service_sessions', 'idx_gcash_session_responsible', 'responsible_user_id');
    },
];
