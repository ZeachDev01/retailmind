<?php
// Backup Keyring UI + recovery-key recommendation contract (#460, stories 21-36, 44-45).
//
// Static and needle checks: the key endpoint stays the single guarded download
// path, key material never reaches URLs, filenames, logs, or page copy, and the
// recommendation banner/modal is rendered from server state on both backup pages.
$root = dirname(__DIR__, 3);
$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return $contents === false ? '' : $contents;
};

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$endpoint = $read('src/frontend/components/backup/recovery_key.php');
$form = $read('src/frontend/components/backup/recovery_key_form.php');
$banner = $read('src/frontend/components/backup/recovery_key_recommendation.php');
$script = $read('src/frontend/assets/js/backup_key_recommendation.js');
$administratorPage = $read('src/frontend/components/administrator/database_backup.php');
$superAdministratorPage = $read('src/frontend/components/system_administrator/backup_restore.php');

foreach ([
    'recovery-key endpoint' => $endpoint,
    'recovery-key form' => $form,
    'recommendation partial' => $banner,
    'recommendation script' => $script,
    'Administrator backup page' => $administratorPage,
    'Super Administrator backup page' => $superAdministratorPage,
] as $name => $source) {
    $assert($source !== '', ucfirst($name) . ' must be readable');
}

// --- endpoint: one guarded path, every action password-authorized -----------
$assert(str_contains($endpoint, 'require_capability(RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP)'),
    'The key endpoint must require the backup capability on every request');
$assert(str_contains($endpoint, 'require_local_request('),
    'The key endpoint must reject remote monitoring requests');
$assert(str_contains($endpoint, "\$_SERVER['REQUEST_METHOD'] !== 'POST'")
    && str_contains($endpoint, "header('Allow: POST')"),
    'The key endpoint must stay POST-only with an Allow header');
$assert(str_contains($endpoint, 'csrf_verify()'),
    'The key endpoint must verify CSRF on every action');
$assert(str_contains($endpoint, "header('Cache-Control: no-store')"),
    'The key endpoint response must never be cached');
$assert(str_contains($endpoint, 'authorizeActor((int)$_SESSION[\'user_id\'], (string)current_role())'),
    'The key endpoint must revalidate the active account and workspace');
$assert(str_contains($endpoint, 'password_verify($password, (string)$actor[\'password_hash\'])'),
    'Every key action must verify the current login password against the live account');
$assert(str_contains($endpoint, "\$allowedActions = ['download', 'export', 'import', 'promote', 'delete'];")
    && str_contains($endpoint, 'in_array($action, $allowedActions, true)'),
    'The key endpoint must answer only the documented action allow-list');
$assert(str_contains($endpoint, "preg_match('/^[a-f0-9]{64}$/D', \$fingerprint)"),
    'The key endpoint must validate the fingerprint before resolving a key');
$assert(str_contains($endpoint, 'BackupKeyStore::find($fingerprint)'),
    'Exports must resolve the requested key by fingerprint');
$assert(str_contains($endpoint, 'BackupKeyStore::currentFingerprint()'),
    'The legacy download action must alias the primary fingerprint');
$assert(str_contains($endpoint, 'BackupKeyStore::import(')
    && str_contains($endpoint, 'BackupKeyStore::promote(')
    && str_contains($endpoint, 'BackupKeyStore::delete('),
    'Import, promotion, and deletion must delegate to the key store');
$assert(str_contains($endpoint, "(\$_POST['confirm_delete'] ?? '') !== '1'"),
    'Deleting a key must require the explicit warning checkbox');
$assert(str_contains($endpoint, 'connection_aborted()'),
    'The export stream must stop before acknowledging an aborted download');
$assert(str_contains($endpoint, '->acknowledge($actorUserId, $fingerprint)'),
    'Acknowledgement must happen only after the full stream');
