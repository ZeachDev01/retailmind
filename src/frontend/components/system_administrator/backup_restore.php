<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';
require_role(['admin']);

$message = '';
$messageClass = '';
$storagePath = $GLOBALS['app']['storage_path'] ?? dirname(__DIR__, 3) . '/backend/storage';
$backupDirectory = $storagePath . '/backups';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'backup') {
        $filename = 'retailmind-backup-' . date('Ymd-His') . '.sql';
        $path = $backupDirectory . '/' . $filename;
        try {
            $result = create_database_backup($pdo, $path);
            record_backup_history($pdo, $filename, 'manual', (int)$result['size'], 'completed', (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Database backup', 'Backup & Restore', null, null, ['filename' => $filename, 'size' => $result['size']]);
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        } catch (Throwable $e) {
            record_backup_history($pdo, $filename, 'manual', 0, 'failed', (int)$_SESSION['user_id'], $e->getMessage());
            $message = 'Backup failed: ' . $e->getMessage();
            $messageClass = 'tag-warning';
        }
    }
    if ($action === 'restore') {
        $confirmed = isset($_POST['confirm_restore']);
        $file = $_FILES['backup_file'] ?? null;
        if (!$confirmed) {
            $message = 'Confirm that the current database may be replaced.';
            $messageClass = 'tag-warning';
        } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $message = 'Select a valid SQL backup file.';
            $messageClass = 'tag-warning';
        } elseif ((int)$file['size'] > 25 * 1024 * 1024 || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'sql') {
            $message = 'The restore file must be an SQL file no larger than 25 MB.';
            $messageClass = 'tag-warning';
        } else {
            try {
                // Create a safety backup immediately before restore.
                $safetyName = 'pre-restore-' . date('Ymd-His') . '.sql';
                $safety = create_database_backup($pdo, $backupDirectory . '/' . $safetyName);
                record_backup_history($pdo, $safetyName, 'manual', (int)$safety['size'], 'completed', (int)$_SESSION['user_id'], 'Automatic safety backup before restore.');
                $executed = restore_database_backup($pdo, $file['tmp_name']);
                record_backup_history($pdo, basename($file['name']), 'restore', (int)$file['size'], 'completed', (int)$_SESSION['user_id'], "Executed {$executed} SQL statements.");
                log_activity($pdo, (int)$_SESSION['user_id'], 'Database restore', 'Backup & Restore', null, null, ['filename' => $file['name'], 'statements' => $executed]);
                $message = "Restore completed. {$executed} SQL statements were executed. Sign in again if your session was replaced.";
                $messageClass = 'tag-success';
            } catch (Throwable $e) {
                record_backup_history($pdo, basename((string)$file['name']), 'restore', (int)$file['size'], 'failed', (int)$_SESSION['user_id'], $e->getMessage());
                $message = 'Restore failed: ' . $e->getMessage();
                $messageClass = 'tag-warning';
            }
        }
    }
}
ensure_backup_schema($pdo);
$history = $pdo->query('SELECT bh.*, u.full_name FROM backup_history bh LEFT JOIN users u ON u.user_id = bh.performed_by ORDER BY bh.created_at DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Backup & Restore</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"></head>
<body><div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content"><div class="topbar"><div><h1>Backup & Restore</h1><p class="page-subtitle">Download portable SQL backups and restore only validated RetailMind backup files.</p></div></div>
<?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="card-grid"><section class="dashboard-section"><h3>Create backup</h3><p class="section-description">Exports the table structure and all current records. A history copy is also saved under <code>backend/storage/backups</code>.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button class="btn">Create and download backup</button></form></section>
<section class="dashboard-section"><h3>Restore backup</h3><p class="section-description">A safety backup is created first. Restoring replaces current database tables and records.</p><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><div class="form-group"><label>RetailMind SQL backup</label><input type="file" name="backup_file" accept=".sql" required></div><label class="u-confirm-label"><input type="checkbox" name="confirm_restore" value="1" required> I understand that the current database may be replaced.</label><button class="btn btn-danger">Validate and restore</button></form></section></div>
<div class="dashboard-section"><h3>Backup history</h3><div class="table-wrap"><table><tr><th>Date</th><th>File</th><th>Type</th><th>Size</th><th>Status</th><th>User</th><th>Notes</th></tr><?php foreach ($history as $row): ?><tr><td><?= htmlspecialchars($row['created_at']) ?></td><td><?= htmlspecialchars($row['filename']) ?></td><td><?= htmlspecialchars($row['backup_type']) ?></td><td><?= number_format((int)$row['file_size']/1024,1) ?> KB</td><td><?= htmlspecialchars($row['status']) ?></td><td><?= htmlspecialchars($row['full_name'] ?? 'Scheduled task') ?></td><td><?= htmlspecialchars((string)$row['notes']) ?></td></tr><?php endforeach; ?><?php if(!$history): ?><tr><td colspan="7">No backup history.</td></tr><?php endif; ?></table></div></div>
</main></div></body></html>
