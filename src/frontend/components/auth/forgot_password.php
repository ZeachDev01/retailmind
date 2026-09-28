<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

$message = '';
$messageClass = 'tag-success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $identity = trim((string)($_POST['identity'] ?? ''));
    try {
        if ($identity !== '') {
            $stmt = $pdo->prepare("SELECT user_id, full_name, email FROM users WHERE (username = ? OR email = ?) AND status = 'active' AND is_recovery_account = 0 LIMIT 1");
            $stmt->execute([$identity, $identity]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? OR expires_at < NOW()')->execute([(int)$user['user_id']]);
                $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')
                    ->execute([(int)$user['user_id'], $tokenHash]);
                $resetUrl = rtrim((string)env('APP_URL', ''), '/') . '/components/auth/reset_password.php?token=' . urlencode($token);
                send_email_notification(
                    (string)$user['email'],
                    'RetailMind password reset',
                    "Hello {$user['full_name']},\n\nUse this link within 30 minutes to reset your password:\n{$resetUrl}\n\nIgnore this email if you did not request a reset."
                );
            }
        }
        $message = 'If the account exists and has an email address, a password-reset link has been sent.';
    } catch (Throwable $exception) {
        $message = \App\Support\OperatorAlert::message($exception, 'The reset link could not be sent. Check your connection and try again. Tell your Administrator if this keeps happening.');
        $messageClass = 'tag-warning';
    }
}
$forgotPasswordStylesheetPath = __DIR__ . '/../../assets/css/forgot-password.css';
$forgotPasswordStylesheetVersion = is_file($forgotPasswordStylesheetPath) ? (string)filemtime($forgotPasswordStylesheetPath) : '1';
$forgotPasswordStylesheetUrl = app_url('assets/css/forgot-password.css') . '?v=' . rawurlencode($forgotPasswordStylesheetVersion);
$brandLogoUrl = app_url('assets/img/retailmind-logo-600x200.png');
$brandIconUrl = app_url('assets/img/retailmind-icon-512.png');
$faviconUrl = app_url('assets/img/retailmind-favicon-32.png');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset password | RetailMind</title>
    <meta name="description" content="Request a secure RetailMind password-reset link for your staff account.">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($faviconUrl) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($forgotPasswordStylesheetUrl) ?>">
</head>

<body class="auth-page forgot-password-page">
    <main class="password-recovery">
        <section class="password-recovery__shell" aria-labelledby="reset-password-title">
            <div class="password-recovery__form-panel">
                <header class="password-recovery__header">
                    <a class="password-recovery__brand" href="<?= htmlspecialchars(app_url()) ?>" aria-label="RetailMind home">
                        <img src="<?= htmlspecialchars($brandLogoUrl) ?>" alt="RetailMind">
                    </a>
                    <a class="password-recovery__back" href="<?= htmlspecialchars(app_url('?login=1')) ?>">Back to login</a>
                </header>

                <div class="password-recovery__form-content">
                    <h1 id="reset-password-title">Reset your password</h1>
                    <p class="password-recovery__intro">Enter the username or email address you use for RetailMind. If the account can receive email, we’ll send a secure reset link.</p>
                    <?php if ($message): ?>
                        <div
                            class="password-recovery__alert <?= htmlspecialchars($messageClass) ?>"
                            role="<?= $messageClass === 'tag-warning' ? 'alert' : 'status' ?>"
                            aria-live="<?= $messageClass === 'tag-warning' ? 'assertive' : 'polite' ?>"
                            aria-atomic="true"
                        ><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>

                    <form class="password-recovery__form" method="post">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="identity">Username or email</label>
                            <input id="identity" name="identity" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" aria-describedby="identity-help" required>
                            <small id="identity-help">Use the same identifier you enter when signing in.</small>
                        </div>
                        <button class="btn btn-block" type="submit">Send reset link</button>
                    </form>

                    <p class="password-recovery__privacy">For privacy, RetailMind shows the same confirmation whether or not an account matches.</p>
                </div>
            </div>

            <aside class="password-recovery__guide" aria-labelledby="recovery-guide-title">
                <div class="password-recovery__guide-heading">
                    <span class="password-recovery__icon" aria-hidden="true">
                        <img src="<?= htmlspecialchars($brandIconUrl) ?>" alt="">
                    </span>
                    <div>
                        <h2 id="recovery-guide-title">A secure way back to your workspace</h2>
                        <p>Your access stays unchanged until you choose a new password.</p>
                    </div>
                </div>

                <ol class="password-recovery__steps" aria-label="Password reset process">
                    <li>
                        <span aria-hidden="true">01</span>
                        <div><strong>Request a link</strong><p>Enter your staff username or email address.</p></div>
                    </li>
                    <li>
                        <span aria-hidden="true">02</span>
                        <div><strong>Check your email</strong><p>Open the RetailMind message sent to your account.</p></div>
                    </li>
                    <li>
                        <span aria-hidden="true">03</span>
                        <div><strong>Choose a password</strong><p>Use the secure link within 30 minutes.</p></div>
                    </li>
                </ol>

                <div class="password-recovery__support">
                    <strong>Didn’t receive the email?</strong>
                    <p>Check your spam folder, then ask your Administrator to confirm that your staff account has an email address.</p>
                </div>
            </aside>
        </section>
    </main>
</body>

</html>
