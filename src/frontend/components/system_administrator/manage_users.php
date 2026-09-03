<?php
// components/system_administrator/manage_users.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_role(['admin']);

$message = '';
$messageClass = '';

function get_user_snapshot(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT user_id, username, full_name, email, status, role_id FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function role_exists(PDO $pdo, int $roleId): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE role_id = ?");
    $stmt->execute([$roleId]);

    return (int)$stmt->fetchColumn() > 0;
}

// Handle create user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    csrf_verify();
    $passwordError = password_policy_error((string)($_POST['password'] ?? ''));
    if ($passwordError) {
        $message = $passwordError;
        $messageClass = 'tag-warning';
    } else try {
        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, username, email, password_hash, role_id, must_change_password) VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            $_POST['full_name'],
            $_POST['username'],
            $_POST['email'],
            password_hash($_POST['password'], PASSWORD_DEFAULT),
            $_POST['role_id'],
        ]);
        $newUserId = (int)$pdo->lastInsertId();
        log_activity(
            $pdo,
            (int)$_SESSION['user_id'],
            'User creation',
            'Users',
            $newUserId,
            null,
            [
                'user_id' => $newUserId,
                'full_name' => $_POST['full_name'] ?? '',
                'username' => $_POST['username'] ?? '',
                'email' => $_POST['email'] ?? '',
                'role_id' => (int)($_POST['role_id'] ?? 0),
                'status' => 'active',
            ]
        );
        $message = 'User created successfully.';
        $messageClass = 'tag-success';
    } catch (PDOException $e) {
        $message = 'Unable to create user. Username or email may already exist.';
        $messageClass = 'tag-warning';
    }
}

// Handle user account updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    csrf_verify();
    $userId = (int)($_POST['user_id'] ?? 0);
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $requestedStatus = $_POST['status'] ?? null;
    $requestedRoleId = (int)($_POST['role_id'] ?? 0);

    if ($userId <= 0 || $fullName === '' || $username === '') {
        $message = 'Full name and username are required.';
        $messageClass = 'tag-warning';
    } else {
        $before = get_user_snapshot($pdo, $userId);

        if (!$before) {
            $message = 'User not found.';
            $messageClass = 'tag-warning';
        } else {
            $status = in_array($requestedStatus, ['active', 'disabled'], true)
                ? $requestedStatus
                : $before['status'];
            $roleId = $requestedRoleId > 0 ? $requestedRoleId : (int)$before['role_id'];

            if ($userId === (int)$_SESSION['user_id'] && $status === 'disabled') {
                $message = 'You cannot disable your own account while logged in.';
                $messageClass = 'tag-warning';
            } elseif ($userId === (int)$_SESSION['user_id'] && $roleId !== (int)$before['role_id']) {
                $message = 'You cannot change your own role while logged in.';
                $messageClass = 'tag-warning';
            } elseif (!role_exists($pdo, $roleId)) {
                $message = 'Please choose a valid role.';
                $messageClass = 'tag-warning';
            } else {
                try {
                    $updateParts = ['full_name = ?', 'username = ?', 'status = ?', 'role_id = ?'];
                    $params = [$fullName, $username, $status, $roleId];
                    $shouldInvalidateSessions = false;

                    if ($newPassword !== '') {
                        if ($passwordError = password_policy_error($newPassword)) {
                            throw new InvalidArgumentException($passwordError);
                        }
                        $updateParts[] = 'password_hash = ?';
                        $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
                        $updateParts[] = 'password_changed_at = NOW()';
                        $updateParts[] = 'must_change_password = 1';
                        $shouldInvalidateSessions = true;
                    }
                    if ($status !== $before['status']) {
                        $shouldInvalidateSessions = true;
                    }
                    if ($roleId !== (int)$before['role_id']) {
                        $shouldInvalidateSessions = true;
                    }
                    if ($shouldInvalidateSessions) {
                        $updateParts[] = 'session_version = session_version + 1';
                    }

                    $params[] = $userId;
                    $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $updateParts) . ' WHERE user_id = ?');
                    $stmt->execute($params);

                    $after = get_user_snapshot($pdo, $userId);
                    log_activity(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        $newPassword !== '' ? 'User account update with password change' : 'User account update',
                        'Users',
                        $userId,
                        $before,
                        $after
                    );

                    if ($userId === (int)$_SESSION['user_id']) {
                        $_SESSION['full_name'] = $fullName;
                    }

                    $message = 'User account updated successfully.';
                    $messageClass = 'tag-success';
                } catch (InvalidArgumentException $e) {
                    $message = $e->getMessage();
                    $messageClass = 'tag-warning';
                } catch (PDOException $e) {
                    $message = 'Unable to update user. Username may already exist.';
                    $messageClass = 'tag-warning';
                }
            }
        }
    }
}

