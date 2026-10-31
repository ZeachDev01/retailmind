<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\{BackupKeyStore, DatabaseBackupService};

require_capability(RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP);
require_local_request('Recovery keys are not available during remote monitoring.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
csrf_verify();
header('Cache-Control: no-store');
try {
    $actor = (new DatabaseBackupService($pdo, role_capability_policy()))->authorizeActor((int)$_SESSION['user_id'], (string)current_role());
    $password = (string)($_POST['key_password'] ?? '');
    if ($password === '' || !password_verify($password, (string)$actor['password_hash'])) {
        throw new DomainException('Your current login password is incorrect.');
    }
    $action = (string)($_POST['key_action'] ?? '');
    if ($action === 'download') {
        $key = BackupKeyStore::current() . "\n";
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="retailmind-recovery.key"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($key));
        echo $key;
        exit;
    }
    if ($action !== 'import') throw new DomainException('Choose a recovery-key action.');
    $file = $_FILES['recovery_key'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))
        || filesize($file['tmp_name']) > 128) {
        throw new DomainException('Select a valid RetailMind recovery-key file.');
    }
    BackupKeyStore::import((string)file_get_contents($file['tmp_name']));
    $_SESSION['backup_key_message'] = 'Recovery key imported. You can now restore its backups with your current login password.';
} catch (Throwable $exception) {
    $_SESSION['backup_key_message'] = $exception instanceof DomainException || $exception instanceof RuntimeException
        ? $exception->getMessage() : 'The recovery key could not be saved. Try again.';
}
$page = (string)current_role() === 'super_admin' ? 'system_administrator/backup_restore.php' : 'administrator/database_backup.php';
header('Location: ' . app_url('components/' . $page) . '#recovery-key', true, 303);
exit;
