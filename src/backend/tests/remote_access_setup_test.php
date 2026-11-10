<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Services/CloudflareTunnelService.php';
require_once __DIR__ . '/../app/Services/TunnelSetupBlockBuilder.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$root = sys_get_temp_dir() . '/retailmind_remote_' . bin2hex(random_bytes(6));
mkdir($root);
$service = new class($root, $root, $root) extends App\Services\CloudflareTunnelService {
    public bool $running = true;
    public function isWindowsServiceRunning(string $serviceName = 'RetailMindCloudflareTunnel'): bool { return $this->running; }
};
try {
    $url = 'https://store.ngrok-free.dev';
    file_put_contents($root . '/active_tunnel.json', json_encode(['mode' => 'ngrok', 'url' => $url, 'port' => 8080, 'health_path' => '/shop/src/frontend/remote_health.php']));
    $service->setHealthChecker(static fn(string $candidate): bool => false);
    $status = $service->getStatus();
    expect($status['status'] === 'starting' && !$status['online'] && $status['url'] === null, 'A running disconnected service must not be Online or share a stale URL.');
    $service->setHealthChecker(static fn(string $candidate): bool => $candidate === $url);
    expect($service->getStatus()['online'], 'A verified route is Online.');
    expect($service->getStatus()['url'] === $url . '/shop/src/frontend/', 'Shared links must open RetailMind, not the XAMPP root.');
    $service->setToken('valid-cloudflare-token', 'remote.example.com');
    expect($service->getStatus()['mode'] === 'ngrok' && $service->getUrl() === $url, 'Saving upgrade settings must not change the installed connection.');
    $service->clearToken();
    expect($service->getStatus()['mode'] === 'ngrok', 'Clearing staged Cloudflare credentials must not change the active provider.');
    $service->running = false;
    expect($service->getStatus()['online'], 'A verified public route remains Online if the web process cannot query Windows service state.');
    $service->setHealthChecker(static fn(string $candidate): bool => false);
    expect($service->getStatus()['status'] === 'offline' && $service->getStatus()['url'] === null, 'A stopped service must not expose its cached URL.');
    expect($service->getStatus()['configured_url'] === $url . '/shop/src/frontend/', 'The saved link remains available while Offline without claiming it is reachable.');
    $service->setHealthChecker(null);
    expect(!$service->isPublicUrlReachable('https://127.0.0.1/') && !$service->isPublicUrlReachable('http://example.com/'), 'Health probes reject local addresses and non-HTTPS URLs.');
    foreach (['bad token', 'token;command', "token\ncommand"] as $token) {
        try { $service->setToken($token, 'remote.example.com'); throw new LogicException('Invalid token was accepted.'); }
        catch (InvalidArgumentException) {}
    }
    foreach (['https://remote.example.com', 'remote.example.com/path', 'remote.example.com:443'] as $host) {
        try { $service->setToken('valid-token', $host); throw new LogicException('Invalid hostname was accepted.'); }
        catch (InvalidArgumentException) {}
    }
    $builder = new App\Services\TunnelSetupBlockBuilder();
    $input = ['app_root' => "C:\\Store's PC\\retailmind", 'local_port' => 8080, 'token' => 'ngrok_token123', 'hostname' => 'store.ngrok-free.dev', 'mode' => 'ngrok', 'health_path' => '/retailmind/src/frontend/remote_health.php'];
    $block = $builder->buildSetupBlock($input);
    expect(str_contains($block, '-Mode Ngrok') && str_contains($block, 'service_setup.ps1'), 'Free setup uses the verified native-service installer.');
    expect(substr_count($block, $input['token']) === 1 && str_contains($block, "Store''s PC"), 'Credentials occur once and application paths are quoted.');
    foreach (['store.example.com', 'store.ngrok-free.dev/evil', 'x.ngrok-free.dev;cmd'] as $host) {
        try { $builder->buildSetupBlock(array_replace($input, ['hostname' => $host])); throw new LogicException('Invalid free hostname was accepted.'); }
        catch (InvalidArgumentException) {}
    }
    try { $builder->buildSetupBlock(array_replace($input, ['token' => ''])); throw new LogicException('Missing free token was accepted.'); }
    catch (InvalidArgumentException) {}
    // Exercise the real host classifier against an isolated installation manifest.
    $bootstrap = str_replace("\r\n", "\n", (string)file_get_contents(__DIR__ . '/../bootstrap/app.php'));
    preg_match('/function is_tunnel_host\(.*?(?=\n}\n)/s', $bootstrap, $hostFunction);
    $hostSource = str_replace("dirname(__DIR__) . '/storage/tunnel/active_tunnel.json'", var_export($root . '/active_tunnel.json', true), $hostFunction[0]);
    eval('function tunnel_host_suffixes(): array { return [".ngrok-free.dev"]; } ' . $hostSource);
    file_put_contents($root . '/active_tunnel.json', json_encode(['mode' => 'permanent', 'url' => 'https://remote.store.example']));
    expect(is_tunnel_host('REMOTE.STORE.EXAMPLE:443'), 'The verified custom hostname must use local database/workspace routing.');
    expect(!is_tunnel_host('attacker.remote.store.example') && !is_tunnel_host('remote.store.example.attacker.test'), 'Custom hostname trust must be an exact match.');
    expect(is_tunnel_host('store.ngrok-free.dev'), 'The free hostname remains recognized after staging an upgrade.');
    echo "Remote setup: disconnected status, staged upgrades, credential validation and free block passed.\n";
} finally {
    foreach (glob($root . '/*') ?: [] as $file) { unlink($file); }
    rmdir($root);
}
