<?php
// includes/functions.php

require_once __DIR__ . '/../config/ml_config.php';

function get_client_ip_address(): string
{
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = $_SERVER[$key] ?? '';
        if ($value === '') {
            continue;
        }

        $ip = trim(explode(',', $value)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return 'unknown';
}

function format_audit_value($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return (string)$value;
}

function activity_log_columns(PDO $pdo): array
{
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }

    $columns = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM activity_log')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['Field']] = true;
        }
    } catch (Throwable $e) {
        $columns = ['user_id' => true, 'action' => true];
    }

    return $columns;
}

function log_activity(
    PDO $pdo,
    ?int $user_id,
    string $action,
    ?string $module = null,
    ?int $record_id = null,
    $previous_value = null,
    $new_value = null,
    ?string $ip_address = null
): void {
    $columns = activity_log_columns($pdo);
    $data = [
        'user_id' => $user_id,
        'action' => $action,
        'module' => $module,
        'record_id' => $record_id,
        'previous_value' => format_audit_value($previous_value),
        'new_value' => format_audit_value($new_value),
        'ip_address' => $ip_address ?: get_client_ip_address(),
    ];

    $insert = [];
    foreach ($data as $column => $value) {
        if (isset($columns[$column])) {
            $insert[$column] = $value;
        }
    }

    $fieldList = implode(', ', array_keys($insert));
    $placeholders = implode(', ', array_fill(0, count($insert), '?'));
    $stmt = $pdo->prepare("INSERT INTO activity_log ($fieldList) VALUES ($placeholders)");
    $stmt->execute(array_values($insert));
}

function get_low_stock_products(PDO $pdo, ?int $threshold = null): array {
    $sql = "SELECT p.product_id, p.product_name, p.sku, i.quantity_on_hand, p.reorder_level
            FROM products p
            JOIN inventory i ON p.product_id = i.product_id";
    $products = $pdo->query($sql)->fetchAll();

    if ($threshold !== null && $threshold >= 0) {
        return array_values(array_filter($products, function ($product) use ($threshold): bool {
            return (int)$product['quantity_on_hand'] <= $threshold;
        }));
    }

    return array_values(array_filter($products, function ($product): bool {
        return (int)$product['quantity_on_hand'] <= (int)$product['reorder_level'];
    }));
}

/**
 * Call the authenticated Python ML microservice.
 */
function call_ml_api(string $endpoint, string $method = 'GET', ?array $payload = null, ?int $timeout = null): array {
    $headers = ['Accept: application/json'];
    if (defined('ML_API_KEY') && ML_API_KEY !== '') {
        $headers[] = 'X-API-Key: ' . ML_API_KEY;
    }
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout ?? (defined('ML_API_TIMEOUT') ? ML_API_TIMEOUT : 30),
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $response === false || $response === '') {
        return ['success' => false, 'message' => $error ?: 'ML service unavailable', '_http_code' => $httpCode];
    }
    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        return ['success' => false, 'message' => 'Invalid ML response', '_http_code' => $httpCode];
    }
    $data['_http_code'] = $httpCode;
    return $data;
}

function get_ml_prediction(int $product_id): array {
    return call_ml_api(ML_API_PREDICT_ENDPOINT, 'POST', ['product_id' => $product_id], 15);
}

function get_stored_predictions(PDO $pdo): array {
    return array_values(array_filter(get_forecasting_readiness($pdo), static function (array $row): bool {
        return !empty($row['can_show_forecast']);
    }));
}

function get_ml_model_metrics(): array {
    $metricsPath = __DIR__ . '/../ml/model_metrics.json';
    if (!is_readable($metricsPath)) {
        return [];
    }
    $metrics = json_decode((string)file_get_contents($metricsPath), true);
    return is_array($metrics) ? $metrics : [];
}

