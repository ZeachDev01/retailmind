<?php
// Administrator Database Backup (ticket #69).
//
// Both administrator roles capture encrypted Store packages. In-app history
// stays limited; this screen does not authorize replacement.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP);

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\DatabaseBackupService;

$message = '';
$messageClass = '';
$download = null;
// Operator Alert boundary line. The full exception text always goes to the log
// and the Protected Audit Record trail; the operator only ever sees this line.
$backupFailureLine = 'The backup could not be created. Check free space and your connection, then try again. Tell your Super Administrator if this keeps happening.';
$service = new DatabaseBackupService($pdo, role_capability_policy());
$actorUserId = (int)$_SESSION['user_id'];
$actorRole = (string)current_role();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_local_request('Database backup is not available during remote monitoring.');
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'backup') {
        try {
            $result = $service->create($actorUserId, $actorRole, (string)($_POST['backup_passphrase'] ?? ''));
            if (($result['status'] ?? '') === DatabaseBackupService::OUTCOME_IN_PROGRESS) {
                $message = DatabaseBackupService::IN_PROGRESS_LINE;
                $messageClass = 'tag-warning';
            } else {
                $service->logActivity($actorUserId, 'Database backup', [
                    'filename' => (string)$result['filename'],
                    'size' => (int)$result['size'],
                    'snapshot_at' => (string)$result['snapshot_at'],
                ]);
                $message = 'Backup created. Download your copy below.';
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
}

ensure_backup_schema($pdo);
$history = $service->history($actorRole);
$restoreCapability = role_capability_policy()->allows($actorRole, RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
$restoreService = new \App\Backup\DatabaseRestoreService($pdo, role_capability_policy(), $service);
$restoreGuidance = $restoreService->workflowGuidance();
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Database Backup</title>
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/backup.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/backup.css')) ?>">
</head>

<body class="manage-users-page database-backup-page administrator-backup-page" data-rm-authenticated="true"
    data-rm-backup-status="<?= $escape(app_url('components/backup/backup_status.php')) ?>">
    <div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
            <header class="page-heading">
                <div><h1>Database Backup</h1><p class="section-description">Save an encrypted copy of Store records and uploaded media.</p></div>
                <a class="backup-history-link" href="#backup-history">View backup history</a>
            </header>

            <?php if ($message): ?><div class="alert <?= $escape($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= $escape($message) ?></div><?php endif; ?>
            <?php if (\App\Backup\RecoveryStore::isPaused()): ?><p class="alert tag-warning" role="status">Store operations are paused for recovery. <a href="#backup-recovery">Continue recovery</a>.</p><?php endif; ?>

            <?php if ($download): ?>
                <section class="dashboard-section backup-ready" aria-labelledby="backup-ready-title">
                    <h2 id="backup-ready-title">Your backup is ready</h2>
                    <p class="section-description">Download the file, then keep a copy outside this server. Creating a backup does not confirm the file is saved on your device.</p>
                    <p class="backup-filename"><?= $escape($download['filename']) ?> <span class="backup-hint"><?= number_format($download['size'] / 1024, 1) ?> KB</span></p>
                    <p><a class="btn btn-primary" href="<?= $escape($download['url']) ?>" data-backup-download>Download backup</a></p>
                </section>
            <?php endif; ?>

            <section class="dashboard-section backup-create" aria-labelledby="backup-create-title">
                <div>
                    <h2 id="backup-create-title">Create backup</h2>
                    <p class="section-description">Choose a passphrase to protect the file. You will need it to restore this backup.</p>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup">
                        <div class="form-group">
                            <label for="backup-passphrase">New backup passphrase</label>
                            <input id="backup-passphrase" type="password" name="backup_passphrase" autocomplete="new-password" minlength="16" maxlength="1024" aria-describedby="backup-passphrase-help" required>
                            <p class="backup-hint" id="backup-passphrase-help">At least 16 characters. Keep a separate recovery copy of this passphrase.</p>
                        </div>
                        <button type="submit" class="btn btn-primary" data-backup-create>Create backup</button>
                    </form>
                </div>
                <aside class="backup-storage" aria-label="Keep your backup usable">
                    <h3>Keep your backup usable</h3>
                    <p>After creating a backup, download it and save it outside this server.</p>
                    <p>Keep the passphrase separately. <strong>Resetting your login password cannot unlock a backup.</strong></p>
                    <p>A Super Administrator can validate the file before you restore it.</p>
                </aside>
            </section>

            <section class="dashboard-section backup-history" id="backup-history" aria-labelledby="backup-history-title">
                <h2 id="backup-history-title">Backup history</h2>
                <p class="section-description">“Created” confirms the backup file was made, not that it was downloaded.</p>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Backup history records">
                    <table data-no-smart-table>
                        <thead><tr>
                            <th scope="col">Snapshot date</th>
                            <th scope="col">Backup file</th>
                            <th scope="col">Size</th>
                            <th scope="col">Outcome</th>
                            <th scope="col">Requested by</th>
                        </tr></thead><tbody><?php foreach ($history as $row): ?><tr>
                                <td class="backup-date"><?= $escape(format_display_datetime((string)($row['snapshot_at'] ?? $row['created_at']))) ?></td>
                                <td><span class="backup-filename"><?= $escape($row['filename']) ?></span><span class="backup-hint backup-format"><?= $escape($row['format']) ?></span><?php if (trim((string)$row['notes']) !== ''): ?><details class="backup-row-notes"><summary>View note</summary><p><?= $escape($row['notes']) ?></p></details><?php endif; ?></td>
                                <td class="backup-size"><?= number_format($row['file_size'] / 1024, 1) ?> KB</td>
                                <td><span class="tag <?= $row['status'] === 'completed' ? 'tag-success' : 'tag-warning' ?>"><?= $row['status'] === 'completed' ? 'Created' : 'Not created' ?></span></td>
                                <td><?= $escape($row['requested_by']) ?></td>
                            </tr><?php endforeach; ?><?php if (!$history): ?><tr>
                                <td colspan="5" class="backup-empty">No backups yet. Create one above, then download your copy.</td>
                            </tr><?php endif; ?></tbody>
                    </table>
                </div>
            </section>

            <details class="dashboard-section backup-recovery" id="backup-recovery" <?= \App\Backup\RecoveryStore::isPaused() || !empty($_SESSION['restore_error']) || ($_POST['action'] ?? '') === 'safety' ? 'open' : '' ?>>
                <summary><span class="backup-recovery-title">Restore &amp; recovery</span><span class="backup-hint">Restoring replaces newer Store records. Open only when you need to recover data.</span></summary>
                <div class="restore-guidance" data-restore-guidance>
                    <h2>Restore status</h2>
                    <p><strong>Status:</strong> <span data-restore-stage="<?= $escape($restoreGuidance['stage']) ?>"><?= $escape($restoreGuidance['stage_label']) ?></span></p>
                    <?php if ($restoreGuidance['stage'] === 'technical_approval_waiting'): ?>
                        <p data-restore-next-action>Ask your Super Administrator to approve Identity Reconciliation for the backup you want to restore.</p>
                    <?php else: ?>
                        <p data-restore-next-action><?= $escape($restoreGuidance['next_permitted_action']) ?></p>
                    <?php endif; ?>
                    <?php if (\App\Backup\RecoveryStore::isPaused()): ?>
                        <p><a class="btn btn-primary" href="<?= $escape(app_url('components/backup/recovery.php')) ?>">Open Database Recovery Console</a></p>
                    <?php endif; ?>
                </div>
                <?php require __DIR__ . '/../backup/restore_form.php'; ?>
                <?php require __DIR__ . '/../backup/safety_copy.php'; ?>
            </details>
        </main>
    </div>
    <script src="<?= $escape(app_url('assets/js/backup_status.js')) ?>"></script>
</body>

</html>
