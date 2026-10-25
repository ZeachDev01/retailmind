# Database Backup and recovery

Current ordinary capture/preflight: [Encrypted Store package contract](STORE_PACKAGE_FORMAT.md), #282/#283. Both administrator roles now create encrypted database-and-upload `.rmpkg` packages with a separate passphrase. Super Administrator browser validation is read-only; new replacement is not yet enabled. The SQL-only instructions below describe retained **legacy offline recovery**, not the ordinary browser workflow. Keep old recovery files private until replacement encrypted recovery has been fully tested.

## Current capture

Enter a separate backup passphrase (16–1024 bytes), create, then download. Keep the package and an off-server passphrase recovery copy separately. Creation history confirms capture, not that your browser saved it. Missing every passphrase copy makes the package unusable. See the linked contract for runtime limits, complete upload capture, CLI input and security inventory.

## Identity Reconciliation preview

The Super Administrator can open **Review Identity Reconciliation** from Backup & Restore. Upload a compatible encrypted package and enter the current destination login password plus that package's separate passphrase. Source installation access is not required. The preview lists source/destination people and matching numeric IDs, usernames and emails; no match automatically links people.

Choose an explicit same-person destination account or unused login details for each distinct ordinary person. Leaving the optional email blank explicitly clears it. Links retain the entire destination account, including password, status, roles, security state and profile. Source privileged-only and Recovery identities become roleless Historical Actors: disabled, unusable passwords, no memberships/reset/recovery authority, and permanently excluded from account activation or role assignment. Ordinary source memberships are restricted to the destination's fixed role templates. A reviewed result requires an active ordinary Administrator; designation is explicit.

Reselect the same package and supply both secrets again to review resolutions. This read-only preview stores neither decrypted package contents nor credentials and never authorizes replacement. Protected preparation and security approval are a separate delivery step. Existing historical JSON stays unchanged; installation-aware record provenance resolves embedded source identities and survives repeated transfers. Required imported images receive collision-free paths without replacing destination profiles. Both installations must apply `202610250002_identity_provenance`; exact-schema package checks remain enforced. Actual hosted recovery/migration proof remains #288.

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
