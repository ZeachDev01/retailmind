<?php
// Browser recovery console and status (#311, #312, #313).
// Renders and authenticates independently of database tables.
require_once dirname(__DIR__, 3) . '/backend/bootstrap/app.php';
require_once dirname(__DIR__, 3) . '/backend/includes/backup.php';

use App\Backup\RecoveryStore;
use App\Backup\RecoveryAuthService;
use App\Backup\DatabaseRestoreService;
use App\Backup\DatabaseBackupService;
use App\Authorization\RoleCapabilityPolicy;

App\Core\Session::start();
require_once dirname(__DIR__, 3) . '/backend/includes/csrf.php';
require_once dirname(__DIR__, 3) . '/backend/includes/theme.php';

if (!function_exists('app_base_url')) {
    function app_base_url(): string {
        $documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
        if ($documentRoot === '' || $projectRoot === '') return '';
        if (strpos($projectRoot, $documentRoot) === 0) {
            $basePath = substr($projectRoot, strlen($documentRoot));
            return $basePath === '' ? '' : '/' . trim($basePath, '/');
        }
        return '';
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string {
        $baseUrl = app_base_url();
        $normalizedPath = ltrim($path, '/');
        if ($normalizedPath === '') return $baseUrl === '' ? '/' : $baseUrl . '/';
        return ($baseUrl === '' ? '' : $baseUrl) . '/' . $normalizedPath;
    }
}

$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$error = '';
$notice = (string)($_SESSION['_recovery_notice'] ?? '');
unset($_SESSION['_recovery_notice']);
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

if (!isset($_SESSION['_recovery_login_csrf'])) {
    $_SESSION['_recovery_login_csrf'] = bin2hex(random_bytes(16));
}

$session = RecoveryAuthService::currentRecoverySession();
$action = (string)($_POST['action'] ?? '');

$getPdo = static fn(): PDO => \App\Core\Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'authenticate') {
            $token = (string)($_POST['csrf_token'] ?? '');
            if (!hash_equals((string)($_SESSION['_recovery_login_csrf'] ?? ''), $token)) {
                throw new DomainException('Invalid recovery request token.');
            }
            $username = (string)($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $authData = RecoveryAuthService::authenticate($username, $password, $clientIp);
            RecoveryAuthService::startRecoverySession($authData);
            $_SESSION['_recovery_login_csrf'] = bin2hex(random_bytes(16));
            $session = RecoveryAuthService::currentRecoverySession();
            $notice = 'Signed in to recovery.';
        } elseif ($action === 'logout') {
            if (!RecoveryAuthService::verifyCsrf((string)($_POST['recovery_csrf'] ?? ''))) {
                throw new DomainException('Invalid recovery session token.');
            }
            RecoveryAuthService::clearRecoverySession();
            $session = null;
            $notice = 'Recovery session ended.';
        } elseif ($action === 'recover_safety') {
            if (!$session) {
                $username = (string)($_POST['username'] ?? '');
                $password = (string)($_POST['password'] ?? '');
                $token = (string)($_POST['csrf_token'] ?? '');
                if (!hash_equals((string)($_SESSION['_recovery_login_csrf'] ?? ''), $token)) {
                    throw new DomainException('Invalid recovery request token.');
                }
                $authData = RecoveryAuthService::authenticate($username, $password, $clientIp);
                RecoveryAuthService::startRecoverySession($authData);
                $session = RecoveryAuthService::currentRecoverySession();
            } else {
                if (!RecoveryAuthService::verifyCsrf((string)($_POST['recovery_csrf'] ?? ''))) {
                    throw new DomainException('Invalid recovery session token.');
                }
            }
            $state = RecoveryStore::state();
            $safetyOpId = (string)($state['safety_operation_id'] ?? '');
            if ($safetyOpId === '') {
                throw new DomainException('No safety operation bound to active recovery.');
            }

            $pdo = $getPdo();
            $policy = new RoleCapabilityPolicy();
            $backups = new DatabaseBackupService($pdo, $policy);
            $restore = new DatabaseRestoreService($pdo, $policy, $backups);
            $result = $restore->recoverSafety((int)$session['user_id'], (string)$session['role'], $safetyOpId);
            $notice = 'Complete safety recovery finished successfully. Database and uploads rolled back to verified original. Store is pending operator review.';
        } elseif ($action === 'restart_incoming') {
            if (!$session) {
                $username = (string)($_POST['username'] ?? '');
                $password = (string)($_POST['password'] ?? '');
                $token = (string)($_POST['csrf_token'] ?? '');
                if (!hash_equals((string)($_SESSION['_recovery_login_csrf'] ?? ''), $token)) {
                    throw new DomainException('Invalid recovery request token.');
                }
                $authData = RecoveryAuthService::authenticate($username, $password, $clientIp);
                RecoveryAuthService::startRecoverySession($authData);
                $session = RecoveryAuthService::currentRecoverySession();
            } else {
                if (!RecoveryAuthService::verifyCsrf((string)($_POST['recovery_csrf'] ?? ''))) {
                    throw new DomainException('Invalid recovery session token.');
                }
            }

            $packagePath = null;
            if (isset($_FILES['package_file']) && $_FILES['package_file']['error'] === UPLOAD_ERR_OK && is_uploaded_file($_FILES['package_file']['tmp_name'])) {
                $packagePath = $_FILES['package_file']['tmp_name'];
            } else {
                throw new DomainException('Upload the approved encrypted Store package (.rmpkg).');
            }

            $pdo = $getPdo();
            $policy = new RoleCapabilityPolicy();
            $backups = new DatabaseBackupService($pdo, $policy);
            $restore = new DatabaseRestoreService($pdo, $policy, $backups);
            $result = $restore->restartIncoming((int)$session['user_id'], (string)$session['role'], $packagePath);
            $notice = 'Approved incoming replacement restarted from the beginning. Verification passed. Store is pending operator review.';
        } elseif ($action === 'run_verification') {
            if (!$session) {
                throw new DomainException('Operator authentication required.');
            }
            if (!RecoveryAuthService::verifyCsrf((string)($_POST['recovery_csrf'] ?? ''))) {
                throw new DomainException('Invalid recovery session token.');
            }
            $pdo = $getPdo();
            $policy = new RoleCapabilityPolicy();
            $backups = new DatabaseBackupService($pdo, $policy);
            $restore = new DatabaseRestoreService($pdo, $policy, $backups);
            $vResult = $restore->runVerificationChecks();
            $restore->recordReviewChecks($vResult['checks']);
            $notice = 'Verification checks updated successfully. All automated integrity checks completed.';
        } elseif ($action === 'confirm_checklist') {
            if (!$session) {
                throw new DomainException('Operator authentication required.');
            }
            if (!RecoveryAuthService::verifyCsrf((string)($_POST['recovery_csrf'] ?? ''))) {
                throw new DomainException('Invalid recovery session token.');
            }
            $pdo = $getPdo();
            $policy = new RoleCapabilityPolicy();
            $backups = new DatabaseBackupService($pdo, $policy);
            $restore = new DatabaseRestoreService($pdo, $policy, $backups);
            foreach (['stock', 'receipt', 'upload', 'attribution'] as $key) {
                $restore->confirmChecklistItem((int)$session['user_id'], (string)$session['role'], $key, !empty($_POST['confirm_' . $key]));
            }
            $notice = 'Checklist confirmations saved.';
        } elseif ($action === 'reopen') {
            if (!$session) {
                throw new DomainException('Operator authentication required.');
            }
            if (!RecoveryAuthService::verifyCsrf((string)($_POST['recovery_csrf'] ?? ''))) {
                throw new DomainException('Invalid recovery session token.');
            }

            $currentStage = RecoveryStore::currentStage();
            if ($currentStage !== RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING) {
                throw new DomainException('Store can only be reopened from operator review pending stage.');
            }
            $operatorPassword = (string)($_POST['operator_password'] ?? '');
            if ($operatorPassword === '') {
                throw new DomainException('Fresh operator password verification is required to reopen the Store.');
            }
            $opId = (string)($_POST['operation_id'] ?? '');
            if (empty($_POST['confirm_one_active'])) {
                throw new DomainException('You must confirm that recovery completion reopens a single active Store.');
            }
            $pdo = $getPdo();
            $policy = new RoleCapabilityPolicy();
            $backups = new DatabaseBackupService($pdo, $policy);
            $restore = new DatabaseRestoreService($pdo, $policy, $backups);
            $result = $restore->reopenStore((int)$session['user_id'], (string)$session['role'], $operatorPassword, $opId);
            RecoveryAuthService::clearRecoverySession();
            $session = null;
            $notice = 'Recovery completed successfully. Store access has been resumed for all operators.';
        }
        if ($notice !== '' && $action !== 'reopen') {
            $_SESSION['_recovery_notice'] = $notice;
            header('Location: ' . app_url('components/backup/recovery.php'), true, 303);
            exit;
        }
    } catch (DomainException | RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Recovery exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        $error = 'A recovery error occurred: ' . $e->getMessage();
    }
}

