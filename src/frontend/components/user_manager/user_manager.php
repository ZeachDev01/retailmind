<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::MANAGE_USERS);

use App\Authorization\RoleCapabilityPolicy;
use App\Authorization\DormancyStatusTab;
use App\Services\UserLifecycleService;
use App\Store\StoreScope;

$storeId = store_scope_id($pdo);
$lifecycle = new UserLifecycleService($pdo, role_capability_policy(), new StoreScope($pdo));
$dormancyStatusTab = new DormancyStatusTab($pdo);
$actorId = (int)$_SESSION['user_id'];
$actorRole = (string)current_role();
$pageTitle = $actorRole === 'super_admin' ? 'Users & Access' : 'Store Staff';
$message = '';
$messageClass = '';
$createFormSubmitted = false;
$createFormValues = ['first_name' => '', 'last_name' => '', 'username' => '', 'email' => '', 'role_ids' => []];

function display_label(string $value): string
{
    return ucwords(str_replace(['_', '-'], ' ', strtolower(trim($value))));
}

function display_person_name(string $value): string
{
    $value = trim($value);
    return $value === strtoupper($value) ? ucwords(strtolower($value)) : $value;
}

function role_name_for_id(PDO $pdo, int $roleId): ?string
{
    $statement = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ? AND role_name <> 'seller'");
    $statement->execute([$roleId]);
    $role = $statement->fetchColumn();
    return $role === false ? null : (string)$role;
}

function role_names_for_ids(PDO $pdo, array $roleIds): array
{
    $roles = [];
    foreach (array_values(array_unique(array_map('intval', $roleIds))) as $roleId) {
        $role = role_name_for_id($pdo, $roleId);
        if ($role !== null) {
            $roles[] = $role;
        }
    }
    return $roles;
}

