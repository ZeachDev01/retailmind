<?php
$restoreLimit = (new \App\Backup\DatabaseRestoreService($pdo, role_capability_policy(), $service))->supportedSizeLine();
$restoreError = (string)($_SESSION['restore_error'] ?? '');
unset($_SESSION['restore_error']);
$reviewFirst = $restoreReviewFirst ?? true;
?>
<section class="dashboard-section" id="restore-package" aria-labelledby="restore-package-title">
    <h2 id="restore-package-title">Restore encrypted backup</h2>
    <?php if ($restoreError !== ''): ?><p class="alert tag-warning" role="alert"><?= $escape($restoreError) ?></p><?php endif; ?>
    <p id="restore-package-warning"><strong>This replaces Store records with the selected snapshot. Newer sales and other changes disappear from the active database.</strong> Stop source sales and background workers first. The Store stays closed until recovery checks are confirmed. A fresh encrypted destination safety copy is verified before replacement.</p>
    <p><?= $reviewFirst ? 'Review the snapshot and account choices, then confirm restoration with your own login password.' : 'Review complete. Confirm restoration below.' ?> Destination privileged access and security records are preserved.</p>
    <form method="post" enctype="multipart/form-data" action="<?= $escape(app_url($reviewFirst ? 'components/system_administrator/identity_reconciliation.php' : 'components/backup/restore.php')) ?>" aria-describedby="restore-package-warning"<?= $reviewFirst ? ' data-identity-form' : '' ?>>
        <?= csrf_field() ?>
        <div class="form-group"><label for="incoming-package">Encrypted Store package</label><p><?= $escape($restoreLimit) ?></p><input id="incoming-package" type="file" name="backup_file" accept=".rmpkg" required></div>
        <div class="form-group"><label for="incoming-password">Current login password</label><input id="incoming-password" type="password" name="restore_password" autocomplete="current-password" required></div>
        <details><summary>Older backup options</summary><div class="form-group"><label for="incoming-passphrase">Original passphrase (older backups only)</label><input id="incoming-passphrase" type="password" name="backup_passphrase" autocomplete="off" maxlength="1024"></div></details>
        <?php if (!$reviewFirst): ?>
        <p><label><input type="checkbox" name="confirm_replacement" value="1" required> I understand the snapshot replaces newer records and Store operations remain paused until verification.</label></p>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit" data-start-restore><?= $reviewFirst ? 'Review backup' : 'Restore and verify' ?></button>
    </form>
</section>
