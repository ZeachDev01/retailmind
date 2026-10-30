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
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$styleCssVersion = (string)(@filemtime(__DIR__ . '/../../assets/css/style.css') ?: '1');
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Updates | RetailMind</title>
    <link rel="stylesheet" href="<?= $escape(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css') . '?v=' . rawurlencode($styleCssVersion)) ?>">
</head>
<body>
<main class="u-auth-main">
  <section class="dashboard-section u-auth-card" aria-labelledby="page-title" style="width: min(680px, 100%);">
    <header class="topbar">
      <div>
        <h1 id="page-title">Database Updates</h1>
        <p class="page-subtitle">Review and apply pending database schema migrations.</p>
      </div>
    </header>

    <?php if ($message !== ''): ?>
      <div class="alert tag-success" role="status" aria-live="polite">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        <span><?= $escape($message) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="alert tag-danger" role="alert" aria-live="assertive">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <span><?= $escape($error) ?></span>
      </div>
    <?php endif; ?>

    <article class="stat-card with-icon <?= $pending !== [] ? 'warning' : 'success' ?>" aria-label="Database update status">
      <div class="stat-icon" aria-hidden="true"><i class="bi <?= $pending !== [] ? 'bi-database-exclamation' : 'bi-database-check' ?>"></i></div>
      <div class="value"><?= count($pending) ?></div>
      <div class="label"><?= count($pending) === 1 ? 'Pending migration' : 'Pending migrations' ?></div>
      <div class="hint"><?= $pending !== [] ? 'Schema requires updates.' : 'Database schema is up to date.' ?></div>
    </article>

    <div class="table-wrap" role="region" aria-label="Migration status" tabindex="0" style="margin-top: 1rem;">
      <table>
        <thead>
          <tr>
            <th scope="col">Migration</th>
            <th scope="col">Description</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($pending === []): ?>
            <tr>
              <td colspan="3" class="u-text-center">No pending updates. Schema is up to date.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($pending as $item): ?>
              <tr>
                <td><code><?= $escape($item['key']) ?></code></td>
                <td><?= $escape($item['description']) ?></td>
                <td><span class="tag-warning">Pending</span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="u-auth-actions" style="margin-top: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap; justify-content: flex-start;">
      <a class="btn btn-quiet" href="<?= $escape(app_url('components/super_administrator/dashboard.php')) ?>">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Control Center
      </a>
      <?php if ($pending !== []): ?>
        <form method="post" style="margin: 0;">
          <?= csrf_field() ?>
          <button type="submit" class="btn" aria-label="Apply pending database updates">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Apply database updates
          </button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</main>
</body>
</html>
