# Local Windows deployment with private remote access

The remote-access requirements below are superseded by [Owner remote monitoring through Cloudflare](owner-remote-monitoring-cloudflare.md): owner-only, browser-based, and read-only. The local hosting, checkout, backup, and recovery requirements still apply.

## Problem Statement

The Store owner wants RetailMind to run on an existing dedicated Windows PC with one wired barcode scanner and one thermal printer. Cashiers must be able to sell when the Store's internet connection is unavailable, and the application must start whenever Windows starts without someone launching Laragon or dismissing its popup.

The owner also wants optional private internet access for approved users. A host failure must be recoverable from separately stored backups. Existing backup capture, role authorization, and scheduling components cover part of this need, but unattended deployment, durable backup archiving, and local-only cashier enforcement are not complete.

## Solution

Deploy one local RetailMind application and primary database using standalone Apache HTTP Server, PHP, and the existing MySQL/MariaDB engine. Configure Apache and the database for automatic Windows service startup, and run the existing PHP/Python background jobs through Windows scheduling independently of Laragon. Once services are ready, the Cashier opens the local browser URL; automatic browser launch or Windows sign-in is not required.

Provide optional encrypted private-network access for the owner and approved staff using an installed client on their remote devices. Remote access supports authorized reports, inventory, and administration; completing sales and Cash Refunds is restricted to the Store checkout PC. Keep application sign-in and active Workspace permissions in force through both access routes.

Create one automatic Database Backup per day, retain 30 daily recovery copies subject to storage capacity, and preserve recoverable copies on an external drive and encrypted owner-controlled cloud storage. The user accepts potential loss of approximately one day of sales and inventory changes after drive failure. Target restoration within four business hours when suitable replacement hardware and recovery credentials are available. The owner decides UPS purchase and runtime; the user maintains updates, backup alerts, and recovery.

## User Stories

1. As the Store owner, I want to use the existing Windows PC, so that deployment does not require another server.
2. As a Cashier, I want RetailMind's services to start whenever Windows boots, so that I do not need to launch a development control panel.
3. As a Cashier, I want a documented local browser address, so that I can open RetailMind once its services are ready.
4. As a Cashier, I want checkout to work while the Store's internet connection is unavailable, so that an ISP outage does not stop local sales.
5. As a Cashier, I want the existing wired barcode scanner to work, so that I can scan Products without new scanning hardware.
6. As a Cashier, I want the existing thermal printer to print and reprint Sale Receipts, so that customers receive proof of purchase.
7. As a Cashier, I want existing Cashier Shift, stock validation, and duplicate-submission protections preserved, so that deployment does not change transaction correctness.
8. As the Store owner, I want private access from an approved phone or laptop, so that I can view Store Reports away from the Store.
9. As an Inventory Manager, I want approved remote access to my existing Workspace, so that I can perform permitted inventory work away from the Store.
10. As an Administrator, I want my existing authorized administrative workflows remotely, so that I can manage the Store without visiting it.
11. As the Store owner, I want remote access limited to approved users and devices, so that the application does not require public website exposure.
12. As the Store owner, I want remote access revocable, so that a lost device or departed staff member can lose access.
13. As the Store owner, I want remote sales and Cash Refunds blocked even for a user with a Cashier role, so that checkout remains at the physical register.
14. As a multi-role user, I want active Workspace permissions preserved, so that private-network membership does not combine my assigned roles.
15. As the Store owner, I want a consistent Database Backup created daily, so that a host failure does not require rebuilding all Store records manually.
16. As the Store owner, I want 30 daily recovery copies retained, so that I can recover from a problem discovered after the latest backup.
17. As the Store owner, I want a backup copied to a separate external drive, so that the host's internal drive is not the only recovery location.
18. As the Store owner, I want encrypted cloud copies, so that a Store-site incident does not necessarily destroy every recovery copy.
19. As the Store owner, I want local backups to continue during an internet outage, so that cloud unavailability does not prevent backup capture.
20. As the maintainer, I want capture, external-copy, and cloud-copy failures distinguishable, so that I know which recovery copies actually exist.
21. As the Store owner, I want control of the cloud account and an encryption-key recovery copy outside the PC, so that recovery does not depend entirely on the maintainer.
22. As the maintainer, I want a documented replacement-PC restoration procedure, so that I can pursue the agreed four-business-hour recovery target.
23. As the Store owner, I want to understand that daily backups can lose approximately a day of records, so that the recovery limit is explicit.
24. As the maintainer, I want forecasting and notification jobs scheduled independently of Laragon, so that background operations survive the deployment change.
25. As the Store owner, I want to approve UPS capacity, runtime, and cost, so that power protection matches the Store's needs and budget.
26. As the maintainer, I want compatible application files, configuration, uploads, and forecasting artifacts included in the recovery procedure, so that a SQL file is not mistaken for a complete PC recovery package.
27. As the Store owner, I want exact recurring service prices reviewed before purchase, so that the deployment creates no unapproved subscription costs.
28. As the maintainer, I want a rehearsed boot, internet-outage, and restore checklist, so that readiness is demonstrated on the actual Windows setup.

