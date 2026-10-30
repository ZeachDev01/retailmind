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
                $message = 'Backup ready. Download it and store it off-server.';
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
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/backup.css')) ?>">
</head>

<body class="manage-users-page database-backup-page" data-rm-authenticated="true"
    data-rm-backup-status="<?= $escape(app_url('components/backup/backup_status.php')) ?>">
    <div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
            <header class="page-heading">
                <h1>Database Backup</h1>
            </header>

            <?php if ($message): ?><div class="alert <?= $escape($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= $escape($message) ?></div><?php endif; ?>

            <?php if ($download): ?>
                <section class="dashboard-section backup-ready" aria-labelledby="backup-ready-title">
                    <h2 id="backup-ready-title">Your backup is ready</h2>
                    <p class="section-description">Save <?= $escape($download['filename']) ?> off-server. Creation does not confirm the file was saved on your device.</p>
                    <p><a class="btn" href="<?= $escape($download['url']) ?>" data-backup-download>Download <?= $escape($download['filename']) ?></a> <span class="backup-hint"><?= number_format($download['size'] / 1024, 1) ?> KB</span></p>
                </section>
            <?php endif; ?>

            <section class="dashboard-section restore-guidance" data-restore-guidance>
                <h2>Protected restore guidance</h2>
                <div class="guidance-banner">
                    <p><strong>Current stage:</strong> <span data-restore-stage="<?= $escape($restoreGuidance['stage']) ?>"><?= $escape($restoreGuidance['stage_label']) ?></span></p>
                    <p><strong>Next permitted action:</strong> <span data-restore-next-action><?= $escape($restoreGuidance['next_permitted_action']) ?></span></p>
                </div>
                <?php if ($restoreGuidance['stage'] === 'technical_approval_waiting'): ?>
                    <p class="backup-hint">Technical identity approval from a Super Administrator is required before database replacement.</p>
                <?php elseif (\App\Backup\RecoveryStore::isPaused()): ?>
                    <p><a class="btn btn-primary" href="<?= $escape(app_url('components/backup/recovery.php')) ?>">Open Database Recovery Console</a></p>
                <?php endif; ?>
            </section>

            <?php require __DIR__ . '/../backup/restore_form.php'; ?>
            <?php require __DIR__ . '/../backup/safety_copy.php'; ?>

            <div class="card-grid">
                <section class="dashboard-section">
                    <h2>Create backup</h2>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><div class="form-group"><label for="backup-passphrase">New backup passphrase</label><input id="backup-passphrase" type="password" name="backup_passphrase" autocomplete="new-password" minlength="16" maxlength="1024" required></div><button type="submit" class="btn" data-backup-create>Create backup</button></form>
                </section>
                <section class="dashboard-section">
                    <h2>Keeping your copy safe</h2>
                    <ul class="backup-guidance">
                        <li>Encrypted package contains database records and uploaded media.</li>
                        <li>Store passphrase separately; login password resets cannot decrypt packages.</li>
                        <li>Super Administrator can validate packages prior to replacement.</li>
                    </ul>
                </section>
            </div>

            <div class="dashboard-section">
                <h2>Backup history</h2>
                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Snapshot</th>
                            <th>File</th>
                            <th>Size</th>
                            <th>Format</th>
                            <th>Outcome</th>
                            <th>Requested by</th>
                            <th>Note</th>
                        </tr><?php foreach ($history as $row): ?><tr>
                                <td><?= $escape(format_display_datetime((string)$row['snapshot_at'] ?? $row['created_at'])) ?></td>
                                <td><?= $escape($row['filename']) ?></td>
                                <td><?= number_format($row['file_size'] / 1024, 1) ?> KB</td>
                                <td><?= $escape($row['format']) ?></td>
                                <td><?= $row['status'] === 'completed' ? 'Created' : 'Not created' ?></td>
                                <td><?= $escape($row['requested_by']) ?></td>
                                <td><?= $escape($row['notes']) ?></td>
                            </tr><?php endforeach; ?><?php if (!$history): ?><tr>
                                <td colspan="7">No backups have been created yet.</td>
                            </tr><?php endif; ?>
                    </table>
                </div>
            </div>
        </main>
    </div>
    <script src="<?= $escape(app_url('assets/js/backup_status.js')) ?>"></script>
</body>

</html>
