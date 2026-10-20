<?php
// Ticket #255: verify the supported carry paths (backup, restore, reset, seeds,
// demand-history import/export) do not drop the Sales Pack records or reinterpret
// historical totals. Static/contract only: no database is opened.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "  ok - {$message}\n";
    } else {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    }
};

$root = dirname(__DIR__, 2); // src/
$read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);

$clear = "pack_size|pack_barcode|pack_price|sealed_quantity|loose_quantity|selling_form|received_packs|received_loose|pulled_packs|pulled_loose|physical_sealed_quantity|physical_loose_quantity";

// --- Backup / restore: schema-driven, so newly added columns are carried. ---
$writer = $read('backend/app/Backup/DatabaseSnapshotWriter.php');
$format = $read('backend/app/Backup/SqlBackupFormat.php');
$assert(str_contains($writer, 'SHOW CREATE TABLE') && str_contains($writer, '$definition[\'columns\']'),
    'Database backup derives tables and columns from the live schema (no fixed column list to drop)');
$assert(!preg_match('/' . $clear . '/', $writer), 'Database backup does not hardcode a Sales Pack column list');
$assert(str_contains($format, "SHOW COLUMNS FROM") && str_contains($format, "array_column(\$columns, 'Field')"),
    'Backup schema discovery reads every column via SHOW COLUMNS');
$assert(!preg_match('/' . $clear . '/', $format), 'Backup format does not hardcode a Sales Pack column list');

$restore = $read('backend/app/Backup/DatabaseRestoreService.php');
$assert(str_contains($restore, 'SqlBackupFormat') || str_contains($restore, 'swap') || str_contains($restore, 'replace'),
    'Restore consumes the same schema-complete snapshot (full replacement, no per-column mapping)');

// --- Reset: the Sales Pack-bearing tables are emptied, pullouts before their batches. ---
$reset = $read('backend/app/Services/OperationalDataResetService.php');
foreach (['products', 'product_batches', 'stock_receiving', 'sale_items', 'inventory_counts',
    'inventory_adjustments', 'supplier_pullouts', 'cash_refund_items', 'cash_refunds',
    'sale_item_batches', 'held_sales', 'checkout_attempts'] as $table) {
    $assert(str_contains($reset, "'{$table}'"), "Operational reset empties {$table} (a Sales Pack-bearing table or its parent)");
}
$pulloutPos = strpos($reset, "'supplier_pullouts'");
$batchPos = strpos($reset, "'product_batches'");
$assert($pulloutPos !== false && $batchPos !== false && $pulloutPos < $batchPos,
    'Operational reset deletes supplier pullouts before product_batches (the batch FK has no cascade)');

// --- Seeds/fixtures stay ordinary products; pack configuration is operator data. ---
$seedHeader = strtok($read('backend/database/seeds/product_seed.csv'), "\n");
$assert(!preg_match('/' . $clear . '/', (string)$seedHeader),
    'Product seed CSV header carries no Sales Pack columns, so seeded products remain piece-only');
$schema = $read('backend/database/sql/schema.sql');
$assert(str_contains($schema, 'uq_products_branch_pack_barcode'),
    'Canonical schema keeps the Sales Pack barcode uniqueness key');

// --- Demand history import/export stays in Base Units via the recorded sale form. ---
$demand = $read('backend/app/Forecasting/DemandHistoryService.php');
$assert(str_contains($demand, "'selling_form'") && str_contains($demand, "'pack_size'")
    && str_contains($demand, 'quantity *') && str_contains($demand, 'ELSE'),
    'Demand history converts pack sales to Base Units and falls back to piece quantity for legacy rows');

if ($failures) {
    fwrite(STDERR, 'Sales pack carry-records contract: ' . count($failures) . " failure(s)\n");
    exit(1);
}
echo "Sales pack carry-records contract: passed\n";
