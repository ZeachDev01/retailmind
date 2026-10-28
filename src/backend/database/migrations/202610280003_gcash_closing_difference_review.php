<?php

use App\Database\Schema;

return [
    'key' => '202610280003_gcash_closing_difference_review',
    'description' => 'Add closing difference review fields to gcash_service_sessions (ticket #332)',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'reviewed_by', 'INT NULL AFTER `waived_at`');
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'reviewed_at', 'TIMESTAMP NULL AFTER `reviewed_by`');
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'review_notes', 'TEXT NULL AFTER `reviewed_at`');
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'review_draft_notes', 'TEXT NULL AFTER `review_notes`');
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'review_shortage', 'DECIMAL(12,2) NULL AFTER `review_draft_notes`');
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'review_history', 'TEXT NULL AFTER `review_shortage`');
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'cash_check_explanation', 'TEXT NULL AFTER `review_history`');
        Schema::addColumnIfMissing($pdo, 'gcash_service_sessions', 'wallet_check_explanation', 'TEXT NULL AFTER `cash_check_explanation`');
    },
];
