<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
$review = null; $approval = null; $message = ''; $packageHash = '';
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$backups = new \App\Backup\DatabaseBackupService($pdo, role_capability_policy());
$restorer = new \App\Backup\DatabaseRestoreService($pdo, role_capability_policy(), $backups);
$reconciler = new \App\Backup\IdentityReconciliationService($pdo, role_capability_policy());
$decisions = is_array($_POST['people'] ?? null) ? $_POST['people'] : [];
$action = (string)($_POST['action'] ?? 'review');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $file = $_FILES['backup_file'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new DomainException('Select the encrypted Store package to review.');
        $packageHash = hash_file('sha256', $file['tmp_name']);
        if ($decisions !== [] && (!is_string($_POST['package_hash'] ?? null) || !hash_equals($_POST['package_hash'], $packageHash))) throw new DomainException('The selected package changed. Start a new identity review before choosing resolutions.');
        $package = $restorer->readPackage((int)$_SESSION['user_id'], (string)current_role(), $file['tmp_name'], $file['name'], (string)($_POST['restore_password'] ?? ''), (string)($_POST['backup_passphrase'] ?? ''));
        if ($action === 'approve') {
            $approved = $reconciler->approve((int)$_SESSION['user_id'], (string)current_role(), (string)($_POST['restore_password'] ?? ''), $package, $decisions);
            $review = $approved['review'];
            $approval = $approved['approval'];
            $message = 'Reconciliation approved. Destination security and preparation evidence are recorded. No records or files were replaced.';
        } else {
            $review = $reconciler->review((int)$_SESSION['user_id'], (string)current_role(), $package, $decisions);
            $message = $review['ready'] ? 'Identity review is ready for destination security approval. No records or files were replaced.' : implode(' ', $review['errors']);
        }
    } catch (DomainException $e) { $message = $e->getMessage(); }
    catch (Throwable $e) { $message = \App\Support\OperatorAlert::message($e, 'The identity review could not be completed. No Store records or files were replaced.'); }
}
$recordedApproval = $approval ?? $reconciler->latestApproval();
?>
<!doctype html><html lang="en"><head><?php retailmind_theme_head(); ?>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Identity Reconciliation</title>
<link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>"></head><body><div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content"><header class="topbar"><h1>Identity Reconciliation</h1><a href="<?= $escape(app_url('components/system_administrator/backup_restore.php')) ?>">Back to Backup &amp; Restore</a></header>
<section class="dashboard-section"><p>Matching names, IDs and login details never prove that two accounts belong to the same person. Links retain the destination account's password, status, roles, security state and profile.</p>
<p>Source privileged-only and Recovery identities remain Historical Actors without usable access. Ordinary replacement remains disabled.</p>
<?php if ($message !== ''): ?><p role="<?= ($review && $review['ready']) || $approval ? 'status' : 'alert' ?>"><?= $escape($message) ?></p><?php endif; ?>
<?php if ($recordedApproval && !$approval): ?>
<div class="dashboard-card" role="region" aria-label="Approved preparation evidence">
<h2>Active preparation evidence</h2>
<p>Approved by Super Administrator #<?= (int)$recordedApproval['approved_by'] ?> at <?= $escape($recordedApproval['approved_at']) ?>. Package ID: <code><?= $escape($recordedApproval['package_id']) ?></code>.</p>
<p><?= (int)$recordedApproval['active_administrators'] ?> active Administrator(s) configured. Replacement remains disabled; authorized consumers may use this preparation without receiving platform governance.</p>
</div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<?php if ($review): ?><input type="hidden" name="package_hash" value="<?= $escape($packageHash) ?>"><?php endif; ?>
<div class="form-group"><label for="identity-package">Encrypted Store package</label><input id="identity-package" type="file" name="backup_file" accept=".rmpkg" required aria-describedby="identity-upload-help"></div>
<p id="identity-upload-help"><?= $escape($restorer->supportedSizeLine()) ?> Select the same package again when reviewing resolutions. Package contents and passwords are not stored by this preview.</p>
<div class="form-group"><label for="identity-password">Current destination login password</label><input id="identity-password" type="password" name="restore_password" autocomplete="current-password" required></div>
<div class="form-group"><label for="identity-passphrase">This package's original backup passphrase</label><input id="identity-passphrase" type="password" name="backup_passphrase" autocomplete="off" required></div>
<?php if ($review): ?>
<h2>Destination people</h2><div class="table-wrap" tabindex="0" role="region" aria-label="Destination people"><table><thead><tr><th scope="col">Person</th><th scope="col">Login details</th><th scope="col">Current access</th></tr></thead><tbody>
<?php foreach ($review['destination_users'] as $person): ?><tr><td>#<?= $escape($person['user_id']) ?> <?= $escape($person['full_name']) ?></td><td><?= $escape($person['username']) ?><br><?= $escape($person['email'] ?? 'No email') ?></td><td><?= $escape($person['status']) ?>: <?= $escape(implode(', ', $person['roles'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><h2>Conflicts</h2><ul>
<?php foreach ($review['conflicts'] as $conflict): ?><li>Source #<?= $escape($conflict['source_id']) ?> and destination #<?= $escape($conflict['destination_id']) ?> share <?= $escape($conflict['kind']) ?>.</li><?php endforeach; ?>
<?php if (!$review['conflicts']): ?><li>No matching IDs or login details. Explicit identity choices are still required.</li><?php endif; ?></ul>
<h2>Source people and resolutions</h2>
<?php foreach ($review['source_users'] as $person): $id = (int)$person['user_id']; $choice = $decisions[$id] ?? []; ?>
<fieldset><legend>Source #<?= $id ?>: <?= $escape($person['full_name']) ?></legend><p><?= $escape($person['username']) ?>, <?= $escape($person['email'] ?? 'No email') ?>, <?= $escape($person['status']) ?>, <?= $escape(implode(', ', $person['roles'])) ?></p>
<?php if ($person['historical']): ?><input type="hidden" name="people[<?= $id ?>][action]" value="historical"><p>Historical Actor only. No login, role, password reset or Recovery authority will be imported.</p>
<?php else: ?>
<div class="form-group"><label for="resolution-<?= $id ?>">Resolution for source #<?= $id ?></label><select id="resolution-<?= $id ?>" name="people[<?= $id ?>][action]" required><option value="">Choose explicitly</option><option value="link" <?= ($choice['action'] ?? '') === 'link' ? 'selected' : '' ?>>Same person: retain destination account</option><option value="distinct" <?= ($choice['action'] ?? '') === 'distinct' ? 'selected' : '' ?>>Distinct person: import ordinary access</option></select></div>
<div class="form-group"><label for="destination-<?= $id ?>">Same-person destination account for source #<?= $id ?></label><select id="destination-<?= $id ?>" name="people[<?= $id ?>][destination_id]"><option value="">Choose only when linking</option><?php foreach ($review['destination_users'] as $target): ?><option value="<?= (int)$target['user_id'] ?>" <?= (int)($choice['destination_id'] ?? 0) === (int)$target['user_id'] ? 'selected' : '' ?>>#<?= (int)$target['user_id'] ?>: <?= $escape($target['full_name']) ?> (<?= $escape($target['username']) ?>)</option><?php endforeach; ?></select></div>
<div class="form-group"><label for="username-<?= $id ?>">Distinct person's unused username for source #<?= $id ?></label><input id="username-<?= $id ?>" name="people[<?= $id ?>][username]" maxlength="80" value="<?= $escape($choice['username'] ?? '') ?>" autocomplete="off"></div>
<div class="form-group"><label for="email-<?= $id ?>">Distinct person's email for source #<?= $id ?> (leave blank to clear explicitly)</label><input id="email-<?= $id ?>" type="email" name="people[<?= $id ?>][email]" maxlength="120" value="<?= $escape($choice['email'] ?? '') ?>" autocomplete="off"></div>
<label><input type="checkbox" name="people[<?= $id ?>][administrator]" value="1" <?= !empty($choice['administrator']) ? 'checked' : '' ?>> Explicitly designate this distinct ordinary person as Administrator</label><p>Resulting ordinary roles: <?= $escape(implode(', ', $person['ordinary_roles'])) ?>. Privileged source memberships are removed.</p>
<?php endif; ?></fieldset><?php endforeach; ?>
<?php if ($review['ready']): ?>
<h2>Destination preservation closure</h2>
<div class="table-wrap" tabindex="0" role="region" aria-label="Preservation categories">
<table><thead><tr><th scope="col">Preserved category</th><th scope="col">Destination count</th><th scope="col">Preservation rule</th></tr></thead><tbody>
<tr><td>Recovery accounts</td><td><?= (int)$review['preservation_closure']['counts']['recovery_accounts'] ?></td><td>Destination sealed recovery state preserved intact</td></tr>
<tr><td>Platform settings</td><td><?= (int)$review['preservation_closure']['counts']['platform_settings'] ?></td><td>Destination platform governance settings preserved</td></tr>
<tr><td>Platform attention settings</td><td><?= (int)$review['preservation_closure']['counts']['platform_attention_settings'] ?></td><td>Platform-scoped threshold and alerting preserved</td></tr>
<tr><td>Technical forecasting</td><td><?= (int)$review['preservation_closure']['counts']['ml_settings'] ?></td><td>Machine learning and forecasting configuration preserved</td></tr>
<tr><td>Security &amp; governance evidence</td><td><?= (int)$review['preservation_closure']['counts']['activity_log_evidence'] ?></td><td>Classified audit evidence preserved with unchanged JSON</td></tr>
<tr><td>Login attempts &amp; watermarks</td><td><?= (int)$review['preservation_closure']['counts']['login_attempts'] ?></td><td>Destination login tracking and attention watermarks preserved</td></tr>
<tr><td>Protected reset tokens</td><td><?= (int)$review['preservation_closure']['counts']['password_reset_tokens'] ?></td><td>Valid protected reset state preserved; source tokens dropped</td></tr>
<tr><td>Protected preferences &amp; alerts</td><td><?= (int)$review['preservation_closure']['counts']['notification_preferences'] ?></td><td>Protected notification preferences preserved</td></tr>
<tr><td>Backup operations history</td><td><?= (int)$review['preservation_closure']['counts']['backup_history'] ?></td><td>Destination backup history preserved with collision-free IDs</td></tr>
<tr><td>Email delivery evidence</td><td><?= (int)$review['preservation_closure']['counts']['email_delivery_log'] ?></td><td>Destination email delivery records preserved</td></tr>
</tbody></table></div>
<h2>Resulting access projection</h2>
<p><?= (int)$review['active_administrators'] ?> active ordinary Administrator(s). Privileged-only source identities and unlinked destination accounts gain no imported login access.</p>
<div class="table-wrap" tabindex="0" role="region" aria-label="Resulting access">
<table><thead><tr><th scope="col">User</th><th scope="col">Classification</th><th scope="col">Usable login access</th><th scope="col">Effective roles</th></tr></thead><tbody>
<?php foreach ($review['resulting_access'] as $acc): ?>
<tr><td>#<?= (int)$acc['user_id'] ?> <?= $escape($acc['username']) ?></td><td><?= $escape($acc['classification']) ?></td><td><?= $acc['usable_access'] ? 'Active login' : 'None (Evidence only)' ?></td><td><?= $escape(implode(', ', $acc['roles']) ?: 'None') ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($approval): ?>
<div class="dashboard-card" role="region" aria-label="Reconciliation approval confirmed">
<h3>Reconciliation approved</h3>
<p>Approval ID: <code><?= $escape($approval['approval_id']) ?></code></p>
<p>Approved at <?= $escape($approval['approved_at']) ?> by Super Administrator #<?= (int)$approval['approved_by'] ?>.</p>
<p>Destination security and preparation evidence are recorded. Ordinary replacement remains disabled.</p>
</div>
<?php else: ?>
<div class="form-actions">
<button class="btn btn-primary" type="submit" name="action" value="approve">Approve reconciliation and record preparation evidence</button>
<button class="btn btn-secondary" type="submit" name="action" value="review">Review resolutions</button>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php if (!$review || !$review['ready']): ?>
<button class="btn btn-primary" type="submit" name="action" value="review"><?= $review ? 'Review resolutions' : 'Inspect people and conflicts' ?></button>
<?php endif; ?>
</form></section></main></div></body></html>

