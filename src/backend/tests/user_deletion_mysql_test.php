<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_DB_TESTS') !== '1') { echo "User deletion MySQL: skipped (RUN_DB_TESTS=1 required)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e); exit(1); });
$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$recovery = sys_get_temp_dir() . '/rm-user-deletion-' . bin2hex(random_bytes(6));
mkdir($recovery, 0700);
$_ENV['BACKUP_STORAGE_PATH'] = $_SERVER['BACKUP_STORAGE_PATH'] = $recovery;
$fixture = static function (string $action) use ($database): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/support/theme_fixture.php', $action, $database], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($errors . $output);
};
$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
try {
    $fixture('create');
    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $service = new App\Services\UserLifecycleService($pdo, new App\Authorization\RoleCapabilityPolicy(), null);
    $nextId = 100;
    $seed = static function (string $status = 'active', int $roleId = 4) use ($pdo, &$nextId): int {
        $id = $nextId++;
        $pdo->prepare('INSERT INTO users (user_id,first_name,last_name,display_name,username,email,password_hash,role_id,must_change_password,status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute([$id, 'Imported', (string)$id, 'Imported ' . $id, 'imported_' . $id, $id . '@example.test', password_hash('ImportedPassword123', PASSWORD_DEFAULT), $roleId, $status]);
        $pdo->prepare('INSERT INTO user_roles (user_id,role_id,is_primary) VALUES (?, ?, 1)')->execute([$id, $roleId]);
        return $id;
    };
    $denied = static function (callable $operation, string $expected) use ($assert): void {
        try { $operation(); throw new RuntimeException('Expected deletion rejection: ' . $expected); }
        catch (DomainException $e) { $assert(str_contains($e->getMessage(), $expected), $e->getMessage()); }
    };
    $auditCount = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
    foreach (['active', 'disabled'] as $status) {
        foreach ([1, 4] as $roleId) {
            $id = $seed($status, $roleId);
            $before = $service->get($id);
            $count = $auditCount();
            foreach ([[1, 'admin'], [2, 'super_admin']] as [$actor, $workspace]) {
                foreach ([false, true] as $confirmed) {
                    $denied(fn() => $service->delete($actor, $workspace, $id, $confirmed), App\Services\UserLifecycleService::DELETION_DISABLED_MESSAGE);
                    $assert($service->get($id) === $before, 'Rejected deletion retains identity, credentials, roles and sessions');
                    $assert($auditCount() === $count, 'Rejected deletion leaves audit history unchanged');
                }
            }
        }
    }
    echo "User deletion MySQL: passed (all deletions denied)\n";
} finally {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $fixture('cleanup');
    foreach (glob($recovery . '/*') as $file) unlink($file);
    rmdir($recovery);
}
