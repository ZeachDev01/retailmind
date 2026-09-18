<?php

use App\Database\Schema;

return [
    'key' => '202609170001_profile_images',
    'description' => 'Optional generated profile image filename for user accounts',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'users', 'profile_image', 'VARCHAR(80) NULL AFTER email');
    },
];