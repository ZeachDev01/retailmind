<?php
$private = sys_get_temp_dir() . '/rm-format-test-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
require_once __DIR__ . '/../bootstrap/app.php';
$_ENV['BACKUP_STORAGE_PATH'] = $private;
$_ENV['SESSION_SAVE_PATH'] = $private . '/sessions';
require_once __DIR__ . '/../includes/backup.php';

use App\Backup\RecoveryStore;
use App\Backup\SqlBackupFormat;
use App\Core\Session;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$wrap = static function (string $body): string {
    $body = SqlBackupFormat::HEADER . "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" . $body . "\nSET FOREIGN_KEY_CHECKS=1;";
    return $body . "\n-- SHA256: " . hash('sha256', $body) . "\n";
};
$ddl = 'CREATE TABLE `items` (`id` int, `note` text) ENGINE=InnoDB';
$schema = ['items' => ['ddl' => $ddl, 'columns' => ['id', 'note']]];
$prefix = "DROP TABLE IF EXISTS `items`;\n{$ddl};\n";
$valid = $wrap($prefix . "INSERT INTO `items` (`id`, `note`) VALUES (X'31', NULL);");
$exit = 0;
try {
    $assert(count(SqlBackupFormat::statements($valid, $schema)) === 3, 'Valid SQL subset should parse');
    $assert(str_contains(SqlBackupFormat::statements($valid, $schema)[2], "CONCAT(X'31')"), 'Numeric columns must receive the decoded text value, not the hex integer');
    $assert(SqlBackupFormat::normalizeDdl('CREATE TABLE `x` (`value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)') === SqlBackupFormat::normalizeDdl('CREATE TABLE `x` (`value` text COLLATE utf8mb4_unicode_ci)'), 'MySQL charset declarations implied by the collation must not reject a restored schema');
    $bad = [
        'arbitrary SQL' => 'DROP DATABASE store;',
        'legacy envelope' => "RMBAK1\nanything",
        'truncation' => substr($valid, 0, -10),
        'corruption' => str_replace("X'31'", "X'32'", $valid),
        'extra command with valid checksum' => $wrap($prefix . 'DROP DATABASE store;'),
        'wrong target table' => $wrap(str_replace('`items`', '`other`', $prefix)),
        'incompatible schema' => $wrap(str_replace('`note` text', '`note` blob', $prefix)),
        'data expression' => $wrap($prefix . "INSERT INTO `items` (`id`, `note`) VALUES (X'31', LOAD_FILE('/etc/passwd'));"),
        'statement injection' => $wrap($prefix . "INSERT INTO `items` (`id`, `note`) VALUES (X'31', X''); DELETE FROM `users`;"),
        'odd hex literal' => $wrap($prefix . "INSERT INTO `items` (`id`, `note`) VALUES (X'1', NULL);"),
        'missing table' => $wrap(''),
        'extra table' => $wrap($prefix . 'CREATE TABLE `other` (`id` int);'),
        'wrong column count' => $wrap($prefix . "INSERT INTO `items` (`id`, `note`) VALUES (NULL);"),
    ];
    foreach ($bad as $name => $sql) {
        $rejected = false;
        try { SqlBackupFormat::statements($sql, $schema); } catch (RuntimeException) { $rejected = true; }
        $assert($rejected, $name . ' must be rejected before replacement');
    }
    // Producer-schema extraction never needs a destination or a live database.
    $emptyDdl = "CREATE TABLE `empty_table` (\n  `a` int NOT NULL,\n  `b` text DEFAULT NULL,\n  `c` int GENERATED ALWAYS AS (`a` + 1) STORED,\n  PRIMARY KEY (`a`)\n) ENGINE=InnoDB";
    $producerSql = $wrap('DROP TABLE IF EXISTS `items`;' . "\n{$ddl};\n"
        . "INSERT INTO `items` (`id`, `note`) VALUES (X'31', X'32');\n"
        . 'DROP TABLE IF EXISTS `empty_table`;' . "\n{$emptyDdl};");
    $producer = SqlBackupFormat::producerSchema($producerSql);
    $assert(array_keys($producer) === ['items', 'empty_table'], 'Producer schema lists every captured table in SQL order');
    $assert($producer['items']['columns'] === ['id', 'note'], 'Producer columns come from the INSERT column list');
    $assert($producer['items']['ddl'] === SqlBackupFormat::normalizeDdl($ddl), 'Producer DDL is read from the package only');
    $assert($producer['empty_table']['columns'] === ['a', 'b'], 'Empty tables keep their DDL columns and skip generated columns');
    $assert(isset(SqlBackupFormat::producerSchema($wrap('DROP TABLE IF EXISTS `items`;' . "\n{$ddl};"))['items']['columns'][1]), 'A table without INSERTs still exposes DDL columns');

    $source = ['items' => ['ddl' => $ddl, 'columns' => ['id', 'note']], 'legacy' => ['ddl' => 'CREATE TABLE `legacy` (`id` int) ENGINE=InnoDB', 'columns' => ['id']]];
    $destination = ['items' => ['ddl' => $ddl, 'columns' => ['id', 'note', 'status']], 'newer' => ['ddl' => 'CREATE TABLE `newer` (`id` int) ENGINE=InnoDB', 'columns' => ['id']]];
    $diff = SqlBackupFormat::structureDiff($source, $destination);
    $assert($diff['tables_removed'] === ['legacy'], 'structureDiff names source-only tables');
    $assert($diff['tables_added'] === ['newer'], 'structureDiff names destination-only tables');
    $assert($diff['columns_added'] === ['items.status'], 'structureDiff names destination-only columns');
    $assert($diff['columns_removed'] === [], 'structureDiff does not invent dropped columns');
    $assert($diff['changed'] === [] && $diff['enums'] === [], 'Column changes are not double-reported as DDL drift');
    $enumSource = ['flags' => ['ddl' => "CREATE TABLE `flags` (\n  `state` enum('a','b') NOT NULL\n) ENGINE=InnoDB", 'columns' => ['state']]];
    $enumDestination = ['flags' => ['ddl' => "CREATE TABLE `flags` (\n  `state` enum('a','b','c') NOT NULL\n) ENGINE=InnoDB", 'columns' => ['state']]];
    $enumDiff = SqlBackupFormat::structureDiff($enumSource, $enumDestination);
    $assert($enumDiff['enums'] === ['flags.state' => ['added' => ['c'], 'removed' => []]], 'structureDiff reports enum widening per column');
    $assert($enumDiff['changed'] === [], 'Enum widening is not reported as opaque DDL drift');
    $narrowed = SqlBackupFormat::structureDiff($enumDestination, $enumSource);
    $assert($narrowed['enums'] === ['flags.state' => ['added' => [], 'removed' => ['c']]], 'structureDiff reports enum narrowing');
    $assert(SqlBackupFormat::structureFingerprint($source) !== SqlBackupFormat::structureFingerprint($destination), 'Structure fingerprints distinguish table and column changes');
    $assert(SqlBackupFormat::structureFingerprint($source) === SqlBackupFormat::structureFingerprint($source), 'Structure fingerprints are stable');
    $assert(SqlBackupFormat::portableDdl("CREATE TABLE `items` (\n  `id` int(11) NOT NULL\n) ENGINE=InnoDB")
        === SqlBackupFormat::portableDdl("CREATE TABLE `items` (\n  `id` int NOT NULL\n) ENGINE=InnoDB"), 'Portable DDL comparison is reusable');
    $producerRejected = false;
    try { SqlBackupFormat::statements($producerSql, $schema); } catch (RuntimeException) { $producerRejected = true; }
    $assert($producerRejected, 'Strict statements() still rejects unadapted producer SQL');
    Session::start();
    $_SESSION['user_id'] = 1;
    $_SESSION['_authenticated_at'] = time();
    $_SESSION['_last_activity'] = time();
    Session::start();
    $assert(isset($_SESSION['user_id']), 'Normal requests keep their authenticated session');
    RecoveryStore::write('session-epoch', 'new-generation');
    Session::start();
    $assert(!isset($_SESSION['user_id']), 'Epoch change signs out an existing session even if DB session_version matches');
    $_SESSION['user_id'] = 2;
    $_SESSION['_authenticated_at'] = time();
    $_SESSION['_last_activity'] = time();
    Session::start();
    $assert(isset($_SESSION['user_id']), 'New login after a restore remains valid');
    Session::destroy();
    echo "SQL backup format and session invalidation: passed\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    $exit = 1;
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach (glob($path . '/*') ?: [] as $child) { $remove($child); }
            @rmdir($path);
        } else { @unlink($path); }
    };
    $remove($private);
}
exit($exit);
