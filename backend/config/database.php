<?php

require_once dirname(__DIR__) . '/bootstrap/app.php';

$envValue = static function (array $keys, $default = null) {
    foreach ($keys as $key) {
        $value = env($key);
        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return $default;
};

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

$appEnv = strtolower((string)env('APP_ENV', $isLocalHost ? 'development' : 'production'));
$useHostedDatabase = strtolower((string)env('DATABASE_ENV', '')) === 'hosted';
$isHosted = !$isLocalHost || $useHostedDatabase || $appEnv === 'infinityfree';

if ($isHosted) {
    $config = [
        'driver' => $envValue(['INFINITYFREE_DB_CONNECTION', 'DB_CONNECTION'], 'mysql'),
        'host' => $envValue(['INFINITYFREE_DB_HOST', 'MYSQL_HOST', 'DB_HOST']),
        'port' => $envValue(['INFINITYFREE_DB_PORT', 'MYSQL_PORT', 'DB_PORT'], '3306'),
        'database' => $envValue(['INFINITYFREE_DB_NAME', 'MYSQL_DATABASE', 'DB_NAME']),
        'username' => $envValue(['INFINITYFREE_DB_USER', 'MYSQL_USER', 'DB_USER']),
        'password' => $envValue(['INFINITYFREE_DB_PASSWORD', 'MYSQL_PASSWORD', 'DB_PASSWORD'], ''),
        'charset' => $envValue(['INFINITYFREE_DB_CHARSET', 'DB_CHARSET'], 'utf8mb4'),
    ];

    $missing = [];
    foreach (['host', 'database', 'username'] as $key) {
        if ($config[$key] === null || $config[$key] === '') {
            $missing[] = $key;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException(
            'Hosted database credentials are missing: ' . implode(', ', $missing) .
            '. Set DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD in .env using your InfinityFree MySQL details.'
        );
    }

    return $config;
}

return [
    'driver' => $envValue(['DB_CONNECTION'], 'mysql'),
    'host' => $envValue(['DB_HOST'], 'localhost'),
    'port' => $envValue(['DB_PORT'], '3306'),
    'database' => $envValue(['DB_NAME'], 'inventory_system'),
    'username' => $envValue(['DB_USER'], 'root'),
    'password' => $envValue(['DB_PASSWORD'], ''),
    'charset' => $envValue(['DB_CHARSET'], 'utf8mb4'),
];
