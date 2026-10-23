<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Render the actual presentation with a workspace payload; authentication stays in route coverage.
function retailmind_theme_head(): void {}
function app_url(string $path): string { return '/' . $path; }
function theme_url(string $path): string { return '/' . $path; }
$dashboard = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$selectedRange = 30;
$selectedPeriod = $argv[1] ?? 'today';
$source = file_get_contents(__DIR__ . '/../../frontend/components/administrator/dashboard.php');
$source = substr($source, strpos($source, '$escape ='));
$source = preg_replace('/<\?php include __DIR__ .*?\?>/', '', $source);
eval($source);
