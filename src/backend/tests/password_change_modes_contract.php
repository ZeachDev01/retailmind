<?php
// Dual-mode Change screen contract (ticket #44, part of #42).
// Mandatory mode when the Temporary Password flag is set (forced copy +
// navigation blocking); Voluntary mode when reached from Profile with the
// flag unset (standard copy, no blocking). Both modes share validation,
// clear the flag, revoke other sessions, keep the current session, and
// land in the role workspace.
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 3);
$read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);

$change = $read('src/frontend/components/auth/change_password.php');
$auth = $read('src/backend/includes/auth.php');

// Seam 1: session validation gate is the single enforcement point.
$assert(
    str_contains($auth, "in_array(\$currentScript, ['change_password.php', 'logout.php']"),
    'session gate must allow only the change screen and logout when the flag is set'
);
$assert(
    str_contains($auth, "header('Location: ' . app_url('components/auth/change_password.php'))"),
    'session gate must redirect every other authenticated page to the change screen'
);

// Seam 2: mode derived from the flag at render time.
$assert(
    str_contains($change, "must_change_password"),
    'change screen must derive its mode from the Temporary Password flag at render time'
);

// Mandatory copy stays forced; voluntary copy must not reuse the forced sentence.
$assert(
    str_contains($change, 'The temporary password must be replaced before you can access RetailMind.'),
    'mandatory mode must keep the forced copy'
);
$hasVoluntaryBranch = preg_match('/must_change_password.*\?.*:|if\s*\(.*must_change_password/s', $change) === 1
    && stripos($change, 'voluntar') !== false;
$assert($hasVoluntaryBranch, 'change screen must render distinct voluntary copy when the flag is unset');

// Shared interaction: all four rejections work in both modes (single code path).
foreach ([
    'The current password is incorrect.' => 'current-password verification',
    'The new passwords do not match.' => 'confirmation-match rejection',
    'Choose a new password that is different from the current password.' => 'reuse rejection',
    'password_policy_error' => 'unified policy enforcement',
] as $needle => $label) {
    $assert(str_contains($change, $needle), "change screen must keep {$label} in both modes");
}

// Success: clears flag, revokes others, keeps current session, lands in workspace.
$assert(str_contains($change, 'must_change_password = 0'), 'successful change must clear the Temporary Password flag');
$assert(str_contains($change, 'session_version'), 'successful change must revoke other sessions via session_version');
$assert(str_contains($change, "\$_SESSION['session_version'] = \$newVersion"), 'successful change must preserve the current session');
$assert(str_contains($change, 'redirect_by_role()'), 'successful change must land in the role workspace');

// Audit distinguishes the two completions while staying in Protected Audit Records.
$assert(
    str_contains($change, 'Mandatory password change completed'),
    'mandatory completion must be recorded as a Mandatory password change'
);
$assert(
    str_contains($change, 'Voluntary password change completed') || str_contains($change, 'Password change completed'),
    'voluntary completion must be recorded distinctly from the mandatory gate'
);

// Recovery Account stays on the offline procedure in this flow too.
$assert(
    str_contains($change, 'Recovery Account credentials can be rotated only through the offline recovery procedure.'),
    'change screen must keep the Recovery Account on the offline procedure'
);

// Voluntary entry point: reachable from Profile with no blocking of its own.
$profile = $read('src/frontend/components/auth/user_info.php');
$assert(
    str_contains($profile, 'components/auth/change_password.php'),
    'voluntary change must stay reachable from the Profile'
);

if ($failures) {
    fwrite(STDERR, "Password change modes contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Password change modes contract: passed\n";
