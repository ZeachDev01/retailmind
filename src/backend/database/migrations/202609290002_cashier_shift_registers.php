<?php

use App\Database\Schema;

return [
    'key' => '202609290002_cashier_shift_registers',
    'description' => 'Bind each open Cashier Shift to an exclusively owned Register (ticket #88)',
    'up' => static function (PDO $pdo): void {
        // Ticket #88. A Cashier Shift is an exclusively owned drawer session:
        // it records the Register it anchors, and a Cashier and a Register each
        // belong to at most one open shift. MySQL has no filtered unique index,
        // so each rule is a generated column that is non-NULL only while the
        // shift is open, carried by a UNIQUE key. Closed history is therefore
        // never constrained, and NULLs never collide in a unique index.
        //
        // This must match the `cashier_shifts` table in sql/schema.sql so a
        // fresh install and an upgraded install agree.

        // Existing history predates Registers, so the column stays nullable and
        // only the opening flow requires one. A referenced Register is never
        // deleted (RESTRICT), matching the guard RegisterService applies.
        Schema::addColumnIfMissing($pdo, 'cashier_shifts', 'register_id', 'INT NULL AFTER `cashier_id`');
        Schema::addIndexIfMissing($pdo, 'cashier_shifts', 'idx_cashier_shifts_register', '`register_id`');

        // Refuses to install the exclusivity constraints over history that
        // already breaks them. Closing somebody's live shift is an operational
        // decision, not a migration side effect, so the upgrade stops and names
        // the conflicts instead. MigrationRunner leaves the migration pending,
        // so resolving them and re-running completes the upgrade.
        //
        // This is a closure rather than a top-level function on purpose:
        // MigrationRunner::available() loads migrations with `require`, not
        // `require_once`, so a second load in the same process would fatal on
        // a redeclaration. Migrations must not declare anything at file scope.
        $requireNoOpenShiftConflicts = static function (PDO $pdo): void {
            $conflicts = [];
            $duplicatedCashiers = $pdo->query(
                "SELECT cashier_id, COUNT(*) AS open_count FROM cashier_shifts
                 WHERE status = 'open' GROUP BY cashier_id HAVING open_count > 1"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($duplicatedCashiers as $row) {
                $conflicts[] = 'cashier_id ' . (int)$row['cashier_id'] . ' holds ' . (int)$row['open_count'] . ' open shifts';
            }

            $duplicatedRegisters = $pdo->query(
                "SELECT register_id, COUNT(*) AS open_count FROM cashier_shifts
                 WHERE status = 'open' AND register_id IS NOT NULL
                 GROUP BY register_id HAVING open_count > 1"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($duplicatedRegisters as $row) {
                $conflicts[] = 'register_id ' . (int)$row['register_id'] . ' holds ' . (int)$row['open_count'] . ' open shifts';
            }

            if ($conflicts !== []) {
                throw new \RuntimeException(
                    'Cannot enforce exclusive Cashier Shifts until these open shifts are reconciled: '
                    . implode('; ', $conflicts)
                    . '. Close the extra shifts, then run the migration again.'
                );
            }
        };
        $requireNoOpenShiftConflicts($pdo);

        Schema::addColumnIfMissing(
            $pdo,
            'cashier_shifts',
            'open_cashier_id',
            "INT GENERATED ALWAYS AS (IF(status = 'open', cashier_id, NULL)) STORED"
        );
        Schema::addColumnIfMissing(
            $pdo,
            'cashier_shifts',
            'open_register_id',
            "INT GENERATED ALWAYS AS (IF(status = 'open', register_id, NULL)) STORED"
        );
        Schema::addUniqueKeyIfMissing($pdo, 'cashier_shifts', 'uq_cashier_shifts_open_cashier', '`open_cashier_id`');
        Schema::addUniqueKeyIfMissing($pdo, 'cashier_shifts', 'uq_cashier_shifts_open_register', '`open_register_id`');

        Schema::addForeignKeyIfMissing(
            $pdo,
            'cashier_shifts',
            'fk_cashier_shifts_register',
            'register_id',
            'registers',
            'register_id',
            'RESTRICT'
        );
    },
];
