<?php

use App\Database\Schema;

return [
    'key' => '202609290004_cashier_shift_register_lock',
    'description' => 'Let a Cashier lock an open Cashier Shift and resume it with their own password (ticket #90)',
    'up' => static function (PDO $pdo): void {
        // Ticket #90. A Cashier who steps away locks the point of sale without
        // closing their shift: the drawer stays theirs, the Register stays
        // claimed, and the Cashier Shift stays open so no reconciliation runs.
        //
        // The lock lives on the shift row, never in the session, for one reason:
        // the session can disappear without the Cashier choosing to — an idle
        // timeout, a closed browser, a restored database. A session-backed lock
        // would silently reopen a Register its owner walked away from, which is
        // the one outcome the lock exists to prevent.
        //
        // NULL means unlocked, so this is the same shape as the other nullable
        // shift timestamps and needs no separate lookup table. The column must
        // match sql/schema.sql so a fresh install and an upgraded install agree.
        Schema::addColumnIfMissing($pdo, 'cashier_shifts', 'locked_at', 'TIMESTAMP NULL DEFAULT NULL AFTER `reviewed_at`');
    },
];
