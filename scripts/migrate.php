<?php
// CLI: php scripts/migrate.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../config/db.php';

use App\Database\MigrationRunner;

try {
    $runner = new MigrationRunner($pdo, __DIR__ . '/../database/migrations');
    $count = $runner->runPending(static function (string $message): void {
        echo $message . PHP_EOL;
    });

    if ($count === 0) {
        echo "Database is already up to date.\n";
    } else {
        echo "Applied {$count} migration(s) successfully.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
