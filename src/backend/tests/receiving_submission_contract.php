<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Support/OperatorAlert.php';

function require_role(array $roles): void {}
function has_capability(string $capability): bool { return true; }
function require_capability(string $capability): void {}
function verify_csrf_token(string $token): void {}
function log_activity(...$arguments): void {}

// Observe the HTTP handler's write boundary without changing a database.
class ReceivingService
{
    public static int $saves = 0;
    public function __construct($pdo) {}
    public function getActiveProducts(): array { return []; }
    public function receiveShoppingTrip(array $lines, array $entry, int $userId): array
    {
        self::$saves++;
        return [['product_name' => 'Test product', 'accepted_qty' => 1,
            'damaged_qty' => 0, 'recorded_by' => 'Manager', 'received_at' => '2026-10-15']];
    }
}

function submitReceiving(array $post): array
{
    $pdo = null;
    $_GET = [];
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SESSION = ['user_id' => 1, 'receiving_nonces' => ['test-nonce' => true]];
    $source = file_get_contents(__DIR__ . '/../../frontend/components/report/stock_receiving.php');
    $source = explode('?>', $source, 2)[0];
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
    eval(substr($source, 5));
    return [$error, $message, $formValues];
}

$post = ['csrf_token' => 'test', 'receiving_nonce' => 'test-nonce', 'entry_mode' => 'shopping_trip'];
for ($index = 0; $index < 120; $index++) {
    $post['lines'][] = ['product_id' => (string)($index + 1), 'quantity_mode' => 'units',
        'received_qty' => '1', 'damaged_qty' => '0', 'cost_price' => '',
        'batch_number' => '', 'expiration_date' => '', 'discrepancy_notes' => '', 'notes' => ''];
}
$post['supplier'] = 'Shopping store';
$post['receiving_complete'] = '1';
// PHP uses the same variable limit for form submissions and parse_str().
@parse_str(http_build_query($post), $truncated);
if (isset($truncated['receiving_complete']) || count($truncated['lines']) >= 120) {
    throw new RuntimeException('Run this regression with max_input_vars=1000');
}
[$error, $message, $retained] = submitReceiving($truncated);
if (ReceivingService::$saves !== 0 || !str_contains($error, 'Nothing was saved')
    || $message !== '' || $retained !== $truncated || !isset($_SESSION['receiving_nonces']['test-nonce'])) {
    throw new RuntimeException('Truncated receiving must save nothing and retain the parsed draft and nonce');
}
$complete = $post;
$complete['lines'] = [$post['lines'][0]];
[$error, $message] = submitReceiving($complete);
if (ReceivingService::$saves !== 1 || $error !== '' || !str_contains($message, 'Receiving entry saved.')
    || isset($_SESSION['receiving_nonces']['test-nonce'])) {
    throw new RuntimeException('A complete submission must save once and consume its nonce');
}
echo "Receiving submission contract: passed (truncation rejected, complete entry accepted)\n";
