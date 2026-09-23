<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

if (!is_logged_in()) {
    header('Location: ' . app_url('?login=1'));
    exit;
}
if ((bool)($_SESSION['is_recovery_account'] ?? false)) {
    http_response_code(403);
    exit('Recovery Account credentials can be rotated only through the offline recovery procedure.');
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare('SELECT password_hash, session_version FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($currentPassword, (string)$user['password_hash'])) {
        $message = 'The current password is incorrect.';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'The new passwords do not match.';
    } elseif ($newPassword === $currentPassword) {
        $message = 'Choose a new password that is different from the current password.';
    } elseif ($policyError = password_policy_error($newPassword)) {
        $message = $policyError;
    } else {
        $newVersion = (int)$user['session_version'] + 1;
        $pdo->prepare(
            'UPDATE users SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0, session_version = ?, failed_login_attempts = 0, locked_until = NULL WHERE user_id = ?'
        )->execute([password_hash($newPassword, PASSWORD_DEFAULT), $newVersion, (int)$_SESSION['user_id']]);

        $_SESSION['session_version'] = $newVersion;
        $_SESSION['must_change_password'] = false;
        $_SESSION['_flash_success'] = 'Password changed successfully.';
        log_activity(
            $pdo,
            (int)$_SESSION['user_id'],
            'Mandatory password change completed',
            'Authentication',
            (int)$_SESSION['user_id'],
            null,
            ['status' => 'completed']
        );
        redirect_by_role();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>

<body>
    <main class="u-auth-main">
        <section class="dashboard-section u-auth-card">
            <div class="section-header">
                <div>
                    <p class="u-text-muted u-m-0">Account security</p>
                    <h1 class="u-auth-title">Create a new password</h1>
                    <p class="section-description">The temporary password must be replaced before you can access RetailMind.</p>
                </div>
            </div>
            <?php if ($message): ?><div class="alert tag-warning"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <form method="POST" class="form-grid" autocomplete="off">
                <?= csrf_field() ?>
                <div class="form-group u-grid-full"><label for="current_password">Current password</label>
                    <div class="password-input"><input type="password" id="current_password" name="current_password" required autocomplete="current-password"><button type="button" class="password-toggle" data-password-toggle="current_password" aria-controls="current_password" aria-pressed="false" aria-label="Show current password">Show</button></div>
                </div>
                <div class="form-group u-grid-full"><label for="new_password">New password</label>
                    <div class="password-input"><input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password"><button type="button" class="password-toggle" data-password-toggle="new_password" aria-controls="new_password" aria-pressed="false" aria-label="Show new password">Show</button></div><small>Use at least 8 characters with uppercase, lowercase, and a number.</small>
                </div>
                <div class="form-group u-grid-full"><label for="confirm_password">Confirm new password</label>
                    <div class="password-input"><input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password"><button type="button" class="password-toggle" data-password-toggle="confirm_password" aria-controls="confirm_password" aria-pressed="false" aria-label="Show confirmation password">Show</button></div>
                </div>
                <div class="u-auth-actions"><a class="btn btn-quiet" href="<?= htmlspecialchars(app_url('components/auth/logout.php')) ?>">Log out</a><button class="btn" type="submit">Save new password</button></div>
            </form>
        </section>
    </main>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="<?= htmlspecialchars(app_url('assets/js/ui.js')) ?>"></script>
    <script>
        document.querySelectorAll('[data-password-toggle]').forEach(function(toggle) {
            var input = document.getElementById(toggle.dataset.passwordToggle);
            if (!input) return;
            toggle.addEventListener('click', function() {
                var isVisible = input.type === 'text';
                input.type = isVisible ? 'password' : 'text';
                toggle.textContent = isVisible ? 'Show' : 'Hide';
                toggle.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
                toggle.setAttribute('aria-label', (isVisible ? 'Show' : 'Hide') + ' password');
            });
        });
    </script>
</body>

</html>