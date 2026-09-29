<?php
// Held Sale Cashier Shift ownership schema parity contract (ticket #91, AC 6).
//
// A fresh install (src/backend/sql/schema.sql) and an upgraded install (the
// 202609290005_held_sale_shift_ownership migration) must produce the same
// held_sales table, because the closure invariant is only as good as the
// relationship it reads. The two declarations are compared directly so the check
// is deterministic and never depends on the state of a developer's database.
// Deployed-table assertions live in database_integration.php, the documented
// RUN_DB_TESTS seam.
require_once __DIR__ . '/../bootstrap/app.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$assertMatches = static function (string $haystack, string $pattern, string $message) use (&$assert): void {
    $assert(preg_match($pattern, $haystack) === 1, $message);
};

$root = dirname(__DIR__, 3);
$schema = (string)@file_get_contents($root . '/src/backend/sql/schema.sql');
$migration = (string)@file_get_contents($root . '/src/backend/database/migrations/202609290005_held_sale_shift_ownership.php');
$assert($schema !== '' && $migration !== '', 'Held sale schema sources must be readable');

// The isolated `held_sales` CREATE TABLE block, so a token belonging to another
// table cannot satisfy a check that is about this one.
preg_match('/CREATE TABLE `held_sales` \((.*?)\n\) ENGINE=/s', $schema, $block);
$heldTable = $block[1] ?? '';
$assert($heldTable !== '', 'schema.sql still declares a held_sales table');

// Both lineages must name the same relationships and the same index. The
// parking Cashier is not in this list because it already existed and the upgrade
// has no reason to touch it; it is asserted in schema.sql below instead.
$requiredTokens = [
    'the owning Cashier Shift' => '/shift_id/i',
    'the explained discard reason' => '/discard_reason/i',
    'the discard note' => '/discard_note/i',
    'the discarding Cashier' => '/discarded_by/i',
    'the completed sale' => '/sale_id/i',
    'the shift-scoped lookup index' => '/idx_held_sales_shift_status/i',
];
foreach ($requiredTokens as $label => $pattern) {
    foreach (['schema.sql' => $heldTable, 'the 202609290005 migration' => $migration] as $source => $text) {
        $assertMatches($text, $pattern, "{$source} is missing {$label}");
    }
}
$assertMatches(
    $heldTable,
    '/CONSTRAINT `held_sales_ibfk_1` FOREIGN KEY \(`cashier_id`\) REFERENCES `users` \(`user_id`\)/i',
    'schema.sql keeps a held sale attributed to the Cashier who parked it'
);

// The closure invariant reads this pair, so both lineages must resolve it.
$assertMatches(
    $schema,
    '/KEY `idx_held_sales_shift_status` \(`shift_id`,`status`\)/i',
    'schema.sql indexes held sales by Cashier Shift and status, which is what a closure reads'
);
$assert(
    str_contains($migration, "'idx_held_sales_shift_status'")
        && str_contains($migration, '`shift_id`, `status`'),
    'the migration adds the same shift-and-status index'
);
// ...and it has to drop the single-column index the Cashier Shift foreign key
// brought along, or an upgraded database keeps a second, redundant copy that a
// fresh install never has. Presence-only assertions cannot see this, so the
// absence is asserted.
$assert(
    !preg_match('/KEY `shift_id` \(`shift_id`\)/i', $heldTable),
    'schema.sql does not keep the single-column index the composite one replaces'
);
$assert(
    str_contains($migration, 'DROP INDEX `shift_id`')
        && str_contains($migration, "Schema::indexExists(\$pdo, 'held_sales', 'shift_id')"),
    'the migration drops the redundant single-column index it replaces'
);

// The shift a cart is parked on must not be deletable out from under it. With
// ON DELETE SET NULL, deleting a shift would silently release its unresolved
// carts and let a later closure sail past them.
$assertMatches(
    $schema,
    '/CONSTRAINT `fk_held_sales_shift` FOREIGN KEY \(`shift_id`\) REFERENCES `cashier_shifts` \(`shift_id`\) ON DELETE RESTRICT/i',
    'schema.sql protects the Cashier Shift a held sale is parked on'
);
$assert(
    preg_match("/'fk_held_sales_shift',\s*\n\s*'shift_id',\s*\n\s*'cashier_shifts',\s*\n\s*'shift_id',\s*\n\s*'RESTRICT'/s", $migration) === 1,
    'the migration adds the Cashier Shift reference with ON DELETE RESTRICT'
);
// ...and the migration has to remove the looser rule it is replacing, or the
// existing constraint is simply left in place and RESTRICT never takes effect.
$assert(
    str_contains($migration, 'DROP FOREIGN KEY')
        && str_contains($migration, 'referential_constraints')
        && str_contains($migration, "'SET NULL'"),
    'the migration drops the previous ON DELETE SET NULL before adding RESTRICT'
);

