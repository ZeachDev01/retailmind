<?php
// Only uniquely named disposable databases are changed.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Consolidated SQL integration: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/pairing.php';
restore_exception_handler();

$config = require __DIR__ . '/../config/database.php';
$dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$server = new PDO($dsn, $config['username'], $config['password'], $options);
$names = ['rm_sql_php_' . bin2hex(random_bytes(6)), 'rm_sql_update_' . bin2hex(random_bytes(6))];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$import = static function (PDO $db, string $file): void {
    // The dump's versioned comments contain executable session settings.
    $sql = preg_replace('/\/\*!\d{5}\s*(.*?)\*\//s', '$1', file_get_contents($file));
    foreach (split_sql_statements($sql) as $statement) {
        $db->query($statement)->closeCursor();
    }
};
$structures = static function (PDO $db): array {
    $tables = [];
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $ddl = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
        // Column/index order is immaterial when an older table gains fields at its end.
        $lines = array_map(static fn(string $line): string => trim($line, " ,\r\n\t"), explode("\n", preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl)));
        sort($lines);
        $tables[$table] = $lines;
    }
    ksort($tables);
    return $tables;
};
$records = static function (PDO $db, string $table): array {
    $rows = array_map('json_encode', $db->query("SELECT * FROM `{$table}`")->fetchAll());
    sort($rows);
    return $rows;
};

