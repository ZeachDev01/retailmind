<?php

use App\Database\Schema;

return [
    'key' => '202611020001_ml_jobs_evidence_cutoff',
    'description' => 'Add evidence_cutoff and model_version columns to ml_jobs for logical work deduplication',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'ml_jobs', 'evidence_cutoff', 'VARCHAR(20) NULL AFTER requester_id');
        Schema::addColumnIfMissing($pdo, 'ml_jobs', 'model_version', 'VARCHAR(50) NULL AFTER evidence_cutoff');
    },
];
