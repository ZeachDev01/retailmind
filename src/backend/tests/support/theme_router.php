<?php
// Real application routes; only the disposable database name is supplied here.
require_once __DIR__ . '/../../bootstrap/app.php';
$database = getenv('RM_THEME_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/', $database)) throw new RuntimeException('Disposable database required.');
$_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $database;
$_ENV['APP_ENV'] = 'development'; // Includes the existing development-only forecast view.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath(__DIR__ . '/../../../frontend' . ($path === '/' ? '/index.php' : $path));
$root = realpath(__DIR__ . '/../../../frontend');
if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) { http_response_code(404); exit; }
if (!str_ends_with($file, '.php')) return false;
require $file;
