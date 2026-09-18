<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::MANAGE_USERS);

use App\Authorization\RoleCapabilityPolicy;
use App\Services\UserLifecycleService;
use App\Store\StoreScope;

$storeId = store_scope_id($pdo);
$lifecycle = new UserLifecycleService($pdo, role_capability_policy(), new StoreScope($pdo));
$actorId = (int)$_SESSION['user_id'];
$actorRole = (string)current_role();
$message = '';
$messageClass = '';
$createFormSubmitted = false;
$createFormValues = ['first_name' => '', 'last_name' => '', 'username' => '', 'email' => '', 'role_id' => ''];

function display_label(string $value): string
{
    return ucwords(str_replace(['_', '-'], ' ', strtolower(trim($value))));
}

function display_person_name(string $value): string
{
    $value = trim($value);
    return $value === strtoupper($value) ? ucwords(strtolower($value)) : $value;
}

function user_last_active_label(?string $lastLogin): string
{
    if (!$lastLogin) {
        return 'Active: Never';
    }
    $timestamp = strtotime($lastLogin);
    if (!$timestamp) {
        return 'Active: Unknown';
    }
    $diff = max(0, time() - $timestamp);
    if ($diff < 60) return 'Active: Just now';
    if ($diff < 3600) return 'Active: ' . floor($diff / 60) . 'm ago';
    if ($diff < 86400) return 'Active: ' . floor($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'Active: Yesterday';
    return 'Active: ' . format_display_date(date('Y-m-d', $timestamp));
}

function role_name_for_id(PDO $pdo, int $roleId): ?string
{
    $statement = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ? AND role_name <> 'seller'");
    $statement->execute([$roleId]);
    $role = $statement->fetchColumn();
    return $role === false ? null : (string)$role;
}

function can_manage_user(array $user): bool
{
    return has_capability(RoleCapabilityPolicy::MANAGE_USERS, (string)($user['role_name'] ?? ''));
}

$action = (string)($_POST['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    try {
        if ($action === 'create') {
            $createFormSubmitted = true;
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $createFormValues = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'username' => trim((string)($_POST['username'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')),
                'role_id' => (string)($_POST['role_id'] ?? ''),
            ];
            $password = (string)($_POST['password'] ?? '');
            if ($passwordError = password_policy_error($password)) {
                throw new InvalidArgumentException($passwordError);
            }
            $role = role_name_for_id($pdo, (int)($_POST['role_id'] ?? 0));
            if ($role === null) {
                throw new InvalidArgumentException('Please choose a valid role template.');
            }
            $profileImage = profile_image_storage()->store($_FILES['profile_image'] ?? null);
            try {
                $lifecycle->create($actorId, $actorRole, [
                    'full_name' => trim($firstName . ' ' . $lastName),
                    'username' => $createFormValues['username'],
                    'email' => $createFormValues['email'],
                    'profile_image' => $profileImage,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                ]);
            } catch (Throwable $exception) {
                if ($profileImage !== null) profile_image_storage()->delete($profileImage);
                throw $exception;
            }
            $message = 'User created successfully in the Store.';
            $messageClass = 'tag-success';
            $createFormValues = ['first_name' => '', 'last_name' => '', 'username' => '', 'email' => '', 'role_id' => ''];
        } elseif ($action === 'update') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $before = $lifecycle->get($userId);
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $role = role_name_for_id($pdo, (int)($_POST['role_id'] ?? 0)) ?? (string)$before['role_name'];
            $account = [
                'full_name' => trim($firstName . ' ' . $lastName),
                'username' => trim((string)($_POST['username'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')),
                'role' => $role,
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
                $account['profile_image'] = null;
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
    } catch (DomainException | InvalidArgumentException | RuntimeException $exception) {
        $message = $exception->getMessage();
        $messageClass = 'tag-warning';
    } catch (PDOException $exception) {
        $message = 'Unable to save the account. Username or email may already exist.';
        $messageClass = 'tag-warning';
    }
}

$roles = array_values(array_filter(
    $pdo->query("SELECT role_id, role_name FROM roles WHERE role_name <> 'seller' ORDER BY role_id")->fetchAll(PDO::FETCH_ASSOC),
    static fn(array $role): bool => has_capability(RoleCapabilityPolicy::ASSIGN_ROLES, (string)$role['role_name'])
        && $role['role_name'] !== 'super_admin'
));
$users = $pdo->query(
    'SELECT u.*, r.role_name
     FROM users u
     JOIN roles r ON r.role_id = u.role_id
     ORDER BY u.created_at DESC, u.user_id DESC'
)->fetchAll(PDO::FETCH_ASSOC);
$activeCount = count(array_filter($users, static fn(array $user): bool => $user['status'] === 'active'));
$disabledCount = count($users) - $activeCount;
$isEmbedded = ($_GET['embed'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Staff</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body class="manage-users-page<?= $isEmbedded ? ' manage-users-embedded' : '' ?>">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div><h1>Store Staff</h1><p class="page-subtitle">Manage fixed role templates and account access for the single Store.</p></div>
        </header>
        <?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <div class="card-grid">
            <article class="stat-card with-icon"><span class="stat-icon"><i class="bi bi-people-fill"></i></span><div class="value"><?= count($users) ?></div><div class="label">Total Users</div></article>
            <article class="stat-card with-icon success"><span class="stat-icon"><i class="bi bi-person-check-fill"></i></span><div class="value"><?= $activeCount ?></div><div class="label">Active Users</div></article>
            <article class="stat-card with-icon <?= $disabledCount ? 'warning' : 'success' ?>"><span class="stat-icon"><i class="bi bi-person-x-fill"></i></span><div class="value"><?= $disabledCount ?></div><div class="label">Disabled Users</div></article>
        </div>
        <section class="dashboard-section manage-users-list" id="usersPanel">
            <div class="section-header">
                <div><h2>Store accounts</h2><p class="section-description">Every operational account is scoped to this Store automatically.</p></div>
                <button type="button" class="btn manage-users-primary manage-users-section-action" id="openUserModal"><i class="bi bi-person-plus"></i> Add Staff User</button>
            </div>
            <div class="table-wrap">
                <table class="users-table">
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $user):
                        $canManage = can_manage_user($user);
                        $isSelf = (int)$user['user_id'] === $actorId;
                        $isSuper = $user['role_name'] === 'super_admin';
                        $hasImage = !empty($user['profile_image']) && profile_image_storage()->exists((string)$user['profile_image']);
                    ?>
                        <tr>
                            <td><div class="user-name-cell"><?= profile_avatar_html((int)$user['user_id'], (string)$user['full_name'], $user['profile_image'] ?? null, 'user-avatar') ?><strong><?= htmlspecialchars(display_person_name((string)$user['full_name'])) ?></strong></div></td>
                            <td><?= htmlspecialchars((string)($user['email'] ?: $user['username'])) ?></td>
                            <td><?= htmlspecialchars(display_label((string)$user['role_name'])) ?></td>
                            <td><?= htmlspecialchars(display_label((string)$user['status'])) ?></td>
                            <td class="action-cell">
                                <?php if (!$canManage): ?>
                                    <span class="user-protected-label"><i class="bi bi-lock-fill"></i> Protected</span>
                                <?php else: ?>
                                    <button type="button" class="btn btn-small open-user-drawer"
                                        data-user-id="<?= (int)$user['user_id'] ?>"
                                        data-full-name="<?= htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-username="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-email="<?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-role-id="<?= (int)$user['role_id'] ?>" data-status="<?= htmlspecialchars((string)$user['status']) ?>"
                                        data-is-self="<?= $isSelf ? '1' : '0' ?>" data-is-super-admin="<?= $isSuper ? '1' : '0' ?>"
                                        data-profile-initials="<?= htmlspecialchars(profile_initials((string)$user['full_name'])) ?>"
                                        data-profile-url="<?= $hasImage ? htmlspecialchars(profile_image_url((int)$user['user_id'])) : '' ?>"
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
<script>
document.addEventListener('DOMContentLoaded', function () {
    const addOverlay = document.getElementById('userModalOverlay');
    const drawerOverlay = document.getElementById('userDrawerOverlay');
    const setOpen = (overlay, open) => { if (overlay) { overlay.classList.toggle('open', open); overlay.setAttribute('aria-hidden', open ? 'false' : 'true'); } };
    document.getElementById('openUserModal')?.addEventListener('click', () => setOpen(addOverlay, true));
    document.querySelectorAll('#closeUserModal, #cancelUserModal').forEach(button => button.addEventListener('click', () => setOpen(addOverlay, false)));
    document.querySelectorAll('#closeUserDrawer, #cancelUserDrawer').forEach(button => button.addEventListener('click', () => setOpen(drawerOverlay, false)));

    const drawerTabs = document.querySelectorAll('[data-user-drawer-tab]');
    const drawerPanels = document.querySelectorAll('[data-user-drawer-panel]');
    function selectDrawerTab(name) {
        drawerTabs.forEach(tab => { const selected = tab.dataset.userDrawerTab === name; tab.classList.toggle('is-active', selected); tab.setAttribute('aria-selected', selected ? 'true' : 'false'); });
        drawerPanels.forEach(panel => { panel.hidden = panel.dataset.userDrawerPanel !== name; panel.classList.toggle('is-active', !panel.hidden); });
    }
    drawerTabs.forEach(tab => tab.addEventListener('click', () => selectDrawerTab(tab.dataset.userDrawerTab)));

    document.querySelectorAll('.open-user-drawer').forEach(button => button.addEventListener('click', function () {
        const parts = (button.dataset.fullName || '').trim().split(/\s+/).filter(Boolean);
        document.getElementById('drawerUserId').value = button.dataset.userId || '';
        document.getElementById('drawerRevokeUserId').value = button.dataset.userId || '';
        document.getElementById('drawerUsername').value = button.dataset.username || '';
        document.getElementById('drawerFirstName').value = parts.shift() || '';
        document.getElementById('drawerLastName').value = parts.join(' ');
        document.getElementById('drawerEmail').value = button.dataset.email || '';
        document.getElementById('drawerRole').value = button.dataset.roleId || '';
        document.getElementById('drawerStatus').value = button.dataset.status || 'active';
        document.getElementById('userDrawerMeta').textContent = '@' + (button.dataset.username || '');
        const isSelf = button.dataset.isSelf === '1';
        const isSuper = button.dataset.isSuperAdmin === '1';
        document.getElementById('drawerRole').disabled = isSelf || isSuper;
        document.getElementById('drawerStatus').disabled = isSelf || isSuper;
        document.getElementById('drawerStatusButton').disabled = isSelf || isSuper;
        document.getElementById('drawerRevokeButton').disabled = isSelf || isSuper;
        const remove = document.getElementById('drawerRemoveProfileImage');
        remove.checked = false; remove.disabled = button.dataset.hasProfileImage !== '1';
        selectDrawerTab('user');
        setOpen(drawerOverlay, true);
    }));

    document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', function () {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
    }));
    [addOverlay, drawerOverlay].forEach(overlay => overlay?.addEventListener('click', event => { if (event.target === overlay) setOpen(overlay, false); }));
    document.addEventListener('keydown', event => { if (event.key === 'Escape') { setOpen(addOverlay, false); setOpen(drawerOverlay, false); } });
    <?php if ($createFormSubmitted && $messageClass === 'tag-warning'): ?>setOpen(addOverlay, true);<?php endif; ?>
});
</script>
</body>
</html>
