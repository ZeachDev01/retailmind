<?php
// Super Administrator Database Backup and Database Restore (ticket #69).
//
// This page keeps the whole platform recovery surface: the shared SQL
// Database Backup workflow, the destructive Database Restore that only the
// Super Administrator may run, and the full backup history including the
// restricted recovery rows.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\RecoveryStore;
use App\Services\DatabaseBackupService;
use App\Services\DatabaseRestoreService;

$message = '';
$messageClass = '';
// Operator Alert boundary lines. The full exception text always goes to the log and the
// Protected Audit Record trail; the operator only ever sees these easy sentences.
$backupFailureLine = 'The backup could not be created. Check free space and your connection, then try again. Tell your Super Administrator if this keeps happening.';
$restoreFailureLine = 'The restore could not be completed. Check the backup file and try again. Tell your Super Administrator if this keeps happening.';
$download = null;
$service = new DatabaseBackupService($pdo, role_capability_policy());
$restorer = new DatabaseRestoreService($pdo, role_capability_policy(), $service);
$actorUserId = (int)$_SESSION['user_id'];
$actorRole = (string)current_role();
$maxUploadBytes = $restorer->supportedUploadBytes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'backup') {
        try {
            $result = $service->create($actorUserId, $actorRole);
            if (($result['status'] ?? '') === DatabaseBackupService::OUTCOME_IN_PROGRESS) {
                $message = DatabaseBackupService::IN_PROGRESS_LINE;
                $messageClass = 'tag-warning';
            } else {
                $service->logActivity($actorUserId, 'Database backup', [
                    'filename' => (string)$result['filename'],
                    'size' => (int)$result['size'],
                    'snapshot_at' => (string)$result['snapshot_at'],
                ]);
                $message = 'Your SQL backup is ready. Download it and keep it somewhere other than the application server.';
                $messageClass = 'tag-success';
                $download = [
                    'filename' => (string)$result['filename'],
                    'url' => app_url('components/backup/backup_download.php?token=' . rawurlencode((string)$result['token'])),
                    'size' => (int)$result['size'],
                ];
            }
        } catch (Throwable $e) {
            record_backup_history($pdo, DatabaseBackupService::downloadFilename(), 'manual', 0, 'failed', $actorUserId, $e->getMessage());
            $message = \App\Support\OperatorAlert::message($e, $backupFailureLine);
            $messageClass = 'tag-warning';
        }
    }
    if ($action === 'restore') {
        $file = $_FILES['backup_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $message = 'Select a valid RetailMind SQL backup file.';
            $messageClass = 'tag-warning';
        } elseif ((int)$file['size'] > $maxUploadBytes) {
            $message = 'The restore file is larger than this server accepts. ' . $restorer->supportedSizeLine();
            $messageClass = 'tag-warning';
        } else {
            $filename = basename((string)$file['name']);
            try {
                $outcome = $restorer->restore($actorUserId, $actorRole, (string)$file['tmp_name'], $filename, (string)($_POST['restore_password'] ?? ''));
                \App\Core\Session::destroy();
                header('Content-Type: text/html; charset=utf-8');
                echo '<p>' . htmlspecialchars($outcome['message'], ENT_QUOTES, 'UTF-8') . '</p><p><a href="'
                    . htmlspecialchars(app_url('?login=1'), ENT_QUOTES, 'UTF-8') . '">Sign in</a></p>';
                exit;
            } catch (Throwable $e) {
                if (RecoveryStore::isPaused()) {
                    error_log('Incomplete restore: ' . $e->getMessage());
                    http_response_code(503);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo 'Restoration stopped before completion. Store access remains blocked. Use the offline restore command to recover with the retained safety backup. See docs/BACKUP_RECOVERY.md.';
                    exit;
                }
                if (!RecoveryStore::admitRequest()) {
                    http_response_code(503);
                    exit('Database recovery is in progress. Try again later.');
                }
                $message = \App\Support\OperatorAlert::message($e, $restoreFailureLine);
                $messageClass = 'tag-warning';
            }
        }
    }
}

ensure_backup_schema($pdo);
$service->cleanupStrandedArtifacts();
$history = $service->history($actorRole);
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Backup &amp; Restore</title>
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/backup.css')) ?>">
</head>

