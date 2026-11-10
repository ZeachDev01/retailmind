<?php

use App\Database\Schema;

return [
    'key' => '202611100003_store_calendar_notes',
    'description' => 'Optional operator notes on Store calendar dates',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'store_calendar', 'notes', 'TEXT NULL AFTER `source`');
    },
];