function format_ml_metric(array $metrics, string $key, int $decimals = 2): string {
    if (!array_key_exists($key, $metrics) || $metrics[$key] === null || $metrics[$key] === '') {
        return '-';
    }
    return is_numeric($metrics[$key])
        ? number_format((float)$metrics[$key], $decimals)
        : (string)$metrics[$key];
}

function ensure_key_value_table(PDO $pdo, string $table): void {
    $allowed = ['ml_settings', 'store_settings'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported settings table.');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
    )");
}

function get_key_value_settings(PDO $pdo, string $table, array $defaults): array {
    ensure_key_value_table($pdo, $table);
    $settings = $defaults;
    foreach ($pdo->query("SELECT setting_key, setting_value FROM {$table}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function save_key_value_settings(PDO $pdo, string $table, array $settings, ?int $userId = null): void {
    ensure_key_value_table($pdo, $table);
    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
    );
    foreach ($settings as $key => $value) {
        $stmt->execute([(string)$key, (string)$value, $userId]);
    }
}

function get_ml_settings(PDO $pdo): array {
    return get_key_value_settings($pdo, 'ml_settings', [
        'minimum_history_days' => '30',
        'preferred_history_days' => '90',
        'minimum_nonzero_sales_days' => '5',
        'history_window_days' => '365',
        'forecast_period_days' => '30',
        'n_estimators' => '300',
        'max_depth' => '18',
        'min_samples_split' => '4',
        'min_samples_leaf' => '2',
        'retrain_frequency_days' => '7',
        'retrain_new_sales_records' => '100',
        'accuracy_threshold_wape' => '35',
        'prediction_interval_lower' => '10',
        'prediction_interval_upper' => '90',
        'holiday_dates' => '',
    ]);
}

function get_store_settings(PDO $pdo): array {
    return get_key_value_settings($pdo, 'store_settings', [
        'store_name' => 'Shalom Store',
        'store_address' => 'Tangub City',
        'store_phone' => '',
        'store_email' => '',
        'business_identifier' => '',
        'receipt_footer' => 'Thank you for shopping with us.',
        'currency_symbol' => '₱',
        'timezone' => 'Asia/Manila',
    ]);
}

function get_forecasting_readiness(PDO $pdo): array {
    $settings = get_ml_settings($pdo);
    $minimumHistoryDays = max(1, (int)$settings['minimum_history_days']);
    $preferredHistoryDays = max($minimumHistoryDays, (int)$settings['preferred_history_days']);
    $minimumNonzeroDays = max(1, (int)$settings['minimum_nonzero_sales_days']);
    $currentModelVersion = 'rf-v2';

    $sql = "SELECT
                p.product_id,
                COALESCE(p.barcode, p.sku) AS sku,
                p.product_name,
                COALESCE(c.category_name, 'Uncategorized') AS category,
                COALESCE(i.quantity_on_hand, 0) AS current_stock,
                COALESCE(incoming.incoming_stock, 0) AS incoming_stock,
                COALESCE(p.reorder_level, 10) AS reorder_level,
                COALESCE(p.supplier_lead_time_days, 7) AS supplier_lead_time_days,
                COALESCE(p.safety_stock, 0) AS safety_stock,
                COALESCE(p.minimum_order_quantity, 1) AS minimum_order_quantity,
                COALESCE(p.units_per_package, 1) AS units_per_package,
                COALESCE(NULLIF(p.preferred_supplier, ''), p.supplier, '') AS preferred_supplier,
                COALESCE(stats.nonzero_sales_days, 0) AS nonzero_sales_days,
                CASE WHEN stats.first_sale_date IS NULL THEN 0 ELSE DATEDIFF(CURDATE(), stats.first_sale_date) + 1 END AS calendar_history_days,
                CASE WHEN stats.first_sale_date IS NULL THEN 0 ELSE GREATEST((DATEDIFF(CURDATE(), stats.first_sale_date) + 1) - stats.nonzero_sales_days, 0) END AS zero_sales_days,
                stats.first_sale_date,
                stats.last_sale_at,
                COALESCE(stats.sales_records, 0) AS sales_records,
                COALESCE(stats.total_qty_sold, 0) AS total_qty_sold,
                sp.prediction_id, sp.forecast_run_id, sp.forecast_period_days, sp.forecast_value,
                sp.predicted_demand_next_7_days, sp.predicted_demand_next_30_days,
                sp.lower_bound_7_days, sp.upper_bound_7_days,
                sp.lower_bound_30_days, sp.upper_bound_30_days,
                sp.forecasted_demand_during_lead_time,
                sp.lead_time_lower_bound, sp.lead_time_upper_bound,
                sp.actual_demand, sp.evaluation_result,
                sp.supplier_lead_time_days AS prediction_supplier_lead_time_days,
                sp.safety_stock_used, sp.current_stock_used, sp.incoming_stock_used,
                sp.minimum_order_quantity_used, sp.units_per_package_used,
                sp.reorder_suggested, sp.suggested_reorder_qty, sp.confidence_score,
                sp.forecast_explanation, sp.model_version, sp.generated_at,
                fe.mae AS product_mae, fe.rmse AS product_rmse,
                fe.wape AS product_wape, fe.smape AS product_smape
            FROM products p
            LEFT JOIN categories c ON c.category_id = p.category_id
            LEFT JOIN inventory i ON i.product_id = p.product_id
            LEFT JOIN (
                SELECT rr.product_id,
                       SUM(GREATEST(rr.request_qty - COALESCE(received.received_to_date, 0), 0)) AS incoming_stock
                FROM replenishment_requests rr
                LEFT JOIN (
                    SELECT replenishment_request_id, SUM(received_qty) AS received_to_date
                    FROM stock_receiving WHERE replenishment_request_id IS NOT NULL
                    GROUP BY replenishment_request_id
                ) received ON received.replenishment_request_id = rr.request_id
                WHERE rr.status IN ('approved', 'partially_received')
                GROUP BY rr.product_id
            ) incoming ON incoming.product_id = p.product_id
            LEFT JOIN (
                SELECT si.product_id,
                       COUNT(DISTINCT DATE(s.sale_date)) AS nonzero_sales_days,
                       MIN(DATE(s.sale_date)) AS first_sale_date,
                       MAX(s.sale_date) AS last_sale_at,
                       COUNT(*) AS sales_records,
                       SUM(si.quantity) AS total_qty_sold
                FROM sale_items si JOIN sales s ON si.sale_id = s.sale_id
                GROUP BY si.product_id
            ) stats ON stats.product_id = p.product_id
            LEFT JOIN stock_predictions sp ON sp.prediction_id = (
                SELECT sp2.prediction_id FROM stock_predictions sp2
                WHERE sp2.product_id = p.product_id
                ORDER BY sp2.generated_at DESC, sp2.prediction_id DESC LIMIT 1
            )
            LEFT JOIN forecast_evaluations fe ON fe.evaluation_id = (
                SELECT fe2.evaluation_id FROM forecast_evaluations fe2
                WHERE fe2.product_id = p.product_id
                ORDER BY fe2.generated_at DESC, fe2.evaluation_id DESC LIMIT 1
            )
            WHERE p.status = 'active'
            ORDER BY p.product_name";

    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        // The migration has not been run yet. Keep the page usable with the legacy columns.
        error_log('Forecast readiness requires sql/upgrade_random_forest_v2.sql: ' . $exception->getMessage());
        return [];
    }

    foreach ($rows as &$row) {
        $calendarDays = (int)$row['calendar_history_days'];
        $nonzeroDays = (int)$row['nonzero_sales_days'];
        $hasPrediction = !empty($row['prediction_id']);
        $modelVersion = (string)($row['model_version'] ?? '');
        $generatedAt = $row['generated_at'] ?? null;
        $lastSaleAt = $row['last_sale_at'] ?? null;
        $row['sales_history_days'] = $calendarDays; // backward-compatible page key
        $row['missing_dates'] = (int)$row['zero_sales_days'];
        $row['can_show_forecast'] = false;
        $row['forecast_status'] = 'Insufficient Data';
        $row['forecast_reason'] = sprintf(
            '%d calendar days and %d days with sales are available. At least %d calendar days and %d nonzero-sales days are required.',
            $calendarDays,
            $nonzeroDays,
            $minimumHistoryDays,
            $minimumNonzeroDays
        );

        if ($calendarDays < $minimumHistoryDays || $nonzeroDays < $minimumNonzeroDays) {
            continue;
        }
        if (!$hasPrediction) {
            $row['forecast_status'] = 'Ready for Forecasting';
            $row['forecast_reason'] = 'The product has enough data. Run the Random Forest retraining job to generate a forecast.';
            continue;
        }
        if ($modelVersion !== $currentModelVersion) {
            $row['forecast_status'] = 'Model Requires Retraining';
            $row['forecast_reason'] = 'The stored forecast was created by an older model version.';
            continue;
        }
        if ($lastSaleAt && $generatedAt && strtotime((string)$lastSaleAt) > strtotime((string)$generatedAt)) {
            $row['forecast_status'] = 'Model Requires Retraining';
            $row['forecast_reason'] = 'New sales were recorded after the latest forecast.';
            continue;
        }

        $row['can_show_forecast'] = true;
        $row['forecast_status'] = 'Forecast Generated';
        $row['forecast_reason'] = $calendarDays >= $preferredHistoryDays
            ? "{$calendarDays} complete calendar days are available, meeting the preferred history target."
            : "Forecast available. Collecting {$preferredHistoryDays} complete calendar days is preferred.";
    }
    unset($row);

    $priority = ['Model Requires Retraining' => 0, 'Ready for Forecasting' => 1, 'Forecast Generated' => 2, 'Insufficient Data' => 3];
    usort($rows, static function (array $a, array $b) use ($priority): int {
        return ($priority[$a['forecast_status']] ?? 4) <=> ($priority[$b['forecast_status']] ?? 4)
            ?: strcasecmp((string)$a['product_name'], (string)$b['product_name']);
    });
    return $rows;
}

