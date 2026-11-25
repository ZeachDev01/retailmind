<?php

use App\Database\Schema;

return [
    'key' => '202611250001_notification_dismissal',
    'description' => 'Track dismissal of read notifications',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'notifications', 'dismissed_at', 'DATETIME(6) NULL');
    },
];
