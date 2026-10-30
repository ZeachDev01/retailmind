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
use DomainException;
use RuntimeException;

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
$notice = '';
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
            $notice = 'Recovery authenticated successfully. Operational evidence unlocked for ' . $authData['role'] . '.';
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

            $safetyPassphrase = (string)($_POST['safety_passphrase'] ?? '');
            $state = RecoveryStore::state();
            $safetyOpId = (string)($state['safety_operation_id'] ?? '');
            if ($safetyOpId === '') {
                throw new DomainException('No safety operation bound to active recovery.');
            }

            $pdo = $getPdo();
            $policy = new RoleCapabilityPolicy();
            $backups = new DatabaseBackupService($pdo, $policy);
            $restore = new DatabaseRestoreService($pdo, $policy, $backups);
            $result = $restore->recoverSafety((int)$session['user_id'], (string)$session['role'], $safetyOpId, $safetyPassphrase);
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

            $incomingPassphrase = (string)($_POST['incoming_passphrase'] ?? '');
            $safetyPassphrase = (string)($_POST['safety_passphrase'] ?? '');

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
            $result = $restore->restartIncoming((int)$session['user_id'], (string)$session['role'], $packagePath, $incomingPassphrase, $safetyPassphrase);
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
                if (!empty($_POST['confirm_' . $key])) {
                    $restore->confirmChecklistItem((int)$session['user_id'], (string)$session['role'], $key, true);
                }
            }
            $notice = 'Human review checklist confirmations saved.';
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

