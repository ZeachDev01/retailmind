<?php

namespace App\Services;

use PDO;
use Throwable;

class StockBriefService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Build the structured Daily Stock Brief report content.
     */
    public function buildReport(): array
    {
        require_once __DIR__ . '/../../includes/functions.php';
        $settings = get_store_settings($this->pdo);
        $storeName = htmlspecialchars((string)($settings['store_name'] ?? 'RetailMind'), ENT_QUOTES, 'UTF-8');
        $dateStr = date('F j, Y, g:i A');

        // Query low and out of stock items
        $stockSql = "SELECT p.product_id, p.product_name, p.sku, COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand, p.reorder_level
                     FROM products p
                     LEFT JOIN inventory i ON p.product_id = i.product_id
                     WHERE COALESCE(i.quantity_on_hand, 0) <= p.reorder_level
                     ORDER BY (COALESCE(i.quantity_on_hand, 0) <= 0) DESC, i.quantity_on_hand ASC, p.product_name ASC";
        $stockItems = $this->pdo->query($stockSql)->fetchAll(PDO::FETCH_ASSOC);

        $outOfStock = [];
        $lowStock = [];
        foreach ($stockItems as $item) {
            if ((int)$item['quantity_on_hand'] <= 0) {
                $outOfStock[] = $item;
            } else {
                $lowStock[] = $item;
            }
        }

        // Query expiring batches (within 30 days or overdue)
        $today = date('Y-m-d');
        $todayQuoted = $this->pdo->quote($today);
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $diffExpr = "CAST(ROUND(julianday(pb.expiration_date) - julianday({$todayQuoted})) AS INTEGER)";
        } else {
            $diffExpr = "DATEDIFF(pb.expiration_date, {$todayQuoted})";
        }

        $expSql = "SELECT pb.batch_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
                          {$diffExpr} AS days_remaining, p.product_name, p.sku
                   FROM product_batches pb
                   JOIN products p ON p.product_id = pb.product_id
                   WHERE pb.remaining_quantity > 0
                     AND pb.expiration_date IS NOT NULL
                     AND ({$diffExpr} <= COALESCE(p.warning_lead_time_days, 30) OR pb.expiration_date < {$todayQuoted})
                   ORDER BY pb.expiration_date ASC LIMIT 50";
        $expiringBatches = $this->pdo->query($expSql)->fetchAll(PDO::FETCH_ASSOC);

        $outCount = count($outOfStock);
        $lowCount = count($lowStock);
        $expCount = count($expiringBatches);

        $subject = "{$storeName} Daily Stock Brief - " . date('M j, Y');

        // Build plain text version
        $text = "{$storeName} - Daily Stock Brief\n";
        $text .= "Date: {$dateStr}\n\n";
        $text .= "SUMMARY:\n";
        $text .= "- Out of Stock: {$outCount} items\n";
        $text .= "- Low Stock: {$lowCount} items\n";
        $text .= "- Expiring / Expired: {$expCount} batches\n\n";

        if ($outCount > 0) {
            $text .= "--- OUT OF STOCK ({$outCount}) ---\n";
            foreach (array_slice($outOfStock, 0, 25) as $item) {
                $text .= "• {$item['product_name']} ({$item['sku']}) - Qty: {$item['quantity_on_hand']}, Reorder at: {$item['reorder_level']}\n";
            }
            if ($outCount > 25) $text .= "... and " . ($outCount - 25) . " more items.\n";
            $text .= "\n";
        }

        if ($lowCount > 0) {
            $text .= "--- LOW STOCK ({$lowCount}) ---\n";
            foreach (array_slice($lowStock, 0, 25) as $item) {
                $text .= "• {$item['product_name']} ({$item['sku']}) - Qty: {$item['quantity_on_hand']}, Reorder at: {$item['reorder_level']}\n";
            }
            if ($lowCount > 25) $text .= "... and " . ($lowCount - 25) . " more items.\n";
            $text .= "\n";
        }

        if ($expCount > 0) {
            $text .= "--- EXPIRING / EXPIRED BATCHES ({$expCount}) ---\n";
            foreach (array_slice($expiringBatches, 0, 20) as $b) {
                $status = (int)$b['days_remaining'] < 0 ? 'EXPIRED' : $b['days_remaining'] . ' days left';
                $text .= "• {$b['product_name']} (Batch {$b['batch_number']}) - Rem: {$b['remaining_quantity']}, Exp: {$b['expiration_date']} ({$status})\n";
            }
            $text .= "\n";
        }

        $text .= "Check RetailMind Inventory Dashboard for complete details.\n";

        // Build HTML table version
        $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);">'
            . '<div style="background:#0f172a;color:#ffffff;padding:24px 28px;">'
            . '<h1 style="margin:0 0 4px;font-size:20px;font-weight:700;letter-spacing:-0.01em;">' . $storeName . '</h1>'
            . '<p style="margin:0;color:#94a3b8;font-size:13px;">Daily Stock Brief &bull; ' . $dateStr . '</p>'
            . '</div>'
            . '<div style="padding:24px 28px;">'
            // Metric KPI Pills
            . '<div style="display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap;">'
            . '<div style="flex:1;min-width:140px;background:#fef2f2;border:1px solid #fee2e2;border-radius:8px;padding:12px 16px;">'
            . '<div style="color:#991b1b;font-size:11px;font-weight:700;text-transform:uppercase;">Out of Stock</div>'
            . '<div style="color:#b91c1c;font-size:24px;font-weight:800;margin-top:2px;">' . $outCount . '</div>'
            . '</div>'
            . '<div style="flex:1;min-width:140px;background:#fefce8;border:1px solid #fef08a;border-radius:8px;padding:12px 16px;">'
            . '<div style="color:#854d0e;font-size:11px;font-weight:700;text-transform:uppercase;">Low Stock</div>'
            . '<div style="color:#a16207;font-size:24px;font-weight:800;margin-top:2px;">' . $lowCount . '</div>'
            . '</div>'
            . '<div style="flex:1;min-width:140px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px 16px;">'
            . '<div style="color:#9a3412;font-size:11px;font-weight:700;text-transform:uppercase;">Expiring / Expired</div>'
            . '<div style="color:#c2410c;font-size:24px;font-weight:800;margin-top:2px;">' . $expCount . '</div>'
            . '</div>'
            . '</div>';

        // Out of Stock Table
        if ($outCount > 0) {
            $html .= '<h3 style="margin:20px 0 10px;font-size:15px;color:#991b1b;font-weight:700;display:flex;align-items:center;">'
                . '<span style="display:inline-block;width:8px;height:8px;background:#dc2626;border-radius:50%;margin-right:8px;"></span>'
                . 'Out of Stock Items (' . $outCount . ')</h3>'
                . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px;border:1px solid #f1f5f9;border-radius:6px;overflow:hidden;">'
                . '<thead><tr style="background:#f8fafc;color:#475569;text-align:left;border-bottom:1px solid #e2e8f0;">'
                . '<th style="padding:10px 12px;font-weight:600;">Product</th>'
                . '<th style="padding:10px 12px;font-weight:600;">SKU</th>'
                . '<th style="padding:10px 12px;font-weight:600;text-align:right;">Current</th>'
                . '<th style="padding:10px 12px;font-weight:600;text-align:right;">Reorder At</th>'
                . '</tr></thead><tbody>';
            foreach (array_slice($outOfStock, 0, 20) as $row) {
                $html .= '<tr style="border-bottom:1px solid #f1f5f9;">'
                    . '<td style="padding:10px 12px;color:#0f172a;font-weight:500;">' . htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:10px 12px;color:#64748b;font-family:monospace;font-size:12px;">' . htmlspecialchars($row['sku'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:10px 12px;color:#dc2626;font-weight:700;text-align:right;">0</td>'
                    . '<td style="padding:10px 12px;color:#64748b;text-align:right;">' . (int)$row['reorder_level'] . '</td>'
                    . '</tr>';
            }
            if ($outCount > 20) {
                $html .= '<tr><td colspan="4" style="padding:8px 12px;color:#64748b;font-size:12px;font-style:italic;background:#f8fafc;text-align:center;">+ ' . ($outCount - 20) . ' more items in RetailMind inventory</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        // Low Stock Table
        if ($lowCount > 0) {
            $html .= '<h3 style="margin:20px 0 10px;font-size:15px;color:#854d0e;font-weight:700;display:flex;align-items:center;">'
                . '<span style="display:inline-block;width:8px;height:8px;background:#eab308;border-radius:50%;margin-right:8px;"></span>'
                . 'Low Stock Items (' . $lowCount . ')</h3>'
                . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px;border:1px solid #f1f5f9;border-radius:6px;overflow:hidden;">'
                . '<thead><tr style="background:#f8fafc;color:#475569;text-align:left;border-bottom:1px solid #e2e8f0;">'
                . '<th style="padding:10px 12px;font-weight:600;">Product</th>'
                . '<th style="padding:10px 12px;font-weight:600;">SKU</th>'
                . '<th style="padding:10px 12px;font-weight:600;text-align:right;">Current</th>'
                . '<th style="padding:10px 12px;font-weight:600;text-align:right;">Reorder At</th>'
                . '</tr></thead><tbody>';
            foreach (array_slice($lowStock, 0, 20) as $row) {
                $html .= '<tr style="border-bottom:1px solid #f1f5f9;">'
                    . '<td style="padding:10px 12px;color:#0f172a;font-weight:500;">' . htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:10px 12px;color:#64748b;font-family:monospace;font-size:12px;">' . htmlspecialchars($row['sku'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:10px 12px;color:#ca8a04;font-weight:700;text-align:right;">' . (int)$row['quantity_on_hand'] . '</td>'
                    . '<td style="padding:10px 12px;color:#64748b;text-align:right;">' . (int)$row['reorder_level'] . '</td>'
                    . '</tr>';
            }
            if ($lowCount > 20) {
                $html .= '<tr><td colspan="4" style="padding:8px 12px;color:#64748b;font-size:12px;font-style:italic;background:#f8fafc;text-align:center;">+ ' . ($lowCount - 20) . ' more items in RetailMind inventory</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        // Expiring Batches Table
        if ($expCount > 0) {
            $html .= '<h3 style="margin:20px 0 10px;font-size:15px;color:#9a3412;font-weight:700;display:flex;align-items:center;">'
                . '<span style="display:inline-block;width:8px;height:8px;background:#f97316;border-radius:50%;margin-right:8px;"></span>'
                . 'Expiring / Expired Batches (' . $expCount . ')</h3>'
                . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px;border:1px solid #f1f5f9;border-radius:6px;overflow:hidden;">'
                . '<thead><tr style="background:#f8fafc;color:#475569;text-align:left;border-bottom:1px solid #e2e8f0;">'
                . '<th style="padding:10px 12px;font-weight:600;">Product</th>'
                . '<th style="padding:10px 12px;font-weight:600;">Batch</th>'
                . '<th style="padding:10px 12px;font-weight:600;text-align:right;">Remaining</th>'
                . '<th style="padding:10px 12px;font-weight:600;text-align:right;">Expiration</th>'
                . '</tr></thead><tbody>';
            foreach (array_slice($expiringBatches, 0, 15) as $b) {
                $days = (int)$b['days_remaining'];
                $statusColor = $days < 0 ? '#b91c1c' : ($days <= 7 ? '#c2410c' : '#854d0e');
                $statusText = $days < 0 ? 'Expired (' . abs($days) . 'd ago)' : ($days === 0 ? 'Today' : $days . 'd left');
                $html .= '<tr style="border-bottom:1px solid #f1f5f9;">'
                    . '<td style="padding:10px 12px;color:#0f172a;font-weight:500;">' . htmlspecialchars($b['product_name'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:10px 12px;color:#64748b;font-family:monospace;font-size:12px;">' . htmlspecialchars($b['batch_number'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:10px 12px;color:#0f172a;font-weight:600;text-align:right;">' . (int)$b['remaining_quantity'] . '</td>'
                    . '<td style="padding:10px 12px;color:' . $statusColor . ';font-weight:600;text-align:right;">'
                    . htmlspecialchars($b['expiration_date'], ENT_QUOTES, 'UTF-8') . ' <span style="font-size:11px;">(' . $statusText . ')</span></td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0 16px;">'
            . '<p style="margin:0;color:#94a3b8;font-size:12px;text-align:center;">This automated Daily Stock Brief was compiled by RetailMind for store inventory oversight.</p>'
            . '</div></div>';

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
            'metrics' => [
                'out_of_stock' => $outCount,
                'low_stock' => $lowCount,
                'expiring' => $expCount,
            ],
        ];
    }

    /**
     * Dispatch the Daily Stock Brief to target recipients.
     */
    public function dispatch(?string $specificRecipient = null): array
    {
        require_once __DIR__ . '/../../includes/functions.php';
        $report = $this->buildReport();
        $totalItems = ($report['metrics']['out_of_stock'] ?? 0)
            + ($report['metrics']['low_stock'] ?? 0)
            + ($report['metrics']['expiring'] ?? 0);

        if ($specificRecipient === null && $totalItems === 0) {
            return [
                'report' => $report['metrics'],
                'deliveries' => [],
            ];
        }

        $recipients = [];

        if ($specificRecipient !== null && filter_var($specificRecipient, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = trim($specificRecipient);
        } else {
            // Find opted-in inventory managers and admins
            $sql = "SELECT DISTINCT u.email FROM users u
                    LEFT JOIN notification_preferences np ON np.user_id = u.user_id
                    WHERE u.status = 'active'
                      AND u.email IS NOT NULL AND u.email != ''
                      AND COALESCE(np.notify_email, 1) = 1
                      AND EXISTS (
                          SELECT 1 FROM roles r WHERE r.role_name IN ('inventory_manager', 'admin', 'super_admin') AND (
                              EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.user_id AND ur.role_id = r.role_id)
                              OR (r.role_id = u.role_id AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.user_id))
                          )
                      )";
            $users = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as $u) {
                $email = trim((string)$u['email']);
                if (filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $recipients, true)) {
                    $recipients[] = $email;
                }
            }

            // Also include store_email if configured
            $settings = get_store_settings($this->pdo);
            $storeEmail = trim((string)($settings['store_email'] ?? ''));
            if (filter_var($storeEmail, FILTER_VALIDATE_EMAIL) && !in_array($storeEmail, $recipients, true)) {
                $recipients[] = $storeEmail;
            }
        }

        $results = [];
        foreach ($recipients as $recipient) {
            $sent = send_email_notification($recipient, $report['subject'], $report['text'], $report['html']);
            $results[$recipient] = $sent;
        }

        return [
            'report' => $report['metrics'],
            'deliveries' => $results,
        ];
    }
}
