<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['super_admin']);

use App\Database\MigrationRunner;

$runner = new MigrationRunner($pdo, dirname(__DIR__, 3) . '/backend/database/migrations');
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    try {
        $locked = (int)$pdo->query("SELECT GET_LOCK('retailmind_schema_update', 0)")->fetchColumn();
        if ($locked !== 1) {
            throw new RuntimeException('Another database update is already running.');
        }
        try {
            $count = $runner->runPending();
            $message = $count === 0
                ? 'The database is already up to date.'
                : "Applied {$count} database update(s).";
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('retailmind_schema_update')");
        }
    } catch (Throwable $exception) {
        error_log('Database update failed: ' . $exception);
        http_response_code(500);
        $error = 'The database update stopped. Please contact your Administrator with the time of this attempt.';
    }
}

$pending = $runner->pending();
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Updates | RetailMind</title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; background: var(--bg, #f7f8fb); color: var(--text, #202638); margin: 0; }
        main { max-width: 42rem; margin: 7vh auto; padding: 2rem; background: var(--card-bg, white); border: 1px solid var(--border, #dce2ea); border-radius: .75rem; }
        h1 { margin-top: 0; }
        .success { color: var(--theme-success-text, #166534); }
        .error { color: var(--theme-danger-text, #b91c1c); }
        button { background: #263b74; color: white; border: 0; border-radius: .4rem; padding: .75rem 1rem; font: inherit; cursor: pointer; }
        button:disabled { opacity: .5; cursor: default; }
    </style>
</head>
<body>
<main>
    <h1>Database Updates</h1>
    <?php if ($message !== ''): ?><p class="success" role="status"><?= $escape($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <p><?= count($pending) ?> update(s) pending.</p>
    <?php if ($pending !== []): ?>
        <form method="post">
            <?= csrf_field() ?>
            <button type="submit">Apply database updates</button>
        </form>
    <?php endif; ?>
    <p><a href="<?= $escape(app_url('components/user_manager/user_manager.php')) ?>">Go to Users &amp; Access</a></p>
</main>
</body>
</html>
