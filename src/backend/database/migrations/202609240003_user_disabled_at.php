<?php

use App\Database\Schema;

return [
    'key' => '202609240003_user_disabled_at',
    'description' => 'Record when a user account transitions to Disabled (ticket #61)',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing(
            $pdo,
            'users',
            'disabled_at',
            'DATETIME NULL AFTER `is_recovery_account`'
        );
    },
];
