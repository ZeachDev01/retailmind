<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

$recoveryUrl = app_url() . '?login=1&recovery=1';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $recoveryUrl, true, 302);
    exit;
}

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
        $_SESSION['_recovery_message'] = 'If the account exists and has an email address, a password-reset link has been sent.';
        $_SESSION['_recovery_message_class'] = 'tag-success';
    } catch (Throwable $exception) {
        $_SESSION['_recovery_message'] = \App\Support\OperatorAlert::message($exception, 'The reset link could not be sent. Check your connection and try again. Tell your Administrator if this keeps happening.');
        $_SESSION['_recovery_message_class'] = 'tag-warning';
        $_SESSION['_recovery_identity'] = $identity;
    }
}
header('Location: ' . $recoveryUrl, true, 303);
exit;
