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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset password | RetailMind</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>

<body class="auth-page">
    <main class="login-wrapper">
        <section class="login-card" aria-labelledby="reset-password-title">
            <div class="login-card__brand">
                <span class="brand-icon" aria-hidden="true">RM</span>
                <span class="login-card__brand-copy">
                    <strong>RetailMind</strong>
                    <small>Shalom Store staff access</small>
                </span>
            </div>
            <h1 id="reset-password-title">Reset password</h1>
            <p class="subtitle">Enter your username or email address.</p>
            <?php if ($message): ?>
                <div
                    class="alert <?= htmlspecialchars($messageClass) ?>"
                    role="<?= $messageClass === 'tag-warning' ? 'alert' : 'status' ?>"
                    aria-live="<?= $messageClass === 'tag-warning' ? 'assertive' : 'polite' ?>"
                    aria-atomic="true"
                ><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="identity">Username or email</label>
                    <input id="identity" name="identity" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                </div>
                <button class="btn btn-block" type="submit">Send reset link</button>
            </form>
            <p class="u-back-link"><a href="<?= htmlspecialchars(app_url('?login=1')) ?>">Back to login</a></p>
        </section>
    </main>
<!-- impeccable-live-start -->
<script src="http://localhost:8400/live.js?token=9d673aff-16bf-4b27-a8df-dbe423e3e255"></script>
<!-- impeccable-live-end -->
</body>

</html>