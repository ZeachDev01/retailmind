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
    profile_image_check_request_size();
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
                    static fn() => $pdo->commit(),
                    is_array($_POST['crop'] ?? null) ? $_POST['crop'] : []
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

$roleLabel = account_role_label((string)$account['role_name']);
$passwordStatus = (int)$account['must_change_password'] === 1 ? 'Change required' : 'No change required';
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My profile · RetailMind</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/user-info.css')) ?>">
</head>

<body class="user-info-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <header class="page-heading">
                <div>
                    <h1>My profile</h1>
                    <p class="page-subtitle">Update your picture, name, and email.</p>
                </div>
            </header>

            <?php if ($message): ?>
                <div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="user-info-layout">
                <section class="dashboard-section profile-card">
                    <div class="section-header">
                        <div>
                            <h2>Personal information</h2>
                        </div>
                    </div>
                    <?php if (can_manage_own_profile_image()): ?>
                        <div class="profile-picture-settings">
                            <div class="profile-picture-controls">
                                <div class="profile-picture-copy">
                                    <h3>Profile picture</h3>
                                    <p>Optional. Your initials appear when no picture is set.</p>
                                </div>
                                <form method="POST" enctype="multipart/form-data" class="profile-picture-form" id="profile-picture-form"
                                      data-max-bytes="<?= ProfileImageStorage::MAX_BYTES ?>" data-max-dimension="<?= ProfileImageStorage::MAX_DIMENSION ?>" data-max-pixels="<?= ProfileImageStorage::MAX_PIXELS ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="replace_profile_image">
                                    <div class="profile-picture-picker">
                                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= ProfileImageStorage::MAX_FILE_SIZE ?>">
                                        <input class="profile-picture-input" type="file" id="profile_image" name="profile_image" accept="<?= ProfileImageStorage::ACCEPT ?>" aria-describedby="profile-picture-help profile-crop-error" required>
                                        <label class="btn btn-quiet btn-small profile-picture-choose" for="profile_image"><i class="bi bi-image"></i><?= !empty($account['profile_image']) ? 'Choose replacement' : 'Choose image' ?></label>
                                        <span class="profile-picture-filename" id="profile-picture-filename">No file selected</span>
                                    </div>
                                    <small class="field-help" id="profile-picture-help"><?= ProfileImageStorage::HELP ?></small>
                                    <p id="profile-crop-error" class="field-help" role="alert"></p>
                                    <fieldset id="profile-crop-editor" hidden>
                                        <legend>Adjust square crop</legend>
                                        <canvas id="profile-crop-preview" width="256" height="256" role="img" aria-label="Selected square picture crop"></canvas>
                                        <label for="profile-crop-zoom">Zoom</label>
                                        <input id="profile-crop-zoom" type="range" min="1" max="10" step="0.1" value="1">
                                        <label for="profile-crop-horizontal">Horizontal position</label>
                                        <input id="profile-crop-horizontal" type="range" min="0" max="100" value="50">
                                        <label for="profile-crop-vertical">Vertical position</label>
                                        <input id="profile-crop-vertical" type="range" min="0" max="100" value="50">
                                        <input id="profile-crop-x" type="hidden" name="crop[x]">
                                        <input id="profile-crop-y" type="hidden" name="crop[y]">
                                        <input id="profile-crop-size" type="hidden" name="crop[size]">
                                    </fieldset>
                                    <div class="profile-picture-actions">
                                        <button id="profile-picture-save" class="btn btn-small" type="submit" disabled><i class="bi bi-cloud-arrow-up"></i>Save picture</button>
                                        <button id="profile-picture-cancel" class="btn btn-quiet btn-small" type="button" hidden>Cancel</button>
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
                    <form method="POST" class="form-grid profile-details-form" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-group full">
                            <label for="full_name">Full name (required)</label>
                            <input id="full_name" name="full_name" autocomplete="name" value="<?= htmlspecialchars($account['full_name']) ?>" required>
                        </div>
                        <div class="form-group full">
                            <label for="email">Email (optional)</label>
                            <input type="email" id="email" name="email" autocomplete="email" value="<?= htmlspecialchars((string)($account['email'] ?? '')) ?>" placeholder="name@example.com">
                        </div>
                        <div class="full profile-save-row">
                            <button class="btn" type="submit">Save details</button>
                        </div>
                    </form>
                </section>

                <section class="dashboard-section account-details-card">
                    <div class="section-header">
                        <h2>Account details</h2>
                    </div>
                    <dl class="account-detail-list">
                        <div><dt>Username</dt><dd><?= htmlspecialchars($account['username']) ?></dd></div>
                        <div><dt>Role</dt><dd><?= htmlspecialchars($roleLabel) ?></dd></div>
                        <div><dt>Status</dt><dd class="account-status-value <?= $account['status'] === 'active' ? 'is-active' : 'is-inactive' ?>"><?= htmlspecialchars(ucfirst((string)$account['status'])) ?></dd></div>
                        <div><dt>User ID</dt><dd>#<?= (int)$account['user_id'] ?></dd></div>
                        <div><dt>Last sign-in</dt><dd><?= htmlspecialchars(account_format_datetime($account['last_login_at'] ?? null)) ?></dd></div>
                        <div><dt>Account created</dt><dd><?= htmlspecialchars(account_format_datetime($account['created_at'] ?? null)) ?></dd></div>
                    </dl>
                    <div class="account-security">
                        <h3>Password</h3>
                        <p><?= htmlspecialchars($passwordStatus) ?></p>
                        <dl class="account-detail-list">
                            <div><dt>Last changed</dt><dd><?= htmlspecialchars(account_format_datetime($account['password_changed_at'] ?? null)) ?></dd></div>
                        </dl>
                        <a class="btn btn-quiet" href="<?= htmlspecialchars(app_url('components/auth/change_password.php')) ?>">Change password</a>
                    </div>
                </section>
            </div>
        </main>
    </div>
    <script src="<?= htmlspecialchars(app_url('assets/js/profile-picture-crop.js')) ?>"></script>
</body>

</html>
