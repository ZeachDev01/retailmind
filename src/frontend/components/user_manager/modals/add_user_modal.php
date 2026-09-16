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
                <label>Username</label>
                <input name="username" value="<?= htmlspecialchars($createFormValues['username'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-group">
                <label>First Name</label>
                <input name="first_name" value="<?= htmlspecialchars($createFormValues['first_name'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-group">
                <label>Last Name</label>
                <input name="last_name" value="<?= htmlspecialchars($createFormValues['last_name'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($createFormValues['email'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="password-field">
                    <input type="password" name="password" id="createUserPassword" required>
                    <button type="button" class="password-toggle" id="toggleCreatePassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                </div>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role_id" required>
                    <?php foreach ($roles as $r): ?>
                        <?php if (!in_array($r['role_name'], ['super_admin', 'seller'], true)): ?>
                            <option value="<?= (int)$r['role_id'] ?>" <?= $createFormValues['role_id'] === (string)$r['role_id'] ? ' selected' : '' ?>><?= htmlspecialchars(display_label((string)$r['role_name'])) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Assigned Branch</label>
                <select name="branch_id">
                    <option value="" <?= $createFormValues['branch_id'] === '' ? ' selected' : '' ?>>No branch (administrators only)</option>
                    <?php foreach ($branches as $branch): ?>
                        <?php if ($branch['status'] === 'active'): ?>
                            <option value="<?= (int)$branch['branch_id'] ?>" <?= $createFormValues['branch_id'] === (string)$branch['branch_id'] ? ' selected' : '' ?>><?= htmlspecialchars(display_person_name((string)$branch['branch_name']) . ' (' . $branch['branch_code'] . ')') ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelUserModal">Cancel</button>
                <button class="btn manage-users-primary" type="submit"><i class="bi bi-person-plus" aria-hidden="true"></i> Create User</button>
            </div>
        </form>
    </div>
</div>
