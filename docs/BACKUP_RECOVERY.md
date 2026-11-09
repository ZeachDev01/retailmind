# Database Backup and recovery

Current ordinary workflow: [Encrypted Store package contract](STORE_PACKAGE_FORMAT.md). Both administrator roles create and restore encrypted database-and-upload `.rmpkg` packages using the installation's automatic Recovery Key; no backup passphrase is entered. Restoration requires approved Identity Reconciliation, a fresh verified destination safety copy, and recovery review before reopening. Super Administrator package validation alone remains read-only. The SQL-only instructions below describe retained **legacy offline recovery**, not the ordinary browser workflow. Keep old recovery files private until replacement encrypted recovery has been fully tested.

## Current capture

Create the backup, then download it. Keep the package and an off-server copy of the Recovery Key separately. Creation history confirms capture, not that your browser saved it; the download recommendation and Backup Keyring exist to close that gap. A Scheduled Backup runs weekly without an operator, keeps the newest verified automatic package, and records failures for follow-up. Missing every Recovery Key copy makes those packages unusable. See the linked contract for runtime limits, complete upload capture, CLI input and security inventory.

## Identity Reconciliation preview

The Super Administrator can open **Review Identity Reconciliation** from Backup & Restore. Upload a compatible encrypted package and enter the current destination login password. Decryption uses the Recovery Key automatically; import a transferred installation's key into the Backup Keyring first when needed. Source installation access is not required. The preview lists source/destination people and matching numeric IDs, usernames and emails; no match automatically links people.

Choose an explicit same-person destination account or unused login details for each distinct ordinary person. Leaving the optional email blank explicitly clears it. Links retain the entire destination account, including password, status, roles, security state and profile. Source privileged-only and Recovery identities become roleless Historical Actors: disabled, unusable passwords, no memberships/reset/recovery authority, and permanently excluded from account activation or role assignment. Ordinary source memberships are restricted to the destination's fixed role templates. A reviewed result requires an active ordinary Administrator; designation is explicit.

Reselect the same package and re-enter the current destination login password to review resolutions and inspect the complete destination preservation closure and resulting access. The preservation closure guarantees complete retention of destination-owned security: protected accounts, credentials, profiles and sealed recovery accounts (`recovery_accounts`); platform settings (`platform_settings`); platform-scoped attention settings (`attention_settings`); technical forecasting configuration (`ml_settings`); classified security, recovery, platform-setting and recovery-account evidence (`activity_log`); login tracking and review watermarks (`login_attempts`, `attention_states`); valid protected reset state (`password_reset_tokens`); protected notification preferences and alerts (`notification_preferences`, `notifications`); backup and email-delivery history (`backup_history`, `email_delivery_log`); and dependent logical actors preserved as evidence-only accounts without usable access.

The approving Super Administrator confirms reconciliation with their current destination login password. This records protected destination-owned preparation evidence in `RecoveryStore` (`reconciliation-approval.json`) capturing the exact package ID, origin, schema hash, decisions, explicit identity/file maps, active administrators count, resulting access, and a cryptographic destination watermark. Ordinary Administrators cannot approve or alter reconciliation; later authorized consumers may use valid preparation without receiving platform governance.

Immediately before destructive use, the package integrity, required credentials, current authority, effective roles, relevant destination state (watermark), and mappings are strictly rechecked. Any discrepancy invalidates approval and demands renewed review. Clear, actionable errors are returned without exposing restricted evidence or secrets. Existing historical JSON stays unchanged; installation-aware record provenance resolves embedded source identities and survives repeated transfers. Required imported images receive collision-free paths without replacing destination profiles. Both installations must apply `202610250002_identity_provenance`. Review classifies packages as current, upgradable, or unsupported from the sealed migration ledger plus schema structure; registered adapters upgrade known older structures, the destination migration ledger is preserved, and unknown or newer structures stop before destructive work. Original producer-fingerprint checks remain enforced across MySQL and MariaDB. See ADR-0021 for the allowed representation differences and ADR-0028 for scheduling, keyring, and compatibility decisions.

## Start encrypted restoration

After reconciliation approval, open **Restore encrypted backup** on either administrator backup screen. Select the exact approved package and enter your current destination login password. The destination safety copy is encrypted automatically with the Recovery Key; keep an off-server key copy available before continuing. Confirm that newer Store records will be replaced and stop source sales and background workers.

Restoration acquires the exclusive Store request lock and captures a fresh verified safety checkpoint; previously downloaded safety copies are not reused. Destination security evidence is preserved from this checkpoint, including changes recorded after reconciliation approval. The operation is bound to the exact approval and package, and retained destination credentials authorize recovery even if replacement loses database tables. Old forecasting jobs are invalidated and model approval is cleared; forecasting recommendations remain suspended until destination rebuilding and approval.

