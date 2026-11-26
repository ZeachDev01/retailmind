<?php
require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\CashierLoginService;

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE users(user_id INTEGER PRIMARY KEY, session_version INT, status TEXT);
    INSERT INTO users VALUES(1,1,'active'),(2,1,'active')");
$migration = require __DIR__ . '/../database/migrations/202611260001_cashier_login_sessions.php';
$migration['up']($pdo);
$service = new CashierLoginService($pdo);
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$check($service->request(1, 1, 'device-a') === 'ready', 'First login gets access');
$check($service->request(1, 1, 'device-b') === 'waiting', 'Second login needs approval');
$check($service->request(1, 1, 'device-c') === 'busy', 'Third login cannot replace a pending request');
$check($service->request(2, 1, 'other-cashier') === 'ready', 'Different cashiers can work together');
$check($service->claim(1, 1, 'device-b') === 'waiting', 'Credentials alone cannot approve login');
$request = $service->pending(1, 'device-a');
$check($request !== null, 'Owner sees pending login');
$check(!$service->decide(1, 'device-b', $request, true), 'Requester cannot approve itself');
$check(!$service->decide(1, 'device-a', 'wrong-request', true), 'Stale request cannot approve another login');
$check($service->decide(1, 'device-a', $request, false), 'Owner can reject');
$check($service->claim(1, 1, 'device-b') === 'expired', 'Rejected login cannot claim access');
$check($service->owns(1, 'device-a', time()), 'Rejecting keeps old login');
$check($service->request(1, 1, 'device-b') === 'waiting', 'New request can follow rejection');
$check($service->decide(1, 'device-a', $service->pending(1, 'device-a'), true), 'Owner can approve');
$check(!$service->owns(1, 'device-a', time()), 'Approval immediately rejects old login');
$check($service->claim(1, 1, 'device-b') === 'ready', 'Approved requester gets access');
$check($service->claim(1, 1, 'device-b') === 'expired', 'Approval cannot be reused');
$service->release(1, 'device-a');
$check($service->owns(1, 'device-b', time()), 'Old logout cannot revoke new device');
$service->request(1, 1, 'device-c');
$pdo->exec('UPDATE users SET session_version = 2 WHERE user_id = 1');
$check($service->claim(1, 1, 'device-c') === 'expired', 'Revocation invalidates pending credentials');
$check($service->request(1, 2, 'device-d') === 'ready', 'Fresh credentials work after revocation');
$pdo->exec('UPDATE cashier_login_sessions SET last_activity = ' . (time() - 3600) . ' WHERE user_id = 1');
$check($service->request(1, 2, 'device-e') === 'ready', 'Offline old login expires at one hour');
$check(!$service->owns(1, 'device-d', time()), 'Expired device cannot regain ownership');
$service->request(1, 2, 'device-f');
$pdo->exec('UPDATE cashier_login_sessions SET last_activity = ' . (time() - 3600) . ' WHERE user_id = 1');
$check($service->claim(1, 2, 'device-f') === 'ready', 'Waiting device can enter when old login expires');
$service->request(1, 2, 'device-g');
$pdo->exec('UPDATE cashier_login_sessions SET pending_until = ' . time() . ' WHERE user_id = 1');
$check($service->claim(1, 2, 'device-g') === 'expired', 'Expired request cannot log in');
$service->release(1, 'device-f');
$check($service->request(1, 2, 'device-h') === 'ready', 'Logout releases login immediately');
echo "Cashier login service: passed\n";
