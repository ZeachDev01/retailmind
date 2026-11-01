<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin', 'inventory_manager']);
$error = '';
$sanitizeRedirect = static function (mixed $target): string {
    $prefix = preg_quote(app_url('components/'), '~');
    return (is_string($target) && preg_match('~^' . $prefix . '[a-z_]+/[a-z_]+\.php$~D', $target))
        ? $target
        : app_url('components/auth/help.php');
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['confirm_task'])) {
        $task = (string)$_POST['confirm_task'];
        try {
            \App\Store\StoreSetupConfirmations::confirm($pdo, $task, (int)$_SESSION['user_id'], current_role());
            if (\App\Store\StoreSetupConfirmations::isComplete($pdo)) {
                $_SESSION['store_setup_just_completed'] = true;
            }
            header('Location: ' . $sanitizeRedirect($_POST['return_to'] ?? null), true, 303);
            exit;
        } catch (DomainException $e) {
            http_response_code(403);
            $error = $e->getMessage();
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = \App\Support\OperatorAlert::message($e, 'Unable to save your confirmation. Please try again.');
        }
    } else {
        $status = $_POST['guidance_status'] ?? '';
        if (!is_string($status) || !in_array($status, ['skipped', 'deferred', 'completed'], true)) {
            http_response_code(422);
            $error = 'Choose Finish overview, Skip guidance or Not now.';
        } else {
            try {
                // Reopening Help cannot reset a completed overview.
                $query = $pdo->prepare('INSERT INTO workspace_guidance (user_id, workspace, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = IF(status = \'completed\', status, VALUES(status))');
                $query->execute([(int)$_SESSION['user_id'], current_role(), $status]);
                header('Location: ' . $sanitizeRedirect($_POST['return_to'] ?? null), true, 303);
                exit;
            } catch (Throwable $e) {
                $error = \App\Support\OperatorAlert::message($e, 'Unable to save your guidance choice. Please try again.');
            }
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
        <?php if (!empty($_SESSION['store_setup_just_completed'])): unset($_SESSION['store_setup_just_completed']); ?>
            <div id="store-setup-completion" class="alert tag-success store-setup-completion" role="status" data-rm-alert-processed="1">
                <strong>Store setup complete.</strong> Essential product, opening-stock, and Store calendar setup tasks are confirmed. Automatic onboarding reminders are now turned off. You can revisit guidance anytime from Help in your account menu.
            </div>
        <?php endif; ?>
        <section class="workspace-guidance" aria-labelledby="workspace-guidance-title">
            <?php $guidanceIsHelp = true; include __DIR__ . '/workspace_overview.php'; ?>
            <?php include __DIR__ . '/store_setup_checklist.php'; ?>
            <?php include __DIR__ . '/workspace_guidance_actions.php'; ?>
        </section>
    </main>
</div>
</body>
</html>
