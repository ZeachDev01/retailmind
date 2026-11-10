<?php

declare(strict_types=1);

// Opt-in: the installed service and real HTTPS route must already be available.
if (getenv('RUN_TUNNEL_LIVE_TEST') !== '1') {
    echo "Live tunnel health: skipped (RUN_TUNNEL_LIVE_TEST=1 required).\n";
    exit;
}
require_once __DIR__ . '/../app/Services/CloudflareTunnelService.php';
$status = (new App\Services\CloudflareTunnelService())->getStatus(true);
if (empty($status['online']) || empty($status['url'])) {
    throw new RuntimeException('The running public tunnel must pass the PHP HTTPS health check.');
}
echo "Live tunnel health: verified through PHP with certificate validation enabled.\n";
