<?php
// Persistent, per-account recommendation to save the key that encrypted the
// newest completed backup. The banner follows server acknowledgement state;
// the dialog is shown at most once per session by backup_key_recommendation.js.
$backupRecommendation = $backupRecommendation ?? null;
$recommendedFingerprint = is_array($backupRecommendation) ? (string)($backupRecommendation['fingerprint'] ?? '') : '';
if ($recommendedFingerprint !== '' && preg_match('/^[a-f0-9]{64}$/D', $recommendedFingerprint) === 1
    && \App\Backup\RecoveryStore::isAvailable() && !\App\Backup\RecoveryStore::isPaused()):
    $recommendedShort = substr($recommendedFingerprint, 0, 8);
    $recommendedSnapshot = $backupRecommendation['snapshot_at'] ?? null;
?>
<section class="dashboard-section backup-key-recommendation" data-backup-key-recommendation data-fingerprint="<?= $escape($recommendedFingerprint) ?>" aria-labelledby="backup-key-recommendation-title">
    <div class="backup-key-recommendation-copy">
        <h2 id="backup-key-recommendation-title"><i class="bi bi-key" aria-hidden="true"></i> Save your newest backup key</h2>
        <p>Your newest backup uses recovery key <code title="<?= $escape($recommendedFingerprint) ?>"><?= $escape($recommendedShort) ?>…</code>
            <?php if ($recommendedSnapshot !== null && $recommendedSnapshot !== ''): ?>from <?= $escape(format_display_datetime((string)$recommendedSnapshot)) ?><?php endif; ?>.
            Download it and keep it somewhere separate from this computer. Without it this backup cannot be opened.</p>
    </div>
    <form class="backup-key-recommendation-download" method="post" action="<?= $escape(app_url('components/backup/recovery_key.php')) ?>" aria-labelledby="backup-key-recommendation-download-title" data-password-authorization data-backup-key-download>
        <?= csrf_field() ?>
        <input type="hidden" name="key_action" value="export">
        <input type="hidden" name="key_fingerprint" value="<?= $escape($recommendedFingerprint) ?>">
        <h3 id="backup-key-recommendation-download-title">Download the recommended key</h3>
        <div class="form-group"><label for="key-recommendation-password">Current login password</label><input id="key-recommendation-password" name="key_password" type="password" autocomplete="current-password" required></div>
        <div class="backup-key-recommendation-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-download" aria-hidden="true"></i> Download recovery key</button>
            <a class="backup-history-link" href="#recovery-key">Open the Backup Keyring</a>
        </div>
    </form>
</section>
<dialog class="backup-authorization backup-key-recommendation-dialog" data-backup-key-dialog aria-labelledby="backup-key-recommendation-dialog-title">
    <h2 id="backup-key-recommendation-dialog-title">Save your recovery key</h2>
    <p>Your newest backup uses recovery key <code title="<?= $escape($recommendedFingerprint) ?>"><?= $escape($recommendedShort) ?>…</code>.
        Download it from the banner on this page and keep it somewhere separate from this computer.</p>
    <div class="backup-authorization-actions">
        <a class="btn btn-secondary" href="#recovery-key">Open the Backup Keyring</a>
        <button class="btn btn-primary" type="button" data-backup-key-dialog-close>Got it</button>
    </div>
</dialog>
<?php endif; ?>
