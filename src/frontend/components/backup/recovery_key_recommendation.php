<?php
// Manual downloads always offer their exact key; unattended backups use the account recommendation.
$backupRecommendation = $backupRecommendation ?? null;
$recoveryKeyHref = $recoveryKeyHref ?? '#recovery-key';
$recommendedFingerprint = (string)($download['key_fingerprint'] ?? $backupRecommendation['fingerprint'] ?? '');
if (preg_match('/^[a-f0-9]{64}$/D', $recommendedFingerprint) === 1
    && \App\Backup\RecoveryStore::isAvailable() && !\App\Backup\RecoveryStore::isPaused()):
    $recommendedShort = substr($recommendedFingerprint, 0, 8);
?>
<dialog class="backup-authorization backup-key-recommendation-dialog" data-backup-key-dialog data-backup-key-recommendation data-fingerprint="<?= $escape($recommendedFingerprint) ?>" data-after-download="<?= isset($download['key_fingerprint']) ? '1' : '0' ?>" aria-labelledby="backup-key-recommendation-dialog-title" aria-describedby="backup-key-recommendation-description">
    <h2 id="backup-key-recommendation-dialog-title">Save your recovery key</h2>
    <p id="backup-key-recommendation-description">Key <code title="<?= $escape($recommendedFingerprint) ?>"><?= $escape($recommendedShort) ?>…</code> opens this backup. Keep a copy separately from your backup file.</p>
    <form method="post" action="<?= $escape(app_url('components/backup/recovery_key.php')) ?>" data-backup-key-export>
        <?= csrf_field() ?>
        <input type="hidden" name="key_action" value="export">
        <input type="hidden" name="key_fingerprint" value="<?= $escape($recommendedFingerprint) ?>">
        <div class="form-group"><label for="key-recommendation-password">Current login password</label><input id="key-recommendation-password" name="key_password" type="password" autocomplete="current-password" required></div>
        <p role="alert" data-backup-key-error hidden></p>
        <div class="backup-authorization-actions">
            <button class="btn btn-secondary" type="button" data-backup-key-dialog-close>I already have a copy</button>
            <button class="btn btn-primary" type="submit"><i class="bi bi-download" aria-hidden="true"></i> Download recovery key</button>
        </div>
    </form>
    <a class="backup-history-link" href="<?= $escape($recoveryKeyHref) ?>" data-backup-key-manage>Open the Backup Keyring</a>
</dialog>
<?php endif; ?>
