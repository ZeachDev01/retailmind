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
$file = null;
try {
    if (empty($_POST['confirm_replacement'])) throw new DomainException('Confirm that this snapshot replaces newer Store records and pauses operations.');
    if (isset($_FILES['backup_file']) && ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && !is_uploaded_file((string)($_FILES['backup_file']['tmp_name'] ?? ''))) {
        throw new DomainException('Select the encrypted RetailMind package again.');
    }
    $file = backup_review_source();
    $decisions = is_array($_POST['people'] ?? null) ? $_POST['people'] : [];
    if ($decisions !== [] && (!is_string($_POST['package_hash'] ?? null)
        || !hash_equals($_POST['package_hash'], hash_file('sha256', $file['tmp_name'])))) {
        throw new DomainException('The selected backup changed. Review it again before restoring.');
    }
    $backups = new DatabaseBackupService($pdo, role_capability_policy());
    $restorer = new DatabaseRestoreService($pdo, role_capability_policy(), $backups);
    $restorer->startIncoming((int)$_SESSION['user_id'], $role, (string)$file['tmp_name'], (string)$file['name'],
        (string)($_POST['restore_password'] ?? ''), $decisions);
    unset($_SESSION['backup_review']);
} catch (Throwable $exception) {
    $_SESSION['restore_error'] = \App\Support\OperatorAlert::message($exception, 'Restore could not finish. Check recovery status before reopening the Store.');
} finally {
    if (!empty($file['temporary'])) unlink($file['tmp_name']);
}
if (RecoveryStore::isPaused()) {
    header('Location: ' . app_url('components/backup/recovery.php'), true, 303);
} else {
    $page = $role === 'super_admin' ? 'system_administrator/backup_restore.php' : 'administrator/database_backup.php';
    header('Location: ' . app_url('components/' . $page) . '#restore-package', true, 303);
}
exit;
