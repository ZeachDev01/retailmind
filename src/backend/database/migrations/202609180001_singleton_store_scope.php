<?php

return [
    'key' => '202609180001_singleton_store_scope',
    'description' => 'Resolve the singleton Store compatibility identity',
    'up' => static function (PDO $pdo): void {
        // Singleton store identity is the literal 1; no schema migration required.
    },
];
