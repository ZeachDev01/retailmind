<?php
// dashboard/notification_preferences.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $notify_low_stock = isset($_POST['notify_low_stock']) ? 1 : 0;
    $notify_replenishment = isset($_POST['notify_replenishment']) ? 1 : 0;
    $notify_adjustment = isset($_POST['notify_adjustment']) ? 1 : 0;
    $notify_email = isset($_POST['notify_email']) ? 1 : 0;
    $notify_inapp = isset($_POST['notify_inapp']) ? 1 : 0;
    $low_stock_threshold = (int)($_POST['low_stock_threshold'] ?? 10);

    if ($low_stock_threshold < 1) {
        $error = 'Low stock threshold must be at least 1';
    } else {
        // Check if preferences exist
        $check_stmt = $pdo->prepare("SELECT pref_id FROM notification_preferences WHERE user_id = ?");
        $check_stmt->execute([$_SESSION['user_id']]);
        $exists = $check_stmt->fetch();

        try {
            if ($exists) {
                $stmt = $pdo->prepare("UPDATE notification_preferences 
                                      SET notify_low_stock = ?, notify_replenishment = ?, notify_adjustment = ?,
                                          notify_email = ?, notify_inapp = ?, low_stock_threshold = ?
                                      WHERE user_id = ?");
                $stmt->execute([$notify_low_stock, $notify_replenishment, $notify_adjustment, 
                               $notify_email, $notify_inapp, $low_stock_threshold, $_SESSION['user_id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO notification_preferences 
                                      (user_id, notify_low_stock, notify_replenishment, notify_adjustment,
                                       notify_email, notify_inapp, low_stock_threshold)
                                      VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $notify_low_stock, $notify_replenishment, $notify_adjustment,
                               $notify_email, $notify_inapp, $low_stock_threshold]);
            }
            $message = "Notification preferences updated successfully!";
            log_activity($pdo, $_SESSION['user_id'], "Updated notification preferences");
        } catch (Exception $e) {
            $error = "Error updating preferences: " . $e->getMessage();
        }
    }
}

// Get current preferences
$prefs = get_notification_prefs($pdo, $_SESSION['user_id']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Preferences</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <style>
        .preferences-form {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 2rem;
            max-width: 600px;
        }
        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid #eee;
        }
        .form-section:last-of-type {
            border-bottom: none;
            margin-bottom: 1.5rem;
        }
        .form-section h3 {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: #333;
        }
        .form-section p {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        .form-group {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        .form-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 1rem;
            cursor: pointer;
        }
        .form-group label {
            flex: 1;
            cursor: pointer;
            margin: 0;
            font-weight: 500;
        }
        .form-group .description {
            display: block;
            margin-top: 0.25rem;
            color: #666;
            font-size: 0.85rem;
            margin-left: 2rem;
        }
        .threshold-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-top: 1rem;
            margin-left: 2rem;
        }
        .threshold-group label {
            margin: 0;
            font-weight: 500;
        }
        .threshold-group input {
            width: 80px;
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .message {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .btn-submit {
            background: #007bff;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            width: 100%;
        }
        .btn-submit:hover {
            background: #0056b3;
        }
        .info-box {
            background: #f0f7ff;
            border: 1px solid #b3d9ff;
            border-radius: 4px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #004085;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../../../../frontend/components/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>Notification Preferences</h1>
        </div>

        <div class="info-box">
            <strong>ℹ️ Tip:</strong> Customize how and when you receive notifications about inventory events, 
            stock levels, and system activities.
        </div>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="preferences-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">

            <div class="form-section">
                <h3>📬 Notification Types</h3>
                <p>Choose which types of notifications you want to receive:</p>

                <div class="form-group">
                    <input type="checkbox" name="notify_low_stock" id="notify_low_stock" 
                           <?= $prefs['notify_low_stock'] ? 'checked' : '' ?>>
                    <label for="notify_low_stock">Low Stock Alerts</label>
                    <span class="description">Notified when inventory levels drop below reorder point</span>
                </div>

                <div class="form-group">
                    <input type="checkbox" name="notify_replenishment" id="notify_replenishment" 
                           <?= $prefs['notify_replenishment'] ? 'checked' : '' ?>>
                    <label for="notify_replenishment">Replenishment Requests</label>
                    <span class="description">Notified about pending and approved replenishment requests</span>
                </div>

                <div class="form-group">
                    <input type="checkbox" name="notify_adjustment" id="notify_adjustment" 
                           <?= $prefs['notify_adjustment'] ? 'checked' : '' ?>>
                    <label for="notify_adjustment">Inventory Adjustments</label>
                    <span class="description">Notified when adjustments (damaged/expired/missing) are reported</span>
                </div>
            </div>

            <div class="form-section">
                <h3>📨 Delivery Method</h3>
                <p>Select how you'd like to receive notifications:</p>

                <div class="form-group">
                    <input type="checkbox" name="notify_inapp" id="notify_inapp" 
                           <?= $prefs['notify_inapp'] ? 'checked' : '' ?>>
                    <label for="notify_inapp">In-App Notifications</label>
                    <span class="description">Notifications appear in the notification center (default: enabled)</span>
                </div>

                <div class="form-group">
                    <input type="checkbox" name="notify_email" id="notify_email" 
                           <?= $prefs['notify_email'] ? 'checked' : '' ?>>
                    <label for="notify_email">Email Notifications</label>
                    <span class="description">Send important updates to <?= htmlspecialchars($_SESSION['email'] ?? 'your email') ?></span>
                </div>
            </div>

            <div class="form-section">
                <h3>⚙️ Advanced Settings</h3>
                <p>Fine-tune notification triggers:</p>

                <div class="threshold-group">
                    <label for="low_stock_threshold">Low Stock Threshold (units):</label>
                    <input type="number" name="low_stock_threshold" id="low_stock_threshold" 
                           value="<?= htmlspecialchars($prefs['low_stock_threshold']) ?>" min="1">
                    <span style="color: #666; font-size: 0.85rem;">Override default reorder levels for all products</span>
                </div>
            </div>

            <button type="submit" class="btn-submit">Save Preferences</button>
        </form>
    </div>
</div>
</body>
</html>