## Implementation Decisions

- **One host and database:** The Store PC hosts the application, primary database, and current PHP/Python jobs. Remote users access the same application and data. No cloud replica, synchronization protocol, second writable database, or automatic failover is introduced.
- **Standalone Windows services:** Replace reliance on Laragon with standalone Apache and PHP integration plus the existing database engine. Preserve compatible runtime versions, required PHP extensions, application URL handling, Apache access restrictions, and filesystem permissions. Configure automatic service startup and dependency ordering; validate actual startup rather than assuming service registration proves readiness.
- **Windows scheduling:** Reuse the current asynchronous forecasting scheduler/CLI worker and notification maintenance flows. Do not introduce the legacy Flask service. Schedule work while the PC is powered on and document missed-run handling; jobs cannot run while the PC is off. Correct stale deployment instructions accordingly.
- **Local checkout availability:** Sale finalization still requires a reachable application server and database, but not a working ISP connection when both run locally. Audit required assets and operational requests for external dependencies and verify cold-start operation without internet. PC failure, service failure, or power loss remains an outage. Cached carts are not permission to finalize a sale without the server.
- **Private remote ingress:** Use an installed private-network client for approved remote devices. The specific provider, account plan, and price are setup selections rather than decisions already made. Keep the database and recovery storage unavailable through remote web access. Use encrypted remote transport, retain application authentication, and document device/user enrollment and revocation.
- **Trusted local-only authorization:** Enforce the location restriction on every sale-finalization and Cash Refund mutation, including alternate endpoints and multi-role Workspace switching. Hiding navigation or checking assigned roles alone is insufficient. Use a trustworthy distinction between local checkout ingress and private remote ingress. Do not infer trust from arbitrary client-supplied forwarding headers or assume a proxy's loopback connection makes a request local. Preserve ordinary role, Cashier Shift, validation, CSRF, and concurrency checks.
- **Browser origin and HTTPS:** Use a stable loopback origin for checkout on the host PC where supported. If another browser origin or device is introduced, validate its secure-context requirements before use. Verify Web Locks, session cookies, and generated links under the selected remote HTTPS arrangement; trust proxy metadata only from configured trusted ingress. A wired barcode scanner does not require adding phone-camera scanning.
- **Durable daily backups:** Reuse the coordinated Database Backup capture and existing SQL format. Immediately preserve successful scheduled captures in a durable protected archive rather than relying on temporary downloads. Capture completion, external-copy completion, and cloud-copy completion are separate outcomes. Validate copied artifacts and retain dated successful daily copies; failed or partial copies must not replace the last good recovery copy.
- **Retention and schedule:** Retain 30 daily recovery copies subject to provisioned capacity. The maintainer selects a daily time when the PC will be on and documents what happens when a run is missed or the PC is shut down early. Make stale/missed backups and unavailable destinations visible to the maintainer through a documented operational check or existing status surface. Do not claim a one-day loss bound after failed or missed backups.
- **Private storage and encryption:** Keep active recovery locks/state on reliable private local storage outside the application and web roots. Archive copies outward; do not turn a cloud-sync directory into the active recovery store. Encrypt cloud-bound archives before upload. Existing readable manual SQL downloads and the SQL restore format remain as defined by the backup ADR; encryption is an outer archive/storage protection layer, removed before ordinary SQL validation and restore. Restrict access to local plaintext artifacts and keep secrets out of source control, logs, and public URLs.
- **Backup destinations:** Use an external drive for recovery from host-drive failure and owner-controlled cloud storage for an additional off-site copy. Continue local capture/copy while cloud connectivity is down and report cloud staleness; upload preserved pending copies when connectivity returns. A connected external drive is not independent protection against ransomware, theft, or site loss.
- **Complete recovery:** Document preservation or reinstallation of the matching application version, configuration, required uploads, forecasting artifacts, dependencies, and private recovery records in addition to SQL. Reuse existing Super Administrator-only Database Restore validation, safety backup, Store blocking, and session invalidation. Stop independent database writers during restoration. Do not merge newer records into a full-replacement restore.
- **Recovery targets:** One daily backup with approximately one day of potential data loss supersedes the earlier one-hour proposal. Four business hours is a target conditional on suitable replacement hardware, usable backup copies, keys, credentials, and matching software; a restore drill must establish whether it is realistic. Use manual sales recording while the application is unavailable; automatic reconciliation of those records is not part of this feature.
- **Power and maintenance:** The owner retains the UPS purchase/runtime decision. Short-outage bridging and controlled shutdown are recommendations, not an approved runtime specification. Size and test against the actual connected load. Automatic Windows service startup does not guarantee the PC itself powers on after an outage; firmware and UPS behavior require separate validation. The user is responsible for updates, backup-failure review, and recovery, while the owner retains account and encryption-key recovery access.
- **Interfaces and scope:** Reuse the existing backup, restore, authorization, sales/refund, and scheduling boundaries. No new schema or public API contract was agreed in the discussion. Prefer deployment configuration and the smallest shared enforcement/automation changes needed for these requirements; update affected callers and operational documentation together.

