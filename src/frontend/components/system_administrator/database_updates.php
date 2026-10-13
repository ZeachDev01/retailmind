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
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>">
</head>
<body>
<main class="u-auth-main">
  <section class="dashboard-section u-auth-card">
    <header class="topbar"><div><h1>Database Updates</h1><p class="page-subtitle">Review and apply pending database updates.</p></div></header>
    <?php if ($message !== ''): ?><p class="alert tag-success" role="status"><?= $escape($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="alert tag-danger" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <p class="section-description"><?= count($pending) ?> update(s) pending.</p>
    <?php if ($pending !== []): ?>
        <form method="post">
            <?= csrf_field() ?>
            <button type="submit" class="btn">Apply database updates</button>
        </form>
    <?php endif; ?>
    <a class="btn btn-quiet" href="<?= $escape(app_url('components/super_administrator/dashboard.php')) ?>">Back to Control Center</a>
  </section>
</main>
</body>
</html>
