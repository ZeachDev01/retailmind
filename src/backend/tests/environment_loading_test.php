<?php

require_once __DIR__ . '/../app/Core/Environment.php';

use App\Core\Environment;

$path = tempnam(sys_get_temp_dir(), 'retailmind-env-');
if ($path === false) {
    throw new RuntimeException('Cannot create environment fixture.');
}

try {
    if (function_exists('putenv')) {
        putenv('RETAILMIND_TEST_OVERRIDE=process-value');
    }
    file_put_contents($path, "RETAILMIND_TEST_DB_HOST=sql.example.test\nRETAILMIND_TEST_DB_PASSWORD=\"test=value#123\"\nRETAILMIND_TEST_OVERRIDE=file-value\n");
    Environment::load($path);

    foreach ([
        'RETAILMIND_TEST_DB_HOST' => 'sql.example.test',
        'RETAILMIND_TEST_DB_PASSWORD' => 'test=value#123',
        'RETAILMIND_TEST_OVERRIDE' => function_exists('putenv') ? 'process-value' : 'file-value',
    ] as $key => $expected) {
        if (Environment::get($key) !== $expected || ($_SERVER[$key] ?? null) !== $expected) {
            throw new RuntimeException('Environment file value was not loaded: ' . $key);
        }
        if (function_exists('putenv') && getenv($key) !== $expected) {
            throw new RuntimeException('Process environment value was not loaded: ' . $key);
        }
    }

    if (Environment::get('RETAILMIND_TEST_MISSING', 'fallback') !== 'fallback') {
        throw new RuntimeException('Missing environment value must use its default.');
    }

    echo "Environment loading checks passed.\n";
} finally {
    unlink($path);
}
