<?php
// Super Administrator Database Backup and Database Restore (ticket #69).
//
// Shared encrypted capture, read-only preflight, encrypted replacement,
// and restricted backup history.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\BackupKeyRecommendation;
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
$scheduledSchedule = \App\Backup\ScheduledBackupSettings::summary($pdo);
$actorUserId = (int)$_SESSION['user_id'];
$actorRole = (string)current_role();
$maxUploadBytes = $restorer->supportedUploadBytes();
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$backupRecommendation = (new BackupKeyRecommendation($pdo))->recommendation((int)$_SESSION['user_id'], (string)current_role());
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_local_request('Database backup is not available during remote monitoring.');
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
    if ($action === 'run_scheduled_catchup') {
        try {
            $result = (new \App\Backup\ScheduledBackupService($pdo, new \App\Attention\SystemClock()))
                ->runCatchup();
            $message = (string)($result['message'] ?? '');
            $messageClass = ($result['outcome'] ?? '') === \App\Backup\ScheduledBackupService::OUTCOME_COMPLETED
                ? 'tag-success'
                : 'tag-warning';
            $scheduledSchedule = \App\Backup\ScheduledBackupSettings::summary($pdo);
        } catch (Throwable $e) {
            $message = \App\Support\OperatorAlert::message(
                $e,
                'The Scheduled Backup could not be started. Check the Backup Destination in Platform Settings and try later.'
            );
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
                    (string)$file['name'], (string)($_POST['restore_password'] ?? ''));
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
$storageMessage = '';
try {
    ensure_backup_storage($pdo);
    $history = $service->history($actorRole);
} catch (Throwable $exception) {
    error_log('Backup history unavailable: ' . $exception->getMessage());
    $history = [];
    $storageMessage = 'Backup history needs database updates before it can be shown.';
}

$historyPerPage = 10;
$historyTotal = count($history);
$historyPages = max(1, (int)ceil($historyTotal / $historyPerPage));
$historyPage = min($historyPages, max(1, (int)($_GET['history_page'] ?? 1)));
$historyOffset = ($historyPage - 1) * $historyPerPage;
$historyRows = array_slice($history, $historyOffset, $historyPerPage);
$restoreGuidance = $restorer->workflowGuidance();

$completedHistory = array_filter($history, static fn(array $row): bool => ($row['status'] ?? '') === 'completed');
$latestBackup = !empty($completedHistory) ? reset($completedHistory) : null;
$totalSnapshots = count($completedHistory);
$storePaused = RecoveryStore::isPaused();
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Backup &amp; Restore</title>
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/backup.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/backup.css')) ?>">
</head>

<body class="manage-users-page database-backup-page administrator-backup-page" data-rm-authenticated="true"
    data-rm-backup-status="<?= $escape(app_url('components/backup/backup_status.php')) ?>">
    <div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
            <header class="page-heading">
                <div><h1>Backup &amp; Restore</h1></div>
                <div class="backup-pagination-actions">
                    <a class="btn btn-secondary" href="<?= $escape(app_url('components/system_administrator/identity_reconciliation.php')) ?>"><i class="bi bi-person-check" aria-hidden="true"></i> Identity Reconciliation</a>
                </div>
            </header>

            <?php if ($message): ?><div id="recovery-feedback" class="alert <?= $escape($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= $escape($message) ?></div><?php endif; ?>
            <?php if ($storageMessage): ?><p class="alert tag-warning" role="alert"><?= $escape($storageMessage) ?> <a href="<?= $escape(app_url('components/system_administrator/database_updates.php')) ?>">Apply Database Updates</a>.</p><?php endif; ?>
            <?php if (\App\Backup\RecoveryStore::isPaused()): ?><p class="alert tag-warning" role="status">Store operations are paused for recovery. <a href="#backup-recovery">Continue recovery</a>.</p><?php endif; ?>

            <?php require __DIR__ . '/../backup/recovery_key_recommendation.php'; ?>

            <dl class="backup-summary" aria-label="Backup overview">
                <div>
                    <dt>Latest backup</dt>
                    <dd><i class="bi bi-clock-history" aria-hidden="true"></i> <?= $latestBackup ? $escape(format_display_datetime((string)($latestBackup['snapshot_at'] ?? $latestBackup['created_at']))) : 'None yet' ?></dd>
                </div>
                <div>
                    <dt>Backups created</dt>
                    <dd><i class="bi bi-archive" aria-hidden="true"></i> <?= $totalSnapshots ?></dd>
                </div>
                <div>
                    <dt>Store status</dt>
                    <dd><span class="status-indicator <?= $storePaused ? 'is-paused' : 'is-operational' ?>" aria-hidden="true"></span> <?= $storePaused ? 'Paused for recovery' : 'Operational' ?></dd>
                </div>
                <div>
                    <dt>Next scheduled run</dt>
                    <dd><?= $scheduledSchedule['next_run_at'] !== '' ? $escape(format_display_datetime((string)$scheduledSchedule['next_run_at'])) : '—' ?></dd>
                </div>
                <div>
                    <dt>Last outcome</dt>
                    <dd><span class="tag <?= $escape(\App\Backup\ScheduledBackupSettings::outcomeClass($scheduledSchedule['last_outcome'])) ?>"><?= $escape(\App\Backup\ScheduledBackupSettings::outcomeLabel($scheduledSchedule['last_outcome'])) ?></span></dd>
                </div>
            </dl>

            <section class="dashboard-section backup-create" aria-labelledby="backup-schedule-title">
                <div class="backup-create-main">
                    <div class="backup-create-copy">
                        <h2 id="backup-schedule-title">Weekly automatic backup</h2>
                        <p>The Windows task checks hourly and creates one verified copy when the week is due. The schedule and Backup Destination live in Platform Settings.</p>
                    </div>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="run_scheduled_catchup">
                        <button type="submit" class="btn btn-secondary"><i class="bi bi-calendar-check" aria-hidden="true"></i> Catch up missed backup</button>
                    </form>
                </div>
            </section>

            <?php if ($download): ?>
                <section class="dashboard-section backup-ready" aria-labelledby="backup-ready-title">
                    <h2 id="backup-ready-title"><i class="bi bi-check2-circle" aria-hidden="true"></i> Backup ready for download</h2>
                    <div class="backup-ready-details">
                        <p class="backup-filename">
                            <i class="bi bi-file-earmark-lock2" aria-hidden="true"></i>
                            <span><?= $escape($download['filename']) ?></span>
                            <span class="tag tag-success"><?= number_format($download['size'] / 1024, 1) ?> KB</span>
                        </p>
                        <p><a class="btn btn-primary" href="<?= $escape($download['url']) ?>" download data-backup-download><i class="bi bi-download" aria-hidden="true"></i> Download backup</a></p>
                    </div>
                </section>
            <?php endif; ?>

            <section class="dashboard-section backup-create" aria-labelledby="backup-create-title">
                <div class="backup-create-main">
                    <div class="backup-create-copy">
                        <h2 id="backup-create-title">Create backup <span class="backup-encrypted"><i class="bi bi-lock-fill" aria-hidden="true"></i> Encrypted</span></h2>
                        <p>Creating a backup does not confirm the file was saved on your device. Download and store your copy separately.</p>
                    </div>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup">
                        <button type="submit" class="btn btn-primary" data-backup-create><i class="bi bi-plus-lg" aria-hidden="true"></i> Create backup</button>
                    </form>
                </div>
            </section>

            <section class="dashboard-section backup-recovery" id="backup-recovery" aria-labelledby="backup-recovery-title" data-recovery-tab="<?= ($_POST['action'] ?? '') === 'safety' ? 'safety-panel' : (isset($_SESSION['backup_key_message']) ? 'key-panel' : 'restore-panel') ?>">
                <div class="restore-guidance" data-restore-guidance>
                    <div class="restore-status-heading">
                        <h2 id="backup-recovery-title">Restore &amp; recovery</h2>
                        <p class="restore-stage" role="status"><span class="sr-only">Restore status: </span><span data-restore-stage="<?= $escape($restoreGuidance['stage']) ?>"><?= $escape($restoreGuidance['stage_label']) ?></span></p>
                    </div>
                    <?php if (\App\Backup\RecoveryStore::isPaused()): ?>
                        <p data-restore-next-action><?= $escape($restoreGuidance['next_permitted_action']) ?></p>
                        <p><a class="btn btn-primary" href="<?= $escape(app_url('components/backup/recovery.php')) ?>">Continue recovery</a></p>
                    <?php endif; ?>
                </div>
                <div class="backup-recovery-tabs" role="tablist" aria-label="Restore and recovery tools" hidden>
                    <button type="button" id="restore-tab" role="tab" aria-controls="restore-panel" aria-selected="true">Restore</button>
                    <button type="button" id="safety-tab" role="tab" aria-controls="safety-panel" aria-selected="false" tabindex="-1">Safety copy</button>
                    <button type="button" id="key-tab" role="tab" aria-controls="key-panel" aria-selected="false" tabindex="-1">Recovery key</button>
                </div>
                <div id="restore-panel" role="tabpanel" aria-labelledby="restore-tab" tabindex="0">
                    <?php require __DIR__ . '/../backup/restore_form.php'; ?>
                    <details class="backup-tips"<?= in_array($_POST['action'] ?? '', ['preflight', 'restore'], true) ? ' open' : '' ?>>
                        <summary>Advanced: validate without restoring</summary>
                        <section aria-labelledby="restore-title">
                            <div class="recovery-panel-heading">
                                <h2 id="restore-title"><i class="bi bi-patch-check" aria-hidden="true"></i> Validate backup</h2>
                                <span class="tag tag-soft" id="restore-warning"><i class="bi bi-shield-check" aria-hidden="true"></i> Dry run</span>
                            </div>
                            <form method="post" enctype="multipart/form-data" data-restore-form><?= csrf_field() ?><input type="hidden" name="action" value="preflight">
                                <div class="form-group<?= $invalidField === 'file' ? ' invalid' : '' ?>">
                                    <label for="backup-file">Encrypted RetailMind package</label>
                                    <div class="restore-file-field">
                                        <input id="backup-file" type="file" name="backup_file" accept=".rmpkg" aria-describedby="backup-file-hint<?= $invalidField === 'file' ? ' recovery-feedback' : '' ?>"<?= $invalidField === 'file' ? ' aria-invalid="true"' : '' ?> required>
                                    </div>
                                    <span class="backup-hint" id="backup-file-hint">.rmpkg &bull; Max <?= number_format($maxUploadBytes / 1048576, 1) ?>&nbsp;MB</span>
                                </div>
                                <div class="form-group">
                                    <label for="restore-password">Administrator password</label>
                                    <input id="restore-password" type="password" name="restore_password" autocomplete="current-password"<?= $invalidField === 'restore' ? ' aria-describedby="recovery-feedback" aria-invalid="true"' : '' ?> required>
                                </div>
                                <button type="submit" class="btn btn-primary" data-backup-restore aria-describedby="restore-warning"><i class="bi bi-search" aria-hidden="true"></i> Validate package</button>
                            </form>
                            <?php if ($preflight): ?>
                                <?php $compatibility = $preflight['compatibility'] ?? null; ?>
                                <div class="preflight-result" role="status"<?= $compatibility ? ' data-restore-compatibility="' . $escape($compatibility['status']) . '"' : '' ?>>
                                    <p><i class="bi bi-file-earmark-check" aria-hidden="true"></i> <strong><?= (int)$preflight['files'] ?></strong> files &bull; <strong><?= number_format((int)$preflight['bytes'] / 1048576, 1) ?> MB</strong> &bull; Snapshot <?= $escape($preflight['snapshot_at']) ?></p>
                                    <?php if ($compatibility): ?>
                                        <p class="backup-hint" data-restore-compatibility-message>
                                            <span class="tag <?= $compatibility['status'] === 'current' ? 'tag-success' : ($compatibility['status'] === 'upgradable' ? 'tag-warning' : 'tag-danger') ?>"><?= $escape($compatibility['label']) ?></span>
                                            <?= $escape($compatibility['message']) ?>
                                            <?php if (!empty($compatibility['missing_migrations'])): ?>
                                                <span>Database updates involved: <code><?= $escape(implode(', ', $compatibility['missing_migrations'])) ?></code></span>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ($preflight['conflicts']): ?>
                                        <h3>Identity conflicts to resolve</h3>
                                        <ul>
                                            <?php foreach ($preflight['conflicts'] as $conflict): ?>
                                                <li><?= $escape($conflict['kind']) ?>: user <?= (int)$conflict['source_id'] ?> &rarr; <?= (int)$conflict['destination_id'] ?> &mdash; <?= $escape($conflict['message']) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    </details>
                </div>
                <div id="safety-panel" role="tabpanel" aria-labelledby="safety-tab" tabindex="0">
                    <?php require __DIR__ . '/../backup/safety_copy.php'; ?>
                    <?php if (is_file(RecoveryStore::path('latest-safety.sql'))): ?>
                    <p><a class="backup-history-link" href="<?= $escape(app_url('components/backup/safety_download.php')) ?>"><i class="bi bi-download" aria-hidden="true"></i> Download latest pre-restore safety backup</a></p>
                    <?php endif; ?>
                </div>
                <div id="key-panel" role="tabpanel" aria-labelledby="key-tab" tabindex="0">
                    <?php $recoveryTabbed = true; require __DIR__ . '/../backup/recovery_key_form.php'; ?>
                </div>
            </section>
            <section class="dashboard-section backup-history" id="backup-history" aria-labelledby="backup-history-title">
                <div class="backup-section-heading">
                    <h2 id="backup-history-title"><i class="bi bi-clock-history" aria-hidden="true"></i> Backup history</h2>
                    <span class="backup-record-count"><?= $historyTotal ?> <?= $historyTotal === 1 ? 'record' : 'records' ?></span>
                </div>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Backup history records">
                    <table data-no-smart-table aria-label="Backup history">
                        <caption class="sr-only">Recorded database backup snapshots and outcomes</caption>
                        <thead><tr>
                            <th scope="col">Backup date</th>
                            <th scope="col">Kind</th>
                            <th scope="col">Backup file</th>
                            <th scope="col">File</th>
                            <th scope="col" class="backup-size">Size</th>
                            <th scope="col">Outcome</th>
                            <th scope="col">Requested by</th>
                        </tr></thead><tbody><?php foreach ($historyRows as $row): ?><tr>
                                <td class="backup-date"><?= $escape(format_display_datetime((string)($row['snapshot_at'] ?? $row['created_at']))) ?></td>
                                <td><span class="tag tag-soft"><?= $escape($row['backup_type_label'] ?? 'Manual') ?></span></td>
                                <td><i class="bi bi-file-earmark-lock" aria-hidden="true"></i> <span class="backup-filename"><?= $escape($row['filename']) ?></span><?php if (trim((string)$row['notes']) !== ''): ?><details class="backup-row-notes"><summary>Note</summary><p><?= $escape($row['notes']) ?></p></details><?php endif; ?></td>
                                <?php $artifactState = (string)($row['artifact_state'] ?? 'not_retained'); ?>
                                <td><span class="tag <?= $escape(\App\Backup\DatabaseBackupService::artifactClass($artifactState)) ?>"><?= $escape(\App\Backup\DatabaseBackupService::artifactLabel($artifactState)) ?></span></td>
                                <td class="backup-size"><?= number_format($row['file_size'] / 1024, 1) ?> KB</td>
                                <td><?php $rowStatus = (string)$row['status']; ?><span class="tag <?= \App\Backup\ScheduledBackupSettings::outcomeClass($rowStatus) ?>"><i class="bi <?= $rowStatus === 'completed' ? 'bi-check-circle' : ($rowStatus === 'skipped' ? 'bi-arrow-repeat' : 'bi-exclamation-circle') ?>" aria-hidden="true"></i> <?= $escape(\App\Backup\ScheduledBackupSettings::outcomeLabel($rowStatus)) ?></span></td>
                                <td><?= $escape($row['requested_by']) ?></td>
                            </tr><?php endforeach; ?><?php if (!$history): ?><tr>
                                <td colspan="7" class="backup-empty"><i class="bi bi-archive" aria-hidden="true"></i><strong>No backups yet</strong></td>
                            </tr><?php endif; ?></tbody>
                    </table>
                </div>
                <?php if ($historyTotal > 0): ?>
                <nav class="backup-pagination" aria-label="Backup history pagination">
                    <p>Showing <?= $historyOffset + 1 ?>–<?= min($historyOffset + $historyPerPage, $historyTotal) ?> of <?= $historyTotal ?></p>
                    <div class="backup-pagination-actions">
                        <?php if ($historyPage > 1): ?><a class="btn btn-secondary" href="?history_page=<?= $historyPage - 1 ?>#backup-history" rel="prev">Previous</a><?php else: ?><span class="btn btn-secondary" aria-disabled="true">Previous</span><?php endif; ?>
                        <span class="backup-page-number" aria-current="page">Page <?= $historyPage ?> of <?= $historyPages ?></span>
                        <?php if ($historyPage < $historyPages): ?><a class="btn btn-secondary" href="?history_page=<?= $historyPage + 1 ?>#backup-history" rel="next">Next</a><?php else: ?><span class="btn btn-secondary" aria-disabled="true">Next</span><?php endif; ?>
                    </div>
                </nav>
                <?php endif; ?>
            </section>

        </main>
    </div>
    <script src="<?= $escape(app_url('assets/js/backup_status.js')) ?>"></script>
    <script src="<?= $escape(app_url('assets/js/backup_review.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/backup_review.js')) ?>"></script>
    <script src="<?= $escape(app_url('assets/js/backup_key_recommendation.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/backup_key_recommendation.js')) ?>"></script>
    <script src="<?= $escape(app_url('assets/js/backup_restore.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/backup_restore.js')) ?>"></script>
    <script src="<?= $escape(app_url('assets/js/backup_recovery_tabs.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/backup_recovery_tabs.js')) ?>"></script>
</body>

</html>
