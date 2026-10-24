---
status: accepted
---

# Encrypted portable backups and Administrator restore

On 2026-10-24, the user selected recovery after data loss and migration in both directions between localhost and web hosting, encrypted backups that protect against theft of the backup file, and Database Restore access for the Administrator as well as the Super Administrator. The user subsequently requested specification synthesis/publication and confirmed workflow integration and Administrator-screen testing with disposable data. This accepts the target design; it does not authorize production deployment or a destructive live migration.

This revisits ADR-0004's plaintext backup and Super Administrator-only restore policy and ADR-0001's recovery authority boundary. Migration adds a choice of active host to ADR-0015's local-hosting plan; it does not authorize simultaneous independent Store operation. Existing behavior and operational instructions remain unchanged until the design is completed and implemented.

## Confirmed design requirements

- Migration switches one active Store installation between localhost and hosting; it does not synchronize two independently operating Stores.
- Transfer the database and uploaded images/files into a destination with compatible RetailMind code already installed. Configure destination database credentials, domain, and server secrets separately.
- Require a portable backup passphrase independent of login passwords, with a recovery copy held outside both servers.
- Administrator restoration preserves destination Super Administrator and Recovery Account access. The identity mapping and protection of related privilege data remain unresolved; this is an exception to today's full replacement, not an implemented safeguard.
- Pause sales and background jobs from final source capture through destination verification. After the destination begins accepting transactions, returning to the other host requires a fresh migration from the active installation.
- Rebuild and approve forecasting models on the destination rather than treating source database-bound artifacts as portable. Forecasting stays unavailable until ready; verified sales and inventory operations may resume earlier.
- The Store owner controls the backup passphrase, supplies it to authorized restoring administrators, and retains a separate recovery copy outside both servers.
- Keep destination Super Administrator and Recovery Account access authoritative. Retain source privileged identities for historical attribution without automatically granting them active privileged access on the destination.
- Detect ambiguous account ID, username, or email conflicts before replacement and require explicit resolution; never silently combine different people or reattribute their historical records.
- Real hosted Store operations use a production site/database separate from the existing Hosted Demo. The user's "okay for now" is taken to accept forecasting remaining unavailable on current hosting; no compatible Python host or purchase is selected.
- Restore replaces newer Store records with the selected snapshot rather than merging them. Show an explicit warning that post-snapshot sales and other changes disappear from the active database.
- Preserve destination security settings and security logs while importing Store sales, stock, staff, and historical records. Define and validate the exact security-data boundary before implementing replacement; do not treat retaining login passwords alone as sufficient.
- Capture and verify a complete destination safety copy before any replacement. If capture fails, abort without replacement. A failure after replacement starts keeps Store operations blocked until recovery finishes.
- The new ordinary restore workflow accepts encrypted packages only. Retain existing plaintext SQL backups privately until replacement encrypted backups have been successfully restore-tested. Using an old SQL backup requires a separate recovery procedure; its design and implementation are outside the new ordinary restore workflow.

## Implementation and deployment prerequisites

- Confirm the exact separate production site and compatible PHP, database, encryption support, private storage, upload limits, and available disk/memory before migration. This record does not authorize deployment or purchases.
- Define the backup package and complete validation before destructive changes: compatible application/schema version, authenticated decryption, safe uploaded-file paths and types, required contents, and account/security reconciliation.
- Preserve historical actor attribution and destination privileged access together. Block ambiguous conflicts before replacement; do not guess identity matches from numeric IDs. Specify preserved roles, recovery state, settings, and logs and their dependent records.
- Exclude source server credentials and active recovery locks/state from the portable package. Preserve destination recovery state separately and protect temporary decrypted data and safety copies.
- Prevent stale running-job state and source model pointers from resuming as valid destination work. Enable background jobs only after destination verification and runtime checks.
- Keep the source inactive during cutover. Verify destination sign-in, Products/stock, Sale Receipts, uploaded images, reports, preserved security controls, and background-job state before opening operations. After new transactions, transfer fresh state before switching back.
- Document authorized failed-restore recovery for localhost and hosting; a partially restored database must not be relied on for ordinary authentication or reopened by deleting the failure marker.
- A login-password or future backup-passphrase change must not silently make old backups unusable. Each encrypted backup still requires its own applicable passphrase; losing all copies of it means losing access to that backup.
- Validate with disposable installations, migration in both directions, wrong passphrases, damaged packages, account conflicts, preserved security data, interrupted replacement and recovery. No destructive migration tests against the live Store.

The [implementation specification](../specs/encrypted-backup-and-store-migration.md), published as [issue #282](https://github.com/ZeachDev01/retailmind/issues/282) with `ready-for-agent`, captures the confirmed requirements, implementation boundaries, and user-confirmed testing approach. Detailed implementation choices must satisfy these requirements; unresolved account/security reconciliation is not permission to ship a partially protected restore. The discussion of admin-password-only restoration did not change the requirement for a separate backup passphrase.