$isPaused = RecoveryStore::isPaused();
$viewData = RecoveryAuthService::recoveryViewData($session);
$reopenedRecord = RecoveryStore::reopenedRecord();

$readiness = \App\Backup\StoreReopening::readiness($isPaused ? RecoveryStore::state() : null);
$reviewChecks = $viewData['review_checks'] ?? [];
$automatedChecks = $reviewChecks['automated'] ?? [];
$humanChecks = $reviewChecks['human_confirmations'] ?? [];
$confirmedCount = count(array_filter(['stock', 'receipt', 'upload', 'attribution'], static fn($key) => !empty($humanChecks[$key]['confirmed'])));
$checklistComplete = $confirmedCount === 4;
$nextAction = $viewData['next_permitted_action'] ?? '';
if ($viewData['stage'] === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING) {
    $nextAction = $readiness['ready'] ? 'Enter your password and reopen Store access.'
        : ($checklistComplete ? 'Run checks again and review any failures.' : 'Review the results and save your checklist.');
}

$canReopen = !empty($session['is_initiator']) || in_array(($session['role'] ?? ''), ['admin', 'super_admin'], true);
$isTakeover = !empty($session) && empty($session['is_initiator']);
?>
<!doctype html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Recovery - RetailMind</title>
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/recovery.css')) ?>">
</head>
<body class="recovery-console">
<main class="rc-main">
    <div class="rc-glow rc-glow-top" aria-hidden="true"></div>
    <div class="rc-glow rc-glow-bottom" aria-hidden="true"></div>
    <div class="rc-grid-bg" aria-hidden="true"></div>

    <div class="rc-brand">
        <img class="rc-brand-logo" src="<?= $escape(app_url('assets/img/retailmind-web-assets%20dark%20mode/logos/light/logo-header.png')) ?>" data-brand-base="<?= $escape(app_url('assets/img/retailmind-web-assets%20dark%20mode/')) ?>" data-brand-variant="logos/STYLE/logo-header.png" alt="RetailMind" height="44">
    </div>

    <?php if ($error !== ''): ?>
        <div class="rc-alert rc-alert-error" role="alert" data-recovery-alert="error">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span><?= $escape($error) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($notice !== ''): ?>
        <div class="rc-alert rc-alert-notice" role="status" data-recovery-alert="notice">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <span><?= $escape($notice) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!$isPaused): ?>
        <section class="rc-card rc-card--narrow" data-recovery-card="idle" aria-labelledby="recovery-title-idle">
            <div class="rc-head">
                <div class="rc-icon" aria-hidden="true"><i class="bi bi-shield-check"></i></div>
                <span class="rc-badge rc-badge-online" data-recovery-status="online"><span class="rc-dot"></span>Store online</span>
            </div>

            <h1 id="recovery-title-idle" class="rc-title">No recovery needed</h1>
            <p class="rc-sub">Store access is open.</p>

            <?php if ($reopenedRecord): ?>
                <details class="rc-disclosure" data-recovery-reopened-evidence>
                    <summary>Last reopening</summary>
                    <dl class="rc-facts">
                        <div><dt>Reopened at</dt><dd><?= $escape($reopenedRecord['reopened_at'] ?? 'unknown') ?></dd></div>
                        <div><dt>Reopened by</dt><dd><?= $escape($reopenedRecord['reopened_by']['username'] ?? 'unknown') ?> (<?= $escape($reopenedRecord['reopened_by']['role'] ?? '') ?><?= !empty($reopenedRecord['reopened_by']['is_initiator']) ? ', Initiator' : '' ?>)</dd></div>
                        <div><dt>Path completed</dt><dd><?= $escape($reopenedRecord['path_completed'] ?? 'unknown') ?></dd></div>
                        <div><dt>One active Store</dt><dd>Verified (dual active Stores prohibited)</dd></div>
                        <div><dt>Session epoch</dt><dd><?= $escape(substr((string)($reopenedRecord['session_epoch'] ?? ''), 0, 16)) ?>...</dd></div>
                    </dl>
                </details>
            <?php endif; ?>

            <div class="rc-actions">
                <a href="<?= $escape(app_url('components/system_administrator/backup_restore.php')) ?>" class="rc-btn rc-btn-quiet">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Backup &amp; Restore
                </a>
            </div>
        </section>
    <?php elseif (!$session): ?>
        <section class="rc-card rc-card--narrow" data-recovery-card="login" aria-labelledby="recovery-title-login">
            <div class="rc-head">
                <div class="rc-icon" aria-hidden="true"><i class="bi bi-shield-lock-fill"></i></div>
                <span class="rc-badge rc-badge-paused" data-recovery-status="paused"><span class="rc-dot"></span>Maintenance active</span>
            </div>

            <h1 id="recovery-title-login" class="rc-title">Sign in to recovery</h1>
            <p class="rc-sub">Use this server's administrator credentials from before the restore.</p>

            <form method="post" class="rc-form" data-recovery-form="auth">
                <input type="hidden" name="action" value="authenticate">
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['_recovery_login_csrf']) ?>">

                <label class="rc-label" for="recovery-username">Username</label>
                <input class="rc-input" id="recovery-username" name="username" type="text" required autocomplete="username">

                <label class="rc-label" for="recovery-password">Password</label>
                <input class="rc-input" id="recovery-password" name="password" type="password" required autocomplete="current-password">

                <button type="submit" class="rc-btn rc-btn-primary rc-btn-block" data-recovery-submit="login">
                    <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Sign in
                </button>
            </form>

            <details class="rc-disclosure" data-recovery-login-details>
                <summary>Recovery details</summary>
                <dl class="rc-facts">
                    <div><dt>Stage</dt><dd><span data-recovery-stage="<?= $escape($viewData['stage']) ?>" data-recovery-current-stage="<?= $escape($viewData['workflow_stage'] ?? '') ?>"><?= $escape($viewData['workflow_stage_label'] ?? $viewData['stage']) ?></span></dd></div>
                    <div><dt>Next action</dt><dd><span data-recovery-next-action><?= $escape($viewData['next_permitted_action'] ?? '') ?></span></dd></div>
                    <div><dt>Operation ID</dt><dd><?= $escape($viewData['operation_id']) ?></dd></div>
                </dl>
                <p class="rc-note">Sealed Recovery Accounts must be unsealed separately and cannot sign in here.</p>
            </details>
        </section>
    <?php else: ?>
        <section class="rc-card" data-recovery-card="authenticated" aria-labelledby="recovery-title-auth">
            <div class="rc-head">
                <div class="rc-icon" aria-hidden="true"><i class="bi bi-shield-check"></i></div>
                <span class="rc-badge rc-badge-paused" data-recovery-status="paused"><span class="rc-dot"></span>Maintenance active</span>
            </div>

            <div class="rc-title-row">
                <div>
                    <h1 id="recovery-title-auth" class="rc-title">Recovery review</h1>
                    <p class="rc-sub"><?= $escape($session['username']) ?> · <?= $escape(str_replace('_', ' ', $session['role'])) ?></p>
                </div>
                <form method="post" data-recovery-form="logout">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">
                    <button type="submit" class="rc-btn rc-btn-quiet rc-btn-sm btn-quiet" data-recovery-submit="logout">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out
                    </button>
                </form>
            </div>

            <div class="rc-meta">
                <div>
                    <div class="rc-meta-label">Stage</div>
                    <div class="rc-meta-value" data-recovery-stage="<?= $escape($viewData['stage']) ?>" data-recovery-current-stage="<?= $escape($viewData['workflow_stage'] ?? '') ?>"><?= $escape($viewData['workflow_stage_label'] ?? $viewData['stage']) ?></div>
                </div>
                <div>
                    <div class="rc-meta-label">Next action</div>
                    <div class="rc-meta-value" data-recovery-next-action><?= $escape($nextAction) ?></div>
                </div>
            </div>

            <?php if ($viewData['stage'] === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING): ?>
                <div class="rc-block" data-recovery-card="review">
                    <details class="rc-disclosure rc-disclosure--flush" data-recovery-results <?= !$readiness['ready'] ? 'open' : '' ?>>
                        <summary>Verification results</summary>

                        <div class="rc-check-head">
                            <h2 class="rc-h3">Automated checks</h2>
                            <form method="post">
                                <input type="hidden" name="action" value="run_verification">
                                <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">
                                <button type="submit" class="rc-btn rc-btn-quiet rc-btn-sm btn-quiet" data-recovery-submit="rerun-checks">Run checks again</button>
                            </form>
                        </div>

                        <?php
                        $reviewCheckLabels = [
                            'stock' => 'Stock & Batch Integrity',
                            'receipt' => 'Receipt & Subtotal Verification',
                            'upload' => 'Upload & Image Assets',
                            'attribution' => 'Identity & Attribution',
                        ];
                        ?>
                        <div class="rc-checks">
                            <?php foreach ($reviewCheckLabels as $checkKey => $checkLabel): ?>
                                <?php $checkPassed = ($automatedChecks[$checkKey]['status'] ?? '') === 'passed'; ?>
                                <div class="rc-check" data-recovery-check="<?= $escape($checkKey) ?>">
                                    <div class="rc-check-top">
                                        <span><?= $escape($checkLabel) ?></span>
                                        <span class="rc-chip<?= $checkPassed ? ' rc-chip-pass' : '' ?>"><?= strtoupper($escape($automatedChecks[$checkKey]['status'] ?? 'pending')) ?></span>
                                    </div>
                                    <p><?= $escape($automatedChecks[$checkKey]['details'] ?? 'Pending check execution.') ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="rc-checks">
                            <div class="rc-check rc-check--plain" data-recovery-preview="signin">
                                <div class="rc-check-top"><span>Active accounts</span></div>
                                <?php if (!empty($automatedChecks['signin_preview']['accounts'])): ?>
                                    <div class="rc-tags">
                                        <?php foreach ($automatedChecks['signin_preview']['accounts'] as $acc): ?>
                                            <span class="rc-tag"><?= $escape($acc['username']) ?> (<?= $escape($acc['role_name']) ?>)</span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p>No active accounts.</p>
                                <?php endif; ?>
                            </div>

                            <div class="rc-check rc-check--plain" data-recovery-preview="report">
                                <div class="rc-check-top"><span>Store summary</span></div>
                                <?php if (!empty($automatedChecks['report']['metrics'])): ?>
                                    <dl class="rc-facts">
                                        <div><dt>Sales</dt><dd><?= (int)($automatedChecks['report']['metrics']['sales_count'] ?? 0) ?></dd></div>
                                        <div><dt>Total</dt><dd><?= number_format((float)($automatedChecks['report']['metrics']['total_revenue'] ?? 0), 2) ?></dd></div>
                                        <div><dt>Products</dt><dd><?= (int)($automatedChecks['report']['metrics']['products_count'] ?? 0) ?></dd></div>
                                    </dl>
                                <?php else: ?>
                                    <p>No summary available.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </details>

                    <details class="rc-disclosure" data-recovery-card="checklist" <?= !$checklistComplete ? 'open' : '' ?>>
                        <summary>Review checklist · <?= $confirmedCount ?> of 4 saved<?= $checklistComplete ? ' — complete' : '' ?></summary>
                        <form method="post" class="rc-form rc-form--tight" data-recovery-form="checklist">
                            <input type="hidden" name="action" value="confirm_checklist">
                            <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">

                            <label class="rc-check-item">
                                <input type="checkbox" name="confirm_stock" value="1" data-recovery-confirm="stock" <?= !empty($humanChecks['stock']['confirmed']) ? 'checked' : '' ?>>
                                <span>Stock counts and batches reviewed; no negative stock.</span>
                            </label>
                            <label class="rc-check-item">
                                <input type="checkbox" name="confirm_receipt" value="1" data-recovery-confirm="receipt" <?= !empty($humanChecks['receipt']['confirmed']) ? 'checked' : '' ?>>
                                <span>Receipt subtotals match recorded sales.</span>
                            </label>
                            <label class="rc-check-item">
                                <input type="checkbox" name="confirm_upload" value="1" data-recovery-confirm="upload" <?= !empty($humanChecks['upload']['confirmed']) ? 'checked' : '' ?>>
                                <span>Product and profile images reviewed.</span>
                            </label>
                            <label class="rc-check-item">
                                <input type="checkbox" name="confirm_attribution" value="1" data-recovery-confirm="attribution" <?= !empty($humanChecks['attribution']['confirmed']) ? 'checked' : '' ?>>
                                <span>Active accounts and record attribution reviewed.</span>
                            </label>

                            <button type="submit" class="rc-btn rc-btn-primary" data-recovery-submit="checklist">Save checklist</button>
                        </form>
                    </details>

                    <div class="rc-panel rc-panel--warning" data-recovery-card="reopen">
                        <h2 class="rc-h3">Reopen Store access</h2>
                        <p class="rc-note">Resumes Store activity and signs out existing sessions.</p>

                        <?php if (!$readiness['ready']): ?>
                            <div class="rc-guidance" role="alert" data-recovery-alert="guidance">
                                <p>Save all four confirmations. Automated checks must pass within the last 15 minutes.</p>
                                <details class="rc-disclosure rc-disclosure--flush">
                                    <summary>Pending checks</summary>
                                    <ul class="rc-list">
                                        <?php foreach ($readiness['missing'] as $miss): ?>
                                            <li><?= $escape($miss) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            </div>
                        <?php endif; ?>

                        <?php if ($canReopen): ?>
                            <?php if ($isTakeover): ?>
                                <p class="rc-note rc-note--strong" data-recovery-takeover-banner>
                                    Initiating administrator unavailable. You are signed in as <?= $escape($session['role']) ?> (<?= $escape($session['username']) ?>); reopening is attributed to you.
                                </p>
                            <?php endif; ?>
                            <form method="post" class="rc-form" data-recovery-form="reopen" onsubmit="return confirm('Explicitly complete recovery and reopen Store access to all users?');">
                                <input type="hidden" name="action" value="reopen">
                                <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">
                                <input type="hidden" name="operation_id" value="<?= $escape($viewData['operation_id']) ?>">

                                <label class="rc-label" for="reopen-operator-password">Your password on this server</label>
                                <input class="rc-input rc-input--short" id="reopen-operator-password" name="operator_password" type="password" required autocomplete="current-password">

                                <label class="rc-check-item">
                                    <input id="confirm-one-active" type="checkbox" name="confirm_one_active" value="1" required>
                                    <span>Recovery is complete. This is the only active Store.</span>
                                </label>

                                <button type="submit" class="rc-btn rc-btn-warning" data-recovery-submit="reopen">
                                    <i class="bi bi-unlock" aria-hidden="true"></i> Reopen Store access
                                </button>
                            </form>
                        <?php else: ?>
                            <p class="rc-note">Only an authorized Administrator or destination Super Administrator can reopen the Store.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <details class="rc-disclosure" data-recovery-actions <?= $viewData['stage'] !== RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING ? 'open' : '' ?>>
                <summary>Other recovery options</summary>
                <div class="rc-panels">
                    <div class="rc-panel" data-recovery-card="safety">
                        <h2 class="rc-h3">Restore safety copy</h2>
                        <p class="rc-note">Replaces current data with this server's pre-restore copy.</p>
                        <form method="post" class="rc-form rc-form--tight" data-recovery-form="safety">
                            <input type="hidden" name="action" value="recover_safety">
                            <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">
                            <button type="submit" class="rc-btn rc-btn-quiet btn-quiet" data-recovery-submit="safety">Restore safety copy</button>
                        </form>
                    </div>

                    <div class="rc-panel" data-recovery-card="incoming">
                        <h2 class="rc-h3">Restart incoming restore</h2>
                        <p class="rc-note">Replaces current data using the approved package and mappings.</p>
                        <form method="post" enctype="multipart/form-data" class="rc-form rc-form--tight" data-recovery-form="incoming">
                            <input type="hidden" name="action" value="restart_incoming">
                            <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">
                            <label class="rc-label" for="incoming-file">Approved package (.rmpkg)</label>
                            <input class="rc-input rc-input--file" id="incoming-file" name="package_file" type="file" accept=".rmpkg" required>
                            <button type="submit" class="rc-btn rc-btn-quiet btn-quiet" data-recovery-submit="incoming">Restart incoming restore</button>
                        </form>
                    </div>
                </div>
            </details>

            <details class="rc-disclosure">
                <summary>Recovery history &amp; operation ID</summary>
                <p class="rc-code" data-recovery-opid="<?= $escape($viewData['operation_id']) ?>"><?= $escape($viewData['operation_id']) ?></p>
                <ol class="rc-timeline" data-recovery-timeline>
                    <?php foreach ($viewData['stages'] as $step): ?>
                        <li><span><?= $escape($step['stage']) ?></span><span><?= $escape($step['time']) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            </details>

            <?php if ($session['role'] === 'super_admin'): ?>
                <details class="rc-disclosure" data-recovery-super-evidence>
                    <summary>Security &amp; recovery details</summary>
                    <dl class="rc-facts rc-facts--code">
                        <div><dt>Watermark</dt><dd><?= $escape($viewData['destination_watermark']) ?></dd></div>
                        <div><dt>Safety operation ID</dt><dd><?= $escape($viewData['safety_operation_id']) ?></dd></div>
                        <div><dt>Approved package ID</dt><dd><?= $escape($viewData['package_id']) ?></dd></div>
                    </dl>

                    <?php if (!empty($viewData['append_log'])): ?>
                        <details class="rc-disclosure rc-disclosure--nested">
                            <summary>Operation outcomes (<?= count($viewData['append_log']) ?>)</summary>
                            <pre class="rc-pre" data-recovery-append-log><?= $escape(json_encode($viewData['append_log'], JSON_PRETTY_PRINT)) ?></pre>
                        </details>
                    <?php endif; ?>
                </details>
                <?php if ($viewData['stage'] !== RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING): ?>
                    <p class="rc-note rc-note--warning">Complete a recovery option above before reviewing and reopening Store access.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <footer class="rc-foot">RetailMind &copy; <?= date('Y') ?></footer>
</main>
</body>
</html>
