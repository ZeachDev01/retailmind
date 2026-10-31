<?php
require_once __DIR__ . '/../app/Core/Environment.php';
require_once __DIR__ . '/../app/Backup/RecoveryStore.php';
require_once __DIR__ . '/../includes/backup.php';

$directory = sys_get_temp_dir() . '/rm-review-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
putenv('BACKUP_STORAGE_PATH=' . $directory);
$_ENV['BACKUP_STORAGE_PATH'] = $directory;
$_SESSION = ['user_id' => 7, 'role' => 'admin'];
$_FILES = [];
$bytes = 'encrypted-package-fixture';
$draft = ['user_id' => 7, 'role' => 'admin', 'expires_at' => time() + 1800,
    'token' => bin2hex(random_bytes(32)), 'package_hash' => hash('sha256', $bytes),
    'encrypted_package' => $bytes, 'filename' => 'store.rmpkg'];
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function () use ($check): void {
    try {
        $file = backup_review_source();
        if (!empty($file['temporary'])) unlink($file['tmp_name']);
    } catch (DomainException | RuntimeException $exception) {
        return;
    }
    $check(false, 'Invalid review source was accepted.');
};
try {
    $_SESSION['backup_review'] = $draft;
    $_POST = ['review_token' => $draft['token'], 'package_hash' => $draft['package_hash']];
    $file = backup_review_source();
    $check($file['temporary'] && file_get_contents($file['tmp_name']) === $bytes, 'Resumed review changed the encrypted package.');
    unlink($file['tmp_name']);
    foreach (['review_token', 'package_hash'] as $key) {
        $original = $_POST[$key];
        $_POST[$key] = 'tampered';
        $reject();
        $_POST[$key] = $original;
    }
    foreach (['user_id' => 8, 'role' => 'cashier', 'expires_at' => time() - 1] as $key => $value) {
        $_SESSION['backup_review'] = array_replace($draft, [$key => $value]);
        $check(backup_review_draft() === null, 'Wrong owner, role or expired review remained accessible.');
        $reject();
    }
    $_SESSION['backup_review'] = array_replace($draft, ['encrypted_package' => 'changed']);
    $reject();
    $check(glob($directory . '/*') === [], 'Failed package validation left a temporary file.');
    $_SESSION['backup_review'] = $draft;
    $_FILES['backup_file'] = ['error' => UPLOAD_ERR_INI_SIZE];
    $reject();
    echo "Backup review draft: passed (resume, owner, role, expiry, token, hash, upload errors, cleanup)\n";
} finally {
    rmdir($directory);
}