$canReopen = !empty($session['is_initiator']) || in_array(($session['role'] ?? ''), ['admin', 'super_admin'], true);
$isTakeover = !empty($session) && empty($session['is_initiator']);
?>
<!doctype html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Recovery Console - RetailMind</title>
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/backup.css')) ?>">
</head>
<body class="bg-slate-50 text-slate-800">
<div class="min-h-screen flex flex-col justify-between p-4 sm:p-6 lg:p-8 max-w-4xl mx-auto">
    <header class="mb-6 border-b border-slate-200 pb-4 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Database Recovery Console</h1>
            <p class="text-sm text-slate-500">Operation-bound recovery, verification, and explicit reopening (#311, #312, #313)</p>
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
                <h2 class="text-lg font-semibold text-slate-900">Store Online</h2>
                <p class="text-slate-600 text-sm">The Store database is running normally and write coordination is open. Ordinary requests are admitted.</p>

                <?php if ($reopenedRecord): ?>
                    <div class="p-4 bg-slate-50 rounded border border-slate-200 text-xs space-y-1 font-mono text-slate-700" data-recovery-reopened-evidence>
                        <div class="font-bold text-slate-900 mb-1">Most Recent Reopening Evidence:</div>
                        <div><strong>Reopened At:</strong> <?= $escape($reopenedRecord['reopened_at'] ?? 'unknown') ?></div>
                        <div><strong>Reopened By:</strong> <?= $escape($reopenedRecord['reopened_by']['username'] ?? 'unknown') ?> (<?= $escape($reopenedRecord['reopened_by']['role'] ?? '') ?><?= !empty($reopenedRecord['reopened_by']['is_initiator']) ? ', Initiator' : '' ?>)</div>
                        <div><strong>Path Completed:</strong> <?= $escape($reopenedRecord['path_completed'] ?? 'unknown') ?></div>
                        <div><strong>One Active Store:</strong> Verified (Dual active stores prohibited)</div>
                        <div><strong>Session Epoch:</strong> <?= $escape(substr((string)($reopenedRecord['session_epoch'] ?? ''), 0, 16)) ?>...</div>
                    </div>
                <?php endif; ?>

                <a href="<?= $escape(app_url('components/system_administrator/backup_restore.php')) ?>" class="inline-flex items-center px-4 py-2 border border-slate-300 rounded-md shadow-sm text-sm font-medium text-slate-700 bg-white hover:bg-slate-50">
                    Return to Backup &amp; Restore
                </a>
            </section>
        <?php elseif (!$session): ?>
            <section class="bg-white p-6 rounded-lg shadow-sm border border-slate-200 space-y-4" data-recovery-card="login">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Operator Authentication Required</h2>
                    <p class="text-slate-600 text-sm">
                        Store access and ordinary requests are fenced. Enter destination pre-restore administrator credentials to access the recovery console. Sealed Recovery Accounts retain separate unsealing rules and cannot authenticate here.
                    </p>
                </div>

                <div class="bg-slate-50 p-3 rounded text-xs space-y-1 font-mono text-slate-600">
                    <div><strong>Durable Stage:</strong> <span data-recovery-stage="<?= $escape($viewData['stage']) ?>" data-recovery-current-stage="<?= $escape($viewData['workflow_stage'] ?? '') ?>"><?= $escape($viewData['workflow_stage_label'] ?? $viewData['stage']) ?></span></div>
                    <div><strong>Next Permitted Action:</strong> <span data-recovery-next-action><?= $escape($viewData['next_permitted_action'] ?? '') ?></span></div>
                    <div><strong>Operation ID:</strong> <?= $escape($viewData['operation_id']) ?></div>
                </div>

                <form method="post" class="space-y-4" data-recovery-form="auth">
                    <input type="hidden" name="action" value="authenticate">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['_recovery_login_csrf']) ?>">

                    <div>
                        <label for="recovery-username" class="block text-sm font-medium text-slate-700 mb-1">Pre-restore Username</label>
                        <input id="recovery-username" name="username" type="text" required autocomplete="username" class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                    </div>

                    <div>
                        <label for="recovery-password" class="block text-sm font-medium text-slate-700 mb-1">Pre-restore Destination Password</label>
                        <input id="recovery-password" name="password" type="password" required autocomplete="current-password" class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                    </div>

                    <button type="submit" data-recovery-submit="login" class="w-full sm:w-auto px-5 py-2.5 bg-indigo-600 text-white font-medium text-sm rounded-md shadow hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Authenticate Recovery
                    </button>
                </form>
            </section>
        <?php else: ?>
            <section class="bg-white p-6 rounded-lg shadow-sm border border-slate-200 space-y-6" data-recovery-card="authenticated">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Recovery Console: <?= $escape($session['username']) ?></h2>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-800">
                            Role: <?= $escape($session['role']) ?> <?= !empty($session['is_initiator']) ? '(Initiator)' : '' ?>
                        </span>
                    </div>
                    <form method="post" data-recovery-form="logout">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">
                        <button type="submit" data-recovery-submit="logout" class="text-xs text-slate-600 hover:text-slate-900 underline">
                            Sign Out of Recovery
                        </button>
                    </form>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div class="bg-slate-50 p-3 rounded">
                        <div class="text-xs text-slate-500 uppercase font-semibold">Current Durable Stage</div>
                        <div class="text-base font-bold text-slate-900 mt-1" data-recovery-stage="<?= $escape($viewData['stage']) ?>" data-recovery-current-stage="<?= $escape($viewData['workflow_stage'] ?? '') ?>">
                            <?= $escape($viewData['workflow_stage_label'] ?? $viewData['stage']) ?>
                        </div>
                    </div>
                    <div class="bg-slate-50 p-3 rounded">
                        <div class="text-xs text-slate-500 uppercase font-semibold">Bound Operation ID</div>
                        <div class="font-mono text-xs text-slate-900 mt-1 break-all" data-recovery-opid="<?= $escape($viewData['operation_id']) ?>">
                            <?= $escape($viewData['operation_id']) ?>
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-indigo-50 border border-indigo-200 rounded text-xs text-indigo-950">
                    <div class="font-semibold text-indigo-900">Next Permitted Action:</div>
                    <div class="mt-0.5 text-indigo-800" data-recovery-next-action><?= $escape($viewData['next_permitted_action'] ?? '') ?></div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-slate-900 mb-2">Stage Timeline</h3>
                    <ol class="border-l border-slate-200 ml-2 space-y-2 text-xs text-slate-600 pl-4" data-recovery-timeline>
                        <?php foreach ($viewData['stages'] as $step): ?>
                            <li>
                                <span class="font-semibold text-slate-800"><?= $escape($step['stage']) ?></span>
                                <span class="text-slate-400 ml-2"><?= $escape($step['time']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>

                <!-- Ticket #313: Controlled Verification Checks & Previews -->
                <?php if ($viewData['stage'] === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING): ?>
                    <div class="border-t border-slate-200 pt-6 space-y-6" data-recovery-card="review">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-base font-bold text-slate-900">Operator Verification &amp; Review</h3>
                                <p class="text-xs text-slate-600 mt-1">
                                    Store writes and background workers remain fenced. Review controlled read-only sign-in preview, stock, receipts, images, and reports below.
                                </p>
                            </div>
                            <form method="post">
                                <input type="hidden" name="action" value="run_verification">
                                <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">
                                <button type="submit" data-recovery-submit="rerun-checks" class="px-3 py-1.5 border border-slate-300 rounded text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 shadow-sm">
                                    Re-run Verification Checks
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
                                <div class="font-semibold text-slate-800">Sign-in Preview (Active Accounts)</div>
                                <div class="text-slate-600"><?= $escape($automatedChecks['signin_preview']['details'] ?? 'No active accounts previewed.') ?></div>
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
                                <div class="font-semibold text-slate-800">Summary Report Check</div>
                                <div class="text-slate-600"><?= $escape($automatedChecks['report']['details'] ?? 'No summary computed.') ?></div>
                                <?php if (!empty($automatedChecks['report']['metrics'])): ?>
                                    <div class="grid grid-cols-2 gap-2 mt-1 text-[11px]">
                                        <div class="bg-slate-50 p-1.5 rounded">Sales Count: <strong><?= (int)($automatedChecks['report']['metrics']['sales_count'] ?? 0) ?></strong></div>
                                        <div class="bg-slate-50 p-1.5 rounded">Sales Total: <strong><?= number_format((float)($automatedChecks['report']['metrics']['total_revenue'] ?? 0), 2) ?></strong></div>
                                        <div class="bg-slate-50 p-1.5 rounded col-span-2">Products: <strong><?= (int)($automatedChecks['report']['metrics']['products_count'] ?? 0) ?></strong></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Human Checklist Confirmation Form -->
                        <div class="p-4 bg-slate-50 border border-slate-200 rounded-lg space-y-3" data-recovery-card="checklist">
                            <div>
                                <h4 class="text-sm font-bold text-slate-900">Human Checklist Confirmations</h4>
                                <p class="text-xs text-slate-600 mt-0.5">
                                    Each item must be confirmed by an authorized operator before Store reopening can proceed.
                                </p>
                            </div>
                            <form method="post" class="space-y-3" data-recovery-form="checklist">
                                <input type="hidden" name="action" value="confirm_checklist">
                                <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">

                                <div class="space-y-2 text-xs">
                                    <label class="flex items-start space-x-2.5">
                                        <input type="checkbox" name="confirm_stock" value="1" data-recovery-confirm="stock" <?= !empty($humanChecks['stock']['confirmed']) ? 'checked' : '' ?> class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-slate-700"><strong>Stock Integrity:</strong> I have reviewed inventory counts, product batches, and verified zero negative stock.</span>
                                    </label>

                                    <label class="flex items-start space-x-2.5">
                                        <input type="checkbox" name="confirm_receipt" value="1" data-recovery-confirm="receipt" <?= !empty($humanChecks['receipt']['confirmed']) ? 'checked' : '' ?> class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-slate-700"><strong>Receipt Integrity:</strong> I have checked sales receipts and confirmed item subtotals match recorded revenue.</span>
                                    </label>

                                    <label class="flex items-start space-x-2.5">
                                        <input type="checkbox" name="confirm_upload" value="1" data-recovery-confirm="upload" <?= !empty($humanChecks['upload']['confirmed']) ? 'checked' : '' ?> class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-slate-700"><strong>Upload Assets:</strong> I have previewed product and profile images and confirm filesystem assets are intact.</span>
                                    </label>

                                    <label class="flex items-start space-x-2.5">
                                        <input type="checkbox" name="confirm_attribution" value="1" data-recovery-confirm="attribution" <?= !empty($humanChecks['attribution']['confirmed']) ? 'checked' : '' ?> class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-slate-700"><strong>Operator Attribution:</strong> I have reviewed active accounts and confirmed all actor associations resolve cleanly.</span>
                                    </label>
                                </div>

                                <button type="submit" data-recovery-submit="checklist" class="px-4 py-2 bg-indigo-600 text-white font-medium text-xs rounded hover:bg-indigo-700">
                                    Save Checklist Confirmations
                                </button>
                            </form>
                        </div>

                        <!-- Ticket #313: Explicit Reopening Section -->
                        <div class="p-5 border-2 border-amber-300 bg-amber-50 rounded-lg space-y-4" data-recovery-card="reopen">
                            <div>
                                <h4 class="text-sm font-bold text-amber-950 uppercase tracking-wide">Explicit Store Reopening</h4>
                                <p class="text-xs text-amber-900 mt-1">
                                    Reopening resumes ordinary read/write traffic, unpauses write gates, invalidates old sessions, and logs completion.
                                    <strong>One-Active-Store Constraint:</strong> Completing recovery reopens this Store as the sole operational Store. Dual active stores are strictly unauthorized.
                                </p>
                            </div>

                            <?php if (!$readiness['ready']): ?>
                                <div class="p-3 bg-white border border-amber-200 rounded text-xs text-amber-900 space-y-1" role="alert" data-recovery-alert="guidance">
                                    <div class="font-bold flex items-center text-amber-800">
                                        <span>Reopening is Blocked Until All Checks Complete:</span>
                                    </div>
                                    <ul class="list-disc list-inside space-y-0.5 text-slate-700">
                                        <?php foreach ($readiness['missing'] as $miss): ?>
                                            <li><?= $escape($miss) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
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
                                            Re-enter Your Destination Operator Password (Fresh Authentication Required)
                                        </label>
                                        <input id="reopen-operator-password" name="operator_password" type="password" required autocomplete="current-password" class="w-full sm:w-80 px-3 py-1.5 border border-slate-300 rounded text-xs focus:ring-amber-500 focus:border-amber-500">
                                    </div>

                                    <div>
                                        <label class="flex items-start space-x-2 text-xs text-amber-950 font-medium">
                                            <input id="confirm-one-active" type="checkbox" name="confirm_one_active" value="1" required class="mt-0.5 rounded text-amber-600 focus:ring-amber-500">
                                            <span>I confirm that recovery is complete and this Store is the single active Store. Dual active stores are unauthorized.</span>
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
                <div class="border-t border-slate-100 pt-4 space-y-4" data-recovery-actions>
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Alternative Recovery Actions</h3>
                        <p class="text-xs text-slate-600 mt-1">
                            Choose an action to recover safety state or restart approved incoming replacement. Both actions require appropriate passphrases and revalidate integrity independently of partial live tables.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Action 1: Complete Safety Recovery -->
                        <div class="p-4 border border-slate-200 rounded-lg bg-slate-50 space-y-3" data-recovery-card="safety">
                            <div>
                                <h4 class="text-sm font-bold text-slate-900">Complete Safety Recovery</h4>
                                <p class="text-xs text-slate-600 mt-1">
                                    Restores the original verified destination safety copy. Works completely without incoming package material.
                                </p>
                            </div>
                            <form method="post" class="space-y-3" data-recovery-form="safety">
                                <input type="hidden" name="action" value="recover_safety">
                                <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">

                                <div>
                                    <label for="safety-passphrase" class="block text-xs font-medium text-slate-700 mb-1">Safety-Copy Passphrase</label>
                                    <input id="safety-passphrase" name="safety_passphrase" type="password" required autocomplete="current-password" class="w-full px-3 py-1.5 border border-slate-300 rounded text-xs">
                                </div>

                                <button type="submit" data-recovery-submit="safety" class="w-full px-3 py-2 bg-slate-800 text-white font-medium text-xs rounded hover:bg-slate-900">
                                    Recover Destination Safety Copy
                                </button>
                            </form>
                        </div>

                        <!-- Action 2: Restart Approved Incoming Replacement -->
                        <div class="p-4 border border-slate-200 rounded-lg bg-slate-50 space-y-3" data-recovery-card="incoming">
                            <div>
                                <h4 class="text-sm font-bold text-slate-900">Restart Approved Incoming Replacement</h4>
                                <p class="text-xs text-slate-600 mt-1">
                                    Restarts incoming replacement deterministically from the beginning with approved identity and file mappings.
                                </p>
                            </div>
                            <form method="post" enctype="multipart/form-data" class="space-y-3" data-recovery-form="incoming">
                                <input type="hidden" name="action" value="restart_incoming">
                                <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">

                                <div>
                                    <label for="incoming-file" class="block text-xs font-medium text-slate-700 mb-1">Approved Package (.rmpkg)</label>
                                    <input id="incoming-file" name="package_file" type="file" accept=".rmpkg" required class="w-full text-xs">
                                </div>

                                <div>
                                    <label for="incoming-passphrase" class="block text-xs font-medium text-slate-700 mb-1">Incoming Package Passphrase</label>
                                    <input id="incoming-passphrase" name="incoming_passphrase" type="password" required class="w-full px-3 py-1.5 border border-slate-300 rounded text-xs">
                                </div>

                                <div>
                                    <label for="safety-passphrase-restart" class="block text-xs font-medium text-slate-700 mb-1">Destination Safety Passphrase</label>
                                    <input id="safety-passphrase-restart" name="safety_passphrase" type="password" required class="w-full px-3 py-1.5 border border-slate-300 rounded text-xs">
                                </div>

                                <button type="submit" data-recovery-submit="incoming" class="w-full px-3 py-2 bg-indigo-600 text-white font-medium text-xs rounded hover:bg-indigo-700">
                                    Restart Incoming Replacement
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <?php if ($session['role'] === 'super_admin'): ?>
                    <div class="border-t border-slate-100 pt-4 space-y-4 max-w-full overflow-hidden" data-recovery-super-evidence>
                        <h3 class="text-sm font-semibold text-slate-900">Destination Security &amp; Safety Evidence</h3>
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

                        <?php if ($viewData['stage'] !== RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING): ?>
                            <div class="bg-amber-50 border border-amber-200 p-4 rounded-md text-amber-900 text-xs space-y-3">
                                <p><strong>Offline Recovery &amp; Reopening:</strong> Ordinary replacement remains disabled. If an incomplete restore requires rollback, run <code>php src/backend/scripts/restore_database.php</code> with the initiating administrator credentials.</p>
                                <form method="post" onsubmit="return confirm('Explicitly complete recovery and reopen Store access to all users?');" data-recovery-form="reopen">
                                    <input type="hidden" name="action" value="reopen">
                                    <input type="hidden" name="recovery_csrf" value="<?= $escape($session['csrf_token']) ?>">
                                    <button type="submit" data-recovery-submit="reopen" class="px-4 py-2 bg-amber-700 text-white font-medium text-xs rounded hover:bg-amber-800">
                                        Reopen Store Access
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="border-t border-slate-100 pt-4 text-xs text-slate-500" data-recovery-admin-notice>
                        <p>Initiating Administrator view: Store access remains fenced across worker execution and crashes.</p>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>

    <footer class="mt-8 pt-4 border-t border-slate-200 text-center text-xs text-slate-400">
        RetailMind &copy; <?= date('Y') ?> &bull; Durable Recovery Store
    </footer>
</div>
</body>
</html>