## Testing Decisions

The user confirmed using existing browser/HTTP workflows, existing backup/restore integration coverage, and a Windows boot/outage/restore acceptance checklist. Tests assert observable outcomes rather than specific configuration text, helper calls, or internal archive layouts.

- **Primary seam: deployed application workflows.** From the local checkout browser, open a Cashier Shift, scan a Product with the wired scanner, complete a sale, print its Sale Receipt, retry a submission safely, and perform an authorized Cash Refund. Reuse existing cashier browser, receipt, refund, and offline-scanning scenarios.
- **Real ISP outage:** Disconnect internet access while keeping the PC and local services running. Test a fresh browser session/page load as well as an already-open checkout. Verify required assets load, scanning and sale finalization work, and receipts print. Browser-wide offline emulation alone is insufficient because it can also block the local server. Confirm remote access becomes unavailable without corrupting local transactions.
- **Remote authorization:** Exercise approved reports, inventory, and administration through the private route. Attempt sale and Cash Refund mutations through that route using a valid Cashier Workspace, a multi-role user, alternate mutation routes, direct HTTP requests, and spoofed forwarding headers. Verify rejection and unchanged sales, cash, and stock records. Verify the same authorized local flows still succeed and unauthorized private-network users do not bypass sign-in.
- **Backup and archive behavior:** Extend existing Database Backup/Database Restore integration coverage using disposable databases and existing overlap/concurrent-writer scenarios. Verify a consistent capture survives temporary-download cleanup, successful copies match the captured artifact, cloud archives are encrypted and recoverable, and retention preserves the required daily history.
- **Backup failures:** Simulate a missing external drive, denied write, insufficient disk space, encryption failure, lost internet/cloud credentials, and overlapping runs. Verify failures are reported without deleting or replacing the last good copy, local backup can succeed during a cloud outage, and pending cloud copies can subsequently complete. Verify a failed run is not reported as a fresh recoverable daily backup.
- **Windows startup and jobs:** Cold-boot the actual PC, confirm Apache/database readiness and scheduled-job operation without opening Laragon or an interactive control panel, and verify checkout after services become ready. Exercise early shutdown/missed schedules and startup recovery instructions. Check that the configured recovery target is not claimed merely because a task exists.
- **Secure origins and deployment boundaries:** Verify checkout recovery/browser features, session cookies, generated URLs, remote TLS, and trusted-ingress behavior. Confirm backend/private artifacts, SQL files, credentials, and database ports are not exposed through either web route.
- **Restore drill:** On an isolated suitable replacement environment, recover the owner-controlled key and archive, decrypt the trusted SQL, restore through existing safeguards, recover required non-database artifacts, and verify sign-in, Products/stock, Sale Receipts, reports, and background jobs. Measure elapsed recovery time against four business hours. Never use the live Store database as a destructive test fixture.
- **Hardware and power acceptance:** Test the actual scanner and thermal printer with the deployed browser/driver settings. If a UPS is purchased, validate supported safe shutdown and actual runtime on the chosen load without risking live transaction data. Document behavior after power returns.

