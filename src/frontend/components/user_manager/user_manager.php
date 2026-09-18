<?php
// components/user_manager/user_manager.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::MANAGE_USERS);

$message = '';
$messageClass = '';
$createFormSubmitted = false;
$createFormValues = ['first_name' => '', 'last_name' => '', 'full_name' => '', 'username' => '', 'email' => '', 'role_id' => '', 'branch_id' => ''];
$branchFormSubmitted = false;
$branchFormValues = ['branch_name' => '', 'branch_code' => ''];

function get_user_snapshot(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.username, u.full_name, u.email, u.profile_image, u.status, u.role_id, u.branch_id, r.role_name
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = ?"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function user_is_super_admin(array $user): bool
{
    return ($user['role_name'] ?? '') === 'super_admin';
}

function can_manage_user(array $user): bool
{
    return has_capability(
        \App\Authorization\RoleCapabilityPolicy::MANAGE_USERS,
        (string)($user['role_name'] ?? '')
    );
}

function role_requires_branch(PDO $pdo, int $roleId): bool
{
    $stmt = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ?");
    $stmt->execute([$roleId]);
    return in_array((string)$stmt->fetchColumn(), ['admin', 'inventory_manager', 'cashier'], true);
}

function valid_branch_assignment(PDO $pdo, ?int $branchId): bool
{
    if ($branchId === null) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE branch_id = ? AND status = 'active'");
    $stmt->execute([$branchId]);
    return (int)$stmt->fetchColumn() > 0;
}

function role_name_for_id(PDO $pdo, int $roleId): ?string
{
    $stmt = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ? AND role_name <> 'seller'");
    $stmt->execute([$roleId]);
    $roleName = $stmt->fetchColumn();
    return $roleName !== false ? (string)$roleName : null;
}

function role_exists(PDO $pdo, int $roleId): bool
{
    return role_name_for_id($pdo, $roleId) !== null;
}

function ensure_single_super_admin(PDO $pdo, int $roleId, ?int $excludeUserId = null): void
{
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
        throw new InvalidArgumentException('Only one Super Administrator account is allowed.');
    }
}

function display_label(string $value): string
{
    return ucwords(str_replace(['_', '-'], ' ', strtolower(trim($value))));
}

