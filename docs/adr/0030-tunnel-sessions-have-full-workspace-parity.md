---
status: accepted
---

# Tunnel sessions have full workspace parity, except Super Administrator

The Store operator runs RetailMind behind a tunnel (`cloudflared`, ngrok, or localtunnel) and expects the same behaviour as localhost there. The read-only monitoring model of ADR-0019 blocked most pages behind a fixed route whitelist and remote-operation guardrails, which made routine work (reports, inventory, POS, refunds) unreachable through the tunnel.

Requests whose host is a tunnel hostname are now classified as local: `is_tunnel_host()` in `src/backend/bootstrap/app.php` feeds `is_remote_request()`, the database host classification, and the barcode CORS origin. `admin`, `inventory_manager`, and `cashier` workspaces keep every capability they have on localhost, including writes, exports, and receipt printing.

The `super_admin` workspace stays local-only. Tunnel sessions never land on it, never see it in the workspace switcher, and get the shared access-denied page when a request resolves to it. This keeps platform governance, database restore, reset-data, database updates, and platform settings on the Store computer while the owner's `admin` workspace is fully usable remotely.

This supersedes the read-only portion of ADR-0019 (and its revision of ADR-0015). The 18-route whitelist remains in place as the guard for genuinely remote hosts such as the hosted `retailmind.infinityfreeapp.com` deployment; tunnel requests never reach it.

The Store's configured custom domain is a shared entry point for Administrator, Inventory Manager, and Cashier workspaces, including work from outside the Store; each person still signs in and retains only their assigned workspace permissions. Keep direct access through the Store PC's LAN IP available alongside the domain, including when internet access or the tunnel is unavailable. Local work requires the Store PC, web server, database, and local network to remain available; buying a domain does not move the application or its database off the Store PC. Super Administrator remains local-only through either access arrangement. Domain activation follows ADR-0031.

Localhost on the Store PC, direct LAN IP access, and the remote tunnel must work simultaneously. Starting or stopping remote access must not stop the local web server or database, disable local access, or force local requests onto the public domain. Other Store devices connect to the Store PC's LAN IP; their own localhost points to themselves.
