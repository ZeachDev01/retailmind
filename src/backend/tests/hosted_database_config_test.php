<?php

require_once __DIR__ . '/../app/Core/Environment.php';

// Keep local credentials out of this configuration-only check.
App\Core\Environment::load(__DIR__ . '/nonexistent-test.env');
$_SERVER['HTTP_HOST'] = 'shalom.example.com';
$_ENV['APP_ENV'] = 'production';
// Configuration checks do not need the database recovery admission gate.
$_SERVER['SCRIPT_FILENAME'] = realpath(__DIR__ . '/../scripts/restore_database.php');

foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASSWORD', 'CHARSET', 'CONNECTION'] as $key) {
    $_ENV['DB_' . $key] = 'current-' . $key;
    $_ENV['MYSQL_' . $key] = 'old-mysql-' . $key;
    $_ENV['INFINITYFREE_DB_' . $key] = 'old-infinityfree-' . $key;
}

$config = require __DIR__ . '/../config/database.php';
restore_exception_handler();
foreach (['host' => 'HOST', 'port' => 'PORT', 'database' => 'NAME', 'username' => 'USER', 'password' => 'PASSWORD', 'charset' => 'CHARSET', 'driver' => 'CONNECTION'] as $field => $key) {
    if ($config[$field] !== 'current-' . $key) {
        throw new RuntimeException('Standard database setting must take priority: ' . $field);
    }
}

$_ENV['DB_HOST'] = '';
$config = require __DIR__ . '/../config/database.php';
if ($config['host'] !== 'localhost') {
    throw new RuntimeException('Default to the hosting provider local database host.');
}

$_ENV['DB_NAME'] = '';
$_ENV['DB_USER'] = '';
$_ENV['DB_PASSWORD'] = '';
try {
    require __DIR__ . '/../config/database.php';
    throw new RuntimeException('Hosted database credentials must come from the environment.');
} catch (RuntimeException $exception) {
    if (strpos($exception->getMessage(), 'Hosted database credentials are missing: database, username, password') !== 0) {
        throw $exception;
    }
}

echo "Hosted database configuration checks passed.\n";
