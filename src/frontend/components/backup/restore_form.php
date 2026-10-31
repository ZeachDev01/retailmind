<?php
$restoreLimit = (new \App\Backup\DatabaseRestoreService($pdo, role_capability_policy(), $service))->supportedSizeLine();
$restoreError = (string)($_SESSION['restore_error'] ?? '');
unset($_SESSION['restore_error']);
$reviewFirst = $restoreReviewFirst ?? true;
?>
<section class="dashboard-section" id="restore-package" aria-labelledby="restore-package-title">
    <h2 id="restore-package-title">Restore backup</h2>
    <?php if ($restoreError !== ''): ?><p class="alert tag-warning" role="alert"><?= $escape($restoreError) ?></p><?php endif; ?>
    <div class="restore-introduction">
    <p id="restore-package-warning"><strong>Restore replaces newer records, including sales.</strong> Stop source sales and background workers. The Store stays closed until recovery is verified.</p>
    </div>
    <form method="post" enctype="multipart/form-data" action="<?= $escape(app_url($reviewFirst ? 'components/system_administrator/identity_reconciliation.php' : 'components/backup/restore.php')) ?>" aria-describedby="restore-package-warning"<?= $reviewFirst ? ' data-identity-form' : '' ?>>
        <?= csrf_field() ?>
        <div class="form-group"><label for="incoming-package">Backup file (.rmpkg)</label><input id="incoming-package" type="file" name="backup_file" accept=".rmpkg" aria-describedby="incoming-package-help" required><p id="incoming-package-help" class="backup-hint"><?= $escape($restoreLimit) ?></p></div>
        <div class="form-group"><label for="incoming-password">Current login password</label><input id="incoming-password" type="password" name="restore_password" autocomplete="current-password" required></div>
        <?php if (!$reviewFirst): ?>
        <p><label><input type="checkbox" name="confirm_replacement" value="1" required> I understand the snapshot replaces newer records and Store operations remain paused until verification.</label></p>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit" data-start-restore><?= $reviewFirst ? 'Review backup' : 'Restore and verify' ?></button>
    </form>
</section>
