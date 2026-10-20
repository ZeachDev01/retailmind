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
use App\Backup\DatabaseBackupService;
use App\Backup\DatabaseRestoreService;

if (!RecoveryStore::isAvailable()) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    $workspaceUrl = htmlspecialchars(app_url('components/auth/workspace.php'), ENT_QUOTES, 'UTF-8');
    $dashboardUrl = htmlspecialchars(app_url('components/super_administrator/dashboard.php'), ENT_QUOTES, 'UTF-8');
    $logoutUrl = htmlspecialchars(app_url('components/auth/logout.php'), ENT_QUOTES, 'UTF-8');
    $stylesheetUrl = htmlspecialchars(theme_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8');
    $restoreStylesheetUrl = htmlspecialchars(app_url('assets/css/backup_restore.css'), ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head>';
    require_once __DIR__ . '/../../../backend/includes/theme.php';
    retailmind_theme_head();
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Backup &amp; Restore unavailable</title><link rel="stylesheet" href="' . $stylesheetUrl . '">'
        . '<link rel="stylesheet" href="' . $restoreStylesheetUrl . '"></head>'
        . '<body class="database-backup-page database-restore-page"><main class="main-content recovery-standalone">'
        . '<section class="dashboard-section">'
        . '<h1>Backup &amp; Restore</h1>'
        . '<p role="alert">Database Backup and Restore are unavailable on this hosting plan.</p>'
        . '<p class="recovery-standalone-actions"><a class="btn btn-primary" href="' . $workspaceUrl . '">Change workspace</a> '
        . '<a class="btn btn-secondary" href="' . $dashboardUrl . '">Return to dashboard</a> '
        . '<a class="recovery-standalone-link" href="' . $logoutUrl . '">Log out</a></p>'
        . '</section></main></body></html>';
    return;
}

$message = '';
$messageClass = '';
$invalidField = '';
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
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$renderStandalonePage = static function (string $title, string $heading, string $bodyHtml) use ($escape): void {
    header('Content-Type: text/html; charset=utf-8');
    $stylesheetUrl = $escape(theme_url('assets/css/style.css'));
    $restoreStylesheetUrl = $escape(app_url('assets/css/backup_restore.css'));
    echo '<!doctype html><html lang="en"><head>';
    require_once __DIR__ . '/../../../backend/includes/theme.php';
    retailmind_theme_head();
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $escape($title) . '</title>'
        . '<link rel="stylesheet" href="' . $stylesheetUrl . '">'
        . '<link rel="stylesheet" href="' . $restoreStylesheetUrl . '"></head>'
        . '<body class="database-backup-page database-restore-page"><main class="main-content recovery-standalone">'
        . '<section class="dashboard-section"><h1>' . $escape($heading) . '</h1>'
        . $bodyHtml
        . '</section></main></body></html>';
};

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
            $invalidField = 'file';
        } elseif ((int)$file['size'] > $maxUploadBytes) {
            $message = 'The restore file is larger than this server accepts. ' . $restorer->supportedSizeLine();
            $messageClass = 'tag-warning';
            $invalidField = 'file';
        } else {
            $filename = basename((string)$file['name']);
            try {
                $outcome = $restorer->restore($actorUserId, $actorRole, (string)$file['tmp_name'], $filename, (string)($_POST['restore_password'] ?? ''));
                \App\Core\Session::destroy();
                $renderStandalonePage(
                    'Database restored',
                    'Database restored',
                    '<p class="recovery-intro" role="status">' . $escape($outcome['message']) . '</p>'
                    . '<p class="recovery-standalone-actions"><a class="btn btn-primary" href="' . $escape(app_url('?login=1')) . '">Sign in</a></p>'
                );
                exit;
            } catch (Throwable $e) {
                if (RecoveryStore::isPaused()) {
                    error_log('Incomplete restore: ' . $e->getMessage());
                    http_response_code(503);
                    $renderStandalonePage(
                        'Database recovery required',
                        'Database recovery required',
                        '<div class="alert tag-warning" role="alert">Restoration stopped before completion. Store access remains blocked. Use the offline restore command to recover with the retained safety backup. See docs/BACKUP_RECOVERY.md.</div>'
                    );
                    exit;
                }
                if (!RecoveryStore::admitRequest()) {
                    http_response_code(503);
                    $renderStandalonePage(
                        'Database recovery in progress',
                        'Database recovery in progress',
                        '<div class="alert tag-warning" role="alert">Database recovery is in progress. Try again later.</div>'
                    );
                    exit;
                }
                $message = \App\Support\OperatorAlert::message($e, $restoreFailureLine);
                $messageClass = 'tag-warning';
                $invalidField = 'restore';
            }
        }
    }
}

