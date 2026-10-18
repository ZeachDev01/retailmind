<?php

require_once dirname(__DIR__) . '/bootstrap/app.php';

$endsWith = static function (string $value, string $suffix): bool {
    $length = strlen($suffix);
    return $length === 0 || substr($value, -$length) === $suffix;
};

$normalizeHost = static function (?string $host): string {
    $host = strtolower(trim((string)$host));
    if ($host === '') {
        return '';
    }

    if ($host[0] === '[') {
        $end = strpos($host, ']');
        return $end === false ? trim($host, '[]') : substr($host, 1, $end - 1);
    }

    if (substr_count($host, ':') === 1) {
        return explode(':', $host, 2)[0];
    }

    return $host;
};

$host = $normalizeHost($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
$isPrivateIp = filter_var($host, FILTER_VALIDATE_IP)
    && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
$isLocalHost = $host === ''
    || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
    || $isPrivateIp
    || $endsWith($host, '.local')
    || $endsWith($host, '.localhost')
    || $endsWith($host, '.test');

$useHostedDatabase = strtolower((string)env('DATABASE_ENV', '')) === 'hosted';
$isHosted = !$isLocalHost || $useHostedDatabase;

if ($isHosted) {
    $config = [
        'driver' => env('DB_CONNECTION', 'mysql'),
        'host' => env('DB_HOST', 'localhost'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_NAME', 'u845277124_shalom'),
        'username' => env('DB_USER', 'u845277124_shalom'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
    ];

    $missing = [];
    foreach (['host', 'database', 'username', 'password'] as $key) {
        if ($config[$key] === null || $config[$key] === '') {
            $missing[] = $key;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException(
            'Hosted database credentials are missing: ' . implode(', ', $missing) .
            '. Set DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD in .env using your hosting provider\'s MySQL details.'
        );
    }

    return $config;
}

return [
    'driver' => env('DB_CONNECTION', 'mysql'),
    'host' => env('DB_HOST', 'localhost'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_NAME', 'inventory_system'),
    'username' => env('DB_USER', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
];
