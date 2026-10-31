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
            (string)($_POST['safety_password'] ?? ''));
    } catch (Throwable $exception) {
        $safetyError = \App\Support\OperatorAlert::message($exception, 'The safety copy could not be verified. Nothing was replaced. Check storage and required images, then retry.');
    }
}
try { $safetyDownload = $safety->resolveDownload($safetyOperation, $actorUserId, $actorRole); }
catch (DomainException) { /* No verified safety artifact has been prepared for this session. */ }
catch (Throwable $exception) { $safetyError = \App\Support\OperatorAlert::message($exception, 'The safety checkpoint cannot be read. Keep its artifact and ask your Super Administrator to check private storage.'); }
?>
<section class="dashboard-section" aria-labelledby="safety-copy-title" data-safety-copy>
    <h2 id="safety-copy-title">Safety copy</h2>
    <p class="recovery-tool-description">Keep a verified copy of this server’s current Store records and images before a restore.</p>
    <?php if ($safetyError !== ''): ?><p class="alert tag-warning" role="alert"><?= $escape($safetyError) ?></p><?php endif; ?>
    <?php if ($safetyDownload): ?>
        <div class="safety-copy-ready">
            <div role="status">
                <h3><i class="bi bi-check-circle" aria-hidden="true"></i> Safety copy verified</h3>
                <p>Retained for this operation. Download a copy to keep off-server.</p>
            </div>
            <a class="btn btn-primary" download data-safety-download href="<?= $escape(app_url('components/backup/backup_download.php?safety=' . rawurlencode($safetyOperation))) ?>"><i class="bi bi-download" aria-hidden="true"></i> Download safety copy</a>
        </div>
    <?php else: ?>
        <form class="safety-copy-create" method="post" data-password-authorization>
            <?= csrf_field() ?><input type="hidden" name="action" value="safety"><input type="hidden" name="safety_operation" value="<?= $escape($safetyOperation) ?>">
            <h3>Create a safety copy</h3>
            <div class="form-group"><label for="safety-password">Current login password</label><input id="safety-password" type="password" name="safety_password" autocomplete="current-password" required></div>
            <button class="btn btn-primary" type="submit" data-safety-create>Create and verify safety copy</button>
        </form>
    <?php endif; ?>
</section>
