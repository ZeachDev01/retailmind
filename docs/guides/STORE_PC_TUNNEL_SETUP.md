# Store PC Remote Tunnel Setup

See the complete 5-minute setup guide at [docs/DEPLOYMENT_REMOTE_TUNNEL.md](../DEPLOYMENT_REMOTE_TUNNEL.md).

Open **Super Administrator → Platform Settings → Remote Access** on the Store PC.

For the free initial setup, create the Store's own ngrok account, then copy its authtoken and assigned `*.ngrok-free.dev` or `*.ngrok-free.app` hostname from the dashboard. Enter those values under **Free setup (ngrok)** and click **Generate free setup block**. Copy the block into 64-bit PowerShell opened as Administrator. It downloads the portable agent, installs its native Windows service, verifies the public route, and prints the link. Keep the generated block private because it contains the token. The local traffic inspector is disabled.

For a later custom-domain upgrade, buy/connect the domain in Cloudflare, create a Named Tunnel, and route the hostname to `http://127.0.0.1:<Apache port>`. Save its token and hostname in Remote Access, then **Generate custom domain setup block**, copy and run it as Administrator. Saving settings alone does not switch providers. The new route must pass verification before the working free service is stopped and changed to manual startup. Failed setup restores the previous configuration. RetailMind accounts and data stay the same; share the new link after upgrading.

Both services start automatically at boot and restart after crashes. The agents handle temporary network reconnects. The Store PC, Apache, database, and internet must remain running during store hours. The setup account can start/stop the service from non-elevated XAMPP; Apache running under another Windows account may need an explicit service-permission grant. **Test connection** forces a public check; other status checks can lag by one minute and count toward provider usage.

The Apache PHP runtime must load the cURL extension for the page's HTTPS status checks. A CLI PHP check alone does not verify Apache's extensions. If the service and public link work but the page stays Connecting, inspect Apache's startup log for `Unable to load dynamic library 'curl'`. On a manually upgraded Windows PHP installation, load its existing `brotlicommon.dll`, `brotlidec.dll`, `nghttp2.dll`, and `libssh2.dll` before the PHP module in Apache's config, in that dependency order; validate with `httpd.exe -t` before restarting Apache. Do not disable TLS verification or copy arbitrary DLLs into Windows system folders.

ngrok Free currently includes 1 GB outbound traffic and 20,000 HTTP requests per month, a browser warning, and an assigned hostname that survives agent restarts. Assets, polling and health checks consume requests. Provider outages and exhausted quota cannot be fixed by service restarts. See [ngrok limits](https://ngrok.com/docs/pricing-limits/free-plan-limits) and [ADR-0031](../adr/0031-free-ngrok-demo-with-cloudflare-domain-upgrade.md).

Before the demo, verify mobile-data login and role permissions, the same link after reboot, recovery after an internet disconnect/reconnect, honest unavailable status, a failed upgrade retaining the previous route, and local checkout during internet loss. These live checks require the Store's own token. Use **Generate removal block** to remove only this installation's tunnel services and credentials.

For manual script reference on dedicated Store PCs:
```powershell
# Permanent named tunnel
powershell.exe -ExecutionPolicy Bypass -File .\scripts\tunnel\service_install.ps1 -Token "<YOUR_TOKEN>" -Hostname "supervisor.mystore.com"

# Troubleshooting only: Quick Tunnel changes URL and has no uptime guarantee
powershell.exe -ExecutionPolicy Bypass -File .\scripts\tunnel\service_install.ps1 -Mode Quick
```
Service starts automatically at boot with auto-restart on unexpected crashes.
