<?php
// Administrator Database Backup (ticket #69).
//
// Both administrator roles capture encrypted Store packages. In-app history
// stays limited; this screen does not authorize replacement.
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP);

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\BackupKeyRecommendation;
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
$scheduledSchedule = \App\Backup\ScheduledBackupSettings::summary($pdo);

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
                $message = 'Backup created. Download your copy below.';
                $messageClass = 'tag-success';
                $download = [
                    'filename' => (string)$result['filename'],
                    'url' => app_url('components/backup/backup_download.php?token=' . rawurlencode((string)$result['token'])),
                    'size' => (int)$result['size'],
                    'key_fingerprint' => (string)$result['key_fingerprint'],
                ];
            }
        } catch (Throwable $e) {
            record_backup_history($pdo, DatabaseBackupService::downloadFilename(), 'manual', 0, 'failed', $actorUserId, $e->getMessage());
            $message = \App\Support\OperatorAlert::message($e, $backupFailureLine);
            $messageClass = 'tag-warning';
        }
    }
}

$storageMessage = '';
try {
    ensure_backup_storage($pdo);
    $history = $service->history($actorRole);
} catch (Throwable $exception) {
    error_log('Backup history unavailable: ' . $exception->getMessage());
    $history = [];
    $storageMessage = 'Backup history needs database updates before it can be shown. Ask your Super Administrator to Apply Database Updates.';
}
$historyPerPage = 10;
$historyTotal = count($history);
$historyPages = max(1, (int)ceil($historyTotal / $historyPerPage));
$historyPage = min($historyPages, max(1, (int)($_GET['history_page'] ?? 1)));
$historyOffset = ($historyPage - 1) * $historyPerPage;
$historyRows = array_slice($history, $historyOffset, $historyPerPage);
$restoreCapability = role_capability_policy()->allows($actorRole, RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
$restoreService = new \App\Backup\DatabaseRestoreService($pdo, role_capability_policy(), $service);
$restoreGuidance = $restoreService->workflowGuidance();
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$backupRecommendation = (new BackupKeyRecommendation($pdo))->recommendation((int)$_SESSION['user_id'], (string)current_role());

$completedHistory = array_filter($history, static fn(array $row): bool => ($row['status'] ?? '') === 'completed');
$latestBackup = !empty($completedHistory) ? reset($completedHistory) : null;
$totalSnapshots = count($completedHistory);
$storePaused = \App\Backup\RecoveryStore::isPaused();
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
                <div><h1>Database Backup</h1></div>
                <a class="backup-history-link" href="#backup-history"><i class="bi bi-clock-history" aria-hidden="true"></i> View history</a>
            </header>

            <?php if ($message): ?><div class="alert <?= $escape($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= $escape($message) ?></div><?php endif; ?>
            <?php if ($storageMessage): ?><p class="alert tag-warning" role="alert"><?= $escape($storageMessage) ?></p><?php endif; ?>
            <?php if (\App\Backup\RecoveryStore::isPaused()): ?><p class="alert tag-warning" role="status">Store operations are paused for recovery. <a href="#backup-recovery">Continue recovery</a>.</p><?php endif; ?>

            <?php require __DIR__ . '/../backup/recovery_key_recommendation.php'; ?>

            <dl class="backup-summary" aria-label="Backup overview">
                <div>
                    <dt><i class="bi bi-clock-history" aria-hidden="true"></i> Latest backup</dt>
                    <dd><?= $latestBackup ? $escape(format_display_datetime((string)($latestBackup['snapshot_at'] ?? $latestBackup['created_at']))) : 'None yet' ?></dd>
                </div>
                <div>
                    <dt><i class="bi bi-collection" aria-hidden="true"></i> Backups created</dt>
                    <dd><?= $totalSnapshots ?></dd>
                </div>
                <div>
                    <dt>Store status</dt>
                    <dd><span class="status-indicator <?= $storePaused ? 'is-paused' : 'is-operational' ?>" aria-hidden="true"></span><?= $storePaused ? 'Paused for recovery' : 'Operational' ?></dd>
                </div>
                <div>
                    <dt><i class="bi bi-calendar-week" aria-hidden="true"></i> Next scheduled run</dt>
                    <dd><?= $scheduledSchedule['next_run_at'] !== '' ? $escape(format_display_datetime((string)$scheduledSchedule['next_run_at'])) : '—' ?></dd>
                </div>
                <div>
                    <dt><i class="bi bi-activity" aria-hidden="true"></i> Last outcome</dt>
                    <dd><span class="tag <?= $escape(\App\Backup\ScheduledBackupSettings::outcomeClass($scheduledSchedule['last_outcome'])) ?>"><?= $escape(\App\Backup\ScheduledBackupSettings::outcomeLabel($scheduledSchedule['last_outcome'])) ?></span></dd>
                </div>
            </dl>

            <?php if ($download): ?>
                <section class="dashboard-section backup-ready" aria-labelledby="backup-ready-title">
                    <div class="backup-ready-main">
                        <h2 id="backup-ready-title"><i class="bi bi-check2-circle" aria-hidden="true"></i> Backup ready</h2>
                        <p class="backup-filename" title="<?= $escape($download['filename']) ?>">
                            <i class="bi bi-file-earmark-lock2" aria-hidden="true"></i>
                            <span><?= $escape($download['filename']) ?></span>
                            <span class="tag tag-success"><?= number_format($download['size'] / 1024, 1) ?> KB</span>
                        </p>
                    </div>
                    <a class="btn btn-primary" href="<?= $escape($download['url']) ?>" download data-backup-download><i class="bi bi-download" aria-hidden="true"></i> Download backup</a>
                </section>
            <?php endif; ?>

            <section class="dashboard-section backup-create" aria-labelledby="backup-create-title">
                <div class="backup-create-main">
                    <div class="backup-create-copy">
                        <h2 id="backup-create-title">Create backup <span class="backup-encrypted"><i class="bi bi-lock" aria-hidden="true"></i> Encrypted</span></h2>
                        <p>Store records and images, sealed in one package.</p>
                    </div>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup">
                        <button type="submit" class="btn btn-primary" data-backup-create><i class="bi bi-plus-lg" aria-hidden="true"></i> Create backup</button>
                    </form>
                </div>
                <details class="backup-tips">
                    <summary><i class="bi bi-info-circle" aria-hidden="true"></i> What to keep</summary>
                    <ul class="backup-guidance-list">
                        <li>Creating a backup does not confirm the file was saved on your device.</li>
                        <li>No backup passphrase is needed.</li>
                        <li>Download the recovery key from the Recovery key tab. Keep it separate from backups.</li>
                    </ul>
                </details>
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
                    <button type="button" id="restore-tab" role="tab" aria-controls="restore-panel" aria-selected="true"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore</button>
                    <button type="button" id="safety-tab" role="tab" aria-controls="safety-panel" aria-selected="false" tabindex="-1"><i class="bi bi-shield-check" aria-hidden="true"></i> Safety copy</button>
                    <button type="button" id="key-tab" role="tab" aria-controls="key-panel" aria-selected="false" tabindex="-1"><i class="bi bi-key" aria-hidden="true"></i> Recovery key</button>
                </div>
                <div id="restore-panel" role="tabpanel" aria-labelledby="restore-tab" tabindex="0">
                    <?php require __DIR__ . '/../backup/restore_form.php'; ?>
                </div>
                <div id="safety-panel" role="tabpanel" aria-labelledby="safety-tab" tabindex="0">
                    <?php require __DIR__ . '/../backup/safety_copy.php'; ?>
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
                    <table data-no-smart-table data-no-ghost-rows aria-label="Backup history">
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
                                <td class="backup-file-cell"><i class="bi bi-file-earmark-lock" aria-hidden="true"></i> <span class="backup-filename" title="<?= $escape($row['format']) ?>"><?= $escape($row['filename']) ?></span><?php if (trim((string)$row['notes']) !== ''): ?><details class="backup-row-notes"><summary>View note</summary><p><?= $escape($row['notes']) ?></p></details><?php endif; ?></td>
                                <?php $artifactState = (string)($row['artifact_state'] ?? 'not_retained'); ?>
                                <td><span class="tag <?= $escape(\App\Backup\DatabaseBackupService::artifactClass($artifactState)) ?>"><?= $escape(\App\Backup\DatabaseBackupService::artifactLabel($artifactState)) ?></span></td>
                                <td class="backup-size"><?= number_format($row['file_size'] / 1024, 1) ?> KB</td>
                                <td><?php $rowStatus = (string)$row['status']; ?><span class="tag <?= \App\Backup\ScheduledBackupSettings::outcomeClass($rowStatus) ?>"><i class="bi <?= $rowStatus === 'completed' ? 'bi-check-circle' : ($rowStatus === 'skipped' ? 'bi-arrow-repeat' : 'bi-exclamation-circle') ?>" aria-hidden="true"></i> <?= $escape(\App\Backup\ScheduledBackupSettings::outcomeLabel($rowStatus)) ?></span></td>
                                <td><?= $escape($row['requested_by']) ?></td>
                            </tr><?php endforeach; ?><?php if (!$history): ?><tr>
                                <td colspan="7" class="backup-empty"><i class="bi bi-archive" aria-hidden="true"></i><strong>No backups yet</strong><span>Create a backup above to record your first copy.</span></td>
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
    <script src="<?= $escape(app_url('assets/js/backup_recovery_tabs.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/backup_recovery_tabs.js')) ?>"></script>
</body>

</html>