$action = (string)($_POST['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && (string)($_GET['action'] ?? '') === 'availability') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $availability = $lifecycle->availability($actorRole, (string)($_GET['username'] ?? ''), (string)($_GET['email'] ?? ''));
    } catch (DomainException $exception) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied.']);
        exit;
    }
    echo json_encode(['username_taken' => $availability['username_taken'], 'email_taken' => $availability['email_taken']]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    profile_image_check_request_size();
    csrf_verify();
    try {
        if ($action === 'remove_profile_image') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $lifecycle->removePicture($actorId, $actorRole, $userId, profile_image_storage());
            if ($userId === $actorId) $_SESSION['profile_image'] = null;
            $message = 'Profile picture removed. Initials are now shown.';
            $messageClass = 'tag-success';
        } elseif ($action === 'create') {
            $createFormSubmitted = true;
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $createFormValues = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'username' => trim((string)($_POST['username'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')),
                'role_ids' => array_map('strval', (array)($_POST['role_ids'] ?? [])),
            ];
            $password = (string)($_POST['password'] ?? '');
            if ($passwordError = password_policy_error($password)) {
                throw new InvalidArgumentException($passwordError);
            }
            $selectedRoles = role_names_for_ids($pdo, (array)($_POST['role_ids'] ?? []));
            if ($selectedRoles === []) {
                throw new InvalidArgumentException('Please choose at least one valid role template.');
            }
            $profileImage = profile_image_storage()->store($_FILES['profile_image'] ?? null);
            try {
                $lifecycle->create($actorId, $actorRole, [
                    'full_name' => trim($firstName . ' ' . $lastName),
                    'username' => $createFormValues['username'],
                    'email' => $createFormValues['email'],
                    'profile_image' => $profileImage,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'roles' => $selectedRoles,
                ]);
            } catch (Throwable $exception) {
                if ($profileImage !== null) profile_image_storage()->delete($profileImage);
                throw $exception;
            }
            $message = 'User created successfully in the Store.';
            $messageClass = 'tag-success';
            $createFormValues = ['first_name' => '', 'last_name' => '', 'username' => '', 'email' => '', 'role_ids' => []];
        } elseif ($action === 'update') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $before = $lifecycle->get($userId);
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $selectedRoles = role_names_for_ids($pdo, (array)($_POST['role_ids'] ?? []));
            if ($selectedRoles === [] && !($before['role_name'] === 'super_admin'
                && $actorRole === 'super_admin' && $userId === $actorId
                && ($_POST['role_access_present'] ?? '') === '1')) {
                $selectedRoles = (array)($before['roles'] ?? [$before['role_name']]);
            }
            $account = [
                'full_name' => trim($firstName . ' ' . $lastName),
                'username' => trim((string)($_POST['username'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')),
                'roles' => $selectedRoles,
            ];
            $newPassword = (string)($_POST['new_password'] ?? '');
            if ($newPassword !== '' && ($passwordError = password_policy_error($newPassword))) {
                throw new InvalidArgumentException($passwordError);
            }
            $newProfileImage = null;
            if (\App\Services\ProfileImageStorage::hasUpload($_FILES['profile_image'] ?? null)) {
                $newProfileImage = profile_image_storage()->store($_FILES['profile_image']);
                $account['profile_image'] = $newProfileImage;
            } elseif (isset($_POST['remove_profile_image'])) {
                throw new InvalidArgumentException('Use Remove picture to remove the current picture separately.');
            }
            try {
                $after = $lifecycle->update($actorId, $actorRole, $userId, $account);
            } catch (Throwable $exception) {
                if ($newProfileImage !== null) profile_image_storage()->delete($newProfileImage);
                throw $exception;
            }
            if (array_key_exists('profile_image', $account) && !empty($before['profile_image']) && $before['profile_image'] !== ($after['profile_image'] ?? null)) {
                profile_image_storage()->delete((string)$before['profile_image']);
            }
            if ($newPassword !== '') {
                $lifecycle->resetPassword($actorId, $actorRole, $userId, password_hash($newPassword, PASSWORD_DEFAULT));
            }
            $requestedStatus = (string)($_POST['status'] ?? $before['status']);
            if ($requestedStatus !== $before['status']) {
                $lifecycle->setStatus($actorId, $actorRole, $userId, $requestedStatus);
            }
            if ($userId === $actorId) {
                $_SESSION['full_name'] = $after['full_name'];
                $_SESSION['profile_image'] = $after['profile_image'] ?? null;
            }
            $message = 'User account updated successfully.';
            $messageClass = 'tag-success';
        } elseif ($action === 'toggle') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $target = $lifecycle->get($userId);
            $lifecycle->setStatus($actorId, $actorRole, $userId, $target['status'] === 'active' ? 'disabled' : 'active');
            $message = 'User status updated and active sessions revoked.';
            $messageClass = 'tag-success';
        } elseif ($action === 'revoke_sessions') {
            $lifecycle->revokeSessions($actorId, $actorRole, (int)($_POST['user_id'] ?? 0));
            $message = 'Active sessions revoked.';
            $messageClass = 'tag-success';
        }
    } catch (PDOException $exception) {
        $message = \App\Support\OperatorAlert::message($exception, $action === 'remove_profile_image'
            ? 'The profile picture could not be removed. Please try again. Tell your Administrator if this keeps happening.'
            : 'Unable to save the account. Username or email may already exist. Tell your Administrator if this keeps happening.');
        $messageClass = 'tag-warning';
    } catch (Throwable $exception) {
        $message = \App\Support\OperatorAlert::message($exception, 'The account could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.');
        $messageClass = 'tag-warning';
    }
}

$roles = array_values(array_filter(
    $pdo->query("SELECT role_id, role_name FROM roles WHERE role_name <> 'seller' ORDER BY role_id")->fetchAll(PDO::FETCH_ASSOC),
    static fn(array $role): bool => has_capability(RoleCapabilityPolicy::ASSIGN_ROLES, (string)$role['role_name'])
        && $role['role_name'] !== 'super_admin'
));
$users = $pdo->query(
    "SELECT u.*, r.role_name,
            COALESCE(GROUP_CONCAT(DISTINCT ar.role_name ORDER BY ur.is_primary DESC, ar.role_id SEPARATOR ','), r.role_name) AS assigned_roles,
            COALESCE(GROUP_CONCAT(DISTINCT ar.role_id ORDER BY ur.is_primary DESC, ar.role_id SEPARATOR ','), u.role_id) AS assigned_role_ids
     FROM users u
     JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN user_roles ur ON ur.user_id = u.user_id
     LEFT JOIN roles ar ON ar.role_id = ur.role_id AND ar.role_name <> 'seller'
     WHERE u.is_recovery_account = 0
     GROUP BY u.user_id
     ORDER BY u.created_at DESC, u.user_id DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$activeCount = count(array_filter($users, static fn(array $user): bool => $user['status'] === 'active'));
$disabledCount = count($users) - $activeCount;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" data-bootstrap-icons>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/avatars.css')) ?>?v=<?= (int)@filemtime(__DIR__ . '/../../assets/css/avatars.css') ?>" data-avatar-styles>
</head>
<body class="manage-users-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div><h1><?= htmlspecialchars($pageTitle) ?></h1><p class="page-subtitle">Manage Store staff accounts and fixed role access.</p></div>
        </header>
        <?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>" role="status"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <div class="card-grid">
            <article class="stat-card with-icon"><span class="stat-icon"><i class="bi bi-people-fill" aria-hidden="true"></i></span><div class="value"><?= count($users) ?></div><div class="label">Total Users</div></article>
            <article class="stat-card with-icon success"><span class="stat-icon"><i class="bi bi-person-check-fill" aria-hidden="true"></i></span><div class="value"><?= $activeCount ?></div><div class="label">Active Users</div></article>
            <article class="stat-card with-icon <?= $disabledCount ? 'warning' : '' ?>"><span class="stat-icon"><i class="bi bi-person-x-fill" aria-hidden="true"></i></span><div class="value"><?= $disabledCount ?></div><div class="label">Disabled Users</div></article>
        </div>
        <section class="dashboard-section manage-users-list" id="usersPanel">
            <div class="section-header">
                <div><h2 id="usersHeading">Store accounts</h2></div>
                <button type="button" class="btn manage-users-primary manage-users-section-action" id="openUserModal"><i class="bi bi-person-plus" aria-hidden="true"></i> Add Staff User</button>
            </div>
            <div class="table-wrap" role="region" aria-labelledby="usersHeading" tabindex="0">
                <table class="users-table">
                    <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Password</th><th scope="col">Action</th></tr></thead>
                    <tbody>
                    <?php if ($users === []): ?>
                        <tr class="table-empty-row"><td colspan="6" class="text-muted">No staff accounts are registered in this Store yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($users as $user):
                        $pictureTarget = $user;
                        $pictureTarget['roles'] = explode(',', (string)($user['assigned_roles'] ?? $user['role_name']));
                        $canManage = !(bool)($_SESSION['is_recovery_account'] ?? false)
                            && $lifecycle->canManageAccount($actorId, $actorRole, $pictureTarget);
                        $isSelf = (int)$user['user_id'] === $actorId;
                        $isSuper = $user['role_name'] === 'super_admin';
                        $hasImage = !empty($user['profile_image']) && profile_image_storage()->exists((string)$user['profile_image']);
                        $canViewImage = $hasImage && $lifecycle->canViewPicture($actorId, $actorRole, $pictureTarget);
                        $statusView = $dormancyStatusTab->view($user);
                        $displayName = display_person_name((string)$user['full_name']);
                    ?>
                        <tr>
                            <td><div class="user-name-cell"><?= profile_avatar_html((int)$user['user_id'], (string)$user['full_name'], $canViewImage ? ($user['profile_image'] ?? null) : null, 'user-avatar') ?><strong><?= htmlspecialchars($displayName) ?></strong></div></td>
                            <td><?= htmlspecialchars((string)($user['email'] ?: $user['username'])) ?></td>
                            <td><?= htmlspecialchars(implode(', ', array_map('display_label', array_filter(explode(',', (string)($user['assigned_roles'] ?? $user['role_name'])))))) ?></td>
                            <td><span class="<?= $user['status'] === 'active' ? 'tag-success' : 'tag-warning' ?>"><?= htmlspecialchars(display_label((string)$user['status'])) ?></span></td>
                            <td><?= ((int)($user['must_change_password'] ?? 0) === 1) ? '<span class="tag-warning">Change required</span>' : '<span class="tag-success">Current</span>' ?></td>
                            <td class="action-cell">
                                <?php if (!$canManage): ?>
                                    <span class="user-protected-label"><i class="bi bi-lock-fill" aria-hidden="true"></i> Protected</span>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-small open-user-drawer"
                                        aria-label="Manage <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>"
                                        data-user-id="<?= (int)$user['user_id'] ?>"
                                        data-full-name="<?= htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-username="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-email="<?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-role-id="<?= (int)$user['role_id'] ?>"
                                        data-role-ids="<?= htmlspecialchars((string)($user['assigned_role_ids'] ?? $user['role_id']), ENT_QUOTES, 'UTF-8') ?>"
                                        data-status="<?= htmlspecialchars((string)$user['status']) ?>"
                                        data-last-login="<?= htmlspecialchars($statusView['last_login'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-auto-disable-date="<?= htmlspecialchars((string)($statusView['auto_disable_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-policy-disabled="<?= htmlspecialchars((string)($statusView['policy_disabled_line'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-is-self="<?= $isSelf ? '1' : '0' ?>" data-is-super-admin="<?= $isSuper ? '1' : '0' ?>"
                                        data-profile-initials="<?= htmlspecialchars(profile_initials((string)$user['full_name'])) ?>"
                                        data-profile-url="<?= $canViewImage ? htmlspecialchars(profile_image_url((int)$user['user_id'])) : '' ?>"
                                        data-has-profile-image="<?= $hasImage ? '1' : '0' ?>">Manage</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
<?php include __DIR__ . '/modals/manage_user_modal.php'; ?>
<?php include __DIR__ . '/modals/add_user_modal.php'; ?>
<script src="<?= htmlspecialchars(app_url('assets/js/password-feedback.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/store-staff-availability.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const addOverlay = document.getElementById('userModalOverlay');
    const drawerOverlay = document.getElementById('userDrawerOverlay');
    const resetPasswordToggles = (root) => {
        (root || document).querySelectorAll('[data-password-toggle]').forEach(toggle => {
            const input = document.getElementById(toggle.dataset.passwordToggle);
            if (input) input.type = 'password';
            toggle.setAttribute('aria-label', 'Show password');
            toggle.setAttribute('aria-pressed', 'false');
            const icon = toggle.querySelector('i');
            if (icon) {
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });
    };
    const setOpen = (overlay, open) => {
        if (!overlay) return;
        if (!open) resetPasswordToggles(overlay);
        if (open) {
            if (window.RetailMindUI?.openOverlay) {
                RetailMindUI.openOverlay(overlay);
            } else {
                overlay.classList.add('open');
                overlay.setAttribute('aria-hidden', 'false');
                document.body.classList.add('no-scroll');
            }
        } else {
            if (window.RetailMindUI?.closeOverlay) {
                RetailMindUI.closeOverlay(overlay);
            } else {
                overlay.classList.remove('open');
                overlay.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('no-scroll');
            }
        }
    };
    document.getElementById('openUserModal')?.addEventListener('click', () => setOpen(addOverlay, true));
    document.querySelectorAll('#closeUserModal, #cancelUserModal').forEach(button => button.addEventListener('click', () => setOpen(addOverlay, false)));
    document.querySelectorAll('#closeUserDrawer, #cancelUserDrawer').forEach(button => button.addEventListener('click', () => setOpen(drawerOverlay, false)));

    const drawerTabs = Array.from(document.querySelectorAll('[data-user-drawer-tab]'));
    const drawerPanels = Array.from(document.querySelectorAll('[data-user-drawer-panel]'));
    function selectDrawerTab(name, focusTab = false) {
        drawerTabs.forEach(tab => {
            const selected = tab.dataset.userDrawerTab === name;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.setAttribute('tabindex', selected ? '0' : '-1');
            if (selected && focusTab) tab.focus();
        });
        drawerPanels.forEach(panel => {
            panel.hidden = panel.dataset.userDrawerPanel !== name;
            panel.classList.toggle('is-active', !panel.hidden);
        });
    }
    drawerTabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selectDrawerTab(tab.dataset.userDrawerTab));
        tab.addEventListener('keydown', (event) => {
            let nextIndex = -1;
            if (event.key === 'ArrowRight') nextIndex = (index + 1) % drawerTabs.length;
            else if (event.key === 'ArrowLeft') nextIndex = (index - 1 + drawerTabs.length) % drawerTabs.length;
            else if (event.key === 'Home') nextIndex = 0;
            else if (event.key === 'End') nextIndex = drawerTabs.length - 1;
            if (nextIndex >= 0) {
                event.preventDefault();
                selectDrawerTab(drawerTabs[nextIndex].dataset.userDrawerTab, true);
            }
        });
    });

    const drawerForm = document.getElementById('userDrawerForm');
    drawerForm?.addEventListener('invalid', (event) => {
        const panel = event.target instanceof HTMLElement ? event.target.closest('[data-user-drawer-panel]') : null;
        if (panel && panel.hidden) {
            selectDrawerTab(panel.dataset.userDrawerPanel);
        }
    }, true);

    document.querySelectorAll('.open-user-drawer').forEach(button => button.addEventListener('click', function () {
        const parts = (button.dataset.fullName || '').trim().split(/\s+/).filter(Boolean);
        document.getElementById('drawerUserId').value = button.dataset.userId || '';
        document.getElementById('drawerRevokeUserId').value = button.dataset.userId || '';
        document.getElementById('drawerUsername').value = button.dataset.username || '';
        document.getElementById('drawerFirstName').value = parts.shift() || '';
        document.getElementById('drawerLastName').value = parts.join(' ');
        document.getElementById('drawerEmail').value = button.dataset.email || '';
        const drawerPassword = document.getElementById('drawerPassword');
        if (drawerPassword) {
            drawerPassword.value = '';
            drawerPassword.setCustomValidity('');
            drawerPassword.dispatchEvent(new Event('input'));
        }
        const drawerProfileImage = document.getElementById('drawerProfileImage');
        if (drawerProfileImage) drawerProfileImage.value = '';
        resetPasswordToggles(drawerOverlay);
        const avatar = document.getElementById('drawerProfileAvatar');
        if (avatar) {
            const fallback = avatar.querySelector('.profile-avatar-fallback');
            if (fallback) fallback.textContent = button.dataset.profileInitials || 'RM';
            const existingImg = avatar.querySelector('.profile-avatar-image');
            if (existingImg) existingImg.remove();
            if (button.dataset.profileUrl) {
                const img = document.createElement('img');
                img.className = 'profile-avatar-image';
                img.src = button.dataset.profileUrl;
                img.alt = '';
                img.loading = 'lazy';
                img.decoding = 'async';
                img.onerror = function () { this.remove(); };
                avatar.appendChild(img);
            }
        }
        const selectedRoleIds = (button.dataset.roleIds || button.dataset.roleId || '').split(',').filter(Boolean);
        document.querySelectorAll('#drawerRoles input[name="role_ids[]"]').forEach(input => {
            input.checked = selectedRoleIds.includes(input.value);
        });
        document.getElementById('drawerStatus').value = button.dataset.status || 'active';
        document.getElementById('drawerLastLogin').textContent = button.dataset.lastLogin || 'Never';
        const autoDisableDate = button.dataset.autoDisableDate || '';
        document.getElementById('drawerAutoDisableDate').textContent = autoDisableDate;
        document.getElementById('drawerAutoDisableRow').hidden = autoDisableDate === '';
        const policyDisabledLine = button.dataset.policyDisabled || '';
        document.getElementById('drawerPolicyDisabledLine').textContent = policyDisabledLine;
        document.getElementById('drawerPolicyDisabledLine').hidden = policyDisabledLine === '';
        document.getElementById('userDrawerMeta').textContent = '@' + (button.dataset.username || '');
        const isSelf = button.dataset.isSelf === '1';
        const isSuper = button.dataset.isSuperAdmin === '1';
        const canEditOwnSuperRoles = isSelf && isSuper && <?= json_encode($actorRole === 'super_admin') ?>;
        document.querySelectorAll('#drawerRoles input[name="role_ids[]"]').forEach(input => { input.disabled = (isSelf || isSuper) && !canEditOwnSuperRoles; });
        document.getElementById('drawerStatus').disabled = isSelf || isSuper;
        document.getElementById('drawerStatusButton').disabled = (isSelf || isSuper) && !canEditOwnSuperRoles;
        document.getElementById('drawerRevokeButton').disabled = isSelf || isSuper;
        const remove = document.getElementById('drawerRemoveProfileImage');
        remove.disabled = button.dataset.hasProfileImage !== '1';
        selectDrawerTab('user');
        setOpen(drawerOverlay, true);
    }));

    document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', function () {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        const showing = input.type === 'password';
        input.type = showing ? 'text' : 'password';
        button.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', showing ? 'true' : 'false');
        const icon = button.querySelector('i');
        if (icon) {
            icon.classList.toggle('bi-eye', !showing);
            icon.classList.toggle('bi-eye-slash', showing);
        }
    }));
    if (!window.RetailMindUI?.openOverlay) {
        [addOverlay, drawerOverlay].forEach(overlay => overlay?.addEventListener('click', event => { if (event.target === overlay) setOpen(overlay, false); }));
    }
    <?php if ($createFormSubmitted && $messageClass === 'tag-warning'): ?>setOpen(addOverlay, true);<?php endif; ?>
});
</script>
</body>
</html>
