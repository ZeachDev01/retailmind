<div class="user-modal-overlay" id="userModalOverlay" aria-hidden="true">
    <div class="user-modal" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
        <div class="user-modal-header">
            <div><h3 id="userModalTitle"><?= $actorRole === 'super_admin' ? 'Add Administrator' : 'Add Store Staff' ?></h3><p><?= $actorRole === 'super_admin' ? 'Create the Store Administrator account.' : 'Create an account with one or more workspaces.' ?></p></div>
            <button type="button" class="user-modal-close" id="closeUserModal" aria-label="Close add user form">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="user-form" id="createUserForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group" id="createUsernameGroup"><label for="createUsernameInput">Username</label><input id="createUsernameInput" name="username" value="<?= htmlspecialchars($createFormValues['username'], ENT_QUOTES, 'UTF-8') ?>" required autocomplete="off" spellcheck="false" aria-describedby="createUsernameHelp createUsernameNotice"><small class="field-help" id="createUsernameHelp">Usernames cannot contain @ or spaces. Use letters, numbers, dots, underscores, and hyphens.</small><small class="field-error" id="createUsernameNotice" aria-live="polite">This username is already in use.</small></div>
            <div class="form-group"><label for="createFirstName">First Name</label><input id="createFirstName" name="first_name" maxlength="50" autocomplete="given-name" value="<?= htmlspecialchars($createFormValues['first_name'], ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="form-group"><label for="createLastName">Last Name</label><input id="createLastName" name="last_name" maxlength="50" autocomplete="family-name" value="<?= htmlspecialchars($createFormValues['last_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="duplicate-summary" id="createDuplicateSummary" role="alert" hidden>An account with this username or email already exists.</div>
            <div class="form-group" id="createEmailGroup"><label for="createEmailInput">Email</label><input type="email" id="createEmailInput" name="email" value="<?= htmlspecialchars($createFormValues['email'], ENT_QUOTES, 'UTF-8') ?>" <?= $actorRole === 'super_admin' ? 'required' : '' ?> autocomplete="email" spellcheck="false" aria-describedby="createEmailNotice"><small class="field-error" id="createEmailNotice" aria-live="polite">This email is already in use.</small></div>
            <div class="form-group">
                <label for="createProfileImage">Profile Picture <span class="optional-label">Optional</span></label>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= \App\Services\ProfileImageStorage::MAX_FILE_SIZE ?>">
                <input type="file" id="createProfileImage" name="profile_image" accept="<?= \App\Services\ProfileImageStorage::ACCEPT ?>" aria-describedby="createProfileImageHelp">
                <small class="field-help" id="createProfileImageHelp"><?= \App\Services\ProfileImageStorage::HELP ?></small>
            </div>
            <div class="form-group">
                <label for="createUserPassword">Password</label>
                <div class="password-field"><input type="password" name="password" id="createUserPassword" autocomplete="new-password" required minlength="8" aria-describedby="createPasswordHelp" data-password-criteria="createPasswordCriteria"><button type="button" class="password-toggle" data-password-toggle="createUserPassword" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                <small class="field-help" id="createPasswordHelp">Use at least 8 characters with uppercase, lowercase, and a number.</small>
                <div class="cp-meter-container" id="createPasswordCriteria" aria-hidden="true">
                    <div class="cp-meter-bars">
                        <span class="cp-meter-bar" data-strength-bar></span>
                        <span class="cp-meter-bar" data-strength-bar></span>
                        <span class="cp-meter-bar" data-strength-bar></span>
                        <span class="cp-meter-bar" data-strength-bar></span>
                    </div>
                    <div class="cp-requirements">
                        <span class="cp-req-chip" data-rule="len"><i class="bi bi-circle"></i> 8+ chars</span>
                        <span class="cp-req-chip" data-rule="upper"><i class="bi bi-circle"></i> Uppercase</span>
                        <span class="cp-req-chip" data-rule="lower"><i class="bi bi-circle"></i> Lowercase</span>
                        <span class="cp-req-chip" data-rule="num"><i class="bi bi-circle"></i> Number</span>
                    </div>
                </div>
            </div>
            <div class="form-group" role="group" aria-labelledby="createRolesLabel" aria-describedby="createRolesHelp">
                <label id="createRolesLabel">Role access</label>
                <?php if ($actorRole === 'super_admin'): ?>
                    <?php $createdRole = $roles[0] ?? null; ?>
                    <?php if ($createdRole !== null): ?>
                        <input type="hidden" name="role_ids[]" value="<?= (int)$createdRole['role_id'] ?>">
                        <div class="role-check-list">
                            <label class="role-check"><input type="checkbox" checked disabled> <span><?= htmlspecialchars(display_label((string)$createdRole['role_name'])) ?></span></label>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="role-check-list">
                        <?php foreach ($roles as $r): ?>
                            <?php if (!in_array($r['role_name'], ['super_admin', 'seller'], true)): ?>
                                <label class="role-check"><input type="checkbox" name="role_ids[]" value="<?= (int)$r['role_id'] ?>" <?= in_array((string)$r['role_id'], $createFormValues['role_ids'], true) ? ' checked' : '' ?>> <span><?= htmlspecialchars(display_label((string)$r['role_name'])) ?></span></label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <small class="field-help" id="createRolesHelp"><?= $actorRole === 'super_admin'
                    ? "Super Administrators create Administrator accounts. Administrators manage staff Workspaces."
                    : 'Select every workspace this user can access. The first selected role becomes the default workspace.' ?></small>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelUserModal">Cancel</button>
                <button class="btn manage-users-primary" type="submit" id="createUserSubmit"><i class="bi bi-person-plus" aria-hidden="true"></i> Create User</button>
            </div>
        </form>
    </div>
</div>
