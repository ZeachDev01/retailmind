<?php
namespace App\Forecasting;

use PDO;
use DateTime;
use App\Store\StoreCalendar;

class DemandHistoryService
{
    public function getDailyEvidence(PDO $pdo, int $branchId, int $productId, string $startDate, string $endDate, string $cutoff): array
    {
        $current = new DateTime($startDate);
        $end = new DateTime($endDate);
        
        $results = [];
        $calendar = new StoreCalendar();
        
        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $startOfDay = $dateStr . ' 00:00:00';
            $endOfDay = $dateStr . ' 23:59:59';
            $effectiveEnd = min($endOfDay, $cutoff);
            
            // 1. Calendar Status
            // Since StoreCalendar uses the requested cutoff time for its historical reproduction:
            $calStatus = $calendar->getCalendarDateStatusAtCutoff($pdo, $branchId, $dateStr, $cutoff);
            
            // 2. End of day stock
            $stockStmt = $pdo->prepare("
                SELECT COALESCE(SUM(change_qty), 0)
                FROM stock_movements
                WHERE product_id = ? AND moved_at <= ?
            ");
            $stockStmt->execute([$productId, $effectiveEnd]);
            $endOfDayStock = (int)$stockStmt->fetchColumn();
            
            // 3. Valid Sales Units
            // Sold items
            $soldStmt = $pdo->prepare("
                SELECT COALESCE(SUM(si.quantity), 0)
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.sale_id
                WHERE si.product_id = ?
                  AND s.sale_date >= ? AND s.sale_date <= ?
            ");
            $soldStmt->execute([$productId, $startOfDay, $effectiveEnd]);
            $sold = (int)$soldStmt->fetchColumn();
            
            // Reversed items (approved before cutoff)
            $reversedStmt = $pdo->prepare("
                SELECT COALESCE(SUM(sri.quantity), 0)
                FROM sale_reversal_items sri
                JOIN sale_reversals sr ON sri.reversal_id = sr.reversal_id
                JOIN sale_items si ON sri.sale_item_id = si.sale_item_id
                JOIN sales s ON si.sale_id = s.sale_id
                WHERE sri.product_id = ?
                  AND s.sale_date >= ? AND s.sale_date <= ?
                  AND sr.status = 'approved'
                  AND sr.approved_at <= ?
            ");
            $reversedStmt->execute([$productId, $startOfDay, $effectiveEnd, $cutoff]);
            $reversed = (int)$reversedStmt->fetchColumn();
            
            // Refunded items (created before cutoff)
            $refundedStmt = $pdo->prepare("
                SELECT COALESCE(SUM(cri.quantity), 0)
                FROM cash_refund_items cri
                JOIN cash_refunds cr ON cri.refund_id = cr.refund_id
                JOIN sale_items si ON cri.sale_item_id = si.sale_item_id
                JOIN sales s ON si.sale_id = s.sale_id
                WHERE cri.product_id = ?
                  AND s.sale_date >= ? AND s.sale_date <= ?
                  AND cr.created_at <= ?
            ");
            $refundedStmt->execute([$productId, $startOfDay, $effectiveEnd, $cutoff]);
            $refunded = (int)$refundedStmt->fetchColumn();
            
            $validSalesUnits = $sold - $reversed - $refunded;
            
            // 4. Classification
            $classification = 'missing_evidence';
            if ($calStatus === null) {
                $classification = 'missing_evidence';
            } elseif ($calStatus === 'closed' || $calStatus === 'holiday' || $calStatus === 'exceptional_closure') {
                $classification = 'closed';
            } elseif ($calStatus === 'open') {
                if ($validSalesUnits > 0) {
                    $classification = 'valid_demand';
                } elseif ($endOfDayStock > 0) {
                    $classification = 'genuine_zero';
                } else {
                    $classification = 'stockout_censored';
                }
            }
            
            $results[] = [
                'date' => $dateStr,
                'valid_sales_units' => $validSalesUnits,
                'calendar_status' => $calStatus,
                'end_of_day_stock' => $endOfDayStock,
                'classification' => $classification
            ];
            
            $current->modify('+1 day');
        }
        
        return $results;
    }

    public function getReadinessSummary(PDO $pdo, int $branchId, int $productId, string $startDate, string $endDate, string $cutoff): array
    {
        $evidence = $this->getDailyEvidence($pdo, $branchId, $productId, $startDate, $endDate, $cutoff);
        
        $summary = [
            'usable_history_days' => 0,
            'excluded_days' => [
                'closed' => 0,
                'missing_evidence' => 0,
                'stockout_censored' => 0,
            ]
        ];
        
        foreach ($evidence as $day) {
            if (in_array($day['classification'], ['valid_demand', 'genuine_zero'])) {
                $summary['usable_history_days']++;
            } else {
                if (!isset($summary['excluded_days'][$day['classification']])) {
                    $summary['excluded_days'][$day['classification']] = 0;
                }
                $summary['excluded_days'][$day['classification']]++;
            }
        }
        
        return $summary;
    }
}
