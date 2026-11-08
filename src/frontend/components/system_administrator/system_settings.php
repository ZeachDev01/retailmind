<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);

$message = '';
$messageClass = '';
$dormancyPolicyService = new \App\Authorization\DormancyPolicyService($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'save_dormancy') {
        try {
            $updates = [
                'disable_days' => trim((string)($_POST['disable_days'] ?? '')),
                'warn_days' => trim((string)($_POST['warn_days'] ?? '')),
            ];
            $dormancyPolicyService->update((string)current_role(), $updates, (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Dormancy Policy thresholds updated', 'Platform Settings', null, null, $updates);
            $message = 'Dormancy Policy thresholds saved.';
            $messageClass = 'tag-success';
        } catch (Throwable $exception) {
            $message = \App\Support\OperatorAlert::message($exception, 'The Dormancy Policy could not be saved. Check the values and try again. Tell your Super Administrator if this keeps happening.');
            $messageClass = 'tag-danger';
        }
    } else {
        $message = 'Choose a platform setting to save. Store and receipt settings are managed in the Administrator Workspace.';
        $messageClass = 'tag-danger';
    }
}
$dormancyThresholds = $dormancyPolicyService->thresholds();
$styleVersion = @filemtime(__DIR__ . '/../../assets/css/style.css') ?: 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Settings | RetailMind</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . $styleVersion) ?>">
</head>
<body class="platform-settings-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div>
                <h1>Platform Settings</h1>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>" data-rm-alert-processed="1" aria-live="polite">
                <i class="bi <?= $messageClass === 'tag-success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <section class="dashboard-section" aria-labelledby="heading-dormancy">
            <div class="section-header">
                <h2 id="heading-dormancy"><i class="bi bi-shield-lock" aria-hidden="true"></i> Dormancy Policy</h2>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_dormancy">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="disable_days">
                            Disable after inactivity (days)
                            <span class="required-mark" aria-hidden="true">*</span>
                        </label>
                        <input type="number"
                               min="1"
                               step="1"
                               id="disable_days"
                               name="disable_days"
                               inputmode="numeric"
                               value="<?= htmlspecialchars((string)$dormancyThresholds['disable_days']) ?>"
                               autocomplete="off"
                               aria-required="true"
                               required>
                    </div>
                    <div class="form-group">
                        <label for="warn_days">
                            Warn after inactivity (days)
                            <span class="required-mark" aria-hidden="true">*</span>
                        </label>
                        <input type="number"
                               min="0"
                               step="1"
                               id="warn_days"
                               name="warn_days"
                               inputmode="numeric"
                               value="<?= htmlspecialchars((string)$dormancyThresholds['warn_days']) ?>"
                               autocomplete="off"
                               aria-describedby="warn_days_help"
                               aria-required="true"
                               required>
                    </div>
                </div>
                <div class="form-actions u-mt-1">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check" aria-hidden="true"></i> Save Dormancy Policy</button>
                </div>
            </form>
        </section>

    </main>
</div>
</body>
</html>
