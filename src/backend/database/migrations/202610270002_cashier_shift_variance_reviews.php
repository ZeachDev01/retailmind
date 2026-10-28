<?php

use App\Database\Schema;

return [
    'key' => '202610270002_cashier_shift_variance_reviews',
    'description' => 'Add accountable review draft, notes, shortage, and history to cashier_shifts (ticket #329)',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'cashier_shifts', 'review_draft_notes', 'TEXT NULL AFTER `reviewed_at`');
        Schema::addColumnIfMissing($pdo, 'cashier_shifts', 'review_notes', 'TEXT NULL AFTER `review_draft_notes`');
        Schema::addColumnIfMissing($pdo, 'cashier_shifts', 'review_shortage', 'DECIMAL(12,2) NULL AFTER `review_notes`');
        Schema::addColumnIfMissing($pdo, 'cashier_shifts', 'review_history', 'TEXT NULL AFTER `review_shortage`');
    },
];
