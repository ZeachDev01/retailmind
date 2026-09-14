<?php
// components/modals/manage_users.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_role(['admin']);

$message = '';
$messageClass = '';
$createFormValues = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'role_id' => '',
    'branch_id' => '',
];
$createFormSubmitted = false;

function get_user_snapshot(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT user_id, username, full_name, email, status, role_id, branch_id FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function role_requires_branch(PDO $pdo, int $roleId): bool {
    $stmt = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ?");
    $stmt->execute([$roleId]);
    return in_array((string)$stmt->fetchColumn(), ['inventory_manager', 'cashier'], true);
}

function valid_branch_assignment(PDO $pdo, ?int $branchId): bool {
    if ($branchId === null) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE branch_id = ? AND status = 'active'");
    $stmt->execute([$branchId]);
    return (int)$stmt->fetchColumn() > 0;
}

function role_exists(PDO $pdo, int $roleId): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE role_id = ? AND role_name <> 'seller'");
    $stmt->execute([$roleId]);

    return (int)$stmt->fetchColumn() > 0;
}

function ensure_single_super_admin(PDO $pdo, int $roleId, ?int $excludeUserId = null): void {
    $roleStmt = $pdo->prepare('SELECT role_name FROM roles WHERE role_id = ?');
    $roleStmt->execute([$roleId]);
    if ($roleStmt->fetchColumn() !== 'super_admin') {
        return;
    }

    $sql = "SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'super_admin'";
    $params = [];
    if ($excludeUserId !== null) {
        $sql .= ' AND u.user_id <> ?';
        $params[] = $excludeUserId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new InvalidArgumentException('Only one Super Admin account is allowed.');
    }
}

function user_initials(string $name, string $fallback): string {
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }

    return substr($initials ?: strtoupper(substr($fallback, 0, 2)) ?: 'RM', 0, 2);
}

function user_last_active_label(?string $lastLogin): string {
    if (!$lastLogin) {
        return 'Active: Never';
    }

    $timestamp = strtotime($lastLogin);
    if (!$timestamp) {
        return 'Active: Unknown';
    }

    $diff = max(0, time() - $timestamp);
    if ($diff < 60) {
        return 'Active: Just now';
    }
    if ($diff < 3600) {
        return 'Active: ' . floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return 'Active: ' . floor($diff / 3600) . 'h ago';
    }
    if ($diff < 172800) {
        return 'Active: Yesterday';
    }

    return 'Active: ' . date('M d, Y', $timestamp);
}

