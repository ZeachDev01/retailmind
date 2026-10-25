<?php
// Disposable complete-schema MySQL/filesystem safety-copy workflow.
if (getenv('RUN_DB_TESTS') !== '1') { echo "Safety copy workflow: skipped (set RUN_DB_TESTS=1)\n"; exit; }
$private = sys_get_temp_dir() . '/rm-safety-test-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
require_once __DIR__ . '/../bootstrap/app.php';
$_ENV['BACKUP_STORAGE_PATH'] = $private;
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/support/safety_failure_io.php';
use App\Authorization\RoleCapabilityPolicy;
use App\Backup\{RecoveryStore, SqlBackupFormat, StorePackageFormat, SafetyCopyService};
$assert = static function(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$reject = static function(callable $work, string $message) use ($assert): void { $failed = false; try { $work(); } catch (Throwable) { $failed = true; } $assert($failed, $message); };
$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$fixture = static function(string $action) use ($database): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/support/theme_fixture.php', $action, $database], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($output . $error);
};
try {
    $fixture('create');
    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $uploads = $private . '/uploads'; mkdir($uploads . '/images', 0700, true); mkdir($uploads . '/profile-images', 0700, true);
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=');
    file_put_contents($uploads . '/images/safety.png', $image); file_put_contents($uploads . '/profile-images/safety.png', $image);
    $pdo->exec("UPDATE products SET product_image='storage/images/safety.png' WHERE product_id=1");
    $pdo->exec("UPDATE users SET profile_image='safety.png' WHERE user_id=1");
    $service = new SafetyCopyService($pdo, new RoleCapabilityPolicy(), $pdo, $uploads);
    $operation = bin2hex(random_bytes(16)); $secret = 'Independent destination safety secret 2026';
    $copy = $service->create($operation, 1, 'admin', 'ThemeTest@2026', $secret);
    $assert($copy['stage'] === 'safety_verified', 'Only fully verified safety copies become ready');
    $verified = $service->verify($operation, $secret);
    $assert($verified['package']['files']['images/safety.png'] === $image && $verified['package']['files']['profile-images/safety.png'] === $image, 'Both upload classes round trip');
    $assert((int)$verified['package']['rows']['inventory'][0]['quantity_on_hand'] === 10, 'Exact destination stock captured');
    $assert(!RecoveryStore::isPaused(), 'Safety capture does not enable replacement or leave maintenance');
    $download = $service->resolveDownload($operation, 1, 'admin');
    $original = hash_file('sha256', $download['path']);
    $assert($download['size'] > 0 && !str_contains(file_get_contents($download['path']), 'Theme Test'), 'Retained artifact is encrypted');
    $reject(fn() => $service->resolveDownload($operation, 2, 'super_admin'), 'Another account cannot download the safety artifact');
    $reject(fn() => $service->create($operation, 1, 'admin', 'wrong', $secret), 'Fresh destination password required');
    $reject(fn() => $service->verify($operation, 'Wrong separate safety secret'), 'Wrong safety passphrase rejected');
    $pdo->exec('UPDATE inventory SET quantity_on_hand=27 WHERE product_id=1');
    $service->create($operation, 1, 'admin', 'ThemeTest@2026', $secret);
    $assert(hash_file('sha256', $download['path']) === $original, 'Retry preserves original fallback instead of capturing partial state');
    $assert((int)$service->verify($operation, $secret)['package']['rows']['inventory'][0]['quantity_on_hand'] === 10, 'Retry retains original destination snapshot');
    $assert(is_file($service->resolveDownload($operation, 1, 'admin')['path']), 'Safety download is repeatable and never consumes fallback');
    $reject(fn() => $service->create(bin2hex(random_bytes(16)), 4, 'cashier', 'ThemeTest@2026', $secret), 'Operational staff cannot capture safety');
    $reject(fn() => $service->create('../escape', 1, 'admin', 'ThemeTest@2026', $secret), 'Operation path is validated');
    foreach (['encryption', 'storage', 'verification', 'checkpoint', 'capacity'] as $fault) {
        $failedOperation = bin2hex(random_bytes(16));
        $GLOBALS['safetyFault'] = $fault;
        $reject(fn() => $service->create($failedOperation, 1, 'admin', 'ThemeTest@2026', $secret), 'Injected ' . $fault . ' failure must abort');
        unset($GLOBALS['safetyFault']);
        $assert(!is_file(RecoveryStore::path('safety-' . $failedOperation . '/metadata.json')), 'Incomplete artifact cannot become verified fallback');
        $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn() === 27, 'Failed safety capture leaves stock intact');
        $assert(file_get_contents($uploads . '/images/safety.png') === $image, 'Failed capture leaves uploaded bytes intact');
        $assert(hash_file('sha256', $download['path']) === $original, 'Original verified copy survives independent failures');
        $assert(!RecoveryStore::isPaused(), 'Failed prerequisite never starts destructive maintenance');
    }
    unlink($uploads . '/images/safety.png');
    $reject(fn() => $service->create(bin2hex(random_bytes(16)), 1, 'admin', 'ThemeTest@2026', $secret), 'Missing required upload aborts');
    file_put_contents($uploads . '/images/safety.png', $image);
    $pdo->exec('CREATE VIEW safety_unsupported_view AS SELECT user_id FROM users');
    $reject(fn() => $service->create(bin2hex(random_bytes(16)), 1, 'admin', 'ThemeTest@2026', $secret), 'Unsupported database capture aborts');
    $pdo->exec('DROP VIEW safety_unsupported_view');
    $invalidOperation = bin2hex(random_bytes(16));
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); $pdo->exec('UPDATE sales SET cashier_id=999 WHERE sale_id=1'); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $reject(fn() => $service->create($invalidOperation, 1, 'admin', 'ThemeTest@2026', $secret), 'Invalid database actor reference prevents verified safety');
    $assert(!is_file(RecoveryStore::path('safety-' . $invalidOperation . '/metadata.json')), 'Broken database relationships cannot become safety ready');
    $pdo->exec('UPDATE sales SET cashier_id=4 WHERE sale_id=1');
    $metadata = file_get_contents(RecoveryStore::path('safety-' . $operation . '/metadata.json'));
    $assert(!str_contains($metadata, $secret) && !str_contains($metadata, 'ThemeTest@2026') && !str_contains($metadata, (string)$pdo->query('SELECT password_hash FROM users WHERE user_id=1')->fetchColumn()), 'Checkpoint contains no plaintext credentials or captured records');
    $encrypted = file_get_contents($download['path']);
    file_put_contents($download['path'], substr($encrypted, 0, -1));
    $reject(fn() => $service->verify($operation, $secret), 'Changed original safety material fails closed');
    $reject(fn() => $service->create($operation, 1, 'admin', 'ThemeTest@2026', $secret), 'Changed original cannot be silently recaptured');
    file_put_contents($download['path'], $encrypted);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); $pdo->exec('DROP TABLE users'); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $assert($service->verify($operation, $secret)['metadata']['sha256'] === $original, 'Original safety verifies against retained schema with users missing');
    echo "Safety copy workflow: passed (complete schema/uploads, immutable retry, private download, independent failures, missing users)\n";
    echo "Runtime: PHP " . PHP_VERSION . "; MySQL " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "; schema " . $copy['schema_hash'] . "; encrypted bytes " . $copy['size'] . "; peak memory " . memory_get_peak_usage(true) . "\n";
} finally {
    $fixture('cleanup');
    $delete = static function(string $path) use (&$delete): void { if (is_dir($path)) { foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $delete($path . '/' . $name); rmdir($path); } else unlink($path); };
    if (str_starts_with(basename($private), 'rm-safety-test-') && dirname($private) === sys_get_temp_dir() && is_dir($private)) $delete($private);
}
