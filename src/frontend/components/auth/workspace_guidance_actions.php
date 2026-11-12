<form method="post" action="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>" class="workspace-guidance-actions<?= current_role() === 'inventory_manager' ? ' workspace-invitation-actions' : '' ?>" data-rm-backup-bypass="true">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= htmlspecialchars(basename($_SERVER['SCRIPT_NAME'] ?? '') === 'help.php'
        ? app_url(\App\Authorization\RoleWorkspaceRouter::pathFor(current_role()))
        : (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '')) ?>">
    <?php if (current_role() === 'inventory_manager'): ?>
    <a href="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>" class="btn btn-primary">View guidance <span class="wi-arrow" aria-hidden="true">→</span></a>
    <?php else: ?>
    <button type="submit" class="btn btn-primary" name="guidance_status" value="completed">Finish overview</button>
    <button type="submit" class="btn btn-secondary" name="guidance_status" value="skipped">Skip guidance</button>
    <?php endif; ?>
    <button type="submit" class="btn btn-secondary" name="guidance_status" value="deferred">Not now</button>
</form>