The browser opens the Recovery Console after replacement, or after a failure that requires recovery. Sign in with retained destination credentials, review and confirm the stock/receipt/upload/attribution checklist, then explicitly reopen with your password and confirmation that only one Store installation is active. Everyone signs in again. If replacement fails, use the original encrypted safety checkpoint or restart the approved incoming replacement as described below. Do not remove recovery state to bypass verification.
## Verified encrypted destination safety copies

Both administrator backup screens also provide **Destination safety copy** for a separate manual checkpoint. Enter your current destination login password. The capture uses the existing coordinated encrypted workflow and the installation's Recovery Key. Full decryption, schema/type/uniqueness/foreign-key and upload-integrity checks complete before safety readiness is published. Starting a restore captures its own fresh checkpoint.

Download the encrypted safety artifact and retain it outside both servers. Keep an owner-controlled off-server copy of the Recovery Key; neither the login password nor another installation's incoming key is assumed to unlock it. Losing every key copy makes this fallback unusable. Downloading does not consume or delete the original server artifact.

Each operation owns one immutable fallback under private `BACKUP_STORAGE_PATH/safety-<operation-id>/`, separate from temporary backup-download cleanup and replacement tables/uploads. Its checkpoint binds requester, exact schema, package/origin IDs, encrypted hash, SQL/upload hashes and snapshot time. Retry verifies the same original rather than capturing changed or partial state. Missing/changed artifacts or invalid checkpoints fail closed; never substitute another operation's copy. Checkpoint verification uses its retained schema even if application users are missing. Protected browser recovery authentication and replacement/reopening are separate dependent deliveries; an ordinary session safety download cannot recover or reopen a failed restore.

Capture requires functioning PHP 8.2+, OpenSSL/fileinfo, 512 MiB PHP memory, private persistent worker-accessible storage and reliable locks. Existing limits remain 48 MiB encrypted input, 32 MiB decoded contents, 100,000 rows, 2,000 images and 5 MiB per image. Capture requires at least 96 MiB free before encryption, in addition to existing retained copies. Database/upload/encryption/storage/verification failure cannot authorize destruction. Temporary plaintext SQL is deleted after capture or a handled failure; no decrypted copy or Recovery Key is kept as recovery material. Independently stop/drain background workers as documented for package capture.

Disposable checks: `RUN_DB_TESTS=1 php -d memory_limit=512M src/backend/tests/safety_copy_workflow_integration.php` and `RUN_DB_TESTS=1 node src/backend/tests/safety_copy_browser_test.js`. The complete-schema test covers Product/profile bytes, exact stock, immutable retries, both roles, private repeatable download, wrong secrets, missing users and independent encryption/write/readback/checkpoint/capacity failures. Browser checks exercise both screens, password/CSRF errors, requester denial and mobile/desktop fit. Matching-runtime hosted capacity, persistence, isolation and recovery drills remain unexecuted under #288; these local checks do not certify migration readiness.

## Durable maintenance and browser recovery authentication

Before destructive operations begin, approved package and plan identifiers, destination-security checkpoints, verified safety metadata, operation stages, session invalidations, and append-only outcomes are durably preserved outside replaceable database tables and uploads in `RecoveryStore` under `BACKUP_STORAGE_PATH`.

### Pre-restore operator credentials and authorization

Only protected pre-restore password verifiers (hashes) and operation-bound authorization are retained for the initiating administrator and active destination Super Administrators authorized before replacement. Each authenticates independently with their own pre-restore credentials. Sealed Recovery Accounts retain separate offline unsealing rules and cannot authenticate via this path; imported identities, unrelated Administrators, and ordinary user sessions cannot authorize recovery. Plaintext passwords, passphrases, and decrypted records are never retained.

### Durable stage lifecycle

Progress persists through explicit durable stages in `restore-state.json` and append-only `restore-log.jsonl`:
1. `safety_verified`: Approved reconciliation and destination safety capture verified.
2. `destructive_start`: Persisted strictly BEFORE the first destructive DDL or file write. Session epoch is regenerated, invalidating all pre-existing sessions.
3. `database_replacement`: DDL and table replacement underway.
4. `uploads`: Uploaded asset replacement.
5. `automated_verification`: Post-replacement integrity assertions.
6. `operator_review_pending`: Destination administrator review.
7. `recovery_required`: Triggered on failure or interruption, holding the Store paused.
8. `reopened_completed`: Explicit destination Super Administrator reopening.

Uncertain, corrupt, or missing coordination state fails closed and never authorizes reopening.

### Maintenance fencing and request admission

