<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';

use App\Services\ProfileImageStorage;

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

    return format_display_datetime($value);
}

function account_role_label(string $role): string
{
    return ucwords(str_replace('_', ' ', $role));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'update_profile');

    if (in_array($action, ['replace_profile_image', 'remove_profile_image'], true)) {
        if (!is_system_admin()) {
            http_response_code(403);
            die('Access denied: only administrators can manage profile pictures.');
        }

        $imageService = profile_image_storage();
        $currentFilename = (string)($_SESSION['profile_image'] ?? '');

        try {
            if ($action === 'replace_profile_image') {
                $newFilename = $imageService->replace(
                    $_FILES['profile_image'] ?? [],
                    $currentFilename,
                    static function (string $filename) use ($pdo): void {
                        $stmt = $pdo->prepare('UPDATE users SET profile_image = ? WHERE user_id = ?');
                        $stmt->execute([$filename, (int)$_SESSION['user_id']]);
                    }
                );
                $_SESSION['profile_image'] = $newFilename;
                $message = $currentFilename !== '' ? 'Profile picture replaced.' : 'Profile picture uploaded.';
            } else {
                $imageService->remove(
                    $currentFilename,
                    static function () use ($pdo): void {
                        $stmt = $pdo->prepare('UPDATE users SET profile_image = NULL WHERE user_id = ?');
                        $stmt->execute([(int)$_SESSION['user_id']]);
                    }
                );
                $_SESSION['profile_image'] = null;
                $message = 'Profile picture removed. The default picture is now shown.';
            }

            log_activity(
                $pdo,
                (int)$_SESSION['user_id'],
                $action === 'replace_profile_image' ? 'User profile picture update' : 'User profile picture removal',
                'Users',
                (int)$_SESSION['user_id'],
                null,
                ['has_custom_profile_image' => $action === 'replace_profile_image']
            );
            $messageClass = 'tag-success';
        } catch (PDOException $e) {
            error_log('Profile picture database update failed: ' . $e->getMessage());
            $message = 'Unable to update the profile picture right now. Please try again.';
            $messageClass = 'tag-warning';
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $messageClass = 'tag-warning';
        } catch (Throwable $e) {
            error_log('Profile picture update failed: ' . $e->getMessage());
            $message = 'Unable to update the profile picture right now. Please try again.';
            $messageClass = 'tag-warning';
        }
    } else {
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
}

$stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name, u.username, u.email, u.profile_image, u.status, u.last_login_at,
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
$roleLabel = account_role_label((string)$account['role_name']);
$statusClass = $account['status'] === 'active' ? 'tag-success' : 'tag-warning';
$passwordStatus = (int)$account['must_change_password'] === 1 ? 'Change required' : 'Current';
$passwordStatusClass = (int)$account['must_change_password'] === 1 ? 'tag-warning' : 'tag-success';
$profileImageUrl = profile_image_url((int)$account['user_id']);
$defaultProfileImageUrl = default_profile_image_url();
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
                    <a class="btn btn-quiet btn-icon" href="<?= htmlspecialchars(app_url('components/auth/change_password.php')) ?>"><i class="bi bi-shield-lock"></i>Change Password</a>
                </div>
            </header>

            <?php if ($message): ?>
                <div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <section class="account-hero">
                <?= profile_avatar_html((int)$account['user_id'], $displayName, $account['profile_image'] ?? null, 'account-avatar') ?>
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
                            <p class="section-description">Keep your picture, name, and email current for account records.</p>
                        </div>
                    </div>
                    <?php if (is_system_admin()): ?>
                        <div class="profile-picture-settings">
                            <img id="profile-picture-preview" class="profile-picture-preview" src="<?= htmlspecialchars($profileImageUrl) ?>" alt="Current profile picture" data-default-src="<?= htmlspecialchars($defaultProfileImageUrl) ?>" onerror="this.onerror=null;this.src=this.dataset.defaultSrc">
                            <div class="profile-picture-controls">
                                <form method="POST" enctype="multipart/form-data" class="profile-picture-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="replace_profile_image">
                                    <div class="form-group">
                                        <label for="profile_image"><?= !empty($account['profile_image']) ? 'Replace Profile Picture' : 'Upload Profile Picture' ?></label>
                                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= ProfileImageStorage::MAX_FILE_SIZE ?>">
                                        <input type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" required>
                                        <small class="field-help">JPEG, PNG, GIF, or WebP. Maximum 2 MB.</small>
                                    </div>
                                    <button class="btn btn-small" type="submit"><?= !empty($account['profile_image']) ? 'Replace Picture' : 'Upload Picture' ?></button>
                                </form>
                                <?php if (!empty($account['profile_image'])): ?>
                                    <form method="POST" class="profile-picture-remove-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove_profile_image">
                                        <button class="btn btn-quiet btn-small" type="submit">Remove Picture</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <form method="POST" class="form-grid" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">
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
                        <div class="full u-flex-end">
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
    <script>
        (function() {
            var input = document.getElementById('profile_image');
            var preview = document.getElementById('profile-picture-preview');
            if (!input || !preview) return;

            input.addEventListener('change', function() {
                var file = input.files && input.files[0];
                if (!file) return;
                var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (allowedTypes.indexOf(file.type) === -1 || file.size > <?= ProfileImageStorage::MAX_FILE_SIZE ?>) {
                    input.value = '';
                    if (window.RetailMindUI) {
                        RetailMindUI.toast('Choose a JPEG, PNG, GIF, or WebP image no larger than 2 MB.', 'warning');
                    }
                    return;
                }

                var reader = new FileReader();
                reader.addEventListener('load', function(event) {
                    preview.src = event.target.result;
                });
                reader.readAsDataURL(file);
            });
        })();
    </script>
</body>

</html>