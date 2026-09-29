<?php
// Register lock schema parity contract (ticket #90).
//
// A fresh install (src/backend/sql/schema.sql) and an upgraded install (the
// 202609290004_cashier_shift_register_lock migration) must produce the same
// Cashier Shift lock state. The two declarations are compared directly so the
// check is deterministic and never depends on the state of a developer's
// database. Deployed-table assertions live in database_integration.php, the
// documented RUN_DB_TESTS seam.
//
// The rule this pins down: a break is held by the shift, never by the account
// and never by the session. NULL means unlocked, so an upgrade adds state
// without backfilling any.
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
$schema = (string)@file_get_contents($root . '/src/backend/sql/schema.sql');
$migration = (string)@file_get_contents($root . '/src/backend/database/migrations/202609290004_cashier_shift_register_lock.php');
$assert($schema !== '' && $migration !== '', 'Register lock schema sources must be readable');

foreach (['schema.sql' => $schema, 'the 202609290004 migration' => $migration] as $source => $text) {
    $assertMatches(
        $text,
        '/locked_at/i',
        "{$source} is missing the Cashier Shift lock column"
    );
}

// The fresh install states the column literally, so assert its exact shape
// there: the lock lives on the shift, beside the other nullable shift
// timestamps, and not on the account.
$assertMatches(
    $schema,
    '/`locked_at` timestamp NULL DEFAULT NULL/i',
    'schema.sql records the lock on the Cashier Shift as a nullable timestamp'
);
$assertMatches(
    $schema,
    '/CREATE TABLE `cashier_shifts` \((?:(?!\n\)).)*`locked_at` timestamp NULL DEFAULT NULL,(?:(?!\n\)).)*\n\)/s',
    'schema.sql declares the lock inside cashier_shifts'
);

// No separate credential is introduced: the account table is untouched, because
// resumption reuses the password the Cashier already signs in with.
$assertMatches(
    $schema,
    '/CREATE TABLE `users` \((?:(?!\n\)).)*\n\)/s',
    'schema.sql still declares the users table'
);
$assert(
    preg_match('/CREATE TABLE `users` \((.*?)\n\)/s', $schema, $users) === 1
        && preg_match('/`[^`]*(pin|unlock_code)[^`]*`/i', $users[1]) !== 1,
    'no separate PIN or unlock code is introduced on the account'
);

// The migration must reach the same shape through the idempotent helper, so
// re-running it is safe on either lineage.
$assert(
    str_contains($migration, 'Schema::addColumnIfMissing('),
    'the migration uses Schema::addColumnIfMissing() so re-running it is safe'
);
$assert(
    str_contains($migration, "'cashier_shifts', 'locked_at', 'TIMESTAMP NULL DEFAULT NULL"),
    'the migration adds cashier_shifts.locked_at as a nullable timestamp'
);
$assert(
    preg_match("/'key'\s*=>\s*'202609290004_cashier_shift_register_lock'/", $migration) === 1,
    'the migration keeps its versioned key'
);

// A break is not an ending: the upgrade must never close a shift, reconcile a
// drawer, or backfill a lock onto a shift that was running when it was applied.
$assert(
    !preg_match('/UPDATE\s+`?cashier_shifts`?/i', $migration),
    'the migration never rewrites existing Cashier Shifts'
);
$assert(
    !preg_match('/(status\s*=\s*.closed.|closed_at|expected_cash|cash_variance)/i', $migration),
    'the migration closes nothing and reconciles nothing'
);

if ($failures) {
    fwrite(STDERR, "Register lock schema parity contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Register lock schema parity contract: passed\n";
