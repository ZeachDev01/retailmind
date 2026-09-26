<?php
// Administrator Database Backup (ticket #69).
//
// The Administrator may download the complete readable database. In-app history
// stays limited; restoration and platform governance remain Super Administrator-only.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP);

use App\Authorization\RoleCapabilityPolicy;
use App\Services\DatabaseBackupService;

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
}

ensure_backup_schema($pdo);
$history = $service->history($actorRole);
$restoreCapability = role_capability_policy()->allows($actorRole, RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Database Backup</title>
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/backup.css')) ?>">
</head>

<body class="manage-users-page database-backup-page" data-rm-authenticated="true"
    data-rm-backup-status="<?= $escape(app_url('components/backup/backup_status.php')) ?>">
    <div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
            <div class="topbar">
                <div>
                    <h1>Database Backup</h1>
                    <p class="page-subtitle">Create an unencrypted SQL copy of the Store database and download it to your own device.</p>
                </div>
            </div>

            <?php if ($message): ?><div class="alert <?= $escape($messageClass) ?>" role="status"><?= $escape($message) ?></div><?php endif; ?>

            <?php if ($download): ?>
                <section class="dashboard-section backup-ready" aria-labelledby="backup-ready-title">
                    <h3 id="backup-ready-title">Your backup is ready</h3>
                    <p class="section-description">A completed download is not proof that the file was saved. Keep <?= $escape($download['filename']) ?> on separate storage, and give it to your Super Administrator if you ever need it restored.</p>
                    <p><a class="btn" href="<?= $escape($download['url']) ?>" data-backup-download>Download <?= $escape($download['filename']) ?></a> <span class="backup-hint"><?= number_format($download['size'] / 1024, 1) ?> KB</span></p>
                </section>
            <?php endif; ?>

            <div class="card-grid">
                <section class="dashboard-section">
                    <h3>Create backup</h3>
                    <p class="section-description">Saving changes pauses briefly while a consistent point-in-time copy is captured, then resumes automatically. You can keep browsing and your unsaved work is not lost.</p>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button class="btn" data-backup-create>Create backup</button></form>
                </section>
                <section class="dashboard-section">
                    <h3>Keeping your copy safe</h3>
                    <ul class="backup-guidance">
                        <li>The file is not encrypted. It exposes all records, including password hashes and restricted audit history. Keep it private.</li>
                        <li>Store it somewhere other than the application server, such as removable storage or your own cloud folder.</li>
                        <li>No encryption key is needed. Only your Super Administrator may restore the database.</li>
                    </ul>
                    <?php if (!$restoreCapability): ?>
                        <p class="backup-hint">Ask your Super Administrator to restore a backup. Restoration is a technical recovery action.</p>
                    <?php endif; ?>
                </section>
            </div>

            <div class="dashboard-section">
                <h3>Backup history</h3>
                <p class="section-description">Recent backup activity. A completed entry means the server created the backup; it does not confirm the file reached your device.</p>
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
