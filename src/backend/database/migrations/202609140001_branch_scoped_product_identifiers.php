<?php

use App\Database\Schema;

return [
    'key' => '202609140001_branch_scoped_product_identifiers',
    'description' => 'Allow product identifiers to be reused across branches',
    'up' => static function (PDO $pdo): void {
        foreach (['sku', 'barcode', 'case_barcode'] as $index) {
            if (Schema::indexExists($pdo, 'products', $index)) {
                $pdo->exec("ALTER TABLE products DROP INDEX `{$index}`");
            }
        }

        if (!Schema::indexExists($pdo, 'products', 'uq_products_branch_sku')) {
            $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_products_branch_sku (branch_id, sku)');
        }
        if (!Schema::indexExists($pdo, 'products', 'uq_products_branch_barcode')) {
            $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_products_branch_barcode (branch_id, barcode)');
        }
        if (!Schema::indexExists($pdo, 'products', 'uq_products_branch_case_barcode')) {
            $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_products_branch_case_barcode (branch_id, case_barcode)');
        }
    },
];