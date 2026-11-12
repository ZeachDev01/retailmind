<?php
// Email template contract: the password reset code email and the Daily Stock
// Brief email keep table layout, inline styles only, escaped dynamic values,
// and a single quiet call to action.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/StockBriefService.php';
restore_exception_handler();

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

try {
    $reset = password_reset_email_html('<b>Ana</b>', '123 456');
    $assert(str_contains($reset, '&lt;b&gt;Ana&lt;/b&gt;'), 'Reset email must escape the display name');
    $assert(!str_contains($reset, '<b>Ana</b>'), 'Reset email must not inject the display name as markup');
    $assert(str_contains($reset, '123 456'), 'Reset email must show the verification code');
    $assert(str_contains($reset, 'Expires in 15 minutes'), 'Reset email must state the expiry');
    $assert(substr_count($reset, '<table') >= 3, 'Reset email must use table layout');
    $assert(str_contains($reset, '<!--[if mso]>'), 'Reset email must pin a fixed width for Outlook');
    $assert(!str_contains($reset, 'display:flex') && !str_contains($reset, 'display:grid'), 'Reset email must avoid flex and grid');

    $temporaryPassword = 'Temp<>&"\'Password123';
    $welcome = administrator_welcome_email_html('<b>Ana</b>', 'admin<one>', $temporaryPassword);
    foreach (['<b>Ana</b>', 'admin<one>', $temporaryPassword] as $value) {
        $assert(str_contains($welcome, htmlspecialchars($value, ENT_QUOTES, 'UTF-8')), 'Welcome email must preserve and escape each account detail');
        $assert(!str_contains($welcome, $value), 'Welcome email must not inject account details as markup');
    }
    $assert(str_contains($welcome, 'Temporary password'), 'Welcome email must label the password as temporary');
    $assert(str_contains($welcome, 'Change your password at first sign-in'), 'Welcome email must clearly require password replacement');
    $assert(str_contains($welcome, 'before you can access your workspace'), 'Welcome email must explain the mandatory gate');
    $assert(str_contains($welcome, 'Keep these details private'), 'Welcome email must explain credential privacy');
    $assert(substr_count($welcome, '<table') >= 3 && str_contains($welcome, '<!--[if mso]>'), 'Welcome email must keep email-client-compatible table layout');
    $assert(!preg_match('/<script|<link|<style|display:(flex|grid)/i', $welcome), 'Welcome email must use inline styles without scripts or external assets');

    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE store_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
CREATE TABLE products (product_id INTEGER PRIMARY KEY, product_name TEXT, sku TEXT, reorder_level INTEGER, warning_lead_time_days INTEGER);
CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER);
CREATE TABLE product_batches (batch_id INTEGER PRIMARY KEY, product_id INTEGER, batch_number TEXT, remaining_quantity INTEGER, expiration_date TEXT, date_received TEXT, supplier TEXT);");
    $pdo->exec("INSERT INTO store_settings (setting_key, setting_value) VALUES ('store_name','Shalom <script>alert(1)</script>'),('store_email','')");
    $pdo->exec("INSERT INTO products (product_id, product_name, sku, reorder_level, warning_lead_time_days) VALUES
(1,'Bad <b>Item</b>','SKU<1>',5,30),(2,'Fine Item','FINE-1',5,30)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1,0),(2,4)");
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, remaining_quantity, expiration_date, date_received, supplier) VALUES
(1,2,'B<b>1</b>',3,'" . date('Y-m-d', strtotime('+3 days')) . "','" . date('Y-m-d') . "','')");

    $brief = (new App\Services\StockBriefService($pdo))->buildReport();
    $html = (string)$brief['html'];

    $assert(!str_contains($html, '<script>'), 'Stock brief must escape the Store name');
    $assert(!str_contains($html, '<b>Item</b>'), 'Stock brief must escape product names');
    $assert(!str_contains($html, '<b>1</b>'), 'Stock brief must escape batch numbers');
    $assert(str_contains($html, '&lt;b&gt;Item&lt;/b&gt;') && str_contains($html, '&lt;b&gt;1&lt;/b&gt;'), 'Stock brief must show escaped values as text');
    $assert(str_contains($html, 'role="presentation"'), 'Stock brief must use table layout');
    $assert(str_contains($html, 'rm-sm-hide'), 'Stock brief must collapse optional columns on small screens');
    $assert(str_contains($html, '<!--[if mso]>'), 'Stock brief must pin a fixed width for Outlook');
    $assert(str_contains($html, 'Daily Stock Brief'), 'Stock brief must name the report');
    $assert(str_contains($html, 'Automated Daily Stock Brief from RetailMind'), 'Stock brief must carry its footer credit');
    $assert(!str_contains($html, 'display:flex') && !str_contains($html, 'display:grid'), 'Stock brief must avoid flex and grid');
} catch (Throwable $exception) {
    $failures[] = 'Email template contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Email template contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Email template contract: passed\n";
