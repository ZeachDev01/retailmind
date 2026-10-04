<?php
// Render the shipped Administrator template with synthetic records, without Store writes.
$allRegisters = [
    ['register_id' => 1, 'name' => 'Front Counter', 'status' => 'active', 'paper_width_mm' => 80, 'disabled_at' => null],
    ['register_id' => 2, 'name' => 'Narrow Counter', 'status' => 'disabled', 'paper_width_mm' => 58, 'disabled_at' => null],
];
$availableCount = 1;
$message = $messageClass = '';
function app_url(string $path): string { return '/'.$path; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="synthetic">'; }
function retailmind_theme_head(): void {}
require_once __DIR__ . '/../../bootstrap/app.php';
$source = file_get_contents(__DIR__ . '/../../../frontend/components/administrator/registers.php');
$source = substr($source, strpos($source, '<!DOCTYPE html>'));
$source = str_replace("<?php include __DIR__ . '/../sidebar.php'; ?>", '', $source);
$source = str_replace('RegisterService::', '\\App\\Services\\RegisterService::', $source);
eval('?>' . $source);
