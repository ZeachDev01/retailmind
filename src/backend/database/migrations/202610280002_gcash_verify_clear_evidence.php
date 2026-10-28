<?php

use App\Database\Schema;

return [
    'key' => '202610280002_gcash_verify_clear_evidence',
    'description' => 'Add cash and wallet check evidence to gcash_transactions (ticket #331)',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'gcash_transactions', 'cash_checked_by', 'INT NULL AFTER `verified_by`');
        Schema::addColumnIfMissing($pdo, 'gcash_transactions', 'cash_finding', 'VARCHAR(255) NULL AFTER `cash_checked_by`');
        Schema::addColumnIfMissing($pdo, 'gcash_transactions', 'wallet_checked_by', 'INT NULL AFTER `cash_finding`');
        Schema::addColumnIfMissing($pdo, 'gcash_transactions', 'wallet_finding', 'VARCHAR(255) NULL AFTER `wallet_checked_by`');
    },
];
