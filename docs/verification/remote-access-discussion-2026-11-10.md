# Remote Access investigation and discussion — 2026-11-10

Status: requirements accepted; implementation completed for review. Live provider acceptance is pending the Store's credentials.

## Confirmed requirements

- Remote access is for authorized users only. Installing a client app is acceptable if needed.
- Initial installation and demonstration must work without requiring the customer to pay, including for a domain. A purchased domain remains an optional later upgrade, not a prerequisite.
- Remote availability is required during store hours only, while the Store PC, local services, and internet connection are running.
- ngrok Free's account requirement, browser warning, and usage limits are accepted for the zero-cost demo path; runtime acceptance remains pending.
- Acceptance must cover automatic startup, recovery after internet reconnect, the same link after restart, and no Online indication while disconnected. Quota exhaustion and provider outages must be shown as unavailable rather than treated as recoverable by endless restarting.
- A later custom-domain upgrade should use a simple token + hostname + verification flow comparable to the Brevo setup. Cloudflare-side domain/DNS/tunnel provisioning still needs to happen first; the existing generated Windows setup block requires administrator privileges.
- Each Store owns its ngrok and Cloudflare accounts. Existing RetailMind login and role permissions are the initial access gate.
- The user requested copy-paste service setup scripts available from Remote Access for both providers. This is implemented; provider dashboard provisioning remains manual.

## Observed runtime

- `RetailMindCloudflareTunnel` is Running with automatic startup, in Quick Tunnel mode, forwarding to `http://127.0.0.1:80`.
- `http://127.0.0.1:80/retailmind/` returns HTTP 200. This checks the landing page, not authenticated workflows.
- The cached Quick Tunnel hostname fails DNS resolution, including a lookup against `1.1.1.1`.
- The tunnel log repeatedly reports `Unauthorized: Tunnel not found`. Earlier connections registered at 17:18:28 and 17:25:44 Asia/Manila on November 10.
- `CloudflareTunnelService::getStatus()` treats a running process/service plus a URL as online; it does not establish public reachability. Service crash recovery does not help while the process remains alive and retries.

Bounded public reproduction (hostname is the observed temporary URL, not a permanent deployment address):

```powershell
curl.exe -sS --max-time 12 -o NUL -w "PUBLIC http=%{http_code} remote=%{remote_ip} time=%{time_total}\n" https://monitored-fee-solomon-correction.trycloudflare.com/retailmind/
```

Observed twice: curl exit 6, `Could not resolve host`, HTTP `000`. Evidence indicates a missing temporary tunnel registration; the reason it disappeared is not established.

## Accepted setup

