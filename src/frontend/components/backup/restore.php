<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\{DatabaseBackupService, DatabaseRestoreService, RecoveryStore};

require_capability(RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP);
require_local_request('Database restore is not available during remote monitoring.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
csrf_verify();
$role = (string)current_role();
try {
    if (empty($_POST['confirm_replacement'])) throw new DomainException('Confirm that this snapshot replaces newer Store records and pauses operations.');
    $file = $_FILES['backup_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        throw new DomainException('Select a complete encrypted Store package within the displayed upload limit.');
    }
    $backups = new DatabaseBackupService($pdo, role_capability_policy());
    $restorer = new DatabaseRestoreService($pdo, role_capability_policy(), $backups);
    $restorer->startIncoming((int)$_SESSION['user_id'], $role, (string)$file['tmp_name'], (string)$file['name'],
        (string)($_POST['restore_password'] ?? ''), (string)($_POST['backup_passphrase'] ?? ''),
        '');
} catch (Throwable $exception) {
    $_SESSION['restore_error'] = \App\Support\OperatorAlert::message($exception, 'Restore could not finish. Check recovery status before reopening the Store.');
}
if (RecoveryStore::isPaused()) {
    header('Location: ' . app_url('components/backup/recovery.php'), true, 303);
} else {
    $page = $role === 'super_admin' ? 'system_administrator/backup_restore.php' : 'administrator/database_backup.php';
    header('Location: ' . app_url('components/' . $page) . '#restore-package', true, 303);
}
exit;
