---
status: accepted
---

# Owner-only remote monitoring through Cloudflare

The Store owner chose a stable browser link to the Store PC for read-only monitoring of existing Store activity, Cashier Shift, overview, and report screens. This revises the remote-access portion of ADR-0015: remote staff workflows and an installed private-network client are no longer required. Keep the local PC and database as the sole operational host, with sales and Cash Refunds at the Store.

Use a named Cloudflare Tunnel with a hostname on an owner-controlled Cloudflare domain. Protect the route with Cloudflare Access limited to the owner's exact email and one-time PIN, then require the owner's existing RetailMind sign-in and Workspace permissions. Validate Access at the tunnel, and enforce read-only access at the application boundary; Access authentication alone does not prevent writes. Exclude database backups and bulk downloads. Start the tunnel with Windows services. Remote monitoring is unavailable when the PC, local services, or internet connection is down; local checkout remains usable during an internet outage.

This decision trades remote staff functionality and full administration for a narrower browser-only monitoring route. It requires auditing every reachable route, including GET actions, and distinguishing trusted tunnel ingress from local checkout without trusting user-controlled headers or the proxy's loopback address. See [the monitoring specification](../specs/owner-remote-monitoring-cloudflare.md). No live setup or purchase is authorized by this record.
