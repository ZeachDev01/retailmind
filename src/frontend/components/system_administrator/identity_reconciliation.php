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
$file = null;
if (isset($_GET['new'])) unset($_SESSION['backup_review']);
$draft = backup_review_draft();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $draft) {
    $review = $draft['review'];
    $decisions = $draft['decisions'];
    $packageHash = $draft['package_hash'];
    $package = ['manifest' => ['snapshot_at' => $draft['snapshot_at']]];
    $action = $draft['action'];
    $message = 'Review resumed. Enter your current login password to continue.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_local_request('Database restore is not available during remote monitoring.');
    csrf_verify();
    try {
        $file = backup_review_source();
        $packageHash = hash_file('sha256', $file['tmp_name']);
        if ($decisions !== [] && (!is_string($_POST['package_hash'] ?? null) || !hash_equals($_POST['package_hash'], $packageHash))) {
            throw new DomainException('The selected package changed. Start a new identity review before choosing resolutions.');
        }
        $package = $restorer->readPackage(
            (int)$_SESSION['user_id'],
            (string)current_role(),
            $file['tmp_name'],
            $file['name'],
            (string)($_POST['restore_password'] ?? ''));
        if ($action === 'approve') {
            $approved = $reconciler->approve((int)$_SESSION['user_id'], (string)current_role(), (string)($_POST['restore_password'] ?? ''), $package, $decisions);
            $review = $approved['review'];
            $approval = $approved['approval'];
            $message = 'Reconciliation approved. No records were replaced.';
        } else {
            $review = $reconciler->review((int)$_SESSION['user_id'], (string)current_role(), $package, $decisions);
            $message = $action === 'edit' ? 'Adjust the account choices below.'
                : ($review['ready'] ? 'Account choices are ready. Confirm restoration below.' : ($decisions === [] ? '' : implode(' ', $review['errors'])));
        }
        $encryptedPackage = file_get_contents($file['tmp_name']);
        if ($encryptedPackage === false) throw new RuntimeException('The reviewed package could not be retained.');
        $_SESSION['backup_review'] = $draft = [
            'user_id' => $_SESSION['user_id'], 'role' => $_SESSION['role'], 'expires_at' => time() + 1800,
            'token' => bin2hex(random_bytes(32)), 'package_hash' => $packageHash, 'filename' => $file['name'],
            'encrypted_package' => $encryptedPackage, 'snapshot_at' => $package['manifest']['snapshot_at'],
            'review' => array_intersect_key($review, array_flip(['source_users', 'destination_users', 'conflicts', 'errors',
                'ready', 'active_administrators', 'preservation_closure', 'resulting_access', 'compatibility'])),
            'decisions' => $decisions, 'action' => $action,
        ];
    } catch (DomainException $e) {
        $message = $e->getMessage();
    } catch (Throwable $e) {
        $message = \App\Support\OperatorAlert::message($e, 'The identity review could not be completed. No Store records or files were replaced.');
    } finally {
        if (!empty($file['temporary'])) unlink($file['tmp_name']);
    }
}
$editing = $action === 'edit';
$recordedApproval = $approval;
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
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/backup.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/backup.css')) ?>">
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
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to backups
                </a>
            </div>
        </header>

        <div class="reconcile-stack">
            <ol class="reconcile-steps" aria-label="Restoration progress">
                <li class="<?= $review ? 'is-complete' : 'is-current' ?>"<?= $review ? '' : ' aria-current="step"' ?>>
                    <span class="reconcile-step-number" aria-hidden="true">1</span>
                    <strong>Select package</strong>
                </li>
                <?php $stepTwoDone = $review && !empty($review['ready']) && !$editing; ?>
                <li class="<?= $review ? ($stepTwoDone ? 'is-complete' : 'is-current') : '' ?>"<?= $review && !$stepTwoDone ? ' aria-current="step"' : '' ?>>
                    <span class="reconcile-step-number" aria-hidden="true">2</span>
                    <strong>Review identities</strong>
                </li>
                <li class="<?= $approval ? 'is-complete' : ($stepTwoDone ? 'is-current' : '') ?>"<?= $stepTwoDone ? ' aria-current="step"' : '' ?>>
                    <span class="reconcile-step-number" aria-hidden="true">3</span>
                    <strong>Confirm restoration</strong>
                </li>
            </ol>

            <?php if ($message !== ''): ?>
                <div class="alert reconcile-alert <?= $isSuccessMessage ? 'tag-success' : 'tag-warning' ?>" role="<?= $isSuccessMessage ? 'status' : 'alert' ?>">
                    <i class="bi <?= $isSuccessMessage ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>" aria-hidden="true"></i>
                    <span><?= $escape($message) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($recordedApproval): ?>
                <section class="dashboard-section reconcile-evidence" aria-labelledby="active-evidence-title">
                    <div class="recovery-panel-heading">
                        <h2 id="active-evidence-title"><i class="bi bi-patch-check" aria-hidden="true"></i> Approved review</h2>
                        <span class="tag-success">Approved</span>
                    </div>
                    <dl class="reconcile-facts">
                        <div><dt>Reviewed by</dt><dd>Administrator #<?= (int)$recordedApproval['approved_by'] ?></dd></div>
                        <div><dt>Reviewed at</dt><dd><time><?= $escape($recordedApproval['approved_at']) ?></time></dd></div>
                        <div><dt>Package ID</dt><dd><code><?= $escape($recordedApproval['package_id']) ?></code></dd></div>
                        <div><dt>Active Administrators</dt><dd><?= (int)$recordedApproval['active_administrators'] ?></dd></div>
                    </dl>
                </section>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" action="<?= $escape(app_url('components/system_administrator/identity_reconciliation.php')) ?>" data-identity-form<?= !$review ? ' data-password-authorization' : '' ?> class="reconcile-stack">
                <?= csrf_field() ?>
                <?php if ($review): ?>
                    <input type="hidden" name="package_hash" value="<?= $escape($packageHash) ?>">
                    <?php if ($draft): ?><input type="hidden" name="review_token" value="<?= $escape($draft['token']) ?>"><?php endif; ?>
                <?php endif; ?>

                <section class="dashboard-section reconcile-package" aria-labelledby="package-heading">
                    <div class="recovery-panel-heading">
                        <h2 id="package-heading"><i class="bi bi-shield-lock" aria-hidden="true"></i> Choose backup</h2>
                        <span class="backup-hint">No Store data changes during review</span>
                    </div>

                    <?php if ($review && !empty($review['compatibility'])): $compatibility = $review['compatibility']; ?>
                        <p class="backup-hint" data-restore-compatibility="<?= $escape($compatibility['status']) ?>">
                            <span class="tag <?= $compatibility['status'] === 'current' ? 'tag-success' : ($compatibility['status'] === 'upgradable' ? 'tag-warning' : 'tag-danger') ?>"><?= $escape($compatibility['label']) ?></span>
                            <?= $escape($compatibility['message']) ?>
                            <?php if (!empty($compatibility['missing_migrations'])): ?>
                                <span>Database updates involved: <code><?= $escape(implode(', ', $compatibility['missing_migrations'])) ?></code></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($review): ?>
                        <div class="reconcile-selected" data-reviewed-package<?= $draft ? '' : ' hidden' ?>>
                            <i class="bi bi-file-earmark-zip" aria-hidden="true"></i>
                            <div>
                                <strong data-selected-package-name><?= $escape($draft['filename'] ?? '') ?></strong>
                                <?php if (isset($package)): ?><span>Snapshot <?= $escape($package['manifest']['snapshot_at']) ?> UTC. Saved for 30 minutes in this session.</span><?php endif; ?>
                            </div>
                            <a class="btn btn-secondary" href="<?= $escape(app_url('components/system_administrator/identity_reconciliation.php?new=1')) ?>">Change backup</a>
                        </div>
                    <?php endif; ?>

                    <div class="package-fields" data-package-inputs>
                        <div class="form-group"<?= $review && $draft ? ' hidden' : '' ?>>
                            <label for="identity-package">Backup package (.rmpkg)</label>
                            <div class="restore-file-field">
                                <input id="identity-package" type="file" name="backup_file" accept=".rmpkg"<?= $review && $draft ? '' : ' required' ?> aria-describedby="identity-upload-help">
                            </div>
                            <p class="restore-file-hint" id="identity-upload-help">Maximum <?= (int)floor($restorer->supportedUploadBytes() / 1048576) ?> MB</p>
                        </div>
                        <div class="form-group">
                            <label for="identity-password">Current login password</label>
                            <input id="identity-password" type="password" name="restore_password" autocomplete="current-password" required>
                        </div>
                    </div>
                </section>

                <?php if ($review): ?>
                    <div data-identity-choices<?= !empty($review['ready']) && !$editing ? ' hidden' : '' ?>>
                    <section class="dashboard-section" aria-labelledby="source-resolutions-heading">
                        <div class="recovery-panel-heading">
                            <h2 id="source-resolutions-heading"><i class="bi bi-person-gear" aria-hidden="true"></i> Accounts in the backup</h2>
                            <span class="history-total"><?= count($review['source_users']) ?> <?= count($review['source_users']) === 1 ? 'identity' : 'identities' ?></span>
                        </div>
                        <p class="backup-hint">Match each person to one account on this server.</p>
                        <div class="resolution-list">
                            <?php foreach ($review['source_users'] as $person):
                                $id = (int)$person['user_id'];
                                $choice = $decisions[$id] ?? [];
                            ?>
                                <fieldset class="resolution-card">
                                    <legend class="sr-only">Backup account #<?= $id ?>: <?= $escape($person['display_name']) ?></legend>
                                    <div class="resolution-head">
                                        <div>
                                            <h3><span class="reconcile-id">#<?= $id ?></span> <?= $escape($person['display_name']) ?></h3>
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
                                            <p><strong>Historical Actor only.</strong> History is kept; login access is not imported.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="form-group resolution-question">
                                            <label for="resolution-<?= $id ?>">How they sign in</label>
                                            <select id="resolution-<?= $id ?>" name="people[<?= $id ?>][action]" data-account-action required>
                                                <option value="">Choose how they sign in</option>
                                                <option value="link" <?= ($choice['action'] ?? '') === 'link' ? 'selected' : '' ?>>Use their existing account here</option>
                                                <option value="distinct" <?= ($choice['action'] ?? '') === 'distinct' ? 'selected' : '' ?>>Create a separate account</option>
                                            </select>
                                        </div>
                                        <div data-account-fields="link">
                                            <div class="form-group">
                                                <label for="destination-<?= $id ?>">Their account on this server</label>
                                                <select id="destination-<?= $id ?>" name="people[<?= $id ?>][destination_id]">
                                                    <option value="">Choose their existing account</option>
                                                    <?php foreach ($review['destination_users'] as $target): ?>
                                                        <option value="<?= (int)$target['user_id'] ?>" <?= (int)($choice['destination_id'] ?? 0) === (int)$target['user_id'] ? 'selected' : '' ?>>
                                                            #<?= (int)$target['user_id'] ?>: <?= $escape($target['display_name']) ?> (<?= $escape($target['username']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <p class="backup-hint">Keeps that account's password and access.</p>
                                        </div>
                                        <div data-account-fields="distinct">
                                        <p class="backup-hint">Super Administrator and Recovery access are not imported.</p>
                                        <div class="resolution-grid">
                                            <div class="form-group">
                                                <label for="username-<?= $id ?>">New username</label>
                                                <input id="username-<?= $id ?>" name="people[<?= $id ?>][username]" maxlength="80" value="<?= $escape($choice['username'] ?? '') ?>" autocomplete="off" placeholder="New username">
                                            </div>
                                            <div class="form-group">
                                                <label for="email-<?= $id ?>">Email (optional)</label>
                                                <input id="email-<?= $id ?>" type="email" name="people[<?= $id ?>][email]" maxlength="120" value="<?= $escape($choice['email'] ?? '') ?>" autocomplete="off" spellcheck="false" placeholder="user@example.com">
                                            </div>
                                        </div>
                                        <label class="resolution-option" for="admin-<?= $id ?>">
                                            <input type="checkbox" id="admin-<?= $id ?>" name="people[<?= $id ?>][administrator]" value="1" <?= !empty($choice['administrator']) ? 'checked' : '' ?>>
                                            <span>
                                                Make this new account an Administrator
                                                <small>Imported roles: <strong><?= $escape(implode(', ', $person['ordinary_roles'])) ?></strong>.</small>
                                            </span>
                                        </label>
                                        </div>
                                    <?php endif; ?>
                                </fieldset>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <details class="reconcile-details">
                        <summary>View server accounts and matching details</summary>
                    <div class="reconcile-reference">
                    <section class="dashboard-section" aria-labelledby="dest-people-heading">
                        <div class="recovery-panel-heading">
                            <h2 id="dest-people-heading"><i class="bi bi-people" aria-hidden="true"></i> Accounts on this server</h2>
                            <span class="history-total"><?= count($review['destination_users']) ?> <?= count($review['destination_users']) === 1 ? 'account' : 'accounts' ?></span>
                        </div>
                        <div class="table-wrap reconcile-table" tabindex="0" role="region" aria-label="Accounts on this server">
                            <table data-no-smart-table>
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
                                            <td><span class="reconcile-id">#<?= $escape($person['user_id']) ?></span> <?= $escape($person['display_name']) ?></td>
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
                            <h2 id="conflicts-heading"><i class="bi bi-exclamation-diamond" aria-hidden="true"></i> Matching details</h2>
                            <span class="history-total"><?= count($review['conflicts']) ?> <?= count($review['conflicts']) === 1 ? 'conflict' : 'conflicts' ?></span>
                        </div>
                        <?php if (!empty($review['conflicts'])): ?>
                            <ul class="reconcile-conflicts">
                                <?php foreach ($review['conflicts'] as $conflict): ?>
                                    <li>Backup <strong>#<?= $escape($conflict['source_id']) ?></strong> and server account <strong>#<?= $escape($conflict['destination_id']) ?></strong> share <span class="tag-warning"><?= $escape($conflict['kind']) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="backup-hint"><i class="bi bi-check2-circle" aria-hidden="true"></i> No matching IDs or login details.</p>
                        <?php endif; ?>
                    </section>

                    </div>
                    </details>
                    </div>
                    <?php if ($review['ready'] && !$editing): ?>
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
                        <details class="reconcile-details reconcile-preserved" aria-labelledby="preservation-heading">
                            <summary id="preservation-heading">Security and settings preserved</summary>
                            <div class="table-wrap reconcile-table" tabindex="0" role="region" aria-label="Preservation categories">
                                <table data-no-smart-table>
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
                        </details>

                        <section class="dashboard-section" aria-labelledby="projection-heading">
                            <div class="recovery-panel-heading">
                                <h2 id="projection-heading"><i class="bi bi-person-check" aria-hidden="true"></i> Access after restore</h2>
                                <span class="tag-success"><?= (int)$review['active_administrators'] ?> active <?= (int)$review['active_administrators'] === 1 ? 'Administrator' : 'Administrators' ?></span>
                            </div>
                            <p class="backup-hint">Only linked accounts gain login access from the backup.</p>
                            <div class="table-wrap reconcile-table" tabindex="0" role="region" aria-label="Resulting access">
                                <table data-no-smart-table>
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
                                                <td><span class="tag-neutral"><?= $escape(match ($acc['classification']) {
                                                    'destination_linked' => 'Existing server account',
                                                    'imported_distinct' => 'Separate account',
                                                    'evidence_only' => 'History only',
                                                    'historical' => 'Historical Actor',
                                                    'protected' => 'Protected server account',
                                                    default => $acc['classification'],
                                                }) ?></span></td>
                                                <td>
                                                    <?php if ($acc['usable_access']): ?>
                                                        <span class="tag-success"><i class="bi bi-check-circle" aria-hidden="true"></i> Active login</span>
                                                    <?php else: ?>
                                                        <span class="tag-warning"><i class="bi bi-slash-circle" aria-hidden="true"></i> No login · history only</span>
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
                            </section>
                        <?php else: ?>
                            <section class="dashboard-section reconcile-confirm" aria-label="Confirm restoration">
                                <div class="recovery-panel-heading">
                                    <h2><i class="bi bi-shield-check" aria-hidden="true"></i> Confirm restoration</h2>
                                </div>
                                <label class="resolution-option" for="confirm_replacement">
                                    <input type="checkbox" id="confirm_replacement" name="confirm_replacement" value="1" required>
                                    <span>I understand this backup replaces newer sales and records. Stop sales and background jobs before restoring.</span>
                                </label>
                                <div class="reconcile-actions">
                                    <button class="btn btn-danger" type="submit" formaction="<?= $escape(app_url('components/backup/restore.php')) ?>" data-start-restore>
                                        <i class="bi bi-shield-check" aria-hidden="true"></i> Restore and verify
                                    </button>
                                    <button class="btn btn-secondary" type="submit" name="action" value="edit" formnovalidate>
                                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Edit account choices
                                    </button>
                                </div>
                            </section>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$review || empty($review['ready']) || $editing): ?>
                    <div class="reconcile-actions">
                        <button class="btn btn-primary" type="submit" name="action" value="review">
                            <i class="bi bi-search" aria-hidden="true"></i> <?= $review ? 'Review resolutions' : 'Review identities' ?>
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
<script src="<?= $escape(app_url('assets/js/backup_review.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/backup_review.js')) ?>"></script>
</body>
</html>