// Handle delete user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    csrf_verify();
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($userId === (int)$_SESSION['user_id']) {
        $message = 'You cannot delete your own account while logged in.';
        $messageClass = 'tag-warning';
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);

            if ($stmt->rowCount() > 0) {
                log_activity($pdo, (int)$_SESSION['user_id'], "Deleted user ID {$userId}", 'Users', $userId);
                $message = 'User deleted successfully.';
                $messageClass = 'tag-success';
            } else {
                $message = 'User not found.';
                $messageClass = 'tag-warning';
            }
        } catch (PDOException $e) {
            $message = 'Unable to delete this user because they are referenced by other records. Disable the account instead.';
            $messageClass = 'tag-warning';
        }
    }
}

// Handle disable/enable toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle') {
    csrf_verify();
    $toggleId = (int)($_POST['user_id'] ?? 0);
    $before = get_user_snapshot($pdo, $toggleId);

    if ($before) {
        $stmt = $pdo->prepare(
            "UPDATE users SET status = IF(status='active','disabled','active'), session_version = session_version + 1 WHERE user_id = ?"
        );
        $stmt->execute([$toggleId]);

        $after = get_user_snapshot($pdo, $toggleId);
        $action = ($after['status'] ?? '') === 'disabled' ? 'User deactivation' : 'User activation';
        log_activity($pdo, (int)$_SESSION['user_id'], $action, 'Users', $toggleId, $before, $after);
    }
    header('Location: manage_users.php');
    exit;
}

