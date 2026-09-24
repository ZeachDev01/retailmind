<?php
// Public landing page. Authenticated users are sent to their primary workspace.
require_once dirname(__DIR__) . '/backend/bootstrap/app.php';

App\Core\Session::start();
require_once dirname(__DIR__) . '/backend/includes/csrf.php';

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once dirname(__DIR__) . '/backend/includes/auth.php';

    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $_SESSION['_login_username'] = $username;

    if ($username && $password && login_user($pdo, $username, $password)) {
        header('Location: ' . landing_app_url('?login_success=1'));
        exit;
    }

    $_SESSION['_login_error'] = last_login_error();
    header('Location: ' . landing_app_url('?login=1'));
    exit;
}

if (isset($_SESSION['user_id']) && ($_GET['login_success'] ?? '') !== '1') {
    landing_redirect_by_role();
}

$loginUrl = htmlspecialchars(landing_app_url('?login=1'), ENT_QUOTES, 'UTF-8');
$loginActionUrl = htmlspecialchars(landing_app_url(), ENT_QUOTES, 'UTF-8');
$forgotPasswordUrl = htmlspecialchars(landing_app_url('components/auth/forgot_password.php'), ENT_QUOTES, 'UTF-8');
$styleUrl = htmlspecialchars(landing_app_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8');
$loginError = '';
$loginSuccess = '';
$loginSuccessRedirect = '';
$flashError = (string)($_SESSION['_flash_error'] ?? '');
unset($_SESSION['_flash_error']);
$loginUsername = (string)($_SESSION['_login_username'] ?? '');
$shouldOpenLogin = ($_GET['login'] ?? '') === '1';
if ($shouldOpenLogin && isset($_SESSION['_login_error'])) {
    $loginError = (string)$_SESSION['_login_error'];
    unset($_SESSION['_login_error']);
}
$loginError = $loginError !== '' ? $loginError : $flashError;
$loginSuccess = (string)($_SESSION['_flash_success'] ?? '');
unset($_SESSION['_flash_success']);
if (($_GET['login_success'] ?? '') === '1' && isset($_SESSION['user_id'])) {
    $loginSuccess = 'Login successful.';
    $loginSuccessRedirect = landing_role_destination();
}
unset($_SESSION['_login_username']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RetailMind — Store inventory, sales, and forecasting</title>
    <meta name="description" content="RetailMind keeps Shalom Store inventory, barcode sales, purchasing, cashier shifts, and demand forecasting in one operational workspace.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $styleUrl ?>">
</head>

<body class="landing-page">
    <header class="landing-header">
        <nav class="landing-nav" aria-label="Primary">
            <a class="landing-brand" href="<?= htmlspecialchars(landing_app_url(), ENT_QUOTES, 'UTF-8') ?>">
                <span class="landing-brand__mark" aria-hidden="true">RM</span>
                <span>
                    <strong>RetailMind</strong>
                    <small>Shalom Store / Operations</small>
                </span>
            </a>
            <div class="landing-nav__links">
                <a href="#capabilities">System</a>
                <a href="#workflow">Process</a>
                <a class="landing-nav__login" href="<?= $loginUrl ?>" data-login-modal-open>Staff login</a>
            </div>
        </nav>
    </header>

    <main>
        <section class="landing-hero" aria-labelledby="landing-title">
            <div class="landing-hero__content">
                <p class="landing-kicker"><span>01</span> Store operations, kept in order</p>
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
                <img src="<?= htmlspecialchars(landing_app_url('assets/img/landing-hero.png'), ENT_QUOTES, 'UTF-8') ?>" alt="Barcode scanner and inventory dashboard at a stocked retail counter">
                <figcaption>
                    <span>RM / INVENTORY DESK</span>
                    Live stock, checkout data, and purchasing decisions stay connected.
                </figcaption>
            </figure>
            <div class="landing-hero__index" aria-hidden="true">
                <span>SCAN</span><span>COUNT</span><span>FORECAST</span><span>ORDER</span>
            </div>
        </section>

        <section id="capabilities" class="landing-system">
            <header class="landing-section-heading">
                <p class="landing-kicker"><span>02</span> One operating record</p>
                <h2>The shelf, the till, and the order book agree.</h2>
                <p>Store teams move through the day without rebuilding the same information in separate tools.</p>
            </header>

            <div class="landing-capabilities">
                <article class="landing-capability landing-capability--lead">
                    <p class="landing-capability__code">INV / 001</p>
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
                        <p class="landing-capability__code">POS / 002</p>
                        <div><h3>Barcode sales</h3><p>Scan labels, hold sales, control discounts, and print receipts.</p></div>
                    </article>
                    <article class="landing-capability">
                        <p class="landing-capability__code">FCST / 003</p>
                        <div><h3>Forecast planning</h3><p>Compare Random Forest forecasts, confidence ranges, and reorder inputs.</p></div>
                    </article>
                    <article class="landing-capability">
                        <p class="landing-capability__code">PO / 004</p>
                        <div><h3>Purchase flow</h3><p>Manage suppliers, approvals, package conversion, and partial receiving.</p></div>
                    </article>
                    <article class="landing-capability">
                        <p class="landing-capability__code">SHIFT / 005</p>
                        <div><h3>Shift closeout</h3><p>Track pay-ins, pay-outs, expected cash, and end-of-shift variance.</p></div>
                    </article>
                    <article class="landing-capability">
                        <p class="landing-capability__code">RPT / 006</p>
                        <div><h3>Operational reports</h3><p>Review inventory insights, forecast exceptions, backups, and notifications.</p></div>
                    </article>
                </div>
            </div>
        </section>

        <section id="workflow" class="landing-workflow">
            <header class="landing-workflow__content">
                <p class="landing-kicker"><span>03</span> The operating process</p>
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
            <p class="landing-access__label">STAFF ACCESS / SECURE</p>
            <div>
                <h2 id="access-title">Your tools are ready at the counter.</h2>
                <p>Administrators, Inventory Managers, and Cashiers are sent to the workspace assigned to their role.</p>
            </div>
            <div class="landing-access__actions">
                <a class="btn landing-btn landing-btn--primary" href="<?= $loginUrl ?>" data-login-modal-open>Log in to RetailMind</a>
                <a class="landing-link" href="<?= $forgotPasswordUrl ?>">Reset password</a>
            </div>
        </section>
    </main>

    <div class="landing-login-modal<?= $shouldOpenLogin ? ' is-open' : '' ?>" id="loginModal" role="dialog" aria-modal="true" aria-labelledby="login-modal-title" aria-hidden="<?= $shouldOpenLogin ? 'false' : 'true' ?>">
        <div class="landing-login-modal__backdrop" data-login-modal-close></div>
        <section class="landing-login-modal__panel" tabindex="-1">
            <button class="landing-login-modal__close" type="button" aria-label="Close login dialog" data-login-modal-close>&times;</button>
            <div class="landing-login-modal__shell">
                <aside class="landing-login-modal__aside" aria-label="RetailMind access">
                    <span class="landing-brand__mark" aria-hidden="true">R</span>
                    <div>
                        <p class="landing-eyebrow">Staff workspace</p>
                        <h2 id="login-modal-title">Welcome back</h2>
                        <p>Access inventory, sales, reports, and system tools from one workspace.</p>
                    </div>
                </aside>
                <div class="landing-login-modal__body">
                    <div class="landing-login-modal__brand">
                        <div>
                            <h3>Log in</h3>
                            <p>Enter your credentials to continue.</p>
                        </div>
                    </div>

                    <?php if ($loginError !== ''): ?>
                        <div class="error-msg"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($loginSuccess !== ''): ?><div id="rm-flash-messages" class="alert tag-success" data-success="<?= htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8') ?>" <?php if ($loginSuccessRedirect !== ''): ?> data-redirect="<?= htmlspecialchars($loginSuccessRedirect, ENT_QUOTES, 'UTF-8') ?>" <?php endif; ?>><?= htmlspecialchars($loginSuccess, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

                    <form method="POST" action="<?= $loginActionUrl ?>" class="landing-login-form">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="landing-login-username">Username</label>
                            <input type="text" id="landing-login-username" name="username" value="<?= htmlspecialchars($loginUsername, ENT_QUOTES, 'UTF-8') ?>" placeholder="Enter your username" required autocomplete="username">
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
                            <a href="<?= $forgotPasswordUrl ?>">Forgot password?</a>
                        </div>
                    </form>
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
            var loginForm = modal.querySelector('.landing-login-form');
            var openers = document.querySelectorAll('[data-login-modal-open]');
            var closers = modal.querySelectorAll('[data-login-modal-close]');
            var passwordStateKey = 'retailmind.login.password';
            var hasLoginError = Boolean(modal.querySelector('.error-msg'));
            var loginFormState = {
                username: username ? username.value : '',
                password: password ? password.value : ''
            };

            if (hasLoginError && password && window.sessionStorage) {
                try {
                    password.value = window.sessionStorage.getItem(passwordStateKey) || '';
                    window.sessionStorage.removeItem(passwordStateKey);
                } catch (error) {}
                loginFormState.password = password.value;
            } else if (window.sessionStorage) {
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

            function openModal(event) {
                if (event) {
                    event.preventDefault();
                }
                restoreFormState();
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('landing-modal-open');
                window.setTimeout(function() {
                    (username || panel).focus();
                }, 50);
            }

            function closeModal() {
                saveFormState();
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('landing-modal-open');
            }

            openers.forEach(function(opener) {
                opener.addEventListener('click', openModal);
            });
            closers.forEach(function(closer) {
                closer.addEventListener('click', closeModal);
            });
            if (loginForm && window.sessionStorage) {
                loginForm.addEventListener('submit', function() {
                    try {
                        window.sessionStorage.setItem(passwordStateKey, password ? password.value : '');
                    } catch (error) {}
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
                if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                }
            });

            if (modal.classList.contains('is-open')) {
                document.body.classList.add('landing-modal-open');
                window.setTimeout(function() {
                    (username || panel).focus();
                }, 50);
            }
        })();
    </script>
</body>

</html>
