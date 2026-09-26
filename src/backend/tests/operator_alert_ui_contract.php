<?php
// Friendly Operator Alerts (ticket #52): frontend safety net + fallback wiring.
//
// Dialog-level contract asserting externally visible behavior only: the shared
// alert wrapper cleans unknown technical text, debug gating is wired from the
// existing APP_DEBUG flag, scoped pages stop concatenating raw exception text,
// and the global/DB fallbacks keep full detail out of shop mode.

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);

    $ui = $read('src/frontend/assets/js/ui.js');
    $bootstrap = $read('src/backend/bootstrap/app.php');
    $fallback = $read('src/backend/app/Support/ErrorFallback.php');
    $dbConfig = $read('src/backend/config/db.php');
    $sidebar = $read('src/frontend/components/sidebar.php');
    $login = $read('src/frontend/index.php');
    $cashierStock = $read('src/frontend/components/cashier/stock_issues.php');
    $managerStock = $read('src/frontend/components/inventory_management/stock_issues.php');
    $adminStock = $read('src/frontend/components/administrator/stock_issues.php');
    $receiving = $read('src/frontend/components/report/stock_receiving.php');
    $shifts = $read('src/frontend/components/cashier/shifts.php');
    $storeSettings = $read('src/frontend/components/administrator/store_settings.php');
    $userManager = $read('src/frontend/components/user_manager/user_manager.php');
    $backupPage = $read('src/frontend/components/system_administrator/backup_restore.php');
    $backupRoute = $read('src/backend/legacy/routes/admin/backup_restore.php');
    $products = $read('src/frontend/components/inventory_management/products.php');
    $pos = $read('src/frontend/components/cashier/pos.php');
    $inventoryCounts = $read('src/frontend/components/inventory_management/inventory_counts.php');
    $sales = $read('src/frontend/components/invoice/sales.php');
    $auth = $read('src/backend/includes/auth.php');

    $assert($ui !== '', 'ui.js must be readable');
    $assert($bootstrap !== '', 'bootstrap/app.php must be readable');

    // --- Shared frontend alert wrapper safety net ---
    $assert(str_contains($ui, 'sanitizeAlert'), 'ui.js must expose a sanitize step for operator alerts');
    $assert(str_contains($ui, 'SQLSTATE'), 'Safety net must recognise SQLSTATE leaks');
    $assert(str_contains($ui, 'Stack trace'), 'Safety net must recognise stack traces');
    $assert(str_contains($ui, 'RM_DEBUG'), 'ui.js must gate the tech line on the debug flag');
    $assert(str_contains($ui, 'rm-toast-tech'), 'Debug tech line must render in toasts as a small grey line');
    $assert(str_contains($ui, 'rm-swal-tech'), 'Debug tech line must render in modal alerts');
    $assert(
        str_contains($ui, 'console.error'),
        'Debug mode must emit the technical detail to the developer console'
    );
    $assert(
        str_contains($ui, 'Tell your Administrator if this keeps happening'),
        'Generic easy line must tell the operator who to contact when it repeats'
    );
    $assert((bool)preg_match('/error:\s*"Unable to continue"/', $ui), 'Blocked work must keep the red title Unable to continue');
    $assert((bool)preg_match('/warning:\s*"Attention"/', $ui), 'Retryable work must keep the yellow title Attention');
    $assert(
        (bool)preg_match('/success:\s*"Success"/', $ui) && (bool)preg_match('/info:\s*"Update"/', $ui),
        'Success and info alert titles must stay unchanged'
    );
    $assert(str_contains($ui, 'RM.toast = function') || str_contains($ui, 'RM.toast ='), 'toast entry point must remain');
    $assert(str_contains($ui, 'RM.alert = function') || str_contains($ui, 'RM.alert ='), 'alert entry point must remain');
    // All alert surfaces must run the safety net (toast, alert, confirm).
    $toastStart = strpos($ui, 'RM.toast');
    $alertStart = strpos($ui, 'RM.alert');
    $queueStart = strpos($ui, 'RM.queueAlert');
    $assert($toastStart !== false && $alertStart !== false && $queueStart !== false, 'alert API surface must remain');
    $assert(
        substr_count($ui, 'sanitizeAlert') >= 4,
        'Toast, alert, and confirm must all apply the safety net'
    );

    // --- Debug flag wiring (reuse APP_DEBUG, no new env key) ---
    $assert(str_contains($sidebar, 'RM_DEBUG'), 'sidebar must publish the debug flag before ui.js runs');
    $assert(str_contains($login, 'RM_DEBUG'), 'login page must publish the debug flag for ui.js');
    $assert(!str_contains($sidebar, 'APP_DEBUG_NEW'), 'No new debug env key may be introduced');

    // --- Global fallback gated by debug ---
    $assert($fallback !== '', 'ErrorFallback must be readable');
    $assert(
        str_contains($bootstrap . $fallback, 'Tell your Administrator if this keeps happening'),
        'Global HTML fallback must use the easy line shape'
    );
    $assert(str_contains($bootstrap, 'debug'), 'Global fallback must gate the tech detail on the debug flag');
    $assert(str_contains($fallback, 'tech'), 'Global fallback must be able to surface a tech line in debug mode');
    $assert(str_contains($bootstrap, 'error_log'), 'Global fallback must keep full detail in server logs');
    $assert(
        str_contains($fallback, 'The request could not be completed') || str_contains($fallback, 'Try again'),
        'Global JSON fallback must keep an easy message'
    );

    // --- Database connection failure path ---
    $assert(str_contains($dbConfig, 'error_log'), 'DB failure path must log full detail');
    $assert(str_contains($dbConfig, 'debug'), 'DB failure path must gate tech detail on debug');
    $assert(
        !str_contains($dbConfig, "die('Database connection failed. Please contact the system administrator.')"),
        'DB failure die() must move to the easy-line helper shape'
    );

    // --- Scoped pages: no raw exception concatenation ---
    $assert(!str_contains($cashierStock, "Could not save the report: ' ."), 'Cashier stock report must not concatenate raw exception text');
    $assert(str_contains($cashierStock, 'OperatorAlert'), 'Cashier stock report must build the friendly message at the boundary');
    $assert(!str_contains($managerStock, "Review failed: ' ."), 'Manager stock review must not concatenate raw exception text');
    $assert(str_contains($managerStock, 'OperatorAlert'), 'Manager stock review must build the friendly message at the boundary');
    $assert(str_contains($adminStock, 'OperatorAlert'), 'Administrator oversight must build the friendly message at the boundary');
    $assert(!str_contains($receiving, 'Error recording receipt: '), 'Receiving must not concatenate raw exception text');
    $assert(str_contains($receiving, 'OperatorAlert'), 'Receiving must build the friendly message at the boundary');
    $assert(str_contains($shifts, 'OperatorAlert'), 'Shifts must build the friendly message at the boundary');
    $assert(str_contains($storeSettings, 'OperatorAlert'), 'Store Settings must build the friendly message at the boundary');
    $assert(str_contains($userManager, 'OperatorAlert'), 'User management must build the friendly message at the boundary');
    $assert(str_contains($backupPage, 'OperatorAlert'), 'Backup page must build the friendly message at the boundary');
    $assert(!str_contains($backupPage, "Backup failed: ' ."), 'Backup page must not concatenate raw exception text');
    $assert(
        str_contains($backupRoute, 'frontend/components/system_administrator/backup_restore.php')
            && str_contains($backupPage, 'OperatorAlert')
            && str_contains($read('src/frontend/components/administrator/database_backup.php'), 'OperatorAlert'),
        'Legacy backup route must delegate to pages with friendly failure boundaries'
    );
    $assert(!str_contains($backupRoute, "Backup failed: ' ."), 'Backup route must not concatenate raw exception text');
    $assert(str_contains($pos, 'OperatorAlert'), 'Checkout must build the friendly message at the boundary');
    $assert(str_contains($inventoryCounts, 'OperatorAlert'), 'Inventory counts must build the friendly message at the boundary');
    $assert(str_contains($sales, 'OperatorAlert'), 'Sales reversal path must build the friendly message at the boundary');

    // --- Camera / product photo: no String(error) to the operator ---
    $assert(
        !str_contains($products, 'String(error)'),
        'Camera failure must not show String(error) to the operator'
    );
    $assert(
        str_contains($products, 'camera permission') || str_contains($products, 'Camera could not start') || str_contains($products, 'camera could not start'),
        'Camera failure must give a plain next step (check permission / manual entry)'
    );
    $assert(str_contains($products, 'console.'), 'Camera failure must keep tech detail on the developer console');
    $assert(str_contains($products, 'OperatorAlert'), 'Product save failures must build the friendly message at the boundary');

    // --- Login flash wording stays (no account-enumeration leak) ---
    $assert(str_contains($auth, 'Invalid username or password.'), 'Login failure must stay as Invalid username or password');
    $assert(
        str_contains($auth, 'disabled') || str_contains($auth, 'Disabled'),
        'Disabled Account login failure must remain a plain disabled message'
    );
} catch (Throwable $exception) {
    $failures[] = 'Operator alert UI contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Operator alert UI contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Operator alert UI contract: passed\n";
