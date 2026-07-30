<?php

require_once dirname(__DIR__) . '/bootstrap/app.php';

return [
    'name' => env('APP_NAME', 'RetailMind'),
    'env' => env('APP_ENV', 'development'),
    'debug' => filter_var(env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN),
    'url' => rtrim((string)env('APP_URL', 'http://localhost/inventory_system'), '/'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),
];
