<?php

use App\Store\StoreScope;

return [
    'key' => '202609180001_singleton_store_scope',
    'description' => 'Resolve the singleton Store compatibility identity',
    'up' => static function (PDO $pdo): void {
        (new StoreScope($pdo))->migrate();
    },
];
