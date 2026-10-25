<?php
// Browser recovery console and status (#311).
// Renders and authenticates independently of database tables.
require_once dirname(__DIR__, 3) . '/backend/bootstrap/app.php';

use App\Backup\RecoveryStore;
use App\Backup\RecoveryAuthService;

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
        } elseif ($action === 'reopen') {
            if (!$session || ($session['role'] ?? '') !== 'super_admin') {
                throw new DomainException('Only a destination Super Administrator can explicitly reopen the Store.');
            }
            if (!RecoveryAuthService::verifyCsrf((string)($_POST['recovery_csrf'] ?? ''))) {
                throw new DomainException('Invalid recovery session token.');
            }
            RecoveryStore::transitionStage(RecoveryStore::STAGE_REOPENED_COMPLETED);
            RecoveryAuthService::clearRecoverySession();
            $session = null;
            $notice = 'Recovery completed. Store access has been resumed.';
        }
    } catch (DomainException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = 'A recovery error occurred. Coordination state remains fenced.';
    }
}

$isPaused = RecoveryStore::isPaused();
$viewData = RecoveryAuthService::recoveryViewData($session);
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
            <p class="text-sm text-slate-500">Operation-bound recovery and maintenance fencing (#311)</p>
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
            <section class="bg-white p-6 rounded-lg shadow-sm border border-slate-200" data-recovery-card="idle">
                <h2 class="text-lg font-semibold text-slate-900 mb-2">No Active Recovery Operation</h2>
                <p class="text-slate-600 mb-4">The Store database is running normally and write coordination is open. No recovery intervention is required.</p>
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
                    <div><strong>Durable Stage:</strong> <span data-recovery-stage="<?= $escape($viewData['stage']) ?>"><?= $escape($viewData['stage']) ?></span></div>
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
                        <div class="text-base font-bold text-slate-900 mt-1" data-recovery-stage="<?= $escape($viewData['stage']) ?>">
                            <?= $escape($viewData['stage']) ?>
                        </div>
                    </div>
                    <div class="bg-slate-50 p-3 rounded">
                        <div class="text-xs text-slate-500 uppercase font-semibold">Bound Operation ID</div>
                        <div class="font-mono text-xs text-slate-900 mt-1 break-all" data-recovery-opid="<?= $escape($viewData['operation_id']) ?>">
                            <?= $escape($viewData['operation_id']) ?>
                        </div>
                    </div>
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
                    </div>
                <?php else: ?>
                    <div class="border-t border-slate-100 pt-4 text-xs text-slate-500" data-recovery-admin-notice>
                        <p>Initiating Administrator view: Store access remains fenced across worker execution and crashes. Platform governance actions (reopening or offline rollback) require a destination Super Administrator.</p>
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
