<?php
// Recovery exclusion, independent email reset, status and audit (ticket #45, part of #42).
// The Recovery Account stays on the offline recovery procedure in every
// password flow, the logged-out email reset completes in one step without a
// second forced step, staff password status reads Change required versus
// Current, and forced completions plus Administrator resets are recorded as
// Protected Audit Records.
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

require_once __DIR__ . '/../app/Audit/AuditRecordCategory.php';

use App\Audit\AuditRecordCategory;

$root = dirname(__DIR__, 3);
$read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);

$change = $read('src/frontend/components/auth/change_password.php');
$forgot = $read('src/frontend/components/auth/forgot_password.php');
$reset = $read('src/frontend/components/auth/reset_password.php');

// Recovery Account is rejected from ordinary change and email reset with
// direction to the offline procedure.
$assert(
    str_contains($change, 'Recovery Account credentials can be rotated only through the offline recovery procedure.'),
    'ordinary change must reject the Recovery Account with direction to the offline procedure'
);
$assert(
    str_contains($forgot, 'is_recovery_account'),
    'forgot-password must exclude the Recovery Account from email reset'
);
$assert(
    str_contains($reset, 'is_recovery_account'),
    'email-link reset must exclude the Recovery Account from email reset'
);

// Email link reset is single-use, time-bound, finishes directly, and clears
// the forced flag.
$assert(str_contains($reset, 'used_at IS NULL'), 'email reset must accept only unused tokens (single-use)');
$assert(str_contains($reset, 'UPDATE password_reset_tokens SET used_at'), 'email reset must consume the token on success (single-use)');
$assert(str_contains($reset, 'expires_at'), 'email reset must stay time-bound');
$assert(str_contains($reset, 'must_change_password = 0'), 'email reset must clear the Temporary Password flag');
$assert(str_contains($reset, 'session_version = session_version + 1'), 'email reset must revoke other sessions');
$assert(str_contains($reset, '?login=1'), 'email reset must finish directly at the login step');
$assert(!str_contains($reset, 'change_password.php'), 'email reset must not pass through a second forced-change step');

// Status indicator distinguishes Change required from Current for
// Administrator oversight.
foreach ([
    'src/frontend/components/user_manager/user_manager.php' => 'Store Staff workspace',
    'src/backend/legacy/routes/admin/manage_users.php' => 'legacy Manage Users route',
] as $entryPoint => $label) {
    $source = $read($entryPoint);
    $assert(str_contains($source, 'Change required'), "{$label} must show a Change required password status");
    $assert(str_contains($source, 'Current'), "{$label} must show a Current password status");
    $assert(str_contains($source, 'must_change_password'), "{$label} must derive password status from the Temporary Password flag");
}

// Completion and reset events appear as immutable Protected Audit Records
// with correct visibility.
$assert(
    str_contains($change, 'Mandatory password change completed'),
    'mandatory completion must be recorded as a Protected Audit Record'
);
$assert(
    str_contains($change, 'Voluntary password change completed') || str_contains($change, 'Password change completed'),
    'voluntary completion must be recorded as a Protected Audit Record'
);
$assert(
    str_contains($reset, 'log_activity') && str_contains($reset, 'User Access'),
    'email-reset completion must be recorded under User Access'
);
$lifecycle = $read('src/backend/app/Services/UserLifecycleService.php');
$assert(
    str_contains($lifecycle, "'Password reset'"),
    'Administrator resets must be recorded as Protected Audit Records'
);
$assert(
    AuditRecordCategory::classify('Authentication', 'Mandatory password change completed') === AuditRecordCategory::SECURITY,
    'forced-change completion must be categorized as security'
);
$assert(
    AuditRecordCategory::classify('User Access', 'Password reset completed via email link', ['role' => 'cashier']) === AuditRecordCategory::STORE_OPERATION,
    'delegated email-reset completion must stay visible to the Administrator'
);
$assert(
    AuditRecordCategory::classify('User Access', 'Password reset', ['target_role' => 'inventory_manager']) === AuditRecordCategory::STORE_OPERATION,
    'delegated Administrator resets must stay visible to the Administrator'
);

if ($failures) {
    fwrite(STDERR, "Recovery exclusion reset audit contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Recovery exclusion reset audit contract: passed\n";
