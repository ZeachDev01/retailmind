<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../bootstrap/app.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (user_id INTEGER, display_name TEXT);
    CREATE TABLE store_setup_confirmations (task_key TEXT, confirmed_by_user_id INTEGER, confirmed_workspace TEXT, confirmed_at TEXT);
    CREATE TABLE workspace_guidance (user_id INTEGER, workspace TEXT, status TEXT, active_topic TEXT, active_step INTEGER, completed_topics TEXT, invitation_status TEXT)');
$_SESSION = ['user_id' => 1];
$_SERVER['SCRIPT_NAME'] = '/components/inventory_management/inventory_overview.php';
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
$role = 'inventory_manager';

function current_role(): string { return $GLOBALS['role']; }
function app_url(string $path): string { return '/' . $path; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="test">'; }
function sidebar_icon(string $name, string $class = ''): string { return ''; }

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$render = static function () use ($pdo): string {
    ob_start();
    include __DIR__ . '/../../frontend/components/auth/workspace_guidance.php';
    $output = ob_get_clean();
    if (!preg_match("/main\.insertAdjacentHTML\('afterbegin', (.+)\);/", $output, $matches)) {
        throw new RuntimeException('Guidance banner was not rendered.');
    }
    return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
};

$inventory = $render();
$assert(str_contains($inventory, 'Welcome to the Inventory Manager Workspace'), 'Inventory uses the invitation heading.');
$assert(!str_contains($inventory, 'Stock monitoring'), 'Inventory banner omits page-specific workflow copy.');
$assert(str_contains($inventory, 'href="/components/auth/help.php" class="btn btn-primary">View guidance'), 'View guidance links directly to Help.');
$assert(!str_contains($inventory, 'Store calendar review') && !str_contains($inventory, 'Awaiting Administrator'), 'Inventory hides the calendar task.');
$assert(str_contains($inventory, 'Confirm product setup') && str_contains($inventory, '0 of 1 tasks confirmed'), 'Product confirmation and visible progress remain available.');
$pdo->exec("INSERT INTO store_setup_confirmations VALUES ('store_calendar', 1, 'admin', '2026-11-12')");
$assert(str_contains($render(), '0 of 1 tasks confirmed'), 'Hidden calendar confirmations do not count toward inventory progress.');
$pdo->exec('DELETE FROM store_setup_confirmations');
$pdo->exec("INSERT INTO store_setup_confirmations VALUES ('products', 1, 'inventory_manager', '2026-11-12')");
$assert(str_contains($render(), '1 of 1 tasks confirmed &middot; Your tasks complete'), 'Inventory completion describes personal tasks.');
$assert(!\App\Store\StoreSetupConfirmations::isComplete($pdo), 'Global Store setup still requires calendar confirmation.');

$role = 'admin';
$admin = $render();
$assert(str_contains($admin, 'Store calendar review') && str_contains($admin, 'Confirm calendar review'), 'Administrator retains calendar review.');
$assert(str_contains($admin, '1 of 2 tasks confirmed') && str_contains($admin, 'Finish overview'), 'Administrator retains global progress and overview controls.');
$pdo->exec("INSERT INTO workspace_guidance (user_id, workspace, invitation_status) VALUES (1, 'admin', 'invited')");
$assert(str_contains($render(), 'Start guidance'), 'Administrator invitation remains available.');

echo "Inventory guidance: passed\n";
