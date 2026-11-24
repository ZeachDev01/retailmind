<?php
return [
    'key' => '202611240001_refund_return_conditions',
    'description' => 'Append-only Cash Refund return condition correction history',
    'up' => static function (PDO $pdo): void {
        App\Services\RefundReturnConditionService::ensureSchema($pdo);
    },
];
