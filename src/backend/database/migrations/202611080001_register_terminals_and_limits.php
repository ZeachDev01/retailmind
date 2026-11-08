<?php

use App\Database\Schema;

return [
    'key' => '202611080001_register_terminals_and_limits',
    'description' => 'Add terminal identifier and transaction limit to registers table',
    'up' => static function (PDO $pdo): void {
        if (!Schema::tableExists($pdo, 'registers')) {
            throw new RuntimeException('The registers table is required before adding terminal and limit columns.');
        }

        Schema::addColumnIfMissing($pdo, 'registers', 'terminal_identifier', 'VARCHAR(64) NULL AFTER `paper_width_mm`');
        Schema::addColumnIfMissing($pdo, 'registers', 'transaction_limit', 'DECIMAL(12, 2) NULL AFTER `terminal_identifier`');
        Schema::addIndexIfMissing($pdo, 'registers', 'idx_registers_terminal', '`terminal_identifier`');
    },
];
