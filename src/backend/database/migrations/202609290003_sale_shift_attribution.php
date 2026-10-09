<?php

use App\Database\Schema;

return [
    'key' => '202609290003_sale_shift_attribution',
    'description' => 'Attribute each new sale to the Cashier and Cashier Shift that authorized it (ticket #89)',
    'up' => static function (PDO $pdo): void {
        // Ticket #89. A sale is written by a Cashier under one open Cashier
        // Shift, so the sale must name the shift that authorized it rather than
        // leaving attribution to chance. The Register is deliberately NOT
        // copied onto the sale: it is determinable by joining the sale to its
        // shift, so there is no second copy to drift and no column a client
        // could supply.
        //
        // This must match the `sales` table in database/sql/schema.sql so a fresh
        // install and an upgraded install agree.
        //
        // The column stays NULLABLE on purpose. Sales recorded before this
        // deployment were never attributed to a shift, and rewriting history to
        // invent one would be a guess about a drawer that cannot be recovered.
        // They stay unlinked and are presented as Legacy / Unassigned. This is
        // the deployment cutoff: sales before it are unlinked, sales after it
        // always carry their shift.
        Schema::addColumnIfMissing($pdo, 'sales', 'shift_id', 'INT NULL AFTER `cashier_id`');
        Schema::addIndexIfMissing($pdo, 'sales', 'idx_sales_shift', '`shift_id`');

        // The sales ledger is authoritative, so a Cashier Shift that a sale
        // already points at must never be deleted out from under it. RESTRICT
        // keeps the attribution intact, matching the Register guard (#87).
        Schema::addForeignKeyIfMissing(
            $pdo,
            'sales',
            'fk_sales_shift',
            'shift_id',
            'cashier_shifts',
            'shift_id',
            'RESTRICT'
        );
    },
];
