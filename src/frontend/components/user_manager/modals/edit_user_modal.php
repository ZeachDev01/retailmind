<div class="user-modal-overlay" id="editUserModalOverlay" aria-hidden="true">
    <div class="user-modal" role="dialog" aria-modal="true" aria-labelledby="editUserModalTitle">
        <div class="user-modal-header">
            <div>
                <h3 id="editUserModalTitle">Edit User</h3>
                <p>Update account identity and password details.</p>
            </div>
            <button type="button" class="user-modal-close" id="closeEditUserModal" aria-label="Close edit user form">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="user-form" id="editUserForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" id="editUserId">
            <input type="hidden" name="role_id" id="editRole">
            <input type="hidden" name="branch_id" id="editBranch">
            <input type="hidden" name="status" id="editStatus">
            <div class="form-group">
                <label for="editFullName">Full Name</label>
                <input id="editFullName" name="full_name" required>
            </div>
            <div class="form-group">
                <label for="editUsername">Username</label>
                <input id="editUsername" name="username" required>
            </div>
            <div class="form-group">
                <label for="editPassword">New Password</label>
                <input type="password" id="editPassword" name="new_password" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="editProfileImage">Profile Picture <span class="optional-label">Optional</span></label>
                <input type="file" id="editProfileImage" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp">
                <small class="field-help">JPEG, PNG, GIF, or WebP. Maximum 2MB.</small>
                <label class="profile-image-remove"><input type="checkbox" name="remove_profile_image" value="1" id="editRemoveProfileImage"> Remove current picture</label>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelEditUserModal">Cancel</button>
                <button class="btn manage-users-primary" type="submit">Save User</button>
            </div>
        </form>
    </div>
</div>
