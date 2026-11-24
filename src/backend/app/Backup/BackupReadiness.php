<?php

namespace App\Backup;

use PDO;

final class BackupReadiness
{
    public static function hasStoreActivity(PDO $pdo): bool
    {
        try {
            foreach (['sales', 'stock_receiving', 'gcash_transactions', 'gcash_funding_movements', 'inventory_adjustments', 'cashier_shifts'] as $table) {
                if ($pdo->query("SELECT 1 FROM {$table} LIMIT 1")->fetchColumn() !== false) {
                    return true;
                }
            }
            return $pdo->query('SELECT 1 FROM inventory WHERE quantity_on_hand > 0 LIMIT 1')->fetchColumn() !== false;
        } catch (\Throwable) {
            // Incomplete schema is not evidence of an unused Store.
            return true;
        }
    }
}
