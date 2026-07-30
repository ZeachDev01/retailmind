<?php
// login.php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect_by_role();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password && login_user($pdo, $username, $password)) {
        redirect_by_role();
    } else {
        $error = last_login_error();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - Inventory System</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-card__brand">
            <div class="brand-icon">📦</div>
            <div>
                <h1>Inventory System</h1>
                <p class="subtitle">Sign in to manage stock, sales, and replenishment</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn btn-block">Log In</button>
        </form>
        <p style="margin-top:1rem;text-align:center;"><a href="<?= htmlspecialchars(app_url('forgot_password.php')) ?>">Forgot password?</a></p>
    </div>
</div>
</body>
</html>
