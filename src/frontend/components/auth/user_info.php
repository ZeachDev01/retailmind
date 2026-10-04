<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';

use App\Services\ProfileImageStorage;

if (!is_logged_in()) {
    header('Location: ' . app_url('?login=1'));
    exit;
}
if ((bool)($_SESSION['is_recovery_account'] ?? false)) {
    http_response_code(403);
    exit('Recovery Account details are available only through the offline recovery procedure.');
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

function can_manage_own_profile_image(): bool
{
    return is_logged_in()
        && !(bool)($_SESSION['is_recovery_account'] ?? false)
        && !(bool)($_SESSION['must_change_password'] ?? false)
        && in_array(current_role(), ['cashier', 'inventory_manager', 'admin', 'super_admin'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'update_profile');

    if (in_array($action, ['replace_profile_image', 'remove_profile_image'], true)) {
        if (!can_manage_own_profile_image()
            || isset($_POST['user_id']) || isset($_GET['user_id'])
        ) {
            http_response_code(403);
            die('Access denied: you can manage only your own profile picture.');
        }

        $imageService = profile_image_storage();
        $currentFilename = (string)($_SESSION['profile_image'] ?? '');

        try {
            $pdo->beginTransaction();
            $newFilename = null;
            if ($action === 'replace_profile_image') {
                $newFilename = $imageService->replace(
                    $_FILES['profile_image'] ?? [],
                    $currentFilename,
                    static function (string $filename) use ($pdo): void {
                        $stmt = $pdo->prepare('UPDATE users SET profile_image = ? WHERE user_id = ?');
                        $stmt->execute([$filename, (int)$_SESSION['user_id']]);
                    },
                    static fn() => $pdo->commit()
                );
                $message = $currentFilename !== '' ? 'Profile picture replaced.' : 'Profile picture uploaded.';
            } else {
                $imageService->remove(
                    $currentFilename,
                    static function () use ($pdo): void {
                        $stmt = $pdo->prepare('UPDATE users SET profile_image = NULL WHERE user_id = ?');
                        $stmt->execute([(int)$_SESSION['user_id']]);
                    },
                    static fn() => $pdo->commit()
                );
                $message = 'Profile picture removed. Your initials are now shown.';
            }

            $_SESSION['profile_image'] = $newFilename;

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
            $message = \App\Support\OperatorAlert::message($e, 'The profile picture could not be updated. Please try again. Tell your Administrator if this keeps happening.');
            $messageClass = 'tag-warning';
        } catch (RuntimeException $e) {
            $message = \App\Support\OperatorAlert::message($e, 'The profile picture could not be updated. Check the file size and type, then try again. Tell your Administrator if this keeps happening.');
            $messageClass = 'tag-warning';
        } catch (Throwable $e) {
            error_log('Profile picture update failed: ' . $e->getMessage());
            $message = \App\Support\OperatorAlert::message($e, 'The profile picture could not be updated. Please try again. Tell your Administrator if this keeps happening.');
            $messageClass = 'tag-warning';
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Info</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/user-info.css')) ?>">
</head>

<body class="user-info-page">
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

            <section class="account-hero user-info-hero">
                <?= profile_avatar_html((int)$account['user_id'], $displayName, $account['profile_image'] ?? null, 'account-avatar') ?>
                <div class="account-hero-copy">
                    <span class="user-info-eyebrow">Signed-in account</span>
                    <h2><?= htmlspecialchars($account['full_name']) ?></h2>
                    <p>@<?= htmlspecialchars($account['username']) ?></p>
                    <div class="account-badges">
                        <span class="badge-role"><?= htmlspecialchars($roleLabel) ?></span>
                        <span class="<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars((string)$account['status']) ?></span>
                        <span class="<?= htmlspecialchars($passwordStatusClass) ?>"><?= htmlspecialchars($passwordStatus) ?></span>
                    </div>
                </div>
            </section>

            <div class="user-info-layout">
                <section class="dashboard-section profile-card">
                    <div class="section-header">
                        <div>
                            <h3>Profile</h3>
                            <p class="section-description">Keep your picture, name, and email current for account records.</p>
                        </div>
                    </div>
                    <?php if (can_manage_own_profile_image()): ?>
                        <div class="profile-picture-settings">
                            <div class="profile-picture-frame">
                                <span class="profile-avatar-fallback"><?= htmlspecialchars(profile_initials($displayName)) ?></span>
                                <img id="profile-picture-preview" class="profile-picture-preview" src="<?= htmlspecialchars($profileImageUrl) ?>" alt="Current profile picture" <?= profile_image_storage()->exists($account['profile_image'] ?? null) ? '' : 'hidden' ?> onerror="this.hidden=true">
                            </div>
                            <div class="profile-picture-controls">
                                <div class="profile-picture-copy">
                                    <h4>Profile picture</h4>
                                    <p>Optional. Without a picture, your name initials appear.</p>
                                </div>
                                <form method="POST" enctype="multipart/form-data" class="profile-picture-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="replace_profile_image">
                                    <div class="profile-picture-picker">
                                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= ProfileImageStorage::MAX_FILE_SIZE ?>">
                                        <input class="profile-picture-input" type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" required>
                                        <label class="btn btn-quiet btn-small profile-picture-choose" for="profile_image"><i class="bi bi-image"></i><?= !empty($account['profile_image']) ? 'Choose replacement' : 'Choose image' ?></label>
                                        <span class="profile-picture-filename" id="profile-picture-filename">No file selected</span>
                                    </div>
                                    <small class="field-help">JPEG, PNG, GIF, or WebP. Maximum 2 MB.</small>
                                    <div class="profile-picture-actions">
                                        <button class="btn btn-small" type="submit"><i class="bi bi-cloud-arrow-up"></i>Save picture</button>
                                    </div>
                                </form>
                                <?php if (!empty($account['profile_image'])): ?>
                                    <form method="POST" class="profile-picture-remove-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove_profile_image">
                                        <button class="btn btn-quiet btn-small" type="submit"><i class="bi bi-trash3"></i>Remove picture</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="profile-details-heading">
                        <h4>Personal information</h4>
                        <p>These details appear across account records and administrative activity.</p>
                    </div>
                    <form method="POST" class="form-grid profile-details-form" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-group full">
                            <label for="full_name">Full Name</label>
                            <input id="full_name" name="full_name" value="<?= htmlspecialchars($account['full_name']) ?>" required>
                        </div>
                        <div class="form-group full">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars((string)($account['email'] ?? '')) ?>" placeholder="name@example.com">
                        </div>
                        <div class="form-group">
                            <label for="profile_username">Username</label>
                            <input id="profile_username" value="<?= htmlspecialchars($account['username']) ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="profile_role">Role</label>
                            <input id="profile_role" value="<?= htmlspecialchars($roleLabel) ?>" disabled>
                        </div>
                        <div class="full profile-save-row">
                            <button class="btn" type="submit"><i class="bi bi-check2-circle"></i>Save Profile</button>
                        </div>
                    </form>
                </section>

                <section class="dashboard-section account-details-card">
                    <div class="section-header account-details-header">
                        <div>
                            <span class="account-details-icon" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
                            <h3>Account Details</h3>
                            <p class="section-description">Your access and security metadata.</p>
                        </div>
                    </div>
                    <div class="detail-grid account-detail-grid">
                        <div class="detail-item">
                            <span>User ID</span>
                            <strong>#<?= (int)$account['user_id'] ?></strong>
                        </div>
                        <div class="detail-item">
                            <span>Status</span>
                            <strong class="account-status-value <?= $account['status'] === 'active' ? 'is-active' : 'is-inactive' ?>"><i class="bi <?= $account['status'] === 'active' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>"></i><?= htmlspecialchars(ucfirst((string)$account['status'])) ?></strong>
                        </div>
                        <div class="detail-item full">
                            <span>Last Login</span>
                            <strong><?= htmlspecialchars(account_format_datetime($account['last_login_at'] ?? null)) ?></strong>
                        </div>
                        <div class="detail-item full">
                            <span>Password Changed</span>
                            <strong><?= htmlspecialchars(account_format_datetime($account['password_changed_at'] ?? null)) ?></strong>
                        </div>
                        <div class="detail-item full">
                            <span>Account Created</span>
                            <strong><?= htmlspecialchars(account_format_datetime($account['created_at'] ?? null)) ?></strong>
                        </div>
                    </div>
                    <a class="account-security-link" href="<?= htmlspecialchars(app_url('components/auth/change_password.php')) ?>">
                        <span><i class="bi bi-key"></i><strong>Security settings</strong></span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </section>
            </div>
        </main>
    </div>
    <script>
        (function() {
            var input = document.getElementById('profile_image');
            var preview = document.getElementById('profile-picture-preview');
            var filename = document.getElementById('profile-picture-filename');
            if (!input || !preview) return;

            input.addEventListener('change', function() {
                var file = input.files && input.files[0];
                if (!file) return;
                var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (allowedTypes.indexOf(file.type) === -1 || file.size > <?= ProfileImageStorage::MAX_FILE_SIZE ?>) {
                    input.value = '';
                    if (filename) filename.textContent = 'No file selected';
                    if (window.RetailMindUI) {
                        RetailMindUI.toast('Choose a JPEG, PNG, GIF, or WebP image no larger than 2 MB.', 'warning');
                    }
                    return;
                }

                var reader = new FileReader();
                reader.addEventListener('load', function(event) {
                    preview.src = event.target.result;
                    preview.hidden = false;
                });
                reader.readAsDataURL(file);
                if (filename) filename.textContent = file.name;
            });
        })();
    </script>
</body>

</html>
