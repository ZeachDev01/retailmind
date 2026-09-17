<div class="user-drawer-overlay" id="userDrawerOverlay" aria-hidden="true">
    <aside class="user-drawer" role="dialog" aria-modal="true" aria-labelledby="userDrawerTitle">
        <div class="user-drawer-header">
            <div class="user-drawer-identity">
                <span class="profile-avatar user-drawer-avatar" id="drawerProfileAvatar" aria-hidden="true"><span class="profile-avatar-fallback">RM</span></span>
                <div>
                    <h3 id="userDrawerTitle">Manage Account</h3>
                    <p id="userDrawerMeta"></p>
                </div>
            </div>
            <button type="button" class="user-modal-close" id="closeUserDrawer" aria-label="Close account manager">&times;</button>
        </div>
        <div class="user-drawer-body">
            <div class="user-drawer-tabs" role="tablist" aria-label="Manage user sections">
                <button type="button" class="user-drawer-tab is-active" id="drawerTabUser" role="tab" aria-selected="true" aria-controls="drawerPanelUser" data-user-drawer-tab="user">User</button>
                <button type="button" class="user-drawer-tab" id="drawerTabBranch" role="tab" aria-selected="false" aria-controls="drawerPanelBranch" data-user-drawer-tab="branch">Assign Branch</button>
                <button type="button" class="user-drawer-tab" id="drawerTabStatus" role="tab" aria-selected="false" aria-controls="drawerPanelStatus" data-user-drawer-tab="status">Status</button>
                <button type="button" class="user-drawer-tab" id="drawerTabSecurity" role="tab" aria-selected="false" aria-controls="drawerPanelSecurity" data-user-drawer-tab="security">Security</button>
            </div>
            <form method="POST" enctype="multipart/form-data" class="user-form user-drawer-form" id="userDrawerForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="manage_privileges" value="1" id="drawerManagePrivileges">
                <input type="hidden" name="user_id" id="drawerUserId">
                <div class="user-drawer-panel is-active" id="drawerPanelUser" role="tabpanel" aria-labelledby="drawerTabUser" data-user-drawer-panel="user">
                    <div class="form-group">
                        <label for="drawerUsername">Username</label>
                        <input id="drawerUsername" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="drawerFirstName">First Name</label>
                        <input id="drawerFirstName" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="drawerLastName">Last Name</label>
                        <input id="drawerLastName" name="last_name">
                    </div>
                    <div class="form-group">
                        <label for="drawerEmail">Email</label>
                        <input type="email" id="drawerEmail" name="email">
                    </div>
                    <div class="form-group">
                        <label for="drawerProfileImage">Profile Picture <span class="optional-label">Optional</span></label>
                        <input type="file" id="drawerProfileImage" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp">
                        <small class="field-help">JPEG, PNG, GIF, or WebP. Maximum 2MB. Uploading a new picture replaces the current one.</small>
                        <label class="profile-image-remove"><input type="checkbox" name="remove_profile_image" value="1" id="drawerRemoveProfileImage"> Remove current picture</label>
                    </div>
                    <div class="form-group">
                        <label for="drawerRole">Role</label>
                        <select id="drawerRole" name="role_id" required>
                            <?php foreach ($roles as $r): ?>
                                <?php if (!in_array($r['role_name'], ['super_admin', 'seller'], true)): ?>
                                    <option value="<?= (int)$r['role_id'] ?>"><?= htmlspecialchars(display_label((string)$r['role_name'])) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="user-drawer-panel" id="drawerPanelBranch" role="tabpanel" aria-labelledby="drawerTabBranch" data-user-drawer-panel="branch" hidden>
                    <div class="form-group">
                        <label for="drawerBranch">Assigned Branch</label>
                        <select id="drawerBranch" name="branch_id" required>
                            <?php foreach ($branches as $branch): ?>
                                <?php if ($branch['status'] === 'active'): ?>
                                    <option value="<?= (int)$branch['branch_id'] ?>"><?= htmlspecialchars(display_person_name((string)$branch['branch_name']) . ' (' . $branch['branch_code'] . ')') ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="drawerPrivilegesGroup" hidden>
                        <label>Additional Privileges</label>
                        <div class="privilege-list">
                            <?php foreach ($privileges as $privilege): ?>
                                <label><input type="checkbox" name="privilege_ids[]" value="<?= (int)$privilege['privilege_id'] ?>" data-privilege-id="<?= (int)$privilege['privilege_id'] ?>"> <?= htmlspecialchars(display_label((string)$privilege['privilege_name'])) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="user-drawer-panel" id="drawerPanelStatus" role="tabpanel" aria-labelledby="drawerTabStatus" data-user-drawer-panel="status" hidden>
                    <div class="form-group">
                        <label for="drawerStatus">Status</label>
                        <select id="drawerStatus" name="status">
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>
                </div>
                <div class="user-drawer-panel" id="drawerPanelSecurity" role="tabpanel" aria-labelledby="drawerTabSecurity" data-user-drawer-panel="security" hidden>
                    <div class="form-group">
                        <label for="drawerPassword">New Password</label>
                        <div class="password-field">
                            <input type="password" id="drawerPassword" name="new_password" autocomplete="new-password">
                            <button type="button" class="password-toggle" id="toggleDrawerPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="cancelUserDrawer">Cancel</button>
                    <button class="btn" type="submit">Save Changes</button>
                </div>
            </form>

            <div class="modal-actions drawer-modal-actions" data-user-drawer-danger="security" hidden>
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
