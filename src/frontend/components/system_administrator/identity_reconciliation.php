<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
$review = null; $message = ''; $packageHash = '';
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$backups = new \App\Backup\DatabaseBackupService($pdo, role_capability_policy());
$restorer = new \App\Backup\DatabaseRestoreService($pdo, role_capability_policy(), $backups);
$decisions = is_array($_POST['people'] ?? null) ? $_POST['people'] : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $file = $_FILES['backup_file'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new DomainException('Select the encrypted Store package to review.');
        $packageHash = hash_file('sha256', $file['tmp_name']);
        if ($decisions !== [] && (!is_string($_POST['package_hash'] ?? null) || !hash_equals($_POST['package_hash'], $packageHash))) throw new DomainException('The selected package changed. Start a new identity review before choosing resolutions.');
        $package = $restorer->readPackage((int)$_SESSION['user_id'], (string)current_role(), $file['tmp_name'], $file['name'], (string)($_POST['restore_password'] ?? ''), (string)($_POST['backup_passphrase'] ?? ''));
        $review = (new \App\Backup\IdentityReconciliationService($pdo, role_capability_policy()))->review((int)$_SESSION['user_id'], (string)current_role(), $package, $decisions);
        $message = $review['ready'] ? 'Identity review is ready for destination security approval. No records or files were replaced.' : implode(' ', $review['errors']);
    } catch (DomainException $e) { $message = $e->getMessage(); }
    catch (Throwable $e) { $message = \App\Support\OperatorAlert::message($e, 'The identity review could not be completed. No Store records or files were replaced.'); }
}
?>
<!doctype html><html lang="en"><head><?php retailmind_theme_head(); ?>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Identity Reconciliation</title>
<link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>"></head><body><div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content"><header class="topbar"><h1>Identity Reconciliation</h1><a href="<?= $escape(app_url('components/system_administrator/backup_restore.php')) ?>">Back to Backup &amp; Restore</a></header>
<section class="dashboard-section"><p>Matching names, IDs and login details never prove that two accounts belong to the same person. Links retain the destination account's password, status, roles, security state and profile.</p>
<p>Source privileged-only and Recovery identities remain Historical Actors without usable access. This review never replaces Store records or files.</p>
<?php if ($message !== ''): ?><p role="<?= $review && $review['ready'] ? 'status' : 'alert' ?>"><?= $escape($message) ?></p><?php endif; ?>
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
<?php if ($review['ready']): ?><h2>Reviewed result</h2><p><?= (int)$review['active_administrators'] ?> active ordinary Administrator(s). Unlinked destination accounts without protected authority do not gain imported login access.</p><ul><?php foreach ($review['identity_map'] as $sourceId => $localId): ?><li>Source #<?= (int)$sourceId ?> maps to destination #<?= (int)$localId ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php endif; ?><button class="btn btn-primary" type="submit"><?= $review ? 'Review resolutions' : 'Inspect people and conflicts' ?></button>
</form></section></main></div></body></html>
