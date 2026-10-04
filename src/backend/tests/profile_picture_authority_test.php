<?php
// Role/privilege matrix and atomic filesystem/database failure checks, isolated from Store data.
require_once __DIR__ . '/../bootstrap/app.php';
restore_exception_handler();

use App\Authorization\RoleCapabilityPolicy;
use App\Services\ProfileImageStorage;
use App\Services\UserLifecycleService;
use App\Store\StoreScope;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$pdo = new class('sqlite::memory:') extends PDO {
    public bool $failCommit = false;
    public function commit(): bool {
        if ($this->failCommit) throw new PDOException('Injected commit failure');
        return parent::commit();
    }
};
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE roles (role_id INTEGER, role_name TEXT);
    INSERT INTO roles VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin'), (4, 'super_admin');
    CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, email TEXT, profile_image TEXT,
        password_hash TEXT, status TEXT, disabled_at TEXT, session_version INTEGER, must_change_password INTEGER,
        is_recovery_account INTEGER, role_id INTEGER, branch_id INTEGER);
    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, is_primary INTEGER);
    CREATE TABLE activity_log (user_id INTEGER, action TEXT, category TEXT, module TEXT, record_id INTEGER,
        previous_value TEXT, new_value TEXT, ip_address TEXT)");
foreach ([1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 4, 6 => 4, 7 => 1, 8 => 1] as $id => $roleId) {
    $pdo->prepare("INSERT INTO users VALUES (?, 'Test Person', ?, NULL, NULL, 'hash', 'active', NULL, 1, 0, ?, ?, 1)")
        ->execute([$id, 'user' . $id, (int)($id === 6), $roleId]);
}
// Secondary privileged assignments and a primary privilege missing from mapping.
$pdo->exec('INSERT INTO user_roles VALUES (7, 1, 1), (7, 3, 0), (8, 1, 1), (8, 4, 0), (5, 1, 1)');
$service = new UserLifecycleService($pdo, new RoleCapabilityPolicy(), new StoreScope($pdo));
$directory = sys_get_temp_dir() . '/rm-picture-authority-' . bin2hex(random_bytes(6));
mkdir($directory);
// Generate valid PNG bytes with installed GD, avoiding an external fixture.
$image = imagecreatetruecolor(1, 1); ob_start(); imagepng($image); $png = ob_get_clean();
$old = str_repeat('a', 64) . '.png';
$failure = '';
$storage = new ProfileImageStorage($directory, 'is_file', 'copy',
    static function (string $path) use (&$failure): bool { return $failure === 'delete' ? false : unlink($path); });
try {
    foreach ([1 => 'cashier', 2 => 'inventory_manager', 3 => 'admin', 4 => 'super_admin'] as $actor => $role) {
        foreach (range(1, 8) as $targetId) {
            $allowed = ($actor === 3 && in_array($targetId, [1, 2], true))
                || ($actor === 4 && in_array($targetId, [1, 2, 3, 4, 7], true));
            $target = $service->get($targetId);
            check($service->canManageAccount($actor, $role, $target) === $allowed, "Removal authority {$role} -> {$targetId}");
            check($service->canViewPicture($actor, $role, $target) === ($allowed || $actor === $targetId), "Read authority {$role} -> {$targetId}");
            file_put_contents($directory . '/' . $old, $png);
            $pdo->prepare('UPDATE users SET profile_image = ? WHERE user_id = ?')->execute([$old, $targetId]);
            $before = $pdo->query('SELECT * FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
            try {
                $after = $service->removePicture($actor, $role, $targetId, $storage);
                check($allowed, 'Denied removal succeeded');
                check($after['profile_image'] === null && !$storage->exists($old), 'Removal clears association and deletes bytes');
                $before[$targetId - 1]['profile_image'] = null;
            } catch (DomainException $exception) {
                check(!$allowed && $storage->exists($old), 'Denied removal preserves bytes');
            }
            check($pdo->query('SELECT * FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC) === $before, 'Only target picture changes');
        }
    }
    foreach (['delete', 'persistence', 'commit'] as $failure) {
        file_put_contents($directory . '/' . $old, $png);
        $pdo->prepare('UPDATE users SET profile_image = ? WHERE user_id = 1')->execute([$old]);
        $before = $pdo->query('SELECT * FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
        $auditCount = $pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
        $pdo->failCommit = $failure === 'commit';
        if ($failure === 'persistence') $pdo->exec("CREATE TRIGGER reject_picture BEFORE UPDATE OF profile_image ON users BEGIN SELECT RAISE(ABORT, 'Injected write failure'); END");
        try { $service->removePicture(3, 'admin', 1, $storage); throw new LogicException('Failure injection did not fire'); }
        catch (RuntimeException $exception) {
            check($pdo->query('SELECT * FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC) === $before, 'Failure preserves every account field');
            check($storage->read($old)['contents'] === $png, 'Failure preserves old image bytes');
            check($pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() === $auditCount, 'Failure rolls back audit');
        }
        $pdo->exec('DROP TRIGGER IF EXISTS reject_picture'); $pdo->failCommit = false;
    }
    $failure = '';
    try { $service->removePicture(6, 'super_admin', 1, $storage); throw new LogicException('Recovery actor accepted'); }
    catch (DomainException) {}
    $service->removePicture(3, 'admin', 1, $storage);
    $upload = $directory . '/upload.png'; file_put_contents($upload, $png);
    $pdo->beginTransaction();
    $new = $storage->replace(['tmp_name' => $upload, 'size' => strlen($png), 'error' => UPLOAD_ERR_OK], null,
        fn(string $name) => $pdo->prepare('UPDATE users SET profile_image = ? WHERE user_id = 1')->execute([$name]),
        fn() => $pdo->commit(), ['x' => 0, 'y' => 0, 'size' => 1]);
    check($service->get(1)['profile_image'] === $new && $storage->exists($new), 'Holder can re-upload after manager removal');
    require_once __DIR__ . '/../includes/profile_images.php';
    function app_url(string $path): string { return '/' . $path; }
    check(!str_contains(profile_avatar_html(1, 'Test Person', null, '', $storage), '<img'), 'Removed picture renders initials on refresh');
    echo "Profile picture authority tests: passed\n";
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