When recovery state is active (`RecoveryStore::isPaused()`), ordinary web requests return `503 Service Unavailable`, background writers are blocked by `StoreWriteGate` with `StoreWritePausedException`, and CLI workers (forecasting scheduler, daily notifications, manual backups) terminate. Only authorized recovery access (`/components/backup/recovery.php` and `backup_status.php`) and explicit CLI recovery (`restore_database.php`) are admitted. Fencing persists across worker crashes, request timeouts, and database table loss.

### Browser recovery console

The browser recovery console (`/components/backup/recovery.php`) renders and authenticates without querying application tables. It enforces:
- Fresh authentication against retained operator hashes.
- Operation binding: sessions are strictly tied to the active `operation_id`.
- CSRF protection: independent recovery CSRF tokens.
- Externally maintained throttling: 5 failed attempts locks out the client for 15 minutes via `recovery-throttle.json`.
- Session expiry: 15-minute inactivity limit.
- Role-restricted evidence: Initiating Administrators view operational progress and safety confirmations; Super Administrators access destination watermarks, safety checkpoints, append-only logs, and the explicit reopening action.

### Operational requirements and verification

Recovery operations require PHP 8.2+, HTTPS, MySQL 8.0+, 512 MiB PHP memory limit, persistent worker-accessible private storage outside the document root, and reliable file locking (`flock`).

Disposable checks: `RUN_DB_TESTS=1 php -d memory_limit=512M src/backend/tests/recovery_restart_workflow_integration.php`, `RUN_DB_TESTS=1 php -d memory_limit=512M src/backend/tests/recovery_auth_workflow_integration.php`, and `RUN_DB_TESTS=1 node src/backend/tests/recovery_restart_browser_test.js`.
Tests prove independent operator authentication with dropped application tables, rejection of forged/expired/unauthorized requests, throttling, worker death fencing, and responsive layout across 390px and 1440px viewports. Hosted multi-node locking, persistence, and disaster cutover drills remain deferred under #288; ordinary replacement remains disabled until downstream delivery is complete.

## Safety copy recovery and approved incoming restart (#312)

The browser recovery console (`/components/backup/recovery.php`) provides two clearly distinguished, authenticated operator actions while the Store is fenced:

1. **Complete Safety Recovery (`recover_safety`)**:
   - Restores destination database tables and referenced image uploads directly from the verified destination safety copy (`safety-<operation_id>/safety.rmpkg`).
   - Requires the destination login password and the destination Recovery Key.
   - Operates completely without incoming package material or incoming keys.
   - Retains original verified safety artifact and checkpoint immutably on disk.

2. **Restart Approved Incoming Replacement (`restart_incoming`)**:
   - Restarts replacement deterministically from the beginning using the approved encrypted Store package (`.rmpkg`).
   - Requires the destination login password and the Recovery Key that opens both the incoming package and the safety copy.
   - Revalidates package identity, destination watermark, and reconciliation plan against the original safety checkpoint independently of partial live tables.
   - Re-applies approved identity and file mappings, preserves destination security closure, maps Historical Actors, and writes actor provenance to `store_record_origins`.

### Automated post-replacement verification

Before transitioning to operator review, both recovery paths execute strict post-replacement integrity assertions:
- Database relationships: validates that all declared foreign key constraints are satisfied with zero orphan rows.
- Retained authority: confirms at least one active destination Super Administrator exists with valid platform governance capabilities.
- Actor attribution: asserts that unconstrained audit/historical columns (branches, sales) reference existing users.
- Stock and batches: asserts non-negative quantities on hand and valid batch allocations.
- Receipts and totals: confirms sales and refund receipt line items sum to header transaction amounts.
- Uploaded asset hashes: asserts all referenced files exist in storage with matching SHA-256 hashes.
- Background jobs: marks queued/running ML jobs failed and sets unready forecasting model flags.

