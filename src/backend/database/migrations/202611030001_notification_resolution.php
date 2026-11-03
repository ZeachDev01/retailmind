<?php

use App\Database\Schema;

return [
    'key' => '202611030001_notification_resolution',
    'description' => 'Retain resolution for each notification occurrence (#420)',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'notifications', 'resolved_at', 'DATETIME NULL');
    },
];
