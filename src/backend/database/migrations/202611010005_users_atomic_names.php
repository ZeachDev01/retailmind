<?php

use App\Database\Schema;

return [
    'key' => '202611010005_users_atomic_names',
    'description' => 'Split user names into first and last name fields',
    'up' => static function (PDO $pdo): void {
        if (!Schema::tableExists($pdo, 'users')) {
            throw new \RuntimeException("Required table 'users' does not exist.");
        }

        Schema::addColumnIfMissing($pdo, 'users', 'first_name', 'VARCHAR(50) NULL AFTER `user_id`');
        Schema::addColumnIfMissing($pdo, 'users', 'last_name', 'VARCHAR(50) NULL AFTER `first_name`');
        Schema::addColumnIfMissing($pdo, 'users', 'display_name', 'VARCHAR(100) NULL AFTER `last_name`');

        if (Schema::columnExists($pdo, 'users', 'full_name')) {
            $pdo->exec('UPDATE users SET display_name = full_name WHERE display_name IS NULL');
            $pdo->exec('ALTER TABLE users DROP COLUMN full_name');
        }

        $pdo->exec('ALTER TABLE users MODIFY display_name VARCHAR(100) NOT NULL');
    },
];
