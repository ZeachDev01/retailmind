<?php

return [
    'key' => '202610240001_gcash_receipt_details',
    'description' => 'Preserve completed GCash Service Receipts',
    'up' => static function (PDO $pdo): void {
        App\Database\Schema::addColumnIfMissing($pdo, 'gcash_transactions', 'receipt_details_json', 'LONGTEXT NULL');
    },
];
