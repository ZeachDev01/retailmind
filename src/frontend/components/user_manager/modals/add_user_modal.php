<div class="user-modal-overlay" id="userModalOverlay" aria-hidden="true">
    <div class="user-modal" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
        <div class="user-modal-header">
            <div><h3 id="userModalTitle">Add Store Staff</h3><p>Create an account with a fixed operational role template.</p></div>
            <button type="button" class="user-modal-close" id="closeUserModal" aria-label="Close add user form">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="user-form" id="createUserForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group"><label>Username</label><input name="username" value="<?= htmlspecialchars($createFormValues['username'], ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="form-group"><label>First Name</label><input name="first_name" value="<?= htmlspecialchars($createFormValues['first_name'], ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="form-group"><label>Last Name</label><input name="last_name" value="<?= htmlspecialchars($createFormValues['last_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($createFormValues['email'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="form-group">
                <label for="createProfileImage">Profile Picture <span class="optional-label">Optional</span></label>
                <input type="file" id="createProfileImage" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp">
                <small class="field-help">JPEG, PNG, GIF, or WebP. Maximum 2MB.</small>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="password-field"><input type="password" name="password" id="createUserPassword" required minlength="8"><button type="button" class="password-toggle" data-password-toggle="createUserPassword" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
                <small class="field-help">Use at least 8 characters with uppercase, lowercase, and a number.</small>
            </div>
            <div class="form-group">
                <label>Role template</label>
                <select name="role_id" required>
                    <?php foreach ($roles as $r): ?>
                        <?php if (!in_array($r['role_name'], ['super_admin', 'seller'], true)): ?>
                            <option value="<?= (int)$r['role_id'] ?>" <?= $createFormValues['role_id'] === (string)$r['role_id'] ? ' selected' : '' ?>><?= htmlspecialchars(display_label((string)$r['role_name'])) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <small class="field-help">Store scope is assigned securely by the server.</small>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelUserModal">Cancel</button>
                <button class="btn manage-users-primary" type="submit"><i class="bi bi-person-plus"></i> Create User</button>
            </div>
        </form>
    </div>
</div>