// Handle create user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    csrf_verify();
    $createFormSubmitted = true;
    $createFormValues = [
        'full_name' => trim((string)($_POST['full_name'] ?? '')),
        'username' => trim((string)($_POST['username'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'role_id' => (string)($_POST['role_id'] ?? ''),
        'branch_id' => (string)($_POST['branch_id'] ?? ''),
    ];
    $passwordError = password_policy_error((string)($_POST['password'] ?? ''));
    if ($passwordError) {
        $message = $passwordError;
        $messageClass = 'tag-warning';
    } else try {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $branchId = (int)($_POST['branch_id'] ?? 0) ?: null;
        if (!role_exists($pdo, $roleId) || !valid_branch_assignment($pdo, $branchId)) {
            throw new InvalidArgumentException('Please choose a valid active branch and role.');
        }
        $roleNameStmt = $pdo->prepare('SELECT role_name FROM roles WHERE role_id = ?');
        $roleNameStmt->execute([$roleId]);
        if ($roleNameStmt->fetchColumn() === 'super_admin') {
            throw new InvalidArgumentException('The Super Admin account is managed separately and cannot be created here.');
        }
        ensure_single_super_admin($pdo, $roleId);
        if (role_requires_branch($pdo, $roleId) && $branchId === null) {
            throw new InvalidArgumentException('Inventory Managers and Cashiers must be assigned to a branch.');
        }
        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, username, email, password_hash, role_id, branch_id, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            $_POST['full_name'],
            $_POST['username'],
            $_POST['email'],
            password_hash($_POST['password'], PASSWORD_DEFAULT),
            $roleId,
            $branchId,
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
        $createFormValues = ['full_name' => '', 'username' => '', 'email' => '', 'role_id' => '', 'branch_id' => ''];
    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
        $messageClass = 'tag-warning';
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
    $requestedBranchId = (int)($_POST['branch_id'] ?? 0) ?: null;

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
            } elseif (!valid_branch_assignment($pdo, $requestedBranchId)) {
                $message = 'Please choose an active branch.';
                $messageClass = 'tag-warning';
            } elseif (role_requires_branch($pdo, $roleId) && $requestedBranchId === null) {
                $message = 'Inventory Managers and Cashiers must be assigned to a branch.';
                $messageClass = 'tag-warning';
            } else {
                try {
                    ensure_single_super_admin($pdo, $roleId, $userId);
                    $updateParts = ['full_name = ?', 'username = ?', 'status = ?', 'role_id = ?', 'branch_id = ?'];
                    $params = [$fullName, $username, $status, $roleId, $requestedBranchId];
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
                    if ($roleId !== (int)$before['role_id'] || $requestedBranchId !== ($before['branch_id'] !== null ? (int)$before['branch_id'] : null)) {
                        $shouldInvalidateSessions = true;
                    }
                    if ($shouldInvalidateSessions) {
                        $updateParts[] = 'session_version = session_version + 1';
                    }

                    $params[] = $userId;
                    $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $updateParts) . ' WHERE user_id = ?');
                    $stmt->execute($params);

                    $pdo->prepare('DELETE FROM user_privileges WHERE user_id = ?')->execute([$userId]);
                    foreach (array_unique(array_map('intval', (array)($_POST['privilege_ids'] ?? []))) as $privilegeId) {
                        if ($privilegeId > 0) {
                            $pdo->prepare('INSERT INTO user_privileges (user_id, privilege_id, allowed) VALUES (?, ?, 1)')->execute([$userId, $privilegeId]);
                        }
                    }

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_branch') {
    csrf_verify();
    $branchName = trim((string)($_POST['branch_name'] ?? ''));
    $branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
    if ($branchName === '' || $branchCode === '') {
        $message = 'Branch name and branch code are required.';
        $messageClass = 'tag-warning';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO branches (branch_name, branch_code, created_by) VALUES (?, ?, ?)');
            $stmt->execute([$branchName, $branchCode, (int)$_SESSION['user_id']]);
            $message = 'Branch created successfully.';
            $messageClass = 'tag-success';
        } catch (PDOException $e) {
            $message = 'Unable to create branch. The name or code may already exist.';
            $messageClass = 'tag-warning';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_branch') {
    csrf_verify();
    $branchId = (int)($_POST['branch_id'] ?? 0);
    if ($branchId > 0) {
        $stmt = $pdo->prepare("UPDATE branches SET status = IF(status = 'active', 'inactive', 'active') WHERE branch_id = ?");
        $stmt->execute([$branchId]);
        $message = 'Branch status updated.';
        $messageClass = 'tag-success';
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
    header('Location: manage_users.php' . (isset($_GET['embed']) ? '?embed=1' : ''));
    exit;
}

$roles = $pdo->query("SELECT * FROM roles")->fetchAll();
$branches = $pdo->query("SELECT branch_id, branch_name, branch_code, status FROM branches ORDER BY branch_name")->fetchAll();
$privileges = $pdo->query("SELECT privilege_id, privilege_name FROM privileges ORDER BY privilege_name")->fetchAll();
$users = $pdo->query(
    "SELECT u.*, r.role_name, b.branch_name, b.branch_code,
            GROUP_CONCAT(CASE WHEN up.allowed = 1 THEN up.privilege_id END) AS assigned_privileges
     FROM users u
     JOIN roles r ON u.role_id = r.role_id
     LEFT JOIN branches b ON b.branch_id = u.branch_id
     LEFT JOIN user_privileges up ON up.user_id = u.user_id
     GROUP BY u.user_id
     ORDER BY u.created_at DESC"
)->fetchAll();
$activeCount = count(array_filter($users, fn($user) => $user['status'] === 'active'));
$disabledCount = count($users) - $activeCount;
$isEmbedded = ($_GET['embed'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body class="manage-users-page<?= $isEmbedded ? ' manage-users-embedded' : '' ?>">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="<?= $isEmbedded ? 'manage-users-heading-copy' : '' ?>">
                <?php if ($isEmbedded): ?>
                    <span class="manage-users-title-icon" aria-hidden="true"><i class="bi bi-person"></i></span>
                <?php endif; ?>
                <h1><?= $isEmbedded ? 'Users &amp; Access Management' : 'Manage Users' ?></h1>
                <p class="page-subtitle"><?= $isEmbedded ? 'Manage retail staff credentials, access levels, and active sessions.' : 'Create accounts, manage access, and keep team permissions organized.' ?></p>
            </div>
            <?php if ($isEmbedded): ?>
                <button type="button" class="manage-users-close" data-embedded-close aria-label="Close user management"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            <?php else: ?>
                <button type="button" class="btn" id="openUserModal"><i class="bi bi-person-plus" aria-hidden="true"></i> Add Staff User</button>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($isEmbedded): ?>
            <div class="manage-users-toolbar">
                <label class="manage-users-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="userSearch" placeholder="Search user by name, email, or role..." aria-label="Search users"></label>
                <button type="button" class="btn manage-users-add" id="openUserModal"><i class="bi bi-person-plus" aria-hidden="true"></i> Add Staff User</button>
            </div>
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
                    <tr><th>Name</th><th>Email</th><th>Role</th><th>Branch</th><th>Action</th></tr>
                    <?php foreach ($users as $u): ?>
                    <?php
                        $displayEmail = (string)($u['email'] ?: $u['username']);
                    ?>
                    <tr>
                        <td><span class="user-avatar" aria-hidden="true"><?= htmlspecialchars(user_initials((string)$u['full_name'], (string)$u['username'])) ?></span><span class="user-identity"><strong><?= htmlspecialchars($u['full_name']) ?></strong><span class="user-email mobile-user-email"><?= htmlspecialchars($displayEmail) ?> &bull; <?= htmlspecialchars($u['role_name']) ?></span></span></td>
                        <td class="user-email-cell"><?= htmlspecialchars($displayEmail) ?></td>
                        <td class="user-role-cell"><?= htmlspecialchars($u['role_name']) ?></td>
                        <td><?= htmlspecialchars($u['branch_name'] ?? 'All branches') ?></td>
                        <td class="action-cell">
                            <button
                                type="button"
                                class="btn btn-small open-user-drawer"
                                data-user-id="<?= (int)$u['user_id'] ?>"
                                data-full-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>"
                                data-status="<?= htmlspecialchars($u['status'], ENT_QUOTES, 'UTF-8') ?>"
                                data-role-id="<?= (int)$u['role_id'] ?>"
                                data-branch-id="<?= $u['branch_id'] !== null ? (int)$u['branch_id'] : '' ?>"
                                data-privilege-ids="<?= htmlspecialchars((string)($u['assigned_privileges'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
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

<?php if ($isEmbedded): ?>
<footer class="manage-users-footer"><span><?= $activeCount ?> Active System Users</span><button type="button" class="btn btn-secondary" data-embedded-close>Done</button></footer>
<?php endif; ?>

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
                            <?php if (!in_array($r['role_name'], ['super_admin', 'seller'], true)): ?>
                                <option value="<?= (int)$r['role_id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="drawerBranch">Assigned Branch</label>
                    <select id="drawerBranch" name="branch_id">
                        <option value="">No branch (administrators only)</option>
                        <?php foreach ($branches as $branch): ?>
                            <?php if ($branch['status'] === 'active'): ?><option value="<?= (int)$branch['branch_id'] ?>"><?= htmlspecialchars($branch['branch_name'] . ' (' . $branch['branch_code'] . ')') ?></option><?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Additional Privileges</label>
                    <div class="privilege-list">
                        <?php foreach ($privileges as $privilege): ?><label><input type="checkbox" name="privilege_ids[]" value="<?= (int)$privilege['privilege_id'] ?>" data-privilege-id="<?= (int)$privilege['privilege_id'] ?>"> <?= htmlspecialchars($privilege['privilege_name']) ?></label><?php endforeach; ?>
                    </div>
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

            <div class="modal-actions drawer-modal-actions">
                <form method="POST" id="drawerDeleteForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <button type="submit" class="btn btn-danger" id="deleteUserButton">Delete User</button>
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
        <form method="POST" class="user-form" id="createUserForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>Full Name</label>
                <input name="full_name" value="<?= htmlspecialchars($createFormValues['full_name'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-group">
                <label>Username</label>
                <input name="username" value="<?= htmlspecialchars($createFormValues['username'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($createFormValues['email'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" id="createUserPassword" required>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role_id" required>
                    <?php foreach ($roles as $r): ?>
                        <?php if (!in_array($r['role_name'], ['super_admin', 'seller'], true)): ?>
                            <option value="<?= $r['role_id'] ?>"<?= $createFormValues['role_id'] === (string)$r['role_id'] ? ' selected' : '' ?>><?= htmlspecialchars($r['role_name']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Assigned Branch</label>
                <select name="branch_id">
                    <option value=""<?= $createFormValues['branch_id'] === '' ? ' selected' : '' ?>>No branch (administrators only)</option>
                    <?php foreach ($branches as $branch): ?>
                        <?php if ($branch['status'] === 'active'): ?><option value="<?= (int)$branch['branch_id'] ?>"<?= $createFormValues['branch_id'] === (string)$branch['branch_id'] ? ' selected' : '' ?>><?= htmlspecialchars($branch['branch_name'] . ' (' . $branch['branch_code'] . ')') ?></option><?php endif; ?>
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

<div class="dashboard-section" style="margin:1.5rem 0;">
    <div class="section-header"><div><h3>Branches</h3><p class="section-description">Create branches and deactivate them when they are no longer operational.</p></div></div>
    <form method="POST" class="user-form" style="display:flex;gap:1rem;align-items:end;flex-wrap:wrap;">
        <?= csrf_field() ?><input type="hidden" name="action" value="create_branch">
        <div class="form-group"><label>Branch Name</label><input name="branch_name" required></div>
        <div class="form-group"><label>Branch Code</label><input name="branch_code" maxlength="30" required></div>
        <button class="btn" type="submit">Create Branch</button>
    </form>
    <div class="table-wrap"><table class="users-table"><tr><th>Branch</th><th>Code</th><th>Status</th><th>Action</th></tr>
        <?php foreach ($branches as $branch): ?><tr><td><?= htmlspecialchars($branch['branch_name']) ?></td><td><?= htmlspecialchars($branch['branch_code']) ?></td><td><?= htmlspecialchars($branch['status']) ?></td><td><form method="POST"><input type="hidden" name="action" value="toggle_branch"><?= csrf_field() ?><input type="hidden" name="branch_id" value="<?= (int)$branch['branch_id'] ?>"><button class="btn btn-small" type="submit"><?= $branch['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; ?>
    </table></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('userModalOverlay');
    const openButton = document.getElementById('openUserModal');
    const closeButton = document.getElementById('closeUserModal');
    const cancelButton = document.getElementById('cancelUserModal');
    const createForm = document.getElementById('createUserForm');
    const createPassword = document.getElementById('createUserPassword');
    const createPasswordStateKey = 'retailmind.manage-users.create-password';
    const restoreCreateForm = <?= $createFormSubmitted && $messageClass === 'tag-warning' ? 'true' : 'false' ?>;
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
    const drawerBranch = document.getElementById('drawerBranch');
    const privilegeInputs = document.querySelectorAll('[data-privilege-id]');
    const drawerStatus = document.getElementById('drawerStatus');
    const deleteUserId = document.getElementById('deleteUserId');
    const deleteForm = document.getElementById('drawerDeleteForm');
    const deleteButton = document.getElementById('deleteUserButton');
    let selectedUsername = '';
    let createFormState = null;

    function saveCreateFormState() {
        if (!createForm) return;
        createFormState = new FormData(createForm);
    }

    function restoreCreateFormState() {
        if (!createForm || !createFormState) return;
        createForm.querySelectorAll('input:not([type="hidden"]), select').forEach(function (field) {
            if (field.name && createFormState.has(field.name)) {
                field.value = createFormState.get(field.name);
            }
        });
    }

    if (createForm) {
        createFormState = new FormData(createForm);
        if (restoreCreateForm && createPassword && window.sessionStorage) {
            try {
                createPassword.value = window.sessionStorage.getItem(createPasswordStateKey) || '';
                window.sessionStorage.removeItem(createPasswordStateKey);
            } catch (error) {}
        } else if (window.sessionStorage) {
            try {
                window.sessionStorage.removeItem(createPasswordStateKey);
            } catch (error) {}
        }
        createFormState = new FormData(createForm);
    }

    function openModal() {
        restoreCreateFormState();
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        saveCreateFormState();
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
        drawerBranch.value = button.dataset.branchId || '';
        drawerBranch.disabled = isSelf;
        const assignedPrivileges = (button.dataset.privilegeIds || '').split(',').filter(Boolean);
        privilegeInputs.forEach(function (input) {
            input.checked = assignedPrivileges.includes(input.dataset.privilegeId);
            input.disabled = isSelf;
        });
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

    document.querySelectorAll('[data-embedded-close]').forEach(function (button) {
        button.addEventListener('click', function () { window.parent.postMessage({ type: 'close-user-management' }, '*'); });
    });

    const userSearch = document.getElementById('userSearch');
    if (userSearch) {
        userSearch.addEventListener('input', function () {
            const query = userSearch.value.toLowerCase().trim();
            document.querySelectorAll('.users-table tbody tr, .users-table > tr').forEach(function (row) {
                row.hidden = !!query && !row.textContent.toLowerCase().includes(query);
            });
        });
    }

    if (closeButton) {
        closeButton.addEventListener('click', closeModal);
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeModal);
    }

    if (createForm) {
        createForm.addEventListener('submit', function () {
            saveCreateFormState();
            if (createPassword && window.sessionStorage) {
                try {
                    window.sessionStorage.setItem(createPasswordStateKey, createPassword.value);
                } catch (error) {}
            }
        });
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

    if (restoreCreateForm) {
        openModal();
    }
});
</script>
</body>
</html>