function display_person_name(string $value): string
{
    $trimmedValue = trim($value);
    return $trimmedValue === strtoupper($trimmedValue) ? ucwords(strtolower($trimmedValue)) : $trimmedValue;
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
    return 'Active: ' . format_display_date(date('Y-m-d', $timestamp));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    csrf_verify();
    $createFormSubmitted = true;
    $createFirstName = trim((string)($_POST['first_name'] ?? ''));
    $createLastName = trim((string)($_POST['last_name'] ?? ''));
    $createFullName = trim($createFirstName . ' ' . $createLastName);
    $createFormValues = [
        'first_name' => $createFirstName,
        'last_name' => $createLastName,
        'full_name' => $createFullName,
        'username' => trim((string)($_POST['username'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'role_id' => (string)($_POST['role_id'] ?? ''),
        'branch_id' => (string)($_POST['branch_id'] ?? ''),
    ];
    $passwordError = password_policy_error((string)($_POST['password'] ?? ''));
    if ($passwordError) {
        $message = $passwordError;
        $messageClass = 'tag-warning';
    } else {
        $newProfileImage = null;
        $userCreated = false;
        try {
            $roleId = (int)($_POST['role_id'] ?? 0);
            $branchId = (int)($_POST['branch_id'] ?? 0) ?: null;
            if (!role_exists($pdo, $roleId) || !valid_branch_assignment($pdo, $branchId)) {
                throw new InvalidArgumentException('Please choose a valid active branch and role.');
            }
            $roleName = role_name_for_id($pdo, $roleId);
            if ($roleName === null || !has_capability(\App\Authorization\RoleCapabilityPolicy::ASSIGN_ROLES, $roleName)) {
                throw new InvalidArgumentException('Your account cannot assign the selected role.');
            }
            if ($roleName === 'super_admin') {
                throw new InvalidArgumentException('The Super Administrator account is managed separately and cannot be created here.');
            }
            ensure_single_super_admin($pdo, $roleId);
            if (role_requires_branch($pdo, $roleId) && $branchId === null) {
                throw new InvalidArgumentException('Administrators, Inventory Managers, and Cashiers must be assigned to a branch.');
            }

            $newProfileImage = profile_image_storage()->store($_FILES['profile_image'] ?? null);
            $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, profile_image, password_hash, role_id, branch_id, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$createFullName, $_POST['username'], $_POST['email'], $newProfileImage, password_hash($_POST['password'], PASSWORD_DEFAULT), $roleId, $branchId]);
            $userCreated = true;
            $newUserId = (int)$pdo->lastInsertId();
            log_activity($pdo, (int)$_SESSION['user_id'], 'User creation', 'Users', $newUserId, null, [
                'user_id' => $newUserId,
                'full_name' => $createFullName,
                'username' => $_POST['username'] ?? '',
                'email' => $_POST['email'] ?? '',
                'has_profile_image' => $newProfileImage !== null,
                'role_id' => $roleId,
                'status' => 'active',
            ]);
            $message = 'User created successfully.';
            $messageClass = 'tag-success';
            $createFormValues = ['first_name' => '', 'last_name' => '', 'full_name' => '', 'username' => '', 'email' => '', 'role_id' => '', 'branch_id' => ''];
        } catch (InvalidArgumentException $e) {
            if (!$userCreated && $newProfileImage !== null) {
                profile_image_storage()->delete($newProfileImage);
            }
            $message = $e->getMessage();
            $messageClass = 'tag-warning';
        } catch (PDOException $e) {
            if (!$userCreated && $newProfileImage !== null) {
                profile_image_storage()->delete($newProfileImage);
            }
            $message = 'Unable to create user. Username or email may already exist.';
            $messageClass = 'tag-warning';
        } catch (RuntimeException $e) {
            if (!$userCreated && $newProfileImage !== null) {
                profile_image_storage()->delete($newProfileImage);
            }
            $message = $e->getMessage();
            $messageClass = 'tag-warning';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    csrf_verify();
    $userId = (int)($_POST['user_id'] ?? 0);
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $fullName = trim($firstName . ' ' . $lastName);
    if ($fullName === '') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
    }
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
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
        } elseif (!can_manage_user($before)) {
            $message = 'The Super Administrator account is protected and cannot be managed by another administrator.';
            $messageClass = 'tag-warning';
        } else {
            $isProtectedSuperAdmin = user_is_super_admin($before);
            $status = $isProtectedSuperAdmin
                ? $before['status']
                : (in_array($requestedStatus, ['active', 'disabled'], true) ? $requestedStatus : $before['status']);
            $roleId = $isProtectedSuperAdmin
                ? (int)$before['role_id']
                : ($requestedRoleId > 0 ? $requestedRoleId : (int)$before['role_id']);
            if ($isProtectedSuperAdmin) {
                $requestedBranchId = $before['branch_id'] !== null ? (int)$before['branch_id'] : null;
            }
            if ($userId === (int)$_SESSION['user_id'] && $status === 'disabled') {
                $message = 'You cannot disable your own account while logged in.';
                $messageClass = 'tag-warning';
            } elseif ($userId === (int)$_SESSION['user_id'] && $roleId !== (int)$before['role_id']) {
                $message = 'You cannot change your own role while logged in.';
                $messageClass = 'tag-warning';
            } elseif (!role_exists($pdo, $roleId)) {
                $message = 'Please choose a valid role.';
                $messageClass = 'tag-warning';
            } elseif (!has_capability(\App\Authorization\RoleCapabilityPolicy::ASSIGN_ROLES, role_name_for_id($pdo, $roleId))) {
                $message = 'Your account cannot assign the selected role.';
                $messageClass = 'tag-warning';
            } elseif (!valid_branch_assignment($pdo, $requestedBranchId)) {
                $message = 'Please choose an active branch.';
                $messageClass = 'tag-warning';
            } elseif (role_requires_branch($pdo, $roleId) && $requestedBranchId === null) {
                $message = 'Administrators, Inventory Managers, and Cashiers must be assigned to a branch.';
                $messageClass = 'tag-warning';
            } else {
                $newProfileImage = null;
                $profileImageCommitted = false;
                try {
                    ensure_single_super_admin($pdo, $roleId, $userId);
                    $updateParts = ['full_name = ?', 'username = ?', 'email = ?', 'status = ?', 'role_id = ?', 'branch_id = ?'];
                    $params = [$fullName, $username, $email, $status, $roleId, $requestedBranchId];
                    $shouldInvalidateSessions = false;

                    if (\App\Services\ProfileImageStorage::hasUpload($_FILES['profile_image'] ?? null)) {
                        $newProfileImage = profile_image_storage()->store($_FILES['profile_image']);
                        $updateParts[] = 'profile_image = ?';
                        $params[] = $newProfileImage;
                    } elseif (isset($_POST['remove_profile_image'])) {
                        $updateParts[] = 'profile_image = NULL';
                    }

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
                    $profileImageCommitted = true;

                    if (($newProfileImage !== null || isset($_POST['remove_profile_image'])) && !empty($before['profile_image'])) {
                        if (!profile_image_storage()->delete((string)$before['profile_image'])) {
                            error_log('Unable to remove a replaced staff profile image.');
                        }
                    }

                    if (!$isProtectedSuperAdmin
                        && isset($_POST['manage_privileges'])
                        && has_capability(\App\Authorization\RoleCapabilityPolicy::ASSIGN_PRIVILEGES)
                    ) {
                        $pdo->prepare('DELETE FROM user_privileges WHERE user_id = ?')->execute([$userId]);
                        foreach (array_unique(array_map('intval', (array)($_POST['privilege_ids'] ?? []))) as $privilegeId) {
                            if ($privilegeId > 0) {
                                $pdo->prepare('INSERT INTO user_privileges (user_id, privilege_id, allowed) VALUES (?, ?, 1)')->execute([$userId, $privilegeId]);
                            }
                        }
                    }

                    $after = get_user_snapshot($pdo, $userId);
                    log_activity($pdo, (int)$_SESSION['user_id'], $newPassword !== '' ? 'User account update with password change' : 'User account update', 'Users', $userId, $before, $after);
                    if ($userId === (int)$_SESSION['user_id']) {
                        $_SESSION['full_name'] = $fullName;
                        $_SESSION['profile_image'] = $after['profile_image'] ?? null;
                    }
                    $message = 'User account updated successfully.';
                    $messageClass = 'tag-success';
                } catch (InvalidArgumentException $e) {
                    if (!$profileImageCommitted && $newProfileImage !== null) {
                        profile_image_storage()->delete($newProfileImage);
                    }
                    $message = $e->getMessage();
                    $messageClass = 'tag-warning';
                } catch (PDOException $e) {
                    if (!$profileImageCommitted && $newProfileImage !== null) {
                        profile_image_storage()->delete($newProfileImage);
                    }
                    $message = 'Unable to update user. Username or email may already exist.';
                    $messageClass = 'tag-warning';
                } catch (RuntimeException $e) {
                    if (!$profileImageCommitted && $newProfileImage !== null) {
                        profile_image_storage()->delete($newProfileImage);
                    }
                    $message = $e->getMessage();
                    $messageClass = 'tag-warning';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    $userId = (int)($_POST['user_id'] ?? 0);
    $before = get_user_snapshot($pdo, $userId);

    if (!$before) {
        $message = 'User not found.';
        $messageClass = 'tag-warning';
    } elseif (user_is_super_admin($before)) {
        $message = 'The Super Administrator account is protected and cannot be deleted.';
        $messageClass = 'tag-warning';
    } elseif (!can_manage_user($before)) {
        $message = 'Your account cannot delete this privileged user.';
        $messageClass = 'tag-warning';
    } elseif ($userId === (int)$_SESSION['user_id']) {
        $message = 'You cannot delete your own account while logged in.';
        $messageClass = 'tag-warning';
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            if ($stmt->rowCount() > 0) {
                if (!empty($before['profile_image']) && !profile_image_storage()->delete((string)$before['profile_image'])) {
                    error_log('Unable to remove a deleted staff profile image.');
                }
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
    require_capability(\App\Authorization\RoleCapabilityPolicy::STORE_OPERATIONS);
    $branchFormSubmitted = true;
    $branchName = trim((string)($_POST['branch_name'] ?? ''));
    $branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
    $branchFormValues = ['branch_name' => $branchName, 'branch_code' => $branchCode];
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
    require_capability(\App\Authorization\RoleCapabilityPolicy::STORE_OPERATIONS);
    $branchId = (int)($_POST['branch_id'] ?? 0);
    if ($branchId > 0) {
        $stmt = $pdo->prepare("UPDATE branches SET status = IF(status = 'active', 'inactive', 'active') WHERE branch_id = ?");
        $stmt->execute([$branchId]);
        $message = 'Branch status updated.';
        $messageClass = 'tag-success';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    csrf_verify();
    $toggleId = (int)($_POST['user_id'] ?? 0);
    $before = get_user_snapshot($pdo, $toggleId);

    if ($before && user_is_super_admin($before)) {
        http_response_code(403);
        $message = 'The Super Administrator account is protected and its status cannot be changed.';
        $messageClass = 'tag-warning';
    } elseif ($before && !can_manage_user($before)) {
        http_response_code(403);
        $message = 'Your account cannot change this privileged user.';
        $messageClass = 'tag-warning';
    } else {
        if ($before) {
            $stmt = $pdo->prepare("UPDATE users SET status = IF(status='active','disabled','active'), session_version = session_version + 1 WHERE user_id = ?");
            $stmt->execute([$toggleId]);
            $after = get_user_snapshot($pdo, $toggleId);
            $action = ($after['status'] ?? '') === 'disabled' ? 'User deactivation' : 'User activation';
            log_activity($pdo, (int)$_SESSION['user_id'], $action, 'Users', $toggleId, $before, $after);
        }

        header('Location: user_manager.php' . (isset($_GET['embed']) ? '?embed=1' : ''));
        exit;
    }
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
$autoOpenDrawerUserId = 0;
if (!$isEmbedded && ($_GET['drawer'] ?? '') === 'manage') {
    $requestedDrawerUserId = (int)($_GET['user_id'] ?? 0);
    foreach ($users as $user) {
        if ((int)$user['user_id'] === $requestedDrawerUserId && can_manage_user($user)) {
            $autoOpenDrawerUserId = $requestedDrawerUserId;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Manager</title>
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
                    <h1><?= $isEmbedded ? 'Users &amp; Access Management' : 'User Manager' ?></h1>
                    <p class="page-subtitle"><?= $isEmbedded ? 'Manage retail staff credentials, access levels, and active sessions.' : 'Create accounts, manage access, and keep team permissions organized.' ?></p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="card-grid">
                <div class="stat-card with-icon">
                    <span class="stat-icon" aria-hidden="true"><i class="bi bi-people-fill"></i></span>
                    <div class="value"><?= count($users) ?></div>
                    <div class="label">Total Users</div>
                </div>
                <div class="stat-card with-icon success">
                    <span class="stat-icon" aria-hidden="true"><i class="bi bi-person-check-fill"></i></span>
                    <div class="value"><?= $activeCount ?></div>
                    <div class="label">Active Users</div>
                </div>
                <div class="stat-card with-icon <?= $disabledCount > 0 ? 'warning' : 'success' ?>">
                    <span class="stat-icon" aria-hidden="true"><i class="bi bi-person-x-fill"></i></span>
                    <div class="value"><?= $disabledCount ?></div>
                    <div class="label">Disabled Users</div>
                </div>
            </div>

            <div class="manage-users-tabs" role="tablist" aria-label="User management sections">
                <button type="button" class="manage-users-tab is-active" id="usersTab" role="tab" aria-selected="true" aria-controls="usersPanel" data-management-tab="users" tabindex="0">
                    <span class="manage-users-tab-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
                    <span class="manage-users-tab-copy"><span class="manage-users-tab-label">Users</span><span class="manage-users-tab-description">Accounts &amp; access</span></span>
                    <span class="manage-users-tab-count" aria-label="<?= count($users) ?> users"><?= count($users) ?></span>
                </button>
                <button type="button" class="manage-users-tab" id="branchesTab" role="tab" aria-selected="false" aria-controls="branchesPanel" data-management-tab="branches" tabindex="-1">
                    <span class="manage-users-tab-icon" aria-hidden="true"><i class="bi bi-shop"></i></span>
                    <span class="manage-users-tab-copy"><span class="manage-users-tab-label">Branches</span><span class="manage-users-tab-description">Store locations</span></span>
                    <span class="manage-users-tab-count" aria-label="<?= count($branches) ?> branches"><?= count($branches) ?></span>
                </button>
            </div>

            <div class="dashboard-section manage-users-list manage-users-tab-panel is-active" id="usersPanel" role="tabpanel" aria-labelledby="usersTab" data-management-panel="users">
                <?php if ($isEmbedded): ?>
                    <div class="manage-users-toolbar" role="search">
                        <label class="manage-users-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="userSearch" placeholder="Search user by name, email, or role..." aria-label="Search users"></label>
                        <button type="button" class="btn manage-users-primary manage-users-add" id="openUserModal"><i class="bi bi-person-plus" aria-hidden="true"></i> Add Staff User</button>
                    </div>
                <?php endif; ?>
                <div class="section-header">
                    <div>
                        <h3>All Users</h3>
                        <p class="section-description">Review account status and manage permissions quickly.</p>
                    </div>
                    <?php if (!$isEmbedded): ?>
                        <button type="button" class="btn manage-users-primary manage-users-section-action" id="openUserModal"><i class="bi bi-person-plus" aria-hidden="true"></i> Add Staff User</button>
                    <?php endif; ?>
                </div>

                <div class="table-wrap">
                    <table class="users-table">
                        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Branch</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <?php
                                $displayEmail = (string)($u['email'] ?: $u['username']);
                                $displayBranch = $u['branch_name'] ? display_person_name((string)$u['branch_name']) : 'All branches';
                                $createdLabel = !empty($u['created_at']) ? format_display_date(date('Y-m-d', strtotime((string)$u['created_at']))) : 'Unknown';
                                $lastActive = user_last_active_label($u['last_login'] ?? null);
                                $isSelf = (int)$u['user_id'] === (int)$_SESSION['user_id'];
                                $isProtectedSuperAdmin = user_is_super_admin($u);
                                $canManageThisUser = can_manage_user($u);
                                $hasProfileImage = !empty($u['profile_image']) && profile_image_storage()->exists((string)$u['profile_image']);
                                ?>
                                <tr>
                                    <td><div class="user-name-cell"><?= profile_avatar_html((int)$u['user_id'], (string)$u['full_name'], $u['profile_image'] ?? null, 'user-avatar') ?><strong><?= htmlspecialchars(display_person_name((string)$u['full_name'])) ?></strong></div></td>
                                    <td class="user-email-cell"><?= htmlspecialchars($displayEmail) ?></td>
                                    <td class="user-role-cell"><?= htmlspecialchars(display_label((string)$u['role_name'])) ?></td>
                                    <td><?= htmlspecialchars($displayBranch) ?></td>
                                    <td class="action-cell">
                                        <?php if (!$canManageThisUser): ?>
                                            <span class="user-protected-label" title="Only the Super Administrator can manage this account"><i class="bi bi-lock-fill" aria-hidden="true"></i> Protected</span>
                                        <?php elseif ($isEmbedded): ?>
                                            <a class="btn btn-small" target="_top" href="<?= htmlspecialchars(app_url('components/user_manager/user_manager.php?drawer=manage&user_id=' . (int)$u['user_id'])) ?>">Manage</a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-small open-user-drawer" data-user-id="<?= (int)$u['user_id'] ?>" data-full-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>" data-email="<?= htmlspecialchars((string)($u['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>" data-status="<?= htmlspecialchars($u['status'], ENT_QUOTES, 'UTF-8') ?>" data-role-id="<?= (int)$u['role_id'] ?>" data-branch-id="<?= $u['branch_id'] !== null ? (int)$u['branch_id'] : '' ?>" data-privilege-ids="<?= htmlspecialchars((string)($u['assigned_privileges'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-profile-initials="<?= htmlspecialchars(profile_initials((string)$u['full_name']), ENT_QUOTES, 'UTF-8') ?>" data-has-profile-image="<?= !empty($u['profile_image']) ? '1' : '0' ?>" data-profile-url="<?= $hasProfileImage ? htmlspecialchars(profile_image_url((int)$u['user_id']), ENT_QUOTES, 'UTF-8') : '' ?>" data-is-self="<?= $isSelf ? '1' : '0' ?>" data-is-super-admin="<?= $isProtectedSuperAdmin ? '1' : '0' ?>">Manage</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="dashboard-section manage-users-branches manage-users-tab-panel" id="branchesPanel" role="tabpanel" aria-labelledby="branchesTab" data-management-panel="branches" hidden>
                <div class="section-header">
                    <div><h3>Branches</h3><p class="section-description">Create branches and deactivate them when they are no longer operational.</p></div>
                    <button type="button" class="btn manage-users-primary manage-users-section-action" id="openBranchModal"><i class="bi bi-building-add" aria-hidden="true"></i> Create Branch</button>
                </div>
                <div class="table-wrap">
                    <table class="users-table">
                        <thead><tr><th>Branch</th><th>Code</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($branches as $branch): ?>
                                <tr>
                                    <td><?= htmlspecialchars(display_person_name((string)$branch['branch_name'])) ?></td>
                                    <td><?= htmlspecialchars($branch['branch_code']) ?></td>
                                    <td><?= htmlspecialchars(display_label((string)$branch['status'])) ?></td>
                                    <td><form method="POST" class="inline-action-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_branch"><input type="hidden" name="branch_id" value="<?= (int)$branch['branch_id'] ?>"><button class="btn btn-small" type="submit"><?= $branch['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isEmbedded): ?>
        <footer class="manage-users-footer"><span><?= $activeCount ?> Active System Users</span><button type="button" class="btn btn-secondary" data-embedded-close>Done</button></footer>
    <?php endif; ?>

    <?php
    include __DIR__ . '/modals/view_user_modal.php';
    include __DIR__ . '/modals/edit_user_modal.php';
    include __DIR__ . '/modals/manage_user_modal.php';
    include __DIR__ . '/modals/add_user_modal.php';
    include __DIR__ . '/modals/add_branch_modal.php';
    ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const addOverlay = document.getElementById('userModalOverlay');
            const editOverlay = document.getElementById('editUserModalOverlay');
            const viewOverlay = document.getElementById('viewUserModalOverlay');
            const drawerOverlay = document.getElementById('userDrawerOverlay');
            const branchOverlay = document.getElementById('branchModalOverlay');
            const createForm = document.getElementById('createUserForm');
            const createPassword = document.getElementById('createUserPassword');
            const toggleCreatePassword = document.getElementById('toggleCreatePassword');
            const drawerPassword = document.getElementById('drawerPassword');
            const toggleDrawerPassword = document.getElementById('toggleDrawerPassword');
            const createPasswordStateKey = 'retailmind.user-manager.create-password';
            const restoreCreateForm = <?= $createFormSubmitted && $messageClass === 'tag-warning' ? 'true' : 'false' ?>;
            const branchFormSubmitted = <?= $branchFormSubmitted ? 'true' : 'false' ?>;
            const restoreBranchForm = <?= $branchFormSubmitted && $messageClass === 'tag-warning' ? 'true' : 'false' ?>;
            const autoOpenDrawerUserId = <?= (int)$autoOpenDrawerUserId ?>;
            const managementTabs = document.querySelectorAll('[data-management-tab]');
            const managementPanels = document.querySelectorAll('[data-management-panel]');
            const drawerTabs = document.querySelectorAll('[data-user-drawer-tab]');
            const drawerPanels = document.querySelectorAll('[data-user-drawer-panel]');
            const drawerDangerPanels = document.querySelectorAll('[data-user-drawer-danger]');
            let createFormState = createForm ? new FormData(createForm) : null;
            let selectedUsername = '';

            function setModalOpen(overlay, isOpen) {
                if (!overlay) return;
                overlay.classList.toggle('open', isOpen);
                overlay.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            }

            function selectManagementTab(selectedTab) {
                const selectedTabElement = Array.from(managementTabs).find(function(tab) {
                    return tab.dataset.managementTab === selectedTab;
                });
                if (!selectedTabElement) return;

                managementTabs.forEach(function(tab) {
                    const isSelected = tab === selectedTabElement;
                    tab.classList.toggle('is-active', isSelected);
                    tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                    tab.setAttribute('tabindex', isSelected ? '0' : '-1');
                });
                managementPanels.forEach(function(panel) {
                    panel.hidden = panel.dataset.managementPanel !== selectedTab;
                });
            }

            managementTabs.forEach(function(tab) {
                tab.addEventListener('click', function() {
                    selectManagementTab(tab.dataset.managementTab);
                });
                tab.addEventListener('keydown', function(event) {
                    const tabIndex = Array.from(managementTabs).indexOf(tab);
                    let nextIndex = tabIndex;
                    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (tabIndex + 1) % managementTabs.length;
                    if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (tabIndex - 1 + managementTabs.length) % managementTabs.length;
                    if (event.key === 'Home') nextIndex = 0;
                    if (event.key === 'End') nextIndex = managementTabs.length - 1;
                    if (nextIndex !== tabIndex) {
                        event.preventDefault();
                        managementTabs[nextIndex].focus();
                        selectManagementTab(managementTabs[nextIndex].dataset.managementTab);
                    }
                });
            });

            function selectDrawerTab(selectedTab) {
                const selectedTabElement = Array.from(drawerTabs).find(function(tab) {
                    return tab.dataset.userDrawerTab === selectedTab;
                });
                if (!selectedTabElement) return;

                drawerTabs.forEach(function(tab) {
                    const isSelected = tab === selectedTabElement;
                    tab.classList.toggle('is-active', isSelected);
                    tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                    tab.setAttribute('tabindex', isSelected ? '0' : '-1');
                });
                drawerPanels.forEach(function(panel) {
                    panel.hidden = panel.dataset.userDrawerPanel !== selectedTab;
                    panel.classList.toggle('is-active', panel.dataset.userDrawerPanel === selectedTab);
                });
                drawerDangerPanels.forEach(function(panel) {
                    panel.hidden = panel.dataset.userDrawerDanger !== selectedTab;
                });
            }

            drawerTabs.forEach(function(tab) {
                tab.addEventListener('click', function() {
                    selectDrawerTab(tab.dataset.userDrawerTab);
                });
                tab.addEventListener('keydown', function(event) {
                    const tabIndex = Array.from(drawerTabs).indexOf(tab);
                    let nextIndex = tabIndex;
                    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (tabIndex + 1) % drawerTabs.length;
                    if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (tabIndex - 1 + drawerTabs.length) % drawerTabs.length;
                    if (event.key === 'Home') nextIndex = 0;
                    if (event.key === 'End') nextIndex = drawerTabs.length - 1;
                    if (nextIndex !== tabIndex) {
                        event.preventDefault();
                        drawerTabs[nextIndex].focus();
                        selectDrawerTab(drawerTabs[nextIndex].dataset.userDrawerTab);
                    }
                });
            });

            function saveCreateFormState() {
                if (createForm) createFormState = new FormData(createForm);
            }

            function restoreCreateFormState() {
                if (!createForm || !createFormState) return;
                createForm.querySelectorAll('input:not([type="hidden"]):not([type="file"]), select').forEach(function(field) {
                    if (field.name && createFormState.has(field.name)) {
                        field.value = createFormState.get(field.name);
                    }
                });
            }

            if (createForm) {
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
                createForm.addEventListener('submit', function() {
                    saveCreateFormState();
                    if (createPassword && window.sessionStorage) {
                        try {
                            window.sessionStorage.setItem(createPasswordStateKey, createPassword.value);
                        } catch (error) {}
                    }
                });
            }

            if (toggleCreatePassword && createPassword) {
                toggleCreatePassword.addEventListener('click', function() {
                    const isVisible = createPassword.type === 'text';
                    createPassword.type = isVisible ? 'password' : 'text';
                    toggleCreatePassword.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
                    toggleCreatePassword.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
                    const icon = toggleCreatePassword.querySelector('i');
                    if (icon) {
                        icon.className = 'bi ' + (isVisible ? 'bi-eye' : 'bi-eye-slash');
                    }
                });
            }

            if (toggleDrawerPassword && drawerPassword) {
                toggleDrawerPassword.addEventListener('click', function() {
                    const isVisible = drawerPassword.type === 'text';
                    drawerPassword.type = isVisible ? 'password' : 'text';
                    toggleDrawerPassword.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
                    toggleDrawerPassword.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
                    const icon = toggleDrawerPassword.querySelector('i');
                    if (icon) {
                        icon.className = 'bi ' + (isVisible ? 'bi-eye' : 'bi-eye-slash');
                    }
                });
            }

            document.querySelectorAll('#openUserModal').forEach(function(button) {
                button.addEventListener('click', function() {
                    restoreCreateFormState();
                    setModalOpen(addOverlay, true);
                });
            });

            document.querySelectorAll('#closeUserModal, #cancelUserModal').forEach(function(button) {
                button.addEventListener('click', function() {
                    saveCreateFormState();
                    setModalOpen(addOverlay, false);
                });
            });

            const openBranchModal = document.getElementById('openBranchModal');
            if (openBranchModal) {
                openBranchModal.addEventListener('click', function() {
                    setModalOpen(branchOverlay, true);
                    const branchName = document.getElementById('branchName');
                    if (branchName) branchName.focus();
                });
            }

            document.querySelectorAll('#closeBranchModal, #cancelBranchModal').forEach(function(button) {
                button.addEventListener('click', function() {
                    setModalOpen(branchOverlay, false);
                });
            });

            document.querySelectorAll('.open-user-view').forEach(function(button) {
                button.addEventListener('click', function() {
                    document.getElementById('viewUserTitle').textContent = button.dataset.fullName || 'User Details';
                    document.getElementById('viewUserMeta').textContent = button.dataset.username ? '@' + button.dataset.username : '';
                    document.getElementById('viewUserEmail').textContent = button.dataset.email || 'Not set';
                    document.getElementById('viewUserRole').textContent = button.dataset.role || 'Not set';
                    document.getElementById('viewUserBranch').textContent = button.dataset.branch || 'All branches';
                    document.getElementById('viewUserStatus').textContent = button.dataset.status || 'Unknown';
                    document.getElementById('viewUserCreated').textContent = button.dataset.created || 'Unknown';
                    document.getElementById('viewUserLastActive').textContent = button.dataset.lastActive || 'Active: Unknown';
                    setModalOpen(viewOverlay, true);
                });
            });

            document.querySelectorAll('#closeViewUserModal, #cancelViewUserModal').forEach(function(button) {
                button.addEventListener('click', function() {
                    setModalOpen(viewOverlay, false);
                });
            });

            function fillEditFields(prefix, button) {
                document.getElementById(prefix + 'UserId').value = button.dataset.userId || '';
                document.getElementById(prefix + 'Username').value = button.dataset.username || '';
                document.getElementById(prefix + 'Password').value = '';
                document.getElementById(prefix + 'Role').value = button.dataset.roleId || '';
                document.getElementById(prefix + 'Branch').value = button.dataset.branchId || '';
                document.getElementById(prefix + 'Status').value = button.dataset.status === 'disabled' ? 'disabled' : 'active';
                const profileImageInput = document.getElementById(prefix + 'ProfileImage');
                if (profileImageInput) profileImageInput.value = '';
                const removeProfileImage = document.getElementById(prefix + 'RemoveProfileImage');
                if (removeProfileImage) {
                    removeProfileImage.checked = false;
                    removeProfileImage.disabled = button.dataset.hasProfileImage !== '1';
                }
                if (prefix === 'drawer') {
                    const nameParts = (button.dataset.fullName || '').trim().split(/\s+/).filter(Boolean);
                    document.getElementById('drawerFirstName').value = nameParts.shift() || '';
                    document.getElementById('drawerLastName').value = nameParts.join(' ');
                    document.getElementById('drawerEmail').value = button.dataset.email || '';
                    const avatar = document.getElementById('drawerProfileAvatar');
                    if (avatar) {
                        avatar.innerHTML = '<span class="profile-avatar-fallback"></span>';
                        avatar.querySelector('.profile-avatar-fallback').textContent = button.dataset.profileInitials || 'RM';
                        if (button.dataset.profileUrl) {
                            const image = document.createElement('img');
                            image.className = 'profile-avatar-image';
                            image.alt = '';
                            image.src = button.dataset.profileUrl;
                            image.addEventListener('error', function() { image.remove(); });
                            avatar.appendChild(image);
                        }
                    }
                } else {
                    document.getElementById(prefix + 'FullName').value = button.dataset.fullName || '';
                }
            }

            document.querySelectorAll('.open-user-edit').forEach(function(button) {
                button.addEventListener('click', function() {
                    fillEditFields('edit', button);
                    setModalOpen(editOverlay, true);
                    document.getElementById('editFullName').focus();
                });
            });

            document.querySelectorAll('#closeEditUserModal, #cancelEditUserModal').forEach(function(button) {
                button.addEventListener('click', function() {
                    setModalOpen(editOverlay, false);
                });
            });

            function openDrawer(button) {
                const isSelf = button.dataset.isSelf === '1';
                const isProtectedSuperAdmin = button.dataset.isSuperAdmin === '1';
                selectedUsername = button.dataset.username || '';
                fillEditFields('drawer', button);

                document.getElementById('deleteUserId').value = button.dataset.userId || '';
                document.getElementById('userDrawerMeta').textContent = selectedUsername ? '@' + selectedUsername : '';

                const drawerRole = document.getElementById('drawerRole');
                const drawerBranch = document.getElementById('drawerBranch');
                const drawerStatus = document.getElementById('drawerStatus');
                const deleteButton = document.getElementById('deleteUserButton');
                const managePrivileges = document.getElementById('drawerManagePrivileges');
                const privilegesGroup = document.getElementById('drawerPrivilegesGroup');
                const selectedRoleText = drawerRole.options[drawerRole.selectedIndex] ? drawerRole.options[drawerRole.selectedIndex].textContent.trim().toLowerCase() : '';
                const canManageExtraPrivileges = selectedRoleText === 'admin' && !isSelf;
                drawerRole.disabled = isSelf || isProtectedSuperAdmin;
                drawerRole.dataset.isSelf = isSelf ? '1' : '0';
                drawerRole.title = isProtectedSuperAdmin ? 'The Super Administrator role is protected.' : (isSelf ? 'You cannot change your own role.' : '');
                drawerBranch.disabled = isProtectedSuperAdmin;
                drawerBranch.title = isProtectedSuperAdmin ? 'The Super Administrator branch assignment is protected.' : '';
                drawerStatus.disabled = isSelf || isProtectedSuperAdmin;
                drawerStatus.title = isProtectedSuperAdmin ? 'The Super Administrator account cannot be disabled.' : '';
                managePrivileges.disabled = !canManageExtraPrivileges;
                privilegesGroup.hidden = selectedRoleText !== 'admin';

                const assignedPrivileges = (button.dataset.privilegeIds || '').split(',').filter(Boolean);
                document.querySelectorAll('[data-privilege-id]').forEach(function(input) {
                    input.checked = assignedPrivileges.includes(input.dataset.privilegeId);
                    input.disabled = !canManageExtraPrivileges;
                });

                deleteButton.disabled = isSelf;
                deleteButton.title = isSelf ? 'You cannot delete your own account.' : '';
                selectDrawerTab('user');
                setModalOpen(drawerOverlay, true);
                document.getElementById('drawerUsername').focus();
            }

            const drawerRoleSelect = document.getElementById('drawerRole');
            if (drawerRoleSelect) {
                drawerRoleSelect.addEventListener('change', function() {
                    const selectedRoleText = drawerRoleSelect.options[drawerRoleSelect.selectedIndex] ? drawerRoleSelect.options[drawerRoleSelect.selectedIndex].textContent.trim().toLowerCase() : '';
                    const canManageExtraPrivileges = selectedRoleText === 'admin' && drawerRoleSelect.dataset.isSelf !== '1';
                    const managePrivileges = document.getElementById('drawerManagePrivileges');
                    const privilegesGroup = document.getElementById('drawerPrivilegesGroup');
                    privilegesGroup.hidden = selectedRoleText !== 'admin';
                    managePrivileges.disabled = !canManageExtraPrivileges;
                    document.querySelectorAll('[data-privilege-id]').forEach(function(input) {
                        input.disabled = !canManageExtraPrivileges;
                    });
                });
            }

            document.querySelectorAll('.open-user-drawer').forEach(function(button) {
                button.addEventListener('click', function() {
                    openDrawer(button);
                });
            });

            document.querySelectorAll('#closeUserDrawer, #cancelUserDrawer').forEach(function(button) {
                button.addEventListener('click', function() {
                    setModalOpen(drawerOverlay, false);
                });
            });

            document.querySelectorAll('[data-embedded-close]').forEach(function(button) {
                button.addEventListener('click', function() {
                    window.parent.postMessage({ type: 'close-user-management' }, '*');
                });
            });

            const userSearch = document.getElementById('userSearch');
            if (userSearch) {
                userSearch.addEventListener('input', function() {
                    const query = userSearch.value.toLowerCase().trim();
                    document.querySelectorAll('#usersPanel .users-table tbody tr').forEach(function(row) {
                        row.hidden = !!query && !row.textContent.toLowerCase().includes(query);
                    });
                });
            }

            [addOverlay, editOverlay, viewOverlay, drawerOverlay, branchOverlay].forEach(function(overlay) {
                if (!overlay) return;
                overlay.addEventListener('click', function(event) {
                    if (event.target === overlay) {
                        if (overlay === addOverlay) saveCreateFormState();
                        setModalOpen(overlay, false);
                    }
                });
            });

            const deleteForm = document.getElementById('drawerDeleteForm');
            const deleteButton = document.getElementById('deleteUserButton');
            if (deleteForm) {
                deleteForm.addEventListener('submit', async function(event) {
                    if (deleteForm.dataset.confirmed === '1') return;
                    event.preventDefault();
                    if (deleteButton.disabled) return;
                    const approved = await RetailMindUI.confirm({
                        title: 'Delete user permanently',
                        message: 'Delete @' + selectedUsername + '? This cannot be undone.',
                        confirmText: 'Delete user',
                        danger: true
                    });
                    if (approved) {
                        deleteForm.dataset.confirmed = '1';
                        deleteForm.submit();
                    }
                });
            }

            document.addEventListener('keydown', function(event) {
                if (event.key !== 'Escape') return;
                [addOverlay, editOverlay, viewOverlay, drawerOverlay, branchOverlay].forEach(function(overlay) {
                    if (overlay && overlay.classList.contains('open')) {
                        if (overlay === addOverlay) saveCreateFormState();
                        setModalOpen(overlay, false);
                    }
                });
            });

            if (restoreCreateForm) {
                restoreCreateFormState();
                setModalOpen(addOverlay, true);
            } else if (branchFormSubmitted) {
                selectManagementTab('branches');
                if (restoreBranchForm) {
                    setModalOpen(branchOverlay, true);
                    const branchName = document.getElementById('branchName');
                    if (branchName) branchName.focus();
                }
            } else if (autoOpenDrawerUserId > 0) {
                const manageButton = document.querySelector('.open-user-drawer[data-user-id="' + autoOpenDrawerUserId + '"]');
                if (manageButton) {
                    openDrawer(manageButton);
                }
            }
        });
    </script>
</body>

</html>