try {
    $databases = [];
    foreach ($names as $name) {
        $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db = new PDO($dsn . ';dbname=' . $name, $config['username'], $config['password'], $options);
        $db->exec("SET time_zone='+08:00'");
        $db->exec("SET timestamp=1791417600");
        $import($db, __DIR__ . '/../sql/schema.sql');
        $runner = new App\Database\MigrationRunner($db, __DIR__ . '/../database/migrations');
        $assert($runner->pending() === [], 'Fresh import leaves pending migrations');
        $assert((int)$db->query('SELECT COUNT(*) FROM user_roles')->fetchColumn() > 0, 'Fresh import lacks assigned roles');

        // Reproduce an import whose ledger says applied but required structures are missing.
        foreach (['user_roles', 'backup_operations', 'restore_preserved_activity', 'store_write_gate'] as $table) {
            $db->exec("DROP TABLE `{$table}`");
        }
        foreach (['snapshot_at', 'envelope_version', 'cipher', 'requested_by_role'] as $column) {
            $db->exec("ALTER TABLE backup_history DROP COLUMN `{$column}`");
        }
        foreach (['access_token_hash', 'joined_ip'] as $column) {
            $db->exec("ALTER TABLE barcode_pairings DROP COLUMN `{$column}`");
        }
        $forecastSource = file_get_contents(__DIR__ . '/../legacy/demandForcasting/train_model.py');
        preg_match_all('/"(\w+)": "(INT NULL|TEXT NULL)"/', $forecastSource, $forecastColumns, PREG_SET_ORDER);
        foreach ($forecastColumns as $column) $db->exec("ALTER TABLE stock_predictions DROP COLUMN `{$column[1]}`");
        $db->exec("UPDATE platform_settings SET setting_value='99' WHERE setting_key='dormancy_disable_days'");
        $db->exec("UPDATE users SET password_changed_at='2026-10-01 00:00:00'");
        $db->exec("INSERT INTO backup_history (filename,backup_type,file_size,status,notes)
            VALUES ('retained-backup.sql','manual',123,'completed','Keep existing backup evidence')");

        // Exercise legacy Register and held-cart backfills, without changing their IDs/history.
        $db->exec("INSERT INTO registers (name,status) VALUES ('Migration till','disabled')");
        $db->exec('ALTER TABLE registers CHANGE COLUMN name register_name VARCHAR(100) NOT NULL');
        $db->exec('ALTER TABLE registers ADD COLUMN is_enabled TINYINT NOT NULL DEFAULT 1');
        $db->exec("UPDATE registers SET is_enabled=IF(status='active',1,0)");
        $db->exec('ALTER TABLE registers DROP INDEX idx_registers_status, DROP COLUMN status');
        $db->exec("ALTER TABLE held_sales MODIFY status ENUM('held','resumed','cancelled','expired') NOT NULL DEFAULT 'held'");
        $db->exec("INSERT INTO held_sales (cashier_id,reference_no,cart_json,status)
            SELECT user_id,'SQL-LEGACY-CART','{}','cancelled' FROM users WHERE is_recovery_account=0 ORDER BY user_id LIMIT 1");

        // Both snapshots must participate in audit classification, including legacy role IDs.
        $db->exec("ALTER TABLE activity_log MODIFY category ENUM('store_operation','security','recovery','platform_setting','recovery_account') NULL");
        $db->exec("INSERT INTO activity_log (action,module,category,new_value,previous_value) VALUES
            ('Staff updated','Users',NULL,'{\"role\":\"cashier\"}',NULL),
            ('Staff updated','Users',NULL,'{\"role\":\"cashier\"}','{\"role\":\"admin\"}'),
            ('Snapshot created','Backup & Restore',NULL,NULL,NULL),
            ('Login success','Authentication',NULL,NULL,NULL)");
        $databases[] = $db;
    }
    [$php, $sql] = $databases;
    $accounts = $records($sql, 'users');
    $inventory = $records($sql, 'inventory');
    $sales = $records($sql, 'sales');
    $backupHistory = $records($sql, 'backup_history');

    // Run every source migration even though the imported ledger already marks it applied.
    $runner = new App\Database\MigrationRunner($php, __DIR__ . '/../database/migrations');
    foreach ($runner->available() as $migration) ($migration['up'])($php);
    pairing_ensure_schema($php);
    foreach ($forecastColumns as $column) {
        App\Database\Schema::addColumnIfMissing($php, 'stock_predictions', $column[1], $column[2]);
    }
    $import($sql, __DIR__ . '/../sql/update.sql');
    $assert($structures($php) === $structures($sql), 'SQL and PHP migration schemas differ');
    foreach (array_keys($structures($php)) as $table) {
        $assert($records($php, $table) === $records($sql, $table), "SQL and PHP migration records differ: {$table}");
    }
    $assert($accounts === $records($sql, 'users'), 'Update changed existing accounts/passwords');
    $assert($inventory === $records($sql, 'inventory'), 'Update changed inventory');
    $assert($sales === $records($sql, 'sales'), 'Update changed sales');
    // Added backup metadata is nullable; existing fields retain their exact values.
    $oldBackup = array_map('json_decode', $backupHistory);
    $newBackup = array_map(static fn(string $row) => array_diff_key(json_decode($row, true), array_flip(['snapshot_at', 'envelope_version', 'cipher', 'requested_by_role'])), $records($sql, 'backup_history'));
    $assert(json_encode($oldBackup) === json_encode($newBackup), 'Update changed backup history');
    $assert($sql->query("SELECT status FROM held_sales WHERE reference_no='SQL-LEGACY-CART'")->fetchColumn() === 'discarded', 'Legacy cart was not retained as discarded');
    $assert($sql->query("SELECT status FROM registers WHERE name='Migration till'")->fetchColumn() === 'disabled', 'Legacy Register availability changed');

    $before = $structures($sql);
    $rows = [];
    foreach (array_keys($before) as $table) $rows[$table] = $records($sql, $table);
    $import($sql, __DIR__ . '/../sql/update.sql');
    $assert($before === $structures($sql), 'Second import changed schema');
    foreach ($rows as $table => $data) $assert($data === $records($sql, $table), "Second import changed records: {$table}");

    // Runtime tables must also be available before any scanner, email, or Python request.
    foreach (['barcode_scans', 'barcode_pairings', 'email_delivery_log', 'forecast_evaluations', 'model_training_runs'] as $table) {
        $sql->exec("DROP TABLE `{$table}`");
    }
    $import($sql, __DIR__ . '/../sql/update.sql');
    $assert($before === $structures($sql), 'Runtime dependencies differ from the fresh schema');

    // A migration must refuse conflicting live shifts rather than close one to make the index fit.
    $sql->exec('ALTER TABLE cashier_shifts DROP INDEX uq_cashier_shifts_open_cashier');
    $cashierId = (int)$sql->query('SELECT user_id FROM users ORDER BY user_id LIMIT 1')->fetchColumn();
    $sql->exec("INSERT INTO cashier_shifts (cashier_id,status) VALUES ({$cashierId},'open'),({$cashierId},'open')");
    $refused = false;
    try { $import($sql, __DIR__ . '/../sql/update.sql'); } catch (PDOException $exception) {
        $refused = ($exception->errorInfo[1] ?? null) === 1062;
    }
    $assert($refused, 'SQL accepted conflicting open Cashier Shifts');
    $assert((int)$sql->query("SELECT COUNT(*) FROM cashier_shifts WHERE status='open'")->fetchColumn() === 2, 'SQL silently closed a live shift');
    echo "Consolidated SQL integration: passed (fresh import, PHP parity, legacy backfills, record preservation, rerun)\n";
} finally {
    foreach ($names as $name) {
        if (!preg_match('/^rm_sql_(php|update)_[a-f0-9]{12}$/', $name)) throw new RuntimeException('Unsafe test database name');
        $server->exec("DROP DATABASE IF EXISTS `{$name}`");
    }
}
