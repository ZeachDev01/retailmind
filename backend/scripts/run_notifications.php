<?php
// Run daily from Task Scheduler/cron to create and email operational alerts.
require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

check_and_notify_low_stock($pdo);

$recipients = $pdo->query(
    "SELECT u.user_id, u.email, COALESCE(np.notify_email, 0) AS notify_email,
            COALESCE(np.notify_inapp, 1) AS notify_inapp
     FROM users u JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN notification_preferences np ON np.user_id = u.user_id
     WHERE u.status = 'active' AND r.role_name IN ('super_admin','admin','inventory_manager')"
)->fetchAll(PDO::FETCH_ASSOC);

function scheduled_alert(PDO $pdo, array $recipient, string $title, string $body, ?int $referenceId = null, string $referenceType = 'system'): void {
    $check = $pdo->prepare("SELECT notification_id FROM notifications WHERE user_id = ? AND type = 'system' AND title = ? AND DATE(created_at) = CURDATE() LIMIT 1");
    $check->execute([(int)$recipient['user_id'], $title]);
    if ($check->fetch()) {
        return;
    }
    if (!empty($recipient['notify_inapp'])) {
        create_notification($pdo, (int)$recipient['user_id'], 'system', $title, $body, $referenceId, $referenceType);
    }
    if (!empty($recipient['notify_email']) && !empty($recipient['email'])) {
        send_email_notification((string)$recipient['email'], $title, $body);
    }
}

$expiring = $pdo->query(
    "SELECT pb.batch_id, p.product_name, pb.batch_number, pb.remaining_quantity, pb.expiration_date
     FROM product_batches pb JOIN products p ON p.product_id = pb.product_id
     WHERE pb.remaining_quantity > 0 AND pb.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY pb.expiration_date ASC"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($expiring as $batch) {
    $title = 'Expiring stock: ' . $batch['product_name'];
    $body = "Batch {$batch['batch_number']} has {$batch['remaining_quantity']} unit(s) remaining and expires on {$batch['expiration_date']}.";
    foreach ($recipients as $recipient) {
        scheduled_alert($pdo, $recipient, $title, $body, (int)$batch['batch_id'], 'product_batch');
    }
}

$spikes = $pdo->query(
    "SELECT p.product_id, p.product_name, sp.predicted_demand_next_7_days,
            COALESCE(hist.avg_weekly, 0) AS avg_weekly
     FROM stock_predictions sp
     JOIN products p ON p.product_id = sp.product_id
     LEFT JOIN (
         SELECT si.product_id, SUM(si.quantity) / 4 AS avg_weekly
         FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
         WHERE s.sale_date >= DATE_SUB(NOW(), INTERVAL 28 DAY)
         GROUP BY si.product_id
     ) hist ON hist.product_id = sp.product_id
     WHERE sp.prediction_id = (
         SELECT sp2.prediction_id FROM stock_predictions sp2
         WHERE sp2.product_id = sp.product_id ORDER BY sp2.generated_at DESC, sp2.prediction_id DESC LIMIT 1
     )
       AND hist.avg_weekly > 0
       AND sp.predicted_demand_next_7_days >= hist.avg_weekly * 1.75"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($spikes as $spike) {
    $title = 'Unusual demand forecast: ' . $spike['product_name'];
    $body = sprintf(
        'The 7-day forecast is %d units compared with a recent weekly average of %.1f units.',
        (int)$spike['predicted_demand_next_7_days'],
        (float)$spike['avg_weekly']
    );
    foreach ($recipients as $recipient) {
        scheduled_alert($pdo, $recipient, $title, $body, (int)$spike['product_id'], 'product');
    }
}

try {
    $failedRun = $pdo->query("SELECT training_run_id, error_message, started_at FROM model_training_runs WHERE status = 'failed' AND started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY started_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($failedRun) {
        $title = 'Random Forest training failure';
        $body = 'The latest model training failed on ' . $failedRun['started_at'] . '. ' . mb_strimwidth((string)$failedRun['error_message'], 0, 500, '...');
        foreach ($recipients as $recipient) {
            scheduled_alert($pdo, $recipient, $title, $body, (int)$failedRun['training_run_id'], 'model_training');
        }
    }
} catch (PDOException $e) {
    error_log('Training failure notification skipped: ' . $e->getMessage());
}

echo "Notification checks completed.\n";
