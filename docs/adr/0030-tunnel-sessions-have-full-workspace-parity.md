---
status: accepted
---

# Tunnel sessions have full workspace parity for all assigned roles

The Store operator runs RetailMind behind a tunnel (`cloudflared`, ngrok, or localtunnel) and expects the same behaviour as localhost there. The read-only monitoring model of ADR-0019 blocked most pages behind a fixed route whitelist and remote-operation guardrails, which made routine work (reports, inventory, POS, refunds) unreachable through the tunnel.

Requests whose host is a tunnel hostname are now classified as local: `is_tunnel_host()` in `src/backend/bootstrap/app.php` feeds `is_remote_request()`, the database host classification, and the barcode CORS origin. `super_admin`, `admin`, `inventory_manager`, and `cashier` workspaces keep every capability they have on localhost, including writes, exports, and receipt printing.

Amended 2026-11-23 at the Store operator's request: the `super_admin` workspace is also available through the tunnel. Super Administrator accounts can sign in, retain their selected Workspace, and switch among their assigned Workspaces just as on localhost. Platform governance, database restore, reset-data, database updates, and platform settings remain subject to their existing role permissions, password/CSRF checks, and audit rules. This replaces the previous Super Administrator local-only exception; it does not grant platform permissions to other roles.

This supersedes the read-only portion of ADR-0019 (and its revision of ADR-0015). The 18-route whitelist remains in place as the guard for genuinely remote hosts such as the hosted `retailmind.infinityfreeapp.com` deployment; tunnel requests never reach it.

The Store's configured custom domain is a shared entry point for all assigned Workspaces, including Super Administrator, including work from outside the Store; each person still signs in and retains only their assigned workspace permissions. Keep direct access through the Store PC's LAN IP available alongside the domain, including when internet access or the tunnel is unavailable. Local work requires the Store PC, web server, database, and local network to remain available; buying a domain does not move the application or its database off the Store PC. Domain activation follows ADR-0031.

Localhost on the Store PC, direct LAN IP access, and the remote tunnel must work simultaneously. Starting or stopping remote access must not stop the local web server or database, disable local access, or force local requests onto the public domain. Other Store devices connect to the Store PC's LAN IP; their own localhost points to themselves.