For a zero-cost demonstration, evaluate ngrok Free with its assigned static dev domain. Official [free plan limits](https://ngrok.com/docs/pricing-limits/free-plan-limits) specify no endpoint timeout, 1 GB outbound transfer and 20,000 HTTP requests per month, and a browser warning page. Requests include assets and polling, not just page visits. The limits are accepted for the demo, but real-device verification is still needed before describing the setup as reliable. The application recognizes ngrok hostname suffixes, but its Remote Access management and Windows installation currently use Cloudflare; this does not establish complete ngrok integration.

Upgrade from the ngrok assigned URL to Cloudflare Named Tunnel on the owner's custom hostname. This changes the public URL and tunnel provider while retaining RetailMind accounts and data. Merely buying a domain does not enable custom domains on ngrok Free; that requires a paid ngrok plan. The installer validates the replacement connection before retiring the working demo connection and restores the previous setup if activation fails. Live provider acceptance is still pending.

If the team already controls a Cloudflare domain and can provide a subdomain, the existing named-tunnel integration could avoid a customer domain purchase. Domain ownership, ongoing responsibility, and the access policy have not been settled. Keep named Cloudflare Tunnel as an optional later setup and Quick Tunnel for temporary testing. A permanent hostname does not make the Store PC, Apache, database, or internet connection continuously available.

Cloudflare documents [Tunnel availability on all plans](https://developers.cloudflare.com/tunnel/), [the domain requirement for published applications](https://developers.cloudflare.com/tunnel/get-started/), and [Quick Tunnel's changing hostname and lack of uptime guarantee](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/).

Current workspace policy follows [ADR-0030](../adr/0030-tunnel-sessions-have-full-workspace-parity.md): Administrator, Inventory Manager, and Cashier retain their normal capabilities through a tunnel; Super Administrator remains local-only. Older read-only monitoring descriptions are superseded.

Implementation adds free/custom setup blocks, native-service verification and rollback, active-provider tracking, exact custom-host classification, and a data-free public health endpoint. Health results are cached for at most 60 seconds; Test connection bypasses the cache. Credentials are omitted from audit records and service command lines. See [ADR-0031](../adr/0031-free-ngrok-demo-with-cloudflare-domain-upgrade.md).

Validation: `npm run check`, `npm run check:contracts`, focused PHP service contracts, disconnected/recovered UI behavior, mocked native-service install/upgrade/rollback, actual ngrok 3.39.13 config validation, and rendered 1440px/390px production-section browser checks passed. The global header contract's hardcoded live sessions were replaced with current active-account lookup; Inventory Manager/Cashier API checks were skipped because no eligible live accounts existed. Existing role/parity contracts still passed. Proxy contracts passed with pre-existing PHP 8.5 SQLite deprecation notices.

No live tunnel restart, service switch, token change, or domain purchase was performed. An old global-header test deleted live tunnel cache/log files during its cleanup; the cached URL was restored and its filesystem fixture was moved to a temporary directory. The earlier log could not be recovered; the observed error is retained above. The Windows service remained running. No external-device authenticated login, reboot or real network-reconnect acceptance was performed.

## First live setup attempt: router DNS failure

The user ran the ngrok block. Public verification failed and rollback removed the new service, so the subsequent standalone `sc.exe query` printed error 1060. Local health was HTTP 200. Default Wi-Fi DNS (`192.168.0.1`) returned NXDOMAIN for the assigned ngrok hostname, while explicit queries to both `1.1.1.1` and `8.8.8.8` returned public A/AAAA records. A request pinned to a public DNS answer reached ngrok; no service remained after rollback. This establishes a local resolver problem, without establishing whether the supplied authtoken was accepted by ngrok.

With the user's explicit approval, Wi-Fi IPv4 DNS was set to `1.1.1.1` and `8.8.8.8`, the Windows DNS cache was cleared, and default hostname resolution then succeeded. The previous adapter settings are saved locally at `.impeccable/review/remote-access/dns-before.json`. The installer now checks DNS before changing services and emits a specific DNS error. Generated blocks run as a single PowerShell script block so installer failure prevents a misleading follow-up service query. Regression tests cover DNS failure before service changes. The user must regenerate/run the setup block with a private current token; no exposed token was reused by the agent. Actual ngrok authentication, live route, reboot and reconnect acceptance remain pending.

## Successful ngrok setup and Windows PHP certificate trust

The user's next run completed public verification. Read-only checks confirmed ngrok Running/Automatic, the previous Cloudflare service Stopped/Manual, and HTTP 200 for the public RetailMind landing page and health endpoint. PHP initially reported Connecting because its OpenSSL/cURL configuration had no CA bundle: cURL error 60, unable to get local issuer certificate. Windows native CA trust (`CURLSSLOPT_NATIVE_CA`, where supported) fixed the probe without disabling certificate verification. The opt-in `RUN_TUNNEL_LIVE_TEST=1 php src/backend/tests/remote_access_live_health_test.php` reproduced the failure before the fix and passed afterward; the real service status then reported Online with the correct RetailMind application URL. No service restart or credential change was needed for this fix. Authenticated mobile-data login, actual reboot and network reconnect acceptance remain pending.

## Provider setup views and persistent public link

Following the user's UI feedback, Remote Access separates ngrok Free and Cloudflare setup, keeps the installed provider visible independently of Online/Offline, and preserves the saved application link for copying/opening even when reachability is temporarily unverified. Tabs navigate setup only and never switch the service. Secondary instructions and maintenance actions use native disclosures; the setup controller uses the existing versioned asset helper to avoid stale cached behavior. A verified public health response can now establish Online even when the web process cannot query the Windows service. Browser tests isolate provider-navigation, clipboard, status recovery and viewport/theme behavior from the real Store service. See the [surface brief](../../.impeccable/surfaces/remote-access.md).

## Apache-specific cURL load failure

The user still saw Connecting after the CLI probe passed. A temporary localhost-only HTTP probe reproduced the actual Apache context: `apache2handler`, PHP 8.5.12, no loaded cURL extension. Apache's startup log reported that `php_curl.dll` could not load even though the file existed. Import inspection identified the PHP dependency DLLs; Apache's PHP config did not preload `brotlicommon.dll`, `brotlidec.dll`, `nghttp2.dll`, or `libssh2.dll` from the PHP directory.

The machine's `C:/xampp/apache/conf/extra/httpd-xampp.conf` was backed up as `httpd-xampp.conf.retailmind-curl-backup`. Four `LoadFile` lines were added before `LoadModule php_module`, `httpd.exe -t` passed, and Apache was restarted. ngrok was not restarted. The same real HTTP probe then reported Online, HTTP 200, cURL error 0, and the expected health body. The diagnostic file was removed. This confirms the Apache runtime fix rather than relying on a CLI-only pass; no application security check was disabled.
