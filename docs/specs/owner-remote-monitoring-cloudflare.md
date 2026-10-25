# Owner Remote Monitoring through Cloudflare

## Problem Statement

The Store owner wants to monitor staff and Store activity from a phone or laptop while away from the Store. RetailMind will run on a dedicated Windows Store PC; a localhost address cannot be reached from outside the Store. The owner wants a stable link available whenever that PC and its internet connection are running, without giving staff remote access or allowing remote changes. The existing local Windows deployment plan instead describes an installed private-network client and remote staff workflows.

## Solution

Give the owner a stable HTTPS browser link to the existing RetailMind installation through a named Cloudflare Tunnel. Cloudflare Access admits only the owner's exact email through a one-time PIN; RetailMind then requires the owner's normal sign-in. The remote route shows existing Store overview, staff activity, Cashier Shift, and report information. It rejects Store and account changes, sales, Cash Refunds, backups, and bulk downloads. Local checkout remains on the Store PC and works during an internet outage; remote monitoring is unavailable when the PC, required services, or internet connection is down.

## User Stories

1. As the Store owner, I want a stable browser address, so that I can bookmark RetailMind on my phone or laptop.
2. As the Store owner, I want to connect without installing a private-network app, so that I can check the Store from an ordinary browser.
3. As the Store owner, I want only my approved email to pass the Cloudflare gate, so that other people cannot reach the RetailMind login page remotely.
4. As the Store owner, I want a one-time PIN sent to my email, so that the first gate verifies control of that mailbox.
5. As the Store owner, I want my normal RetailMind sign-in after the Cloudflare gate, so that my existing account and Workspace rules remain in force.
6. As the Store owner, I want to see the existing Store overview, so that I can check current operational summaries away from the Store.
7. As the Store owner, I want to see existing staff activity and Cashier Shift information, so that I can monitor work recorded by the application.
8. As the Store owner, I want to read existing Store Reports, so that I can review business results remotely.
9. As the Store owner, I want monitoring pages to work on my phone and laptop, so that device choice does not prevent access.
10. As the Store owner, I want remote pages and assets to load under the stable HTTPS address, so that links and navigation work without returning me to localhost.
11. As the Store owner, I want my remote session to expire and sign out normally, so that an unattended browser does not retain access indefinitely.
12. As the Store owner, I want all remote Store and account changes blocked, so that monitoring cannot accidentally alter operations.
13. As the Store owner, I want direct and disguised write requests blocked, so that a hidden button or alternate URL cannot bypass read-only access.
14. As the Store owner, I want sales and Cash Refunds restricted to the Store, so that a remote browser cannot operate the physical register.
15. As the Store owner, I want database backups and bulk downloads unavailable remotely, so that monitoring does not expose large sets of sensitive records.
16. As the Store owner, I want local authorized administration to keep working, so that the remote restriction does not remove Store-side capabilities.
17. As a Cashier, I want local checkout to work during an internet outage, so that losing Cloudflare connectivity does not stop Store sales.
18. As the Store owner, I want the tunnel to recover after a Windows reboot, so that nobody must manually start a development control panel.
19. As the Store owner, I want a clear understanding that remote monitoring stops when the PC, application services, or internet stops, so that I do not mistake it for cloud hosting.
20. As the Store owner, I want to control the domain and Cloudflare account, so that the remote address and access policy remain under Store ownership.
21. As the Store owner, I want any domain or service cost reviewed before purchase, so that setup creates no unapproved expense.

## Implementation Decisions

