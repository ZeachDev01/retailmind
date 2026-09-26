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
    Session::start();
    $_SESSION['user_id'] = 1;
    Session::start();
    $assert(isset($_SESSION['user_id']), 'Normal requests keep their authenticated session');
    RecoveryStore::write('session-epoch', 'new-generation');
    Session::start();
    $assert(!isset($_SESSION['user_id']), 'Epoch change signs out an existing session even if DB session_version matches');
    $_SESSION['user_id'] = 2;
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
