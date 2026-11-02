<?php

use App\Database\Schema;

return [
    'key' => '202611020002_workspace_walkthrough_progress',
    'description' => 'Persist interactive walkthrough progress per staff account and Workspace',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("ALTER TABLE `workspace_guidance`
            MODIFY COLUMN `status` ENUM('skipped','deferred','completed') NULL DEFAULT NULL");
        Schema::addColumnIfMissing($pdo, 'workspace_guidance', 'active_topic', 'VARCHAR(60) NULL DEFAULT NULL AFTER `status`');
        Schema::addColumnIfMissing($pdo, 'workspace_guidance', 'active_step', 'INT NOT NULL DEFAULT 0 AFTER `active_topic`');
        Schema::addColumnIfMissing($pdo, 'workspace_guidance', 'completed_topics', 'TEXT NULL DEFAULT NULL AFTER `active_step`');
        Schema::addColumnIfMissing($pdo, 'workspace_guidance', 'invitation_status', 'VARCHAR(30) NULL DEFAULT NULL AFTER `completed_topics`');
    },
];
