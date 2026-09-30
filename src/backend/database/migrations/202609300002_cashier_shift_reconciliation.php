<?php

use App\Database\Schema;

return [
    'key' => '202609300002_cashier_shift_reconciliation',
    'description' => 'Preserve Cashier Shift reconciliation and review status (ticket #95)',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'cashier_shifts', 'variance_threshold', 'DECIMAL(12,2) NULL AFTER `cash_variance`');
        Schema::addColumnIfMissing($pdo, 'cashier_shifts', 'variance_review_required', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `variance_threshold`');
        Schema::addColumnIfMissing($pdo, 'cashier_shifts', 'payment_totals', 'TEXT NULL AFTER `variance_review_required`');
        Schema::addIndexIfMissing($pdo, 'cashier_shifts', 'idx_cashier_shifts_variance_review', '`variance_review_required`, `status`, `closed_at`');
    },
];
