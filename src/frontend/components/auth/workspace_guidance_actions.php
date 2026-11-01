<form method="post" action="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>" class="workspace-guidance-actions" data-rm-backup-bypass="true">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '') ?>">
    <button class="btn btn-primary" name="guidance_status" value="completed">Finish overview</button>
    <button class="btn btn-secondary" name="guidance_status" value="skipped">Skip guidance</button>
    <button class="btn btn-secondary" name="guidance_status" value="deferred">Not now</button>
</form>
