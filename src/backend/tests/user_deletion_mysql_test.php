<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_DB_TESTS') !== '1') { echo "User deletion MySQL: skipped (RUN_DB_TESTS=1 required)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e); exit(1); });
$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$recovery = sys_get_temp_dir() . '/rm-user-deletion-' . bin2hex(random_bytes(6));
mkdir($recovery, 0700);
$_ENV['BACKUP_STORAGE_PATH'] = $_SERVER['BACKUP_STORAGE_PATH'] = $recovery;
$fixture = static function (string $action) use ($database): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/support/theme_fixture.php', $action, $database], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($errors . $output);
};
$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
try {
    $fixture('create');
    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $service = new App\Services\UserLifecycleService($pdo, new App\Authorization\RoleCapabilityPolicy(), new App\Store\StoreScope($pdo));
    $nextId = 100;
    $seed = static function (string $status = 'active', int $roleId = 4) use ($pdo, &$nextId): int {
        $id = $nextId++;
        $pdo->prepare('INSERT INTO users (user_id,full_name,username,email,password_hash,role_id,branch_id,must_change_password,status) VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?)')
            ->execute([$id, 'Imported ' . $id, 'imported_' . $id, $id . '@example.test', password_hash('ImportedPassword123', PASSWORD_DEFAULT), $roleId, $status]);
        $pdo->prepare('INSERT INTO user_roles (user_id,role_id,is_primary) VALUES (?, ?, 1)')->execute([$id, $roleId]);
        return $id;
    };
    $denied = static function (callable $operation, string $expected) use ($assert): void {
        try { $operation(); throw new RuntimeException('Expected deletion rejection: ' . $expected); }
        catch (DomainException $e) { $assert(str_contains($e->getMessage(), $expected), $e->getMessage()); }
    };
    $auditCount = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
    // Imported account without history; no audits are removed to make it eligible.
    foreach (['active', 'disabled'] as $status) {
        $id = $seed($status, 1);
        $pdo->exec("INSERT INTO password_reset_tokens (user_id,token_hash,expires_at) VALUES ($id, 'token_$id', DATE_ADD(NOW(), INTERVAL 1 HOUR))");
        $pdo->exec("INSERT INTO notification_preferences (user_id) VALUES ($id)");
        $pdo->exec("INSERT INTO notifications (user_id,title,message,type) VALUES ($id,'Notice','Disposable','system')");
        $pdo->exec("INSERT INTO attention_states (user_id,attention_key,fingerprint,first_detected_at,last_detected_at) VALUES ($id,'test','test',NOW(),NOW())");
        $denied(fn() => $service->delete(1, 'admin', $id), 'Confirm');
        $assert($service->deletionRestriction(1, 'admin', $id) === null, 'Imported Administrator with no history is eligible');
        $service->delete(1, 'admin', $id, true);
        try { $service->get($id); throw new RuntimeException('Deleted account still exists'); }
        catch (InvalidArgumentException $e) { $assert($e->getMessage() === 'User not found.', 'Account cannot be retrieved after deletion'); }
        foreach (['user_roles', 'password_reset_tokens', 'notification_preferences', 'notifications', 'attention_states'] as $table) {
            $assert((int)$pdo->query("SELECT COUNT(*) FROM $table WHERE user_id = $id")->fetchColumn() === 0, "$table removed atomically");
        }
        $audit = $pdo->query("SELECT * FROM activity_log WHERE record_id = $id AND action = 'User permanently deleted'")->fetch(PDO::FETCH_ASSOC);
        $before = json_decode($audit['previous_value'], true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode($audit['new_value'], true, 512, JSON_THROW_ON_ERROR);
        $assert((int)$audit['user_id'] === 1 && $audit['category'] === 'security' && $after['actor_role'] === 'admin', 'Actor attribution and protected category retained');
        $assert($before['user_id'] === $id && $before['username'] === 'imported_' . $id && $before['full_name'] === 'Imported ' . $id && $before['roles'] === ['admin'], 'Deleted identity retained without target FK');
    }
    $history = App\Services\UserLifecycleService::DELETION_HISTORY_MESSAGE;
    foreach ([
        'sale' => 'UPDATE sales SET cashier_id = ? WHERE sale_id = 1',
        'logical discount authorizer' => 'UPDATE sales SET discount_authorized_by = ? WHERE sale_id = 1',
        'shift reviewer (SET NULL)' => 'UPDATE cashier_shifts SET reviewed_by = ? WHERE shift_id = 1',
        'stock' => 'UPDATE inventory_adjustments SET approved_by = ? WHERE adjustment_id = 1',
        'logical branch creator' => 'UPDATE branches SET created_by = ? WHERE branch_id = 1',
        'GCash' => "INSERT INTO gcash_transactions (cashier_id,transaction_type,amount,reference_number) VALUES (?,'Cash-In',100,'DELETE-HISTORY')",
        'refund' => "INSERT INTO cash_refunds (sale_id,shift_id,cashier_id,refund_amount,reason) VALUES (1,1,?,1,'Deletion test')",
        'logical correction actor' => "INSERT INTO inventory_adjustment_revisions (adjustment_id,actor_id,action) VALUES (1,?,'correct')",
        'logical correction snapshot' => "INSERT INTO inventory_adjustment_revisions (adjustment_id,actor_id,action,old_values) VALUES (1,1,'correct',CONCAT('{\"reported_by\":', ?, '}'))",
        'backup requester' => "INSERT INTO backup_operations (operation_key,requested_by) VALUES ('delete-history',?)",
        'audit actor' => "INSERT INTO activity_log (user_id,action,category) VALUES (?,'Evidence','security')",
        'audit target' => "INSERT INTO activity_log (user_id,action,category,module,record_id) VALUES (1,'User creation','security','Users',?)",
        'audit nested identity' => "INSERT INTO activity_log (user_id,action,category,metadata) VALUES (1,'Evidence','security', CONCAT('{\"nested\":{\"incoming_cashier_id\":', ?, '}}'))",
        'audit reporter identity' => "INSERT INTO activity_log (user_id,action,category,metadata) VALUES (1,'Evidence','security', CONCAT('{\"reported_by\":', ?, '}'))",
        'reviewed canonical login identity' => "INSERT INTO activity_log (user_id,action,category,metadata) VALUES (1,'Evidence','security', CONCAT('{\"attempts\":[{\"identity\":\"user:', ?, '\",\"username\":\"previous_username\"}]}'))",
        'review snapshot identity' => "INSERT INTO platform_settings (setting_key,setting_value,updated_by) VALUES ('ml_review_operational_imported', CONCAT('{\"reviewed_by\":', ?, '}'),1)",
    ] as $label => $sql) {
        $id = $seed();
        $pdo->beginTransaction();
        $pdo->prepare($sql)->execute([$id]);
        $count = $auditCount();
        $denied(fn() => $service->delete(1, 'admin', $id, true), $history);
        $assert($service->get($id)['status'] === 'active' && $auditCount() === $count, "$label rejection preserves account and history");
        $pdo->rollBack();
    }
    $id = $service->create(1, 'admin', ['full_name' => 'Created Account', 'username' => 'created_account', 'password_hash' => 'unused', 'roles' => ['cashier']]);
    $nextId = $id + 1;
    $denied(fn() => $service->delete(1, 'admin', $id, true), $history);
    foreach (['previous_value', 'new_value', 'metadata'] as $column) {
        $id = $seed();
        $pdo->prepare("INSERT INTO activity_log (user_id,action,category,$column) VALUES (1,'Evidence','security',?)")
            ->execute([json_encode(['nested' => ['username' => 'imported_' . $id]])]);
        $denied(fn() => $service->delete(1, 'admin', $id, true), $history);
    }
    foreach (['login_attempts' => 'username', 'email_delivery_log' => 'recipient'] as $table => $column) {
        $id = $seed();
        if ($table === 'login_attempts') $pdo->prepare('INSERT INTO login_attempts (username,ip_address) VALUES (?,?)')->execute(['IMPORTED_' . $id, '127.0.0.1']);
        else $pdo->prepare("INSERT INTO email_delivery_log (recipient,subject,status) VALUES (?,'Evidence','sent')")->execute([$id . '@example.test']);
        $denied(fn() => $service->delete(1, 'admin', $id, true), $history);
    }
    foreach (['user:', 'unknown:imported_'] as $prefix) {
        $id = $seed();
        $pdo->prepare('INSERT INTO login_attempts (username,ip_address) VALUES (?,?)')->execute([$prefix . $id, '127.0.0.1']);
        $denied(fn() => $service->delete(1, 'admin', $id, true), $history);
    }
    $id = $seed();
    App\Backup\RecoveryStore::log($id, 'test.sql', 'completed');
    $denied(fn() => $service->delete(1, 'admin', $id, true), $history);
    $assert(str_contains(file_get_contents($recovery . '/restore-log.jsonl'), '"actor_id":' . $id), 'Private restore evidence remains intact');
    $id = $seed();
    foreach ([[1,'cashier'], [4,'admin'], [3,'inventory_manager']] as [$actor, $workspace]) {
        $denied(fn() => $service->delete($actor, $workspace, $id, true), 'Workspace');
    }
    $denied(fn() => $service->delete(1, 'admin', 1, true), 'own account');
    $denied(fn() => $service->delete(1, 'admin', 2, true), 'privileged');
    $protected = $seed();
    $pdo->exec("INSERT INTO user_roles (user_id,role_id,is_primary) VALUES ($protected,2,0)");
    $denied(fn() => $service->delete(2, 'super_admin', $protected, true), 'protected');
    $recovery = $seed();
    $pdo->exec("UPDATE users SET is_recovery_account = 1 WHERE user_id = $recovery");
    $denied(fn() => $service->delete(2, 'super_admin', $recovery, true), 'Recovery Account');
    $denied(fn() => $service->delete($recovery, 'cashier', $id, true), 'Workspace');
    $pdo->exec("UPDATE users SET status = 'disabled' WHERE user_id = 1");
    $denied(fn() => $service->delete(1, 'admin', $id, true), 'Workspace');
    $last = $seed('active', 1);
    $denied(fn() => $service->delete(2, 'super_admin', $last, true), 'Keep at least one');
    $pdo->exec("UPDATE users SET status = 'active' WHERE user_id = 1");
    // A pre-existing repeatable-read snapshot must not hide newly committed history.
    $id = $seed();
    $pdo->beginTransaction(); $pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
    $other = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $other->exec("INSERT INTO activity_log (user_id,action,category,module,record_id) VALUES (1,'Late audit','security','Users',$id)");
    $denied(fn() => $service->delete(1, 'admin', $id, true), $history);
    $pdo->commit();
    // An audit failure rolls the user and disposable state back, even inside a caller transaction.
    $id = $seed();
    $pdo->exec("INSERT INTO notification_preferences (user_id) VALUES ($id)");
    $pdo->exec("CREATE TRIGGER reject_delete_audit BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected audit failure'");
    foreach ([false, true] as $outer) {
        if ($outer) $pdo->beginTransaction();
        $count = $auditCount();
        try { $service->delete(1, 'admin', $id, true); throw new RuntimeException('Audit failure must reject deletion'); }
        catch (PDOException $e) { $assert(str_contains($e->getMessage(), 'Injected audit failure'), 'Expected audit failure'); }
        if ($outer) $pdo->commit();
        $assert($service->get($id)['username'] === 'imported_' . $id && $auditCount() === $count, 'Audit failure restores account and audit outcomes');
        $assert((int)$pdo->query("SELECT COUNT(*) FROM notification_preferences WHERE user_id = $id")->fetchColumn() === 1, 'Audit failure restores disposable state');
    }
    $pdo->exec('DROP TRIGGER reject_delete_audit');
    $id = $seed();
    $pdo->exec("INSERT INTO activity_log (user_id,action,category,module,record_id) VALUES (1,'Failed sign-in attempts reviewed','security','Authentication',$id)");
    $assert($service->deletionRestriction(1, 'admin', $id) === null, 'An unrelated login-attempt ID is not a target user ID');
    $service->delete(1, 'admin', $id, true);
    try { $pdo->exec('DELETE FROM users WHERE user_id = 4'); throw new RuntimeException('Restrictive FK must prevent history deletion'); }
    catch (PDOException $e) { $assert($e->getCode() === '23000', 'Existing restrictive foreign keys remain intact'); }
    echo "User deletion MySQL: passed\n";
} finally {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $fixture('cleanup');
    foreach (glob($recovery . '/*') as $file) unlink($file);
    rmdir($recovery);
}
