<?php

namespace App\Services;

use App\Authorization\RoleCapabilityPolicy;
use DomainException;
use InvalidArgumentException;
use PDO;

require_once __DIR__ . '/../Authorization/RoleCapabilityPolicy.php';

/** Store-wide Cashier Shift report. All amounts in the result are PHP. */
final class StoreShiftReportService
{
    public const UNASSIGNED = 'Legacy / Unassigned';
    public const HEADERS = [
        'Record', 'Date', 'Cashier', 'Cashier ID', 'Register', 'Register ID', 'Shift ID',
        'Status', 'Opened At', 'Closed At', 'Closed By', 'Intervention Reason',
        'Sale Count', 'Cash Sales', 'Card Sales', 'E-Wallet Sales', 'Total Sales',
        'Cash Refunds', 'Card Refunds', 'E-Wallet Refunds', 'Total Refunds',
        'Cash In', 'Cash Out', 'Safe Drops', 'Opening Cash', 'Expected Cash',
        'Counted Cash', 'Variance', 'Material Variance', 'Closing Notes',
    ];

    public function __construct(private PDO $pdo, private ?RoleCapabilityPolicy $policy = null)
    {
        $this->policy ??= new RoleCapabilityPolicy();
    }

    public function report(string $role, array $filters): array
    {
        if (!$this->policy->allows($role, RoleCapabilityPolicy::STORE_OPERATIONS)) {
            throw new DomainException('Only the Administrator workspace can view the Store Cashier Shift report.');
        }
        $from = (string)($filters['from'] ?? '');
        $to = (string)($filters['to'] ?? '');
        if (!self::validDate($from) || !self::validDate($to) || $from > $to) {
            throw new InvalidArgumentException('Choose a valid date range.');
        }
        $cashier = self::filterId($filters['cashier_id'] ?? '');
        $register = self::filterId($filters['register_id'] ?? '');
        $shift = self::filterId($filters['shift_id'] ?? '');
        $where = ['cs.opened_at >= ?', 'cs.opened_at < ?'];
        $params = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
        if ($cashier !== '') {
            $where[] = 'cs.cashier_id = ?';
            $params[] = (int)$cashier;
        }
        if ($register !== '') {
            $where[] = $register === 'legacy' ? 'cs.register_id IS NULL' : 'cs.register_id = ?';
            if ($register !== 'legacy') $params[] = (int)$register;
        }
        if ($shift !== '') {
            $where[] = $shift === 'legacy' ? '1 = 0' : 'cs.shift_id = ?';
            if ($shift !== 'legacy') $params[] = (int)$shift;
        }
        $statement = $this->pdo->prepare(
            'SELECT cs.shift_id, cs.cashier_id, cs.register_id, cs.opened_at, cs.closed_at,
                    cs.status, cs.opening_cash, cs.expected_cash, cs.actual_cash,
                    cs.cash_variance, cs.variance_review_required, cs.closing_notes,
                    cs.closed_by, cs.intervention_reason, u.full_name AS cashier,
                    r.name AS register_name, closer.full_name AS closer_name
             FROM cashier_shifts cs
             LEFT JOIN users u ON u.user_id = cs.cashier_id
             LEFT JOIN registers r ON r.register_id = cs.register_id
             LEFT JOIN users closer ON closer.user_id = cs.closed_by
             WHERE ' . implode(' AND ', $where) . ' ORDER BY cs.opened_at DESC, cs.shift_id DESC'
        );
        $statement->execute($params);
        $shiftService = new CashierShiftService($this->pdo);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $id = (int)$record['shift_id'];
            $summary = $shiftService->calculateShift($id);
            $refunds = $this->refundTotals($id);
            $closed = $record['status'] === 'closed';
            $rows[] = [
                'Record' => 'Cashier Shift', 'Date' => substr((string)$record['opened_at'], 0, 10),
                'Cashier' => $record['cashier'] ?? self::UNASSIGNED,
                'Cashier ID' => $record['cashier_id'],
                'Register' => $record['register_id'] === null ? self::UNASSIGNED : ($record['register_name'] ?? self::UNASSIGNED),
                'Register ID' => $record['register_id'] ?? '', 'Shift ID' => $id,
                'Status' => $record['status'], 'Opened At' => $record['opened_at'],
                'Closed At' => $record['closed_at'] ?? '',
                'Closed By' => $record['closed_by'] === null ? '' : (($record['closer_name'] ?? self::UNASSIGNED) . ' (#' . $record['closed_by'] . ')'),
                'Intervention Reason' => $record['intervention_reason'] ?? '',
                'Sale Count' => (int)$summary['sale_count'],
                'Cash Sales' => self::money($summary['cash_sales']),
                'Card Sales' => self::money($summary['card_sales']),
                'E-Wallet Sales' => self::money($summary['ewallet_sales']),
                'Total Sales' => self::money($summary['total_sales']),
                'Cash Refunds' => self::money($refunds['cash']),
                'Card Refunds' => self::money($refunds['card']),
                'E-Wallet Refunds' => self::money($refunds['ewallet']),
                'Total Refunds' => self::money(array_sum($refunds)),
                'Cash In' => self::money($summary['cash_in']),
                'Cash Out' => self::money($summary['cash_out']),
                'Safe Drops' => self::money($summary['safe_drop']),
                'Opening Cash' => self::money($record['opening_cash']),
                'Expected Cash' => $closed ? self::money($record['expected_cash']) : self::money($summary['calculated_expected_cash']),
                'Counted Cash' => $closed ? self::money($record['actual_cash']) : '',
                'Variance' => $closed ? self::money($record['cash_variance']) : '',
                'Material Variance' => $closed && (int)$record['variance_review_required'] === 1 ? 'Yes' : 'No',
                'Closing Notes' => $record['closing_notes'] ?? '',
            ];
        }
        if ($shift === '' || $shift === 'legacy') {
            if ($register === '' || $register === 'legacy') {
                array_push($rows, ...$this->legacyRows($from, $to, $cashier));
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($b['Date'], $a['Date']) ?: strcmp((string)$b['Shift ID'], (string)$a['Shift ID']));
        return ['headers' => self::HEADERS, 'rows' => $rows, 'totals' => self::totals($rows)];
    }

