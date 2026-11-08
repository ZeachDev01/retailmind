<?php
// Public landing page. Authenticated users are sent to their primary workspace.
require_once dirname(__DIR__) . '/backend/bootstrap/app.php';

App\Core\Session::start();
require_once dirname(__DIR__) . '/backend/includes/csrf.php';
require_once dirname(__DIR__) . '/backend/includes/theme.php';

function landing_app_base_url(): string
{
    $documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $projectRoot = rtrim(str_replace('\\', '/', __DIR__), '/');
    if ($documentRoot === '' || $projectRoot === '') {
        return '';
    }
    if (strpos($projectRoot, $documentRoot) === 0) {
        $basePath = substr($projectRoot, strlen($documentRoot));
        return $basePath === '' ? '' : '/' . trim($basePath, '/');
    }
    return '';
}

function landing_app_url(string $path = ''): string
{
    $baseUrl = landing_app_base_url();
    $normalizedPath = ltrim($path, '/');
    if ($normalizedPath === '') {
        return $baseUrl === '' ? '/' : $baseUrl . '/';
    }
    return ($baseUrl === '' ? '' : $baseUrl) . '/' . $normalizedPath;
}

function landing_role_destination(): string
{
    return landing_app_url(App\Authorization\RoleWorkspaceRouter::pathFor($_SESSION['role'] ?? null));
}

function landing_redirect_by_role(): void
{
    header('Location: ' . landing_role_destination());
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['csrf_refresh'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['csrf_token' => csrf_token()]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_token_is_valid()) {
        $_SESSION['_login_error'] = 'Your login page expired. Please try again.';
        $_SESSION['_login_username'] = is_string($_POST['username'] ?? null)
            ? trim($_POST['username']) : '';
        csrf_token();
        header('Location: ' . landing_app_url('?login=1'), true, 303);
        exit;
    }

    require_once dirname(__DIR__) . '/backend/includes/auth.php';

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $_SESSION['_login_username'] = $username;

    if ($username && $password && login_user($pdo, $username, $password)) {
        landing_redirect_by_role();
    }

    $_SESSION['_login_error'] = last_login_error();
    header('Location: ' . landing_app_url('?login=1'));
    exit;
}

if (isset($_SESSION['user_id'])) {
    landing_redirect_by_role();
}

