<?php
// Shared safety preparation: both administrator screens use the same capture boundary.
$safety = new \App\Backup\SafetyCopyService($pdo, role_capability_policy());
$safetyOperation = (string)($_SESSION['safety_operation'] ??= bin2hex(random_bytes(16)));
$safetyError = '';
$safetyDownload = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'safety') {
    try {
        csrf_verify();
        if (!hash_equals($safetyOperation, (string)($_POST['safety_operation'] ?? ''))) {
            throw new DomainException('This safety operation changed. Reload the page and try again.');
        }
        $safety->create($safetyOperation, $actorUserId, $actorRole,
            (string)($_POST['safety_password'] ?? ''), '');
    } catch (Throwable $exception) {
        $safetyError = \App\Support\OperatorAlert::message($exception, 'The safety copy could not be verified. Nothing was replaced. Check storage and required images, then retry.');
    }
}
try { $safetyDownload = $safety->resolveDownload($safetyOperation, $actorUserId, $actorRole); }
catch (DomainException) { /* No verified safety artifact has been prepared for this session. */ }
catch (Throwable $exception) { $safetyError = \App\Support\OperatorAlert::message($exception, 'The safety checkpoint cannot be read. Keep its artifact and ask your Super Administrator to check private storage.'); }
?>
<section class="dashboard-section" aria-labelledby="safety-copy-title" data-safety-copy>
    <h2 id="safety-copy-title">Destination safety copy</h2>
    <p class="section-description" id="safety-copy-help">Keep this complete encrypted database and image fallback. Starting a restore captures a fresh safety copy automatically.</p>
    <?php if ($safetyError !== ''): ?><p class="alert tag-warning" role="alert"><?= $escape($safetyError) ?></p><?php endif; ?>
    <?php if ($safetyDownload): ?>
        <p role="status">Safety copy verified. The original is retained for this operation; downloading does not remove it.</p>
        <p><a class="btn btn-primary" download data-safety-download href="<?= $escape(app_url('components/backup/backup_download.php?safety=' . rawurlencode($safetyOperation))) ?>">Download encrypted safety copy</a></p>
    <?php else: ?>
        <form method="post" aria-describedby="safety-copy-help safety-custody">
            <?= csrf_field() ?><input type="hidden" name="action" value="safety"><input type="hidden" name="safety_operation" value="<?= $escape($safetyOperation) ?>">
            <div class="form-group"><label for="safety-password">Your current destination login password</label><input id="safety-password" type="password" name="safety_password" autocomplete="current-password" required></div>
            <button class="btn btn-primary" type="submit" data-safety-create>Capture and verify safety copy</button>
        </form>
    <?php endif; ?>
    <p id="safety-custody">The safety copy is encrypted automatically. Save the installation recovery key separately from your backups.</p>
</section>
