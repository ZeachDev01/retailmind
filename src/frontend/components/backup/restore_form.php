<?php
$restoreLimit = (new \App\Backup\DatabaseRestoreService($pdo, role_capability_policy(), $service))->supportedSizeLine();
$restoreError = (string)($_SESSION['restore_error'] ?? '');
unset($_SESSION['restore_error']);
?>
<section class="dashboard-section" id="restore-package" aria-labelledby="restore-package-title">
    <h2 id="restore-package-title">Restore encrypted backup</h2>
    <?php if ($restoreError !== ''): ?><p class="alert tag-warning" role="alert"><?= $escape($restoreError) ?></p><?php endif; ?>
    <p id="restore-package-warning"><strong>This replaces Store records with the selected snapshot. Newer sales and other changes disappear from the active database.</strong> Stop source sales and background workers first. The Store stays closed until recovery checks are confirmed. A fresh encrypted destination safety copy is verified before replacement.</p>
    <p>A Super Administrator must first approve Identity Reconciliation for this exact package. Destination privileged access, security settings, and security evidence are preserved.</p>
    <form method="post" enctype="multipart/form-data" action="<?= $escape(app_url('components/backup/restore.php')) ?>" aria-describedby="restore-package-warning">
        <?= csrf_field() ?>
        <div class="form-group"><label for="incoming-package">Approved encrypted Store package</label><p><?= $escape($restoreLimit) ?></p><input id="incoming-package" type="file" name="backup_file" accept=".rmpkg" required></div>
        <div class="form-group"><label for="incoming-password">Destination login password</label><input id="incoming-password" type="password" name="restore_password" autocomplete="current-password" required></div>
        <div class="form-group"><label for="incoming-passphrase">Original package passphrase</label><input id="incoming-passphrase" type="password" name="backup_passphrase" autocomplete="off" maxlength="1024" required></div>
        <div class="form-group"><label for="replacement-safety-passphrase">New destination safety passphrase</label><input id="replacement-safety-passphrase" type="password" name="safety_passphrase" autocomplete="new-password" minlength="16" maxlength="1024" required><p>Keep a recovery copy outside both servers. Losing every copy makes the safety backup unusable.</p></div>
        <p><label><input type="checkbox" name="confirm_replacement" value="1" required> I understand the snapshot replaces newer records and Store operations remain paused until verification.</label></p>
        <button class="btn btn-primary" type="submit" data-start-restore>Restore and verify</button>
    </form>
</section>
