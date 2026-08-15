<?php

require_once dirname(__DIR__) . '/assets/bootstrap/app.php';

return [
    'api_url' => rtrim((string)env('ML_API_URL', 'http://127.0.0.1:5000'), '/'),
    'api_key' => env('ML_API_KEY', ''),
    'timeout' => (int)env('ML_API_TIMEOUT', 30),
];
