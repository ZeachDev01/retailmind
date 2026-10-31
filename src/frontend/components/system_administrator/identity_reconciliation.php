<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP);
require_local_request('Database restore is not available during remote monitoring.');
$backupPage = (string)current_role() === 'super_admin' ? 'system_administrator/backup_restore.php' : 'administrator/database_backup.php';

$review = null;
$approval = null;
$message = '';
$packageHash = '';
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$backups = new \App\Backup\DatabaseBackupService($pdo, role_capability_policy());
$restorer = new \App\Backup\DatabaseRestoreService($pdo, role_capability_policy(), $backups);
$reconciler = new \App\Backup\IdentityReconciliationService($pdo, role_capability_policy());
$decisions = is_array($_POST['people'] ?? null) ? $_POST['people'] : [];
$action = (string)($_POST['action'] ?? 'review');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_local_request('Database restore is not available during remote monitoring.');
    csrf_verify();
    try {
        $file = $_FILES['backup_file'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            throw new DomainException('Select the encrypted Store package to review.');
        }
        $packageHash = hash_file('sha256', $file['tmp_name']);
        if ($decisions !== [] && (!is_string($_POST['package_hash'] ?? null) || !hash_equals($_POST['package_hash'], $packageHash))) {
            throw new DomainException('The selected package changed. Start a new identity review before choosing resolutions.');
        }
        $package = $restorer->readPackage(
            (int)$_SESSION['user_id'],
            (string)current_role(),
            $file['tmp_name'],
            $file['name'],
            (string)($_POST['restore_password'] ?? ''),
            (string)($_POST['backup_passphrase'] ?? '')
        );
        if ($action === 'approve') {
            $approved = $reconciler->approve((int)$_SESSION['user_id'], (string)current_role(), (string)($_POST['restore_password'] ?? ''), $package, $decisions);
            $review = $approved['review'];
            $approval = $approved['approval'];
            $message = 'Reconciliation approved. Destination security and preparation evidence are recorded. No records or files were replaced.';
        } else {
            $review = $reconciler->review((int)$_SESSION['user_id'], (string)current_role(), $package, $decisions);
            $message = $review['ready'] ? 'Account choices are ready. Confirm restoration below. No records or files were replaced.' : implode(' ', $review['errors']);
        }
    } catch (DomainException $e) {
        $message = $e->getMessage();
    } catch (Throwable $e) {
        $message = \App\Support\OperatorAlert::message($e, 'The identity review could not be completed. No Store records or files were replaced.');
    }
}
$recordedApproval = $approval ?? $reconciler->latestApproval();
$isSuccessMessage = ($approval !== null) || ($review !== null && !empty($review['ready']));
?>
<!doctype html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Review backup</title>
    <link rel="stylesheet" href="<?= $escape(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/backup_restore.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/backup_restore.css')) ?>">

