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
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/backup.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/recovery.css')) ?>">
</head>
<body class="recovery-console">
<div class="recovery-shell">
    <header class="mb-6 border-b border-slate-200 pb-4 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Database Recovery</h1>
        </div>
        <div>
            <?php if ($isPaused): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800" data-recovery-status="paused">
                    Maintenance Active
                </span>
            <?php else: ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800" data-recovery-status="online">
                    Store Online
                </span>
            <?php endif; ?>
        </div>
    </header>

    <main class="space-y-6">
        <?php if ($error !== ''): ?>
            <div class="p-4 rounded-md bg-rose-50 border border-rose-200 text-rose-800 text-sm" role="alert" data-recovery-alert="error">
                <?= $escape($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($notice !== ''): ?>
            <div class="p-4 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm" role="status" data-recovery-alert="notice">
                <?= $escape($notice) ?>
            </div>
        <?php endif; ?>

        <?php if (!$isPaused): ?>
            <section class="bg-white p-6 rounded-lg shadow-sm border border-slate-200 space-y-4" data-recovery-card="idle">
                <h2 class="text-lg font-semibold text-slate-900">No recovery needed</h2>
                <p class="text-slate-600 text-sm">Store access is open.</p>

                <?php if ($reopenedRecord): ?>
                    <details class="text-xs space-y-1 text-slate-700" data-recovery-reopened-evidence>
                        <summary>Last reopening details</summary>
                        <div><strong>Reopened At:</strong> <?= $escape($reopenedRecord['reopened_at'] ?? 'unknown') ?></div>
                        <div><strong>Reopened By:</strong> <?= $escape($reopenedRecord['reopened_by']['username'] ?? 'unknown') ?> (<?= $escape($reopenedRecord['reopened_by']['role'] ?? '') ?><?= !empty($reopenedRecord['reopened_by']['is_initiator']) ? ', Initiator' : '' ?>)</div>
                        <div><strong>Path Completed:</strong> <?= $escape($reopenedRecord['path_completed'] ?? 'unknown') ?></div>
                        <div><strong>One Active Store:</strong> Verified (Dual active stores prohibited)</div>
                        <div><strong>Session Epoch:</strong> <?= $escape(substr((string)($reopenedRecord['session_epoch'] ?? ''), 0, 16)) ?>...</div>
                    </details>
                <?php endif; ?>

                <a href="<?= $escape(app_url('components/system_administrator/backup_restore.php')) ?>" class="inline-flex items-center px-4 py-2 border border-slate-300 rounded-md shadow-sm text-sm font-medium text-slate-700 bg-white hover:bg-slate-50">
                    Return to Backup &amp; Restore
                </a>
            </section>
        <?php elseif (!$session): ?>
            <section class="bg-white p-6 rounded-lg shadow-sm border border-slate-200 space-y-4" data-recovery-card="login">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Sign in to recovery</h2>
                    <p class="text-slate-600 text-sm">
                        Use this server’s administrator credentials from before the restore. Store access remains paused.
                    </p>
                </div>

                <form method="post" class="space-y-4" data-recovery-form="auth">
                    <input type="hidden" name="action" value="authenticate">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['_recovery_login_csrf']) ?>">

                    <div>
                        <label for="recovery-username" class="block text-sm font-medium text-slate-700 mb-1">Username</label>
                        <input id="recovery-username" name="username" type="text" required autocomplete="username" class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                    </div>

                    <div>
                        <label for="recovery-password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                        <input id="recovery-password" name="password" type="password" required autocomplete="current-password" class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                    </div>

                    <button type="submit" data-recovery-submit="login" class="w-full sm:w-auto px-5 py-2.5 bg-indigo-600 text-white font-medium text-sm rounded-md shadow hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Sign in to recovery
                    </button>
                </form>
                <details class="space-y-1 text-sm" data-recovery-login-details>
                    <summary>Recovery details</summary>
                    <div><strong>Stage:</strong> <span data-recovery-stage="<?= $escape($viewData['stage']) ?>" data-recovery-current-stage="<?= $escape($viewData['workflow_stage'] ?? '') ?>"><?= $escape($viewData['workflow_stage_label'] ?? $viewData['stage']) ?></span></div>
                    <div><strong>Next action:</strong> <span data-recovery-next-action><?= $escape($viewData['next_permitted_action'] ?? '') ?></span></div>
                    <div><strong>Operation ID:</strong> <?= $escape($viewData['operation_id']) ?></div>
                    <p>Sealed Recovery Accounts must be unsealed separately and cannot sign in here.</p>
                </details>
            </section>
        <?php else: ?>
            <section class="bg-white p-6 rounded-lg shadow-sm border border-slate-200 space-y-6" data-recovery-card="authenticated">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Recovery review</h2>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-800">
                            <?= $escape($session['username']) ?> · <?= $escape(str_replace('_', ' ', $session['role'])) ?>
                        </span>
                    </div>
                    <form method="post" data-recovery-form="logout">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">
                        <button type="submit" data-recovery-submit="logout" class="btn-secondary text-xs text-slate-600 hover:text-slate-900 underline">
                            Sign out
                        </button>
                    </form>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div class="bg-slate-50 p-3 rounded">
                        <div class="text-xs text-slate-500 uppercase font-semibold">Recovery stage</div>
                        <div class="text-base font-bold text-slate-900 mt-1" data-recovery-stage="<?= $escape($viewData['stage']) ?>" data-recovery-current-stage="<?= $escape($viewData['workflow_stage'] ?? '') ?>">
                            <?= $escape($viewData['workflow_stage_label'] ?? $viewData['stage']) ?>
                        </div>
                    </div>
                    <div>
                        <div class="font-semibold text-indigo-900">Next action</div>
                        <div class="mt-0.5 text-indigo-800" data-recovery-next-action><?= $escape($nextAction) ?></div>
                    </div>
                </div>

                <!-- Ticket #313: Controlled Verification Checks & Previews -->
                <?php if ($viewData['stage'] === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING): ?>
                    <div class="border-t border-slate-200 pt-6 space-y-6" data-recovery-card="review">
                        <details data-recovery-results <?= !$readiness['ready'] ? 'open' : '' ?>>
                        <summary>Verification results</summary>
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-base font-bold text-slate-900">Automated checks</h3>
                            </div>
                            <form method="post">
                                <input type="hidden" name="action" value="run_verification">
                                <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">
                                <button type="submit" data-recovery-submit="rerun-checks" class="btn-secondary px-3 py-1.5 border border-slate-300 rounded text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 shadow-sm">
                                    Run checks again
                                </button>
                            </form>
                        </div>

                        <!-- Controlled Read-Only Previews Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- 1. Stock Check -->
                            <div class="p-3 bg-slate-50 rounded border border-slate-200 text-xs space-y-1.5" data-recovery-check="stock">
                                <div class="flex items-center justify-between font-semibold text-slate-800">
                                    <span>Stock &amp; Batch Integrity</span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= ($automatedChecks['stock']['status'] ?? '') === 'passed' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                        <?= strtoupper($escape($automatedChecks['stock']['status'] ?? 'pending')) ?>
                                    </span>
                                </div>
                                <p class="text-slate-600"><?= $escape($automatedChecks['stock']['details'] ?? 'Pending check execution.') ?></p>
                            </div>

                            <!-- 2. Receipt Lookup -->
                            <div class="p-3 bg-slate-50 rounded border border-slate-200 text-xs space-y-1.5" data-recovery-check="receipt">
                                <div class="flex items-center justify-between font-semibold text-slate-800">
                                    <span>Receipt &amp; Subtotal Verification</span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= ($automatedChecks['receipt']['status'] ?? '') === 'passed' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                        <?= strtoupper($escape($automatedChecks['receipt']['status'] ?? 'pending')) ?>
                                    </span>
                                </div>
                                <p class="text-slate-600"><?= $escape($automatedChecks['receipt']['details'] ?? 'Pending check execution.') ?></p>
                            </div>

                            <!-- 3. Upload Assets Preview -->
                            <div class="p-3 bg-slate-50 rounded border border-slate-200 text-xs space-y-1.5" data-recovery-check="upload">
                                <div class="flex items-center justify-between font-semibold text-slate-800">
                                    <span>Upload &amp; Image Assets</span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= ($automatedChecks['upload']['status'] ?? '') === 'passed' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                        <?= strtoupper($escape($automatedChecks['upload']['status'] ?? 'pending')) ?>
                                    </span>
                                </div>
                                <p class="text-slate-600"><?= $escape($automatedChecks['upload']['details'] ?? 'Pending check execution.') ?></p>
                            </div>

                            <!-- 4. Attribution Check -->
                            <div class="p-3 bg-slate-50 rounded border border-slate-200 text-xs space-y-1.5" data-recovery-check="attribution">
                                <div class="flex items-center justify-between font-semibold text-slate-800">
                                    <span>Identity &amp; Attribution</span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= ($automatedChecks['attribution']['status'] ?? '') === 'passed' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                        <?= strtoupper($escape($automatedChecks['attribution']['status'] ?? 'pending')) ?>
                                    </span>
                                </div>
                                <p class="text-slate-600"><?= $escape($automatedChecks['attribution']['details'] ?? 'Pending check execution.') ?></p>
                            </div>
                        </div>

                        <!-- Previews: Sign-in & Summary Report -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Sign-in Preview -->
                        <div class="p-3 bg-white rounded border border-slate-200 text-xs space-y-2" data-recovery-preview="signin">
                                <div class="font-semibold text-slate-800">Active accounts</div>
                                <?php if (!empty($automatedChecks['signin_preview']['accounts'])): ?>
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        <?php foreach ($automatedChecks['signin_preview']['accounts'] as $acc): ?>
                                            <span class="px-2 py-0.5 bg-slate-100 rounded text-[11px] font-mono text-slate-700">
                                                <?= $escape($acc['username']) ?> (<?= $escape($acc['role_name']) ?>)
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Report Summary -->
                            <div class="p-3 bg-white rounded border border-slate-200 text-xs space-y-2" data-recovery-preview="report">
                                <div class="font-semibold text-slate-800">Store summary</div>
                                <?php if (empty($automatedChecks['report']['metrics'])): ?><p>No summary available.</p><?php endif; ?>
                                <?php if (!empty($automatedChecks['report']['metrics'])): ?>
                                    <div class="grid grid-cols-2 gap-2 mt-1 text-[11px]">
                                        <div class="bg-slate-50 p-1.5 rounded">Sales Count: <strong><?= (int)($automatedChecks['report']['metrics']['sales_count'] ?? 0) ?></strong></div>
                                        <div class="bg-slate-50 p-1.5 rounded">Sales Total: <strong><?= number_format((float)($automatedChecks['report']['metrics']['total_revenue'] ?? 0), 2) ?></strong></div>
                                        <div class="bg-slate-50 p-1.5 rounded col-span-2">Products: <strong><?= (int)($automatedChecks['report']['metrics']['products_count'] ?? 0) ?></strong></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        </details>

                        <!-- Human Checklist Confirmation Form -->
                        <details class="space-y-3" data-recovery-card="checklist" <?= !$checklistComplete ? 'open' : '' ?>>
                            <summary>Review checklist · <?= $confirmedCount ?> of 4 saved<?= $checklistComplete ? ' — complete' : '' ?></summary>
                            <form method="post" class="space-y-3" data-recovery-form="checklist">
                                <input type="hidden" name="action" value="confirm_checklist">
                                <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">

                                <div class="space-y-2 text-xs">
                                    <label class="flex items-start space-x-2.5">
                                        <input type="checkbox" name="confirm_stock" value="1" data-recovery-confirm="stock" <?= !empty($humanChecks['stock']['confirmed']) ? 'checked' : '' ?> class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-slate-700">Stock counts and batches reviewed; no negative stock.</span>
                                    </label>

                                    <label class="flex items-start space-x-2.5">
                                        <input type="checkbox" name="confirm_receipt" value="1" data-recovery-confirm="receipt" <?= !empty($humanChecks['receipt']['confirmed']) ? 'checked' : '' ?> class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-slate-700">Receipt subtotals match recorded sales.</span>
                                    </label>

                                    <label class="flex items-start space-x-2.5">
                                        <input type="checkbox" name="confirm_upload" value="1" data-recovery-confirm="upload" <?= !empty($humanChecks['upload']['confirmed']) ? 'checked' : '' ?> class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-slate-700">Product and profile images reviewed.</span>
                                    </label>

                                    <label class="flex items-start space-x-2.5">
                                        <input type="checkbox" name="confirm_attribution" value="1" data-recovery-confirm="attribution" <?= !empty($humanChecks['attribution']['confirmed']) ? 'checked' : '' ?> class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-slate-700">Active accounts and record attribution reviewed.</span>
                                    </label>
                                </div>

                                <button type="submit" data-recovery-submit="checklist" class="px-4 py-2 bg-indigo-600 text-white font-medium text-xs rounded hover:bg-indigo-700">
                                    Save checklist
                                </button>
                            </form>
                        </details>

                        <!-- Ticket #313: Explicit Reopening Section -->
                        <div class="p-5 border-2 border-amber-300 bg-amber-50 rounded-lg space-y-4" data-recovery-card="reopen">
                            <div>
                                <h4 class="text-sm font-bold text-amber-950">Reopen Store access</h4>
                                <p class="text-xs text-amber-900 mt-1">
                                    Resumes Store activity and signs out existing sessions.
                                </p>
                            </div>

                            <?php if (!$readiness['ready']): ?>
                                <div class="p-3 bg-white border border-amber-200 rounded text-xs text-amber-900 space-y-1" role="alert" data-recovery-alert="guidance">
                                    <p>Save all four confirmations. Automated checks must pass within the last 15 minutes.</p>
                                    <details>
                                    <summary>Pending checks</summary>
                                    <ul class="list-disc list-inside space-y-0.5 text-slate-700">
                                        <?php foreach ($readiness['missing'] as $miss): ?>
                                            <li><?= $escape($miss) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    </details>
                                </div>
                            <?php endif; ?>

                            <?php if ($canReopen): ?>
                                <?php if ($isTakeover): ?>
                                    <div class="p-3 bg-amber-100 border border-amber-300 rounded text-xs text-amber-950 font-medium" data-recovery-takeover-banner>
                                        <strong>Authenticated Takeover:</strong> Initiating administrator is unavailable. You are authorized as <?= $escape($session['role']) ?> (<?= $escape($session['username']) ?>) to take over and complete reopening with your own verified password and takeover attribution.
                                    </div>
                                <?php endif; ?>
                                <form method="post" class="space-y-4" data-recovery-form="reopen" onsubmit="return confirm('Explicitly complete recovery and reopen Store access to all users?');">
                                    <input type="hidden" name="action" value="reopen">
                                    <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">
                                    <input type="hidden" name="operation_id" value="<?= $escape($viewData['operation_id']) ?>">

                                    <div>
                                        <label for="reopen-operator-password" class="block text-xs font-semibold text-slate-800 mb-1">
                                            Your password on this server
                                        </label>
                                        <input id="reopen-operator-password" name="operator_password" type="password" required autocomplete="current-password" class="w-full sm:w-80 px-3 py-1.5 border border-slate-300 rounded text-xs focus:ring-amber-500 focus:border-amber-500">
                                    </div>

                                    <div>
                                        <label class="flex items-start space-x-2 text-xs text-amber-950 font-medium">
                                            <input id="confirm-one-active" type="checkbox" name="confirm_one_active" value="1" required class="mt-0.5 rounded text-amber-600 focus:ring-amber-500">
                                            <span>Recovery is complete. This is the only active Store.</span>
                                        </label>
                                    </div>

                                    <button type="submit" data-recovery-submit="reopen" class="px-5 py-2.5 bg-amber-700 text-white font-semibold text-xs rounded shadow hover:bg-amber-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-600">
                                        Reopen Store Access
                                    </button>
                                </form>
                            <?php else: ?>
                                <p class="text-xs text-amber-800">
                                    Only an authorized Administrator or destination Super Administrator can reopen the Store.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Recovery Actions (Rollback Safety / Restart Incoming) -->
                <details class="border-t border-slate-100 pt-4 space-y-4" data-recovery-actions <?= $viewData['stage'] !== RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING ? 'open' : '' ?>>
                    <summary>Other recovery options</summary>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Action 1: Complete Safety Recovery -->
                        <div class="p-4 border border-slate-200 rounded-lg bg-slate-50 space-y-3" data-recovery-card="safety">
                            <div>
                                <h4 class="text-sm font-bold text-slate-900">Restore safety copy</h4>
                                <p class="text-xs text-slate-600 mt-1">
                                    Replaces current data with this server’s pre-restore copy.
                                </p>
                            </div>
                            <form method="post" class="space-y-3" data-recovery-form="safety">
                                <input type="hidden" name="action" value="recover_safety">
                                <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">

                                <button type="submit" data-recovery-submit="safety" class="btn-secondary w-full px-3 py-2 bg-slate-800 text-white font-medium text-xs rounded hover:bg-slate-900">
                                    Restore safety copy
                                </button>
                            </form>
                        </div>

                        <!-- Action 2: Restart Approved Incoming Replacement -->
                        <div class="p-4 border border-slate-200 rounded-lg bg-slate-50 space-y-3" data-recovery-card="incoming">
                            <div>
                                <h4 class="text-sm font-bold text-slate-900">Restart incoming restore</h4>
                                <p class="text-xs text-slate-600 mt-1">
                                    Replaces current data using the approved package and mappings.
                                </p>
                            </div>
                            <form method="post" enctype="multipart/form-data" class="space-y-3" data-recovery-form="incoming">
                                <input type="hidden" name="action" value="restart_incoming">
                                <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">

                                <div>
                                    <label for="incoming-file" class="block text-xs font-medium text-slate-700 mb-1">Approved Package (.rmpkg)</label>
                                    <input id="incoming-file" name="package_file" type="file" accept=".rmpkg" required class="w-full text-xs">
                                </div>

                                <button type="submit" data-recovery-submit="incoming" class="btn-secondary w-full px-3 py-2 bg-indigo-600 text-white font-medium text-xs rounded hover:bg-indigo-700">
                                    Restart incoming restore
                                </button>
                            </form>
                        </div>
                    </div>
                </details>

                <details class="border-t border-slate-100 pt-4">
                    <summary>Recovery history &amp; operation ID</summary>
                    <div data-recovery-opid="<?= $escape($viewData['operation_id']) ?>"><?= $escape($viewData['operation_id']) ?></div>
                    <ol data-recovery-timeline>
                        <?php foreach ($viewData['stages'] as $step): ?>
                            <li><span><?= $escape($step['stage']) ?></span> <span><?= $escape($step['time']) ?></span></li>
                        <?php endforeach; ?>
                    </ol>
                </details>

                <?php if ($session['role'] === 'super_admin'): ?>
                    <details class="border-t border-slate-100 pt-4 space-y-4 max-w-full overflow-hidden" data-recovery-super-evidence>
                        <summary>Security &amp; recovery details</summary>
                        <div class="bg-slate-50 p-3 rounded text-xs font-mono space-y-1 text-slate-700 max-w-full" style="overflow-wrap:anywhere;word-break:break-all;">
                            <div style="overflow-wrap:anywhere;word-break:break-all;"><strong>Watermark:</strong> <?= $escape($viewData['destination_watermark']) ?></div>
                            <div style="overflow-wrap:anywhere;word-break:break-all;"><strong>Safety Op ID:</strong> <?= $escape($viewData['safety_operation_id']) ?></div>
                            <div style="overflow-wrap:anywhere;word-break:break-all;"><strong>Approved Package ID:</strong> <?= $escape($viewData['package_id']) ?></div>
                        </div>

                        <?php if (!empty($viewData['append_log'])): ?>
                            <details class="text-xs max-w-full">
                                <summary class="cursor-pointer text-indigo-600 hover:text-indigo-800 font-medium">View Append-Only Operation Outcomes (<?= count($viewData['append_log']) ?>)</summary>
                                <pre class="mt-2 bg-slate-900 text-slate-100 p-3 rounded text-xs max-w-full" style="overflow-x:auto;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-all;" data-recovery-append-log><?= $escape(json_encode($viewData['append_log'], JSON_PRETTY_PRINT)) ?></pre>
                            </details>
                        <?php endif; ?>

                    </details>
                    <?php if ($viewData['stage'] !== RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING): ?>
                        <div class="bg-amber-50 border border-amber-200 p-4 rounded-md text-amber-900 text-xs space-y-3">
                            <p>Complete a recovery option above before reviewing and reopening Store access.</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>

    <footer class="mt-8 pt-4 border-t border-slate-200 text-center text-xs text-slate-400">
        RetailMind &copy; <?= date('Y') ?>
    </footer>
</div>
</body>
</html>
