<?php
return [
    'key' => '202610250001_store_receipt_print_jobs',
    'description' => 'Queue saved receipts for the Store PC printer',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(file_get_contents(__DIR__ . '/../sql/migrations/202610250001_store_receipt_print_jobs.sql'));
    },
];