</head>
<body class="database-backup-page database-restore-page identity-reconciliation-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar recovery-heading">
            <div>
                <h1>Review backup</h1>
            </div>
            <div class="recovery-heading-actions">
                <a class="btn btn-secondary" href="<?= $escape(app_url('components/' . $backupPage)) ?>">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Backup &amp; Restore
                </a>
            </div>
        </header>

        <div class="reconcile-stack">
            <h2 class="sr-only">Identity review</h2>
            <div class="backup-storage-note" role="note" aria-label="Identity reconciliation principles">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                <p><strong>Check the accounts.</strong> Matching names or IDs do not prove two accounts belong to the same person. Linked accounts keep their destination password and access. Source privileged-only and Recovery accounts are kept for history without login access.</p>
            </div>

            <?php if ($message !== ''): ?>
                <div class="alert reconcile-alert <?= $isSuccessMessage ? 'tag-success' : 'tag-warning' ?>" role="<?= $isSuccessMessage ? 'status' : 'alert' ?>">
                    <i class="bi <?= $isSuccessMessage ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>" aria-hidden="true"></i>
                    <span><?= $escape($message) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($recordedApproval && !$approval): ?>
                <section class="dashboard-section reconcile-evidence" aria-labelledby="active-evidence-title">
                    <div class="recovery-panel-heading">
                        <h2 id="active-evidence-title"><i class="bi bi-patch-check" aria-hidden="true"></i> Active preparation evidence</h2>
                        <span class="tag-success">Approved</span>
                    </div>
                    <dl class="reconcile-facts">
                        <div><dt>Reviewed by</dt><dd>Administrator #<?= (int)$recordedApproval['approved_by'] ?></dd></div>
                        <div><dt>Reviewed at</dt><dd><time><?= $escape($recordedApproval['approved_at']) ?></time></dd></div>
                        <div><dt>Package ID</dt><dd><code><?= $escape($recordedApproval['package_id']) ?></code></dd></div>
                        <div><dt>Active Administrators</dt><dd><?= (int)$recordedApproval['active_administrators'] ?></dd></div>
                    </dl>
                    <p class="backup-hint">Ready for database restore under fresh credential validation.</p>
                </section>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" action="<?= $escape(app_url('components/system_administrator/identity_reconciliation.php')) ?>" data-identity-form class="reconcile-stack">
                <?= csrf_field() ?>
                <?php if ($review): ?>
                    <input type="hidden" name="package_hash" value="<?= $escape($packageHash) ?>">
                <?php endif; ?>

                <section class="dashboard-section" aria-labelledby="package-heading">
                    <div class="recovery-panel-heading">
                        <h2 id="package-heading"><i class="bi bi-shield-lock" aria-hidden="true"></i> Package &amp; credentials</h2>
                        <?php if (isset($package)): ?>
                            <span class="history-total">Snapshot <?= $escape($package['manifest']['snapshot_at']) ?> UTC</span>
                        <?php endif; ?>
                    </div>
                    <?php if (isset($package)): ?>
                        <p class="backup-hint">Restoring replaces newer Store records.</p>
                    <?php endif; ?>

                    <div class="form-group reconcile-field">
                        <label for="identity-package">Encrypted Store package (.rmpkg)</label>
                        <p class="restore-file-hint" id="identity-upload-help"><?= $escape($restorer->supportedSizeLine()) ?> Your selection stays available during this review. Reloading clears it.</p>
                        <div class="restore-file-field">
                            <input id="identity-package" type="file" name="backup_file" accept=".rmpkg" required aria-describedby="identity-upload-help">
                        </div>
                    </div>

                    <div class="credentials-grid">
                        <div class="form-group">
                            <label for="identity-password">Current destination login password</label>
                            <input id="identity-password" type="password" name="restore_password" autocomplete="current-password" required placeholder="Destination login password">
                        </div>
                        <div class="form-group">
                            <details class="reconcile-older">
                                <summary>Older backup options</summary>
                                <label for="identity-passphrase">Original passphrase (older backups only)</label>
                                <input id="identity-passphrase" type="password" name="backup_passphrase" autocomplete="off" maxlength="1024">
                            </details>
                        </div>
                    </div>
                </section>

                <?php if ($review): ?>
                    <section class="dashboard-section" aria-labelledby="dest-people-heading">
                        <div class="recovery-panel-heading">
                            <h2 id="dest-people-heading"><i class="bi bi-people" aria-hidden="true"></i> Destination people</h2>
                            <span class="history-total"><?= count($review['destination_users']) ?> <?= count($review['destination_users']) === 1 ? 'account' : 'accounts' ?></span>
                        </div>
                        <div class="table-wrap reconcile-table" tabindex="0" role="region" aria-label="Destination people">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">Person</th>
                                        <th scope="col">Login details</th>
                                        <th scope="col">Current access</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($review['destination_users'] as $person): ?>
                                        <tr>
                                            <td><span class="reconcile-id">#<?= $escape($person['user_id']) ?></span> <?= $escape($person['full_name']) ?></td>
                                            <td><code><?= $escape($person['username']) ?></code><span class="reconcile-sub"><?= $escape($person['email'] ?? 'No email') ?></span></td>
                                            <td>
                                                <span class="<?= $person['status'] === 'active' ? 'tag-success' : 'tag-warning' ?>"><?= $escape(ucfirst((string)$person['status'])) ?></span>
                                                <span class="reconcile-sub"><?= $escape(implode(', ', $person['roles'])) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="dashboard-section" aria-labelledby="conflicts-heading">
                        <div class="recovery-panel-heading">
                            <h2 id="conflicts-heading"><i class="bi bi-exclamation-diamond" aria-hidden="true"></i> Conflicts</h2>
                            <span class="history-total"><?= count($review['conflicts']) ?> <?= count($review['conflicts']) === 1 ? 'conflict' : 'conflicts' ?></span>
                        </div>
                        <?php if (!empty($review['conflicts'])): ?>
                            <ul class="reconcile-conflicts">
                                <?php foreach ($review['conflicts'] as $conflict): ?>
                                    <li>Source <strong>#<?= $escape($conflict['source_id']) ?></strong> and destination <strong>#<?= $escape($conflict['destination_id']) ?></strong> share <span class="tag-warning"><?= $escape($conflict['kind']) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="backup-hint"><i class="bi bi-check2-circle" aria-hidden="true"></i> No matching IDs or login details. Explicit identity choices are still required.</p>
                        <?php endif; ?>
                    </section>

                    <section class="dashboard-section" aria-labelledby="source-resolutions-heading">
                        <div class="recovery-panel-heading">
                            <h2 id="source-resolutions-heading"><i class="bi bi-person-gear" aria-hidden="true"></i> Source people and resolutions</h2>
                            <span class="history-total"><?= count($review['source_users']) ?> <?= count($review['source_users']) === 1 ? 'identity' : 'identities' ?></span>
                        </div>
                        <div class="resolution-list">
                            <?php foreach ($review['source_users'] as $person):
                                $id = (int)$person['user_id'];
                                $choice = $decisions[$id] ?? [];
                            ?>
                                <fieldset class="resolution-card">
                                    <legend class="sr-only">Source #<?= $id ?>: <?= $escape($person['full_name']) ?></legend>
                                    <div class="resolution-head">
                                        <div>
                                            <h3><span class="reconcile-id">#<?= $id ?></span> <?= $escape($person['full_name']) ?></h3>
                                            <p class="identity-meta"><code><?= $escape($person['username']) ?></code> <span><?= $escape($person['email'] ?? 'No email') ?></span></p>
                                        </div>
                                        <div class="resolution-badges">
                                            <span class="<?= $person['status'] === 'active' ? 'tag-success' : 'tag-warning' ?>"><?= $escape(ucfirst((string)$person['status'])) ?></span>
                                            <span class="tag-neutral"><?= $escape(implode(', ', $person['roles']) ?: 'No roles') ?></span>
                                        </div>
                                    </div>
                                    <?php if ($person['historical']): ?>
                                        <input type="hidden" name="people[<?= $id ?>][action]" value="historical">
                                        <div class="resolution-historical">
                                            <i class="bi bi-archive" aria-hidden="true"></i>
                                            <p><strong>Historical Actor only.</strong> No login, role, password reset or Recovery authority will be imported.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="resolution-grid">
                                            <div class="form-group">
                                                <label for="resolution-<?= $id ?>">Resolution action</label>
                                                <select id="resolution-<?= $id ?>" name="people[<?= $id ?>][action]" required>
                                                    <option value="">Choose explicitly</option>
                                                    <option value="link" <?= ($choice['action'] ?? '') === 'link' ? 'selected' : '' ?>>Same person: retain destination account</option>
                                                    <option value="distinct" <?= ($choice['action'] ?? '') === 'distinct' ? 'selected' : '' ?>>Distinct person: import ordinary access</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="destination-<?= $id ?>">Destination account (linking only)</label>
                                                <select id="destination-<?= $id ?>" name="people[<?= $id ?>][destination_id]">
                                                    <option value="">Choose only when linking</option>
                                                    <?php foreach ($review['destination_users'] as $target): ?>
                                                        <option value="<?= (int)$target['user_id'] ?>" <?= (int)($choice['destination_id'] ?? 0) === (int)$target['user_id'] ? 'selected' : '' ?>>
                                                            #<?= (int)$target['user_id'] ?>: <?= $escape($target['full_name']) ?> (<?= $escape($target['username']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="username-<?= $id ?>">Distinct unused username</label>
                                                <input id="username-<?= $id ?>" name="people[<?= $id ?>][username]" maxlength="80" value="<?= $escape($choice['username'] ?? '') ?>" autocomplete="off" placeholder="New username">
                                            </div>
                                            <div class="form-group">
                                                <label for="email-<?= $id ?>">Distinct email (blank to clear)</label>
                                                <input id="email-<?= $id ?>" type="email" name="people[<?= $id ?>][email]" maxlength="120" value="<?= $escape($choice['email'] ?? '') ?>" autocomplete="off" placeholder="user@example.com">
                                            </div>
                                        </div>
                                        <label class="resolution-option">
                                            <input type="checkbox" name="people[<?= $id ?>][administrator]" value="1" <?= !empty($choice['administrator']) ? 'checked' : '' ?>>
                                            <span>
                                                Explicitly designate this distinct ordinary person as Administrator
                                                <small>Resulting ordinary roles: <strong><?= $escape(implode(', ', $person['ordinary_roles'])) ?></strong>. Privileged source memberships are removed.</small>
                                            </span>
                                        </label>
                                    <?php endif; ?>
                                </fieldset>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <?php if ($review['ready']): ?>
                        <?php $counts = $review['preservation_closure']['counts'];
                        $preserved = [
                            ['Recovery accounts', 'recovery_accounts', 'Destination sealed recovery state preserved intact'],
                            ['Platform settings', 'platform_settings', 'Destination platform governance settings preserved'],
                            ['Platform attention settings', 'platform_attention_settings', 'Platform-scoped threshold and alerting preserved'],
                            ['Technical forecasting', 'ml_settings', 'Machine learning and forecasting configuration preserved'],
                            ['Security & governance evidence', 'activity_log_evidence', 'Classified audit evidence preserved with unchanged JSON'],
                            ['Login attempts & watermarks', 'login_attempts', 'Destination login tracking and attention watermarks preserved'],
                            ['Protected reset tokens', 'password_reset_tokens', 'Valid protected reset state preserved; source tokens dropped'],
                            ['Protected preferences & alerts', 'notification_preferences', 'Protected notification preferences preserved'],
                            ['Backup operations history', 'backup_history', 'Destination backup history preserved with collision-free IDs'],
                            ['Email delivery evidence', 'email_delivery_log', 'Destination email delivery records preserved'],
                        ]; ?>
                        <section class="dashboard-section" aria-labelledby="preservation-heading">
                            <div class="recovery-panel-heading">
                                <h2 id="preservation-heading"><i class="bi bi-shield-check" aria-hidden="true"></i> Destination preservation closure</h2>
                                <span class="tag-success">10 Rules Enforced</span>
                            </div>
                            <p class="backup-hint">Destination security, recovery states, audit evidence, and tracking watermarks are preserved intact without replacement.</p>
                            <div class="table-wrap reconcile-table" tabindex="0" role="region" aria-label="Preservation categories">
                                <table>
                                    <thead>
                                        <tr>
                                            <th scope="col">Preserved category</th>
                                            <th scope="col" class="num">Destination count</th>
                                            <th scope="col">Preservation rule</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($preserved as [$label, $key, $rule]): ?>
                                            <tr><td><?= $escape($label) ?></td><td class="num"><strong><?= (int)$counts[$key] ?></strong></td><td><?= $escape($rule) ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="dashboard-section" aria-labelledby="projection-heading">
                            <div class="recovery-panel-heading">
                                <h2 id="projection-heading"><i class="bi bi-person-check" aria-hidden="true"></i> Resulting access projection</h2>
                                <span class="tag-success"><?= (int)$review['active_administrators'] ?> Active Admin(s)</span>
                            </div>
                            <p class="backup-hint">Privileged-only source identities and unlinked destination accounts gain no imported login access.</p>
                            <div class="table-wrap reconcile-table" tabindex="0" role="region" aria-label="Resulting access">
                                <table>
                                    <thead>
                                        <tr>
                                            <th scope="col">User</th>
                                            <th scope="col">Classification</th>
                                            <th scope="col">Usable login access</th>
                                            <th scope="col">Effective roles</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($review['resulting_access'] as $acc): ?>
                                            <tr>
                                                <td><span class="reconcile-id">#<?= (int)$acc['user_id'] ?></span> <?= $escape($acc['username']) ?></td>
                                                <td><span class="tag-neutral"><?= $escape($acc['classification']) ?></span></td>
                                                <td>
                                                    <?php if ($acc['usable_access']): ?>
                                                        <span class="tag-success"><i class="bi bi-check-circle" aria-hidden="true"></i> Active login</span>
                                                    <?php else: ?>
                                                        <span class="tag-warning"><i class="bi bi-slash-circle" aria-hidden="true"></i> None (Evidence only)</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $escape(implode(', ', $acc['roles']) ?: 'None') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <?php if ($approval): ?>
                            <section class="dashboard-section reconcile-evidence" aria-labelledby="reconciliation-approved-title">
                                <div class="recovery-panel-heading">
                                    <h2 id="reconciliation-approved-title"><i class="bi bi-check-all" aria-hidden="true"></i> Reconciliation approved</h2>
                                    <span class="tag-success">Evidence recorded</span>
                                </div>
                                <dl class="reconcile-facts">
                                    <div><dt>Approval ID</dt><dd><code><?= $escape($approval['approval_id']) ?></code></dd></div>
                                    <div><dt>Approved at</dt><dd><time><?= $escape($approval['approved_at']) ?></time></dd></div>
                                    <div><dt>Approved by</dt><dd>Administrator #<?= (int)$approval['approved_by'] ?></dd></div>
                                </dl>
                                <p class="backup-hint">Review complete. Confirm restoration below.</p>
                            </section>
                        <?php else: ?>
                            <section class="dashboard-section reconcile-confirm" aria-label="Confirm restoration">
                                <label class="resolution-option">
                                    <input type="checkbox" name="confirm_replacement" value="1" required>
                                    <span>I understand this snapshot replaces newer records and Store operations remain paused until verification. Stop source sales and background workers before continuing.</span>
                                </label>
                                <div class="reconcile-actions">
                                    <button class="btn btn-primary" type="submit" formaction="<?= $escape(app_url('components/backup/restore.php')) ?>" data-start-restore>
                                        <i class="bi bi-shield-check" aria-hidden="true"></i> Restore and verify
                                    </button>
                                    <button class="btn btn-secondary" type="submit" name="action" value="review" formnovalidate>
                                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Review resolutions
                                    </button>
                                </div>
                            </section>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$review || empty($review['ready'])): ?>
                    <div class="reconcile-actions">
                        <button class="btn btn-primary" type="submit" name="action" value="review">
                            <i class="bi bi-search" aria-hidden="true"></i> <?= $review ? 'Review resolutions' : 'Inspect people and conflicts' ?>
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <?php if ($approval):
            $service = $backups;
            $restoreReviewFirst = false;
            require __DIR__ . '/../backup/restore_form.php';
        endif; ?>
    </main>
</div>
<script src="<?= $escape(app_url('assets/js/backup_review.js')) ?>"></script>
</body>
</html>
