<?php
function retailmind_theme_head(): void {}
// Render the production form with synthetic data; no Store connection or writes.
require_once __DIR__ . '/../../bootstrap/app.php';
function app_url(string $path): string { return '/' . $path; }
function generate_csrf_token(): string { return 'fixture-csrf'; }
function format_display_datetime(string $value): string { return $value; }
$isCashier = true;
$actorRole = 'cashier';
$actorId = $targetCashierId = 1;
$message = $error = '';
$ownRegisterLocked = false;
$countPreview = $closedSummary = null;
$openShift = ['shift_id'=>20, 'register_name'=>'Fixture Till', 'opened_at'=>'2026-10-01 08:00:00'];
$summary = ['opening_cash'=>50, 'sale_count'=>1, 'refund_count'=>0, 'cash_sales'=>50, 'cash_in'=>100, 'safe_drop'=>50, 'calculated_expected_cash'=>150];
if (($argv[1] ?? '') === '--refund') {
    $summary = ['opening_cash'=>0, 'sale_count'=>1, 'refund_count'=>2, 'cash_sales'=>71, 'cash_in'=>100, 'cash_refunds'=>60, 'ewallet_refunds'=>25, 'calculated_expected_cash'=>111];
}
$movementToken = 'fixture-movement';
$movements = $unresolvedHeldSales = $recent = $availableRegisters = [];
$movements = [
    ['created_at'=>'2026-11-12 09:00:00', 'movement_type'=>'cash_in', 'amount'=>100, 'reason'=>'additional_float', 'note'=>'From: Owner <cash box>', 'full_name'=>'Fixture Cashier'],
    ['created_at'=>'2026-11-12 10:00:00', 'movement_type'=>'safe_drop', 'amount'=>50, 'reason'=>'excess_cash', 'note'=>'To: Store safe', 'full_name'=>'Fixture Cashier'],
];
if (($argv[1] ?? '') === '--refund') $movements = [$movements[0]];
if (($argv[1] ?? '') === '--empty') {
    $summary = App\Services\ShiftReconciliationCalculator::defaultSummary();
    $movements = [];
}
$path = __DIR__ . '/../../../frontend/components/cashier/shifts.php';
$source = file_get_contents($path);
$source = substr($source, strpos($source, '<!DOCTYPE html>'));
$source = str_replace("<?php include __DIR__ . '/../sidebar.php'; ?>", '', $source);
$source = str_replace('__DIR__', var_export(dirname($path), true), $source);
$source = str_replace('CashierShiftService::', '\\App\\Services\\CashierShiftService::', $source);
eval('?>' . $source);
