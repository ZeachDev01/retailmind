# Remote Access stuck on Connecting — 2026-11-16

Cause: Apache PHP could not load `php_curl.dll` because its Brotli decoder dependency was outside Apache's DLL search path. CLI PHP loaded cURL successfully. `CloudflareTunnelService::isPublicUrlReachable()` therefore returned false under Apache even though ngrok was connected.

Environment fix in `C:/xampp82/apache/conf/extra/httpd-xampp.conf`, immediately before `LoadModule php_module`:

```apache
LoadFile "C:/xampp82/php/brotlidec.dll"
```

Original config backed up at `C:/xampp82/apache/conf/extra/httpd-xampp.conf.before-tunnel-curl-fix`. Apache syntax check passed, then Apache restarted successfully. No application logic or tunnel credentials changed.

## Verification

A temporary localhost-only PHP endpoint called the real `CloudflareTunnelService::getStatus(true)` inside Apache. An unattended HTTP assertion required `online=true`:

- Before: `status=starting`, `online=false`, `running=true`, `curl=false` — failed.
- After: `status=online`, `online=true`, `running=true`, `curl=true`, `native_ca=true` — passed.
- Temporary endpoint removed after verification. This was an environment regression; the existing CLI test alone cannot detect an Apache-only extension-loading failure.

Repeat the existing live HTTPS check from an unrestricted network context:

```powershell
$env:RUN_TUNNEL_LIVE_TEST='1'
& C:/xampp82/php/php.exe src/backend/tests/remote_access_live_health_test.php
```

Result: `Live tunnel health: verified through PHP with certificate validation enabled.` The earlier sandbox run failed because outbound network access was restricted; that failure was not evidence of a tunnel outage.

Protected ngrok configuration passed `ngrok.exe config check`. Upstream is `http://127.0.0.1:80`; `web_addr` and `remote_management` are disabled. Apache2.4, mysql, and ngrok were Running with automatic startup. Ngrok crash recovery was already configured for restarts after 5, 10, and 20 seconds, with a daily failure-count reset.

If this recurs after a PHP/XAMPP upgrade, verify cURL inside Apache, not just CLI PHP. Keep TLS certificate verification enabled.
