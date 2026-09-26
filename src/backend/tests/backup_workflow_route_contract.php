<?php
// HTTP entry-point contract for the shared Database Backup workflow (#69).
//
// The workflow-level integration test proves behaviour at the service seam.
// This contract makes sure a service-level permission cannot hide an unguarded
// page: every create, download, history, and restore route must authorize on
// its own, keep session validation and CSRF for state-changing requests, and
// never hand out a readable full-database copy.
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

$administratorPage = $read('src/frontend/components/administrator/database_backup.php');
$superAdministratorPage = $read('src/frontend/components/system_administrator/backup_restore.php');
$legacyRoute = $read('src/backend/legacy/routes/admin/backup_restore.php');
$downloadEndpoint = $read('src/frontend/components/backup/backup_download.php');
$statusEndpoint = $read('src/frontend/components/backup/backup_status.php');
$sidebar = $read('src/frontend/components/sidebar.php');
$scheduledScript = $read('src/backend/scripts/backup_database.php');
$sharedModule = $read('src/backend/includes/backup.php');
$envExample = $read('.env.example');
$runAll = $read('src/backend/tests/run_all.sh');

foreach ([
    'Administrator page' => $administratorPage,
    'Super Administrator page' => $superAdministratorPage,
    'legacy route' => $legacyRoute,
    'download endpoint' => $downloadEndpoint,
    'status endpoint' => $statusEndpoint,
] as $name => $source) {
    $assert($source !== '', ucfirst($name) . ' must be readable');
}

// --- every entry point authorizes, rather than relying on hidden controls ----
$assert(
    str_contains($administratorPage, 'require_capability(\\App\\Authorization\\RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP)'),
    'Administrator page must require the shared backup capability'
);
$assert(
    str_contains($superAdministratorPage, 'require_capability(\\App\\Authorization\\RoleCapabilityPolicy::PLATFORM_GOVERNANCE)'),
    'Super Administrator page must require platform governance'
);
$assert(
    str_contains($legacyRoute, 'require_capability(RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP)'),
    'The legacy reachable route must guard the page itself'
);
$assert(
    substr_count($legacyRoute, 'require_capability(RoleCapabilityPolicy::PLATFORM_GOVERNANCE)') === 1,
    'The legacy route must guard the restore action separately, not rely on the page guard'
);
$assert(
    str_contains($downloadEndpoint, 'require_any_capability([\\App\\Authorization\\RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP])'),
    'The download endpoint must authorize the backup capability on every request'
);
$assert(
    str_contains($downloadEndpoint, 'validate_current_session'),
    'The download endpoint must keep session validation'
);
$assert(
    str_contains($downloadEndpoint, 'resolveDownload')
        && str_contains($downloadEndpoint, "\$_SESSION['user_id']"),
    'The temporary download must be bound to the administrator that created it'
);
$assert(
    str_contains($downloadEndpoint, 'discardAfterDelivery'),
    'The temporary artifact must be cleaned up only after delivery'
);

// --- state-changing requests keep CSRF protection ---------------------------
foreach (['Administrator page' => $administratorPage, 'Super Administrator page' => $superAdministratorPage, 'legacy route' => $legacyRoute] as $name => $source) {
    $assert(str_contains($source, 'csrf_verify()'), ucfirst($name) . ' must verify CSRF on state-changing requests');
    $assert(str_contains($source, 'csrf_field()'), ucfirst($name) . ' must render the CSRF field');
}

// --- no readable full-database download, and no key material in responses ---
$backupPages = ['Administrator page' => $administratorPage, 'Super Administrator page' => $superAdministratorPage, 'legacy route' => $legacyRoute];
foreach ($backupPages as $name => $source) {
    $assert(!str_contains($source, 'application/sql'), ucfirst($name) . ' must not offer a plain SQL download');
    $assert(!str_contains($source, 'readfile('), ucfirst($name) . ' must not stream a readable copy directly');
    $assert(!str_contains($source, 'BACKUP_ENCRYPTION_KEY'), ucfirst($name) . ' must never surface key configuration to the operator');
    $assert(
        str_contains($source, "DatabaseBackupService::downloadFilename()") || str_contains($source, '.rmbak'),
        ucfirst($name) . ' must hand out the encrypted envelope'
    );
    $assert(
        str_contains($source, 'does not confirm the file') || str_contains($source, 'it does not confirm'),
        ucfirst($name) . ' must state that creation is not proof of local retention'
    );
}
$assert(
    str_contains($downloadEndpoint, 'application/octet-stream'),
    'The download must be delivered as an opaque attachment'
);
$assert(
    str_contains($sharedModule, 'DatabaseSnapshotWriter'),
    'The shared module must reuse the single snapshot writer'
);
$assert(
    str_contains($scheduledScript, 'createForSystem()'),
    'The scheduled script must share the encrypted workflow instead of a second plaintext exporter'
);
$assert(
    !str_contains($scheduledScript, 'create_database_backup'),
    'The scheduled script must not keep a separate plaintext exporter'
);
$assert(
    str_contains($envExample, 'BACKUP_ENCRYPTION_KEY=') && str_contains($envExample, 'no plaintext'),
    'The deployment template must document the recovery key and the fail-closed behaviour'
);

// --- Administrator-facing navigation: backup yes, restoration no -------------
$assert(
    str_contains($sidebar, "'components/administrator/database_backup.php'"),
    'Administrator navigation must offer the shared Database Backup page'
);
foreach (['confirm_restore', 'backup_file'] as $forbidden) {
    $assert(
        !str_contains($administratorPage, $forbidden),
        "Administrator page must not expose restore control: {$forbidden}"
    );
}
$assert(
    !str_contains($administratorPage, 'DatabaseRestoreService'),
    'Administrator page must not wire the Database Restore service at all'
);
$assert(
    str_contains($superAdministratorPage, 'DatabaseRestoreService'),
    'The Super Administrator page must keep the Database Restore workflow'
);
$assert(
    str_contains($superAdministratorPage, 'confirm_restore'),
    'Destructive restore must keep its explicit confirmation'
);

// --- active-user notice, and the paused save behaviour ----------------------
$assert(
    str_contains($sidebar, 'data-rm-backup-status') && str_contains($sidebar, 'backup_status.js'),
    'Every signed-in shell must receive the shared backup status'
);
$assert(
    str_contains($statusEndpoint, "Content-Type: application/json") && str_contains($statusEndpoint, 'is_logged_in()'),
    'The status endpoint must answer signed-in sessions only and stay read-only'
);
$assert(
    !str_contains($statusEndpoint, 'artifact_path') && !str_contains($statusEndpoint, 'operation_key'),
    'The status endpoint must not leak artifact identity or key material'
);

// --- the feature stays in the test suite -----------------------------------
foreach ([
    'php src/backend/tests/database_backup_workflow_integration.php',
    'php src/backend/tests/backup_workflow_route_contract.php',
    'node src/backend/tests/backup_status_alert_behavior_test.js',
] as $command) {
    $assert(str_contains($runAll, $command), "The full suite must run: {$command}");
}

if ($failures) {
    fwrite(STDERR, "Backup workflow route contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Backup workflow route contract: passed\n";
