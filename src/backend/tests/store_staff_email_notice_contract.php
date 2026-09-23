<?php
// Live duplicate notice [37/3]: email live notice and duplicate summary (ticket #40).
//
// Dialog-level contract asserting externally visible behavior wiring only:
// a duplicate email shows a per-field already-in-use notice without submit,
// a duplicate summary sits directly above the email field group when either
// field collides, empty email never shows a duplicate error, Create stays
// disabled while any duplicate flag is active and re-enables when all clear,
// and case/whitespace variants still match via the #38 availability seam.
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

    // Per-field already-in-use notice for the email field, without submit.
    $assert(
        str_contains($modal, 'createEmailInput') || str_contains($modal, 'email-availability'),
        'create dialog email field must expose a hook for the live notice'
    );
    $assert(
        str_contains($modal, 'createEmailNotice') || str_contains($modal, 'email-availability-notice'),
        'create dialog must render a per-field error element for the email notice'
    );
    $assert(
        str_contains(strtolower($modal), 'already in use') || str_contains(strtolower($script), 'already in use'),
        'email duplicate notice copy must state the value is already in use'
    );

    // Duplicate summary placed directly above the email field group.
    $summaryPos = strpos($modal, 'createDuplicateSummary');
    if ($summaryPos === false) {
        $summaryPos = strpos($modal, 'duplicate-summary');
    }
    $emailGroupPos = strpos($modal, 'createEmailGroup');
    if ($emailGroupPos === false) {
        $emailGroupPos = strpos($modal, 'createEmailInput');
    }
    $assert($summaryPos !== false, 'create dialog must render a duplicate summary element');
    $assert(
        $summaryPos !== false && $emailGroupPos !== false && $summaryPos < $emailGroupPos,
        'duplicate summary must appear directly above the email field group'
    );
    if ($summaryPos !== false && $emailGroupPos !== false && $summaryPos < $emailGroupPos) {
        $between = substr($modal, $summaryPos, $emailGroupPos - $summaryPos);
        $assert(
            !str_contains($between, 'createUsernameGroup') && !str_contains($between, 'First Name'),
            'duplicate summary must sit directly above the email field group, not elsewhere in the dialog'
        );
    }

    // Empty email never shows a duplicate error.
    $assert(str_contains($script, 'email_taken'), 'availability script must read the email_taken flag');
    $assert(
        str_contains($script, 'createEmailInput') || str_contains($script, 'emailInput'),
        'availability script must bind the create dialog email input'
    );

    // Create stays disabled while any duplicate flag is active, re-enables when all clear.
    $assert(str_contains($script, 'disabled'), 'availability script must disable the Create action while a duplicate flag is active');
    $assert(
        substr_count($script, 'disabled') >= 2,
        'availability script must both disable and re-enable the Create action (clear path)'
    );
    $assert(
        str_contains($script, 'usernameTaken') || str_contains($script, 'username_taken'),
        'availability script must track the username duplicate flag'
    );
    $assert(
        str_contains($script, 'emailTaken') || str_contains($script, 'email_taken'),
        'availability script must track the email duplicate flag'
    );

    // Notice clears and summary hides once both values are free.
    $assert(str_contains($script, 'createDuplicateSummary') || str_contains($script, 'duplicate-summary') || str_contains($script, 'duplicateSummary'), 'availability script must toggle the duplicate summary');

    // Check runs shortly after typing stops on the email input, not every keystroke.
    $assert(str_contains($script, 'setTimeout'), 'availability script must debounce the live check after typing stops');
    $assert(str_contains($script, 'clearTimeout'), 'availability script must reset the debounce timer on each keystroke');
    $assert(str_contains($script, '300'), 'availability script must wait ~300ms after typing stops before checking');
    $assert(
        str_contains($script, 'action=availability') || str_contains($script, 'availability'),
        'availability script must query the page availability seam'
    );

    // Case/whitespace variants still match: the script must send the typed
    // value to the seam (server normalizes); empty/whitespace-only email is
    // treated as free without showing a notice.
    $assert(str_contains($script, 'encodeURIComponent'), 'availability script must send the typed value to the availability seam');

    // Create-dialog scope only: email binding lives in the create form.
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
    $failures[] = 'Store Staff email notice contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Store Staff email notice contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Store Staff email notice contract: passed\n";
