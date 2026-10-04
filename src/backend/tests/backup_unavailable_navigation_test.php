<?php
// Exercise the unavailable-hosting branch without a database or web server.
$source = file_get_contents(__DIR__ . '/../../frontend/components/system_administrator/backup_restore.php');
if ($source === false || !preg_match('/if \(!RecoveryStore::isAvailable\(\)\) \{(.*?)\n\}/s', $source, $match)) {
    fwrite(STDERR, "Backup unavailable navigation: branch not found.\n");
    exit(1);
}

final class RecoveryStore
{
    public static function isAvailable(): bool
    {
        return false;
    }
}

function app_url(string $path): string
{
    return '/retailmind/' . ltrim($path, '/');
}
// This extracted unavailable-hosting branch has no session/theme integration.
function retailmind_theme_head(): void {}

ob_start();
$branch = str_replace("require_once __DIR__ . '/../../../backend/includes/theme.php';", '', $match[1]);
eval('if (!RecoveryStore::isAvailable()) {' . $branch . "\n}");
$html = (string)ob_get_clean();

if (http_response_code() !== 503
    || !str_contains($html, 'Database Backup and Restore are unavailable on this hosting plan.')
    || !str_contains($html, 'href="/retailmind/components/auth/workspace.php"')
    || !str_contains($html, 'Change workspace')
) {
    fwrite(STDERR, "Backup unavailable navigation: missing message or workspace escape.\n");
    exit(1);
}

echo "Backup unavailable navigation: passed\n";
