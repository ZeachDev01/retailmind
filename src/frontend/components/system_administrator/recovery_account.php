<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';

require_role(['super_admin']);

$status = (new App\Services\RecoveryAccountService($pdo))->publicStatus();
$statusLabel = ucwords(str_replace('_', ' ', (string)$status['status']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recovery Account Status</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div>
                <h1>Recovery Account</h1>
                <p class="page-subtitle">Non-secret lifecycle status for the sealed recovery identity.</p>
            </div>
        </header>
        <section class="dashboard-section">
            <div class="card-grid">
                <article class="stat-card">
                    <div class="value"><?= htmlspecialchars($statusLabel) ?></div>
                    <div class="label">Status</div>
                </article>
                <article class="stat-card">
                    <div class="value"><?= htmlspecialchars($status['last_used_at'] ? format_display_datetime($status['last_used_at']) : 'Never') ?></div>
                    <div class="label">Last use</div>
                </article>
            </div>
            <div class="alert tag-warning">
                Activation, credential rotation, and resealing are available only through the documented offline procedure. No credentials or activation controls are exposed here.
            </div>
        </section>
    </main>
</div>
</body>
</html>