$loginUrl = htmlspecialchars(landing_app_url('?login=1'), ENT_QUOTES, 'UTF-8');
$loginActionUrl = htmlspecialchars(landing_app_url(), ENT_QUOTES, 'UTF-8');
$forgotPasswordUrl = htmlspecialchars(landing_app_url('components/auth/forgot_password.php'), ENT_QUOTES, 'UTF-8');
$styleUrl = htmlspecialchars(landing_app_url('assets/css/style.css?v=' . filemtime(__DIR__ . '/assets/css/style.css')), ENT_QUOTES, 'UTF-8');
$landingStyleUrl = htmlspecialchars(landing_app_url('assets/css/landing.css?v=' . filemtime(__DIR__ . '/assets/css/landing.css')), ENT_QUOTES, 'UTF-8');
$bootstrapIconsUrl = htmlspecialchars(landing_app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css'), ENT_QUOTES, 'UTF-8');
$brandBaseUrl = htmlspecialchars(landing_app_url('assets/img/retailmind-web-assets%20dark%20mode/'), ENT_QUOTES, 'UTF-8');
$faviconUrl = $brandBaseUrl . 'icons/light/favicon-32.png';
$brandLogoUrl = $brandBaseUrl . 'logos/light/logo-header.png';
$heroImageUrl = htmlspecialchars(landing_app_url('assets/img/retailmind-landing-hero.jpg'), ENT_QUOTES, 'UTF-8');
$brandIconUrl = $brandBaseUrl . 'icons/light/icon-64.png';
$loginError = '';
$loginSuccess = '';
$flashError = (string)($_SESSION['_flash_error'] ?? '');
unset($_SESSION['_flash_error']);
$loginUsername = (string)($_SESSION['_login_username'] ?? '');
$recoveryStep = (string)($_SESSION['_recovery_step'] ?? 'request');
$recoveryMessage = (string)($_SESSION['_recovery_message'] ?? '');
$recoveryMessageClass = ($_SESSION['_recovery_message_class'] ?? '') === 'tag-warning' ? 'tag-warning' : 'tag-success';
$recoveryIdentity = (string)($_SESSION['_recovery_identity'] ?? '');
$recoveryMaskedEmail = (string)($_SESSION['_recovery_masked_email'] ?? '');
$resendCooldown = max(0, (int)($_SESSION['_recovery_resend_cooldown'] ?? 0) - time());
$codeExpiresIn = max(0, (int)($_SESSION['_recovery_code_expires_at'] ?? (time() + 900)) - time());
$expMinutes = floor($codeExpiresIn / 60);
$expSeconds = $codeExpiresIn % 60;
$expFormatted = sprintf('%d:%02d', $expMinutes, $expSeconds);
unset($_SESSION['_recovery_message'], $_SESSION['_recovery_message_class']);
$shouldOpenRecovery = ($_GET['recovery'] ?? '') === '1' || $recoveryStep !== 'request';
$shouldOpenLogin = ($_GET['login'] ?? '') === '1' || $shouldOpenRecovery;
if ($shouldOpenLogin && isset($_SESSION['_login_error'])) {
    $loginError = (string)$_SESSION['_login_error'];
    unset($_SESSION['_login_error']);
}
$loginError = $loginError !== '' ? $loginError : $flashError;
$loginSuccess = (string)($_SESSION['_flash_success'] ?? '');
unset($_SESSION['_flash_success']);
unset($_SESSION['_login_username']);
?>
<!DOCTYPE html>
<html lang="en" data-type="software">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RetailMind - Store inventory, sales, and forecasting</title>
    <meta name="description" content="RetailMind keeps Shalom Store inventory, barcode sales, purchasing, cashier shifts, and demand forecasting in one operational workspace.">
    <meta name="page-type" content="software">
    <meta name="site-kind" content="software">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $faviconUrl ?>" data-brand-base="<?= $brandBaseUrl ?>" data-brand-variant="icons/STYLE/favicon-32.png">
    <link rel="stylesheet" href="<?= $styleUrl ?>">
    <link rel="stylesheet" href="<?= $landingStyleUrl ?>">
    <link rel="stylesheet" href="<?= $bootstrapIconsUrl ?>">
</head>

<body class="landing-page">
    <a class="landing-skip-link" href="#main-content">Skip to main content</a>
    <header class="landing-header">
        <nav class="landing-nav" aria-label="Primary">
            <div class="landing-nav__left">
                <a class="landing-brand" href="<?= htmlspecialchars(landing_app_url(), ENT_QUOTES, 'UTF-8') ?>">
                    <img class="landing-brand__logo" src="<?= $brandLogoUrl ?>" data-brand-base="<?= $brandBaseUrl ?>" data-brand-variant="logos/STYLE/logo-header.png" alt="RetailMind">
                    <span class="landing-brand__compact" aria-hidden="true">
                        <img src="<?= $brandIconUrl ?>" data-brand-base="<?= $brandBaseUrl ?>" data-brand-variant="icons/STYLE/icon-64.png" alt="">
                    </span>
                </a>
                <span class="landing-nav__status-pill" title="Operational Store Engine">
                    <span class="landing-status-dot"></span> Shalom Store Engine
                </span>
            </div>
            <div class="landing-nav__links">
                <div class="landing-nav__sections">
                    <a href="#capabilities">Capabilities</a>
                    <a href="#workflow">Workflow</a>
                </div>
                <a class="landing-nav__login" href="<?= $loginUrl ?>" data-login-modal-open>
                    <span>Staff login</span>
                    <svg class="landing-nav__login-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </a>
            </div>
        </nav>
    </header>

    <main id="main-content">
        <section class="landing-hero" aria-labelledby="landing-title">
            <div class="landing-hero__content">
                <h1 id="landing-title">
                    Know what is on the shelf.<br>
                    <span class="landing-hero__title-gradient">Reorder before it runs out.</span>
                </h1>
                <p class="landing-hero__copy">
                    Unified store operations for Shalom Store: barcode checkout, real-time inventory, batch receiving, and demand forecasting.
                </p>
                <div class="landing-actions">
                    <a class="btn landing-btn landing-btn--primary" href="<?= $loginUrl ?>" data-login-modal-open>
                        <span>Staff login</span>
                        <svg class="landing-btn__arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </a>
                    <a class="landing-btn landing-btn--secondary" href="#capabilities">
                        <span>Explore capabilities</span>
                        <svg class="landing-btn__arrow-down" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
                    </a>
                </div>
            </div>

            <figure class="landing-hero__visual">
                <div class="landing-hero__image">
                    <img <?= $shouldOpenLogin ? 'data-src' : 'src' ?>="<?= $heroImageUrl ?>" alt="Stocked Store shelves beside a barcode scanner and incoming inventory">
                    <figcaption>Stock visibility from shelf to receiving</figcaption>
                </div>
                <div class="landing-preview" aria-label="RetailMind operational flow">
                    <div class="landing-preview__header">
                        <strong>One connected Store record</strong>
                        <span class="landing-preview__state">Ready</span>
                    </div>
                    <ol>
                        <li><span>Sale</span><strong>Scan barcode</strong></li>
                        <li><span>Inventory</span><strong>Update stock</strong></li>
                        <li><span>Planning</span><strong>Review demand</strong></li>
                        <li><span>Purchasing</span><strong>Replenish</strong></li>
                    </ol>
                </div>
            </figure>
        </section>

        <!-- Capabilities Section -->
        <section id="capabilities" class="landing-system">
            <header class="landing-section-heading">
                <h2>The shelf, the till, and the order book agree.</h2>
                <p>Every checkout, receiving, and count updates the same operational record in real time.</p>
            </header>

            <div class="landing-capabilities">
                <article class="landing-capability landing-capability--lead">
                    <div class="capability-lead__header">
                        <h3>Inventory control</h3>
                        <p>Track live stock, batch expiries, and reorder levels in the same record used at checkout and receiving.</p>
                    </div>
                </article>

                <div class="landing-capability-list">
                    <!-- Barcode Sales -->
                    <article class="landing-capability-card landing-capability-card--azure">
                        <div class="capability-card__icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5v14M7 5v14M11 5v14M15 5v14M19 5v14M21 5v14"/></svg>
                        </div>
                        <div class="capability-card__body">
                            <h3>Barcode checkout</h3>
                            <p>Fast product scanning, held sales, split tenders, and receipt printing.</p>
                        </div>
                    </article>

                    <!-- Forecast Planning -->
                    <article class="landing-capability-card landing-capability-card--indigo">
                        <div class="capability-card__icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        </div>
                        <div class="capability-card__body">
                            <h3>Demand forecasting</h3>
                            <p>Projects product sales across 7, 14, and 28 days to prevent stockouts.</p>
                        </div>
                    </article>

                    <!-- Stock Receiving -->
                    <article class="landing-capability-card landing-capability-card--emerald">
                        <div class="capability-card__icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                        </div>
                        <div class="capability-card__body">
                            <h3>Stock receiving</h3>
                            <p>Receive cases or units with automatic pack conversion and unit cost calculation.</p>
                        </div>
                    </article>

                    <!-- Shift Closeout -->
                    <article class="landing-capability-card landing-capability-card--rose">
                        <div class="capability-card__icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="6" y1="8" x2="6" y2="8"/><line x1="10" y1="8" x2="10" y2="8"/><line x1="14" y1="8" x2="14" y2="8"/><line x1="18" y1="8" x2="18" y2="8"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="6" y1="16" x2="18" y2="16"/></svg>
                        </div>
                        <div class="capability-card__body">
                            <h3>Cashier shifts</h3>
                            <p>Track opening floats, cash drawer drops, and end-of-shift reconciliation.</p>
                        </div>
                    </article>

                    <!-- Operational Reports -->
                    <article class="landing-capability-card landing-capability-card--amber">
                        <div class="capability-card__icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        </div>
                        <div class="capability-card__body">
                            <h3>Reports &amp; audit trails</h3>
                            <p>Sales summaries, gross margins, audit logs, and full database backups.</p>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <!-- Workflow Section -->
        <section id="workflow" class="landing-workflow">
            <header class="landing-workflow__content">
                <h2>Each sale leaves the next decision better informed.</h2>
                <p>Sales feed directly into inventory and demand models, keeping shelf stock balanced.</p>
            </header>

            <ol class="landing-steps" aria-label="Inventory workflow steps">
                <li class="landing-step-card">
                    <div class="landing-step-card__num">01</div>
                    <div class="landing-step-card__content">
                        <h3>Scan and checkout</h3>
                        <p>Cashiers scan barcodes, apply discounts, and tender payments instantly.</p>
                    </div>
                </li>
                <li class="landing-step-card">
                    <div class="landing-step-card__num">02</div>
                    <div class="landing-step-card__content">
                        <h3>Update inventory</h3>
                        <p>Stock balances decrement in real time with alerts for low stock and expiring batches.</p>
                    </div>
                </li>
                <li class="landing-step-card">
                    <div class="landing-step-card__num">03</div>
                    <div class="landing-step-card__content">
                        <h3>Forecast demand</h3>
                        <p>Predictive models project sales over 7, 14, and 28 days to prevent stockouts.</p>
                    </div>
                </li>
                <li class="landing-step-card">
                    <div class="landing-step-card__num">04</div>
                    <div class="landing-step-card__content">
                        <h3>Replenish and receive</h3>
                        <p>Record received goods with automatic pack-to-unit conversion.</p>
                    </div>
                </li>
            </ol>
        </section>

        <!-- Access Section -->
        <section class="landing-access" aria-labelledby="access-title">
            <div class="landing-access__info">
                <h2 id="access-title">Your tools are ready at the counter.</h2>
                <p>Dedicated workspaces for each store role.</p>
                <div class="landing-roles-list">
                    <span class="landing-role-chip"><strong>Administrator</strong> Operations, settings, and backups</span>
                    <span class="landing-role-chip"><strong>Inventory Manager</strong> Stock receiving and forecasts</span>
                    <span class="landing-role-chip"><strong>Cashier</strong> Barcode sales and shift closeout</span>
                </div>
            </div>
            <div class="landing-access__actions">
                <a class="btn landing-btn landing-btn--primary landing-btn--lg" href="<?= $loginUrl ?>" data-login-modal-open>
                    <span>Log in to RetailMind</span>
                    <svg class="landing-btn__arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </a>
                <a class="landing-link" href="<?= $forgotPasswordUrl ?>" data-recovery-modal-open>Reset password</a>
            </div>
        </section>

        <!-- Footer -->
        <footer class="landing-footer">
            <div class="landing-footer__brand-block">
                <a class="landing-brand" href="<?= htmlspecialchars(landing_app_url(), ENT_QUOTES, 'UTF-8') ?>">
                    <img class="landing-brand__logo" src="<?= $brandLogoUrl ?>" data-brand-base="<?= $brandBaseUrl ?>" data-brand-variant="logos/STYLE/logo-header.png" alt="RetailMind">
                </a>
                <span class="landing-footer__motto">Shalom Store Operations</span>
            </div>
            <p class="landing-footer__copy">
                Operational software for Shalom Store.
            </p>
            <div class="landing-footer__links">
                <a href="#capabilities">Capabilities</a>
                <a href="#workflow">Workflow</a>
                <a href="<?= $loginUrl ?>" data-login-modal-open>Staff login</a>
            </div>
        </footer>
    </main>

    <div class="landing-login-modal<?= $shouldOpenLogin ? ' is-open' : '' ?><?= $shouldOpenRecovery ? ' is-recovery' : '' ?>" id="loginModal" role="dialog" aria-modal="true" aria-labelledby="<?= $shouldOpenRecovery ? 'recovery-modal-title' : 'login-modal-title' ?>" aria-hidden="<?= $shouldOpenLogin ? 'false' : 'true' ?>">
        <div class="landing-login-modal__backdrop" data-login-modal-close></div>
        <section class="landing-login-modal__panel" tabindex="-1">
            <button class="landing-login-modal__close" type="button" aria-label="Close dialog" data-login-modal-close>&times;</button>
            <div class="landing-login-modal__flipper">
            <div class="landing-login-modal__shell landing-login-modal__shell--login" aria-hidden="<?= $shouldOpenRecovery ? 'true' : 'false' ?>" <?= $shouldOpenRecovery ? 'inert' : '' ?>>
                <aside class="landing-login-modal__aside" aria-label="RetailMind access">
                    <span class="landing-login-modal__logo" aria-hidden="true">
                        <img src="<?= $brandIconUrl ?>" data-brand-base="<?= $brandBaseUrl ?>" data-brand-variant="icons/STYLE/icon-64.png" alt="">
                    </span>
                    <div>
                        <h2>Welcome back</h2>
                        <p>Access store inventory, sales, reports, and administrative tools.</p>
                    </div>
                </aside>
                <div class="landing-login-modal__body">
                    <div class="landing-login-modal__brand">
                        <h3 id="login-modal-title">Staff login</h3>
                    </div>
                    <?php if ($loginSuccess !== ''): ?><div id="rm-flash-messages" class="alert tag-success" data-success="<?= htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

                    <form method="POST" action="<?= $loginActionUrl ?>" class="landing-login-form">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="landing-login-username">Username or email</label>
                            <input type="text" id="landing-login-username" name="username" value="<?= htmlspecialchars($loginUsername, ENT_QUOTES, 'UTF-8') ?>" placeholder="username or email" required autocomplete="username">
                        </div>
                        <div class="form-group<?= $loginError !== '' ? ' invalid' : '' ?>">
                            <label for="landing-login-password">Password</label>
                            <div class="password-input">
                                <input type="password" id="landing-login-password" name="password" placeholder="Enter your password" required autocomplete="current-password"<?= $loginError !== '' ? ' class="is-invalid" aria-invalid="true"' : '' ?>>
                                <button type="button" class="password-toggle" id="landing-login-password-toggle" aria-controls="landing-login-password" aria-pressed="false" aria-label="Show password">Show</button>
                            </div>
                            <?php if ($loginError !== ''): ?>
                                <div class="error-msg field-error" role="alert" data-rm-alert-processed="1"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="landing-login-modal__actions">
                            <button type="submit" class="btn btn-block landing-btn landing-btn--primary">Log in</button>
                            <a href="<?= $forgotPasswordUrl ?>" data-recovery-modal-open>Forgot password?</a>
                        </div>
                    </form>
                </div>
            </div>
            <div class="landing-login-modal__shell landing-login-modal__shell--recovery" aria-hidden="<?= $shouldOpenRecovery ? 'false' : 'true' ?>" <?= $shouldOpenRecovery ? '' : 'inert' ?>>
                <aside class="landing-login-modal__aside landing-login-modal__aside--recovery" aria-label="Password recovery guidance">
                    <span class="landing-login-modal__logo" aria-hidden="true">
                        <img src="<?= $brandIconUrl ?>" data-brand-base="<?= $brandBaseUrl ?>" data-brand-variant="icons/STYLE/icon-64.png" alt="">
                    </span>
                    <div>
                        <h2>Reset password</h2>
                        <?php if ($recoveryStep === 'verify_code'): ?>
                            <p>Enter the 6-digit verification code sent to your email.</p>
                        <?php elseif ($recoveryStep === 'set_password'): ?>
                            <p>Choose a secure new password for your account.</p>
                        <?php else: ?>
                            <p>Receive a 6-digit verification code via email.</p>
                        <?php endif; ?>
                    </div>
                </aside>
                <div class="landing-login-modal__body landing-login-modal__body--recovery">
                    <div class="landing-login-modal__brand">
                        <h3 id="recovery-modal-title">
                            <?php if ($recoveryStep === 'verify_code'): ?>
                                Enter Verification Code
                            <?php elseif ($recoveryStep === 'set_password'): ?>
                                Choose New Password
                            <?php else: ?>
                                Reset Password
                            <?php endif; ?>
                        </h3>
                    </div>

                    <?php if ($recoveryMessage !== ''): ?>
                        <div class="landing-login-modal__notice <?= $recoveryMessageClass ?>" role="<?= $recoveryMessageClass === 'tag-warning' ? 'alert' : 'status' ?>" aria-live="<?= $recoveryMessageClass === 'tag-warning' ? 'assertive' : 'polite' ?>"><?= htmlspecialchars($recoveryMessage, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <?php if ($recoveryStep === 'verify_code'): ?>
                        <form method="POST" action="<?= $forgotPasswordUrl ?>" class="landing-login-form landing-recovery-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="verify_code">
                            <div class="form-group" style="text-align:center;">
                                <label for="landing-recovery-code" style="display:block;margin-bottom:8px;font-weight:600;">6-digit verification code</label>
                                <input type="text" id="landing-recovery-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="one-time-code" style="letter-spacing:8px;font-size:1.6rem;font-weight:700;text-align:center;font-family:monospace;padding:10px 14px;" required autofocus>
                                <small id="codeExpiresCountdown" data-seconds="<?= $codeExpiresIn ?>" class="field-help" style="display:block;margin-top:6px;color:#64748b;">Code expires in <strong id="codeExpiresTime"><?= $expFormatted ?></strong></small>
                            </div>
                            <div class="landing-login-modal__actions">
                                <button type="submit" class="btn btn-block landing-btn landing-btn--primary">Verify code</button>
                                <div style="display:flex;justify-content:space-between;align-items:center;width:100%;margin-top:14px;font-size:0.875rem;">
                                    <?php if ($resendCooldown > 0): ?>
                                        <span id="resendCountdown" data-seconds="<?= $resendCooldown ?>" style="color:#94a3b8;">Resend in <strong id="resendSeconds"><?= $resendCooldown ?></strong>s</span>
                                        <a href="<?= $forgotPasswordUrl ?>?action=resend" id="resendLink" style="color:var(--primary);text-decoration:none;display:none;">Resend code</a>
                                    <?php else: ?>
                                        <a href="<?= $forgotPasswordUrl ?>?action=resend" id="resendLink" style="color:var(--primary);text-decoration:none;">Resend code</a>
                                    <?php endif; ?>
                                    <a href="<?= $forgotPasswordUrl ?>?action=cancel" style="color:#64748b;text-decoration:none;">Cancel</a>
                                </div>
                            </div>
                        </form>
                    <?php elseif ($recoveryStep === 'set_password'): ?>
                        <form method="POST" action="<?= $forgotPasswordUrl ?>" class="landing-login-form landing-recovery-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_password">
                            <div class="form-group">
                                <label for="landing-recovery-newpass">New password</label>
                                <input type="password" id="landing-recovery-newpass" name="password" minlength="8" placeholder="At least 8 characters" autocomplete="new-password" required autofocus>
                                <small class="field-help" style="display:block;margin-top:4px;color:#64748b;">Uppercase, lowercase, and a number.</small>
                            </div>
                            <div class="form-group">
                                <label for="landing-recovery-confirm">Confirm new password</label>
                                <input type="password" id="landing-recovery-confirm" name="password_confirm" minlength="8" placeholder="Confirm new password" autocomplete="new-password" required>
                            </div>
                            <div class="landing-login-modal__actions">
                                <button type="submit" class="btn btn-block landing-btn landing-btn--primary">Save password & sign in</button>
                                <div style="display:flex;justify-content:center;width:100%;margin-top:12px;font-size:0.875rem;">
                                    <a href="<?= $forgotPasswordUrl ?>?action=cancel" style="color:#64748b;text-decoration:none;">Cancel</a>
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="POST" action="<?= $forgotPasswordUrl ?>" class="landing-login-form landing-recovery-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="request_code">
                            <div class="form-group">
                                <label for="landing-recovery-identity">Username or email</label>
                                <input type="text" id="landing-recovery-identity" name="identity" value="<?= htmlspecialchars($recoveryIdentity, ENT_QUOTES, 'UTF-8') ?>" placeholder="username or email" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                            </div>
                            <div class="landing-login-modal__actions">
                                <button type="submit" class="btn btn-block landing-btn landing-btn--primary">Send verification code</button>
                                <a href="<?= $loginUrl ?>" data-recovery-modal-back>Back to login</a>
                            </div>
                        </form>
                    <?php endif; ?>
                    <p class="landing-login-modal__privacy">No internet link required. Enter the 6-digit PIN on this screen.</p>
                </div>
            </div>
            </div>
        </section>
    </div>

    <link rel="stylesheet" href="<?= htmlspecialchars(landing_app_url('assets/vendor/sweetalert2/sweetalert2.min.css'), ENT_QUOTES, 'UTF-8') ?>">
    <script src="<?= htmlspecialchars(landing_app_url('assets/vendor/sweetalert2/sweetalert2.all.min.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script>window.RM_DEBUG = <?= !empty($GLOBALS['app']['debug']) ? 'true' : 'false' ?>;</script>
    <script src="<?= htmlspecialchars(landing_app_url('assets/js/ui.js?v=' . filemtime(__DIR__ . '/assets/js/ui.js')), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script>
        (function() {
            var modal = document.getElementById('loginModal');
            if (!modal) {
                return;
            }

            var panel = modal.querySelector('.landing-login-modal__panel');
            var username = document.getElementById('landing-login-username');
            var password = document.getElementById('landing-login-password');
            var passwordToggle = document.getElementById('landing-login-password-toggle');
            var recoveryIdentity = document.getElementById('landing-recovery-identity');
            var loginFace = modal.querySelector('.landing-login-modal__shell--login');
            var recoveryFace = modal.querySelector('.landing-login-modal__shell--recovery');
            var loginForm = loginFace.querySelector('.landing-login-form');
            var recoveryForm = recoveryFace ? recoveryFace.querySelector('.landing-recovery-form') : null;
            var openers = document.querySelectorAll('[data-login-modal-open]');
            var recoveryOpeners = document.querySelectorAll('[data-recovery-modal-open]');
            var recoveryBack = modal.querySelector('[data-recovery-modal-back]');
            var closers = modal.querySelectorAll('[data-login-modal-close]');
            var pageRegions = [document.querySelector('.landing-header'), document.getElementById('main-content')].filter(Boolean);
            var focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';
            var activeOpener = null;
            var viewFocusTimer = null;
            var passwordStateKey = 'retailmind.login.password';
            var hasLoginError = Boolean(modal.querySelector('.error-msg'));
            var loginFormState = {
                username: username ? username.value : '',
                password: password ? password.value : ''
            };

            if (hasLoginError && password) {
                try {
                    password.value = window.sessionStorage.getItem(passwordStateKey) || '';
                    window.sessionStorage.removeItem(passwordStateKey);
                } catch (error) {}
                loginFormState.password = password.value;
            } else {
                try {
                    window.sessionStorage.removeItem(passwordStateKey);
                } catch (error) {}
            }

            function saveFormState() {
                loginFormState.username = username ? username.value : '';
                loginFormState.password = password ? password.value : '';
            }

            function restoreFormState() {
                if (username) {
                    username.value = loginFormState.username;
                }
                if (password) {
                    password.value = loginFormState.password;
                }
            }

            function setPageInert(isInert) {
                pageRegions.forEach(function(region) {
                    region.inert = isInert;
                });
            }

            function setView(view, shouldFocus) {
                var isRecovery = view === 'recovery';
                if (viewFocusTimer !== null) {
                    window.clearTimeout(viewFocusTimer);
                    viewFocusTimer = null;
                }
                modal.classList.toggle('is-recovery', isRecovery);
                modal.setAttribute('aria-labelledby', isRecovery ? 'recovery-modal-title' : 'login-modal-title');
                loginFace.inert = isRecovery;
                recoveryFace.inert = !isRecovery;
                loginFace.setAttribute('aria-hidden', isRecovery ? 'true' : 'false');
                recoveryFace.setAttribute('aria-hidden', isRecovery ? 'false' : 'true');
                if (shouldFocus) {
                    viewFocusTimer = window.setTimeout(function() {
                        (isRecovery ? recoveryIdentity : username || panel).focus();
                        viewFocusTimer = null;
                    }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 440);
                }
            }

            function morph(from, to, update) {
                var root = document.documentElement;
                if (!document.startViewTransition || window.matchMedia('(prefers-reduced-motion: reduce)').matches || !from || !to) {
                    update();
                    return;
                }
                root.classList.add('vt-active');
                from.style.viewTransitionName = 'login-surface';
                var transition = document.startViewTransition(function() {
                    from.style.viewTransitionName = '';
                    to.style.viewTransitionName = 'login-surface';
                    update();
                });
                transition.finished.finally(function() {
                    to.style.viewTransitionName = '';
                    root.classList.remove('vt-active');
                });
            }

            function openModal(event, view) {
                if (event) {
                    event.preventDefault();
                    if (!modal.contains(event.currentTarget)) {
                        activeOpener = event.currentTarget;
                    }
                }
                saveFormState();
                if (view !== 'recovery') {
                    restoreFormState();
                }
                var wasOpen = modal.classList.contains('is-open');
                morph(wasOpen ? null : activeOpener, panel, function() {
                    modal.classList.add('is-open');
                    modal.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('landing-modal-open');
                    setPageInert(true);
                    setView(view, true);
                });
            }

            function closeModal() {
                document.querySelectorAll('img[data-src]').forEach(function(image) {
                    image.src = image.dataset.src;
                    image.removeAttribute('data-src');
                });
                if (viewFocusTimer !== null) {
                    window.clearTimeout(viewFocusTimer);
                    viewFocusTimer = null;
                }
                saveFormState();
                var returnTarget = activeOpener && document.contains(activeOpener) ? activeOpener : openers[0];
                activeOpener = null;
                morph(panel, returnTarget, function() {
                    modal.classList.remove('is-open');
                    modal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('landing-modal-open');
                    setPageInert(false);
                    if (returnTarget) {
                        returnTarget.focus();
                    }
                });
            }

            openers.forEach(function(opener) {
                opener.addEventListener('click', function(event) { openModal(event, 'login'); });
            });
            recoveryOpeners.forEach(function(opener) {
                opener.addEventListener('click', function(event) { openModal(event, 'recovery'); });
            });
            if (recoveryBack) {
                recoveryBack.addEventListener('click', function(event) { openModal(event, 'login'); });
            }
            closers.forEach(function(closer) {
                closer.addEventListener('click', closeModal);
            });
            if (loginForm) {
                var isLoginSubmitting = false;
                loginForm.addEventListener('submit', async function(event) {
                    event.preventDefault();
                    if (isLoginSubmitting) return;
                    isLoginSubmitting = true;
                    var submitBtn = loginForm.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.classList.add('btn-loading');
                    }
                    try {
                        if (window.sessionStorage) {
                            window.sessionStorage.setItem(passwordStateKey, password ? password.value : '');
                        }
                    } catch (error) {}
                    var refreshController = new AbortController();
                    var refreshTimeout = window.setTimeout(function() {
                        refreshController.abort();
                    }, 5000);
                    try {
                        var response = await fetch(loginForm.action + '?csrf_refresh=1', {
                            credentials: 'same-origin',
                            cache: 'no-store',
                            signal: refreshController.signal
                        });
                        if (response.ok) {
                            var data = await response.json();
                            if (typeof data.csrf_token === 'string') {
                                loginForm.elements.csrf_token.value = data.csrf_token;
                            }
                        }
                    } catch (error) {
                        // The POST still validates the form token if refresh is unavailable.
                    } finally {
                        window.clearTimeout(refreshTimeout);
                    }
                    HTMLFormElement.prototype.submit.call(loginForm);
                });
                window.addEventListener('pageshow', function() {
                    isLoginSubmitting = false;
                    var submitBtn = loginForm.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('btn-loading');
                    }
                });
            }
            if (password && passwordToggle) {
                passwordToggle.addEventListener('click', function() {
                    var isVisible = password.type === 'text';
                    password.type = isVisible ? 'password' : 'text';
                    passwordToggle.textContent = isVisible ? 'Show' : 'Hide';
                    passwordToggle.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
                    passwordToggle.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
                });
            }
            function clearInputError(inputEl) {
                if (!inputEl) return;
                inputEl.classList.remove('is-invalid');
                inputEl.removeAttribute('aria-invalid');
                var group = inputEl.closest('.form-group');
                if (group) {
                    group.classList.remove('invalid');
                }
                var err = modal.querySelector('.field-error, .error-msg');
                if (err) {
                    err.remove();
                }
            }
            if (recoveryForm) {
                var isRecoverySubmitting = false;
                recoveryForm.addEventListener('submit', function(event) {
                    if (isRecoverySubmitting) {
                        event.preventDefault();
                        return;
                    }
                    var submitBtn = recoveryForm.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        isRecoverySubmitting = true;
                        submitBtn.disabled = true;
                        submitBtn.classList.add('btn-loading');
                        submitBtn.setAttribute('data-original-text', submitBtn.textContent);
                        submitBtn.textContent = 'Please wait...';
                    }
                });

                var expEl = document.getElementById('codeExpiresCountdown');
                if (expEl) {
                    var expSec = parseInt(expEl.getAttribute('data-seconds'), 10) || 0;
                    var expDisplay = document.getElementById('codeExpiresTime');
                    var expTimer = setInterval(function() {
                        expSec--;
                        if (expSec <= 0) {
                            clearInterval(expTimer);
                            expEl.innerHTML = '<span style="color:#ef4444;font-weight:600;">Code has expired.</span> Please request a new code.';
                            return;
                        }
                        if (expDisplay) {
                            var mins = Math.floor(expSec / 60);
                            var secs = expSec % 60;
                            expDisplay.textContent = mins + ':' + (secs < 10 ? '0' : '') + secs;
                        }
                    }, 1000);
                }

                var countdownEl = document.getElementById('resendCountdown');
                var resendLink = document.getElementById('resendLink');
                if (countdownEl && resendLink) {
                    var sec = parseInt(countdownEl.getAttribute('data-seconds'), 10) || 0;
                    var timer = setInterval(function() {
                        sec--;
                        var secDisplay = document.getElementById('resendSeconds');
                        if (secDisplay) secDisplay.textContent = sec;
                        if (sec <= 0) {
                            clearInterval(timer);
                            countdownEl.style.display = 'none';
                            resendLink.style.display = 'inline';
                        }
                    }, 1000);
                }
            }
            if (password) {
                password.addEventListener('input', function() { clearInputError(password); });
            }
            if (username) {
                username.addEventListener('input', function() { clearInputError(username); });
            }
            document.addEventListener('keydown', function(event) {
                if (!modal.classList.contains('is-open')) {
                    return;
                }
                if (event.key === 'Escape') {
                    if (modal.querySelector('.theme-mobile-menu[open]')) {
                        return;
                    }
                    event.preventDefault();
                    closeModal();
                    return;
                }
                if (event.key === 'Tab') {
                    var focusable = Array.prototype.filter.call(modal.querySelectorAll(focusableSelector), function(element) {
                        return element.offsetParent !== null && !element.closest('[inert]');
                    });
                    if (focusable.length === 0) {
                        event.preventDefault();
                        panel.focus();
                        return;
                    }
                    var first = focusable[0];
                    var last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            });

            if (modal.classList.contains('is-open')) {
                document.body.classList.add('landing-modal-open');
                setPageInert(true);
                window.setTimeout(function() {
                    if (modal.classList.contains('is-recovery')) {
                        var recoveryCodeInput = document.getElementById('landing-recovery-code');
                        if (recoveryCodeInput) {
                            recoveryCodeInput.focus();
                        } else if (recoveryIdentity) {
                            recoveryIdentity.focus();
                        }
                    } else if (hasLoginError && password && username && username.value.trim() !== '') {
                        password.focus();
                        if (typeof password.select === 'function') password.select();
                    } else {
                        (username || panel).focus();
                    }
                }, 50);
            }
        })();
    </script>
    <script src="<?= htmlspecialchars(landing_app_url('assets/js/sw-register.js'), ENT_QUOTES, 'UTF-8') ?>" data-sw="<?= htmlspecialchars(landing_app_url('sw.js'), ENT_QUOTES, 'UTF-8') ?>" data-scope="<?= htmlspecialchars(landing_app_url('/'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body>

</html>
