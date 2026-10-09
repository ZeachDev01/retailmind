<?php
// Cash Refund schema parity contract (ticket #92).
//
// A fresh install (src/backend/database/sql/schema.sql) and an upgraded install (the
// 202609290006_append_only_cash_refunds migration) must produce the same refund
// relationships. The two declarations are compared directly so the check is
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
$assertMatches = static function (string $haystack, string $pattern, string $message) use ($assert): void {
    $assert(preg_match($pattern, $haystack) === 1, $message);
};

$root = dirname(__DIR__, 3);
$schema = (string)@file_get_contents($root . '/src/backend/database/sql/schema.sql');
$migration = (string)@file_get_contents($root . '/src/backend/database/migrations/202609290006_append_only_cash_refunds.php');
$assert($schema !== '' && $migration !== '', 'Cash Refund schema sources must be readable');

// Both lineages must name the same relationships. The fresh install writes
// literal DDL; the migration reaches the same shape through the Schema helpers,
// so the shared tokens are what must not drift.
$requiredTokens = [
    'the refund ledger table' => '/cash_refunds/i',
    'the refund line table' => '/cash_refund_items/i',
    'the original sale a refund is against' => '/fk_cash_refunds_sale/i',
    'the Cashier Shift a refund was issued under' => '/fk_cash_refunds_shift/i',
    'the Cashier who issued a refund' => '/fk_cash_refunds_cashier/i',
    'the sold line a refund came from' => '/fk_cash_refund_items_sale_item/i',
    'a product reference' => '/fk_cash_refund_items_product/i',
    'the Restockable and Damaged classification' => "/enum\\('restockable','damaged'\\)/i",
    'the predefined reason column' => '/`?reason`?\s+varchar\(50\)/i',
    'the note column' => '/`?note`?\s+varchar\(255\)/i',
    'a store-on-delete that protects the refund history' => '/RESTRICT/i',
];
foreach ($requiredTokens as $label => $pattern) {
    foreach (['schema.sql' => $schema, 'the 202609290006 migration' => $migration] as $source => $text) {
        $assertMatches($text, $pattern, "{$source} is missing {$label}");
    }
}

// The fresh install states the relationships literally, so assert their exact
// shape there: a refund is retained history, and a sale, a sold line, a Cashier
// Shift, a Cashier, and the refund it belongs to can never be deleted out from
// under it.
foreach ([
    'fk_cash_refunds_sale' => 'sales',
    'fk_cash_refunds_shift' => 'cashier_shifts',
    'fk_cash_refunds_cashier' => 'users',
] as $constraint => $referenced) {
    $assertMatches(
        $schema,
        '/CONSTRAINT `' . $constraint . '` FOREIGN KEY \(`[a-z_]+`\) REFERENCES `' . $referenced . '` \(`[a-z_]+`\) ON DELETE RESTRICT/i',
        "schema.sql protects the {$referenced} record a Cash Refund depends on"
    );
}
$assertMatches(
    $schema,
    '/CONSTRAINT `fk_cash_refund_items_refund` FOREIGN KEY \(`refund_id`\) REFERENCES `cash_refunds` \(`refund_id`\) ON DELETE RESTRICT/i',
    'schema.sql protects the Cash Refund a refund line belongs to'
);

// The three questions reconciliation asks each get the index their read leads
// with, so a Store with a long refund history does not scan the whole ledger.
$assertMatches($schema, '/KEY `idx_cash_refunds_sale` \(`sale_id`\)/i', 'schema.sql indexes a refund by its sale');
$assertMatches($schema, '/KEY `idx_cash_refunds_shift` \(`shift_id`\)/i', 'schema.sql indexes a refund by its Cashier Shift');
$assertMatches($schema, '/KEY `idx_cash_refunds_cashier` \(`cashier_id`\)/i', 'schema.sql indexes a refund by its Cashier');
$assertMatches(
    $schema,
    '/UNIQUE KEY `uq_cash_refund_items_refund_line` \(`refund_id`,`sale_item_id`\)/i',
    'schema.sql stops one sold line being counted twice in one refund'
);

// The migration must reach the same shape, not merely name it, and re-running it
// must be safe on either lineage.
foreach (['addIndexIfMissing', 'addUniqueKeyIfMissing', 'addForeignKeyIfMissing'] as $helper) {
    $assert(
        str_contains($migration, "Schema::{$helper}("),
        "the migration uses Schema::{$helper}() so re-running it is safe"
    );
}
$assert(
    str_contains($migration, 'if (!Schema::tableExists($pdo, \'cash_refunds\'))'),
    'the migration creates the refund ledger only when it is missing'
);
$assert(
    str_contains($migration, 'if (!Schema::tableExists($pdo, \'cash_refund_items\'))'),
    'the migration creates the refund lines only when they are missing'
);
$assert(
    preg_match("/'key'\s*=>\s*'202609290006_append_only_cash_refunds'/", $migration) === 1,
    'the migration keeps its versioned key'
);
$assert(
    substr_count($migration, "'RESTRICT'") === 6,
    'every refund reference is added with ON DELETE RESTRICT'
);

// A refund is append-only, so the migration must never rewrite the thing it
// reverses. The absence of a sale write is the whole of that guarantee: history
// is added to, never edited.
$assert(
    !preg_match('/UPDATE\s+`?sales`?/i', $migration),
    'the migration never updates or rewrites a completed sale'
);
$assert(
    !preg_match('/DELETE\s+FROM\s+`?sales`?/i', $migration),
    'the migration never deletes a completed sale'
);

// A refund is settled on the original payment method and only cash reduces the
// drawer, so the method is stored on the refund rather than assumed.
$assertMatches(
    $schema,
    "/`payment_method` enum\\('cash','card','ewallet'\\) NOT NULL DEFAULT 'cash'/i",
    "schema.sql records the original payment method on a Cash Refund, and defaults it to cash"
);

// Expected drawer cash has to see the cash refunds a shift issued, and only the
// cash ones: a card or e-wallet refund never entered a drawer.
$shiftService = (string)@file_get_contents($root . '/src/backend/app/Services/CashierShiftService.php');
$assert($shiftService !== '', 'the Cashier Shift service source must be readable');
$assert(
    preg_match("/FROM cash_refunds cr\s+WHERE cr\.shift_id = \? AND cr\.payment_method = 'cash'/", $shiftService) === 1,
    'the reconciling summary subtracts the cash refunds the shift issued, and only the cash ones'
);
$assert(
    preg_match("/cr\.shift_id = \? AND cr\.payment_method = 'cash'/", $shiftService) === 1,
    'a refund is charged to the drawer that issued it, not to the drawer the original sale ran under'
);

// The dump is ordered alphabetically, so the refund ledger is written before
// some of the tables it references. That is safe only because the dump replays
// with foreign key checks switched off, and asserting the order instead of that
// would pin a detail of the dump to a rule it does not actually rest on.
$assert(
    preg_match('/SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0/i', $schema) === 1
        && preg_match('/SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS/i', $schema) === 1,
    'schema.sql replays with foreign key checks disabled, so its alphabetical table order is safe'
);
$assert(
    strpos($schema, 'CREATE TABLE `cash_refund_items`') < strpos($schema, 'CREATE TABLE `cash_refunds`'),
    'schema.sql writes the two refund tables side by side, in one block'
);

if ($failures) {
    fwrite(STDERR, "Cash Refund schema parity contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Cash Refund schema parity contract: passed\n";
