<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Render the actual presentation with a workspace payload; authentication stays in route coverage.
require_once __DIR__ . '/../bootstrap/app.php';
function retailmind_theme_head(): void {}
function app_url(string $path): string { return '/' . $path; }
function theme_url(string $path): string { return '/' . $path; }
function current_role(): string { return 'admin'; }
$_SESSION = ['user_id' => 1, 'display_name' => 'Administrator'];
$pdo = new PDO('sqlite::memory:');
$pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'retailmind');
$pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
$pdo->exec('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT)');
$pdo->exec('CREATE TABLE information_schema.columns (table_schema TEXT, table_name TEXT, column_name TEXT)');
$dashboard = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$selectedRange = 30;
$selectedPeriod = $argv[1] ?? 'today';
$source = file_get_contents(__DIR__ . '/../../frontend/components/administrator/dashboard.php');
$source = substr($source, strpos($source, '$escape ='));
$source = preg_replace('/<\?php include __DIR__ .*?\?>/', '', $source);
$source = str_replace('__DIR__', var_export(dirname(__DIR__ . '/../../frontend/components/administrator/dashboard.php'), true), $source);
eval($source);
