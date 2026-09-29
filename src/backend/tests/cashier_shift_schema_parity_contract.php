<?php
// Cashier Shift / Register schema parity contract (ticket #88, criterion 6).
//
// A fresh install (src/backend/sql/schema.sql) and an upgraded install (the
// 202609290002_cashier_shift_registers migration) must produce the same
// relationship and the same exclusivity constraints on cashier_shifts. The two
// declarations are compared directly so the check is deterministic and never
// depends on the state of a developer's database. Deployed-table assertions
// live in database_integration.php, the documented RUN_DB_TESTS seam.
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
$migration = (string)@file_get_contents($root . '/src/backend/database/migrations/202609290002_cashier_shift_registers.php');
$assert($schema !== '' && $migration !== '', 'Cashier Shift schema sources must be readable');

// Both lineages must name the same relationship and the same exclusivity
// constraints, and must reach them by the same generated-column expression.
// The fresh install writes literal DDL; the migration reaches the same shape
// through the Schema helpers, so the shared tokens are what must not drift.
$requiredTokens = [
    'the cashier_shifts Register reference' => '/register_id/i',
    'the Register lookup index' => '/idx_cashier_shifts_register/i',
    'the open-shift Cashier exclusivity column' => '/open_cashier_id/i',
    'the open-shift Register exclusivity column' => '/open_register_id/i',
    'the one-open-shift-per-Cashier constraint' => '/uq_cashier_shifts_open_cashier/i',
    'the one-open-shift-per-Register constraint' => '/uq_cashier_shifts_open_register/i',
    'the Register foreign key' => '/fk_cashier_shifts_register/i',
    'the open-shift Cashier exclusivity expression' => "/IF\\(status = 'open', cashier_id, NULL\\)/i",
    'the open-shift Register exclusivity expression' => "/IF\\(status = 'open', register_id, NULL\\)/i",
    'a stored (indexable) generated column' => '/STORED/i',
];
foreach ($requiredTokens as $label => $pattern) {
    foreach (['schema.sql' => $schema, 'the 202609290002 migration' => $migration] as $source => $text) {
        $assertMatches($text, $pattern, "{$source} is missing {$label}");
    }
}

// The fresh install states the constraints literally, so assert their exact
// shape there: both exclusivity rules are UNIQUE keys over the generated
// columns, and a referenced Register is never deleted (ticket #87).
$assertMatches(
    $schema,
    '/UNIQUE KEY `uq_cashier_shifts_open_cashier` \(`open_cashier_id`\)/i',
    'schema.sql enforces one open shift per Cashier with a UNIQUE key'
);
$assertMatches(
    $schema,
    '/UNIQUE KEY `uq_cashier_shifts_open_register` \(`open_register_id`\)/i',
    'schema.sql enforces one open shift per Register with a UNIQUE key'
);
$assertMatches(
    $schema,
    '/CONSTRAINT `fk_cashier_shifts_register` FOREIGN KEY \(`register_id`\) REFERENCES `registers` \(`register_id`\) ON DELETE RESTRICT/i',
    'schema.sql protects a referenced Register from deletion'
);

// The migration must reach the same shape, not merely name it: the rules are
// applied as UNIQUE keys, and re-running is safe on either lineage.
$assert(
    preg_match_all('/Schema::addUniqueKeyIfMissing\(/', $migration) === 2,
    'the migration applies both exclusivity rules as UNIQUE keys'
);
$assert(
    str_contains($migration, "'RESTRICT'"),
    'the migration adds the shift reference with ON DELETE RESTRICT'
);
foreach (['addColumnIfMissing', 'addIndexIfMissing', 'addForeignKeyIfMissing'] as $helper) {
    $assert(
        str_contains($migration, "Schema::{$helper}("),
        "the migration uses Schema::{$helper}() so re-running it is safe"
    );
}
$assert(
    preg_match("/'key'\s*=>\s*'202609290002_cashier_shift_registers'/", $migration) === 1,
    'the migration keeps its versioned key'
);
// The upgrade must not close a Cashier's live shift to make room for a
// constraint: it stops and names the conflicts instead.
$assert(
    str_contains($migration, "WHERE status = 'open'") && str_contains($migration, 'RuntimeException('),
    'the migration refuses to install the constraints over conflicting open shifts'
);

if ($failures) {
    fwrite(STDERR, "Cashier Shift schema parity contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Cashier Shift schema parity contract: passed\n";