## Out of Scope

- Installing, migrating, publishing the application, or purchasing services as part of this specification-writing session.
- Public website exposure, anonymous access, public router port forwarding, or remotely exposed database/admin tools.
- Cloud hosting of the primary application, multiple Stores, database replication, automatic failover, or continuous availability while the PC is off.
- Offline sale finalization without the local application server, offline payment authorization, or automatic import of manually recorded outage sales.
- A one-hour recovery-point guarantee, zero data loss, or guaranteed four-hour recovery without available replacement hardware.
- New scanning hardware, camera-scanning workflows, a new receipt design, or replacement of the existing thermal printer.
- Selecting or buying a UPS before the owner's runtime/budget decision and measured load are available.
- New forecast models, Flask hosting, new role combinations, or changes to existing restore privileges and full-replacement semantics.
- A new monitoring platform, backup-management dashboard, generalized installer framework, or provider abstraction without a demonstrated need.

## Further Notes

- This specification records the discussion on 2026-10-22. It authorizes specification publication and future implementation planning, not live deployment, destructive migration, or purchases.
- The owner must review exact recurring costs before purchase. Private-network provider, cloud provider, credentials, backup clock time, storage capacity, and UPS model/runtime remain installation-specific selections; do not invent paid commitments or embed account secrets in the issue.
- The existing scheduler documentation recognizes a dedicated Windows Store PC. Parts of the deployment checklist still describe older worker/setup assumptions and must be reconciled with the current CLI scheduling flow.
- Existing SQL capture produces temporary private artifacts rather than a durable encrypted off-PC archive. Existing Workspace authorization does not enforce the agreed location restriction. Both gaps require implementation and behavioral verification.
- The older caching ADR describes finalization as requiring an internet connection. For this deployment, the requirement is connectivity to the authoritative application server/database; with those on the checkout PC, an ISP connection is unnecessary. Preserve its server-side validation and idempotency requirements and clarify that wording in the related documentation.
- The encrypted cloud archive requirement supplements, rather than changes, the readable manual SQL download and trusted full-replacement restore policies.
- Related decisions: [local Windows hosting](../adr/0015-local-windows-host-with-private-remote-access.md), [single-Store boundaries](../adr/0001-single-store-administrator-boundaries.md), [SQL backup and full replacement](../adr/0004-plain-sql-backup-and-full-replacement.md), [asynchronous forecasting](../adr/0005-single-random-forest-forecasting-pipeline.md), and [caching and server-validated checkout](../adr/0012-storewide-caching-and-offline-tolerant-operations.md).

Published as [GitHub issue #271](https://github.com/ZeachDev01/retailmind/issues/271), labelled `ready-for-agent`.