// ========== NOTIFICATION FUNCTIONS ==========

/**
 * Create a notification for a user
 */
function create_notification(PDO $pdo, int $user_id, string $type, string $title, string $message, 
                            int $reference_id = null, string $reference_type = null): void {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type) 
                           VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $type, $title, $message, $reference_id, $reference_type]);
}

/**
 * Get unread notifications for a user
 */
function get_unread_notifications(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("SELECT notification_id, type, title, message, reference_id, reference_type, created_at
                           FROM notifications WHERE user_id = ? AND is_read = FALSE
                           ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

/**
 * Get notification count for user
 */
function get_notification_count(PDO $pdo, int $user_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->execute([$user_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Mark notification as read
 */
function mark_notification_read(PDO $pdo, int $notification_id): void {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE notification_id = ?");
    $stmt->execute([$notification_id]);
}

/**
 * Get user notification preferences
 */
function get_notification_prefs(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("SELECT * FROM notification_preferences WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $prefs = $stmt->fetch();
    
    // Return defaults if not set
    if (!$prefs) {
        return [
            'notify_low_stock' => true,
            'notify_replenishment' => true,
            'notify_adjustment' => false,
            'notify_email' => false,
            'notify_inapp' => true,
            'low_stock_threshold' => 10
        ];
    }
    return $prefs;
}

/**
 * Check low-stock products and create notifications for users with relevant preferences
 */
function check_and_notify_low_stock(PDO $pdo): void {
    $users_stmt = $pdo->query(
        "SELECT u.user_id, u.email, COALESCE(np.low_stock_threshold, 10) AS low_stock_threshold,
                COALESCE(np.notify_email, 0) AS notify_email, COALESCE(np.notify_inapp, 1) AS notify_inapp
         FROM users u
         LEFT JOIN notification_preferences np ON np.user_id = u.user_id
         WHERE u.status = 'active' AND COALESCE(np.notify_low_stock, 1) = 1"
    );
    $users = $users_stmt->fetchAll();

    foreach ($users as $user) {
        $threshold = isset($user['low_stock_threshold']) && (int)$user['low_stock_threshold'] > 0
            ? (int)$user['low_stock_threshold']
            : null;
        $low_stock = get_low_stock_products($pdo, $threshold);

        foreach ($low_stock as $product) {
            $check_stmt = $pdo->prepare("SELECT notification_id FROM notifications 
                                        WHERE user_id = ? AND type = 'low_stock' 
                                        AND reference_id = ?
                                        AND DATE(created_at) = CURDATE()");
            $check_stmt->execute([$user['user_id'], $product['product_id']]);

            if (!$check_stmt->fetch()) {
                $reorder_target = $threshold !== null ? $threshold : $product['reorder_level'];
                $title = 'Low Stock Alert: ' . $product['product_name'];
                $body = "Product {$product['product_name']} ({$product['sku']}) is at or below the threshold. Current: {$product['quantity_on_hand']}, Threshold: {$reorder_target}";
                if (!empty($user['notify_inapp'])) {
                    create_notification(
                        $pdo,
                        (int)$user['user_id'],
                        'low_stock',
                        $title,
                        $body,
                        (int)$product['product_id'],
                        'product'
                    );
                }
                if (!empty($user['notify_email']) && !empty($user['email'])) {
                    send_email_notification((string)$user['email'], $title, $body);
                }
            }
        }
    }
}

/**
 * Send an email through PHPMailer SMTP when configured. Falls back to PHP mail.
 */
function send_email_notification(string $email, string $subject, string $message): bool {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $success = false;
    $provider = 'disabled';
    $errorMessage = null;
    try {
        $mailer = strtolower((string)env('MAIL_MAILER', 'smtp'));
        if ($mailer === 'smtp' && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $provider = 'smtp';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string)env('MAIL_HOST', '');
            $mail->Port = (int)env('MAIL_PORT', 587);
            $mail->SMTPAuth = (string)env('MAIL_USERNAME', '') !== '';
            $mail->Username = (string)env('MAIL_USERNAME', '');
            $mail->Password = (string)env('MAIL_PASSWORD', '');
            $encryption = strtolower((string)env('MAIL_ENCRYPTION', 'tls'));
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            $from = (string)env('MAIL_FROM_ADDRESS', 'no-reply@retailmind.local');
            $mail->setFrom($from, (string)env('MAIL_FROM_NAME', 'RetailMind'));
            $mail->addAddress($email);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->AltBody = strip_tags($message);
            $success = $mail->send();
        } elseif ($mailer === 'mail') {
            $provider = 'php-mail';
            $from = (string)env('MAIL_FROM_ADDRESS', 'no-reply@retailmind.local');
            $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8";
            $success = mail($email, $subject, $message, $headers);
        } else {
            $errorMessage = 'Email is not configured. Install Composer dependencies and set MAIL_* values.';
        }
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
        error_log('Email delivery failed: ' . $exception->getMessage());
    }

    try {
        $pdo = App\Core\Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_delivery_log (
            email_log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            recipient VARCHAR(190) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status ENUM('sent','failed','queued') NOT NULL,
            provider VARCHAR(50) NULL,
            error_message TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $stmt = $pdo->prepare('INSERT INTO email_delivery_log (recipient, subject, status, provider, error_message) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$email, $subject, $success ? 'sent' : 'failed', $provider, $errorMessage]);
    } catch (Throwable $loggingError) {
        error_log('Could not write email delivery log: ' . $loggingError->getMessage());
    }
    return $success;
}

