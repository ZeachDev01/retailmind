<?php
if (getenv('RUN_DB_TESTS') !== '1') { echo "Identity Reconciliation MySQL: skipped (RUN_DB_TESTS=1 required)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
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
    $rows = [];
    foreach (App\Backup\SqlBackupFormat::schema($pdo) as $table => $definition) {
        $columns = implode(',', array_map([App\Backup\DatabaseSnapshotWriter::class, 'identifier'], $definition['columns']));
        $rows[$table] = $pdo->query('SELECT ' . $columns . ' FROM ' . App\Backup\DatabaseSnapshotWriter::identifier($table))->fetchAll(PDO::FETCH_ASSOC);
    }
    $package = ['rows' => $rows, 'files' => [], 'manifest' => ['origin_id' => str_repeat('a', 32), 'package_id' => str_repeat('b', 32)]];
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
    $assert($review['rows']['password_reset_tokens'] === [] && $review['rows']['recovery_accounts'] === [], 'Source security state cannot activate historical identities');
    $moreChoices = $choices; $moreChoices[3]['administrator'] = true;
    $assert($service->review(2, 'super_admin', $package, $moreChoices)['active_administrators'] === 2, 'Count multiple active ordinary Administrators without silently promoting anyone');
    $recoveryPackage = $package; $recoveryPackage['rows']['users'][3]['is_recovery_account'] = 0;
    $recoveryPackage['rows']['recovery_accounts'][] = ['account_key' => 'recovery', 'user_id' => 4, 'activation_secret_hash' => '!', 'activated_at' => null, 'sealed_at' => null, 'credentials_rotated_at' => null, 'last_used_at' => null];
    $assert($service->review(2, 'super_admin', $recoveryPackage, $choices)['ready'], 'Recovery account membership alone forces a history-only source identity');
    $reject = static function (callable $work, string $message) use ($assert): void {
        try { $work(); } catch (DomainException|InvalidArgumentException $e) { return; }
        throw new RuntimeException($message);
    };
    $reject(fn() => $service->review(1, 'admin', $package, $choices), 'Ordinary Administrator cannot approve identities');
    $duplicate = $choices; $duplicate[3]['username'] = 'THEME_CASHIER';
    $reject(fn() => $service->review(2, 'super_admin', $package, $duplicate), 'MySQL collation detects distinct-person username collision');
    $duplicate = $choices; $duplicate[1]['destination_id'] = 2; $duplicate[3] = ['action' => 'link', 'destination_id' => 2];
    $assert(!$service->review(2, 'super_admin', $package, $duplicate)['ready'], 'Two distinct source people cannot silently combine into one destination account');
    $bad = $package; $bad['rows']['inventory_adjustment_revisions'][] = ['revision_id' => 1, 'adjustment_id' => 1, 'actor_id' => 99999, 'action' => 'correct'];
    $reject(fn() => $service->review(2, 'super_admin', $bad, $choices), 'Unresolved logical revision actors fail closed');
    $pdo->exec("UPDATE users SET status='disabled' WHERE user_id=1");
    $assert(!$service->review(2, 'super_admin', $package, $choices)['ready'], 'Zero active ordinary Administrators blocks review');
    $pdo->exec("UPDATE users SET status='active' WHERE user_id=1");
    $pdo->exec('DELETE FROM user_roles WHERE user_id=1');
    $pdo->exec('INSERT INTO user_roles (user_id,role_id,is_primary) VALUES (1,4,1)');
    $assert(!$service->review(2, 'super_admin', $package, $choices)['ready'], 'Primary Administrator with only Cashier membership is not an effective Administrator');
    $pdo->exec('DELETE FROM user_roles WHERE user_id=1');
    $pdo->exec('INSERT INTO user_roles (user_id,role_id,is_primary) VALUES (1,1,1)');
    $imagePackage = $package;
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=');
    $imagePackage['files']['profile-images/source.png'] = $image;
    $imagePackage['rows']['users'][3]['profile_image'] = 'source.png';
    $collision = 'rm_' . str_repeat('a', 32) . '_' . substr(hash('sha256', 'profile-images/source.png' . $image), 0, 24) . '.png';
    $pdo->prepare('UPDATE users SET profile_image=? WHERE user_id=2')->execute([$collision]);
    $images = $service->review(2, 'super_admin', $imagePackage, $choices);
    $assert($images['ready'] && $images['file_map']['profile-images/source.png'] !== 'profile-images/' . $collision, 'Explicit mapping avoids a deliberately seeded protected-profile path collision');
    $pdo->exec('UPDATE users SET profile_image=NULL WHERE user_id=2');
    $checkpoint = ['rows' => $rows];
    $assert($service->replay($package, $checkpoint, $review)['rows'] === $review['rows'], 'Approved projection reconstructs identically from incoming and original checkpoint');
    $pdo->prepare('INSERT INTO users (user_id,full_name,username,password_hash,role_id,status,branch_id,is_recovery_account) VALUES (?,?,?,?,NULL,\'disabled\',1,0)')
        ->execute([1000, 'Historical employee', 'historical_employee', '!']);
    $lifecycle = new App\Services\UserLifecycleService($pdo, new App\Authorization\RoleCapabilityPolicy(), new App\Store\StoreScope($pdo));
    $reject(fn() => $lifecycle->setStatus(2, 'super_admin', 1000, 'active'), 'Historical Actor cannot activate');
    $reject(fn() => $lifecycle->update(2, 'super_admin', 1000, ['role' => 'admin']), 'Historical Actor cannot receive a role');
    $reject(fn() => $lifecycle->resetPassword(2, 'super_admin', 1000, password_hash('NewPassword', PASSWORD_DEFAULT)), 'Historical Actor cannot receive a password');
    $insertOrigin = $pdo->prepare('INSERT INTO store_record_origins (origin_id,table_name,source_key,local_key,original_details) VALUES (?,?,?,?,?)');
    foreach ($review['provenance'] as $entry) $insertOrigin->execute(array_values($entry));
    $identity = (new App\Backup\RecordProvenance($pdo))->actor('sales', ['sale_id' => 1], null, $review['identity_map'][4]);
    $assert($identity['source_id'] === 4 && $identity['original']['full_name'] === 'Theme Test cashier', 'Read boundary resolves original people in the record installation context');
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
    $pdo->exec('DELETE FROM store_record_origins');
    foreach ($twice['rows']['store_record_origins'] as $entry) $insertOrigin->execute(array_values($entry));
    $gcash = (new App\Receipts\GCashReceiptService($pdo))->fetch(1, 2, 'super_admin');
    $assert($gcash['cashier_provenance']['origin_id'] === str_repeat('a', 32) && $gcash['cashier_provenance']['source_id'] === 4
        && $gcash['cashier_provenance']['local_id'] === $twice['identity_map'][$review['identity_map'][4]], 'Repeated transfer resolves the first installation actor without ambiguous duplicate record origins');
    // Replay the first retained checkpoint rather than querying the now-modified users.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); $pdo->exec('DROP TABLE users'); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $assert($service->replay($package, $checkpoint, $review)['rows'] === $review['rows'], 'Checkpoint replay works with users missing');
    echo "Identity Reconciliation MySQL: passed\n";
} catch (Throwable $e) { $failure = $e; }
finally { $fixture('cleanup'); }
if ($failure !== null) { fwrite(STDERR, (string)$failure); exit(1); }
