<?php
// Public landing page. Authenticated users keep the previous dashboard redirect.
require_once __DIR__ . '/assets/bootstrap/app.php';

App\Core\Session::start();
require_once __DIR__ . '/includes/csrf.php';

function landing_app_base_url(): string {
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

function landing_app_url(string $path = ''): string {
    $baseUrl = landing_app_base_url();
    $normalizedPath = ltrim($path, '/');
    if ($normalizedPath === '') {
        return $baseUrl === '' ? '/' : $baseUrl . '/';
    }
    return ($baseUrl === '' ? '' : $baseUrl) . '/' . $normalizedPath;
}

function landing_redirect_by_role(): void {
    switch ($_SESSION['role'] ?? null) {
        case 'super_admin':
        case 'admin':
            header('Location: ' . landing_app_url('modules/system_administrator/dashboard.php'));
            break;
        case 'inventory_manager':
            header('Location: ' . landing_app_url('modules/inventory_management/dashboard.php'));
            break;
        case 'cashier':
            header('Location: ' . landing_app_url('cashier/pos.php'));
            break;
        default:
            header('Location: ' . landing_app_url('index.php?login=1'));
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/includes/auth.php';

    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password && login_user($pdo, $username, $password)) {
        landing_redirect_by_role();
    }

    $_SESSION['_login_error'] = last_login_error();
    header('Location: ' . landing_app_url('index.php?login=1'));
    exit;
}

if (isset($_SESSION['user_id'])) {
    landing_redirect_by_role();
}

$loginUrl = htmlspecialchars(landing_app_url('index.php?login=1'), ENT_QUOTES, 'UTF-8');
$loginActionUrl = htmlspecialchars(landing_app_url('index.php'), ENT_QUOTES, 'UTF-8');
$forgotPasswordUrl = htmlspecialchars(landing_app_url('forgot_password.php'), ENT_QUOTES, 'UTF-8');
$styleUrl = htmlspecialchars(landing_app_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8');
$loginError = '';
$shouldOpenLogin = ($_GET['login'] ?? '') === '1';
if ($shouldOpenLogin && isset($_SESSION['_login_error'])) {
    $loginError = (string)$_SESSION['_login_error'];
    unset($_SESSION['_login_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RetailMind Inventory</title>
<link rel="stylesheet" href="<?= $styleUrl ?>">
</head>
<body class="landing-page">
<header class="landing-hero" role="img" aria-label="Organized retail inventory station with barcode scanner, tablet dashboard, and stock shelves">
    <nav class="landing-nav" aria-label="Primary">
        <a class="landing-brand" href="<?= htmlspecialchars(landing_app_url(), ENT_QUOTES, 'UTF-8') ?>">
            <span class="landing-brand__mark" aria-hidden="true">R</span>
            <span>
                <strong>RetailMind</strong>
                <small>Inventory System</small>
            </span>
        </a>
        <div class="landing-nav__links">
            <a href="#capabilities">Capabilities</a>
            <a href="#workflow">Workflow</a>
            <a class="landing-nav__login" href="<?= $loginUrl ?>" data-login-modal-open>Log in</a>
        </div>
    </nav>

    <section class="landing-hero__content" aria-labelledby="landing-title">
        <p class="landing-eyebrow">Retail inventory, sales, and forecasting</p>
        <h1 id="landing-title">RetailMind Inventory</h1>
        <p class="landing-hero__copy">
            Run Shalom Store operations from one focused system: product stock, barcode sales,
            replenishment, purchase orders, cashier shifts, reports, and Random Forest demand forecasts.
        </p>
        <div class="landing-actions">
            <a class="btn landing-btn landing-btn--primary" href="<?= $loginUrl ?>" data-login-modal-open>Log in to workspace</a>
            <a class="btn landing-btn landing-btn--secondary" href="#workflow">See workflow</a>
        </div>
        <dl class="landing-metrics" aria-label="System highlights">
            <div>
                <dt>3</dt>
                <dd>operational roles</dd>
            </div>
            <div>
                <dt>24h</dt>
                <dd>held sale expiry</dd>
            </div>
            <div>
                <dt>RF</dt>
                <dd>demand forecasting</dd>
            </div>
        </dl>
    </section>
</header>

<main>
    <section class="landing-proof" aria-label="Operational focus">
        <div class="landing-proof__inner">
            <span>Stock monitoring</span>
            <span>Barcode checkout</span>
            <span>Supplier purchasing</span>
            <span>Cashier reconciliation</span>
            <span>Forecast analytics</span>
        </div>
    </section>

    <section id="capabilities" class="landing-section">
        <div class="landing-section__intro">
            <p class="landing-eyebrow">Capabilities</p>
            <h2>Built for the daily store rhythm</h2>
            <p>
                RetailMind keeps the core work close together, so teams can move from the shelf,
                to the cashier counter, to the reorder decision without switching systems.
            </p>
        </div>
        <div class="landing-feature-grid">
            <article class="landing-feature-card">
                <h3>Inventory Control</h3>
                <p>Track product status, low stock, expiry risk, dead stock, excess stock, and ABC cycle-count priorities.</p>
            </article>
            <article class="landing-feature-card">
                <h3>Barcode Sales</h3>
                <p>Scan manufacturer labels or generated Code 128 labels, hold sales, apply controlled discounts, and print receipts.</p>
            </article>
            <article class="landing-feature-card">
                <h3>Forecast Planning</h3>
                <p>Review Random Forest demand forecasts, confidence ranges, baseline comparisons, and transparent reorder inputs.</p>
            </article>
            <article class="landing-feature-card">
                <h3>Purchase Flow</h3>
                <p>Manage suppliers, purchase orders, partial receiving, package conversion, and approval separation.</p>
            </article>
            <article class="landing-feature-card">
                <h3>Shift Closeout</h3>
                <p>Open cashier shifts, record pay-ins and pay-outs, reconcile end-of-shift cash, and review variances.</p>
            </article>
            <article class="landing-feature-card">
                <h3>Reports</h3>
                <p>Use operational dashboards, inventory insights, forecast exceptions, receipt settings, backups, and notifications.</p>
            </article>
        </div>
    </section>

    <section id="workflow" class="landing-workflow">
        <div class="landing-workflow__content">
            <p class="landing-eyebrow">Workflow</p>
            <h2>From checkout data to better replenishment</h2>
            <p>
                Sales activity feeds stock movement and forecast history. Managers can review low-confidence
                items, accept or adjust recommendations, and create purchase orders with the right approvals.
            </p>
        </div>
        <div class="landing-steps" aria-label="Inventory workflow steps">
            <article>
                <span>01</span>
                <h3>Sell and scan</h3>
                <p>Cashiers process barcode sales and shift activity.</p>
            </article>
            <article>
                <span>02</span>
                <h3>Monitor stock</h3>
                <p>Inventory teams catch low, stale, excess, and expiry-risk items.</p>
            </article>
            <article>
                <span>03</span>
                <h3>Forecast demand</h3>
                <p>The model compares recent demand patterns with baseline performance.</p>
            </article>
            <article>
                <span>04</span>
                <h3>Replenish</h3>
                <p>Managers turn reviewed recommendations into controlled purchase orders.</p>
            </article>
        </div>
    </section>

    <section class="landing-access" aria-labelledby="access-title">
        <div>
            <p class="landing-eyebrow">Staff access</p>
            <h2 id="access-title">Continue to your RetailMind workspace</h2>
            <p>Administrators, inventory managers, and cashiers are routed to their role-specific tools after login.</p>
        </div>
        <div class="landing-access__actions">
            <a class="btn landing-btn landing-btn--primary" href="<?= $loginUrl ?>" data-login-modal-open>Log in</a>
            <a class="landing-link" href="<?= $forgotPasswordUrl ?>">Reset password</a>
        </div>
    </section>
</main>

<div class="landing-login-modal<?= $shouldOpenLogin ? ' is-open' : '' ?>" id="loginModal" role="dialog" aria-modal="true" aria-labelledby="login-modal-title" aria-hidden="<?= $shouldOpenLogin ? 'false' : 'true' ?>">
    <div class="landing-login-modal__backdrop" data-login-modal-close></div>
    <section class="landing-login-modal__panel" tabindex="-1">
        <button class="landing-login-modal__close" type="button" aria-label="Close login dialog" data-login-modal-close>&times;</button>
        <div class="landing-login-modal__brand">
            <span class="landing-brand__mark" aria-hidden="true">R</span>
            <div>
                <h2 id="login-modal-title">Log in to RetailMind</h2>
                <p>Use your staff account to continue to your role-specific workspace.</p>
            </div>
        </div>

        <?php if ($loginError !== ''): ?>
            <div class="error-msg"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= $loginActionUrl ?>" class="landing-login-form">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="landing-login-username">Username</label>
                <input type="text" id="landing-login-username" name="username" placeholder="Enter your username" required autocomplete="username">
            </div>
            <div class="form-group">
                <label for="landing-login-password">Password</label>
                <div class="password-input">
                    <input type="password" id="landing-login-password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                    <button type="button" class="password-toggle" id="landing-login-password-toggle" aria-controls="landing-login-password" aria-pressed="false" aria-label="Show password">Show</button>
                </div>
            </div>
            <button type="submit" class="btn btn-block landing-btn landing-btn--primary">Log in</button>
        </form>
        <p class="landing-login-modal__help"><a href="<?= $forgotPasswordUrl ?>">Forgot password?</a></p>
    </section>
</div>

<script>
(function () {
    var modal = document.getElementById('loginModal');
    if (!modal) {
        return;
    }

    var panel = modal.querySelector('.landing-login-modal__panel');
    var username = document.getElementById('landing-login-username');
    var password = document.getElementById('landing-login-password');
    var passwordToggle = document.getElementById('landing-login-password-toggle');
    var openers = document.querySelectorAll('[data-login-modal-open]');
    var closers = modal.querySelectorAll('[data-login-modal-close]');

    function openModal(event) {
        if (event) {
            event.preventDefault();
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('landing-modal-open');
        window.setTimeout(function () {
            (username || panel).focus();
        }, 50);
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('landing-modal-open');
    }

    openers.forEach(function (opener) {
        opener.addEventListener('click', openModal);
    });
    closers.forEach(function (closer) {
        closer.addEventListener('click', closeModal);
    });
    if (password && passwordToggle) {
        passwordToggle.addEventListener('click', function () {
            var isVisible = password.type === 'text';
            password.type = isVisible ? 'password' : 'text';
            passwordToggle.textContent = isVisible ? 'Show' : 'Hide';
            passwordToggle.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
            passwordToggle.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
        });
    }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });

    if (modal.classList.contains('is-open')) {
        document.body.classList.add('landing-modal-open');
        window.setTimeout(function () {
            (username || panel).focus();
        }, 50);
    }
})();
</script>
</body>
</html>
