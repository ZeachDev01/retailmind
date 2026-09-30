<?php
// Register schema parity contract (ticket #87, acceptance criterion 6).
//
// A fresh install (src/backend/sql/schema.sql) and an upgraded install
// (the 202609290001_store_registers migration) must produce the same Register
// structure. This compares the two declarations directly so the check is
// deterministic and never depends on the state of a developer's database.
// Deployed-table assertions live in database_integration.php, the documented
// RUN_DB_TESTS seam.
require_once __DIR__ . '/../bootstrap/app.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 3);
$schema = (string)@file_get_contents($root . '/src/backend/sql/schema.sql');
$migration = (string)@file_get_contents($root . '/src/backend/database/migrations/202609290001_store_registers.php');
$assert($schema !== '' && $migration !== '', 'Register schema sources must be readable');

$assertMatches = static function (string $haystack, string $pattern, string $message) use (&$assert): void {
    $assert(preg_match($pattern, $haystack) === 1, $message);
};

// The columns the Register service depends on, in both sources. The patterns
// tolerate the spelling differences between the dump and the migration
// (DATETIME NULL vs DEFAULT NULL, current_timestamp vs current_timestamp()).
$requiredColumns = [
    'paper_width_mm' => '/`?paper_width_mm`?\s+ENUM\(\'80\',\'58\'\)\s+NOT NULL DEFAULT \'80\'/i',
    'register_id' => '/`?register_id`?\s+INT(?:\(11\))?\s+NOT NULL AUTO_INCREMENT/i',
    'name' => '/`?name`?\s+VARCHAR\(100\)\s+NOT NULL/i',
    'status' => '/`?status`?\s+ENUM\(\'active\',\'disabled\'\)\s+NOT NULL DEFAULT \'active\'/i',
    'disabled_at' => '/`?disabled_at`?\s+DATETIME\s+(?:DEFAULT\s+)?NULL/i',
    'created_by' => '/`?created_by`?\s+INT(?:\(11\))?\s+(?:DEFAULT\s+)?NULL/i',
    'created_at' => '/`?created_at`?\s+TIMESTAMP\s+NOT NULL DEFAULT CURRENT_TIMESTAMP(?:\(\))?/i',
    'updated_at' => '/`?updated_at`?\s+TIMESTAMP\s+NOT NULL DEFAULT CURRENT_TIMESTAMP(?:\(\))? ON UPDATE CURRENT_TIMESTAMP(?:\(\))?/i',
];
$requiredKeys = [
    'primary key' => '/PRIMARY KEY \(`?register_id`?\)/i',
    'unique name' => '/UNIQUE KEY `?register_name`? \(`?name`?\)/i',
    'status index' => '/KEY `?idx_registers_status`? \(`?status`?\)/i',
    'created_by foreign key' => '/FOREIGN KEY \(`?created_by`?\) REFERENCES `?users`?\s*\(`?user_id`?\) ON DELETE SET NULL/i',
];

foreach ($requiredColumns as $column => $pattern) {
    $assertMatches($schema, $pattern, "schema.sql registers is missing {$column}");
    $assertMatches($migration, $pattern, "migration registers is missing {$column}");
}
foreach ($requiredKeys as $label => $pattern) {
    $assertMatches($schema, $pattern, "schema.sql registers is missing the {$label}");
    $assertMatches($migration, $pattern, "migration registers is missing the {$label}");
}

// The migration is written to be re-runnable: on an upgraded install the table
// already exists, so `up` must leave it untouched rather than fail.
$assert(
    str_contains($migration, "if (!Schema::tableExists(\$pdo, 'registers'))"),
    'the registers migration must be guarded so re-running it is a no-op'
);

if ($failures) {
    fwrite(STDERR, "Register schema parity contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Register schema parity contract: passed\n";
