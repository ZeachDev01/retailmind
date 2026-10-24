<?php
// Render the shared sidebar without a database or authenticated web session.
function current_role(): string
{
    return 'admin';
}

function app_url(string $path): string
{
    return '/retailmind/' . ltrim($path, '/');
}

function profile_avatar_html(...$args): string
{
    return '';
}

function csrf_token(): string
{
    return 'test-token';
}

$_SESSION = ['user_id' => 1, 'full_name' => 'Test Administrator'];
$_SERVER['REQUEST_URI'] = app_url('components/administrator/dashboard.php');
ob_start();
require __DIR__ . '/../../frontend/components/sidebar.php';
$html = (string)ob_get_clean();

$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML($html);
libxml_clear_errors();
$xpath = new DOMXPath($document);
$nextItem = $xpath->query('//nav[@aria-label="Main navigation"]//a[@title="Store Overview"]/following-sibling::*[1]')->item(0);
if (!$nextItem
    || $nextItem->nodeName !== 'a'
    || $nextItem->getAttribute('title') !== 'GCash Overview'
    || $nextItem->getAttribute('href') !== app_url('components/administrator/gcash_overview.php')
) {
    fwrite(STDERR, "Administrator sidebar: GCash Overview must appear immediately after Store Overview.\n");
    exit(1);
}

echo "Administrator sidebar order: passed\n";
