<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

if (is_logged_in()) {
    redirect_by_role();
}

$message = '';
$messageClass = '';
$values = [
    'full_name' => '',
    'username' => '',
    'email' => '',
];

function register_default_role_id(PDO $pdo): ?int {
    $roleName = (string)env('REGISTER_DEFAULT_ROLE', 'cashier');
    $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ? LIMIT 1');
    $stmt->execute([$roleName]);
    $roleId = $stmt->fetchColumn();

    return $roleId === false ? null : (int)$roleId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $values = [
        'full_name' => trim((string)($_POST['full_name'] ?? '')),
        'username' => trim((string)($_POST['username'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
    ];
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
    $roleId = register_default_role_id($pdo);

    if ($values['full_name'] === '' || $values['username'] === '' || $values['email'] === '') {
        $message = 'Full name, username, and email are required.';
        $messageClass = 'tag-warning';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $values['username'])) {
        $message = 'Username must be 3-50 characters and use only letters, numbers, dots, dashes, or underscores.';
        $messageClass = 'tag-warning';
    } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
        $messageClass = 'tag-warning';
    } elseif ($password !== $passwordConfirm) {
        $message = 'Passwords do not match.';
        $messageClass = 'tag-warning';
    } elseif ($passwordError = password_policy_error($password)) {
        $message = $passwordError;
        $messageClass = 'tag-warning';
    } elseif ($roleId === null) {
        $message = 'Registration is not configured. The default role is missing.';
        $messageClass = 'tag-warning';
    } else {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, username, email, password_hash, role_id, status, must_change_password)
                 VALUES (?, ?, ?, ?, ?, 'active', 0)"
            );
            $stmt->execute([
                $values['full_name'],
                $values['username'],
                $values['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $roleId,
            ]);
            $newUserId = (int)$pdo->lastInsertId();
            log_activity(
                $pdo,
                $newUserId,
                'Self registration',
                'Authentication',
                $newUserId,
                null,
                [
                    'username' => $values['username'],
                    'email' => $values['email'],
                    'role_id' => $roleId,
                    'status' => 'active',
                ]
            );
            $message = 'Account created. You can now log in.';
            $messageClass = 'tag-success';
            $values = ['full_name' => '', 'username' => '', 'email' => ''];
        } catch (PDOException $exception) {
            $message = 'Unable to create account. Username or email may already exist.';
            $messageClass = 'tag-warning';
        }
    }
}

$loginUrl = htmlspecialchars(app_url('?login=1'), ENT_QUOTES, 'UTF-8');
$styleUrl = htmlspecialchars(app_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account</title>
<link rel="stylesheet" href="<?= $styleUrl ?>">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card auth-card-wide">
        <div class="login-card__brand">
            <span class="brand-icon" aria-hidden="true">R</span>
            <div>
                <h1>Create account</h1>
                <p class="subtitle">Register for RetailMind staff access.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert <?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($values['full_name'], ENT_QUOTES, 'UTF-8') ?>" required autocomplete="name">
            </div>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($values['username'], ENT_QUOTES, 'UTF-8') ?>" required autocomplete="username">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8') ?>" required autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="new-password">
                <small class="field-help">Use at least <?= max(8, (int)env('PASSWORD_MIN_LENGTH', 10)) ?> characters with uppercase, lowercase, and a number.</small>
            </div>
            <div class="form-group">
                <label for="password_confirm">Confirm password</label>
                <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-block">Create account</button>
        </form>
        <p class="auth-card-link"><a href="<?= $loginUrl ?>">Back to login</a></p>
    </div>
</div>
</body>
</html>
