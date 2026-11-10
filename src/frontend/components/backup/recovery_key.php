<?php
// Single guarded path for the Backup Keyring: import, export by fingerprint,
// promotion, deletion, and the legacy primary download alias. Every action
// re-verifies the current login password and revalidates the active account.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\{BackupKeyRecommendation, BackupKeyStore, DatabaseBackupService};

require_capability(RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP);
require_local_request('Recovery keys are not available during remote monitoring.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
csrf_verify();
header('Cache-Control: no-store');
$allowedActions = ['download', 'export', 'import', 'promote', 'delete'];
$service = new DatabaseBackupService($pdo, role_capability_policy());
$actorUserId = (int)$_SESSION['user_id'];
$actorRole = (string)current_role();
try {
    $actor = $service->authorizeActor((int)$_SESSION['user_id'], (string)current_role());
    $password = (string)($_POST['key_password'] ?? '');
    if ($password === '' || !password_verify($password, (string)$actor['password_hash'])) {
        throw new DomainException('Your current login password is incorrect.');
    }
    $action = (string)($_POST['key_action'] ?? '');
    if (!in_array($action, $allowedActions, true)) {
        throw new DomainException('Choose a recovery-key action.');
    }

    if ($action === 'import') {
        $file = $_FILES['recovery_key'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))
            || filesize($file['tmp_name']) > 128) {
            throw new DomainException('Select a valid RetailMind recovery-key file.');
        }
        $imported = BackupKeyStore::import((string)file_get_contents($file['tmp_name']));
        $service->logActivity($actorUserId, 'Recovery key imported', [
            'fingerprint' => $imported['fingerprint'],
            'status' => $imported['status'],
        ]);
        $_SESSION['backup_key_message'] = $imported['status'] === 'present'
            ? 'Recovery key already in this Backup Keyring. Its backups open with your current login password.'
            : 'Recovery key imported. You can now restore its backups with your current login password.';
    } elseif ($action === 'download' || $action === 'export') {
        // A download may resolve the legacy primary alias; an export names the
        // exact key, so archived and imported keys stay reachable.
        $fingerprint = $action === 'download'
            ? BackupKeyStore::currentFingerprint()
            : (string)($_POST['key_fingerprint'] ?? '');
        // BackupKeyStore::find() is the single fingerprint validation path.
        $key = BackupKeyStore::find($fingerprint);
        $filename = 'retailmind-recovery-' . substr($fingerprint, 0, 8) . '.key';
        $service->logActivity($actorUserId, 'Recovery key exported', [
            'fingerprint' => $fingerprint,
            'filename' => $filename,
            'reason' => $action === 'download' ? 'primary' : 'keyring',
        ]);
        $payload = $key . "\n";
        while (ob_get_level() > 0) ob_end_clean();
        App\Core\Session::closeWrite();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($payload));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        $total = strlen($payload);
        $written = 0;
        while ($written < $total) {
            $chunk = substr($payload, $written, 262144);
            echo $chunk;
            flush();
            // A cancelled transfer must never count as a saved key.
            if (connection_aborted()) exit;
            $written += strlen($chunk);
        }
        if (!connection_aborted() && $written === $total) {
            // PHP can only prove the body was fully written; the operator still
            // owns keeping the file. A failed acknowledgement only re-prompts.
            (new BackupKeyRecommendation($pdo))->acknowledge($actorUserId, $fingerprint);
        }
        exit;
    } elseif ($action === 'promote') {
        $fingerprint = (string)($_POST['key_fingerprint'] ?? '');
        $promoted = BackupKeyStore::promote($fingerprint);
        $service->logActivity($actorUserId, 'Recovery key promoted', [
            'fingerprint' => $promoted['primary'],
            'previous_fingerprint' => $promoted['archived'],
        ]);
        if ($promoted['archived'] !== null && $promoted['archived'] !== $promoted['primary']) {
            $service->logActivity($actorUserId, 'Recovery key archived', [
                'fingerprint' => $promoted['archived'],
                'replaced_by' => $promoted['primary'],
            ]);
        }
        $_SESSION['backup_key_message'] = $promoted['archived'] === null
            ? 'That key is already the Primary Recovery Key.'
            : 'The imported key is now the Primary Recovery Key. The previous primary is archived in the Backup Keyring.';
    } else {
        $fingerprint = (string)($_POST['key_fingerprint'] ?? '');
        if ((string)($_POST['confirm_delete'] ?? '') !== '1') {
            throw new DomainException('Tick the warning to confirm deleting this recovery key.');
        }
        $kind = 'imported';
        foreach (BackupKeyStore::list() as $entry) {
            if ($entry['fingerprint'] === $fingerprint) {
                $kind = (string)$entry['kind'];
                break;
            }
        }
        BackupKeyStore::delete($fingerprint);
        $service->logActivity($actorUserId, 'Recovery key deleted', [
            'fingerprint' => $fingerprint,
            'kind' => $kind,
        ]);
        $_SESSION['backup_key_message'] = 'Recovery key removed from the Backup Keyring. Its packages can no longer be opened.';
    }
} catch (Throwable $exception) {
    $message = $exception instanceof DomainException || $exception instanceof RuntimeException
        ? $exception->getMessage() : 'The recovery key action could not be completed. Try again.';
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['message' => $message], JSON_THROW_ON_ERROR);
        exit;
    }
    $_SESSION['backup_key_message'] = $message;
}
$page = (string)current_role() === 'super_admin' ? 'system_administrator/backup_restore.php' : 'administrator/database_backup.php';
header('Location: ' . app_url('components/' . $page) . '#recovery-key', true, 303);
exit;
