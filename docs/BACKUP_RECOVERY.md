# Database Backup and recovery

Current ordinary capture/preflight: [Encrypted Store package contract](STORE_PACKAGE_FORMAT.md), #282/#283. Both administrator roles now create encrypted database-and-upload `.rmpkg` packages with a separate passphrase. Super Administrator browser validation is read-only; new replacement is not yet enabled. The SQL-only instructions below describe retained **legacy offline recovery**, not the ordinary browser workflow. Keep old recovery files private until replacement encrypted recovery has been fully tested.

## Current capture

Enter a separate backup passphrase (16–1024 bytes), create, then download. Keep the package and an off-server passphrase recovery copy separately. Creation history confirms capture, not that your browser saved it. Missing every passphrase copy makes the package unusable. See the linked contract for runtime limits, complete upload capture, CLI input and security inventory.

## Identity Reconciliation preview

The Super Administrator can open **Review Identity Reconciliation** from Backup & Restore. Upload a compatible encrypted package and enter the current destination login password plus that package's separate passphrase. Source installation access is not required. The preview lists source/destination people and matching numeric IDs, usernames and emails; no match automatically links people.

Choose an explicit same-person destination account or unused login details for each distinct ordinary person. Leaving the optional email blank explicitly clears it. Links retain the entire destination account, including password, status, roles, security state and profile. Source privileged-only and Recovery identities become roleless Historical Actors: disabled, unusable passwords, no memberships/reset/recovery authority, and permanently excluded from account activation or role assignment. Ordinary source memberships are restricted to the destination's fixed role templates. A reviewed result requires an active ordinary Administrator; designation is explicit.

Reselect the same package and supply both secrets again to review resolutions and inspect the complete destination preservation closure and resulting access. The preservation closure guarantees complete retention of destination-owned security: protected accounts, credentials, profiles and sealed recovery accounts (`recovery_accounts`); platform settings (`platform_settings`); platform-scoped attention settings (`attention_settings`); technical forecasting configuration (`ml_settings`); classified security, recovery, platform-setting and recovery-account evidence (`activity_log`); login tracking and review watermarks (`login_attempts`, `attention_states`); valid protected reset state (`password_reset_tokens`); protected notification preferences and alerts (`notification_preferences`, `notifications`); backup and email-delivery history (`backup_history`, `email_delivery_log`); and dependent logical actors preserved as evidence-only accounts without usable access.

The approving Super Administrator confirms reconciliation with their current destination login password. This records protected destination-owned preparation evidence in `RecoveryStore` (`reconciliation-approval.json`) capturing the exact package ID, origin, schema hash, decisions, explicit identity/file maps, active administrators count, resulting access, and a cryptographic destination watermark. Ordinary Administrators cannot approve or alter reconciliation; later authorized consumers may use valid preparation without receiving platform governance.

Immediately before destructive use, the package integrity, required credentials, current authority, effective roles, relevant destination state (watermark), and mappings are strictly rechecked. Any discrepancy invalidates approval and demands renewed review. Clear, actionable errors are returned without exposing restricted evidence or secrets. Ordinary replacement remains disabled; this delivers preparation, review, and approval evidence. Existing historical JSON stays unchanged; installation-aware record provenance resolves embedded source identities and survives repeated transfers. Required imported images receive collision-free paths without replacing destination profiles. Both installations must apply `202610250002_identity_provenance`; exact-schema package checks remain enforced.
## Verified encrypted destination safety copies

Both administrator backup screens provide **Destination safety copy**. Enter your current destination login password and a separately supplied safety-copy passphrase (16–1024 bytes). This captures the current complete database and referenced Product/profile images using the existing coordinated encrypted capture. Full decryption, schema/type/uniqueness/foreign-key and upload-integrity checks complete before safety readiness is published. Ordinary encrypted replacement remains disabled.

Download the encrypted safety artifact and retain it outside both servers. Keep a separate owner-controlled recovery copy of its passphrase; neither the login password nor an incoming package secret is assumed to unlock it. Losing every copy makes this fallback unusable. Downloading does not consume or delete the original server artifact.

Each operation owns one immutable fallback under private `BACKUP_STORAGE_PATH/safety-<operation-id>/`, separate from temporary backup-download cleanup and replacement tables/uploads. Its checkpoint binds requester, exact schema, package/origin IDs, encrypted hash, SQL/upload hashes and snapshot time. Retry verifies the same original rather than capturing changed or partial state. Missing/changed artifacts or invalid checkpoints fail closed; never substitute another operation's copy. Checkpoint verification uses its retained schema even if application users are missing. Protected browser recovery authentication and replacement/reopening are separate dependent deliveries; an ordinary session safety download cannot recover or reopen a failed restore.

Capture requires functioning PHP 8.2+, OpenSSL/fileinfo, 512 MiB PHP memory, private persistent worker-accessible storage and reliable locks. Existing limits remain 48 MiB encrypted input, 32 MiB decoded contents, 100,000 rows, 2,000 images and 5 MiB per image. Capture requires at least 96 MiB free before encryption, in addition to existing retained copies. Database/upload/encryption/storage/verification failure cannot authorize destruction. Temporary plaintext SQL is deleted after capture or a handled failure; no decrypted copy or passphrase is kept as recovery material. Independently stop/drain background workers as documented for package capture.

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
   - Requires only the destination safety-copy passphrase.
   - Operates completely without incoming package material or incoming passphrases.
   - Retains original verified safety artifact and checkpoint immutably on disk.

2. **Restart Approved Incoming Replacement (`restart_incoming`)**:
   - Restarts replacement deterministically from the beginning using the approved encrypted Store package (`.rmpkg`).
   - Requires both the incoming package passphrase AND the destination safety-copy passphrase.
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

