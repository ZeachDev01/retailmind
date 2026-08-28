<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';

if (!is_logged_in()) {
    header('Location: ' . app_url('?login=1'));
    exit;
}

$message = '';
$messageClass = '';

function account_format_datetime(?string $value): string
{
    if (!$value) {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M d, Y g:i A', $timestamp) : $value;
}

function account_role_label(string $role): string
{
    return ucwords(str_replace('_', ' ', $role));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $emailValue = $email === '' ? null : $email;

    if ($fullName === '') {
        $message = 'Full name is required.';
        $messageClass = 'tag-warning';
    } elseif ($emailValue !== null && !filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
        $messageClass = 'tag-warning';
    } else {
        try {
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE user_id = ?');
            $stmt->execute([$fullName, $emailValue, (int)$_SESSION['user_id']]);
            $_SESSION['full_name'] = $fullName;
            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                'User profile update',
                'Users',
                (int)$_SESSION['user_id'],
                null,
                ['full_name' => $fullName, 'email' => $emailValue]
            );
            $message = 'Profile information updated.';
            $messageClass = 'tag-success';
        } catch (PDOException $e) {
            $message = 'Unable to update profile. Email may already be used by another account.';
            $messageClass = 'tag-warning';
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name, u.username, u.email, u.status, u.last_login_at,
            u.password_changed_at, u.must_change_password, u.created_at, r.role_name
     FROM users u JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = ? LIMIT 1"
);
$stmt->execute([(int)$_SESSION['user_id']]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    logout_user();
    header('Location: ' . app_url('?login=1&session=invalid'));
    exit;
}

$displayName = trim((string)$account['full_name']);
$initials = '';
foreach (preg_split('/\s+/', $displayName) ?: [] as $namePart) {
    if ($namePart !== '') {
        $initials .= strtoupper(substr($namePart, 0, 1));
    }
}
$initials = substr($initials ?: 'RM', 0, 2);
$roleLabel = account_role_label((string)$account['role_name']);
$statusClass = $account['status'] === 'active' ? 'tag-success' : 'tag-warning';
$passwordStatus = (int)$account['must_change_password'] === 1 ? 'Change required' : 'Current';
$passwordStatusClass = (int)$account['must_change_password'] === 1 ? 'tag-warning' : 'tag-success';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Info</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>User Info</h1>
                <p class="page-subtitle">Review your account details, contact information, and sign-in status.</p>
            </div>
            <div class="page-heading-actions">
                <a class="btn btn-quiet btn-icon" href="<?= htmlspecialchars(app_url('modules/auth/change_password.php')) ?>"><i class="bi bi-shield-lock"></i>Change Password</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <section class="account-hero">
            <div class="account-avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="account-hero-copy">
                <h2><?= htmlspecialchars($account['full_name']) ?></h2>
                <p>@<?= htmlspecialchars($account['username']) ?></p>
                <div class="account-badges">
                    <span class="badge-role"><?= htmlspecialchars($roleLabel) ?></span>
                    <span class="<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars((string)$account['status']) ?></span>
                    <span class="<?= htmlspecialchars($passwordStatusClass) ?>"><?= htmlspecialchars($passwordStatus) ?></span>
                </div>
            </div>
        </section>

        <div class="dashboard-layout equal">
            <section class="dashboard-section">
                <div class="section-header">
                    <div>
                        <h3>Profile</h3>
                        <p class="section-description">Keep your name and email current for account records.</p>
                    </div>
                </div>
                <form method="POST" class="form-grid" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="form-group full">
                        <label for="full_name">Full Name</label>
                        <input id="full_name" name="full_name" value="<?= htmlspecialchars($account['full_name']) ?>" required>
                    </div>
                    <div class="form-group full">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars((string)($account['email'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input value="<?= htmlspecialchars($account['username']) ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <input value="<?= htmlspecialchars($roleLabel) ?>" disabled>
                    </div>
                    <div class="full" style="display:flex;justify-content:flex-end">
                        <button class="btn" type="submit">Save Profile</button>
                    </div>
                </form>
            </section>

            <section class="dashboard-section">
                <div class="section-header">
                    <div>
                        <h3>Account Details</h3>
                        <p class="section-description">Your access and security metadata.</p>
                    </div>
                </div>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span>User ID</span>
                        <strong>#<?= (int)$account['user_id'] ?></strong>
                    </div>
                    <div class="detail-item">
                        <span>Status</span>
                        <strong><?= htmlspecialchars(ucfirst((string)$account['status'])) ?></strong>
                    </div>
                    <div class="detail-item">
                        <span>Last Login</span>
                        <strong><?= htmlspecialchars(account_format_datetime($account['last_login_at'] ?? null)) ?></strong>
                    </div>
                    <div class="detail-item">
                        <span>Password Changed</span>
                        <strong><?= htmlspecialchars(account_format_datetime($account['password_changed_at'] ?? null)) ?></strong>
                    </div>
                    <div class="detail-item full">
                        <span>Account Created</span>
                        <strong><?= htmlspecialchars(account_format_datetime($account['created_at'] ?? null)) ?></strong>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
