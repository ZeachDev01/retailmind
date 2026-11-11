<div class="user-drawer-overlay" id="userDrawerOverlay" aria-hidden="true">
    <aside class="user-drawer" role="dialog" aria-modal="true" aria-labelledby="userDrawerTitle">
        <div class="user-drawer-header">
            <div class="user-drawer-identity">
                <span class="profile-avatar user-drawer-avatar" id="drawerProfileAvatar" aria-hidden="true"><span class="profile-avatar-fallback">RM</span></span>
                <div class="user-drawer-meta-wrap">
                    <div class="user-drawer-title-row">
                        <h3 id="userDrawerTitle">Manage Account</h3>
                        <span class="drawer-status-chip is-active" id="drawerHeaderStatusChip">
                            <span class="status-dot"></span>
                            <span class="status-chip-text" id="drawerHeaderStatusText">Active</span>
                        </span>
                    </div>
                    <p id="userDrawerMeta" class="drawer-user-meta"></p>
                </div>
            </div>
            <button type="button" class="user-modal-close" id="closeUserDrawer" aria-label="Close account manager"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>

        <nav class="user-drawer-tabs" role="tablist" aria-label="Manage user sections">
            <button type="button" class="user-drawer-tab is-active" id="drawerTabUser" role="tab" aria-selected="true" aria-controls="drawerPanelUser" tabindex="0" data-user-drawer-tab="user">
                <i class="bi bi-person-gear" aria-hidden="true"></i>
                <span>Profile</span>
            </button>
            <button type="button" class="user-drawer-tab" id="drawerTabStatus" role="tab" aria-selected="false" aria-controls="drawerPanelStatus" tabindex="-1" data-user-drawer-tab="status">
                <i class="bi bi-activity" aria-hidden="true"></i>
                <span>Status</span>
            </button>
            <button type="button" class="user-drawer-tab" id="drawerTabSecurity" role="tab" aria-selected="false" aria-controls="drawerPanelSecurity" tabindex="-1" data-user-drawer-tab="security">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                <span>Security</span>
            </button>
        </nav>

        <form method="POST" enctype="multipart/form-data" class="user-form user-drawer-form" id="userDrawerForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="role_access_present" value="1">
            <input type="hidden" name="user_id" id="drawerUserId">
            <input type="hidden" name="role_ids[]" id="drawerRetainedAdministrator" value="<?= (int)(array_column($roles, 'role_id', 'role_name')['admin'] ?? 0) ?>" disabled>
            <p class="field-help" id="drawerAdministratorMinimumHelp" hidden><?= htmlspecialchars(\App\Authorization\AdministratorAccessMinimum::MESSAGE) ?></p>

            <div class="user-drawer-body">
                <!-- Panel 1: Profile / User -->
                <div class="user-drawer-panel is-active anim-panel-in-right" id="drawerPanelUser" role="tabpanel" aria-labelledby="drawerTabUser" tabindex="0" data-user-drawer-panel="user">
                    <div class="drawer-card">
                        <div class="drawer-card-header">
                            <i class="bi bi-person-vcard" aria-hidden="true"></i>
                            <span>Personal Details</span>
                        </div>
                        <div class="drawer-form-row">
                            <div class="form-group">
                                <label for="drawerFirstName">First Name</label>
                                <input id="drawerFirstName" name="first_name" maxlength="50" autocomplete="given-name" placeholder="First name" required>
                            </div>
                            <div class="form-group">
                                <label for="drawerLastName">Last Name</label>
                                <input id="drawerLastName" name="last_name" maxlength="50" autocomplete="family-name" placeholder="Last name">
                            </div>
                        </div>
                        <small class="field-help" id="drawerNameReview" hidden>Review this legacy display name and enter first and last name.</small>
                        <div class="form-group">
                            <label for="drawerUsername">Username</label>
                            <input id="drawerUsername" name="username" autocomplete="username" spellcheck="false" required aria-describedby="drawerUsernameHelp" placeholder="e.g. jdoe">
                            <small class="field-help" id="drawerUsernameHelp">Usernames cannot contain @ or spaces. Use letters, numbers, dots, underscores, and hyphens.</small>
                        </div>
                        <div class="form-group">
                            <label for="drawerEmail">Email</label>
                            <input type="email" id="drawerEmail" name="email" autocomplete="email" spellcheck="false" placeholder="user@store.com">
                        </div>
                    </div>

                    <div class="drawer-card">
                        <div class="drawer-card-header">
                            <i class="bi bi-image" aria-hidden="true"></i>
                            <span>Profile Picture</span>
                        </div>
                        <div class="drawer-photo-control">
                            <div class="drawer-photo-actions">
                                <label for="drawerProfileImage" class="btn btn-secondary btn-small drawer-photo-btn">
                                    <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Choose Photo
                                </label>
                                <input type="hidden" name="MAX_FILE_SIZE" value="<?= \App\Services\ProfileImageStorage::MAX_FILE_SIZE ?>">
                                <input type="file" id="drawerProfileImage" name="profile_image" class="visually-hidden" accept="<?= \App\Services\ProfileImageStorage::ACCEPT ?>" aria-describedby="drawerProfileImageHelp">
                                <button type="submit" name="action" value="remove_profile_image" id="drawerRemoveProfileImage" class="btn btn-secondary btn-small btn-remove-photo" formnovalidate>
                                    <i class="bi bi-trash3" aria-hidden="true"></i> Remove
                                </button>
                            </div>
                            <div id="drawerFileNameDisplay" class="drawer-file-feedback" hidden></div>
                            <small class="field-help" id="drawerProfileImageHelp"><?= \App\Services\ProfileImageStorage::HELP ?></small>
                        </div>
                    </div>

                    <?php if ($actorRole !== 'super_admin'): ?>
                    <div class="drawer-card">
                        <div class="drawer-card-header" id="drawerRolesLabel">
                            <i class="bi bi-shield-check" aria-hidden="true"></i>
                            <span>Role Access</span>
                        </div>
                        <div class="drawer-roles-grid" id="drawerRoles" role="group" aria-labelledby="drawerRolesLabel" aria-describedby="drawerRolesHelp">
                            <?php
                            $roleIcons = [
                                'admin' => 'bi-shield-shaded',
                                'inventory_manager' => 'bi-box-seam',
                                'cashier' => 'bi-receipt',
                                'staff' => 'bi-person-badge',
                                'super_admin' => 'bi-shield-fill-check',
                            ];
                            foreach ($roles as $role):
                                $roleKey = (string)$role['role_name'];
                                $iconClass = $roleIcons[$roleKey] ?? 'bi-person-check';
                            ?>
                                <label class="drawer-role-tile">
                                    <input type="checkbox" name="role_ids[]" data-role="<?= htmlspecialchars($roleKey) ?>" value="<?= (int)$role['role_id'] ?>" class="visually-hidden">
                                    <div class="drawer-role-content">
                                        <div class="drawer-role-icon"><i class="bi <?= $iconClass ?>" aria-hidden="true"></i></div>
                                        <div class="drawer-role-text">
                                            <strong><?= htmlspecialchars(display_label($roleKey)) ?></strong>
                                        </div>
                                    </div>
                                    <span class="drawer-role-check-indicator" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small class="field-help" id="drawerRolesHelp">Workspace access and permissions.</small>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Panel 2: Status -->
                <div class="user-drawer-panel" id="drawerPanelStatus" role="tabpanel" aria-labelledby="drawerTabStatus" tabindex="0" data-user-drawer-panel="status" hidden>
                    <div class="drawer-card">
                        <div class="drawer-card-header">
                            <i class="bi bi-toggle-on" aria-hidden="true"></i>
                            <span>Account State</span>
                        </div>
                        <div class="form-group drawer-status-group">
                            <label for="drawerStatus" class="visually-hidden">Account status</label>
                            <select id="drawerStatus" name="status" class="drawer-status-select" aria-describedby="drawerStatusHelp drawerDormancyHelp">
                                <option value="active">Active &mdash; Enabled for login</option>
                                <option value="disabled">Disabled &mdash; Access blocked</option>
                            </select>
                            <small class="field-help" id="drawerStatusHelp">Disabling retains historical attribution and revokes active sessions.</small>
                        </div>
                    </div>

                    <div id="drawerPendingDuties" hidden class="alert tag-warning drawer-duty-alert" role="alert">
                        <div class="drawer-duty-header">
                            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                            <strong>Unfinished Duties Pending</strong>
                        </div>
                        <p class="drawer-duty-text">Account has open cash or GCash duties requiring operational handover.</p>
                        <div id="drawerPendingLinks" class="drawer-pending-links"></div>
                    </div>

                    <div class="drawer-card">
                        <div class="drawer-card-header">
                            <i class="bi bi-clock-history" aria-hidden="true"></i>
                            <span>Activity &amp; Lifecycle</span>
                        </div>
                        <div class="drawer-activity-grid">
                            <div class="drawer-stat-tile" id="drawerLastLoginRow">
                                <span class="drawer-stat-label" id="drawerLastLoginLabel">
                                    <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Last Login
                                </span>
                                <strong id="drawerLastLogin" class="drawer-stat-value" aria-labelledby="drawerLastLoginLabel">Never</strong>
                            </div>
                            <div class="drawer-stat-tile" id="drawerAutoDisableRow" hidden>
                                <span class="drawer-stat-label" id="drawerAutoDisableLabel">
                                    <i class="bi bi-calendar-x" aria-hidden="true"></i> Scheduled Deactivation
                                </span>
                                <strong id="drawerAutoDisableDate" class="drawer-stat-value" aria-labelledby="drawerAutoDisableLabel"></strong>
                            </div>
                        </div>
                        <p class="field-help" id="drawerPolicyDisabledLine" hidden></p>
                        <small class="field-help" id="drawerDormancyHelp"><?= htmlspecialchars(\App\Authorization\DormancyStatusTab::HELP_LINE) ?></small>
                    </div>
                </div>

                <!-- Panel 3: Security -->
                <div class="user-drawer-panel" id="drawerPanelSecurity" role="tabpanel" aria-labelledby="drawerTabSecurity" tabindex="0" data-user-drawer-panel="security" hidden>
                    <div class="drawer-card">
                        <div class="drawer-card-header">
                            <i class="bi bi-key" aria-hidden="true"></i>
                            <span>Reset Password</span>
                        </div>
                        <div class="form-group">
                            <label for="drawerPassword">New Password</label>
                            <div class="password-field">
                                <input type="password" id="drawerPassword" name="new_password" autocomplete="new-password" minlength="8" placeholder="Enter new password" aria-describedby="drawerPasswordHelp drawerPasswordResetHelp">
                                <button type="button" class="password-toggle" data-password-toggle="drawerPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                            <div class="drawer-password-policy-tags">
                                <span class="policy-pill"><i class="bi bi-check2"></i> 8+ chars</span>
                                <span class="policy-pill"><i class="bi bi-check2"></i> Upper &amp; lower</span>
                                <span class="policy-pill"><i class="bi bi-check2"></i> Number</span>
                            </div>
                            <small class="field-help" id="drawerPasswordHelp">Use at least 8 characters with uppercase, lowercase, and a number.</small>
                            <small class="field-help" id="drawerPasswordResetHelp">A reset revokes active sessions and requires a password change at next login.</small>
                        </div>
                    </div>

                    <div class="drawer-card">
                        <div class="drawer-card-header">
                            <i class="bi bi-shield-lock" aria-hidden="true"></i>
                            <span>Session Management</span>
                        </div>
                        <div class="drawer-action-row">
                            <div class="drawer-action-info">
                                <strong>Active Sessions</strong>
                                <span class="drawer-action-desc">Force log out of all active devices immediately.</span>
                            </div>
                            <button type="submit" form="drawerRevokeForm" class="btn btn-secondary btn-small" id="drawerRevokeButton">
                                <i class="bi bi-door-closed" aria-hidden="true"></i> Revoke Sessions
                            </button>
                        </div>
                    </div>


                </div>
            </div>

            <div class="user-drawer-footer">
                <button type="button" class="btn btn-secondary" id="cancelUserDrawer">Cancel</button>
                <button class="btn btn-primary" type="submit" id="drawerStatusButton">
                    <i class="bi bi-check2" aria-hidden="true"></i> Save Changes
                </button>
            </div>
        </form>

        <form method="POST" class="drawer-modal-actions visually-hidden" id="drawerRevokeForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke_sessions">
            <input type="hidden" name="user_id" id="drawerRevokeUserId">
        </form>

    </aside>
</div>