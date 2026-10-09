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
$styleUrl = htmlspecialchars(landing_app_url('assets/css/style.css?v=20260928d'), ENT_QUOTES, 'UTF-8');
$landingStyleUrl = htmlspecialchars(landing_app_url('assets/css/landing.css?v=' . filemtime(__DIR__ . '/assets/css/landing.css')), ENT_QUOTES, 'UTF-8');
$faviconUrl = htmlspecialchars(landing_app_url('assets/img/retailmind-favicon-32.png'), ENT_QUOTES, 'UTF-8');
$brandLogoUrl = htmlspecialchars(landing_app_url('assets/img/retailmind-logo-600x200.png'), ENT_QUOTES, 'UTF-8');
$heroImageUrl = htmlspecialchars(landing_app_url('assets/img/retailmind-landing-hero.jpg'), ENT_QUOTES, 'UTF-8');
$brandIconUrl = htmlspecialchars(landing_app_url('assets/img/retailmind-icon-512.png'), ENT_QUOTES, 'UTF-8');
$loginError = '';
$loginSuccess = '';
$flashError = (string)($_SESSION['_flash_error'] ?? '');
unset($_SESSION['_flash_error']);
$loginUsername = (string)($_SESSION['_login_username'] ?? '');
$recoveryMessage = (string)($_SESSION['_recovery_message'] ?? '');
$recoveryMessageClass = ($_SESSION['_recovery_message_class'] ?? '') === 'tag-warning' ? 'tag-warning' : 'tag-success';
$recoveryIdentity = (string)($_SESSION['_recovery_identity'] ?? '');
unset($_SESSION['_recovery_message'], $_SESSION['_recovery_message_class'], $_SESSION['_recovery_identity']);
$shouldOpenRecovery = ($_GET['recovery'] ?? '') === '1';
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
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RetailMind - Store inventory, sales, and forecasting</title>
    <meta name="description" content="RetailMind keeps Shalom Store inventory, barcode sales, purchasing, cashier shifts, and demand forecasting in one operational workspace.">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $faviconUrl ?>">
    <link rel="stylesheet" href="<?= $styleUrl ?>">
    <link rel="stylesheet" href="<?= $landingStyleUrl ?>">
</head>

