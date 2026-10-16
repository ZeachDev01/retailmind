<?php

return [
    'key' => '202610170001_cancel_checkout_attempts',
    'description' => 'Retain cancelled checkout identities without creating a sale',
    'up' => static function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE checkout_attempts MODIFY sale_id INT NULL');
    },
];