Upon successful automated verification, the operation stage transitions to `STAGE_OPERATOR_REVIEW_PENDING`. Reopening is NEVER automatic: Store writes and background jobs remain fenced while operator verification is pending (#313).

### Controlled verification checks and operator review (#313)

While ordinary traffic remains blocked by `StoreWriteGate` and `RecoveryStore`, authorized operators can run controlled read-only verification checks in the recovery console (`/components/backup/recovery.php`):
- **Sign-in preview**: read-only preview of active destination administrators and users.
- **Stock & batch check**: verifies inventory counts, batch allocations, and zero negative quantities on hand.
- **Receipt & subtotal verification**: checks sales and cash refunds against item subtotal sums.
- **Upload asset preview**: verifies that referenced product images and profile images exist on disk and match integrity checksums.
- **Summary report check**: computes aggregate revenue, transaction counts, and inventory volume.

### Human review checklist and evidence persistence

Durable evidence (`review_checks`) records both automated check results and human checklist confirmations:
1. `stock`: verified inventory counts, product batches, and zero negative stock.
2. `receipt`: verified sales receipts and financial subtotal integrity.
3. `upload`: previewed product and profile images on filesystem.
4. `attribution`: confirmed operator identities and actor associations resolve cleanly.

Missing or stale checks (> 15 minutes old) strictly prevent reopening with accessible, actionable guidance.

### Explicit reopening and authorization

Reopening requires:
1. **Durable stage**: must be `STAGE_OPERATOR_REVIEW_PENDING`.
2. **Authorization**: only the initiating administrator or a pre-authorized destination Super Administrator (from `authorized_operators`) can reopen. Imported identities, ordinary sessions, or unrelated admins are strictly rejected.
3. **Fresh authentication**: requires re-entering the operator's destination password, verified via `RecoveryAuthService`.
4. **Operation binding**: bound to the active recovery `operation_id` with a valid recovery CSRF token.
5. **Completed checklist**: all 4 automated checks passed and not stale; all 4 human checklist items confirmed.
6. **One-active-Store constraint**: explicit confirmation that this Store is the single active Store. Dual active stores are prohibited.

### Reopening effect and persistence

Executing reopening:
- Transitions durable stage to `STAGE_REOPENED_COMPLETED`.
- Writes `recovery-reopened.json` with operator metadata, timestamp, path completed (`safety_recovery` or `restart_incoming`), verified checks, and session epoch.
- Appends outcome to `restore-log.jsonl`.
- Retains original verified safety copy and checkpoint intact on disk for audit.
- Unpauses `store_write_gate` and clears `isPaused()`, admitting ordinary HTTP read/write requests.
- Invalidates all existing web sessions via a random `session-epoch` bump, preventing old sessions from reviving.
- Leaves stale ML/forecast jobs failed/cancelled; forecasting remains unavailable if unsupported.

## Encrypted backup and recovery reference

### Create and download

The Administrator and Super Administrator share one backup workflow. Choose
**Create backup**, then **Download**. The consistent capture pauses writes while
RetailMind creates an encrypted `.rmpkg` package. The package contains the
validated `RMSQL2` snapshot and required referenced uploads; it is not a plain
`.sql` export.

Before completion is recorded, RetailMind decrypts and validates the package,
including its authentication tag, manifest, schema, SQL rows, and uploaded
members. This creation-time verification is the durable evidence used by System
Health. The temporary download is requester-bound and may be removed after
delivery; its absence does not invalidate a completed verified backup record.

### Private storage and recovery key

Set `BACKUP_STORAGE_PATH` to an absolute, persistent directory outside both the
project and web document root. All PHP workers and recovery commands for the
installation must share it. The stable recovery key is stored outside the
application and database. Keep an off-server copy of the encrypted package and
its recovery key separately; losing every key copy makes the package
irrecoverable.

System Health keeps the Recovery check at **Needs attention** when no recent
completed `RMSTORE2` package is recorded or when no valid private recovery key is
available. It does not create a new key while checking health. A recent verified
package is healthy for seven days; older records remain visible but need
attention.

### Restore

Open **Backup & Restore**, upload the encrypted `.rmpkg`, and provide the current
destination administrator password. Read-only preflight authenticates and
validates the package before replacement. The restore workflow creates a fresh
verified safety checkpoint, pauses Store writes, replaces the database and
referenced uploads, invalidates sessions, and requires Store Reopening after
automated verification and human review.

Legacy plaintext SQL and `RMSTORE1` passphrase packages are not accepted by the
ordinary browser recovery path; only `RMSTORE2` automatic-key packages are.
Never place recovery keys or passwords in URLs, logs, filenames, command
arguments, or source control.

## Scheduled backups and key custody

A Scheduled Backup runs weekly from the Windows scheduler and can be caught up
from Backup & Restore when the task was missed. The Super Administrator owns the
weekday, local time, and Backup Destination; the default is Sunday at 23:00
Asia/Manila. A blank Backup Destination uses the private recovery store's
`scheduled` folder. A configured destination must be an absolute path outside
both the application and web document root, writable, and have at least 96 MiB
free. The previous
Scheduled Backup is removed only after the new package authenticates and
verifies; manual backups, restore safety copies, and restore evidence are never
retention targets. Replaced rows stay in Backup history with their removal time,
and a failure leaves the previous package in place while raising an attention
item and, when enabled, a Store Email Alert.

After a completed backup, each Administrator is recommended to download the
Recovery Key that encrypted the newest package. The recommendation appears as a
once-per-session prompt and a persistent banner until that specific key is
exported with the current login password. Acknowledgement is recorded only
after the download stream completes; another Administrator who has not saved
the key still sees the recommendation. The Backup Keyring lists the Primary
Recovery Key and every imported or archived key with its fingerprint and dates.
Import stays additive, promotion archives the previous primary, any key can be
exported, and deletion requires an explicit password-confirmed warning while the
primary key remains protected.
