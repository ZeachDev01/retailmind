---
status: accepted
---

# One-paste remote-access setup with an interim Quick Tunnel

The Store PC already runs RetailMind under XAMPP, and the Super Administrator holds the tunnel token in the Remote Access card, yet enabling Remote Access required locating scripts and reconstructing an elevated PowerShell command. Generate a self-contained PowerShell setup block from that card instead: the operator pastes it once into an elevated shell on the same PC, and it provisions `cloudflared`, registers the auto-start `RetailMindCloudflareTunnel` Windows Service, starts it, and prints its state. The block is idempotent so the same paste repairs an existing install. The card stays local-only, capability-gated, and audit-logged, and it keeps the token masked until generation.

This amends the named-tunnel-only expectation of ADR-0019 and the Quick Tunnel exclusion in `owner-remote-monitoring-cloudflare.md`: until the owner buys a domain, no named tunnel can exist, so the same card may generate an interim Quick Tunnel block. That trade-off is accepted with its limits made visible: the Quick Tunnel URL changes on restart and sits outside Cloudflare Access; RetailMind sign-in and the application read-only boundary still apply. Saving a token and hostname later switches the same service to the permanent named tunnel without source changes. Setup deliberately excludes XAMPP, application files, and database import, which remain manual preparation steps.

## Consequences

- A Quick Tunnel URL is public HTTPS without the owner-email Access gate; the application login and read-only enforcement carry the full boundary during the interim.
- The operator must re-copy a regenerated block to change modes or tokens; there is no background self-update.
- The setup block reuses the existing tunnel installer rather than introducing a second deployment path, so future service changes must keep that installer paste-safe.