<body class="database-backup-page" data-rm-authenticated="true"
    data-rm-backup-status="<?= $escape(app_url('components/backup/backup_status.php')) ?>">
    <div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
            <div class="topbar">
                <div>
                    <h1>Backup &amp; Restore</h1>
                    <p class="page-subtitle">Download unencrypted SQL backups and replace the Store database with a compatible backup.</p>
                </div>
            </div>

            <?php if ($message): ?><div class="alert <?= $escape($messageClass) ?>" role="status"><?= $escape($message) ?></div><?php endif; ?>

            <?php if ($download): ?>
                <section class="dashboard-section backup-ready" aria-labelledby="backup-ready-title">
                    <h3 id="backup-ready-title">Your backup is ready</h3>
                    <p class="section-description">A completed download is not proof that the file was saved. Keep <?= $escape($download['filename']) ?> on separate storage.</p>
                    <p><a class="btn" href="<?= $escape($download['url']) ?>" data-backup-download>Download <?= $escape($download['filename']) ?></a> <span class="backup-hint"><?= number_format($download['size'] / 1024, 1) ?> KB</span></p>
                </section>
            <?php endif; ?>

            <div class="card-grid">
                <section class="dashboard-section">
                    <h3>Create backup</h3>
                    <p class="section-description">Saving changes pauses briefly while a consistent point-in-time copy is captured, then resumes automatically. The temporary file on the server is removed once it is delivered.</p>
                    <p class="section-description">This file contains all database records, including password hashes and restricted audit history. Keep it private.</p>
                    <?php if (is_file(RecoveryStore::path('latest-safety.sql'))): ?>
                        <p><a href="<?= $escape(app_url('components/backup/safety_download.php')) ?>">Download latest pre-restore safety backup</a></p>
                    <?php endif; ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button class="btn" data-backup-create>Create backup</button></form>
                </section>
                <section class="dashboard-section">
                    <h3>Restore backup</h3>
                    <p class="section-description">A safety backup is retained first. Restoration replaces all records, including accounts, passwords, settings, and audit history. Everyone is signed out and must use credentials from the backup. If restoration fails, Store access stays blocked until offline recovery is completed.</p>
                    <p class="backup-hint"><?= $escape($restorer->supportedSizeLine()) ?></p>
                    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="restore">
                        <div class="form-group"><label for="backup-file">RetailMind SQL backup</label><input id="backup-file" type="file" name="backup_file" accept=".sql" required></div>
                        <div class="form-group"><label for="restore-password">Your current Super Administrator password</label><input id="restore-password" type="password" name="restore_password" autocomplete="current-password" required></div>
                        <p class="backup-hint">Continuing replaces the entire database. This is not a merge and cannot be undone without another restore.</p>
                        <button class="btn btn-danger">Verify password and replace database</button>
                    </form>
                </section>
            </div>

            <div class="dashboard-section">
                <h3>Backup history</h3>
                <p class="section-description">A completed entry means the server created the backup; it does not confirm the file reached a safe location.</p>
                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Date</th>
                            <th>File</th>
                            <th>Type</th>
                            <th>Size</th>
                            <th>Outcome</th>
                            <th>User</th>
                            <th>Notes</th>
                        </tr><?php foreach ($history as $row): ?><tr>
                                <td><?= $escape(format_display_datetime((string)$row['created_at'])) ?></td>
                                <td><?= $escape($row['filename']) ?></td>
                                <td><?= $escape($row['backup_type']) ?></td>
                                <td><?= number_format($row['file_size'] / 1024, 1) ?> KB</td>
                                <td><?= $escape($row['status']) ?></td>
                                <td><?= $escape($row['requested_by']) ?></td>
                                <td><?= $escape($row['notes']) ?></td>
                            </tr><?php endforeach; ?><?php if (!$history): ?><tr>
                                <td colspan="7">No backup history.</td>
                            </tr><?php endif; ?>
                    </table>
                </div>
            </div>
        </main>
    </div>
    <script src="<?= $escape(app_url('assets/js/backup_status.js')) ?>"></script>
</body>

</html>
