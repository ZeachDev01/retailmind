<?php if (role_capability_policy()->allows((string)current_role(), \App\Authorization\RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP)): ?>
<<?= !empty($recoveryTabbed) ? 'section' : 'details' ?> class="dashboard-section" id="recovery-key"<?= empty($recoveryTabbed) && isset($_SESSION['backup_key_message']) ? ' open' : '' ?>>
    <?php if (!empty($recoveryTabbed)): ?><h2>Recovery key</h2><?php else: ?><summary>Recovery key — save once</summary><?php endif; ?>
    <p>Save this key separately from backups. Anyone with both can read Store records.</p>
    <?php if (isset($_SESSION['backup_key_message'])): ?>
        <p role="status"><?= $escape($_SESSION['backup_key_message']) ?></p>
        <?php unset($_SESSION['backup_key_message']); ?>
    <?php endif; ?>
    <div class="recovery-key-actions">
        <form class="recovery-key-import" method="post" enctype="multipart/form-data" action="<?= $escape(app_url('components/backup/recovery_key.php')) ?>" aria-labelledby="key-import-title">
            <?= csrf_field() ?>
            <h3 id="key-import-title">Import key from another server</h3>
            <p class="recovery-tool-description">Restoring a backup from another server? Import its recovery key first.</p>
            <div class="form-group"><label for="recovery-key-file">Recovery key file (.key)</label><input id="recovery-key-file" name="recovery_key" type="file" accept=".key" required></div>
            <div class="form-group"><label for="key-password">Current login password</label><input id="key-password" name="key_password" type="password" autocomplete="current-password" required></div>
            <button class="btn btn-primary" name="key_action" value="import" type="submit"><i class="bi bi-upload" aria-hidden="true"></i> Import recovery key</button>
        </form>
        <form class="recovery-key-download" method="post" action="<?= $escape(app_url('components/backup/recovery_key.php')) ?>" aria-labelledby="key-download-title">
            <?= csrf_field() ?>
            <h3 id="key-download-title">Save this server’s key</h3>
            <p class="recovery-tool-description">Download once per installation and keep it somewhere safe, separate from your backups.</p>
            <div class="form-group"><label for="key-download-password">Current login password</label><input id="key-download-password" name="key_password" type="password" autocomplete="current-password" required></div>
            <button class="btn btn-secondary" name="key_action" value="download" type="submit"><i class="bi bi-download" aria-hidden="true"></i> Download recovery key</button>
        </form>
    </div>
</<?= !empty($recoveryTabbed) ? 'section' : 'details' ?>>
<?php endif; ?>
