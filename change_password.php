<?php
require_once __DIR__ . '/includes/auth.php';

if (!is_logged_in()) {
    header('Location: ' . app_url('index.php?login=1'));
    exit;
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
<body class="landing-page">
<main style="min-height:100vh;display:grid;place-items:center;padding:2rem">
    <section class="dashboard-section" style="width:min(520px,100%);padding:2rem">
        <div class="section-header">
            <div>
                <p class="landing-eyebrow">Account security</p>
                <h1 style="margin:.25rem 0">Create a new password</h1>
                <p class="section-description">The temporary password must be replaced before you can access RetailMind.</p>
            </div>
        </div>
        <?php if ($message): ?><div class="alert tag-warning"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <form method="POST" class="form-grid" autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-group" style="grid-column:1/-1"><label for="current_password">Current password</label><input type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>
            <div class="form-group" style="grid-column:1/-1"><label for="new_password">New password</label><input type="password" id="new_password" name="new_password" required autocomplete="new-password"><small>Use at least <?= max(8, (int)env('PASSWORD_MIN_LENGTH', 10)) ?> characters with uppercase, lowercase, and a number.</small></div>
            <div class="form-group" style="grid-column:1/-1"><label for="confirm_password">Confirm new password</label><input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password"></div>
            <div style="grid-column:1/-1;display:flex;gap:.75rem;justify-content:flex-end"><a class="btn btn-quiet" href="<?= htmlspecialchars(app_url('logout.php')) ?>">Log out</a><button class="btn" type="submit">Save new password</button></div>
        </form>
    </section>
</main>
</body>
</html>