$assert(!str_contains($endpoint, '?key=') && !str_contains($endpoint, '$_GET['),
    'Key material and references must travel in the POST body, never the URL');
$assert(str_contains($endpoint, "substr(\$fingerprint, 0, 8)"),
    'The download filename may carry only the fingerprint prefix, never key material');
$assert(str_contains($endpoint, 'Content-Length')
    && str_contains($endpoint, 'X-Content-Type-Options: nosniff')
    && str_contains($endpoint, 'Cache-Control: private, no-store'),
    'Every key download must be sized, non-sniffable, and uncached');
$assert(str_contains($endpoint, ", true, 303)"),
    'Non-stream actions must redirect back to the role page with 303');

foreach ([
    "'Recovery key imported'",
    "'Recovery key exported'",
    "'Recovery key promoted'",
    "'Recovery key deleted'",
] as $action) {
    $assert(str_contains($endpoint, $action), "Key custody changes must be activity-logged: {$action}");
}
$assert(str_contains($endpoint, "'previous_fingerprint'"),
    'Promotion logging must record the previous fingerprint');
$assert(!preg_match("/'key_password'\s*=>/", $endpoint),
    'Activity metadata must never include the login password');
$assert(!str_contains($endpoint, 'base64_encode') && !str_contains($endpoint, 'bin2hex'),
    'The endpoint must never encode key material into a response');

// --- Backup Keyring table: every key listed, every mutation guarded ---------
$assert(str_contains($form, "\$keyringKeys = \$keyringKeys ?? \\App\\Backup\\BackupKeyStore::list();"),
    'The form must default the keyring list without breaking DB-less render harnesses');
$assert(str_contains($form, 'Backup Keyring'), 'The recovery-key area must name the Backup Keyring');
$assert(str_contains($form, "title=\"<?= \$escape(\$key['fingerprint']) ?>\""),
    'Each row must expose the full fingerprint on hover without printing key material');
$assert(str_contains($form, "'Primary'") && str_contains($form, "'Imported'") && str_contains($form, "'Archived'"),
    'The keyring must label primary, imported, and archived keys');
$assert(str_contains($form, "name=\"key_action\" value=\"promote\"")
    && str_contains($form, "name=\"key_action\" value=\"export\"")
    && str_contains($form, "name=\"key_action\" value=\"delete\""),
    'Each keyring row must offer promote, export, and delete actions');
$assert(str_contains($form, 'data-password-authorization'),
    'Every keyring action must use the shared password authorization dialog');
$assert(preg_match_all('/<button\b(?![^>]*\btype="(?:button|submit|reset)")[^>]*>/i', $form) === 0,
    'Every keyring button must declare an explicit type');
$assert(str_contains($form, 'name="confirm_delete" value="1"') && str_contains($form, 'required'),
    'Deleting a key must require the explicit warning checkbox');
$assert(str_contains($form, 'Promote another key before deleting this one.'),
    'The primary row must explain why its delete action is disabled');
$assert(str_contains($form, 'disabled') && str_contains($form, 'primary-key-locked'),
    'The primary delete action must be disabled and described');

// Each key action form carries exactly one labelled password field.
preg_match_all('/<form\b[^>]*>.*?<\/form>/s', $form, $formMatches);
$assert(count($formMatches[0]) >= 5, 'The keyring must render the import, download, and per-key forms');
foreach ($formMatches[0] as $index => $markup) {
    $assert(substr_count($markup, 'type="password"') === 1,
        'Key form #' . ($index + 1) . ' must contain exactly one password field');
    if (str_contains($markup, 'type="password"')) {
        $assert(preg_match('/<label\b[^>]*\bfor="[^"]+"/', $markup) === 1,
            'Key form #' . ($index + 1) . ' must label its password field');
    }
}
foreach (['key-promote-password-', 'key-export-password-', 'key-delete-password-'] as $prefix) {
    $assert(str_contains($form, 'id="' . $prefix), "Per-key password ids must be unique: {$prefix}");
}
$assert(str_contains($form, "\$escape(\$key['fingerprint'])") && !str_contains($form, 'BACKUP_ENCRYPTION_KEY'),
    'Keyring markup must never surface key configuration');