## Legacy offline recovery reference

## Create and download

The Administrator and Super Administrator share one backup workflow. Choose
**Create backup**, then **Download**. Saving pauses during a consistent capture.
The result is an **unencrypted `.sql` file** containing all database tables,
including password hashes and restricted audit records. Values use SQL hex
literals for lossless binary/text handling; this is encoding, not encryption.

Keep the file private on separate storage. A completed history entry means the
server created it, not that the browser saved it. Temporary downloads are bound
to their requester and removed after delivery; interrupted downloads expire.
There is no automatic archive or new scheduling feature.

## Private storage

Set `BACKUP_STORAGE_PATH` to an absolute, persistent directory outside both the
project and the web document root. Grant access only to the PHP service account
and trusted recovery operators (configure NTFS ACLs on Windows). Do not alias or
serve this directory through the web server, and do not use a temporary directory.
If unset, the default is `retailmind-private-<project-path-hash>` two directories
above the project. The application refuses a path inside the project/document root.
All PHP workers and recovery commands for this installation must share this path.

The directory holds:

- `downloads/`: short-lived requester-bound SQL artifacts.
- `latest-safety.sql`: the latest complete pre-restore safety backup. It is never
  included in temporary-download cleanup. The Super Administrator can download
  it from Backup & Restore while the application is healthy.
- `restore-log.jsonl`: append-only initiating user ID, filename, UTC time, outcome,
  and completed statement count. No passwords or SQL contents are logged.
- `restore-state.json`: exists only while recovery is pending; retains the initiating
  Super Administrator's password **hash** and compatible schema for offline recovery
  if the database is incomplete. Do not manually remove it to reopen a broken Store.
- `session-epoch` and `requests.lock`: session invalidation and request coordination.

Deploy this on one application host with reliable filesystem locking. All application
requests take shared leases; restore takes an exclusive lease, so existing requests
must finish before replacement. A busy Store returns a retry message, not a queued
restore. Pause independent database writers (external integrations, Python jobs,
manual SQL sessions) before restoring; they do not participate in PHP request locks.

## Restore (Super Administrator only)

1. Keep a known-good, trusted RetailMind SQL backup. The current `RMSQL2` format
   accepts a matching base-table schema, including column definitions. Custom views,
   triggers, routines, events, or generated columns are unsupported: backup creation
   fails rather than silently losing them. Use a matching application/schema version.
2. Open **Backup & Restore**, select the `.sql` file, read the replacement warning,
   and enter your **current Super Administrator password**. No encryption key or
   additional confirmation phrase is needed; normal sign-in and CSRF checks still apply.
3. The complete file is checked before replacement. Its checksum detects truncation
   or accidental edits, not malicious forgery. A strict SQL subset rejects arbitrary
   commands/expressions and incompatible structures. **Only restore trusted backups**:
   anyone able to edit a plaintext file can also change its contents and checksum.
4. A complete safety backup is captured, checked, and retained before any table is
   replaced. If it cannot be saved, replacement does not begin.
5. Store access pauses. Restoration replaces every database table, including users,
   passwords, settings, and audit history; newer records are not merged or reconciled.
6. Everyone signs in again using credentials from the restored snapshot. Old sessions
   cannot revive just because their database session-version numbers were restored.

The upload limit is shown on screen from PHP's `upload_max_filesize` and
`post_max_size`. Increase both for larger backups; `post_max_size` also needs room
for multipart overhead. Memory must accommodate the SQL file and parsed statements.

## Incomplete restoration

MySQL table replacement is **not transactional rollback**. A crash or error after
replacement begins leaves Store access blocked, including login. Recovery does not
rely on the partially restored users table and is intentionally an offline command.

On the application host, a trusted Super Administrator runs:

```text
php src/backend/scripts/restore_database.php
```

The default input is `latest-safety.sql`, returning the Store to its pre-restore state.
To retry the original compatible file instead:

```text
php src/backend/scripts/restore_database.php --file=/private/path/backup.sql
```

Temporarily supply `RESTORE_PASSWORD` through the process environment with the
initiating Super Administrator's password **as it was before the failed restore**.
Do not save this password in `.env`, command arguments, scripts, or shell history.
For example, in PowerShell:

```powershell
$credential = Get-Credential -UserName 'Super Administrator' -Message 'Pre-restore password'
try {
    $env:RESTORE_PASSWORD = $credential.GetNetworkCredential().Password
    php src/backend/scripts/restore_database.php
} finally {
    Remove-Item Env:RESTORE_PASSWORD -ErrorAction SilentlyContinue
    $credential = $null
}
```

The command is available only during pending recovery, verifies the saved password
hash, validates the SQL against the saved schema, and does not overwrite the good
safety copy with the partial database. Success logs the outcome, invalidates sessions,
and reopens the Store. Failure leaves it blocked. Read the private restore log and
application error log for diagnosis. If credentials or private recovery state are
lost, rebuild on a separate server from a trusted backup rather than bypassing the gate.

## Server loss and old backups

Provision the matching application/schema version on a replacement server, sign in
as its Super Administrator, and restore a trusted SQL backup. No encryption key is
required for new SQL backups. SQL does not include uploaded files, application code,
server configuration, or the private restore log; preserve those separately.

Old `.rmbak` uploads are rejected. **Keep existing encrypted backups and their keys**
until replacement SQL backups have been tested. Temporary cleanup does not delete
legacy encrypted artifacts. Converting an old backup is an offline migration, not a
second format supported by the simplified restore screen.
