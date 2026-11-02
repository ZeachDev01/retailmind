<?php

use App\Database\Schema;

return [
    'key' => '202611010007_drop_duplicate_barcode_pairing_index',
    'description' => 'Remove redundant barcode pairing code index',
    'up' => static function (PDO $pdo): void {
        if (Schema::tableExists($pdo, 'barcode_pairings')
            && Schema::indexExists($pdo, 'barcode_pairings', 'idx_barcode_pairings_code')) {
            $pdo->exec('ALTER TABLE `barcode_pairings` DROP INDEX `idx_barcode_pairings_code`');
        }
    },
];
