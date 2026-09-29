<?php

use App\Database\Schema;

return [
    'key' => '202609290005_held_sale_shift_ownership',
    'description' => 'Keep held sales inside the Cashier Shift that authorized them (ticket #91)',
    'up' => static function (PDO $pdo): void {
        // Ticket #91. A held sale is suspended work, and suspended work belongs
        // to the Cashier and the Cashier Shift it was parked under. Until this
        // deployment a cart could be cancelled with no reason and a cart past
        // its expiry could resolve itself on the next page load, so neither a
        // discard nor a closure could be held to anything.
        //
        // This must match the `held_sales` table in sql/schema.sql so a fresh
        // install and an upgraded install agree.

        if (!Schema::tableExists($pdo, 'held_sales')) {
            throw new RuntimeException("Required table 'held_sales' does not exist.");
        }

        // --- the resolution vocabulary ---------------------------------------
        // 'discarded' and 'completed' are the two ways a held sale leaves the
        // unresolved set, and they are distinct on purpose: a discard is an
        // explained abandonment with no cash effect, a completion is the sale
        // that finished the cart. 'cancelled' is how the same abandonment was
        // spelled before a reason was required, so those rows are re-spelled
        // rather than dropped — their reason stays NULL, which is the honest
        // record of a cart abandoned before the requirement existed.
        //
        // The order of the three statements is the whole trick, and it is the
        // reverse of the one that looks natural. Narrowing the enum first would
        // destroy every 'cancelled' row on the spot: a value the new enum does
        // not contain becomes the empty string, not an error, so the rewrite
        // below would then find nothing to rewrite. So the enum is widened to
        // hold both spellings, the rows are re-spelled, and only then is it
        // narrowed — at which point nothing left in the column is dropped.
        $targetStatus = "enum('held','resumed','discarded','completed','expired') NOT NULL DEFAULT 'held'";
        $widenedStatus = "enum('held','resumed','cancelled','discarded','completed','expired') NOT NULL DEFAULT 'held'";
        $statusColumn = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'held_sales' AND column_name = 'status'"
        )->fetchColumn();
        if (is_string($statusColumn) && strtolower($statusColumn) !== trim($targetStatus, "enum() \n\r\t")) {
            $pdo->exec("ALTER TABLE `held_sales` MODIFY COLUMN `status` {$widenedStatus}");
            $pdo->exec("UPDATE `held_sales` SET `status` = 'discarded' WHERE `status` = 'cancelled'");
            $pdo->exec("ALTER TABLE `held_sales` MODIFY COLUMN `status` {$targetStatus}");
        }

        // --- the explained discard -------------------------------------------
        Schema::addColumnIfMissing($pdo, 'held_sales', 'discard_reason', "VARCHAR(50) NULL AFTER `resolved_at`");
        Schema::addColumnIfMissing($pdo, 'held_sales', 'discard_note', "VARCHAR(255) NULL AFTER `discard_reason`");
        Schema::addColumnIfMissing($pdo, 'held_sales', 'discarded_by', 'INT NULL AFTER `discard_note`');
        Schema::addColumnIfMissing($pdo, 'held_sales', 'sale_id', 'INT NULL AFTER `resolved_at`');

        // --- the completion ---------------------------------------------------
        // A completed held sale names the sale it became, so a reader can follow
        // a suspended cart through to the transaction without guessing. SET NULL
        // rather than RESTRICT: the sales ledger is authoritative and immutable,
        // so it is never the thing that is removed.
        Schema::addForeignKeyIfMissing(
            $pdo,
            'held_sales',
            'fk_held_sales_sale',
            'sale_id',
            'sales',
            'sale_id',
            'SET NULL'
        );
        // A Disabled Cashier keeps its historical attribution, so discarding a
        // cart is never undone by disabling the account that discarded it.
        Schema::addForeignKeyIfMissing(
            $pdo,
            'held_sales',
            'fk_held_sales_discarded_by',
            'discarded_by',
            'users',
            'user_id',
            'SET NULL'
        );

        // --- the closure invariant -------------------------------------------
        // The shift a cart belongs to is read on every closure, so a shift that
        // was deleted with SET NULL would silently release its unresolved carts
        // and let the shift close over them. RESTRICT closes that hole: the
        // carts are resolved first, then the history stands on its own.
        $shiftKey = $pdo->prepare(
            "SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
             FROM information_schema.referential_constraints rc
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND rc.TABLE_NAME = 'held_sales'
               AND rc.REFERENCED_TABLE_NAME = 'cashier_shifts'"
        );
        $shiftKey->execute();
        foreach ($shiftKey->fetchAll(PDO::FETCH_ASSOC) as $constraint) {
            if (strtoupper((string)$constraint['DELETE_RULE']) !== 'SET NULL') {
                continue;
            }
            $pdo->exec('ALTER TABLE `held_sales` DROP FOREIGN KEY `' . $constraint['CONSTRAINT_NAME'] . '`');
        }
        Schema::addForeignKeyIfMissing(
            $pdo,
            'held_sales',
            'fk_held_sales_shift',
            'shift_id',
            'cashier_shifts',
            'shift_id',
            'RESTRICT'
        );

        // One index serves both questions the flow asks: "what is this Cashier
        // looking at" and "what is blocking this shift from closing". The
        // single-column index the Cashier Shift foreign key used to bring along
        // is dropped, because the composite one leads with the same column and
        // leaves a redundant second copy on every write otherwise.
        Schema::addIndexIfMissing(
            $pdo,
            'held_sales',
            'idx_held_sales_shift_status',
            '`shift_id`, `status`'
        );
        if (Schema::indexExists($pdo, 'held_sales', 'shift_id')) {
            $pdo->exec('ALTER TABLE `held_sales` DROP INDEX `shift_id`');
        }
    },
];
