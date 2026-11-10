# Remote Access investigation and discussion — 2026-11-10

Status: discussion in progress; no deployment or runtime changes authorized by this note.

## Confirmed requirements

- Remote access is for authorized users only. Installing a client app is acceptable if needed.
- Initial installation and demonstration must work without requiring the customer to pay, including for a domain. A purchased domain remains an optional later upgrade, not a prerequisite.
- Remote availability is required during store hours only, while the Store PC, local services, and internet connection are running.
- ngrok Free's account requirement, browser warning, and usage limits are accepted for the zero-cost demo path; runtime acceptance remains pending.
- Acceptance must cover automatic startup, recovery after internet reconnect, the same link after restart, and no Online indication while disconnected. Quota exhaustion and provider outages must be shown as unavailable rather than treated as recoverable by endless restarting.
- A later custom-domain upgrade should use a simple token + hostname + verification flow comparable to the Brevo setup. Cloudflare-side domain/DNS/tunnel provisioning still needs to happen first; the existing generated Windows setup block requires administrator privileges.
- Account ownership, access policy, and final implementation scope remain undecided.

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

## Recommendation pending agreement

For a zero-cost demonstration, evaluate ngrok Free with its assigned static dev domain. Official [free plan limits](https://ngrok.com/docs/pricing-limits/free-plan-limits) specify no endpoint timeout, 1 GB outbound transfer and 20,000 HTTP requests per month, and a browser warning page. Requests include assets and polling, not just page visits. The limits are accepted for the demo, but real-device verification is still needed before describing the setup as reliable. The application recognizes ngrok hostname suffixes, but its Remote Access management and Windows installation currently use Cloudflare; this does not establish complete ngrok integration.

Proposed upgrade: move from the ngrok assigned URL to Cloudflare Named Tunnel on the owner's custom hostname. This changes the public URL and tunnel provider while retaining RetailMind accounts and data. Merely buying a domain does not enable custom domains on ngrok Free; that requires a paid ngrok plan. Validate the replacement connection before retiring the working demo connection, and preserve a recovery path if activation fails. This switching behavior is proposed, not implemented.

If the team already controls a Cloudflare domain and can provide a subdomain, the existing named-tunnel integration could avoid a customer domain purchase. Domain ownership, ongoing responsibility, and the access policy have not been settled. Keep named Cloudflare Tunnel as an optional later setup and Quick Tunnel for temporary testing. A permanent hostname does not make the Store PC, Apache, database, or internet connection continuously available.

Cloudflare documents [Tunnel availability on all plans](https://developers.cloudflare.com/tunnel/), [the domain requirement for published applications](https://developers.cloudflare.com/tunnel/get-started/), and [Quick Tunnel's changing hostname and lack of uptime guarantee](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/).

Current workspace policy follows [ADR-0030](../adr/0030-tunnel-sessions-have-full-workspace-parity.md): Administrator, Inventory Manager, and Cashier retain their normal capabilities through a tunnel; Super Administrator remains local-only. Older read-only monitoring descriptions are superseded.

No tunnel restart, service change, token change, domain purchase, or application fix was performed. No external-device browser or authenticated workflow test was performed.
