<?php
require_once __DIR__ . '/../../includes/auth.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $identity = trim((string)($_POST['identity'] ?? ''));
    if ($identity !== '') {
        $stmt = $pdo->prepare("SELECT user_id, full_name, email FROM users WHERE (username = ? OR email = ?) AND status = 'active' LIMIT 1");
        $stmt->execute([$identity, $identity]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? OR expires_at < NOW()')->execute([(int)$user['user_id']]);
            $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')
                ->execute([(int)$user['user_id'], $tokenHash]);
            $resetUrl = rtrim((string)env('APP_URL', ''), '/') . '/modules/auth/reset_password.php?token=' . urlencode($token);
            send_email_notification(
                (string)$user['email'],
                'RetailMind password reset',
                "Hello {$user['full_name']},\n\nUse this link within 30 minutes to reset your password:\n{$resetUrl}\n\nIgnore this email if you did not request a reset."
            );
        }
    }
    $message = 'If the account exists and has an email address, a password-reset link has been sent.';
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forgot Password</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"></head>
<body><div class="login-wrapper"><div class="login-card"><h1>Reset password</h1><p class="subtitle">Enter your username or email address.</p>
<?php if ($message): ?><div class="alert tag-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?><div class="form-group"><label>Username or email</label><input name="identity" required></div><button class="btn btn-block">Send reset link</button></form>
<p style="margin-top:1rem;text-align:center"><a href="<?= htmlspecialchars(app_url('modules/auth/login.php')) ?>">Back to login</a></p></div></div></body></html>
