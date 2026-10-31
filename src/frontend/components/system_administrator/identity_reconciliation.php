<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/backup.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);

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
            $message = $review['ready'] ? 'Identity review is ready for destination security approval. No records or files were replaced.' : implode(' ', $review['errors']);
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
    <title>Identity Reconciliation</title>
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
                <h1>Identity Reconciliation</h1>
            </div>
            <div class="recovery-heading-actions">
                <a class="btn btn-secondary" href="<?= $escape(app_url('components/system_administrator/backup_restore.php')) ?>">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Backup &amp; Restore
                </a>
            </div>
        </header>

        <section class="dashboard-section" aria-labelledby="policy-heading">
            <h2 id="policy-heading" class="sr-only">Governance Policy</h2>
            <div class="backup-storage-note" role="note" aria-label="Identity reconciliation principles">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                <strong>Identity Matching &amp; Governance Policy:</strong> Matching names or IDs never prove identical ownership. Linked accounts retain destination credentials, roles, and security policies. Privileged-only and Recovery identities convert to Historical Actors without usable login access.
            </div>

            <?php if ($message !== ''): ?>
                <div class="alert <?= $isSuccessMessage ? 'tag-success' : 'tag-warning' ?>" role="<?= $isSuccessMessage ? 'status' : 'alert' ?>" style="margin-top: 1rem;">
                    <i class="bi <?= $isSuccessMessage ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>" aria-hidden="true"></i>
                    <span><?= $escape($message) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($recordedApproval && !$approval): ?>
                <div class="dashboard-card status-callout" role="region" aria-labelledby="active-evidence-title" style="margin-top: 1.25rem;">
                    <div class="recovery-panel-heading">
                        <h2 id="active-evidence-title"><i class="bi bi-patch-check" aria-hidden="true"></i> Active preparation evidence</h2>
                        <span class="tag-success">Approved</span>
                    </div>
                    <p style="margin: 0.5rem 0 0.25rem;">
                        Approved by Super Administrator <strong>#<?= (int)$recordedApproval['approved_by'] ?></strong> at <time><?= $escape($recordedApproval['approved_at']) ?></time> &bull; Package ID: <code><?= $escape($recordedApproval['package_id']) ?></code>
                    </p>
                    <p class="backup-hint" style="margin: 0;">
                        <strong><?= (int)$recordedApproval['active_administrators'] ?></strong> active Administrator(s) configured. Ready for database restore under fresh credential validation.
                    </p>
                </div>
            <?php endif; ?>

            <?php if (isset($package)): ?><p><strong>Backup snapshot: <?= $escape($package['manifest']['snapshot_at']) ?> UTC.</strong> Restoring replaces newer Store records.</p><?php endif; ?>
            <form method="post" enctype="multipart/form-data" action="<?= $escape(app_url('components/system_administrator/identity_reconciliation.php')) ?>" data-identity-form style="margin-top: 1.25rem;"><?= csrf_field() ?>
                <?php if ($review): ?>
                    <input type="hidden" name="package_hash" value="<?= $escape($packageHash) ?>">
                <?php endif; ?>

                <div class="recovery-panel-heading" style="margin-bottom: 0.75rem;">
                    <h2><i class="bi bi-shield-lock" aria-hidden="true"></i> Package &amp; Credentials</h2>
                </div>

                <div class="form-group">
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
                        <details><summary>Older backup options</summary>
                        <label for="identity-passphrase">Original passphrase (older backups only)</label>
                        <input id="identity-passphrase" type="password" name="backup_passphrase" autocomplete="off" maxlength="1024">
                        </details>
                    </div>
                </div>

                <?php if ($review): ?>
                    <section style="margin-top: 1.75rem;" aria-labelledby="dest-people-heading">
                        <div class="recovery-panel-heading">
                            <h2 id="dest-people-heading"><i class="bi bi-people" aria-hidden="true"></i> Destination people</h2>
                            <span class="history-total"><?= count($review['destination_users']) ?> <?= count($review['destination_users']) === 1 ? 'account' : 'accounts' ?></span>
                        </div>
                        <div class="table-wrap" tabindex="0" role="region" aria-label="Destination people">
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
                                            <td><strong>#<?= $escape($person['user_id']) ?></strong> <?= $escape($person['full_name']) ?></td>
                                            <td><code><?= $escape($person['username']) ?></code><br><span class="backup-hint"><?= $escape($person['email'] ?? 'No email') ?></span></td>
                                            <td>
                                                <span class="<?= $person['status'] === 'active' ? 'tag-success' : 'tag-warning' ?>"><?= $escape(ucfirst((string)$person['status'])) ?></span>
                                                <span style="margin-left: 0.35rem; font-size: 0.85rem;"><?= $escape(implode(', ', $person['roles'])) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section style="margin-top: 1.75rem;" aria-labelledby="conflicts-heading">
                        <div class="recovery-panel-heading">
                            <h2 id="conflicts-heading"><i class="bi bi-exclamation-diamond" aria-hidden="true"></i> Conflicts</h2>
                            <span class="history-total"><?= count($review['conflicts']) ?> <?= count($review['conflicts']) === 1 ? 'conflict' : 'conflicts' ?></span>
                        </div>
                        <?php if (!empty($review['conflicts'])): ?>
                            <ul style="margin: 0.5rem 0 0; padding-left: 1.25rem; line-height: 1.7;">
                                <?php foreach ($review['conflicts'] as $conflict): ?>
                                    <li>Source <strong>#<?= $escape($conflict['source_id']) ?></strong> and destination <strong>#<?= $escape($conflict['destination_id']) ?></strong> share <span class="tag-warning"><?= $escape($conflict['kind']) ?></span>.</li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="backup-hint" style="margin: 0.5rem 0 0;"><i class="bi bi-check2-circle" aria-hidden="true"></i> No matching IDs or login details. Explicit identity choices are still required.</p>
                        <?php endif; ?>
                    </section>

                    <section style="margin-top: 1.75rem;" aria-labelledby="source-resolutions-heading">
                        <div class="recovery-panel-heading">
                            <h2 id="source-resolutions-heading"><i class="bi bi-person-gear" aria-hidden="true"></i> Source people and resolutions</h2>
                            <span class="history-total"><?= count($review['source_users']) ?> <?= count($review['source_users']) === 1 ? 'identity' : 'identities' ?></span>
                        </div>
                        <div style="margin-top: 1rem;">
                            <?php foreach ($review['source_users'] as $person):
                                $id = (int)$person['user_id'];
                                $choice = $decisions[$id] ?? [];
                            ?>
                                <fieldset class="resolution-card">
                                    <legend style="padding: 0 0.5rem; font-weight: 700; font-size: 0.95rem; color: var(--recovery-ink);">
                                        Source #<?= $id ?>: <?= $escape($person['full_name']) ?>
                                    </legend>
                                    <div class="identity-meta">
                                        <span>Username: <code><?= $escape($person['username']) ?></code></span>
                                        <span>&bull;</span>
                                        <span>Email: <?= $escape($person['email'] ?? 'No email') ?></span>
                                        <span>&bull;</span>
                                        <span class="<?= $person['status'] === 'active' ? 'tag-success' : 'tag-warning' ?>"><?= $escape(ucfirst((string)$person['status'])) ?></span>
                                        <span>&bull;</span>
                                        <span>Roles: <?= $escape(implode(', ', $person['roles'])) ?></span>
                                    </div>
                                    <?php if ($person['historical']): ?>
                                        <input type="hidden" name="people[<?= $id ?>][action]" value="historical">
                                        <div class="backup-storage-note" style="padding: 0.65rem 0.85rem; font-size: 0.85rem;">
                                            <i class="bi bi-archive" aria-hidden="true"></i> <strong>Historical Actor only.</strong> No login, role, password reset or Recovery authority will be imported.
                                        </div>
                                    <?php else: ?>
                                        <div class="resolution-grid">
                                            <div class="form-group" style="margin-bottom: 0;">
                                                <label for="resolution-<?= $id ?>">Resolution action</label>
                                                <select id="resolution-<?= $id ?>" name="people[<?= $id ?>][action]" required>
                                                    <option value="">Choose explicitly</option>
                                                    <option value="link" <?= ($choice['action'] ?? '') === 'link' ? 'selected' : '' ?>>Same person: retain destination account</option>
                                                    <option value="distinct" <?= ($choice['action'] ?? '') === 'distinct' ? 'selected' : '' ?>>Distinct person: import ordinary access</option>
                                                </select>
                                            </div>
                                            <div class="form-group" style="margin-bottom: 0;">
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
                                            <div class="form-group" style="margin-bottom: 0;">
                                                <label for="username-<?= $id ?>">Distinct unused username</label>
                                                <input id="username-<?= $id ?>" name="people[<?= $id ?>][username]" maxlength="80" value="<?= $escape($choice['username'] ?? '') ?>" autocomplete="off" placeholder="New username">
                                            </div>
                                            <div class="form-group" style="margin-bottom: 0;">
                                                <label for="email-<?= $id ?>">Distinct email (blank to clear)</label>
                                                <input id="email-<?= $id ?>" type="email" name="people[<?= $id ?>][email]" maxlength="120" value="<?= $escape($choice['email'] ?? '') ?>" autocomplete="off" placeholder="user@example.com">
                                            </div>
                                        </div>
                                        <div style="margin-top: 0.85rem; display: flex; flex-direction: column; gap: 0.35rem;">
                                            <label style="display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 500; font-size: 0.9rem; cursor: pointer;">
                                                <input type="checkbox" name="people[<?= $id ?>][administrator]" value="1" <?= !empty($choice['administrator']) ? 'checked' : '' ?>>
                                                <span>Explicitly designate this distinct ordinary person as Administrator</span>
                                            </label>
                                            <p class="backup-hint" style="margin: 0; font-size: 0.8rem;">
                                                Resulting ordinary roles: <strong><?= $escape(implode(', ', $person['ordinary_roles'])) ?></strong>. Privileged source memberships are removed.
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </fieldset>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <?php if ($review['ready']): ?>
                        <section style="margin-top: 1.75rem;" aria-labelledby="preservation-heading">
                            <div class="recovery-panel-heading">
                                <h2 id="preservation-heading"><i class="bi bi-shield-check" aria-hidden="true"></i> Destination preservation closure</h2>
                                <span class="tag-success">10 Rules Enforced</span>
                            </div>
                            <p class="backup-hint" style="margin: 0.35rem 0 0.85rem;">Destination security, recovery states, audit evidence, and tracking watermarks are preserved intact without replacement.</p>
                            <div class="table-wrap" tabindex="0" role="region" aria-label="Preservation categories">
                                <table>
                                    <thead>
                                        <tr>
                                            <th scope="col">Preserved category</th>
                                            <th scope="col" style="text-align: right;">Destination count</th>
                                            <th scope="col">Preservation rule</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr><td>Recovery accounts</td><td style="text-align: right; font-variant-numeric: tabular-nums;"><strong><?= (int)$review['preservation_closure']['counts']['recovery_accounts'] ?></strong></td><td>Destination sealed recovery state preserved intact</td></tr>
                                        <tr><td>Platform settings</td><td style="text-align: right; font-variant-numeric: tabular-nums;"><strong><?= (int)$review['preservation_closure']['counts']['platform_settings'] ?></strong></td><td>Destination platform governance settings preserved</td></tr>
                                        <tr><td>Platform attention settings</td><td style="text-align: right; font-variant-numeric: tabular-nums;"><strong><?= (int)$review['preservation_closure']['counts']['platform_attention_settings'] ?></strong></td><td>Platform-scoped threshold and alerting preserved</td></tr>
                                        <tr><td>Technical forecasting</td><td style="text-align: right; font-variant-numeric: tabular-nums;"><strong><?= (int)$review['preservation_closure']['counts']['ml_settings'] ?></strong></td><td>Machine learning and forecasting configuration preserved</td></tr>
                                        <tr><td>Security &amp; governance evidence</td><td style="text-align: right; font-variant-numeric: tabular-nums;"><strong><?= (int)$review['preservation_closure']['counts']['activity_log_evidence'] ?></strong></td><td>Classified audit evidence preserved with unchanged JSON</td></tr>
                                        <tr><td>Login attempts &amp; watermarks</td><td style="text-align: right; font-variant-numeric: tabular-nums;"><strong><?= (int)$review['preservation_closure']['counts']['login_attempts'] ?></strong></td><td>Destination login tracking and attention watermarks preserved</td></tr>
                                        <tr><td>Protected reset tokens</td><td style="text-align: right; font-variant-numeric: tabular-nums;"><strong><?= (int)$review['preservation_closure']['counts']['password_reset_tokens'] ?></strong></td><td>Valid protected reset state preserved; source tokens dropped</td></tr>
                                        <tr><td>Protected preferences &amp; alerts</td><td style="text-align: right; font-variant-numeric: tabular-nums;"><strong><?= (int)$review['preservation_closure']['counts']['notification_preferences'] ?></strong></td><td>Protected notification preferences preserved</td></tr>
                                        <tr><td>Backup operations history</td><td style="text-align: right; font-variant-numeric: tabular-nums;"><strong><?= (int)$review['preservation_closure']['counts']['backup_history'] ?></strong></td><td>Destination backup history preserved with collision-free IDs</td></tr>
                                        <tr><td>Email delivery evidence</td><td style="text-align: right; font-variant-numeric: tabular-nums;"><strong><?= (int)$review['preservation_closure']['counts']['email_delivery_log'] ?></strong></td><td>Destination email delivery records preserved</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section style="margin-top: 1.75rem;" aria-labelledby="projection-heading">
                            <div class="recovery-panel-heading">
                                <h2 id="projection-heading"><i class="bi bi-person-check" aria-hidden="true"></i> Resulting access projection</h2>
                                <span class="tag-success"><?= (int)$review['active_administrators'] ?> Active Admin(s)</span>
                            </div>
                            <p class="backup-hint" style="margin: 0.35rem 0 0.85rem;">Privileged-only source identities and unlinked destination accounts gain no imported login access.</p>
                            <div class="table-wrap" tabindex="0" role="region" aria-label="Resulting access">
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
                                                <td><strong>#<?= (int)$acc['user_id'] ?></strong> <?= $escape($acc['username']) ?></td>
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
                            <div class="dashboard-card status-callout" role="region" aria-labelledby="reconciliation-approved-title" style="margin-top: 1.5rem;">
                                <div class="recovery-panel-heading">
                                    <h3 id="reconciliation-approved-title" style="margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="bi bi-check-all" style="color: var(--theme-success-text, #166534);" aria-hidden="true"></i> Reconciliation approved
                                    </h3>
                                    <span class="tag-success">Evidence Recorded</span>
                                </div>
                                <p style="margin: 0.75rem 0 0.25rem;">Approval ID: <code><?= $escape($approval['approval_id']) ?></code></p>
                                <p style="margin: 0 0 0.5rem; font-size: 0.9rem; color: var(--recovery-muted);">Approved at <?= $escape($approval['approved_at']) ?> by Super Administrator #<?= (int)$approval['approved_by'] ?>.</p>
                                <p class="backup-hint" style="margin: 0;">Review complete. Confirm restoration below.</p>
                            </div>
                        <?php else: ?>
                            <div class="form-actions" style="margin-top: 1.5rem; display: flex; flex-wrap: wrap; gap: 0.75rem;">
                                <button class="btn btn-primary" type="submit" name="action" value="approve">
                                    <i class="bi bi-shield-check" aria-hidden="true"></i> Approve reconciliation and record preparation evidence
                                </button>
                                <button class="btn btn-secondary" type="submit" name="action" value="review">
                                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Review resolutions
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$review || empty($review['ready'])): ?>
                    <div style="margin-top: 1.5rem;">
                        <button class="btn btn-primary" type="submit" name="action" value="review">
                            <i class="bi bi-search" aria-hidden="true"></i> <?= $review ? 'Review resolutions' : 'Inspect people and conflicts' ?>
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </section>
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