    public static function csv($stream, array $report): void
    {
        fputcsv($stream, $report['headers']);
        foreach ($report['rows'] as $row) {
            fputcsv($stream, array_map(static fn($header): string => self::csvCell((string)($row[$header] ?? '')), $report['headers']));
        }
        $total = array_fill_keys($report['headers'], '');
        $total['Record'] = 'TOTAL';
        foreach ($report['totals'] as $column => $value) $total[$column] = $value;
        fputcsv($stream, array_values($total));
    }

    private static function csvCell(string $value): string
    {
        // Spreadsheet programs treat these prefixes as formulas.
        return !is_numeric($value) && preg_match('/^[\s]*[=+\-@]/u', $value) ? "'" . $value : $value;
    }

    private static function totals(array $rows): array
    {
        $columns = ['Sale Count', 'Cash Sales', 'Card Sales', 'E-Wallet Sales', 'Total Sales',
            'Cash Refunds', 'Card Refunds', 'E-Wallet Refunds', 'Total Refunds',
            'Cash In', 'Cash Out', 'Safe Drops'];
        $totals = [];
        foreach ($columns as $column) {
            $sum = array_sum(array_map(static fn(array $row): float => (float)$row[$column], $rows));
            $totals[$column] = $column === 'Sale Count' ? (string)(int)$sum : self::money($sum);
        }
        // Opening/expected/count/variance are intentionally not totaled: open
        // and legacy rows have no comparable counted cash.
        return $totals;
    }

