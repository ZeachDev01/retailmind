<?php

use App\Database\Schema;

return [
    'key' => '202611250002_gcash_cashier_recording',
    'description' => 'Retain GCash customer and wallet details and allow direct service funding records',
    'up' => static function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE gcash_funding_movements MODIFY authorized_by INT NULL');
        Schema::addColumnIfMissing($pdo, 'gcash_transactions', 'customer_name', 'VARCHAR(100) NULL');
        Schema::addColumnIfMissing($pdo, 'gcash_transactions', 'customer_gcash_number', 'VARCHAR(11) NULL');
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'wallet_number', 'VARCHAR(11) NULL');
    },
];
