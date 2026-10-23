<?php
// Super Administrator Database Backup and Database Restore (ticket #69).
//
// Shared encrypted capture, read-only Super Administrator package preflight,
// and restricted backup history. Package replacement is a separate workflow.
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
$download = null;
$preflight = null;
$service = new DatabaseBackupService($pdo, role_capability_policy());
$restorer = new DatabaseRestoreService($pdo, role_capability_policy(), $service);
$actorUserId = (int)$_SESSION['user_id'];
$actorRole = (string)current_role();
$maxUploadBytes = $restorer->supportedUploadBytes();
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                $message = 'Your encrypted backup is ready. Download it and keep it somewhere other than the application server.';
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
    if ($action === 'preflight' || $action === 'restore') {
        $file = $_FILES['backup_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            $message = 'Select a valid encrypted RetailMind package (.rmpkg). ' . $restorer->supportedSizeLine();
            $messageClass = 'tag-warning';
            $invalidField = 'file';
        } elseif ((int)$file['size'] > $maxUploadBytes) {
            $message = 'The package is larger than this server accepts. ' . $restorer->supportedSizeLine();
            $messageClass = 'tag-warning';
            $invalidField = 'file';
        } else {
            try {
                $preflight = $restorer->preflight($actorUserId, $actorRole, (string)$file['tmp_name'],
                    (string)$file['name'], (string)($_POST['restore_password'] ?? ''), (string)($_POST['backup_passphrase'] ?? ''));
                $message = (string)$preflight['message'];
                $messageClass = $preflight['valid'] ? 'tag-success' : 'tag-warning';
            } catch (DomainException $e) {
                $message = $e->getMessage();
                $messageClass = 'tag-warning';
                $invalidField = 'restore';
            } catch (Throwable $e) {
                $message = \App\Support\OperatorAlert::message($e, 'The package could not be validated. No Store records or files were replaced.');
                $messageClass = 'tag-warning';
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
                    <form method="post" class="backup-create-form"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><div class="form-group"><label for="backup-passphrase">New backup passphrase</label><input id="backup-passphrase" type="password" name="backup_passphrase" autocomplete="new-password" minlength="16" maxlength="1024" required></div><button type="submit" class="btn btn-primary" data-backup-create>Create encrypted backup</button></form>
                    <div class="backup-storage-note"><strong>Encrypted package:</strong> includes all records and uploaded Product/profile images. Keep the package and passphrase off-server, in separate safe places.</div>
                    <details class="restore-details"><summary>Backup details</summary><ul class="backup-guidance"><li>Copies Store records and required uploaded images at one point in time.</li><li>Saving pauses briefly during capture, then resumes.</li><li>Losing every passphrase copy makes this package unusable; changing a login password cannot recover it.</li><li>Completed means created; it does not confirm the file was safely stored. Download and verify it separately.</li></ul></details>
                    <?php if (is_file(RecoveryStore::path('latest-safety.sql'))): ?>
                        <div class="backup-safety"><a href="<?= $escape(app_url('components/backup/safety_download.php')) ?>">Download latest pre-restore safety backup</a></div>
                    <?php endif; ?>
                </section>
                <section class="dashboard-section backup-restore-panel" aria-labelledby="restore-title">
                    <div class="recovery-panel-heading"><h2 id="restore-title">Validate backup</h2></div>
                    <div class="restore-warning" id="restore-warning"><strong>Read-only package validation. No records or uploaded files are replaced.</strong><p>Validation does not authorize replacement. Plaintext SQL and old encrypted formats are not accepted here. Keep old recovery copies privately.</p></div>
                    <form method="post" enctype="multipart/form-data" data-restore-form><?= csrf_field() ?><input type="hidden" name="action" value="preflight">
                        <div class="form-group<?= $invalidField === 'file' ? ' invalid' : '' ?>"><label for="backup-file">Trusted encrypted RetailMind package</label><p class="restore-file-hint" id="backup-file-hint">.rmpkg only · Max <?= number_format($maxUploadBytes / 1048576, 1) ?> MB</p><div class="restore-file-field"><input id="backup-file" type="file" name="backup_file" accept=".rmpkg" aria-describedby="backup-file-hint<?= $invalidField === 'file' ? ' recovery-feedback' : '' ?>"<?= $invalidField === 'file' ? ' aria-invalid="true"' : '' ?> required></div></div>
                        <div class="form-group"><label for="restore-password">Current destination login password</label><input id="restore-password" type="password" name="restore_password" autocomplete="current-password"<?= $invalidField === 'restore' ? ' aria-describedby="recovery-feedback" aria-invalid="true"' : '' ?> required></div>
                        <div class="form-group"><label for="restore-passphrase">This package's original backup passphrase</label><input id="restore-passphrase" type="password" name="backup_passphrase" autocomplete="off" maxlength="1024"<?= $invalidField === 'restore' ? ' aria-describedby="recovery-feedback" aria-invalid="true"' : '' ?> required></div>
                        <button type="submit" class="btn btn-primary" data-backup-restore aria-describedby="restore-warning">Validate package</button>
                    </form>
                    <?php if ($preflight): ?><div class="preflight-result" role="status"><p><?= (int)$preflight['files'] ?> uploaded files · <?= number_format((int)$preflight['bytes'] / 1048576, 1) ?> MB · Snapshot <?= $escape($preflight['snapshot_at']) ?></p><?php if ($preflight['conflicts']): ?><h3>Identity conflicts to resolve before replacement</h3><ul><?php foreach ($preflight['conflicts'] as $conflict): ?><li><?= $escape($conflict['kind']) ?>: source user <?= (int)$conflict['source_id'] ?>, destination user <?= (int)$conflict['destination_id'] ?> — <?= $escape($conflict['message']) ?></li><?php endforeach; ?></ul><?php endif; ?><p>No Store records or files were replaced.</p></div><?php endif; ?>
                    <details class="restore-details"><summary>Passphrase custody</summary><p>Keep an independent passphrase recovery copy outside both servers. Losing all copies makes this package unusable; changing a login password cannot decrypt it.</p></details>
                </section>
            </div>

            <section class="dashboard-section backup-history" id="backup-history" aria-labelledby="history-title">
                <div class="recovery-panel-heading"><h2 id="history-title">Backup history</h2><span class="history-total"><?= count($history) ?> <?= count($history) === 1 ? 'record' : 'records' ?></span></div>
                <?php if ($history): ?>
                    <div class="history-tools" hidden data-history-tools><div class="history-search"><label for="history-search">Search history</label><input id="history-search" type="search" placeholder="File, date, user or notes"></div><div class="history-filter"><label for="history-outcome">Outcome</label><select id="history-outcome"><option value="">All outcomes</option><?php foreach (array_unique(array_column($history, 'status')) as $status): ?><option value="<?= $escape($status) ?>"><?= $escape(ucfirst((string)$status)) ?></option><?php endforeach; ?></select></div><p class="history-count" data-history-count role="status"></p></div>
                <?php endif; ?>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Backup history records">
                    <table data-no-smart-table>
                        <thead><tr><th scope="col">File / activity</th><th scope="col">Created</th><th scope="col">Outcome</th><th scope="col" class="history-size">Size</th><th scope="col">Requested by</th></tr></thead>
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