- **Existing host:** Keep the Store PC and its local database as the sole live installation. Reuse standalone Apache/PHP and MySQL/MariaDB Windows services from the local deployment plan. Start `cloudflared` as a Windows service. Do not rely on Laragon at boot.
- **Stable tunnel:** Use a named Cloudflare Tunnel and an owner-controlled domain in Cloudflare. The tunnel connects outward to Cloudflare; no router port forwarding or inbound database access is needed. Create the Access application before publishing the route.
- **Owner gate:** Configure an Access Allow policy for the owner's exact email, with one-time PIN login. Do not use an all-email, email-domain, or Everyone rule. Require Access token validation at the tunnel. Preserve RetailMind's own login, session policy, and active Workspace permissions.
- **Read-only remote ingress:** Expose only approved monitoring pages and supporting assets, authentication, and session operations through the remote route. Deny other routes at a trusted server boundary. Audit every reachable handler, including side-effecting GET requests, alternate endpoints, and multi-role Workspace switching. HTTP method alone is not a sufficient write classifier. Keep backups and bulk exports unreachable.
- **Local versus remote:** The boundary must distinguish trusted tunnel ingress from local checkout. A proxy's loopback source address and arbitrary forwarding headers do not establish local trust. The selected routing arrangement must remain sound when the same owner account is used locally and remotely.
- **Existing screens:** Reuse the current Store overview, staff and Cashier Shift activity, and Store Reports. No new live-tracking screen or monitoring platform. Any existing navigation that leads to a denied action should not promise that action remotely; server denial remains authoritative.
- **Origins and database:** Verify URL base path, relative links, assets, redirects, session cookies, CSRF, and HTTPS under local and remote origins. Current database configuration infers a hosted database from a public host name; select the Store PC's local database through trusted deployment configuration. Ensure remote session cookies are secure even if the tunnel-to-Apache hop is HTTP. Do not trust client-supplied scheme headers.
- **Availability:** Remote access works only while the PC, Apache, database, `cloudflared`, and internet are available. Local checkout and printing continue during an ISP outage as required by the local deployment plan.
- **Scope revision:** This replaces the remote-access portion of the earlier local Windows deployment plan: no approved-staff remote workflows or installed private-network client. Its local hosting, backup, recovery, and offline-checkout requirements remain applicable. No new schema or public API is required by the agreed feature.

## Testing Decisions

- **Primary seam: deployed browser and direct HTTP behavior.** Use the existing Administrator dashboard, login/session, and cashier browser/HTTP test patterns. Test what an owner or attacker can observe and change, not tunnel configuration strings or private helper calls.
- Verify the approved owner passes Access and RetailMind login, opens each selected monitoring page, follows links, and remains on the remote HTTPS origin. An unapproved email cannot reach RetailMind. Check phone and laptop viewport behavior through the existing responsive browser patterns.
- With a valid owner session, directly request representative mutation, alternate-route, GET-action, backup, export, sale, and Cash Refund endpoints through the remote ingress. Assert denial and unchanged persisted Store/account state. Exercise a multi-role account. Confirm the same actions still follow normal authorization locally.
- Verify session cookie security and normal sign-out/expiry on the remote origin, and confirm the public hostname reaches the local database rather than a hosted database.
- **Windows acceptance:** Reboot the Store PC and verify Apache, database, and tunnel availability without Laragon. Disconnect the ISP while keeping the PC running; confirm a fresh local checkout and receipt flow work while the remote route stops. Restore connectivity and verify monitoring returns. Shut down the PC and verify remote access stops.
- Prior art includes existing Administrator dashboard browser and route tests, login/session browser tests, cashier checkout and refund browser workflows, and the Windows boot/outage checks already specified for local deployment. Add only the cases needed to prove this route's distinct access and read-only boundary.

## Out of Scope

- Remote staff access, remote inventory changes, full remote administration, remote sales, and remote Cash Refunds.
- New live staff tracking, camera monitoring, a new dashboard, cloud database replication, and automatic failover.
- Quick Tunnels with changing URLs, public anonymous access, open router ports, and direct database access.
- Database backup/download access through the monitoring route. Existing local backup and recovery work is governed by the local deployment plan.
- Buying a domain, subscribing to a paid plan, or activating the production tunnel as part of writing this specification.

## Further Notes

This specification follows the confirmed Owner Remote Monitoring decision in ADR-0019 and supersedes only the remote-access requirements of local Windows deployment issue #271. Before implementation, identify the owner-controlled Cloudflare domain and exact owner email, review any cost, and inventory the route allowlist and all reachable write/download actions. Cloudflare Tunnel provides reachability; Cloudflare Access gates identity; RetailMind enforces its own roles and the remote read-only boundary.

Published as [GitHub issue #318](https://github.com/ZeachDev01/retailmind/issues/318) with `ready-for-agent`.
