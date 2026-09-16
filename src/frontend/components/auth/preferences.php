<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';

if (!is_logged_in()) {
    header('Location: ' . app_url('?login=1'));
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $notifyLowStock = isset($_POST['notify_low_stock']) ? 1 : 0;
    $notifyReplenishment = isset($_POST['notify_replenishment']) ? 1 : 0;
    $notifyAdjustment = isset($_POST['notify_adjustment']) ? 1 : 0;
    $notifyEmail = isset($_POST['notify_email']) ? 1 : 0;
    $notifyInApp = isset($_POST['notify_inapp']) ? 1 : 0;
    $lowStockThreshold = (int)($_POST['low_stock_threshold'] ?? 10);

    if ($lowStockThreshold < 1) {
        $error = 'Low stock threshold must be at least 1.';
    } else {
        try {
            $checkStmt = $pdo->prepare('SELECT pref_id FROM notification_preferences WHERE user_id = ?');
            $checkStmt->execute([(int)$_SESSION['user_id']]);

            if ($checkStmt->fetchColumn()) {
                $stmt = $pdo->prepare(
                    'UPDATE notification_preferences
                     SET notify_low_stock = ?, notify_replenishment = ?, notify_adjustment = ?,
                         notify_email = ?, notify_inapp = ?, low_stock_threshold = ?
                     WHERE user_id = ?'
                );
                $stmt->execute([
                    $notifyLowStock,
                    $notifyReplenishment,
                    $notifyAdjustment,
                    $notifyEmail,
                    $notifyInApp,
                    $lowStockThreshold,
                    (int)$_SESSION['user_id'],
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO notification_preferences
                        (user_id, notify_low_stock, notify_replenishment, notify_adjustment,
                         notify_email, notify_inapp, low_stock_threshold)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    (int)$_SESSION['user_id'],
                    $notifyLowStock,
                    $notifyReplenishment,
                    $notifyAdjustment,
                    $notifyEmail,
                    $notifyInApp,
                    $lowStockThreshold,
                ]);
            }

            log_activity($pdo, (int)$_SESSION['user_id'], 'Updated notification preferences');
            $message = 'Preferences updated successfully.';
        } catch (Throwable $e) {
            $error = 'Unable to update preferences right now. Please try again.';
        }
    }
}

$prefs = get_notification_prefs($pdo, (int)$_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preferences</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/preferences.css')) ?>">
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content account-preferences">
            <header class="page-heading">
                <div>
                    <h1>Preferences</h1>
                    <p class="page-subtitle">Choose which inventory updates you receive and how they reach you.</p>
                </div>
                <div class="page-heading-actions">
                    <a class="btn btn-quiet btn-icon" href="<?= htmlspecialchars(app_url('components/auth/user_info.php')) ?>">
                        <i class="bi bi-person-circle" aria-hidden="true"></i>Profile
                    </a>
                </div>
            </header>

            <?php if ($message !== ''): ?>
                <div class="alert tag-success" role="status"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert tag-warning" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="preferences-panel">
                <?= csrf_field() ?>

                <section class="preference-section" aria-labelledby="notification-types-heading">
                    <div class="preference-section-heading">
                        <span class="preference-section-icon"><i class="bi bi-bell" aria-hidden="true"></i></span>
                        <div>
                            <h2 id="notification-types-heading">Notification types</h2>
                            <p>Select the inventory activity you want RetailMind to notify you about.</p>
                        </div>
                    </div>

                    <div class="preference-options">
                        <label class="preference-option" for="notify_low_stock">
                            <span>
                                <strong>Low stock alerts</strong>
                                <small>Receive an alert when inventory drops below its reorder point.</small>
                            </span>
                            <input type="checkbox" name="notify_low_stock" id="notify_low_stock" <?= !empty($prefs['notify_low_stock']) ? 'checked' : '' ?>>
                        </label>

                        <label class="preference-option" for="notify_replenishment">
                            <span>
                                <strong>Replenishment requests</strong>
                                <small>Receive updates about pending and approved replenishment requests.</small>
                            </span>
                            <input type="checkbox" name="notify_replenishment" id="notify_replenishment" <?= !empty($prefs['notify_replenishment']) ? 'checked' : '' ?>>
                        </label>

                        <label class="preference-option" for="notify_adjustment">
                            <span>
                                <strong>Inventory adjustments</strong>
                                <small>Receive updates when damaged, expired, or missing stock is reported.</small>
                            </span>
                            <input type="checkbox" name="notify_adjustment" id="notify_adjustment" <?= !empty($prefs['notify_adjustment']) ? 'checked' : '' ?>>
                        </label>
                    </div>
                </section>

                <section class="preference-section" aria-labelledby="delivery-method-heading">
                    <div class="preference-section-heading">
                        <span class="preference-section-icon"><i class="bi bi-send" aria-hidden="true"></i></span>
                        <div>
                            <h2 id="delivery-method-heading">Delivery method</h2>
                            <p>Control where enabled notifications are delivered.</p>
                        </div>
                    </div>

                    <div class="preference-options">
                        <label class="preference-option" for="notify_inapp">
                            <span>
                                <strong>In-app notifications</strong>
                                <small>Show updates in the RetailMind notification center.</small>
                            </span>
                            <input type="checkbox" name="notify_inapp" id="notify_inapp" <?= !empty($prefs['notify_inapp']) ? 'checked' : '' ?>>
                        </label>

                        <label class="preference-option" for="notify_email">
                            <span>
                                <strong>Email notifications</strong>
                                <small>Send important updates to the email address on your profile.</small>
                            </span>
                            <input type="checkbox" name="notify_email" id="notify_email" <?= !empty($prefs['notify_email']) ? 'checked' : '' ?>>
                        </label>
                    </div>
                </section>

                <section class="preference-section" aria-labelledby="advanced-settings-heading">
                    <div class="preference-section-heading">
                        <span class="preference-section-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span>
                        <div>
                            <h2 id="advanced-settings-heading">Advanced settings</h2>
                            <p>Fine-tune when low stock notifications are triggered.</p>
                        </div>
                    </div>

                    <div class="threshold-field">
                        <div>
                            <label for="low_stock_threshold">Low stock threshold</label>
                            <small>Override the default reorder level for all products.</small>
                        </div>
                        <div class="threshold-input">
                            <input type="number" name="low_stock_threshold" id="low_stock_threshold" value="<?= htmlspecialchars((string)$prefs['low_stock_threshold']) ?>" min="1" required>
                            <span>units</span>
                        </div>
                    </div>
                </section>

                <div class="preferences-actions">
                    <button type="submit" class="btn btn-icon">
                        <i class="bi bi-check2" aria-hidden="true"></i>Save Preferences
                    </button>
                </div>
            </form>
        </main>
    </div>
</body>

</html>
