<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin', 'inventory_manager']);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $status = $_POST['guidance_status'] ?? '';
    if (!is_string($status) || !in_array($status, ['skipped', 'deferred', 'completed'], true)) {
        http_response_code(422);
        $error = 'Choose Finish overview, Skip guidance or Not now.';
    } else {
        try {
            // Reopening Help cannot reset a completed overview.
            $query = $pdo->prepare('INSERT INTO workspace_guidance (user_id, workspace, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = IF(status = \'completed\', status, VALUES(status))');
            $query->execute([(int)$_SESSION['user_id'], current_role(), $status]);
            $target = $_POST['return_to'] ?? '';
            $prefix = preg_quote(app_url('components/'), '~');
            if (!is_string($target) || !preg_match('~^' . $prefix . '[a-z_]+/[a-z_]+\.php$~D', $target)) {
                $target = app_url('components/auth/help.php');
            }
            header('Location: ' . $target, true, 303);
            exit;
        } catch (Throwable $e) {
            $error = \App\Support\OperatorAlert::message($e, 'Unable to save your guidance choice. Please try again.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Workspace Help</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading"><h1>Workspace Help</h1></header>
        <?php if ($error !== ''): ?><div class="alert tag-warning" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <section class="workspace-guidance" aria-labelledby="workspace-guidance-title">
            <?php $guidanceIsHelp = true; include __DIR__ . '/workspace_overview.php'; ?>
            <?php include __DIR__ . '/workspace_guidance_actions.php'; ?>
        </section>
    </main>
</div>
</body>
</html>
