<?php
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
$summary = ['sale_count'=>1];
$movements = $unresolvedHeldSales = $recent = $availableRegisters = [];
$path = __DIR__ . '/../../../frontend/components/cashier/shifts.php';
$source = file_get_contents($path);
$source = substr($source, strpos($source, '<!DOCTYPE html>'));
$source = str_replace("<?php include __DIR__ . '/../sidebar.php'; ?>", '', $source);
$source = str_replace('__DIR__', var_export(dirname($path), true), $source);
$source = str_replace('CashierShiftService::', '\\App\\Services\\CashierShiftService::', $source);
eval('?>' . $source);
