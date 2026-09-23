<?php
// Live duplicate notice [37/2]: username live notice in create dialog (ticket #39).
//
// Dialog-level contract asserting externally visible behavior wiring only:
// a duplicate username shows a per-field already-in-use notice without submit,
// the colliding field gets invalid styling, Create is disabled while the flag
// is active and re-enabled when clear, the check is debounced on the username
// input, and create-dialog scope holds (edit-account flows unchanged).
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);

    $modal = $read('src/frontend/components/user_manager/modals/add_user_modal.php');
    $page = $read('src/frontend/components/user_manager/user_manager.php');
    $script = $read('src/frontend/assets/js/store-staff-availability.js');
    $editModal = $read('src/frontend/components/user_manager/modals/manage_user_modal.php');

    $assert($modal !== '', 'add_user_modal.php must be readable');
    $assert($script !== '', 'store-staff-availability.js must exist');

    // Per-field already-in-use notice under the username field, without submit.
    $assert(str_contains($modal, 'field-error'), 'create dialog must render a per-field error element for the username notice');
    $assert(
        str_contains(strtolower($modal), 'already in use') || str_contains(strtolower($script), 'already in use'),
        'username duplicate notice copy must state the value is already in use'
    );

    // Colliding field gets invalid styling via the existing form-error pattern.
    $assert(str_contains($script, 'invalid'), 'availability script must mark the colliding username group invalid');
    $assert(
        str_contains($modal, 'createUsername') || str_contains($modal, 'username-availability'),
        'create dialog username field must expose a hook for the live notice'
    );

    // Create is disabled while the duplicate flag is active.
    $assert(str_contains($script, 'disabled'), 'availability script must disable the Create action while the duplicate flag is active');
    $assert(
        str_contains($modal, 'createUserSubmit') || str_contains($modal, 'create-user-submit'),
        'create dialog Create action must expose a hook so it can be disabled'
    );

    // Notice clears and Create re-enables once the value is free, without
    // force-enabling a button the module did not disable.
    $assert(
        substr_count($script, 'disabled') >= 2,
        'availability script must both disable and re-enable the Create action (clear path)'
    );
    $assert(str_contains($script, 'duplicateActive'), 'availability script must track its own duplicate flag before re-enabling Create');
    $assert(str_contains($script, 'openUserModal'), 'availability script must reset its flag when the create dialog reopens');

    // Check runs shortly after typing stops on the username input, not every keystroke.
    $assert(str_contains($script, 'setTimeout'), 'availability script must debounce the live check after typing stops');
    $assert(str_contains($script, 'clearTimeout'), 'availability script must reset the debounce timer on each keystroke');
    $assert(str_contains($script, '300'), 'availability script must wait ~300ms after typing stops before checking');
    $assert(
        str_contains($script, 'action=availability') || str_contains($script, 'availability'),
        'availability script must query the page availability seam'
    );
    $assert(str_contains($script, 'username_taken'), 'availability script must read the username_taken flag');

    // Create-dialog scope only: username input binding lives in the create form.
    $assert(str_contains($script, 'createUserForm'), 'availability script must scope to the create dialog form');
    $assert(!str_contains($script, 'drawerUsername'), 'availability script must not touch the edit-account username field');
    $assert(!str_contains($script, 'drawerEmail'), 'availability script must not touch the edit-account email field');
    $assert(!str_contains($editModal, 'field-error'), 'edit-account dialog must stay unchanged (no live notice hooks)');

    // Page wires the script; submit-time fallback remains the enforcer.
    $assert(
        str_contains($page, 'store-staff-availability.js'),
        'Store Staff page must load store-staff-availability.js'
    );
    $assert(
        str_contains($page, 'Username or email may already exist'),
        'submit-time duplicate rejection must remain as the enforcing fallback'
    );
} catch (Throwable $exception) {
    $failures[] = 'Store Staff username notice contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Store Staff username notice contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Store Staff username notice contract: passed\n";
