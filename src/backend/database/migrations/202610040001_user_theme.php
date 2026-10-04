<?php
use App\Database\Schema;
return [
    'key' => '202610040001_user_theme',
    'description' => 'Personal display theme for every Staff account',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'users', 'theme_preference', "ENUM('light','dark','system') NOT NULL DEFAULT 'system'");
    },
];
