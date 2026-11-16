<?php

namespace App\Services;

use PDO;
use Throwable;

require_once __DIR__ . '/../Inventory/StockConditionReader.php';

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
        $stockItems = \App\Inventory\StockConditionReader::reorderBreachProductsForStockBrief($this->pdo);

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
        $expiringBatches = \App\Inventory\StockConditionReader::expiryBatchesForStockBrief($this->pdo);

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

        // HTML version: table layout and inline styles only, because email
        // clients drop stylesheets and flexbox.
        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $mono = "ui-monospace,SFMono-Regular,Consolas,'Liberation Mono',Menlo,monospace";
        $kpi = static function (string $label, int $count, string $color) use ($font): string {
            return '<div style="padding:16px 10px;font-family:' . $font . ';">'
                . '<div style="color:' . $color . ';font-size:24px;font-weight:700;line-height:1.2;">' . $count . '</div>'
                . '<div class="rm-kpi-label" style="margin-top:4px;color:#475569;font-size:12px;line-height:1.5;">' . $label . '</div>'
                . '</div>';
        };
        $sectionTitle = static function (string $title, int $count) use ($font): string {
            return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 12px;"><tr>'
                . '<td style="font-family:' . $font . ';font-size:16px;font-weight:700;line-height:1.4;color:#0f172a;">' . $title . '</td>'
                . '<td align="right" style="padding-left:8px;font-family:' . $font . ';color:#64748b;font-size:12px;line-height:1.5;">' . $count . '</td>'
                . '</tr></table>';
        };
        $headCell = static function (string $label, bool $numeric = false, bool $hideSmall = false) use ($font): string {
            return '<th scope="col"' . ($hideSmall ? ' class="rm-sm-hide"' : '') . ' align="' . ($numeric ? 'right' : 'left') . '" style="padding:0 6px 8px;font-family:' . $font . ';font-size:12px;font-weight:600;line-height:1.5;color:#475569;border-bottom:1px solid #cbd5e1;">' . $label . '</th>';
        };
        $cell = static function (string $value, string $color = '#374151', bool $numeric = false, bool $monospace = false, bool $hideSmall = false) use ($font, $mono): string {
            return '<td' . ($hideSmall ? ' class="rm-sm-hide"' : '') . ' align="' . ($numeric ? 'right' : 'left') . '" style="padding:12px 6px;border-bottom:1px solid #e2e8f0;color:' . $color . ';font-family:' . ($monospace ? $mono : $font) . ';font-size:' . ($monospace ? '12px' : '13px') . ';line-height:1.5;overflow-wrap:anywhere;word-break:break-word;' . ($numeric ? 'font-variant-numeric:tabular-nums;font-weight:600;' : 'font-weight:400;') . '">' . $value . '</td>';
        };
        $moreRow = static function (int $remaining, int $columns) use ($font): string {
            return '<tr><td colspan="' . $columns . '" style="padding:9px 8px;background:#f8fafc;border-bottom:1px solid #f1f5f9;color:#6b7280;font-family:' . $font . ';font-size:12px;text-align:center;">+ ' . $remaining . ' more in RetailMind</td></tr>';
        };
        $tableOpen = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;table-layout:fixed;">';

        $html = '<style>@media only screen and (max-width:480px){.rm-sm-hide{display:none !important;}}</style>'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $outCount . ' out of stock, ' . $lowCount . ' low, ' . $expCount . ' expiring.</div>'
            . '<h1 style="margin:0;color:#0f172a;font-size:24px;line-height:1.3;">Daily Stock Brief</h1>'
            . '<p style="margin:10px 0 24px;color:#475569;font-size:13px;line-height:1.6;">' . $storeName . ' &middot; ' . $dateStr . '</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;table-layout:fixed;"><tr>'
            . '<td width="33.33%" style="vertical-align:top;">' . $kpi('Out of Stock', $outCount, '#b91c1c') . '</td>'
            . '<td width="33.33%" style="vertical-align:top;">' . $kpi('Low Stock', $lowCount, '#92400e') . '</td>'
            . '<td width="33.33%" style="vertical-align:top;">' . $kpi('Expiring', $expCount, '#9a3412') . '</td>'
            . '</tr></table>';

        // Out of Stock Table
        if ($outCount > 0) {
            $html .= $sectionTitle('Out of Stock', $outCount)
                . $tableOpen
                . '<thead><tr>' . $headCell('Product') . $headCell('SKU') . $headCell('Current', true) . $headCell('Reorder at', true, true) . '</tr></thead><tbody>';
            foreach (array_slice($outOfStock, 0, 20) as $row) {
                $html .= '<tr>'
                    . $cell(htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'), '#0f172a')
                    . $cell(htmlspecialchars($row['sku'], ENT_QUOTES, 'UTF-8'), '#6b7280', false, true)
                    . $cell('0', '#dc2626', true)
                    . $cell((string)(int)$row['reorder_level'], '#6b7280', true, false, true)
                    . '</tr>';
            }
            if ($outCount > 20) {
                $html .= $moreRow($outCount - 20, 4);
            }
            $html .= '</tbody></table>';
        }

        // Low Stock Table
        if ($lowCount > 0) {
            $html .= $sectionTitle('Low Stock', $lowCount)
                . $tableOpen
                . '<thead><tr>' . $headCell('Product') . $headCell('SKU') . $headCell('Current', true) . $headCell('Reorder at', true, true) . '</tr></thead><tbody>';
            foreach (array_slice($lowStock, 0, 20) as $row) {
                $html .= '<tr>'
                    . $cell(htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'), '#0f172a')
                    . $cell(htmlspecialchars($row['sku'], ENT_QUOTES, 'UTF-8'), '#6b7280', false, true)
                    . $cell((string)(int)$row['quantity_on_hand'], '#a16207', true)
                    . $cell((string)(int)$row['reorder_level'], '#6b7280', true, false, true)
                    . '</tr>';
            }
            if ($lowCount > 20) {
                $html .= $moreRow($lowCount - 20, 4);
            }
            $html .= '</tbody></table>';
        }

        // Expiring Batches Table
        if ($expCount > 0) {
            $html .= $sectionTitle('Expiring / Expired', $expCount)
                . $tableOpen
                . '<thead><tr>' . $headCell('Product') . $headCell('Batch') . $headCell('Remaining', true, true) . $headCell('Expiration', true) . '</tr></thead><tbody>';
            foreach (array_slice($expiringBatches, 0, 15) as $b) {
                $days = (int)$b['days_remaining'];
                $statusColor = $days < 0 ? '#b91c1c' : ($days <= 7 ? '#c2410c' : '#854d0e');
                $statusText = $days < 0 ? 'Expired ' . abs($days) . 'd ago' : ($days === 0 ? 'Today' : $days . 'd left');
                $html .= '<tr>'
                    . $cell(htmlspecialchars($b['product_name'], ENT_QUOTES, 'UTF-8'), '#0f172a')
                    . $cell(htmlspecialchars($b['batch_number'], ENT_QUOTES, 'UTF-8'), '#6b7280', false, true)
                    . $cell((string)(int)$b['remaining_quantity'], '#0f172a', true, false, true)
                    . '<td align="right" style="padding:10px 6px;border-bottom:1px solid #f1f5f9;font-family:' . $font . ';font-size:13px;font-weight:700;font-variant-numeric:tabular-nums;color:' . $statusColor . ';">'
                    . htmlspecialchars($b['expiration_date'], ENT_QUOTES, 'UTF-8')
                    . '<div style="color:#6b7280;font-size:11px;font-weight:600;margin-top:2px;">' . $statusText . '</div></td>'
                    . '</tr>';
            }
            if ($expCount > 15) {
                $html .= $moreRow($expCount - 15, 4);
            }
            $html .= '</tbody></table>';
        }

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