// --- recommendation banner + once-per-session modal ------------------------
$assert(str_contains($banner, "\$backupRecommendation = \$backupRecommendation ?? null;"),
    'The recommendation partial must tolerate pages and harnesses without a recommendation');
$assert(str_contains($banner, '\\App\\Backup\\RecoveryStore::isPaused()'),
    'The recommendation must stay hidden while recovery is paused');
$assert(str_contains($banner, 'data-backup-key-recommendation'),
    'The banner must be discoverable by the recommendation script');
$assert(str_contains($banner, 'data-backup-key-dialog') && str_contains($banner, '<dialog'),
    'The recommendation must include a native informational dialog');
$assert(str_contains($banner, "name=\"key_action\" value=\"export\"")
    && str_contains($banner, "name=\"key_fingerprint\" value=\"<?= \$escape(\$recommendedFingerprint) ?>\""),
    'The banner download must export the recommended fingerprint through the shared endpoint');
$assert(str_contains($banner, 'data-password-authorization'),
    'The banner download must require the current login password');
$assert(substr_count($banner, 'type="password"') === 1,
    'The banner must collect exactly one password field');
$assert(str_contains($banner, 'href="#recovery-key"'),
    'The recommendation must link to the existing Recovery key area, not a second download path');
$assert(str_contains($banner, 'substr($recommendedFingerprint, 0, 8)') && str_contains($banner, 'title="<?= $escape($recommendedFingerprint) ?>"'),
    'The banner must identify the key by fingerprint without exposing key material');
$assert(str_contains($banner, 'csrf_field()'), 'The banner download form must carry CSRF');

$assert(str_contains($script, 'sessionStorage'), 'The recommendation modal must be limited per session');
$assert(str_contains($script, "'retailmind.backup-key-recommendation.'"),
    'The session flag must be scoped to the fingerprint');
$assert(str_contains($script, 'showModal()'), 'The modal must use the native dialog API');
$assert(str_contains($script, "'cancel'"), 'The modal must close on Escape/cancel');
$assert(!str_contains($script, 'fetch(') && !str_contains($script, 'XMLHttpRequest'),
    'The recommendation script must make no network calls');

foreach (['Administrator page' => $administratorPage, 'Super Administrator page' => $superAdministratorPage] as $name => $source) {
    $assert(str_contains($source, '(new BackupKeyRecommendation($pdo))->recommendation((int)$_SESSION[\'user_id\'], (string)current_role())'),
        ucfirst($name) . ' must compute the recommendation for the signed-in account');
    $assert(str_contains($source, "'/../backup/recovery_key_recommendation.php'"),
        ucfirst($name) . ' must render the shared recommendation partial');
    $assert(str_contains($source, 'backup_key_recommendation.js'),
        ucfirst($name) . ' must load the recommendation script');
    $reviewAt = strpos($source, 'backup_review.js');
    $recommendationAt = strpos($source, 'backup_key_recommendation.js');
    $assert($reviewAt !== false && $recommendationAt !== false && $recommendationAt > $reviewAt,
        ucfirst($name) . ' must load the recommendation script after backup_review.js');
    $headingAt = strpos($source, 'class="page-heading"');
    $bannerAt = strpos($source, "'/../backup/recovery_key_recommendation.php'");
    $summaryAt = strpos($source, 'class="backup-summary"');
    $assert($headingAt !== false && $bannerAt !== false && $summaryAt !== false
        && $headingAt < $bannerAt && $bannerAt < $summaryAt,
        ucfirst($name) . ' must render the recommendation between the heading and the summary');
}

if ($failures) {
    fwrite(STDERR, "Backup Keyring UI contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Backup Keyring UI contract: passed\n";
