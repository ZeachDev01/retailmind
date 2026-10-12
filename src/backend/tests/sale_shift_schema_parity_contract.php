<?php
// Sale Cashier Shift schema parity contract (ticket #89, AC 6).
//
// A fresh install (src/backend/database/sql/schema.sql) and an upgraded install (the
// 202609290003_sale_shift_attribution migration) must produce the same sale
// relationships and the same deployment cutoff behaviour. The two declarations
// are compared directly so the check is deterministic and never depends on the
// state of a developer's database. Deployed-table assertions live in
// database_integration.php, the documented RUN_DB_TESTS seam.
require_once __DIR__ . '/../bootstrap/app.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$assertMatches = static function (string $haystack, string $pattern, string $message) use ($assert): void {
    $assert(preg_match($pattern, $haystack) === 1, $message);
};

$root = dirname(__DIR__, 3);
$schema = (string)@file_get_contents($root . '/src/backend/database/sql/schema.sql');
$migration = (string)@file_get_contents($root . '/src/backend/database/migrations/202609290003_sale_shift_attribution.php');
$assert($schema !== '' && $migration !== '', 'Sale attribution schema sources must be readable');

// Both lineages must name the same relationship and the same supporting index.
// The fresh install writes literal DDL; the migration reaches the same shape
// through the Schema helpers, so the shared tokens are what must not drift.
$requiredTokens = [
    'the sale Cashier Shift column' => '/shift_id/i',
    'the Cashier Shift lookup index' => '/idx_sales_shift/i',
    'the sale Cashier Shift foreign key' => '/fk_sales_shift/i',
    'a store-on-delete that protects the attribution' => '/RESTRICT/i',
];
foreach ($requiredTokens as $label => $pattern) {
    foreach (['schema.sql' => $schema, 'the 202609290003 migration' => $migration] as $source => $text) {
        $assertMatches($text, $pattern, "{$source} is missing {$label}");
    }
}

// The fresh install states the relationship literally, so assert its exact
// shape there: the sale points at a Cashier Shift, and a Cashier Shift a sale
// depends on can never be deleted out from under it.
$assertMatches(
    $schema,
    '/KEY `idx_sales_shift` \(`shift_id`\)/i',
    'schema.sql gives sales a Cashier Shift lookup index'
);
$assertMatches(
    $schema,
    '/CONSTRAINT `fk_sales_shift` FOREIGN KEY \(`shift_id`\) REFERENCES `cashier_shifts` \(`shift_id`\) ON DELETE RESTRICT/i',
    'schema.sql protects a Cashier Shift a sale depends on'
);

// The migration must reach the same shape, not merely name it, and re-running
// it must be safe on either lineage.
foreach (['addColumnIfMissing', 'addIndexIfMissing', 'addForeignKeyIfMissing'] as $helper) {
    $assert(
        str_contains($migration, "Schema::{$helper}("),
        "the migration uses Schema::{$helper}() so re-running it is safe"
    );
}
$assert(
    str_contains($migration, "'RESTRICT'"),
    'the migration adds the sale reference with ON DELETE RESTRICT'
);
$assert(
    preg_match("/'key'\s*=>\s*'202609290003_sale_shift_attribution'/", $migration) === 1,
    'the migration keeps its versioned key'
);

// The deployment cutoff: sales recorded before this migration were never
// attributed to a shift. The column must stay nullable and the upgrade must
// never invent a link, because a guessed drawer cannot be recovered.
$assertMatches(
    $schema,
    '/`shift_id` int\(11\) DEFAULT NULL/i',
    'schema.sql keeps sales.shift_id nullable so pre-cutoff sales stay unlinked'
);
$assert(
    str_contains($migration, "'INT NULL"),
    'the migration adds sales.shift_id as nullable'
);
$assert(
    !preg_match('/UPDATE\s+`?sales`?/i', $migration),
    'the migration never backfills an inferred Cashier Shift onto existing sales'
);

// The Register is determinable from the shift, so the sale must not carry a
// second copy that could disagree with it or be supplied by a client.
$assert(
    !preg_match('/`register_id`\s+int[^,]*,\s*\n[^`]*`gross_amount`/i', $schema),
    'schema.sql does not store a Register on the sale'
);
// A fresh install creates cashier_shifts before sales, so the sale's foreign key
// always resolves while the dump is being replayed.
$assert(
    strpos($schema, 'CREATE TABLE `cashier_shifts`') < strpos($schema, 'CREATE TABLE `sales`'),
    'schema.sql creates cashier_shifts before the sales table that references it'
);

if ($failures) {
    fwrite(STDERR, "Sale Cashier Shift schema parity contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Sale Cashier Shift schema parity contract: passed\n";
