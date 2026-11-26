<?php
$base = sys_get_temp_dir() . '/rm-folder-picker-' . bin2hex(random_bytes(5));
mkdir($base);
putenv('BACKUP_STORAGE_PATH=' . $base . '/private');
require_once __DIR__ . '/../bootstrap/app.php';
$_ENV['BACKUP_STORAGE_PATH'] = $base . '/private';

use App\Backup\BackupDestinationBrowser;
use App\Backup\RecoveryStore;

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function (string $path) use ($check): void {
    try {
        BackupDestinationBrowser::listing($path);
        $check(false, 'Unsafe or missing path accepted: ' . $path);
    } catch (InvalidArgumentException) {
    }
};
try {
    $local = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost:8080'];
    $check(BackupDestinationBrowser::isStorePcRequest($local), 'Direct Store PC request is allowed');
    $check(BackupDestinationBrowser::isStorePcRequest(['REMOTE_ADDR' => '::1', 'HTTP_HOST' => '[::1]:8080']), 'IPv6 loopback is allowed');
    foreach ([['REMOTE_ADDR' => '192.168.1.20'], ['REMOTE_ADDR' => '203.0.113.20'], ['HTTP_HOST' => 'store.trycloudflare.com'], ['HTTP_X_FORWARDED_FOR' => '127.0.0.1'], ['HTTP_X_FORWARDED_HOST' => 'localhost'], ['HTTP_FORWARDED' => 'for=192.168.1.20'], ['HTTP_CF_CONNECTING_IP' => '192.168.1.20']] as $remote) {
        $check(!BackupDestinationBrowser::isStorePcRequest(array_replace($local, $remote)), 'Other PCs and tunnels must not choose folders');
    }
    mkdir($base . '/Weekly');
    file_put_contents($base . '/secret.txt', 'must not be listed');
    RecoveryStore::directory();
    $result = BackupDestinationBrowser::listing($base);
    $check(array_column($result['folders'], 'name') === ['Weekly'], 'Only safe subfolders should be listed');
    $check($result['selectable'] === false, 'Private recovery store ancestor must not be selected');
    $weekly = BackupDestinationBrowser::listing($base . '/Weekly');
    $check($weekly['selectable'] === true && $weekly['folders'] === [], 'Empty writable folder is selectable');
    $check($weekly['parent'] === realpath($base), 'Up returns the containing folder');
    $check(BackupDestinationBrowser::listing('')['path'] === '', 'Root view lists drives');
    foreach (['relative', $base . '/../', $base . '/missing', $base . '/secret.txt', $base . '/private', dirname(__DIR__, 3), "bad\0path", 'php://filter'] as $path) $reject($path);
    $_SERVER['DOCUMENT_ROOT'] = $base . '/Weekly';
    $reject($base . '/Weekly');
    echo "Backup destination browser: PASS\n";
} finally {
    @unlink($base . '/secret.txt');
    @rmdir($base . '/Weekly');
    @rmdir($base . '/private');
    @rmdir($base);
}
