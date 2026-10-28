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
                <button type="button" class="user-drawer-tab is-active" id="drawerTabUser" role="tab" aria-selected="true" aria-controls="drawerPanelUser" tabindex="0" data-user-drawer-tab="user">User</button>
                <button type="button" class="user-drawer-tab" id="drawerTabStatus" role="tab" aria-selected="false" aria-controls="drawerPanelStatus" tabindex="-1" data-user-drawer-tab="status">Status</button>
                <button type="button" class="user-drawer-tab" id="drawerTabSecurity" role="tab" aria-selected="false" aria-controls="drawerPanelSecurity" tabindex="-1" data-user-drawer-tab="security">Security</button>
            </div>
            <form method="POST" enctype="multipart/form-data" class="user-form user-drawer-form" id="userDrawerForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="role_access_present" value="1">
                <input type="hidden" name="user_id" id="drawerUserId">
                <input type="hidden" name="role_ids[]" id="drawerRetainedAdministrator" value="<?= (int)(array_column($roles, 'role_id', 'role_name')['admin'] ?? 0) ?>" disabled>
                <p class="field-help" id="drawerAdministratorMinimumHelp" hidden><?= htmlspecialchars(\App\Authorization\AdministratorAccessMinimum::MESSAGE) ?></p>
                <div class="user-drawer-panel is-active" id="drawerPanelUser" role="tabpanel" aria-labelledby="drawerTabUser" tabindex="0" data-user-drawer-panel="user">
                    <div class="form-group"><label for="drawerUsername">Username</label><input id="drawerUsername" name="username" required aria-describedby="drawerUsernameHelp"><small class="field-help" id="drawerUsernameHelp">Usernames cannot contain @ or spaces. Use letters, numbers, dots, underscores, and hyphens.</small></div>
                    <div class="form-group"><label for="drawerFirstName">First Name</label><input id="drawerFirstName" name="first_name" required></div>
                    <div class="form-group"><label for="drawerLastName">Last Name</label><input id="drawerLastName" name="last_name"></div>
                    <div class="form-group"><label for="drawerEmail">Email</label><input type="email" id="drawerEmail" name="email"></div>
                    <div class="form-group">
                        <label for="drawerProfileImage">Profile Picture <span class="optional-label">Optional</span></label>
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= \App\Services\ProfileImageStorage::MAX_FILE_SIZE ?>">
                        <input type="file" id="drawerProfileImage" name="profile_image" accept="<?= \App\Services\ProfileImageStorage::ACCEPT ?>" aria-describedby="drawerProfileImageHelp">
                        <small class="field-help" id="drawerProfileImageHelp"><?= \App\Services\ProfileImageStorage::HELP ?></small>
                        <button type="submit" name="action" value="remove_profile_image" id="drawerRemoveProfileImage" class="btn btn-secondary" formnovalidate>Remove picture</button>
                    </div>
                    <div class="form-group" role="group" aria-labelledby="drawerRolesLabel" aria-describedby="drawerRolesHelp">
                        <label id="drawerRolesLabel">Role access</label>
                        <div class="role-check-list" id="drawerRoles">
                            <?php foreach ($roles as $role): ?>
                                <label class="role-check"><input type="checkbox" name="role_ids[]" data-role="<?= htmlspecialchars($role['role_name']) ?>" value="<?= (int)$role['role_id'] ?>"> <span><?= htmlspecialchars(display_label((string)$role['role_name'])) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <small class="field-help" id="drawerRolesHelp">Selected roles appear in the workspace switcher. Switch workspaces to use their permissions.</small>
                    </div>
                </div>
                <div class="user-drawer-panel" id="drawerPanelStatus" role="tabpanel" aria-labelledby="drawerTabStatus" tabindex="0" data-user-drawer-panel="status" hidden>
                    <div class="form-group">
                        <label for="drawerStatus">Account status</label>
                        <select id="drawerStatus" name="status" aria-describedby="drawerStatusHelp drawerDormancyHelp"><option value="active">Active</option><option value="disabled">Disabled</option></select>
                        <small class="field-help" id="drawerStatusHelp">Disabling retains historical attribution and revokes active sessions.</small>
                    </div>
                    <div id="drawerPendingDuties" hidden class="alert tag-warning" role="alert" style="margin-top: 10px;">
                        <p><strong>Unfinished duties pending:</strong> This account has open cash or GCash duties that require operational handover.</p>
                        <div id="drawerPendingLinks" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px;"></div>
                    </div>
                    <div class="form-group" id="drawerLastLoginRow">
                        <span class="field-static-label" id="drawerLastLoginLabel">Last login</span>
                        <strong id="drawerLastLogin" aria-labelledby="drawerLastLoginLabel">Never</strong>
                    </div>
                    <div class="form-group" id="drawerAutoDisableRow" hidden>
                        <span class="field-static-label" id="drawerAutoDisableLabel">Automatic deactivation</span>
                        <strong id="drawerAutoDisableDate" aria-labelledby="drawerAutoDisableLabel"></strong>
                    </div>
                    <p class="field-help" id="drawerPolicyDisabledLine" hidden></p>
                    <small class="field-help" id="drawerDormancyHelp"><?= htmlspecialchars(\App\Authorization\DormancyStatusTab::HELP_LINE) ?></small>
                </div>
                <div class="user-drawer-panel" id="drawerPanelSecurity" role="tabpanel" aria-labelledby="drawerTabSecurity" tabindex="0" data-user-drawer-panel="security" hidden>
                    <div class="form-group">
                        <label for="drawerPassword">Reset password</label>
                        <div class="password-field">
                            <input type="password" id="drawerPassword" name="new_password" autocomplete="new-password" minlength="8" aria-describedby="drawerPasswordHelp drawerPasswordResetHelp">
                            <button type="button" class="password-toggle" data-password-toggle="drawerPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                        <small class="field-help" id="drawerPasswordHelp">Use at least 8 characters with uppercase, lowercase, and a number.</small>
                        <small class="field-help" id="drawerPasswordResetHelp">A reset revokes active sessions and requires a password change at next login.</small>
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
                <button type="submit" class="btn btn-secondary" id="drawerRevokeButton"><i class="bi bi-door-closed" aria-hidden="true"></i> Revoke Sessions</button>
            </form>
            <form method="POST" class="drawer-modal-actions" id="drawerDeleteForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" id="drawerDeleteUserId">
                <input type="hidden" name="confirm_delete" id="drawerDeleteConfirmed" value="">
                <p class="field-help" id="drawerDeleteHelp"></p>
                <button type="submit" class="btn btn-danger" id="drawerDeleteButton" aria-describedby="drawerDeleteHelp" disabled>Permanently Delete Account</button>
            </form>
        </div>
    </aside>
</div>
