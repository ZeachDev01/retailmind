<?php if (role_capability_policy()->allows((string)current_role(), \App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE)): ?>
<details class="dashboard-section" id="recovery-key"<?= isset($_SESSION['backup_key_message']) ? ' open' : '' ?>>
    <summary>Recovery key — save once</summary>
    <p>Backups are encrypted automatically. Save this key separately from your backups. You only need to import it after server loss or when moving to another server. Anyone with both the key and a backup can read its contents.</p>
    <?php if (isset($_SESSION['backup_key_message'])): ?>
        <p role="status"><?= $escape($_SESSION['backup_key_message']) ?></p>
        <?php unset($_SESSION['backup_key_message']); ?>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= $escape(app_url('components/backup/recovery_key.php')) ?>">
        <?= csrf_field() ?>
        <div class="form-group"><label for="key-password">Current login password</label><input id="key-password" name="key_password" type="password" autocomplete="current-password" required></div>
        <button class="btn btn-secondary" name="key_action" value="download" type="submit">Download recovery key</button>
        <div class="form-group"><label for="recovery-key-file">Recovery-key file from another server</label><input id="recovery-key-file" name="recovery_key" type="file" accept=".key"></div>
        <button class="btn btn-secondary" name="key_action" value="import" type="submit">Import recovery key</button>
    </form>
</details>
<?php endif; ?>
