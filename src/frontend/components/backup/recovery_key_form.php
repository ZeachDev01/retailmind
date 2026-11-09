<?php if (role_capability_policy()->allows((string)current_role(), \App\Authorization\RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP)): ?>
<?php $keyringKeys = $keyringKeys ?? \App\Backup\BackupKeyStore::list(); ?>
<<?= !empty($recoveryTabbed) ? 'section' : 'details' ?> class="dashboard-section" id="recovery-key"<?= empty($recoveryTabbed) && isset($_SESSION['backup_key_message']) ? ' open' : '' ?>>
    <?php if (!empty($recoveryTabbed)): ?><h2>Recovery key</h2><?php else: ?><summary>Recovery key</summary><?php endif; ?>
    <?php if (isset($_SESSION['backup_key_message'])): ?>
        <p role="status"><?= $escape($_SESSION['backup_key_message']) ?></p>
        <?php unset($_SESSION['backup_key_message']); ?>
    <?php endif; ?>
    <div class="recovery-key-actions">
        <form class="recovery-key-import" method="post" enctype="multipart/form-data" action="<?= $escape(app_url('components/backup/recovery_key.php')) ?>" aria-labelledby="key-import-title" data-password-authorization>
            <?= csrf_field() ?>
            <h3 id="key-import-title">Import key from another server</h3>
            <div class="form-group"><label for="recovery-key-file">Recovery key file (.key)</label><input id="recovery-key-file" name="recovery_key" type="file" accept=".key" required></div>
            <div class="form-group"><label for="key-password">Current login password</label><input id="key-password" name="key_password" type="password" autocomplete="current-password" required></div>
            <button class="btn btn-primary" name="key_action" value="import" type="submit"><i class="bi bi-upload" aria-hidden="true"></i> Import recovery key</button>
        </form>
        <form class="recovery-key-download" method="post" action="<?= $escape(app_url('components/backup/recovery_key.php')) ?>" aria-labelledby="key-download-title" data-password-authorization>
            <?= csrf_field() ?>
            <h3 id="key-download-title">Save this server’s key</h3>
            <div class="form-group"><label for="key-download-password">Current login password</label><input id="key-download-password" name="key_password" type="password" autocomplete="current-password" required></div>
            <button class="btn btn-secondary" name="key_action" value="download" type="submit"><i class="bi bi-download" aria-hidden="true"></i> Download recovery key</button>
        </form>
    </div>
    <div class="backup-keyring">
        <h3 id="backup-keyring-title">Backup Keyring</h3>
        <p class="backup-hint">Every key this installation holds. Archived keys cannot make new backups, but they still open the packages encrypted with them.</p>
        <div class="table-wrap" tabindex="0" role="region" aria-labelledby="backup-keyring-title">
            <table class="backup-keyring-table" data-no-smart-table data-no-ghost-rows aria-labelledby="backup-keyring-title">
                <caption class="sr-only">Recovery keys held by this installation</caption>
                <thead><tr>
                    <th scope="col">Fingerprint</th>
                    <th scope="col">Kind</th>
                    <th scope="col">Added</th>
                    <th scope="col">Archived</th>
                    <th scope="col">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($keyringKeys as $key): ?>
                    <?php
                    $keyFingerprint = (string)$key['fingerprint'];
                    $keyKind = (string)($key['kind'] ?? 'imported');
                    $keyKindLabel = ['primary' => 'Primary', 'imported' => 'Imported', 'archived' => 'Archived'][$keyKind] ?? 'Imported';
                    ?>
                    <tr>
                        <td><code title="<?= $escape($key['fingerprint']) ?>"><?= $escape(substr($keyFingerprint, 0, 12)) ?>…</code></td>
                        <td><span class="tag <?= $keyKind === 'primary' ? 'tag-success' : ($keyKind === 'archived' ? 'tag-warning' : 'tag-soft') ?>"><?= $keyKindLabel ?></span></td>
                        <td><?= $escape(format_display_datetime((string)($key['created_at'] ?? ''))) ?></td>
                        <td><?= empty($key['archived_at']) ? '—' : $escape(format_display_datetime((string)$key['archived_at'])) ?></td>
                        <td class="backup-keyring-actions">
                            <?php if ($keyKind !== 'primary'): ?>
                            <form method="post" action="<?= $escape(app_url('components/backup/recovery_key.php')) ?>" aria-label="Use this key for new backups" data-password-authorization>
                                <?= csrf_field() ?>
                                <input type="hidden" name="key_action" value="promote">
                                <input type="hidden" name="key_fingerprint" value="<?= $escape($key['fingerprint']) ?>">
                                <div class="form-group"><label for="key-promote-password-<?= $escape($key['fingerprint']) ?>">Current login password</label><input id="key-promote-password-<?= $escape($key['fingerprint']) ?>" name="key_password" type="password" autocomplete="current-password" required></div>
                                <button class="btn btn-secondary" type="submit"><i class="bi bi-arrow-up-circle" aria-hidden="true"></i> Use for new backups</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" action="<?= $escape(app_url('components/backup/recovery_key.php')) ?>" aria-label="Export this key" data-password-authorization>
                                <?= csrf_field() ?>
                                <input type="hidden" name="key_action" value="export">
                                <input type="hidden" name="key_fingerprint" value="<?= $escape($key['fingerprint']) ?>">
                                <div class="form-group"><label for="key-export-password-<?= $escape($key['fingerprint']) ?>">Current login password</label><input id="key-export-password-<?= $escape($key['fingerprint']) ?>" name="key_password" type="password" autocomplete="current-password" required></div>
                                <button class="btn btn-secondary" type="submit"><i class="bi bi-download" aria-hidden="true"></i> Export key</button>
                            </form>
                            <?php if ($keyKind !== 'primary'): ?>
                            <form method="post" action="<?= $escape(app_url('components/backup/recovery_key.php')) ?>" aria-label="Delete this key" data-password-authorization>
                                <?= csrf_field() ?>
                                <input type="hidden" name="key_action" value="delete">
                                <input type="hidden" name="key_fingerprint" value="<?= $escape($key['fingerprint']) ?>">
                                <div class="form-group"><label><input type="checkbox" name="confirm_delete" value="1" required> I understand backups encrypted with this key cannot be opened afterwards.</label></div>
                                <div class="form-group"><label for="key-delete-password-<?= $escape($key['fingerprint']) ?>">Current login password</label><input id="key-delete-password-<?= $escape($key['fingerprint']) ?>" name="key_password" type="password" autocomplete="current-password" required></div>
                                <button class="btn btn-danger" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i> Delete key</button>
                            </form>
                            <?php else: ?>
                            <p><button class="btn btn-secondary" type="button" disabled aria-describedby="primary-key-locked-<?= $escape($key['fingerprint']) ?>"><i class="bi bi-lock" aria-hidden="true"></i> Delete key</button></p>
                            <p class="backup-hint" id="primary-key-locked-<?= $escape($key['fingerprint']) ?>">Promote another key before deleting this one.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($keyringKeys === []): ?>
                    <tr><td colspan="5" class="backup-empty"><i class="bi bi-key" aria-hidden="true"></i><strong>No recovery keys yet</strong><span>The first backup creates the Primary Recovery Key.</span></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</<?= !empty($recoveryTabbed) ? 'section' : 'details' ?>>
<?php endif; ?>