$service->cleanupStrandedArtifacts();
try {
    $history = $service->history($actorRole);
} catch (PDOException) {
    ensure_backup_schema($pdo);
    $history = $service->history($actorRole);
}
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Backup &amp; Restore</title>
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/backup_restore.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/backup_restore.css')) ?>">
</head>

<body class="database-backup-page database-restore-page" data-rm-authenticated="true"
    data-rm-backup-status="<?= $escape(app_url('components/backup/backup_status.php')) ?>">
    <div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
            <header class="topbar recovery-heading">
                <div>
                    <h1>Backup &amp; Restore</h1>
                </div>
                <a class="recovery-history-link" href="#backup-history">View backup history</a>
            </header>

            <?php if ($message): ?><div id="recovery-feedback" class="alert <?= $escape($messageClass) ?>" role="<?= $messageClass === 'tag-warning' ? 'alert' : 'status' ?>"><?= $escape($message) ?></div><?php endif; ?>

            <?php if ($download): ?>
                <section class="dashboard-section backup-ready" aria-labelledby="backup-ready-title">
                    <h2 id="backup-ready-title">Your backup is ready</h2>
                    <p class="backup-ready-actions"><a class="btn btn-primary" href="<?= $escape($download['url']) ?>" data-backup-download>Download <?= $escape($download['filename']) ?></a> <span class="backup-hint"><?= number_format($download['size'] / 1024, 1) ?> KB</span></p>
                </section>
            <?php endif; ?>

            <div class="recovery-workspace">
                <section class="dashboard-section backup-create-panel" aria-labelledby="create-title">
                    <div class="recovery-panel-heading">
                        <h2 id="create-title">Create backup</h2>
                    </div>
                    <form method="post" class="backup-create-form"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button type="submit" class="btn btn-primary" data-backup-create>Create SQL backup</button></form>
                    <div class="backup-storage-note"><strong>Unencrypted: includes password hashes and restricted audit history. Keep private on separate storage.</strong></div>
                    <details class="restore-details"><summary>Backup details</summary><ul class="backup-guidance"><li>Copies all Store records, accounts, settings, and audit history at one point in time.</li><li>Saving pauses briefly during capture, then resumes.</li><li>Completed means created; it does not confirm the file was safely stored. Download and verify it separately.</li></ul></details>
                    <?php if (is_file(RecoveryStore::path('latest-safety.sql'))): ?>
                        <div class="backup-safety"><a href="<?= $escape(app_url('components/backup/safety_download.php')) ?>">Download latest pre-restore safety backup</a></div>
                    <?php endif; ?>
                </section>
                <section class="dashboard-section backup-restore-panel" aria-labelledby="restore-title">
                    <div class="recovery-panel-heading"><h2 id="restore-title">Restore backup</h2></div>
                    <div class="restore-warning" id="restore-warning"><strong>This replaces the entire database, including accounts, passwords, settings, and audit history.</strong><p>Newer records are lost. Undo requires another restore.</p></div>
                    <form method="post" enctype="multipart/form-data" data-restore-form><?= csrf_field() ?><input type="hidden" name="action" value="restore">
                        <div class="form-group<?= $invalidField === 'file' ? ' invalid' : '' ?>"><label for="backup-file">Trusted RetailMind SQL backup (max <?= number_format($maxUploadBytes / 1048576, 1) ?> MB)</label><div class="restore-file-field"><input id="backup-file" type="file" name="backup_file" accept=".sql"<?= $invalidField === 'file' ? ' aria-describedby="recovery-feedback" aria-invalid="true"' : '' ?> required></div></div>
                        <div class="form-group<?= $invalidField === 'restore' ? ' invalid' : '' ?>"><label for="restore-password">Current password</label><input id="restore-password" type="password" name="restore_password" autocomplete="current-password"<?= $invalidField === 'restore' ? ' aria-describedby="recovery-feedback" aria-invalid="true"' : '' ?> required></div>
                        <button type="submit" class="btn btn-danger" data-backup-restore aria-describedby="restore-warning">Restore database</button>
                    </form>
                    <details class="restore-details"><summary>Restore details</summary><ul class="backup-guidance"><li>Only trusted RetailMind .sql files are supported; older .rmbak files cannot be restored.</li><li>A safety backup is saved before replacement.</li><li>Store access is blocked during replacement.</li><li>Everyone signs in again with the backup's credentials.</li><li>A failed restore blocks Store access until offline recovery.</li></ul></details>
                </section>
            </div>

            <section class="dashboard-section backup-history" id="backup-history" aria-labelledby="history-title">
                <div class="recovery-panel-heading"><h2 id="history-title">Backup history</h2><span class="history-total"><?= count($history) ?> <?= count($history) === 1 ? 'record' : 'records' ?></span></div>
                <?php if ($history): ?>
                    <div class="history-tools" hidden data-history-tools><div class="history-search"><label for="history-search">Search history</label><input id="history-search" type="search" placeholder="File, date, user or notes"></div><div class="history-filter"><label for="history-outcome">Outcome</label><select id="history-outcome"><option value="">All outcomes</option><?php foreach (array_unique(array_column($history, 'status')) as $status): ?><option value="<?= $escape($status) ?>"><?= $escape(ucfirst((string)$status)) ?></option><?php endforeach; ?></select></div><p class="history-count" data-history-count role="status"></p></div>
                <?php endif; ?>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Backup history records">
                    <table data-no-smart-table>
                        <thead><tr><th scope="col">File / activity</th><th scope="col">Created</th><th scope="col">Outcome</th><th scope="col">Size</th><th scope="col">Requested by</th></tr></thead>
                        <tbody><?php foreach ($history as $row): ?>
                            <tr data-history-row data-outcome="<?= $escape($row['status']) ?>">
                                <td class="history-file"><span class="history-filename"><?= $escape($row['filename']) ?></span><span class="history-type"><?= $escape(ucfirst((string)$row['backup_type'])) ?></span><?php if (trim((string)$row['notes']) !== ''): ?><details class="history-notes"><summary aria-label="View notes for <?= $escape($row['filename']) ?> (<?= $escape(format_display_datetime((string)$row['created_at'])) ?>)">View notes</summary><p><?= $escape($row['notes']) ?></p></details><?php endif; ?></td>
                                <td class="history-date"><?= $escape(format_display_datetime((string)$row['created_at'])) ?></td>
                                <td><span class="history-status <?= $row['status'] === 'completed' ? 'is-completed' : ($row['status'] === 'failed' ? 'is-failed' : '') ?>"><?= $escape(ucfirst((string)$row['status'])) ?></span></td>
                                <td class="history-size"><?= number_format($row['file_size'] / 1024, 1) ?> KB</td>
                                <td class="history-user"><?= $escape($row['requested_by']) ?></td>
                            </tr><?php endforeach; ?>
                            <tr data-history-empty <?= $history ? 'hidden' : '' ?>><td colspan="5"><div class="recovery-empty"><strong><?= $history ? 'No matching records' : 'No backups yet' ?></strong></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
    <script src="<?= $escape(app_url('assets/js/backup_restore.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/backup_restore.js')) ?>"></script>
</body>

</html>