$roles = $pdo->query("SELECT * FROM roles")->fetchAll();
$users = $pdo->query(
    "SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id ORDER BY u.created_at DESC"
)->fetchAll();
$activeCount = count(array_filter($users, fn($user) => $user['status'] === 'active'));
$disabledCount = count($users) - $activeCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <h1>Manage Users</h1>
                <p class="page-subtitle">Create accounts, manage access, and keep team permissions organized.</p>
            </div>
            <button type="button" class="btn" id="openUserModal">+ Add User</button>
        </div>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card-grid">
            <div class="stat-card">
                <div class="value"><?= count($users) ?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= $activeCount ?></div>
                <div class="label">Active Users</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= $disabledCount ?></div>
                <div class="label">Disabled Users</div>
            </div>
        </div>

        <div class="dashboard-section">
            <div class="section-header">
                <div>
                    <h3>All Users</h3>
                    <p class="section-description">Review account status and manage permissions quickly.</p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="users-table">
                    <tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Action</th></tr>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['full_name']) ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['role_name']) ?></td>
                        <td><?= $u['status'] === 'active' ? '<span class="tag-success">active</span>' : '<span class="tag-warning">disabled</span>' ?></td>
                        <td class="action-cell">
                            <button
                                type="button"
                                class="btn btn-small open-user-drawer"
                                data-user-id="<?= (int)$u['user_id'] ?>"
                                data-full-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>"
                                data-status="<?= htmlspecialchars($u['status'], ENT_QUOTES, 'UTF-8') ?>"
                                data-role-id="<?= (int)$u['role_id'] ?>"
                                data-is-self="<?= (int)$u['user_id'] === (int)$_SESSION['user_id'] ? '1' : '0' ?>"
                            >Manage</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="user-drawer-overlay" id="userDrawerOverlay" aria-hidden="true">
    <aside class="user-drawer" role="dialog" aria-modal="true" aria-labelledby="userDrawerTitle">
        <div class="user-drawer-header">
            <div>
                <h3 id="userDrawerTitle">Manage Account</h3>
                <p id="userDrawerMeta"></p>
            </div>
            <button type="button" class="user-modal-close" id="closeUserDrawer" aria-label="Close account manager">&times;</button>
        </div>
        <div class="user-drawer-body">
            <form method="POST" class="user-form user-drawer-form" id="userDrawerForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" id="drawerUserId">
                <div class="form-group">
                    <label for="drawerFullName">Full Name</label>
                    <input id="drawerFullName" name="full_name" required>
                </div>
                <div class="form-group">
                    <label for="drawerUsername">Username</label>
                    <input id="drawerUsername" name="username" required>
                </div>
                <div class="form-group">
                    <label for="drawerPassword">New Password</label>
                    <input type="password" id="drawerPassword" name="new_password" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="drawerRole">Role</label>
                    <select id="drawerRole" name="role_id" required>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int)$r['role_id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="drawerStatus">Status</label>
                    <select id="drawerStatus" name="status">
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="cancelUserDrawer">Cancel</button>
                    <button class="btn" type="submit">Save Changes</button>
                </div>
            </form>

            <div class="drawer-danger">
                <h4>Delete Account</h4>
                <form method="POST" id="drawerDeleteForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <button type="submit" class="btn btn-danger" id="deleteUserButton">Delete Account</button>
                </form>
            </div>
        </div>
    </aside>
</div>

<div class="user-modal-overlay" id="userModalOverlay" aria-hidden="true">
    <div class="user-modal" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
        <div class="user-modal-header">
            <div>
                <h3 id="userModalTitle">Add New User</h3>
                <p>Create a new account and assign a role.</p>
            </div>
            <button type="button" class="user-modal-close" id="closeUserModal" aria-label="Close add user form">&times;</button>
        </div>
        <form method="POST" class="user-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>Full Name</label>
                <input name="full_name" required>
            </div>
            <div class="form-group">
                <label>Username</label>
                <input name="username" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role_id" required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['role_id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelUserModal">Cancel</button>
                <button class="btn" type="submit">Create User</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('userModalOverlay');
    const openButton = document.getElementById('openUserModal');
    const closeButton = document.getElementById('closeUserModal');
    const cancelButton = document.getElementById('cancelUserModal');
    const drawerOverlay = document.getElementById('userDrawerOverlay');
    const drawerButtons = document.querySelectorAll('.open-user-drawer');
    const drawerCloseButton = document.getElementById('closeUserDrawer');
    const drawerCancelButton = document.getElementById('cancelUserDrawer');
    const drawerMeta = document.getElementById('userDrawerMeta');
    const drawerUserId = document.getElementById('drawerUserId');
    const drawerFullName = document.getElementById('drawerFullName');
    const drawerUsername = document.getElementById('drawerUsername');
    const drawerPassword = document.getElementById('drawerPassword');
    const drawerRole = document.getElementById('drawerRole');
    const drawerStatus = document.getElementById('drawerStatus');
    const deleteUserId = document.getElementById('deleteUserId');
    const deleteForm = document.getElementById('drawerDeleteForm');
    const deleteButton = document.getElementById('deleteUserButton');
    let selectedUsername = '';

    function openModal() {
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
    }

    function openDrawer(button) {
        const isSelf = button.dataset.isSelf === '1';
        selectedUsername = button.dataset.username || '';

        drawerUserId.value = button.dataset.userId || '';
        deleteUserId.value = button.dataset.userId || '';
        drawerFullName.value = button.dataset.fullName || '';
        drawerUsername.value = selectedUsername;
        drawerPassword.value = '';
        drawerRole.value = button.dataset.roleId || '';
        drawerRole.disabled = isSelf;
        drawerRole.title = isSelf ? 'You cannot change your own role.' : '';
        drawerStatus.value = button.dataset.status === 'disabled' ? 'disabled' : 'active';
        drawerStatus.disabled = isSelf;
        drawerMeta.textContent = selectedUsername ? '@' + selectedUsername : '';
        deleteButton.disabled = isSelf;
        deleteButton.title = isSelf ? 'You cannot delete your own account.' : '';

        drawerOverlay.classList.add('open');
        drawerOverlay.setAttribute('aria-hidden', 'false');
        drawerFullName.focus();
    }

    function closeDrawer() {
        drawerOverlay.classList.remove('open');
        drawerOverlay.setAttribute('aria-hidden', 'true');
    }

    if (openButton) {
        openButton.addEventListener('click', openModal);
    }

    if (closeButton) {
        closeButton.addEventListener('click', closeModal);
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeModal);
    }

    if (overlay) {
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                closeModal();
            }
        });
    }

    drawerButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            openDrawer(button);
        });
    });

    if (drawerCloseButton) {
        drawerCloseButton.addEventListener('click', closeDrawer);
    }

    if (drawerCancelButton) {
        drawerCancelButton.addEventListener('click', closeDrawer);
    }

    if (drawerOverlay) {
        drawerOverlay.addEventListener('click', function (event) {
            if (event.target === drawerOverlay) {
                closeDrawer();
            }
        });
    }

    if (deleteForm) {
        deleteForm.addEventListener('submit', async function (event) {
            if (deleteForm.dataset.confirmed === '1') return;
            event.preventDefault();
            if (deleteButton.disabled) return;
            const approved = await RetailMindUI.confirm({title:'Delete user permanently',message:'Delete @' + selectedUsername + '? This cannot be undone.',confirmText:'Delete user',danger:true});
            if (approved) { deleteForm.dataset.confirmed = '1'; deleteForm.submit(); }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && overlay && overlay.classList.contains('open')) {
            closeModal();
        }
        if (event.key === 'Escape' && drawerOverlay && drawerOverlay.classList.contains('open')) {
            closeDrawer();
        }
    });
});
</script>
</body>
</html>