<body class="landing-page">
    <a class="landing-skip-link" href="#main-content">Skip to main content</a>
    <header class="landing-header">
        <nav class="landing-nav" aria-label="Primary">
            <a class="landing-brand" href="<?= htmlspecialchars(landing_app_url(), ENT_QUOTES, 'UTF-8') ?>">
                <img class="landing-brand__logo" src="<?= $brandLogoUrl ?>" alt="RetailMind">
                <span class="landing-brand__compact" aria-hidden="true">
                    <img src="<?= $brandIconUrl ?>" alt="">
                </span>
            </a>
            <div class="landing-nav__links">
                <div class="landing-nav__sections">
                <a href="#capabilities">Capabilities</a>
                <a href="#workflow">Workflow</a>
                </div>
                <a class="landing-nav__login" href="<?= $loginUrl ?>" data-login-modal-open>Staff login</a>
            </div>
        </nav>
    </header>

    <main id="main-content">
        <section class="landing-hero" aria-labelledby="landing-title">
            <div class="landing-hero__content">
                <h1 id="landing-title">Know what is on the shelf. Reorder before it runs out.</h1>
                <p class="landing-hero__copy">
                    RetailMind gives Shalom Store one working system for product stock, barcode sales,
                    purchasing, cashier shifts, reports, and demand forecasts.
                </p>
                <div class="landing-actions">
                    <a class="btn landing-btn landing-btn--primary" href="<?= $loginUrl ?>" data-login-modal-open>Open staff workspace</a>
                    <a class="landing-text-link" href="#workflow">View the operating process</a>
                </div>
            </div>
            <figure class="landing-hero__visual">
                <div class="landing-hero__image">
                    <img src="<?= $heroImageUrl ?>" alt="Stocked Store shelves beside a barcode scanner and incoming inventory">
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

        <section id="capabilities" class="landing-system">
            <header class="landing-section-heading">
                <h2>The shelf, the till, and the order book agree.</h2>
                <p>Store teams move through the day without rebuilding the same information in separate tools.</p>
            </header>

            <div class="landing-capabilities">
                <article class="landing-capability landing-capability--lead">
                    <h3>Inventory control</h3>
                    <p>See low stock, expiry risk, dead stock, excess stock, and ABC cycle-count priorities in the same record used for receiving and sales.</p>
                    <ul aria-label="Inventory control details">
                        <li>Stock status</li>
                        <li>Expiry exposure</li>
                        <li>Count priorities</li>
                    </ul>
                </article>
                <div class="landing-capability-list">
                    <article class="landing-capability">
                        <div><h3>Barcode sales</h3><p>Scan labels, hold sales, control discounts, and print receipts.</p></div>
                    </article>
                    <article class="landing-capability">
                        <div><h3>Forecast planning</h3><p>Compare Random Forest forecasts, confidence ranges, and reorder inputs.</p></div>
                    </article>
                    <article class="landing-capability">
                        <div><h3>Purchase flow</h3><p>Manage suppliers, approvals, package conversion, and partial receiving.</p></div>
                    </article>
                    <article class="landing-capability">
                        <div><h3>Shift closeout</h3><p>Track pay-ins, pay-outs, expected cash, and end-of-shift variance.</p></div>
                    </article>
                    <article class="landing-capability">
                        <div><h3>Operational reports</h3><p>Review inventory insights, forecast exceptions, backups, and notifications.</p></div>
                    </article>
                </div>
            </div>
        </section>

        <section id="workflow" class="landing-workflow">
            <header class="landing-workflow__content">
                <h2>Each sale leaves the next decision better informed.</h2>
                <p>
                    Sales activity updates stock movement and forecast history. Managers review uncertain items,
                    adjust recommendations where needed, and create purchase orders with the right approvals.
                </p>
            </header>
            <ol class="landing-steps" aria-label="Inventory workflow steps">
                <li><span>01</span><h3>Sell and scan</h3><p>Cashiers process barcode sales and shift activity.</p></li>
                <li><span>02</span><h3>Monitor stock</h3><p>Inventory teams catch low, stale, excess, and expiry-risk items.</p></li>
                <li><span>03</span><h3>Review demand</h3><p>The model compares recent patterns with baseline performance.</p></li>
                <li><span>04</span><h3>Replenish</h3><p>Reviewed recommendations become controlled purchase orders.</p></li>
            </ol>
        </section>

        <section class="landing-access" aria-labelledby="access-title">
              <div><h2 id="access-title">Your tools are ready at the counter.</h2><p>Administrators, Inventory Managers, and Cashiers are sent to the workspace assigned to their role.</p></div>
              <div class="landing-access__actions">
                <a class="btn landing-btn landing-btn--primary" href="<?= $loginUrl ?>" data-login-modal-open>Log in to RetailMind</a>
                <a class="landing-link" href="<?= $forgotPasswordUrl ?>" data-recovery-modal-open>Reset password</a>
              </div>
            </section>

        <footer class="landing-footer">
              <a class="landing-brand" href="<?= htmlspecialchars(landing_app_url(), ENT_QUOTES, 'UTF-8') ?>"><img class="landing-brand__logo" src="<?= $brandLogoUrl ?>" alt="RetailMind"></a>
              <p>Inventory, sales, purchasing, shifts, reporting, and Demand Forecasts in one operational workspace.</p>
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
                        <img src="<?= $brandIconUrl ?>" alt="">
                    </span>
                    <div>
                        <h2>Welcome back</h2>
                        <p>Access inventory, sales, reports, and system tools from one workspace.</p>
                    </div>
                </aside>
                <div class="landing-login-modal__body">
                    <div class="landing-login-modal__brand">
                        <div>
                            <h3 id="login-modal-title">Log in</h3>
                            <p>Enter your credentials to continue.</p>
                        </div>
                    </div>

                    <?php if ($loginError !== ''): ?>
                        <div class="error-msg"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($loginSuccess !== ''): ?><div id="rm-flash-messages" class="alert tag-success" data-success="<?= htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

                    <form method="POST" action="<?= $loginActionUrl ?>" class="landing-login-form">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="landing-login-username">Username or email</label>
                            <input type="text" id="landing-login-username" name="username" value="<?= htmlspecialchars($loginUsername, ENT_QUOTES, 'UTF-8') ?>" placeholder="Enter your username or email" required autocomplete="username">
                        </div>
                        <div class="form-group">
                            <label for="landing-login-password">Password</label>
                            <div class="password-input">
                                <input type="password" id="landing-login-password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                                <button type="button" class="password-toggle" id="landing-login-password-toggle" aria-controls="landing-login-password" aria-pressed="false" aria-label="Show password">Show</button>
                            </div>
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
                        <img src="<?= $brandIconUrl ?>" alt="">
                    </span>
                    <div>
                        <h2>Find your way back</h2>
                        <p>Request a link, then choose a new password from your email. Your access stays unchanged until you do.</p>
                    </div>
                </aside>
                <div class="landing-login-modal__body landing-login-modal__body--recovery">
                    <div class="landing-login-modal__brand">
                        <h3 id="recovery-modal-title">Reset your password</h3>
                        <p>Enter your staff username or email. If the account can receive email, we’ll send a secure link.</p>
                    </div>

                    <?php if ($recoveryMessage !== ''): ?>
                        <div class="landing-login-modal__notice <?= $recoveryMessageClass ?>" role="<?= $recoveryMessageClass === 'tag-warning' ? 'alert' : 'status' ?>" aria-live="<?= $recoveryMessageClass === 'tag-warning' ? 'assertive' : 'polite' ?>"><?= htmlspecialchars($recoveryMessage, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="POST" action="<?= $forgotPasswordUrl ?>" class="landing-login-form landing-recovery-form">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="landing-recovery-identity">Username or email</label>
                            <input type="text" id="landing-recovery-identity" name="identity" value="<?= htmlspecialchars($recoveryIdentity, ENT_QUOTES, 'UTF-8') ?>" placeholder="Enter your username or email" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                        </div>
                        <div class="landing-login-modal__actions">
                            <button type="submit" class="btn btn-block landing-btn landing-btn--primary">Send reset link</button>
                            <a href="<?= $loginUrl ?>" data-recovery-modal-back>Back to login</a>
                        </div>
                    </form>
                    <p class="landing-login-modal__privacy">For privacy, we show the same confirmation whether or not an account matches. Links expire after 30 minutes.</p>
                </div>
            </div>
            </div>
        </section>
    </div>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>window.RM_DEBUG = <?= !empty($GLOBALS['app']['debug']) ? 'true' : 'false' ?>;</script>
    <script src="<?= htmlspecialchars(landing_app_url('assets/js/ui.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
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
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('landing-modal-open');
                setPageInert(true);
                setView(view, true);
            }

            function closeModal() {
                if (viewFocusTimer !== null) {
                    window.clearTimeout(viewFocusTimer);
                    viewFocusTimer = null;
                }
                saveFormState();
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('landing-modal-open');
                setPageInert(false);
                var returnTarget = activeOpener && document.contains(activeOpener) ? activeOpener : openers[0];
                activeOpener = null;
                if (returnTarget) {
                    returnTarget.focus();
                }
            }

            openers.forEach(function(opener) {
                opener.addEventListener('click', function(event) { openModal(event, 'login'); });
            });
            recoveryOpeners.forEach(function(opener) {
                opener.addEventListener('click', function(event) { openModal(event, 'recovery'); });
            });
            recoveryBack.addEventListener('click', function(event) { openModal(event, 'login'); });
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
                    (modal.classList.contains('is-recovery') ? recoveryIdentity : username || panel).focus();
                }, 50);
            }
        })();
    </script>
</body>

</html>
