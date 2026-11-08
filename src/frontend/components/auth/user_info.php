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
$profileValues = null;
$fieldErrors = [];

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
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $displayName = trim($firstName . ' ' . $lastName);
        $email = trim((string)($_POST['email'] ?? ''));
        $emailValue = $email === '' ? null : $email;
        $profileValues = ['first_name' => $firstName, 'last_name' => $lastName, 'email' => $email];

        if ($firstName === '') $fieldErrors['first_name'] = 'First name is required.';
        elseif (mb_strlen($firstName, 'UTF-8') > 50) $fieldErrors['first_name'] = 'Use 50 characters or fewer.';
        if (mb_strlen($lastName, 'UTF-8') > 50) $fieldErrors['last_name'] = 'Use 50 characters or fewer.';
        if (mb_strlen($displayName, 'UTF-8') > 100) $fieldErrors['first_name'] = 'Keep the display name under 101 characters.';
        if (mb_strlen($email, 'UTF-8') > 100) {
            $fieldErrors['email'] = 'Use 100 characters or fewer for your email.';
        } elseif ($emailValue !== null && !filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['email'] = 'Enter a valid email address.';
        }
        if ($fieldErrors) {
            $message = 'Check the highlighted fields and save again.';
            $messageClass = 'tag-warning';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, display_name = ?, email = ? WHERE user_id = ?');
                $stmt->execute([$firstName, $lastName, $displayName, $emailValue, (int)$_SESSION['user_id']]);
                $_SESSION['display_name'] = $displayName;
                log_activity(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    'User profile update',
                    'Users',
                    (int)$_SESSION['user_id'],
                    null,
                    ['first_name' => $firstName, 'last_name' => $lastName, 'email' => $emailValue]
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
    "SELECT u.user_id, u.first_name, u.last_name, u.display_name, u.username, u.email, u.profile_image, u.status, u.last_login_at,
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
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/user-info.css?v=20261025-profile')) ?>">
</head>

<body class="user-info-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <header class="page-heading">
                <h1>My profile</h1>
            </header>

            <?php if ($message): ?>
                <div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="user-info-layout">
                <section class="dashboard-section profile-card">
                    <div class="section-header">
                        <h2>Personal information</h2>
                    </div>

                    <?php if (can_manage_own_profile_image()): ?>
                        <div class="profile-picture-settings">
                            <?= profile_avatar_html((int)$account['user_id'], (string)$account['display_name'], $account['profile_image'] ?? null, 'profile-picture-avatar') ?>
                            <div class="profile-picture-controls">
                                <div class="profile-picture-copy">
                                    <h3>Profile picture</h3>
                                    <p class="field-hint">Optional.</p>
                                </div>
                                <form method="POST" enctype="multipart/form-data" class="profile-picture-form" id="profile-picture-form"
                                      data-max-bytes="<?= ProfileImageStorage::MAX_BYTES ?>" data-max-dimension="<?= ProfileImageStorage::MAX_DIMENSION ?>" data-max-pixels="<?= ProfileImageStorage::MAX_PIXELS ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="replace_profile_image">
                                    <div class="profile-picture-picker">
                                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= ProfileImageStorage::MAX_FILE_SIZE ?>">
                                        <input class="profile-picture-input" type="file" id="profile_image" name="profile_image" accept="<?= ProfileImageStorage::ACCEPT ?>" aria-describedby="profile-picture-help profile-crop-error" required>
                                        <label class="btn btn-quiet btn-small profile-picture-choose" for="profile_image"><i class="bi bi-camera" aria-hidden="true"></i><?= !empty($account['profile_image']) ? 'Choose Replacement' : 'Choose Image' ?></label>
                                        <span class="profile-picture-filename" id="profile-picture-filename">No file selected</span>
                                    </div>
                                    <small class="field-help" id="profile-picture-help"><?= ProfileImageStorage::HELP ?></small>
                                    <p id="profile-crop-error" class="field-help" role="alert"></p>
                                    <fieldset id="profile-crop-editor" hidden>
                                        <legend>Adjust square crop</legend>
                                        <div class="crop-preview-wrap">
                                            <canvas id="profile-crop-preview" width="256" height="256" role="img" aria-label="Selected square picture crop"></canvas>
                                        </div>
                                        <div class="crop-slider-group">
                                            <div class="crop-slider-row">
                                                <label for="profile-crop-zoom">Zoom</label>
                                                <input id="profile-crop-zoom" type="range" min="1" max="10" step="0.1" value="1">
                                            </div>
                                            <div class="crop-slider-row">
                                                <label for="profile-crop-horizontal">Horizontal position</label>
                                                <input id="profile-crop-horizontal" type="range" min="0" max="100" value="50">
                                            </div>
                                            <div class="crop-slider-row">
                                                <label for="profile-crop-vertical">Vertical position</label>
                                                <input id="profile-crop-vertical" type="range" min="0" max="100" value="50">
                                            </div>
                                        </div>
                                        <input id="profile-crop-x" type="hidden" name="crop[x]">
                                        <input id="profile-crop-y" type="hidden" name="crop[y]">
                                        <input id="profile-crop-size" type="hidden" name="crop[size]">
                                    </fieldset>
                                    <div class="profile-picture-actions">
                                        <button id="profile-picture-save" class="btn btn-primary btn-small" type="submit" disabled>Save picture</button>
                                        <button id="profile-picture-cancel" class="btn btn-quiet btn-small" type="button" hidden>Cancel</button>
                                    </div>
                                </form>
                                <?php if (!empty($account['profile_image'])): ?>
                                    <form method="POST" class="profile-picture-remove-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove_profile_image">
                                        <button class="btn btn-quiet btn-small" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i>Remove Picture</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="form-grid profile-details-form" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <?php if (empty($account['first_name'])): ?><p class="field-help full">Enter your first and last name to update your display name.</p><?php endif; ?>
                        <div class="form-group<?= isset($fieldErrors['first_name']) ? ' invalid' : '' ?>">
                            <label for="first_name">First name</label>
                            <input type="text" id="first_name" name="first_name" autocomplete="given-name" maxlength="50" value="<?= htmlspecialchars($profileValues['first_name'] ?? (string)($account['first_name'] ?? '')) ?>" required<?= isset($fieldErrors['first_name']) ? ' aria-invalid="true" aria-describedby="first-name-error"' : '' ?>>
                            <?php if (isset($fieldErrors['first_name'])): ?><small class="field-error" id="first-name-error"><?= htmlspecialchars($fieldErrors['first_name']) ?></small><?php endif; ?>
                        </div>
                        <div class="form-group<?= isset($fieldErrors['last_name']) ? ' invalid' : '' ?>">
                            <label for="last_name">Last name</label>
                            <input type="text" id="last_name" name="last_name" autocomplete="family-name" maxlength="50" value="<?= htmlspecialchars($profileValues['last_name'] ?? (string)($account['last_name'] ?? '')) ?>"<?= isset($fieldErrors['last_name']) ? ' aria-invalid="true" aria-describedby="last-name-error"' : '' ?>>
                            <?php if (isset($fieldErrors['last_name'])): ?><small class="field-error" id="last-name-error"><?= htmlspecialchars($fieldErrors['last_name']) ?></small><?php endif; ?>
                        </div>
                        <div class="form-group full<?= isset($fieldErrors['email']) ? ' invalid' : '' ?>">
                            <label for="email">Email address</label>
                            <input type="email" id="email" name="email" autocomplete="email" spellcheck="false" maxlength="100" value="<?= htmlspecialchars((string)($profileValues['email'] ?? $account['email'] ?? '')) ?>" placeholder="name@example.com"<?= isset($fieldErrors['email']) ? ' aria-invalid="true" aria-describedby="email-error"' : '' ?>>
                            <?php if (isset($fieldErrors['email'])): ?><small class="field-error" id="email-error"><?= htmlspecialchars($fieldErrors['email']) ?></small><?php endif; ?>
                        </div>
                        <div class="full profile-save-row">
                            <button class="btn btn-primary" type="submit">Save Details</button>
                        </div>
                    </form>
                </section>

                <section class="dashboard-section account-details-card">
                    <div class="section-header">
                        <h2>Account details</h2>
                    </div>
                    <dl class="account-detail-list">
                        <div><dt>Username</dt><dd><span class="account-detail-highlight">@<?= htmlspecialchars($account['username']) ?></span></dd></div>
                        <div><dt>Role</dt><dd><span class="badge-role"><?= htmlspecialchars($roleLabel) ?></span></dd></div>
                        <div><dt>Status</dt><dd class="account-status-value <?= $account['status'] === 'active' ? 'is-active' : 'is-inactive' ?>"><span class="status-badge <?= $account['status'] === 'active' ? 'good' : 'inactive' ?>"><span class="status-dot"></span><?= htmlspecialchars(ucfirst((string)$account['status'])) ?></span></dd></div>
                        <div><dt>User ID</dt><dd class="tabular-num">#<?= (int)$account['user_id'] ?></dd></div>
                        <div><dt>Last sign-in</dt><dd class="tabular-num"><?= htmlspecialchars(account_format_datetime($account['last_login_at'] ?? null)) ?></dd></div>
                        <div><dt>Account created</dt><dd class="tabular-num"><?= htmlspecialchars(account_format_datetime($account['created_at'] ?? null)) ?></dd></div>
                    </dl>
                    <div class="account-security">
                        <div class="security-header">
                            <div>
                                <h3>Password security</h3>
                                <p class="security-status-note <?= (int)$account['must_change_password'] === 1 ? 'is-warning' : 'is-good' ?>">
                                    <?= htmlspecialchars($passwordStatus) ?>
                                </p>
                            </div>
                        </div>
                        <dl class="account-detail-list security-detail-list">
                            <div><dt>Last changed</dt><dd class="tabular-num"><?= htmlspecialchars(account_format_datetime($account['password_changed_at'] ?? null)) ?></dd></div>
                        </dl>
                        <a class="btn btn-quiet change-password-btn" href="<?= htmlspecialchars(app_url('components/auth/change_password.php')) ?>">Change Password</a>
                    </div>
                </section>
            </div>
        </main>
    </div>
    <script src="<?= htmlspecialchars(app_url('assets/js/profile-picture-crop.js')) ?>"></script>
</body>

</html>
