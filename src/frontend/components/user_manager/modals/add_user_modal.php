<div class="user-modal-overlay" id="userModalOverlay" aria-hidden="true">
    <div class="user-modal" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
        <div class="user-modal-header">
            <div><h3 id="userModalTitle">Add Store Staff</h3><p>Create an account with a fixed operational role template.</p></div>
            <button type="button" class="user-modal-close" id="closeUserModal" aria-label="Close add user form">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="user-form" id="createUserForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group" id="createUsernameGroup"><label for="createUsernameInput">Username</label><input id="createUsernameInput" name="username" value="<?= htmlspecialchars($createFormValues['username'], ENT_QUOTES, 'UTF-8') ?>" required autocomplete="off" aria-describedby="createUsernameNotice"><small class="field-help">Usernames cannot contain @ or spaces. Use letters, numbers, dots, underscores, and hyphens.</small><small class="field-error" id="createUsernameNotice" aria-live="polite">This username is already in use.</small></div>
            <div class="form-group"><label>First Name</label><input name="first_name" value="<?= htmlspecialchars($createFormValues['first_name'], ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="form-group"><label>Last Name</label><input name="last_name" value="<?= htmlspecialchars($createFormValues['last_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="duplicate-summary" id="createDuplicateSummary" role="alert" hidden>An account with this username or email already exists.</div>
            <div class="form-group" id="createEmailGroup"><label for="createEmailInput">Email</label><input type="email" id="createEmailInput" name="email" value="<?= htmlspecialchars($createFormValues['email'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" aria-describedby="createEmailNotice"><small class="field-error" id="createEmailNotice" aria-live="polite">This email is already in use.</small></div>
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
                <label>Role access</label>
                <div class="role-check-list">
                    <?php foreach ($roles as $r): ?>
                        <?php if (!in_array($r['role_name'], ['super_admin', 'seller'], true)): ?>
                            <label class="role-check"><input type="checkbox" name="role_ids[]" value="<?= (int)$r['role_id'] ?>" <?= in_array((string)$r['role_id'], $createFormValues['role_ids'], true) ? ' checked' : '' ?>> <span><?= htmlspecialchars(display_label((string)$r['role_name'])) ?></span></label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <small class="field-help">Select every workspace this user can access. The first selected role becomes the default workspace.</small>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelUserModal">Cancel</button>
                <button class="btn manage-users-primary" type="submit" id="createUserSubmit"><i class="bi bi-person-plus"></i> Create User</button>
            </div>
        </form>
    </div>
</div>