// The two terminal resolutions are distinct, and 'cancelled' — how the same
// abandonment was spelled before a reason was required — is re-spelled rather
// than left behind as a second word for the same act.
$assertMatches(
    $schema,
    "/enum\\('held','resumed','discarded','completed','expired'\\)/i",
    'schema.sql states the resolution vocabulary, with a discard and a completion as the two ways out'
);
$assert(
    preg_match("/\\\$targetStatus\s*=\s*\"enum\('held','resumed','discarded','completed','expired'\) NOT NULL DEFAULT 'held'\"/", $migration) === 1,
    'the migration reaches the same resolution vocabulary'
);
$assert(
    preg_match('/MODIFY COLUMN `status` \{\$targetStatus\}/', $migration) === 1,
    'the migration applies the whole status definition, so a database holding one member but not the rest is still corrected'
);
// The statement order here is a correctness property, not a style choice, so it
// is worth pinning. Narrowing the enum first would destroy every 'cancelled'
// row on the spot — a value the new enum does not contain becomes the empty
// string, not an error — and the rewrite would then find nothing to rewrite.
// Widening, rewriting, narrowing is the only order that preserves the rows.
$widenPos = strpos($migration, '$widenedStatus}');
$rewritePos = strpos($migration, "SET `status` = 'discarded' WHERE `status` = 'cancelled'");
$narrowPos = strpos($migration, 'MODIFY COLUMN `status` {$targetStatus}');
$assert(
    $widenPos !== false && $rewritePos !== false && $narrowPos !== false
        && $widenPos < $rewritePos && $rewritePos < $narrowPos,
    "the migration widens the status enum, re-spells legacy 'cancelled' rows, and only then narrows it"
);
$assert(
    preg_match("/SET `status` = 'discarded' WHERE `status` = 'cancelled'/", $migration) === 1,
    'the migration re-spells legacy cancelled carts as discarded instead of leaving two words for one act'
);

// The completion and the discard are recorded, and neither the sale nor the
// Cashier is destroyed by being referenced.
$assertMatches(
    $schema,
    '/CONSTRAINT `fk_held_sales_sale` FOREIGN KEY \(`sale_id`\) REFERENCES `sales` \(`sale_id`\) ON DELETE SET NULL/i',
    'schema.sql links a completed held sale to its sale without making the sale deletable'
);
$assertMatches(
    $schema,
    '/CONSTRAINT `fk_held_sales_discarded_by` FOREIGN KEY \(`discarded_by`\) REFERENCES `users` \(`user_id`\) ON DELETE SET NULL/i',
    'schema.sql keeps a discard attributed to a Cashier whose account is later disabled'
);

// The shift column stays nullable: carts parked before this deployment carry no
// shift, and the upgrade must not invent one. What it may do is expire them
// through the shift-scoped sweep, which is why the column cannot be tightened.
$assertMatches(
    $schema,
    '/`shift_id` int\(11\) DEFAULT NULL/i',
    'schema.sql keeps held_sales.shift_id nullable so pre-cutoff carts stay unlinked'
);
$assert(
    !preg_match('/UPDATE\s+`?held_sales`?\s+SET\s+`?shift_id`?/i', $migration),
    'the migration never backfills an inferred Cashier Shift onto existing held sales'
);

// held_sales names sales and users, both of which are declared later in the dump.
// FOREIGN_KEY_CHECKS is off for the whole replay, so the fresh install must keep
// it off or the dump stops importing.
$assert(
    strpos($schema, 'FOREIGN_KEY_CHECKS=0') < strpos($schema, 'CREATE TABLE `held_sales`'),
    'schema.sql disables foreign key checks before declaring a table that references later tables'
);
$assert(
    strpos($schema, 'CREATE TABLE `held_sales`') < strpos($schema, 'CREATE TABLE `sales`')
        && strpos($schema, 'CREATE TABLE `held_sales`') < strpos($schema, 'CREATE TABLE `users`'),
    'held_sales is declared before the tables it references'
);

// This contract is about the two schema lineages and nothing else. The rules the
// service reads them for are proven as behaviour, not as source text, in
// held_sale_shift_contract.php — a renamed private constant there should break
// no test here, because nothing about the schema changed.

if ($failures) {
    fwrite(STDERR, "Held Sale Cashier Shift ownership schema parity contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Held Sale Cashier Shift ownership schema parity contract: passed\n";
