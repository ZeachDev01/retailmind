<?php
if (getenv('RUN_DB_TESTS') !== '1') { echo "Identity Reconciliation MySQL: skipped (RUN_DB_TESTS=1 required)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$tmpRecovery = sys_get_temp_dir() . '/rm_rec_test_' . bin2hex(random_bytes(6));
mkdir($tmpRecovery, 0700, true);
putenv('BACKUP_STORAGE_PATH=' . $tmpRecovery);
$_ENV['BACKUP_STORAGE_PATH'] = $tmpRecovery;
$fixture = static function (string $action) use ($database): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/support/theme_fixture.php', $action, $database], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($errors . $output);
};
$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$failure = null;
try {
    $fixture('create');
    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $gcashJson = '{"cashier_id":4,"cashier_name":"Frozen cashier","transaction_id":1}';
    $pdo->prepare("INSERT INTO gcash_transactions (transaction_id,cashier_id,transaction_type,amount,verification_status,receipt_details_json) VALUES (1,4,'Cash-In',100,'Completed',?)")->execute([$gcashJson]);
    $pdo->exec("INSERT INTO activity_log (log_id,user_id,action,category,module,metadata) VALUES (200,4,'Historical approval','store_operation','Sales','{\"nested\":{\"actor_id\":4}}')");

    // Seed destination classified state (AC 2, AC 3)
    $pdo->exec("INSERT INTO platform_settings (setting_key,setting_value,updated_by) VALUES ('site_theme','classic',2)");
    $pdo->exec("INSERT INTO attention_settings (setting_scope,setting_key,setting_value,updated_by,updated_at) VALUES ('platform','backup_watermark','72',2,NOW())");
    $pdo->exec("INSERT INTO ml_settings (setting_key,setting_value,updated_by) VALUES ('forecast_engine','rf_v1',2)");
    $pdo->exec("INSERT INTO login_attempts (username,ip_address) VALUES ('probe_attacker','10.0.0.1')");
    $pdo->exec("INSERT INTO password_reset_tokens (token_hash,user_id,expires_at) VALUES ('tok_super_admin',2,DATE_ADD(NOW(),INTERVAL 1 HOUR))");
    $pdo->exec("INSERT INTO notification_preferences (user_id,notify_low_stock,notify_inapp) VALUES (2,1,1)");
    $pdo->exec("INSERT INTO notifications (user_id,type,title,message) VALUES (2,'system','Security Alert','Destination protected alert')");
    $pdo->exec("INSERT INTO attention_states (notification_level,user_id,attention_key,fingerprint,is_active,first_detected_at,last_detected_at) VALUES (1,2,'dest_watermark','fp_dest_1',1,NOW(),NOW())");
    $pdo->exec("INSERT INTO backup_history (filename,backup_type,performed_by,status) VALUES ('dest_backup_1.rmpkg','manual',2,'completed')");
    $pdo->exec("INSERT INTO email_delivery_log (recipient,subject,status) VALUES ('superadmin@test.local','Security alert','sent')");



    $rows = [];
    foreach (App\Backup\SqlBackupFormat::schema($pdo) as $table => $definition) {
        $columns = implode(',', array_map([App\Backup\DatabaseSnapshotWriter::class, 'identifier'], $definition['columns']));
        $rows[$table] = $pdo->query('SELECT ' . $columns . ' FROM ' . App\Backup\DatabaseSnapshotWriter::identifier($table))->fetchAll(PDO::FETCH_ASSOC);
    }
    $package = ['rows' => $rows, 'files' => [], 'manifest' => ['origin_id' => str_repeat('a', 32), 'package_id' => str_repeat('b', 32), 'schema' => hash('sha256', json_encode(array_keys($rows)))]];
    // Destination dependent actor (evidence-only closure, AC 2, 3)
    $pdo->exec("INSERT INTO users (user_id,full_name,username,password_hash,role_id,branch_id) VALUES (50,'Old Log Subject','old_audited_staff','\$2y\$10\$xyz',4,1)");
    $pdo->exec("INSERT INTO activity_log (log_id,user_id,action,category,module,record_id) VALUES (300,2,'Audited old staff','security','User Access',50)");
    $service = new App\Backup\IdentityReconciliationService($pdo, new App\Authorization\RoleCapabilityPolicy());
    $review = $service->review(2, 'super_admin', $package);
    $assert(!$review['ready'] && count($review['conflicts']) >= 4, 'Equal IDs and usernames never silently link people');
    $assert($review['identity_map'] === [], 'No ordinary person receives an unconfirmed mapping');
    $package['rows']['user_roles'] = array_values(array_filter($package['rows']['user_roles'], static fn(array $r): bool => (int)$r['user_id'] !== 2 || (int)$r['role_id'] === 2));
    $package['rows']['users'][3]['is_recovery_account'] = 1;
    $choices = [1 => ['action' => 'link', 'destination_id' => 1], 3 => ['action' => 'distinct', 'username' => 'new_inventory', 'email' => null]];
    $review = $service->review(2, 'super_admin', $package, $choices);
    $assert($review['ready'], implode('; ', $review['errors']));
    $assert($review['identity_map'][1] === 1 && $review['identity_map'][2] > 4 && $review['identity_map'][4] > 4, 'Explicit links preserve destination ID; privileged/Recovery people get separate historical IDs');
    $people = array_column($review['rows']['users'], null, 'user_id');
    $assert($people[1] === $rows['users'][0], 'Link preserves every destination access and profile field');
    foreach ([2, 4] as $sourceId) {
        $person = $people[$review['identity_map'][$sourceId]];
        $assert($person['role_id'] === null && $person['password_hash'] === '!' && $person['status'] === 'disabled' && (int)$person['is_recovery_account'] === 0, 'Historical Actors have no usable role, password or recovery authority');
    }
    $assert($review['active_administrators'] === 1, 'Count all active ordinary Administrators and exclude secondary-privileged users');
    $assert($review['rows']['sale_receipt_details'] === $rows['sale_receipt_details'], 'Frozen receipt JSON stays byte-for-byte unchanged');
    $assert((int)$review['rows']['sales'][0]['cashier_id'] === $review['identity_map'][4], 'Sales attribution follows the explicit map');

    // Preservation of classified categories (AC 1, AC 2)
    $assert(count($review['rows']['platform_settings']) >= 1, 'Platform settings preserved from destination');
    $assert(count(array_filter($review['rows']['attention_settings'], fn($r) => ($r['setting_scope'] ?? '') === 'platform')) >= 1, 'Platform attention settings preserved');
    $assert(count($review['rows']['ml_settings']) >= 1, 'Technical forecasting settings preserved');
    $assert(count($review['rows']['login_attempts']) >= 1, 'Destination login attempts preserved');
    $assert(count($review['rows']['password_reset_tokens']) >= 1, 'Protected reset state preserved');
    $assert(count($review['rows']['notification_preferences']) >= 1, 'Protected notification preferences preserved');
    $assert(count($review['rows']['notifications']) >= 1, 'Protected notifications preserved');
    $assert(count($review['rows']['attention_states']) >= 1, 'Protected attention states preserved');
    $assert(count($review['rows']['backup_history']) >= 1, 'Backup history preserved');
    $assert(count($review['rows']['email_delivery_log']) >= 1, 'Email delivery log preserved');

    // Dependent evidence-only actor closure (AC 2, AC 3)
    $assert(isset($people[50]), 'Unlinked destination actor referenced in security audit preserved in users closure');
    $depActor = $people[50];
    $assert($depActor['role_id'] === null && $depActor['password_hash'] === '!' && $depActor['status'] === 'disabled' && (int)$depActor['is_recovery_account'] === 0, 'Destination evidence actor stripped of usable login access');

    // Preservation closure preview (AC 1)
    $assert(isset($review['preservation_closure']['counts']['platform_settings']), 'Preservation closure preview reports counts');
    $assert(isset($review['resulting_access']) && count($review['resulting_access']) >= 4, 'Resulting access projected');
    $accessById = array_column($review['resulting_access'], null, 'user_id');
    $assert($accessById[1]['usable_access'] === true && $accessById[2]['usable_access'] === true, 'Protected & linked accounts retain usable access');
    $assert($accessById[50]['usable_access'] === false && $accessById[$review['identity_map'][2]]['usable_access'] === false, 'Evidence actors & Historical actors have no usable access');

    $moreChoices = $choices; $moreChoices[3]['administrator'] = true;
    $assert($service->review(2, 'super_admin', $package, $moreChoices)['active_administrators'] === 2, 'Count multiple active ordinary Administrators without silently promoting anyone');
    $recoveryPackage = $package; $recoveryPackage['rows']['users'][3]['is_recovery_account'] = 0;
    $recoveryPackage['rows']['recovery_accounts'][] = ['account_key' => 'recovery', 'user_id' => 4, 'activation_secret_hash' => '!', 'activated_at' => null, 'sealed_at' => null, 'credentials_rotated_at' => null, 'last_used_at' => null];
    $assert($service->review(2, 'super_admin', $recoveryPackage, $choices)['ready'], 'Recovery account membership alone forces a history-only source identity');

    $reject = static function (callable $work, string $message) use ($assert): void {
        try { $work(); } catch (DomainException|InvalidArgumentException $e) { return; }
        throw new RuntimeException($message);
    };

    // Role-denied approval (AC 5)
    $assert($service->review(1, 'admin', $package, $choices)['ready'], 'Ordinary Administrator can review identities');
    $reject(fn() => $service->review(3, 'inventory_manager', $package, $choices), 'Inventory Manager cannot review identities');
    $reject(fn() => $service->review(3, 'admin', $package, $choices), 'Staff cannot claim an Administrator Workspace');
    $reject(fn() => $service->approve(1, 'admin', 'wrong-password', $package, $choices), 'Administrator must supply their own current password');
    $adminApproval = $service->approve(1, 'admin', 'ThemeTest@2026', $package, $choices);
    $assert($adminApproval['approval']['approved_by_role'] === 'admin', 'Administrator can authorize their own account review');
    $assert($service->recheck(1, 'admin', 'ThemeTest@2026', $package)['ready'], 'Administrator review passes the same preservation and integrity rechecks');
    $service->invalidateApproval();
    $pdo->exec("DELETE FROM activity_log WHERE action='Reconciliation approved'");

    // Duplicate collation & link checks
    $duplicate = $choices; $duplicate[3]['username'] = 'THEME_CASHIER';
    $reject(fn() => $service->review(2, 'super_admin', $package, $duplicate), 'MySQL collation detects distinct-person username collision');
    $duplicate = $choices; $duplicate[1]['destination_id'] = 2; $duplicate[3] = ['action' => 'link', 'destination_id' => 2];
    $assert(!$service->review(2, 'super_admin', $package, $duplicate)['ready'], 'Two distinct source people cannot silently combine into one destination account');
    $bad = $package; $bad['rows']['inventory_adjustment_revisions'][] = ['revision_id' => 1, 'adjustment_id' => 1, 'actor_id' => 99999, 'action' => 'correct'];
    $reject(fn() => $service->review(2, 'super_admin', $bad, $choices), 'Unresolved logical revision actors fail closed');

    // Minimum administrator requirement
    $pdo->exec("UPDATE users SET status='disabled' WHERE user_id=1");
    $assert(!$service->review(2, 'super_admin', $package, $choices)['ready'], 'Zero active ordinary Administrators blocks review');
    $pdo->exec("UPDATE users SET status='active' WHERE user_id=1");
    $pdo->exec('DELETE FROM user_roles WHERE user_id=1');
    $pdo->exec('INSERT INTO user_roles (user_id,role_id,is_primary) VALUES (1,4,1)');
    $assert(!$service->review(2, 'super_admin', $package, $choices)['ready'], 'Primary Administrator with only Cashier membership is not an effective Administrator');
    $pdo->exec('DELETE FROM user_roles WHERE user_id=1');
    $pdo->exec('INSERT INTO user_roles (user_id,role_id,is_primary) VALUES (1,1,1)');

    // File collision avoidance (AC 3)
    $imagePackage = $package;
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=');
    $imagePackage['files']['profile-images/source.png'] = $image;
    $imagePackage['rows']['users'][3]['profile_image'] = 'source.png';
    $collision = 'rm_' . str_repeat('a', 32) . '_' . substr(hash('sha256', 'profile-images/source.png' . $image), 0, 24) . '.png';
    $pdo->prepare('UPDATE users SET profile_image=? WHERE user_id=2')->execute([$collision]);
    $images = $service->review(2, 'super_admin', $imagePackage, $choices);
    $assert($images['ready'] && $images['file_map']['profile-images/source.png'] !== 'profile-images/' . $collision, 'Explicit mapping avoids a deliberately seeded protected-profile path collision');
    $pdo->exec('UPDATE users SET profile_image=NULL WHERE user_id=2');

    // Checkpoint replay (AC 1)
    $checkpoint = ['rows' => $review['context']['destination_rows'] + ['users' => $pdo->query('SELECT * FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC), 'roles' => $pdo->query('SELECT * FROM roles ORDER BY role_id')->fetchAll(PDO::FETCH_ASSOC)]];
    $assert($service->replay($package, $checkpoint, $review)['rows'] === $review['rows'], 'Approved projection reconstructs identically from incoming and original checkpoint');

    // User lifecycle guards on historical actors (AC 3)
    $pdo->prepare('INSERT INTO users (user_id,full_name,username,password_hash,role_id,status,branch_id,is_recovery_account) VALUES (?,?,?,?,NULL,\'disabled\',1,0)')
        ->execute([1000, 'Historical employee', 'historical_employee', '!']);
    $lifecycle = new App\Services\UserLifecycleService($pdo, new App\Authorization\RoleCapabilityPolicy(), new App\Store\StoreScope($pdo));
    $reject(fn() => $lifecycle->setStatus(2, 'super_admin', 1000, 'active'), 'Historical Actor cannot activate');
    $reject(fn() => $lifecycle->update(2, 'super_admin', 1000, ['role' => 'admin']), 'Historical Actor cannot receive a role');
    $reject(fn() => $lifecycle->resetPassword(2, 'super_admin', 1000, password_hash('NewPassword', PASSWORD_DEFAULT)), 'Historical Actor cannot receive a password');

    // Provenance actor resolution (AC 3, 4)
    $insertOrigin = $pdo->prepare('INSERT INTO store_record_origins (origin_id,table_name,source_key,local_key,original_details) VALUES (?,?,?,?,?)');
    foreach ($review['provenance'] as $entry) $insertOrigin->execute(array_values($entry));
    $identity = (new App\Backup\RecordProvenance($pdo))->actor('sales', ['sale_id' => 1], null, $review['identity_map'][4]);
    $assert($identity['source_id'] === 4 && $identity['original']['full_name'] === 'Theme Test cashier', 'Read boundary resolves original people in the record installation context');

    // Repeated / Chained transfer
    $again = $package; $again['rows'] = $review['rows']; $again['manifest']['origin_id'] = str_repeat('c', 32);
    $next = [];
    foreach ($again['rows']['users'] as $user) {
        if ($user['role_id'] !== null) $next[(int)$user['user_id']] = (int)$user['user_id'] <= 4
            ? ['action' => 'link', 'destination_id' => (int)$user['user_id']]
            : ['action' => 'distinct', 'username' => 'twice_inventory', 'email' => null];
    }
    $twice = $service->review(2, 'super_admin', $again, $next);
    $assert($twice['ready'], implode('; ', $twice['errors']));
    $assert($twice['rows']['gcash_transactions'][0]['receipt_details_json'] === $gcashJson, 'Chained transfer never rewrites frozen embedded IDs');

    // Password verification & Approval recording in RecoveryStore (AC 5)
    $reject(fn() => $service->approve(2, 'super_admin', 'wrong_password', $package, $choices), 'Incorrect login password rejected for approval');
    $approvedResult = $service->approve(2, 'super_admin', 'ThemeTest@2026', $package, $choices);
    $assert(is_array($approvedResult['approval']), 'Approval evidence returned');
    $assert($approvedResult['approval']['approved_by'] === 2, 'Super Administrator recorded as approver');
    $assert($service->latestApproval() !== null, 'Preparation evidence persisted in RecoveryStore');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE action='Reconciliation approved'")->fetchColumn() === 1, 'Approval recorded in activity log');

    // Recheck package integrity, destination state, authority (AC 6)
    $rechecked = $service->recheck(2, 'super_admin', 'ThemeTest@2026', $package);
    $assert($rechecked['ready'], 'Recheck with exact package and destination state succeeds');

    // Authorized consumer can use preparation without platform governance (AC 5)
    $consumerRecheck = $service->recheck(1, 'admin', 'ThemeTest@2026', $package);
    $assert($consumerRecheck['ready'], 'Authorized consumer can use valid preparation');

    // Stale package rejected & invalidates approval (AC 6)
    $tamperedPkg = $package;
    $tamperedPkg['manifest']['package_id'] = 'tampered_package_id';
    $reject(fn() => $service->recheck(2, 'super_admin', 'ThemeTest@2026', $tamperedPkg), 'Tampered package rejected on recheck');
    $assert($service->latestApproval() === null, 'Tampered package invalidates stored approval');

    // Re-approve, then test destination state change invalidation (AC 6)
    $service->approve(2, 'super_admin', 'ThemeTest@2026', $package, $choices);
    $pdo->exec("UPDATE users SET email='probe_change@test.local' WHERE user_id=1");
    $reject(fn() => $service->recheck(2, 'super_admin', 'ThemeTest@2026', $package), 'Destination state modification rejected on recheck');
    $assert($service->latestApproval() === null, 'Destination state change invalidates approval');
    $pdo->exec("UPDATE users SET email=NULL WHERE user_id=1");

    // Re-approve, then test approver authority revocation (AC 6)
    $service->approve(2, 'super_admin', 'ThemeTest@2026', $package, $choices);
    $pdo->exec("UPDATE users SET role_id=4 WHERE user_id=2"); // Demote to cashier
    $pdo->exec("DELETE FROM user_roles WHERE user_id=2");
    $reject(fn() => $service->recheck(1, 'admin', 'ThemeTest@2026', $package), 'Demoted approver authority rejected on recheck');
    $assert($service->latestApproval() === null, 'Revoked approver authority invalidates approval');
    $pdo->exec("UPDATE users SET role_id=2 WHERE user_id=2"); // Restore Super Admin
    $pdo->exec("INSERT INTO user_roles (user_id,role_id,is_primary) VALUES (2,2,1)");

    // Ordinary replacement remains disabled (AC 7)
    $restorer = new App\Backup\DatabaseRestoreService($pdo, new App\Authorization\RoleCapabilityPolicy(), new App\Backup\DatabaseBackupService($pdo, new App\Authorization\RoleCapabilityPolicy()));
    $assert($review['replacement_authorized'] === false, 'Reconciliation review leaves ordinary replacement disabled');
    $reject(fn() => $restorer->restore(2, 'super_admin', __FILE__, 'test.rmpkg', 'ThemeTest@2026'), 'Restore service keeps replacement disabled');

    // Replay checkpoint with users table dropped
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); $pdo->exec('DROP TABLE users'); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $assert($service->replay($package, $checkpoint, $review)['rows'] === $review['rows'], 'Checkpoint replay works with users missing');

    echo "Identity Reconciliation MySQL: passed\n";
} catch (Throwable $e) { $failure = $e; }
finally {
    $fixture('cleanup');
    if (is_dir($tmpRecovery)) {
        foreach (glob($tmpRecovery . '/*') as $f) @unlink($f);
        @rmdir($tmpRecovery);
    }
}
if ($failure !== null) { fwrite(STDERR, (string)$failure); exit(1); }