    private function refundTotals(int $shiftId): array
    {
        $stmt = $this->pdo->prepare('SELECT payment_method, SUM(refund_amount) AS amount FROM cash_refunds WHERE shift_id = ? GROUP BY payment_method');
        $stmt->execute([$shiftId]);
        $totals = ['cash' => 0.0, 'card' => 0.0, 'ewallet' => 0.0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($totals[$row['payment_method']])) $totals[$row['payment_method']] = (float)$row['amount'];
        }
        return $totals;
    }

    private function legacyRows(string $from, string $to, string $cashier): array
    {
        $where = ['s.shift_id IS NULL', 's.sale_date >= ?', 's.sale_date < ?'];
        $params = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
        if ($cashier !== '') {
            $where[] = 's.cashier_id = ?';
            $params[] = (int)$cashier;
        }
        $stmt = $this->pdo->prepare(
            "SELECT DATE(s.sale_date) AS report_date, s.cashier_id, u.full_name AS cashier,
                    COUNT(*) AS sale_count, SUM(s.total_amount) AS total_sales,
                    SUM(CASE WHEN s.payment_method='cash' THEN s.total_amount ELSE 0 END) AS cash_sales,
                    SUM(CASE WHEN s.payment_method='card' THEN s.total_amount ELSE 0 END) AS card_sales,
                    SUM(CASE WHEN s.payment_method='ewallet' THEN s.total_amount ELSE 0 END) AS ewallet_sales
             FROM sales s LEFT JOIN users u ON u.user_id=s.cashier_id
             WHERE " . implode(' AND ', $where) . ' GROUP BY DATE(s.sale_date), s.cashier_id, u.full_name'
        );
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sale) {
            $row = array_fill_keys(self::HEADERS, '');
            $row['Record'] = self::UNASSIGNED . ' Sale';
            $row['Date'] = $sale['report_date'];
            $row['Cashier'] = $sale['cashier'] ?? self::UNASSIGNED;
            $row['Cashier ID'] = $sale['cashier_id'];
            $row['Register'] = self::UNASSIGNED;
            $row['Status'] = self::UNASSIGNED;
            $row['Sale Count'] = (int)$sale['sale_count'];
            foreach (['cash_sales' => 'Cash Sales', 'card_sales' => 'Card Sales',
                      'ewallet_sales' => 'E-Wallet Sales', 'total_sales' => 'Total Sales'] as $source => $target) {
                $row[$target] = self::money($sale[$source]);
            }
            foreach (['Cash Refunds', 'Card Refunds', 'E-Wallet Refunds', 'Total Refunds',
                      'Cash In', 'Cash Out', 'Safe Drops'] as $column) $row[$column] = self::money(0);
            $rows[] = $row;
        }
        // Approved sale reversals have no shift column. They remain visible as
        // refunds, but the report never assigns them a Register or Shift.
        $reversalWhere = ["sr.status='approved'", 'sr.approved_at >= ?', 'sr.approved_at < ?'];
        if ($cashier !== '') $reversalWhere[] = 'sr.requested_by = ?';
        $reversals = $this->pdo->prepare(
            "SELECT DATE(sr.approved_at) AS report_date, sr.requested_by AS cashier_id, u.full_name AS cashier,
                    sr.settlement_method, SUM(sr.refund_amount) AS amount
             FROM sale_reversals sr LEFT JOIN users u ON u.user_id=sr.requested_by
             WHERE " . implode(' AND ', $reversalWhere) .
            ' GROUP BY DATE(sr.approved_at), sr.requested_by, u.full_name, sr.settlement_method'
        );
        $reversals->execute($params);
        foreach ($reversals->fetchAll(PDO::FETCH_ASSOC) as $reversal) {
            if (!in_array($reversal['settlement_method'], ['cash', 'card', 'ewallet'], true)) continue;
            $row = array_fill_keys(self::HEADERS, '');
            $row['Record'] = self::UNASSIGNED . ' Reversal';
            $row['Date'] = $reversal['report_date'];
            $row['Cashier'] = $reversal['cashier'] ?? self::UNASSIGNED;
            $row['Cashier ID'] = $reversal['cashier_id'];
            $row['Register'] = self::UNASSIGNED;
            $row['Status'] = self::UNASSIGNED;
            $row['Sale Count'] = 0;
            foreach (['Cash Sales', 'Card Sales', 'E-Wallet Sales', 'Total Sales',
                      'Cash Refunds', 'Card Refunds', 'E-Wallet Refunds', 'Total Refunds',
                      'Cash In', 'Cash Out', 'Safe Drops'] as $column) $row[$column] = self::money(0);
            $column = ['cash' => 'Cash Refunds', 'card' => 'Card Refunds', 'ewallet' => 'E-Wallet Refunds'][$reversal['settlement_method']];
            $row[$column] = self::money($reversal['amount']);
            $row['Total Refunds'] = self::money($reversal['amount']);
            $rows[] = $row;
        }
        return $rows;
    }

    private static function filterId($value): string
    {
        if ($value === '' || $value === null) return '';
        if ($value === 'legacy') return 'legacy';
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) throw new InvalidArgumentException('Choose a valid filter.');
        return (string)$id;
    }

    private static function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function money($value): string
    {
        return number_format((float)$value, 2, '.', '');
    }
}
