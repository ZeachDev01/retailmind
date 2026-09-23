<div class="user-drawer-overlay" id="userDrawerOverlay" aria-hidden="true">
    <aside class="user-drawer" role="dialog" aria-modal="true" aria-labelledby="userDrawerTitle">
        <div class="user-drawer-header">
            <div class="user-drawer-identity">
                <span class="profile-avatar user-drawer-avatar" id="drawerProfileAvatar" aria-hidden="true"><span class="profile-avatar-fallback">RM</span></span>
                <div><h3 id="userDrawerTitle">Manage Account</h3><p id="userDrawerMeta"></p></div>
            </div>
            <button type="button" class="user-modal-close" id="closeUserDrawer" aria-label="Close account manager">&times;</button>
        </div>
        <div class="user-drawer-body">
            <div class="user-drawer-tabs" role="tablist" aria-label="Manage user sections">
                <button type="button" class="user-drawer-tab is-active" role="tab" aria-selected="true" data-user-drawer-tab="user">User</button>
                <button type="button" class="user-drawer-tab" role="tab" aria-selected="false" data-user-drawer-tab="status">Status</button>
                <button type="button" class="user-drawer-tab" role="tab" aria-selected="false" data-user-drawer-tab="security">Security</button>
            </div>
            <form method="POST" enctype="multipart/form-data" class="user-form user-drawer-form" id="userDrawerForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" id="drawerUserId">
                <div class="user-drawer-panel is-active" data-user-drawer-panel="user">
                    <div class="form-group"><label for="drawerUsername">Username</label><input id="drawerUsername" name="username" required></div>
                    <div class="form-group"><label for="drawerFirstName">First Name</label><input id="drawerFirstName" name="first_name" required></div>
                    <div class="form-group"><label for="drawerLastName">Last Name</label><input id="drawerLastName" name="last_name"></div>
                    <div class="form-group"><label for="drawerEmail">Email</label><input type="email" id="drawerEmail" name="email"></div>
                    <div class="form-group">
                        <label for="drawerProfileImage">Profile Picture <span class="optional-label">Optional</span></label>
                        <input type="file" id="drawerProfileImage" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp">
                        <label class="profile-image-remove"><input type="checkbox" name="remove_profile_image" value="1" id="drawerRemoveProfileImage"> Remove current picture</label>
                    </div>
                    <div class="form-group">
                        <label for="drawerRole">Role template</label>
                        <select id="drawerRole" name="role_id" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= (int)$role['role_id'] ?>"><?= htmlspecialchars(display_label((string)$role['role_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="field-help">Roles use fixed access templates; individual privilege combinations are not available.</small>
                    </div>
                </div>
                <div class="user-drawer-panel" data-user-drawer-panel="status" hidden>
                    <div class="form-group">
                        <label for="drawerStatus">Account status</label>
                        <select id="drawerStatus" name="status"><option value="active">Active</option><option value="disabled">Disabled</option></select>
                        <small class="field-help">Disabling retains historical attribution and revokes active sessions.</small>
                    </div>
                </div>
                <div class="user-drawer-panel" data-user-drawer-panel="security" hidden>
                    <div class="form-group">
                        <label for="drawerPassword">Reset password</label>
                        <div class="password-field">
                            <input type="password" id="drawerPassword" name="new_password" autocomplete="new-password" minlength="8">
                            <button type="button" class="password-toggle" data-password-toggle="drawerPassword" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        </div>
                        <small class="field-help">Use at least 8 characters with uppercase, lowercase, and a number.</small>
                        <small class="field-help">A reset revokes active sessions and requires a password change at next login.</small>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="cancelUserDrawer">Cancel</button>
                    <button class="btn" type="submit" id="drawerStatusButton">Save Changes</button>
                </div>
            </form>
            <form method="POST" class="drawer-modal-actions">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke_sessions">
                <input type="hidden" name="user_id" id="drawerRevokeUserId">
                <button type="submit" class="btn btn-secondary" id="drawerRevokeButton"><i class="bi bi-door-closed"></i> Revoke Sessions</button>
            </form>
        </div>
    </aside>
</div>
