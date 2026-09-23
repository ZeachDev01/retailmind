<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = '';
$reset = null;
if ($token !== '') {
    $stmt = $pdo->prepare("SELECT pr.reset_id, pr.user_id, r.role_name
        FROM password_reset_tokens pr
        JOIN users u ON u.user_id = pr.user_id
        JOIN roles r ON r.role_id = u.role_id
        WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at >= NOW()
          AND u.is_recovery_account = 0
        LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');
    if (!$reset) {
        $error = 'This reset link is invalid or expired.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif ($policy = password_policy_error($password)) {
        $error = $policy;
    } else {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0, session_version = session_version + 1, failed_login_attempts = 0, locked_until = NULL WHERE user_id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['user_id']]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE reset_id = ?')->execute([(int)$reset['reset_id']]);
        log_activity(
            $pdo,
            (int)$reset['user_id'],
            'Password reset completed via email link',
            'User Access',
            (int)$reset['user_id'],
            null,
            ['role' => (string)($reset['role_name'] ?? ''), 'sessions_revoked' => true, 'password_change_required' => false]
        );
        $pdo->commit();
        $success = 'Your password has been reset. You may now sign in.';
        $reset = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset Password</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>

<body>
    <div class="login-wrapper">
        <div class="login-card">
            <h1>Choose a new password</h1>
            <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert tag-success"><?= htmlspecialchars($success) ?></div>
                <p><a href="<?= htmlspecialchars(app_url('?login=1')) ?>">Continue to login</a></p>
            <?php elseif ($reset): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <div class="form-group"><label>New password</label><input type="password" name="password" required minlength="8"><small class="field-help">Use at least 8 characters with uppercase, lowercase, and a number.</small></div>
                    <div class="form-group"><label>Confirm password</label><input type="password" name="password_confirm" required minlength="8"></div><button class="btn btn-block">Reset password</button>
                </form>
            <?php else: ?><div class="error-msg">This reset link is invalid or expired.</div><?php endif; ?>
        </div>
    </div>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="<?= htmlspecialchars(app_url('assets/js/ui.js')) ?>"></script>
    <script src="<?= htmlspecialchars(app_url('assets/js/password-feedback.js')) ?>"></script>
</body>

</html>